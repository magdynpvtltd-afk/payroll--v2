<?php
/**
 * MagDyn — User datatable VIEW STATE (filters + search + sort + page size).
 *
 * Sibling of user_dt_prefs.php. Where user_dt_prefs stores column LAYOUT
 * (order / visibility / width), this stores the user's last-used VIEW of a
 * datatable: global search text, per-column filters, the active sort, and
 * the chosen page size. Restoring it means a list page comes back exactly
 * as the user left it even when they navigate in fresh from the sidebar
 * (i.e. with no dt_* params in the URL).
 *
 * Design notes:
 *   - One row per (user_id, dt_id). The state is a compact JSON blob so we
 *     can grow the set of remembered fields without a schema change.
 *   - The PAGE NUMBER is intentionally NOT remembered — returning to a list
 *     should land on page 1 with the filters applied, not on whatever deep
 *     page you happened to be on last time.
 *   - Restoration is opt-out per table via $cfg['save_state'] === false.
 *   - Explicit dt_* URL params always win (shareable / bookmarked links),
 *     so saved state is only consulted when the request carries none.
 *
 * Public surface:
 *   user_dt_view_load($userId, $dtId)          → array|null (decoded state)
 *   user_dt_view_save($userId, $dtId, $state)  → bool
 *   user_dt_view_clear($userId, $dtId)         → bool
 */

require_once __DIR__ . '/db.php';

/**
 * Fetch the saved view state for a (user, datatable) pair.
 *
 * Returns a normalised array with keys: q (string), sort (string),
 * dir ('asc'|'desc'), size (int|null), col (map colKey→string),
 * client (map colKey→string). Returns null when nothing is saved or
 * on any DB/parse error (degrade to defaults rather than break render).
 */
function user_dt_view_load($userId, $dtId)
{
    $userId = (int)$userId;
    $dtId   = trim((string)$dtId);
    if ($userId <= 0 || $dtId === '') return null;
    try {
        $json = db_val(
            "SELECT state_json FROM user_dt_view_state WHERE user_id = ? AND dt_id = ?",
            [$userId, $dtId],
            null
        );
    } catch (\Throwable $e) {
        // Table missing (pre-migration) or DB error.
        return null;
    }
    if ($json === null || $json === '') return null;
    $state = json_decode((string)$json, true);
    if (!is_array($state)) return null;

    return [
        'q'      => isset($state['q']) ? (string)$state['q'] : '',
        'sort'   => isset($state['sort']) ? (string)$state['sort'] : '',
        'dir'    => (isset($state['dir']) && strtolower($state['dir']) === 'desc') ? 'desc' : 'asc',
        'size'   => isset($state['size']) ? (int)$state['size'] : null,
        'col'    => (isset($state['col']) && is_array($state['col'])) ? $state['col'] : [],
        'client' => (isset($state['client']) && is_array($state['client'])) ? $state['client'] : [],
    ];
}

/**
 * Persist the view state. $state is the decoded request body coming from
 * the JS client. We re-encode a whitelisted, normalised subset so a
 * malformed/oversized payload can't be stored verbatim.
 */
function user_dt_view_save($userId, $dtId, array $state)
{
    $userId = (int)$userId;
    $dtId   = trim((string)$dtId);
    if ($userId <= 0 || $dtId === '') return false;

    // Normalise + clamp the incoming state so we never store junk.
    $clean = [
        'q'    => isset($state['q']) ? mb_substr((string)$state['q'], 0, 200) : '',
        'sort' => isset($state['sort']) ? mb_substr((string)$state['sort'], 0, 120) : '',
        'dir'  => (isset($state['dir']) && strtolower((string)$state['dir']) === 'desc') ? 'desc' : 'asc',
        'size' => isset($state['size']) ? (int)$state['size'] : null,
        'col'    => user_dt_view__clean_map($state['col'] ?? null),
        'client' => user_dt_view__clean_map($state['client'] ?? null),
    ];

    // If the entire state is empty (no filters, no custom sort/size), clear
    // the row instead of storing an empty blob — keeps the table tidy and
    // means "remove all filters" naturally forgets the saved view.
    if ($clean['q'] === '' && $clean['sort'] === '' && $clean['size'] === null
        && empty($clean['col']) && empty($clean['client'])) {
        return user_dt_view_clear($userId, $dtId);
    }

    $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || strlen($json) > 8000) return false;

    try {
        db_exec(
            "INSERT INTO user_dt_view_state (user_id, dt_id, state_json)
                  VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE state_json = VALUES(state_json)",
            [$userId, $dtId, $json]
        );
        return true;
    } catch (\Throwable $e) {
        error_log('[user_dt_view] save failed: ' . $e->getMessage());
        return false;
    }
}

/** Delete the saved view for (user, datatable). */
function user_dt_view_clear($userId, $dtId)
{
    $userId = (int)$userId;
    $dtId   = trim((string)$dtId);
    if ($userId <= 0 || $dtId === '') return false;
    try {
        db_exec(
            "DELETE FROM user_dt_view_state WHERE user_id = ? AND dt_id = ?",
            [$userId, $dtId]
        );
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Normalise a colKey→value filter map: string keys, string values,
 * trimmed, length-capped, empties dropped, total entries capped.
 */
function user_dt_view__clean_map($raw)
{
    if (!is_array($raw)) return [];
    $out = [];
    $n = 0;
    foreach ($raw as $k => $v) {
        if ($n >= 60) break;                 // sane upper bound on column count
        $key = mb_substr((string)$k, 0, 120);
        if ($key === '') continue;
        if (is_array($v) || is_object($v)) continue;
        $val = trim((string)$v);
        if ($val === '') continue;
        $out[$key] = mb_substr($val, 0, 200);
        $n++;
    }
    return $out;
}
