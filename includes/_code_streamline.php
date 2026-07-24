<?php
/**
 * MagDyn — Streamline codes engine (shared)
 * Created: 20260723_IST
 *
 * Pulled out of code_streamline.php so the renumber logic can be unit-
 * tested independently of the page (routing, header, session). The page
 * and the CLI test both include this.
 *
 * See code_streamline.php for the full rationale. In short: convert the
 * remaining NON-numeric asset tags / inventory item codes into plain
 * numeric codes (assets from 917, items from 2310), leaving already-
 * numeric codes untouched, propagating to every denormalised copy, all
 * inside one transaction.
 */

if (!function_exists('cs_is_numeric_code')) {

    /** A code counts as numeric when it is a non-empty digits-only string. */
    function cs_is_numeric_code($code)
    {
        $code = (string)$code;
        return $code !== '' && ctype_digit($code);
    }

    /**
     * Audit tables. Self-healing so the page works even if the seed was
     * never run; IF NOT EXISTS makes it a no-op once they exist.
     */
    function cs_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        db_exec("CREATE TABLE IF NOT EXISTS `code_streamline_runs` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `kind` enum('asset','inventory') NOT NULL,
            `scope` varchar(20) NOT NULL DEFAULT 'active',
            `format` varchar(20) NOT NULL DEFAULT 'plain',
            `start_number` int(10) unsigned NOT NULL,
            `total_changed` int(10) unsigned NOT NULL DEFAULT 0,
            `min_new_code` varchar(64) DEFAULT NULL,
            `max_new_code` varchar(64) DEFAULT NULL,
            `seq_updated` tinyint(1) NOT NULL DEFAULT 0,
            `run_by` int(10) unsigned DEFAULT NULL,
            `run_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `ix_csr_kind` (`kind`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db_exec("CREATE TABLE IF NOT EXISTS `code_streamline_map` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `run_id` int(10) unsigned NOT NULL,
            `kind` enum('asset','inventory') NOT NULL,
            `entity_id` int(10) unsigned NOT NULL,
            `entity_name` varchar(255) DEFAULT NULL,
            `extra_info` varchar(500) DEFAULT NULL,
            `old_code` varchar(64) NOT NULL,
            `new_code` varchar(64) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `ix_csm_run` (`run_id`),
            KEY `ix_csm_entity` (`kind`,`entity_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    }

    /**
     * Compute the old→new mapping for one kind WITHOUT touching anything.
     * Pure/read-only — the caller decides whether to apply it. This is the
     * piece that unit tests can assert against.
     *
     * @return array list of ['id','old','new','name','extra']
     */
    function cs_build_map($kind, $startNumber)
    {
        if ($kind === 'asset') {
            $table = 'assets'; $col = 'asset_tag';
            $rows = db_all(
                "SELECT a.id, a.asset_tag AS code, a.asset_name AS name,
                        am.code AS model_code, am.name AS model_name
                   FROM assets a
                   LEFT JOIN asset_models am ON am.id = a.model_id
                  WHERE a.status <> 'archived'
                  ORDER BY a.id"
            );
        } else {
            $table = 'inv_items'; $col = 'code';
            $rows = db_all(
                "SELECT id, code, name, part_no
                   FROM inv_items
                  WHERE is_active = 1 AND is_obsolete = 0
                  ORDER BY id"
            );
        }

        // Uniqueness universe: every code currently in the table.
        $used = [];
        foreach (db_all("SELECT `$col` AS c FROM `$table`") as $r) {
            $used[(string)$r['c']] = true;
        }

        $map  = [];
        $next = (int)$startNumber;
        foreach ($rows as $r) {
            $old = (string)$r['code'];
            if (cs_is_numeric_code($old)) continue;         // already numeric — keep

            while (isset($used[(string)$next])) $next++;      // skip taken numbers
            $new = (string)$next;
            $used[$new] = true;
            $next++;

            if ($kind === 'asset') {
                $name  = (string)($r['name'] ?? '');
                $extra = trim(((string)($r['model_code'] ?? '')) . ' — ' . ((string)($r['model_name'] ?? '')), ' —');
            } else {
                $name  = (string)($r['name'] ?? '');
                $extra = (string)($r['part_no'] ?? '');
            }
            $map[] = ['id' => (int)$r['id'], 'old' => $old, 'new' => $new,
                      'name' => $name, 'extra' => $extra];
        }
        return $map;
    }

    /**
     * Apply a prebuilt mapping for one kind: rewrite the master column and
     * every denormalised copy. Caller owns the transaction boundary.
     */
    function cs_apply_map($kind, array $map)
    {
        $table = ($kind === 'asset') ? 'assets' : 'inv_items';
        $col   = ($kind === 'asset') ? 'asset_tag' : 'code';

        // 1) Master codes. New numbers are guaranteed free → no unique clash.
        foreach ($map as $m) {
            db_exec("UPDATE `$table` SET `$col` = ? WHERE id = ?", [$m['new'], $m['id']]);
        }

        // 2) Denormalised copies.
        if ($kind === 'asset') {
            foreach ($map as $m) {
                db_exec(
                    "UPDATE invoice_items SET item_code = ?
                      WHERE item_kind = 'asset' AND item_code = ?",
                    [$m['new'], $m['old']]
                );
            }
        } else {
            foreach ($map as $m) {
                db_exec("UPDATE ats_lines SET inv_code = ? WHERE item_id = ?", [$m['new'], $m['id']]);
                db_exec(
                    "UPDATE invoice_items SET item_code = ?
                      WHERE item_kind = 'inv_item' AND item_code = ?",
                    [$m['new'], $m['old']]
                );
                db_exec(
                    "UPDATE inv_so_pending_summary SET item_code = ? WHERE item_code = ?",
                    [$m['new'], $m['old']]
                );
            }
        }
    }

    /**
     * Full run: build + apply + record + (optionally) flip the sequence to
     * numeric, all in one transaction. Rolls back on any error.
     *
     * @return array ['run_id','count','min','max']
     */
    function cs_streamline($kind, $startNumber, $updateSequence)
    {
        cs_ensure_tables();
        $seqName = ($kind === 'asset') ? 'asset' : 'inv_item';

        $map = cs_build_map($kind, $startNumber);
        if (!$map) {
            return ['run_id' => 0, 'count' => 0, 'min' => null, 'max' => null];
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            cs_apply_map($kind, $map);

            db_exec(
                "INSERT INTO code_streamline_runs
                    (kind, scope, format, start_number, total_changed,
                     min_new_code, max_new_code, seq_updated, run_by)
                 VALUES (?, 'active', 'plain', ?, ?, ?, ?, ?, ?)",
                [$kind, (int)$startNumber, count($map),
                 $map[0]['new'], $map[count($map) - 1]['new'],
                 $updateSequence ? 1 : 0, (int)current_user_id()]
            );
            $runId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare(
                "INSERT INTO code_streamline_map
                    (run_id, kind, entity_id, entity_name, extra_info, old_code, new_code)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($map as $m) {
                $ins->execute([$runId, $kind, $m['id'], $m['name'], $m['extra'], $m['old'], $m['new']]);
            }

            if ($updateSequence) {
                db_exec(
                    "UPDATE code_sequences
                        SET prefix = '', pad = 1, format = 'prefix_pad',
                            date_format = NULL, is_active = 1
                      WHERE name = ?",
                    [$seqName]
                );
            }

            db_exec(
                "INSERT INTO audit_log (actor_id, action, target_id, details) VALUES (?, ?, ?, ?)",
                [(int)current_user_id(), 'code_streamline.' . $kind, $runId,
                 sprintf('Converted %d non-numeric %s code(s): %s..%s%s',
                     count($map), $kind, $map[0]['new'], $map[count($map) - 1]['new'],
                     $updateSequence ? '; sequence set to numeric' : '')]
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['run_id' => $runId, 'count' => count($map),
                'min' => $map[0]['new'], 'max' => $map[count($map) - 1]['new']];
    }
}
