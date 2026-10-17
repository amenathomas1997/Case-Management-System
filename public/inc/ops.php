<?php
declare(strict_types=1);
// Running the system safely (3.0.53): the mail log, the automatic backup check, and the
// Microsoft Graph secret's expiry date. Shown on System health (system_health.php).

/* ---------- Page errors (4.19.0): recorded for the watchdog, which emails the administrators ---------- */

/** The error record, in the private folder (never the web folder), or null when there is none. */
function app_error_log_path(): ?string {
    $dir = $GLOBALS['CMS_PRIVATE_DIR'] ?? null;
    if (!$dir) { $u = (string)(cfg('upload_dir') ?? ''); $dir = $u !== '' ? dirname($u) : null; }
    return $dir ? rtrim((string)$dir, '/') . '/app_errors.log' : null;
}

/**
 * Record that a page or job failed: the kind of failure and where in the code, never its message (which
 * can quote client data). The same failure in the same place is recorded at most once a minute, so a
 * flood (a bot sending bad requests) cannot push real errors out of the record.
 */
function app_error_record(string $kind, string $file, int $line): void {
    try {
        $path = app_error_log_path();
        if ($path === null) return;
        $page = PHP_SAPI === 'cli' ? 'cron/' . basename((string)($_SERVER['SCRIPT_NAME'] ?? 'job')) : basename((string)parse_url((string)($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH));
        $entry = ['at' => date('c'), 'kind' => mb_substr($kind, 0, 80), 'where' => basename($file) . ':' . $line, 'page' => mb_substr($page, 0, 60)];
        $lock = @fopen($path . '.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) return;
        try {
            $seen = json_decode((string)stream_get_contents($lock), true) ?: [];
            $key = md5($entry['kind'] . '|' . $entry['where'] . '|' . $entry['page']);
            $minute = (int)floor(time() / 60);
            if (($seen[$key] ?? -1) === $minute) return; // already recorded this minute
            $seen = array_filter($seen, fn($m) => $m >= $minute - 1); // only the last minute or two are needed
            $seen[$key] = $minute;
            ftruncate($lock, 0); rewind($lock); fwrite($lock, (string)json_encode($seen));
            // Rotated under the same lock, so two pages never both rename it.
            if (is_file($path) && filesize($path) > 1048576) @rename($path, $path . '.1');
            @file_put_contents($path, json_encode($entry) . "\n", FILE_APPEND);
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    } catch (Throwable $e) {}
}

/**
 * Errors to tell the administrators about now: [the time to record once they are told, the errors], or
 * null when there are none, or when they were told less than an hour ago (what is new waits for the next
 * hour; nothing is lost). The cursor moves only when an alert was sent (app_errors_told).
 */
function app_errors_due(int $now): ?array {
    // The cursor is the second up to which errors were told: the next ones start after it.
    $told = (int)(setting('errors_cursor') ?? 0);
    $cursor = max($told ? $told + 1 : $now - 15 * 60, $now - 7 * 86400);
    if ((int)(setting('errors_alerted_at') ?? 0) > $now - 3600) return null;
    $errs = app_errors_since($cursor);
    return $errs ? [$now, $errs, $cursor] : null;
}

/** The administrators were told about the errors up to $upTo. */
function app_errors_told(int $upTo): void {
    set_setting('errors_cursor', (string)$upTo);
    set_setting('errors_alerted_at', (string)$upTo);
}

/** Errors recorded since $since (a timestamp), oldest first: [['at', 'kind', 'where', 'page'], ...]. */
function app_errors_since(int $since): array {
    $path = app_error_log_path();
    $out = [];
    foreach ([$path . '.1', $path] as $f) {
        if ($path === null || !is_file($f)) continue;
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $e = json_decode($line, true);
            if (is_array($e) && strtotime((string)($e['at'] ?? '')) >= $since) $out[] = $e;
        }
    }
    return $out;
}

/** The same errors counted by kind and place: "PDOException at clients.php:120 (clients.php), 3 times". */
function app_errors_summary(array $errors): array {
    $count = [];
    foreach ($errors as $e) {
        $k = ($e['kind'] ?? '?') . ' at ' . ($e['where'] ?? '?') . (($e['page'] ?? '') !== '' ? ' (' . $e['page'] . ')' : '');
        $count[$k] = ($count[$k] ?? 0) + 1;
    }
    arsort($count);
    return array_map(fn($k, $n) => $k . ($n > 1 ? ", $n times" : ''), array_keys($count), $count);
}

// A fatal error (out of memory, a timeout, a missing function) ends a page without reaching any handler.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) app_error_record('Fatal error', (string)$e['file'], (int)$e['line']);
});

