<?php
/**
 * MagDyn — Old Inventory Note-Attachment File Import Service (API version)
 *
 * Companion to OldInventoryNotesImportService. That importer brings over the
 * note rows and the attachment *metadata* (stored_path = "old_import/<tmp_name>")
 * but NOT the physical files. This service fills in the files by downloading
 * them straight from the old server over HTTP — no manual copying, no zip
 * upload required.
 *
 * How it works:
 *   1. Find every note_attachments row whose stored_path lives under
 *      "old_import/" (i.e. was created by the notes importer).
 *   2. Group them by tmp_name (the basename of stored_path) so each distinct
 *      physical file is fetched only once, even when several notes share it.
 *   3. For each file, call api_export_notes.php?action=attachment_file&tmp=...
 *      on the old server. The old server locates the file on disk by its
 *      original filename and streams it back; we write it to
 *      uploads/notes/old_import/<tmp_name>, exactly where note_attach.php
 *      expects it.
 *   4. Record the real byte size back onto the matching attachment rows.
 *
 * The run is idempotent and resumable: files already present on disk are
 * skipped, so re-running after a timeout simply continues where it left off.
 *
 * Usage:
 *   require_once __DIR__ . '/../services/OldInventoryNoteAttachmentsImportService.php';
 *   $svc    = new OldInventoryNoteAttachmentsImportService(current_user_id());
 *   $result = $svc->run();
 */

require_once __DIR__ . '/../includes/old_inventory_api.php';

class OldInventoryNoteAttachmentsImportService
{
    /** Sub-directory (under uploads/notes/) imported attachments live in. */
    private const IMPORT_SUBDIR = 'old_import';

    /** @var int  User on whose behalf the import runs (for logging only). */
    private int $actorId;

    /** @var int  Max number of files to process this run; 0 = all (no limit). */
    private int $limit = 0;

    /** @var callable|null  fn(string $phase, int $done, int $total) */
    private $onProgress = null;

    /** @var array  Accumulated log entries */
    private array $errors = [];

    /** @var array */
    private array $counts = [
        'att_total'  => 0,   // distinct physical files referenced by imported notes
        'downloaded' => 0,   // fetched from the old server this run
        'already'    => 0,   // already present on disk (skipped)
        'missing'    => 0,   // old server has no file on disk (404)
        'failed'     => 0,   // network / write error
        'bytes'      => 0,   // total bytes downloaded
    ];

    public function __construct(int $actorUserId)
    {
        $this->actorId = $actorUserId;
    }

    public function setProgressCallback(callable $cb): void
    {
        $this->onProgress = $cb;
    }

    /** Cap the run to the first N distinct files (0 = no limit). Handy for a test run. */
    public function setLimit(int $n): void
    {
        $this->limit = max(0, $n);
    }

