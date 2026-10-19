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
    dirname(__DIR__, 2) . '/public_html/casemanagement/inc',
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
require_once $inc . '/scheduled_reports.php';

// One run at a time: a track is marked only after its report is emailed, so a
// manual run that overlaps the scheduled one would send each due report (and its
// receipt and case note) twice. The lock is named for this database, so another
// installation on the same database server is not held up; it ends with this process.
if ((int)db()->query("SELECT GET_LOCK(CONCAT('cms_monthly_reports_', MD5(DATABASE())), 0)")->fetchColumn() !== 1) {
    echo "Another progress report run is still working; this run sent nothing.\n";
    exit(0);
}

// A closed or archived client's track that was left Active gets no automatic
// report: the report carries the full record, and the counselor on file may be stale.
// A counselor email that came from the public referral form is unconfirmed
// (counselor_verified = 0) until staff confirm it on the client's edit page; no
// report goes to it before then, since anyone can type any address on that form.
$tracks = db()->query("SELECT t.id, t.title, t.auth_number, t.report_cadence, t.last_report_at, t.start_date, t.created_at,
        c.id AS cid, c.name AS client_name, c.counselor_email, c.counselor_verified, c.assigned_to
    FROM training_tracks t JOIN clients c ON c.id = t.client_id
    WHERE t.status = 'Active' AND t.report_cadence <> 'none'
      AND c.archived_at IS NULL AND c.status NOT LIKE 'Closed%'")->fetchAll();

// Owner decision (2026-10-16): a due report waits for a person to write a short narrative
// and send it from the track's Send report page. This job then only opens a task for the
// client's instructor (once per report period). Administrators can turn automatic sending
// back on (Administration > Scheduled reports, setting reports_auto_send).
$autoSend = (setting('reports_auto_send') ?? '0') === '1';
$tasked = 0;
$taskSeen = db()->prepare('SELECT 1 FROM tasks WHERE auto_key = ? LIMIT 1');
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
    $gap = report_cadence_days($cadence);
    // The words a report uses: two-week, or an added schedule's own name (4.11.0).
    $label = ['weekly' => 'weekly', 'biweekly' => 'two-week', 'monthly' => 'monthly'][$cadence] ?? mb_strtolower($cadence);
    // Due when the last report (or, before the first one, the track's start —
    // its start date, or the day it was entered if that is later, so a new track
    // gets its first full period) is at least the cadence gap old.
    $since = $t['last_report_at'] ?? max((string)$t['start_date'], substr((string)$t['created_at'], 0, 10));
    $age = (int)floor((time() - strtotime($since)) / 86400);
    // Sent on or after the due date the compliance page and the app show
    // (report_due_date(): one cadence period after $since; monthly keeps its
    // familiar 1st-of-month rhythm).
    if (date('Y-m-d') < report_due_date($cadence, (string)$since)) continue;
    if (!$autoSend) {
        $key = 'report-due-' . (int)$t['id'] . '-' . date('Ymd', strtotime((string)$since));
        $taskSeen->execute([$key]);
        if ($taskSeen->fetchColumn()) continue;
        if (add_task('Write and send the ' . $label . ' progress report: ' . $t['client_name'] . ' — ' . $t['title'],
                'The counselor\'s ' . $label . ' progress report is due. Open the training track, choose Send report, add a short narrative, sign it and send it.'
                . (filter_var(strtolower(trim((string)$t['counselor_email'])), FILTER_VALIDATE_EMAIL) && !empty($t['counselor_verified']) ? '' : ' The counselor email is missing or not yet confirmed: fix it on the client\'s edit page first.'),
                (int)$t['cid'], null, date('Y-m-d'), $t['assigned_to'] !== null ? (int)$t['assigned_to'] : null, null, $key)) $tasked++;
        continue;
    }

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
    // The manual send warns when no current release of information covers the counselor; an
    // automatic send has nobody to read that warning, so the track is skipped and listed for
    // the administrators, once per report period (4.27.0).
    if (function_exists('release_covering') && release_covering((int)$t['cid'], 'VR counselor and agency', $to) === null) {
        $key = 'rr' . date('Ymd', strtotime($since)) . '-' . intdiv($age, $gap);
        $noticed->execute([(int)$t['id'], $key]);
        if (!$noticed->fetchColumn()) {
            $skipped[] = "{$t['client_name']} — {$t['title']}: no current release of information covers the counselor (record or request one on the client page, then send the report from the track)";
            $skipMarks[] = [(int)$t['id'], $key];
        }
        continue;
    }
    // One track that cannot be prepared or recorded (a lock wait, a lost
    // connection) never stops the others: it is listed for the administrators.
    // Only the class and code are logged, never client data.
    $emailed = false;
    try {
        // The report lists every session and the totals since the track began, so
        // the introduction says so rather than naming only the last period.
        $report = build_track_report((int)$t['id'], brand('org'),
            "Automatic {$label} progress report: every session and the totals from the start of this authorization to " . fmt_date(date('Y-m-d')) . '.'
            . ($t['last_report_at'] ? ' The previous report was sent on ' . fmt_date((string)$t['last_report_at']) . '.' : ''));
        if (!$report) continue;
        $ackUrl = create_report_ack((int)$t['cid'], (int)$t['id'], $to, $report['text']);
        [, $ackLine] = email_template('report_cover', ['ack' => $ackUrl]);
        $body = $report['text'] . "\n\n---\n" . $ackLine;
        // An unknown outcome (4.28.0: Graph timed out or answered 5xx after it went out) may have
        // arrived: recorded as sent, never sent twice, and listed once for checking by hand.
        $outcome = send_app_mail_outcome($to, $report['subject'], $body);
        if ($outcome === 'unknown') $skipped[] = "{$t['client_name']} — {$t['title']}: the mail service did not confirm the report to {$to}; it is recorded as sent and not sent again, so check with the counselor that it arrived";
        if ($outcome === 'refused') {
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
echo $autoSend ? "Sent {$sent} report(s); skipped " . count($skipped) . ".\n" : "Opened {$tasked} progress report task(s) for staff to write and send.\n";

// Scheduled summary reports (Administration > Scheduled reports): the outcome
// report and the funder outcome report, emailed as plain text to the listed
// addresses on or after the chosen day of the month. Each period is marked sent
// (its stamp, 2026-09 or 2026-Q3, in a setting) only after a recipient received
// it, so a second run the same day, or a retried cron, never sends it twice, and
// a run missed on the chosen day is caught up on the next run that month. Still
// under the lock taken above. A failure here never affects the exit status of
// the track reports: it is reported and tried again tomorrow.
try {
    $srCfg = scheduled_reports_config();
    $srPeriod = scheduled_report_period($srCfg['period']);
    foreach (SCHEDULED_REPORTS as $srKey => $srTitle) {
        if (!$srCfg[$srKey] || !$srCfg['recipients'] || (int)date('j') < $srCfg['day']) continue;
        $sentKey = 'scheduled_reports_sent_' . $srKey;
        if (setting($sentKey) === $srPeriod['stamp']) continue;
        $body = scheduled_report_text($srTitle, $srPeriod['label'], scheduled_report_sections($srKey, $srPeriod['from'], $srPeriod['to']));
        $subject = $srTitle . ' for ' . $srPeriod['label'] . ' — ' . brand('org');
        $ok = []; $failed = []; $unsure = [];
        foreach ($srCfg['recipients'] as $to) {
            // 4.29.0: an unknown outcome (Graph timed out or answered 5xx after the request went
            // out) counts as sent, so the report is not mailed to everyone again tomorrow.
            $o = send_app_mail_outcome($to, $subject, $body);
            if ($o === 'sent') $ok[] = $to; elseif ($o === 'unknown') { $ok[] = $to; $unsure[] = $to; } else $failed[] = $to;
        }
        if ($unsure) {
            // Said once: the period is marked below, so it is not tried (or reported) again.
            notify_admins('Scheduled ' . strtolower($srTitle) . ': delivery not confirmed for ' . count($unsure) . ' address(es)',
                "The mail service did not confirm delivery of the scheduled {$srTitle} for {$srPeriod['label']} to:\n\n- " . implode("\n- ", $unsure)
                . "\n\nIt may have arrived, so it is not sent again automatically. Ask the recipient, and forward it from another recipient if it did not arrive.\n");
        }
        if ($ok) {
            set_setting($sentKey, $srPeriod['stamp']);
            audit(null, 'report.scheduled', 'settings', null, $srTitle . ' ' . $srPeriod['stamp'] . ' to ' . count($ok) . ' address(es)' . ($failed ? ', ' . count($failed) . ' failed' : ''));
        }
        echo date('Y-m-d H:i:s') . " scheduled {$srTitle} ({$srPeriod['stamp']}): sent to " . count($ok) . ' address(es)' . ($failed ? ', failed for ' . count($failed) : '') . ".\n";
        if ($failed) {
            notify_admins('Scheduled ' . strtolower($srTitle) . ': ' . count($failed) . ' address(es) could not be emailed',
                "The scheduled {$srTitle} for {$srPeriod['label']} could not be delivered to:\n\n- " . implode("\n- ", $failed)
                . ($ok ? "\n\nIt was delivered to the other " . count($ok) . " address(es), so it will not be resent automatically; forward it from one of them or correct the address under Administration > Scheduled reports.\n"
                       : "\n\nNobody received it, so it will be tried again on the next run.\n"));
        }
    }
} catch (Throwable $e) {
    error_log('monthly_reports: scheduled summary reports failed (' . get_class($e) . ' ' . $e->getCode() . ')');
    echo "The scheduled summary reports could not be prepared; they will be tried again tomorrow.\n";
}
record_heartbeat('monthly_reports');