/** What kind of recipient an address is, for the mail log (the address itself is never kept). */
function mail_recipient_type(string $to): string {
    $to = strtolower(trim($to));
    if ($to === strtolower(trim((string)cfg('backup_email')))) return 'backup';
    try {
        $q = db()->prepare('SELECT 1 FROM users WHERE LOWER(email) = ? LIMIT 1');
        $q->execute([$to]);
        if ($q->fetchColumn()) return 'staff';
        $q = db()->prepare('SELECT CASE WHEN LOWER(email) = ? OR LOWER(guardian_email) = ? THEN \'client\' ELSE \'counselor\' END FROM clients
            WHERE LOWER(email) = ? OR LOWER(guardian_email) = ? OR LOWER(counselor_email) = ? LIMIT 1');
        $q->execute([$to, $to, $to, $to, $to]);
        $t = $q->fetchColumn();
        if ($t) return (string)$t;
    } catch (Throwable $e) { /* the log never stops a send */ }
    return 'other';
}

/** Record one send in the mail log, and tell the administrators (once a day) when Microsoft Graph failed and the server's own mail was used. */
function mail_log_record(string $to, string $via, bool $ok, bool $attachment = false): void {
    try {
        db()->prepare('INSERT INTO mail_log (recipient_type, via, ok, with_attachment) VALUES (?,?,?,?)')->execute([mail_recipient_type($to), $via, $ok ? 1 : 0, $attachment ? 1 : 0]);
        if ($via === 'mail' && graph_cfg() !== null && setting('mail_fallback_noticed') !== date('Y-m-d')) {
            set_setting('mail_fallback_noticed', date('Y-m-d'));
            notify_admin_users('Email fell back from Microsoft Graph to the server\'s own mail today. Check the Graph settings on System health; mail sent this way is often marked as junk.', 'system_health.php#email-h');
        }
    } catch (Throwable $e) { /* mig_3.0.53 not run yet, or the database is down */ }
}

/** The last $n sends, newest first. */
function mail_log_recent(int $n = 30): array {
    try { return db()->query('SELECT * FROM mail_log ORDER BY id DESC LIMIT ' . max(1, min(200, $n)))->fetchAll(); }
    catch (Throwable $e) { return []; }
}

/**
 * Check that the newest database backup can be restored: it decompresses to the end, it
 * holds a CREATE TABLE for every table in the live database, and (when a backup password is
 * set) an encrypted copy opens again with it. With $CONFIG['restore_test_dsn'] set to a
 * separate scratch database, the dump is also imported there and its row counts compared.
 * Returns ['ok' => bool, 'detail' => string]; the result is recorded for System health.
 */
function backup_check(bool $import = true): array {
    $dir = dirname((string)cfg('upload_dir')) . '/backups';
    $dumps = glob($dir . '/cms_*.sql.gz') ?: [];
    rsort($dumps);
    $result = function (bool $ok, string $detail): array {
        try { set_setting('backup_check_at', date('c')); set_setting('backup_check_result', ($ok ? 'OK: ' : 'PROBLEM: ') . mb_substr($detail, 0, 900)); } catch (Throwable $e) {}
        return ['ok' => $ok, 'detail' => $detail];
    };
    if (!$dumps) return $result(false, 'No database backup was found to check.');
    $latest = $dumps[0];
    $gz = @gzopen($latest, 'rb');
    if (!$gz) return $result(false, basename($latest) . ' could not be opened.');
    $tables = []; $bytes = 0; $tail = '';
    while (!gzeof($gz)) {
        $chunk = gzread($gz, 1 << 20);
        if ($chunk === false) { gzclose($gz); return $result(false, basename($latest) . ' is damaged: it stops part way through.'); }
        $bytes += strlen($chunk);
        // The end of the last piece is kept, so a CREATE TABLE split between two pieces is still found.
        if (preg_match_all('/CREATE TABLE `([^`]+)`/', $tail . $chunk, $m)) foreach ($m[1] as $t) $tables[$t] = true;
        $tail = substr($chunk, -300);
    }
    gzclose($gz);
    // A table created after the dump was made (a release installed since) is not expected in it.
    // (MariaDB also resets CREATE_TIME when a table is rebuilt; such a table is then not checked.)
    $dumpTime = date('Y-m-d H:i:s', (int)filemtime($latest));
    $lq = db()->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (CREATE_TIME IS NULL OR CREATE_TIME <= ?)');
    $lq->execute([$dumpTime]);
    $live = $lq->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff($live, array_keys($tables)));
    if ($missing) return $result(false, basename($latest) . ' is missing ' . count($missing) . ' table' . (count($missing) === 1 ? '' : 's') . ': ' . implode(', ', array_slice($missing, 0, 10)) . '.');
    $detail = basename($latest) . ' opens completely (' . round($bytes / 1048576, 1) . ' MB of SQL) and holds all ' . count($live) . ' tables.';
    // The encryption the off-site copy uses: a small encrypted ZIP must open again with the password.
    $password = (string)(cfg('backup_password') ?? '');
    if ($password !== '' && class_exists('ZipArchive')) {
        $tmp = @tempnam(sys_get_temp_dir(), 'cmsbk');
        $z = new ZipArchive();
        $made = $tmp && $z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true && $z->setPassword($password)
            && $z->addFromString('check.txt', 'backup check') && $z->setEncryptionName('check.txt', ZipArchive::EM_AES_256) && $z->close();
        $r = new ZipArchive();
        $opened = $made && $r->open($tmp) === true && $r->setPassword($password) && $r->getFromName('check.txt') === 'backup check';
        if ($opened) $r->close();
        if ($tmp) @unlink($tmp);
        if (!$opened) return $result(false, $detail . ' But an encrypted copy could not be made and opened again with the backup password.');
        $detail .= ' An encrypted copy opens with the backup password.';
    } else {
        $detail .= ' No backup password is set, so the off-site copy is not sent.';
    }
    // The full test restore runs from the Monday backup job only: it is too slow for a web request.
    $dsn = $import ? (string)(cfg('restore_test_dsn') ?? '') : '';
    if ($dsn !== '') {
        try {
            $t = new PDO($dsn, (string)cfg('restore_test_user'), (string)cfg('restore_test_password'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $name = (string)$t->query('SELECT DATABASE()')->fetchColumn();
            $liveName = (string)db()->query('SELECT DATABASE()')->fetchColumn();
            if ($name === '' || $name === $liveName) return $result(false, $detail . ' The test restore was refused: restore_test_dsn must name a separate scratch database.');
            $t->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($t->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $old) $t->exec('DROP TABLE `' . str_replace('`', '', $old) . '`');
            $gz = gzopen($latest, 'rb'); $stmt = '';
            while (($line = gzgets($gz)) !== false) {
                if (str_starts_with($line, '--') || trim($line) === '') continue;
                $stmt .= $line;
                if (preg_match('/;\s*$/', $line)) { $t->exec($stmt); $stmt = ''; }
            }
            gzclose($gz);
            $diff = [];
            foreach (['clients', 'training_tracks', 'work_logs', 'invoices', 'client_notes'] as $tb) {
                $a = (int)db()->query("SELECT COUNT(*) FROM `$tb`")->fetchColumn();
                $b = (int)$t->query("SELECT COUNT(*) FROM `$tb`")->fetchColumn();
                // Records change between the night's backup and now, both ways.
                if (abs($b - $a) > max(50, (int)ceil($a * 0.05))) $diff[] = "$tb $b (live $a)";
            }
            // The scratch copy holds client records: emptied once compared.
            foreach ($t->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $old) $t->exec('DROP TABLE `' . str_replace('`', '', $old) . '`');
            if ($diff) return $result(false, $detail . ' The test restore loaded, but these counts are far from the live database: ' . implode('; ', $diff) . '.');
            $detail .= ' A test restore into the scratch database loaded and its counts match.';
        } catch (Throwable $e) {
            return $result(false, $detail . ' The test restore into the scratch database failed (' . get_class($e) . ').');
        }
    }
    return $result(true, $detail);
}

/** Days until the Microsoft Graph secret expires (Administration sets the date), or null when not recorded. */
function graph_secret_days_left(): ?int {
    $d = (string)(setting('graph_credential_expires') ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
    return (int)floor((strtotime($d) - strtotime('today')) / 86400);
}