    private function emitProgress(string $phase, int $done, int $total): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($phase, $done, $total);
        }
    }

    public function run(): array
    {
        $dir = $this->ensureImportDir();

        // Every attachment the notes importer created points at old_import/<tmp_name>.
        $rows = db_all(
            "SELECT id, stored_path, filename
               FROM note_attachments
              WHERE stored_path LIKE 'old_import/%'
              ORDER BY id"
        );

        // Group by tmp_name so each physical file is downloaded just once.
        $groups = [];   // tmp_name => ['filename' => string, 'ids' => int[]]
        foreach ($rows as $r) {
            $tmp = basename((string) $r['stored_path']);
            if ($tmp === '') {
                continue;
            }
            if (!isset($groups[$tmp])) {
                $groups[$tmp] = ['filename' => (string) $r['filename'], 'ids' => []];
            }
            $groups[$tmp]['ids'][] = (int) $r['id'];
        }

        $available = count($groups);
        if ($this->limit > 0 && $available > $this->limit) {
            $groups = array_slice($groups, 0, $this->limit, true);
            $this->log("Limited to the first {$this->limit} of {$available} files (test run).", 'warn');
        }

        $total = count($groups);
        $this->counts['att_total'] = $total;
        $this->log("Found {$available} distinct attachment files referenced by imported notes (" . count($rows) . " rows); processing {$total}.");
        $this->emitProgress('Attachments', 0, $total);

        if ($total === 0) {
            $this->log('Nothing to do — import the notes first (Running Notes → Import).', 'warn');
            return array_merge($this->counts, ['errors' => $this->errors]);
        }

        $done = 0;
        foreach ($groups as $tmp => $g) {
            try {
                $this->processOne($dir, (string) $tmp, $g);
            } catch (Throwable $e) {
                $this->counts['failed']++;
                $this->log("Attachment '{$g['filename']}' ({$tmp}): " . $e->getMessage(), 'error');
            }
            $done++;
            // Emit on every file: the streaming endpoint turns each event into a
            // flushed NDJSON line, which both drives the progress bar and keeps the
            // HTTP connection active so long downloads never hit an idle timeout.
            $this->emitProgress('Attachments', $done, $total);
        }
        $this->emitProgress('Attachments', $total, $total);

        $mb = number_format($this->counts['bytes'] / 1048576, 1);
        $this->log(
            "Done — downloaded: {$this->counts['downloaded']} ({$mb} MB), " .
            "already present: {$this->counts['already']}, " .
            "missing on source: {$this->counts['missing']}, " .
            "failed: {$this->counts['failed']}."
        );

        return array_merge($this->counts, ['errors' => $this->errors]);
    }

    /** Fetch (or skip) one physical file and sync its size onto the DB rows. */
    private function processOne(string $dir, string $tmp, array $g): void
    {
        $target = $dir . '/' . $tmp;

        // Already on disk from a previous run → just make sure size is recorded.
        if (is_file($target) && filesize($target) > 0) {
            $this->counts['already']++;
            $this->syncSize($g['ids'], (int) filesize($target));
            return;
        }

        $partFile = $target . '.part';
        $res = old_inventory_notes_api_download(
            ['action' => 'attachment_file', 'tmp' => $tmp],
            $partFile
        );

        if (empty($res['ok'])) {
            @unlink($partFile);
            $status = (int) ($res['status'] ?? 0);
            $msg    = (string) ($res['error'] ?? 'unknown error');
            if ($status === 404) {
                $this->counts['missing']++;
                $this->log("Attachment '{$g['filename']}' ({$tmp}): not on old server — {$msg}", 'warn');
            } else {
                $this->counts['failed']++;
                $this->log("Attachment '{$g['filename']}' ({$tmp}): download failed — {$msg}", 'error');
            }
            return;
        }

        // Atomically move the completed download into place.
        if (!@rename($partFile, $target)) {
            @unlink($partFile);
            throw new RuntimeException('Could not move downloaded file into uploads/notes/' . self::IMPORT_SUBDIR . '/.');
        }

        $size = (int) ($res['bytes'] ?? @filesize($target));
        $this->counts['downloaded']++;
        $this->counts['bytes'] += $size;
        $this->syncSize($g['ids'], $size);
    }

    /** Record the real byte size on every attachment row sharing this file. */
    private function syncSize(array $ids, int $size): void
    {
        if ($size <= 0 || empty($ids)) {
            return;
        }
        $in = implode(',', array_map('intval', $ids));
        db_exec("UPDATE note_attachments SET size_bytes = ? WHERE id IN ($in)", [$size]);
    }

    /** Create uploads/notes/old_import/ if missing; throw if it can't be made. */
    private function ensureImportDir(): string
    {
        $dir = dirname(__DIR__) . '/uploads/notes/' . self::IMPORT_SUBDIR;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create uploads/notes/' . self::IMPORT_SUBDIR . '/ — check filesystem permissions.');
        }
        return $dir;
    }

    private function log(string $message, string $level = 'info'): void
    {
        $this->errors[] = [
            'level'   => $level,
            'message' => $message,
            'time'    => date('H:i:s'),
        ];
    }
}
