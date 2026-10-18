<?php
declare(strict_types=1);
// Virus scanning of uploaded files (4.25.0), with ClamAV, when an administrator turns it on (Settings,
// Security: app.virus_scan). Off by default: shared hosts often have no ClamAV. Once on, every upload (staff,
// clients on the portal, counselors, the apps) is scanned before it is kept; a file that is infected, or that
// cannot be checked because the scanner is not answering, is refused, so nothing unscanned slips through.
// The setting is "clamd" (the ClamAV service, found at its usual places), a socket path, host:port, or the
// path of the clamscan or clamdscan program.

/** How to reach the scanner: ['socket', 'unix:///…' or 'tcp://…'], ['exec', path], or null when off. */
function virus_scan_target(): ?array {
    $v = trim((string)cfg('virus_scan'));
    if ($v === '' || strtolower($v) === 'off') return null;
    if (strtolower($v) === 'clamd') {
        foreach (['/var/run/clamav/clamd.ctl', '/var/run/clamd.scan/clamd.sock', '/run/clamav/clamd.ctl', '/var/run/clamav/clamd.sock', '/tmp/clamd.socket'] as $s) if (@file_exists($s)) return ['socket', 'unix://' . $s];
        return ['socket', 'tcp://127.0.0.1:3310'];
    }
    if (preg_match('/^[A-Za-z0-9.-]+:\d{2,5}$/', $v)) return ['socket', 'tcp://' . $v];
    if (str_starts_with($v, '/') && preg_match('/clam(d)?scan$/', $v)) return ['exec', $v];
    if (str_starts_with($v, '/')) return ['socket', 'unix://' . $v];
    return ['bad', $v];
}

/** Talk to clamd: send $command (and the file, for INSTREAM); the reply, or null when it does not answer. */
function virus_clamd(string $address, string $command, ?string $path = null): ?string {
    $s = @stream_socket_client($address, $errno, $errstr, 5);
    if (!$s) return null;
    stream_set_timeout($s, 60);
    fwrite($s, 'z' . $command . "\0");
    if ($path !== null) {
        $f = @fopen($path, 'rb');
        if (!$f) { fclose($s); return null; }
        while (!feof($f)) {
            $chunk = (string)fread($f, 65536);
            if ($chunk === '') break;
            fwrite($s, pack('N', strlen($chunk)) . $chunk);
        }
        fclose($f);
        fwrite($s, pack('N', 0));
    }
    $reply = (string)stream_get_contents($s);
    fclose($s);
    return $reply === '' ? null : rtrim($reply, "\0\r\n");
}

/** Scan one file: ['clean' => true|false|null (not checked), 'detail' => words]. */
function virus_scan_file(string $path): array {
    // The test harness (command line only) answers for the scanner.
    if (PHP_SAPI === 'cli' && ($fake = getenv('CMS_TEST_VIRUS')) !== false) return ['clean' => $fake === 'clean' ? true : ($fake === 'infected' ? false : null), 'detail' => $fake];
    $t = virus_scan_target();
    if ($t === null) return ['clean' => true, 'detail' => 'off'];
    if ($t[0] === 'bad') return ['clean' => null, 'detail' => 'the setting is not a scanner'];
    if ($t[0] === 'exec') {
        if (!is_executable($t[1]) || !function_exists('proc_open')) return ['clean' => null, 'detail' => 'the scanner program cannot be run'];
        // clamdscan streams the file to the ClamAV service (which runs as its own user and could not read PHP's
        // files); clamscan reads it itself. Either is stopped after two minutes, so a hung scanner refuses, not hangs.
        $args = str_ends_with($t[1], 'clamdscan') ? [$t[1], '--stream', '--no-summary', '--infected', $path] : [$t[1], '--no-summary', '--infected', $path];
        $p = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) return ['clean' => null, 'detail' => 'the scanner program cannot be run'];
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $out = ''; $until = microtime(true) + 120;
        while (true) {
            $st = proc_get_status($p);
            $out .= (string)stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
            if (!$st['running']) break;
            if (microtime(true) > $until) { proc_terminate($p); fclose($pipes[1]); fclose($pipes[2]); proc_close($p); return ['clean' => null, 'detail' => 'the scanner took too long']; }
            usleep(100000);
        }
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($p);
        $code = (int)$st['exitcode'];
        return $code === 0 ? ['clean' => true, 'detail' => 'clean'] : ($code === 1 ? ['clean' => false, 'detail' => trim($out)] : ['clean' => null, 'detail' => 'the scanner reported an error']);
    }
    $reply = virus_clamd($t[1], 'INSTREAM', $path);
    if ($reply === null) return ['clean' => null, 'detail' => 'the scanner did not answer'];
    if (str_ends_with($reply, 'OK')) return ['clean' => true, 'detail' => 'clean'];
    if (str_ends_with($reply, 'FOUND')) return ['clean' => false, 'detail' => $reply];
    return ['clean' => null, 'detail' => $reply];
}

/** Check an upload before it is kept: null when it may be kept, else what to tell the person. */
function upload_virus_check(string $path): ?string {
    $r = virus_scan_file($path);
    if ($r['clean'] === true) return null;
    if ($r['clean'] === false) {
        error_log('Upload refused: virus found (' . preg_replace('/[^A-Za-z0-9.:_ -]/', '', (string)$r['detail']) . ')');
        if (function_exists('notify_admin_users')) notify_admin_users('An upload was refused because the virus scanner found something in it.', 'system_health.php', true);
        return 'That file was refused: the virus scanner found something harmful in it.';
    }
    error_log('Upload refused: the virus scanner could not check it (' . $r['detail'] . ')');
    return 'That file could not be checked for viruses just now, so it was not accepted. Please try again later.';
}

/** For System health: [ok, words]. */
function virus_scan_health(): array {
    $t = virus_scan_target();
    if ($t === null) return [true, 'Off. Turn it on in Settings, Security, if this server has ClamAV.'];
    if ($t[0] === 'bad') return [false, 'The setting is not a scanner: use clamd, a socket path, host:port, or the path of clamscan.'];
    if ($t[0] === 'exec') return [is_executable($t[1]), is_executable($t[1]) ? 'On, with ' . $t[1] . '.' : 'On, but ' . $t[1] . ' cannot be run: uploads are refused until it can.'];
    $pong = virus_clamd($t[1], 'PING');
    return [$pong === 'PONG', $pong === 'PONG' ? 'On; the ClamAV service is answering.' : 'On, but the ClamAV service is not answering: uploads are refused until it does.'];
}
