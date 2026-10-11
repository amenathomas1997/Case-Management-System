<?php
declare(strict_types=1);
// Automatic progress reports on each track's own cadence (weekly, biweekly,
// or monthly; 'none' = manual only). Runs DAILY and sends whichever tracks
// are due, so one job covers every cadence. Cron: 30 7 * * *
if (PHP_SAPI !== 'cli') exit('CLI only.');

// Where the application's inc/ directory is: an optional cron_config.php beside
// this script (it returns the path) wins, then the production path, then the
// repository layout. Nothing needs editing here for a different install.
foreach ([
    is_file(__DIR__ . '/cron_config.php') ? (string)(require __DIR__ . '/cron_config.php') : '',
    '/home/cmsuser/public_html/casemanagement/inc',
    dirname(__DIR__) . '/public/inc',
] as $inc) {
    $inc = rtrim($inc, '/');
    if ($inc !== '' && is_file($inc . '/core.php')) { require $inc . '/core.php'; break; }
}
if (!function_exists('db')) {
    fwrite(STDERR, "CMS cron: the application's inc/core.php was not found. Create cron_config.php beside this script containing: <?php return '/full/path/to/your/web/docroot/inc';\n");
    exit(1);
}
require $inc . '/report_builder.php';

// A closed or archived client's track that was left Active gets no automatic
// report: the report carries the full record, and the counselor on file may be stale.
// A counselor email that came from the public referral form is unconfirmed
// (counselor_verified = 0) until staff confirm it on the client's edit page; no
// report goes to it before then, since anyone can type any address on that form.
$tracks = db()->query("SELECT t.id, t.title, t.auth_number, t.report_cadence, t.last_report_at, t.start_date, t.created_at,
        c.id AS cid, c.name AS client_name, c.counselor_email, c.counselor_verified
    FROM training_tracks t JOIN clients c ON c.id = t.client_id
    WHERE t.status = 'Active' AND t.report_cadence <> 'none'
      AND c.archived_at IS NULL AND c.status NOT LIKE 'Closed%'")->fetchAll();

$minDays = ['weekly' => 7, 'biweekly' => 14, 'monthly' => 28];
$sent = 0; $skipped = []; $skipMarks = [];
$note = db()->prepare('INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?,?,?,?,NULL)');
// last_report_at means "a report was emailed that day" (compliance and the app
// show it as the last report), so it is written only after a successful send.
$mark = db()->prepare('UPDATE training_tracks SET last_report_at = CURDATE() WHERE id = ?');
// A track with no counselor email stays due, so compliance keeps listing it; the
// administrators are told about it once per cadence period, not every day.
$noticed = db()->prepare('SELECT 1 FROM sent_alerts WHERE track_id = ? AND alert_key = ? LIMIT 1');
$notice = db()->prepare('INSERT IGNORE INTO sent_alerts (track_id, alert_key) VALUES (?,?)');

foreach ($tracks as $t) {
    $cadence = $t['report_cadence'] ?? 'monthly';
    $gap = $minDays[$cadence] ?? 28;
    // Due when the last report (or, before the first one, the track's start —
    // its start date, or the day it was entered if that is later, so a new track
    // gets its first full period) is at least the cadence gap old.
    $since = $t['last_report_at'] ?? max((string)$t['start_date'], substr((string)$t['created_at'], 0, 10));
    $age = (int)floor((time() - strtotime($since)) / 86400);
    // Sent on or after the due date the compliance page and the app show
    // (report_due_date(): one cadence period after $since; monthly keeps its
    // familiar 1st-of-month rhythm).
    if (date('Y-m-d') < report_due_date($cadence, (string)$since)) continue;

    $to = strtolower(trim($t['counselor_email'] ?? ''));
    $unconfirmed = filter_var($to, FILTER_VALIDATE_EMAIL) && empty($t['counselor_verified']);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || $unconfirmed) {
        $key = ($unconfirmed ? 'rv' : 'rs') . date('Ymd', strtotime($since)) . '-' . intdiv($age, $gap);
        $noticed->execute([(int)$t['id'], $key]);
        if (!$noticed->fetchColumn()) {
            $skipped[] = "{$t['client_name']} — {$t['title']}: "
                . ($unconfirmed ? 'the counselor email has not been confirmed (confirm it on the client\'s edit page)' : 'no counselor email on file');
            $skipMarks[] = [(int)$t['id'], $key];
        }
        continue;
    }
    // One track that cannot be prepared or recorded (a lock wait, a lost
    // connection) never stops the others: it is listed for the administrators.
    // Only the class and code are logged, never client data.
    $emailed = false;
    try {
        $label = ['weekly' => 'weekly', 'biweekly' => 'two-week', 'monthly' => 'monthly'][$cadence];
        // The report lists every session and the totals since the track began, so
        // the introduction says so rather than naming only the last period.
        $report = build_track_report((int)$t['id'], brand('org'),
            "Automatic {$label} progress report: every session and the totals from the start of this authorization to " . fmt_date(date('Y-m-d')) . '.'
            . ($t['last_report_at'] ? ' The previous report was sent on ' . fmt_date((string)$t['last_report_at']) . '.' : ''));
        if (!$report) continue;
        $ackUrl = create_report_ack((int)$t['cid'], (int)$t['id'], $to);
        [, $ackLine] = email_template('report_cover', ['ack' => $ackUrl]);
        $body = $report['text'] . "\n\n---\n" . $ackLine;
        if (!send_app_mail($to, $report['subject'], $body)) {
            // Nothing was sent, so no receipt is waiting: drop the unacknowledged ack
            // row (as report.php does) instead of listing it on the counselor portal.
            db()->prepare('DELETE FROM report_acks WHERE token = ? AND acked_at IS NULL')
                ->execute([(string)substr($ackUrl, (int)strrpos($ackUrl, '=') + 1)]);
            $skipped[] = "{$t['client_name']} — {$t['title']}: send failed to {$to}";
            continue;
        }
        $emailed = true;
        $sent++;
        // The report has gone: the note and last_report_at are written together
        // (as report.php does), so a failure leaves neither half behind.
        db()->beginTransaction();
        $mark->execute([(int)$t['id']]);
        $note->execute([
            (int)$t['cid'], 'Monthly Summary',
            'Automatic ' . $label . ' report emailed to VRC',
            ucfirst($label) . " report for track “{$t['title']}” (Auth {$t['auth_number']}) emailed to {$to} on " . date('M j, Y') . '.',
        ]);
        db()->commit();
    } catch (Throwable $e) {
        try { if (db()->inTransaction()) db()->rollBack(); } catch (Throwable $ignored) {}
        error_log('monthly_reports: track ' . (int)$t['id'] . ' failed (' . get_class($e) . ' ' . $e->getCode() . ')');
        if ($emailed) {
            // The counselor already has this report: record the send on its own so
            // tomorrow's run does not email it again, and ask for the note by hand.
            try { $mark->execute([(int)$t['id']]); } catch (Throwable $ignored) {}
            $skipped[] = "{$t['client_name']} — {$t['title']}: report sent to {$to} but not recorded on the client's file; add the note by hand";
        } else {
            $skipped[] = "{$t['client_name']} — {$t['title']}: the report could not be prepared; it will be tried again tomorrow";
        }
    }
}

if ($skipped) {
    // Administrators only: the list names clients, so it must not go to every
    // active account regardless of caseload or permissions.
    if (notify_admins('Automatic VRC reports: ' . count($skipped) . ' could not be sent',
            "The report scheduler sent {$sent} report(s) but skipped:\n\n- " . implode("\n- ", $skipped) . "\n")) {
        foreach ($skipMarks as [$tid, $key]) $notice->execute([$tid, $key]);
    }
}
echo "Sent {$sent} report(s); skipped " . count($skipped) . ".\n";
