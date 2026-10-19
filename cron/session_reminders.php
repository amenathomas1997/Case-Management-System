<?php
declare(strict_types=1);
// Session reminders: email client + instructor the day before a scheduled session.
// Each run reminds every session not yet reminded that starts between now and the
// end of tomorrow, so a session booked after the last run (or moved to another
// instructor, which sets reminder_sent back to 0), or one a missed run skipped, is
// reminded by the next run, whatever hour the host's clock runs it at. Run it
// morning and evening; reminder_sent stops a session being reminded twice.
// Cron: 15 7,17 * * *
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

// Staff are joined only while active: a deactivated account whose sessions were
// not yet transferred must not be emailed client details. The client is still
// reminded, and the administrators are asked to transfer or rebook the session.
// A staff member limited to their own caseload is emailed only while the client
// is still assigned to them (the rule the Schedule page and the calendar feed
// apply): after a reassignment the reminder goes to the client's current case
// worker instead, or to the administrators when there is none.
// A client who has since been closed or archived is not emailed; the instructor
// is still reminded, so the session can be cancelled. A client email copied from
// the public referral form (email_verified = 0) is not emailed either until staff
// confirm it: the instructor's reminder and the administrators' alerts say so.
// One run at a time: a manual run overlapping the scheduled one would otherwise email
// every client and instructor twice (reminder_sent is set only after sending).
if ((int)db()->query("SELECT GET_LOCK(CONCAT('cms_session_reminders_', MD5(DATABASE())), 0)")->fetchColumn() !== 1) {
    echo "Another session reminders run is still working; this run sent nothing.\n";
    exit(0);
}
// A client who prefers Phone or Postal mail (a11y_contact) is not emailed (3.0.40): the
// instructor's reminder says to call them, with their number.
$sessions = db()->query("SELECT s.*, c.name AS client_name, c.email AS client_email, c.email_verified AS client_email_verified,
        c.a11y_contact AS client_contact, c.phone AS client_phone, c.time_zone AS client_time_zone, c.state AS client_state, c.office_id AS client_office_id,
        (c.archived_at IS NULL AND c.status NOT LIKE 'Closed%') AS client_current,
        u.id AS active_staff_id, u.name AS staff_name, u.email AS staff_email,
        (u.id IS NOT NULL AND (g.id IS NULL OR g.own_caseload_only = 0 OR c.assigned_to = u.id)) AS staff_sees_client,
        a.name AS assignee_name, a.email AS assignee_email, pt.token AS portal_token
    FROM scheduled_sessions s
    JOIN clients c ON c.id = s.client_id
    LEFT JOIN portal_tokens pt ON pt.client_id = c.id AND (pt.expires_at IS NULL OR pt.expires_at > NOW())
    LEFT JOIN users u ON u.id = s.user_id AND u.is_active = 1
    LEFT JOIN `groups` g ON g.id = u.group_id
    LEFT JOIN users a ON a.id = c.assigned_to AND a.is_active = 1
    WHERE s.status = 'Scheduled' AND s.reminder_sent = 0
      AND s.start_at > NOW() AND s.start_at < DATE_ADD(CURDATE(), INTERVAL " . (cms_number('session_reminder_days') + 1) . " DAY)")->fetchAll();

$sent = 0; $failed = []; $unconfirmed = []; $uncertain = [];
// 4.28.0: an email whose outcome is unknown (Graph timed out or answered 5xx after it went out) may
// well have arrived, so it counts as sent: sending it again on the next run would remind the person
// twice. It is reported once below, so someone can check with that person by hand.
$mailOnce = function (string $to, string $subject, string $body, string $who) use (&$uncertain, &$s): bool {
    $o = send_app_mail_outcome($to, $subject, $body);
    if ($o === 'unknown') { $uncertain[] = [(int)$s['client_id'], $who . ' about ' . $s['client_name'] . "'s session on " . date('l, F j \\a\\t g:i A', strtotime((string)$s['start_at']))]; return true; }
    return $o === 'sent';
};
// Each recipient is recorded on its own (4.27.0, client_reminded_at and staff_reminded_at): a
// failure for one is retried for that one only, so the other is not emailed again every run,
// and a client address that cannot be emailed is reported once. Until the columns exist the
// whole session is retried as before. Once both sides are done the session is marked and the
// two times are cleared, so a later reassignment (reminder_sent back to 0) reminds both again.
$perRecipient = true;
try { db()->query('SELECT client_reminded_at, staff_reminded_at FROM scheduled_sessions LIMIT 0'); } catch (Throwable $e) { $perRecipient = false; }
$mark = db()->prepare('UPDATE scheduled_sessions SET reminder_sent = 1' . ($perRecipient ? ', client_reminded_at = NULL, staff_reminded_at = NULL' : '') . ' WHERE id = ?');
$markClient = $perRecipient ? db()->prepare('UPDATE scheduled_sessions SET client_reminded_at = NOW() WHERE id = ?') : null;
$markStaff = $perRecipient ? db()->prepare('UPDATE scheduled_sessions SET staff_reminded_at = NOW() WHERE id = ?') : null;
// An email template that does not use {day} says "tomorrow": a client must never
// be told that a session today is tomorrow's.
$sameDay = fn(string $text): string => (string)preg_replace_callback('/\btomorrow\b/i',
    fn(array $m): string => ctype_upper($m[0][0]) ? 'Today' : 'today', $text);
// The contact address the public pages' footer gives.
$contact = trim((string)(cfg('support_email') ?: cfg('mail_from')));
if (!filter_var($contact, FILTER_VALIDATE_EMAIL)) $contact = '';
// The built-in wording told the client to "call your instructor" without a number.
// It is replaced with the ways the client can actually reach us: their portal (when
// they have a working link) and the contact address.
$rescheduleOld = 'If you need to reschedule, please reply to this email or call your instructor.';
foreach ($sessions as $s) {
    $startTs = strtotime($s['start_at']);
    // Most sessions are tomorrow's; one booked or moved after the last run can be today's. With
    // reminders set further ahead (Lists and time limits, 4.6.0) the day and date are named.
    $officeDays = (int)round((strtotime(date('Y-m-d', $startTs) . ' 12:00') - strtotime(date('Y-m-d') . ' 12:00')) / 86400);
    $day = $officeDays <= 0 ? 'today' : ($officeDays === 1 ? 'tomorrow' : 'on ' . date('l, F j', $startTs));
    // The same day as a noun ("Tomorrow's session", "Friday, October 23's session").
    $dayNoun = $officeDays <= 0 ? 'today' : ($officeDays === 1 ? 'tomorrow' : date('l, F j', $startTs));
    // With the time zone (e.g. "2:00 PM CDT"): a remote client may be elsewhere.
    $when = date('l, F j \a\t g:i A T', $startTs);
    // The client's own clock (4.1.0): a client in another time zone reads their time, and
    // "today" or "tomorrow" by their calendar.
    $cz = client_zone($s);
    // Counted in days on the client's calendar: far ahead of the office (Guam), "tomorrow"
    // here can be two days away there, so the weekday is said instead.
    $clientDays = (int)zone_format($cz, fn() => (int)round((strtotime(date('Y-m-d', $startTs) . ' 12:00') - strtotime(date('Y-m-d') . ' 12:00')) / 86400));
    $clientDay = $clientDays <= 0 ? 'today' : ($clientDays === 1 ? 'tomorrow' : 'on ' . zone_date($cz, 'l, F j', $startTs));
    // A remote session says so and carries its join link (http(s) links only).
    $url = trim((string)($s['meeting_url'] ?? ''));
    $joinLink = !empty($s['is_remote']) && preg_match('~^https?://\S+$~i', $url) ? $url : '';
    $where = !empty($s['is_remote'])
        ? "\nWhere: online" . ($joinLink !== '' ? "\nJoin link: {$joinLink}" : '')
        : ($s['location'] ? "\nWhere: {$s['location']}" : '');
    $current = (bool)($s['client_current'] ?? true);
    $staffSees = !empty($s['staff_sees_client']);
    $anyAddress = false; $badAddress = false; $emailHeld = false;
    // null: nothing sent to that side this run; true or false: the send's outcome.
    $clientSent = null; $staffSent = null;
    $clientDone = $perRecipient && !empty($s['client_reminded_at']);
    $staffDone = $perRecipient && !empty($s['staff_reminded_at']);
    $prefersOther = in_array($s['client_contact'] ?? '', ['Phone', 'Postal mail'], true);
    // Reached another way: no client email, an unusable one, or a preference for phone or post.
    $callClient = $current && ($prefersOther || !filter_var((string)($s['client_email'] ?? ''), FILTER_VALIDATE_EMAIL));
    if ($s['client_email'] && $current && !$prefersOther) {
        if (empty($s['client_email_verified'])) {
            // Typed on the public referral form by whoever sent it and not yet
            // confirmed by staff: nothing is sent to it. The instructor's reminder
            // and the administrators' alert below ask for it to be confirmed.
            $emailHeld = true;
        } elseif ($clientDone) {
            // Already reminded (or reported) on an earlier run: only the staff side is retried.
            $anyAddress = true;
        } elseif (!filter_var($s['client_email'], FILTER_VALIDATE_EMAIL)) {
            // An address that cannot be emailed is skipped and reported below so someone
            // contacts the client directly: once, when per-recipient records exist.
            $anyAddress = true;
            $badAddress = true;
        } else {
            $anyAddress = true;
            // {when} and {where} keep their English labels for templates written
            // before 3.0.21; {date}, {time}, {location} and {join_link} are the bare
            // values, so a body written in another language can carry its own labels.
            // The instructor is named only when one is visible to this client; the
            // organization's name is never given as the instructor.
            $instructor = $staffSees ? trim((string)($s['staff_name'] ?? '')) : '';
            $portalToken = (string)($s['portal_token'] ?? '');
            $portalLink = preg_match('/^[a-f0-9]{48}$/D', $portalToken) ? cfg('base_url') . '/portal.php?t=' . $portalToken : '';
            [$subj, $body] = email_template('session_reminder_client', [
                'name' => $s['client_name'], 'when' => zone_date($cz, 'l, F j \a\t g:i A T', $startTs), 'where' => $where,
                // A marker for now: the line is dropped below, and prose elsewhere in an
                // administrator's own wording reads "your instructor" rather than a gap.
                'instructor' => $instructor !== '' ? $instructor : "\u{1F}instructor\u{1F}",
                'day' => $clientDay, 'date' => zone_date($cz, 'l, F j', $startTs), 'time' => zone_date($cz, 'g:i A T', $startTs),
                'location' => empty($s['is_remote']) ? (string)($s['location'] ?? '') : '', 'join_link' => $joinLink,
                'portal_link' => $portalLink, 'contact' => $contact,
            ]);
            // No instructor to name: the "Instructor:" line is left out, not left blank.
            if ($instructor === '') {
                $body = (string)preg_replace("/^[ \t]*Instructor:[ \t]*\x{1F}instructor\x{1F}[ \t]*\R/mu", '', $body);
                $body = str_replace("\u{1F}instructor\u{1F}", 'your instructor', $body);
                $subj = str_replace("\u{1F}instructor\u{1F}", 'your instructor', $subj);
            }
            $reschedule = $portalLink !== ''
                ? 'Need a different time? Use your portal: ' . $portalLink . ($contact !== '' ? "\nOr email us at " . $contact . '.' : '')
                : 'Need a different time? Reply to this email' . ($contact !== '' ? ' or email us at ' . $contact : '') . '.';
            $body = str_replace($rescheduleOld, $reschedule, $body);
            if ($clientDay === 'today') { $subj = $sameDay($subj); $body = $sameDay($body); }
            elseif ($clientDay !== 'tomorrow') {
                $later = fn(string $text): string => (string)preg_replace_callback('/\btomorrow\b/i', fn(array $m): string => ctype_upper($m[0][0]) ? ucfirst($clientDay) : $clientDay, $text);
                $subj = $later($subj); $body = $later($body);
            }
            $clientSent = $mailOnce($s['client_email'], $subj, $body, 'the client');
        }
    }
    $staffBody = "Session reminder.\n\nClient: {$s['client_name']}\nWhen: {$when}" . ($cz !== null ? "\nThe client's time: " . zone_date($cz, 'g:i A T', $startTs) : '') . "{$where}\n"
        . ($current ? '' : "\nThis client is closed or archived, so the client was not reminded. Cancel the session on the Schedule page if it no longer applies.\n")
        . ($emailHeld ? "\nThe client was not emailed this reminder: their email address came from the public referral form and has not been confirmed. "
            . "Confirm it on the client's edit page, and remind the client another way this time.\n" : '')
        . ($callClient && !$emailHeld ? "\nRemind the client yourself: " . ($prefersOther ? 'they prefer ' . strtolower((string)$s['client_contact']) : 'there is no email address to remind them at')
            . (trim((string)($s['client_phone'] ?? '')) !== '' ? '. Phone: ' . $s['client_phone'] : '') . ".\n" : '');
    $staffSubject = ucfirst($day) . ': training session — ' . date('g:i A T', $startTs);
    if ($staffDone) {
        $anyAddress = true;
    } elseif ($s['staff_email'] && $staffSees) {
        $anyAddress = true;
        $staffSent = $mailOnce($s['staff_email'], $staffSubject, $staffBody, 'the instructor');
    } elseif ($s['active_staff_id'] && !$staffSees) {
        // Still booked with a staff member who no longer has this client on their
        // caseload (the client was reassigned): they are not sent client details.
        $anyAddress = true;
        $stranded = "This session is still booked with {$s['staff_name']}, who no longer has this client on their caseload. "
            . "Cancel it on the Schedule page and book it again with the right instructor if it should go ahead: " . cfg('base_url') . "/schedule.php\n";
        $staffSent = ($s['assignee_email']
            ? $mailOnce($s['assignee_email'], $staffSubject, $staffBody . "\n" . $stranded, 'the case worker')
            : notify_admins(ucfirst($dayNoun) . "'s session is booked with staff who no longer have this client",
                $staffBody . "\n" . $stranded));
    } elseif ($s['user_id'] && !$s['active_staff_id']) {
        // The session still belongs to a deactivated (or removed) account, so no
        // staff member would hear about it: the administrators are asked to move it.
        // Any one administrator can act on it, so one successful email is enough.
        $anyAddress = true;
        $staffSent = notify_admins(ucfirst($dayNoun) . "'s session belongs to a deactivated account — reassign it",
            "A session {$day} is still assigned to a staff account that is no longer active, so no staff member was reminded about it.\n\n"
            . "Client: {$s['client_name']}\nWhen: {$when}{$where}\n\n"
            . "Transfer that account's caseload on its Users page (this moves its upcoming sessions too): " . cfg('base_url') . '/users.php?id=' . (int)$s['user_id'] . "\n"
            . "Or cancel the session on the Schedule page and book it again with an active instructor: " . cfg('base_url') . "/schedule.php\n");
    }
    // Mark only when every addressed recipient was sent a reminder. A failed
    // reminder is retried by each later run until the session starts, and the
    // failures are reported below instead of being left to look like a quiet success.
    if ($perRecipient) {
        // A client address that cannot be emailed is final for the client side.
        if ($clientSent === true || $badAddress) $markClient->execute([(int)$s['id']]);
        if ($staffSent === true) $markStaff->execute([(int)$s['id']]);
        $allSent = $clientSent !== false && $staffSent !== false;
    } else {
        $allSent = $clientSent !== false && $staffSent !== false && !$badAddress;
    }
    $label = $s['client_name'] . ' (' . $day . ' at ' . date('g:i A', $startTs);
    if ($allSent) {
        $mark->execute([(int)$s['id']]);
        if ($anyAddress) $sent++;
        if ($emailHeld) $unconfirmed[] = [(int)$s['client_id'], $label . ')'];
    }
    if (!$allSent || $badAddress) {
        $failed[] = [(int)$s['client_id'], $label . ($badAddress ? ', the client email address is not valid' : '')
            . ($emailHeld ? ', the client email address has not been confirmed' : '') . ')'];
    }
}
echo "Reminders sent for {$sent} session(s).\n";
// One alert per session, linked to its client's record (never the bare Schedule
// page): the alert names the client, and purging that record (client.php) removes
// the notifications that link to it.
foreach ($unconfirmed as [$clientId, $label]) {
    notify_admin_users('Session reminder not emailed to ' . $label . ': the client\'s email address came from the public referral form and is not confirmed yet. '
        . 'Confirm it on the client\'s edit page.', 'client.php?id=' . $clientId);
}
// Reported once: the session is marked, so a later run does not email it (or report it) again.
foreach ($uncertain as [$clientId, $who]) {
    notify_admin_users('A session reminder to ' . $who . ' may not have arrived (the mail service did not confirm it; it is not sent again, so it cannot arrive twice). '
        . 'Check with them if the session matters.', 'client.php?id=' . $clientId);
}
if ($uncertain) echo count($uncertain) . " reminder email(s) went out unconfirmed; they are not sent again (the administrators were told).\n";
if ($unconfirmed) echo count($unconfirmed) . " client(s) were not emailed: their email address has not been confirmed (the administrators were told).\n";
// Text messages (4.17.0), to clients who agreed: the same reminders (answerable with C, R or X),
// one text for homework assigned since the last run, and texts held overnight that may go now.
if (sms_enabled()) {
    $texted = 0; $textUnknown = [];
    try {
        $rows = db()->query("SELECT s.* FROM scheduled_sessions s JOIN clients c ON c.id = s.client_id
            WHERE s.status = 'Scheduled' AND s.sms_reminder_sent = 0 AND s.start_at > NOW()
              AND s.start_at < DATE_ADD(CURDATE(), INTERVAL " . (cms_number('session_reminder_days') + 1) . " DAY)
              AND c.sms_consent_at IS NOT NULL AND c.sms_opted_out_at IS NULL AND c.archived_at IS NULL AND c.status NOT LIKE 'Closed%'")->fetchAll();
        $markText = db()->prepare('UPDATE scheduled_sessions SET sms_reminder_sent = 1 WHERE id = ?');
        foreach ($rows as $s) {
            $c = sms_client((int)$s['client_id']);
            if (!$c || sms_client_blocked($c)) continue;
            $r = sms_to_client((int)$c['id'], sms_session_text($c, $s, 'reminder'), 'reminder', (int)$s['id']);
            // Marked once logged (sent, or held for the morning), so it is never texted twice. A text
            // the service did not confirm in time (4.28.0) may still arrive: marked too, and reported once.
            if (in_array($r['status'], ['sent', 'held', 'unknown'], true)) { $markText->execute([(int)$s['id']]); $texted++; }
            if ($r['status'] === 'unknown') $textUnknown[] = (int)$s['client_id'];
        }
        // New homework: one text per client, however many were assigned.
        $hw = db()->query("SELECT t.client_id, COUNT(*) n, GROUP_CONCAT(h.id) ids FROM homework h JOIN training_tracks t ON t.id = h.track_id JOIN clients c ON c.id = t.client_id
            WHERE h.sms_notified = 0 AND h.status = 'Assigned' AND h.assigned_at >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)
              AND c.sms_consent_at IS NOT NULL AND c.sms_opted_out_at IS NULL GROUP BY t.client_id")->fetchAll();
        foreach ($hw as $h) {
            $c = sms_client((int)$h['client_id']);
            if (!$c || sms_client_blocked($c)) continue;
            $n = (int)$h['n'];
            $es = client_lang($c) === 'es';
            $body = $es ? 'Hola ' . sms_first_name((string)$c['name']) . ', tiene ' . ($n === 1 ? 'una tarea nueva' : "$n tareas nuevas") . ' en su portal de ' . (brand('short') ?: cfg('name')) . '. Responda STOP para no recibir mensajes.'
                : 'Hi ' . sms_first_name((string)$c['name']) . ', you have ' . ($n === 1 ? 'new homework' : "$n new homework assignments") . ' in your ' . (brand('short') ?: cfg('name')) . ' portal. Reply STOP to stop texts.';
            $r = sms_to_client((int)$c['id'], $body, 'homework');
            if (in_array($r['status'], ['sent', 'held', 'unknown'], true)) db()->exec('UPDATE homework SET sms_notified = 1 WHERE id IN (' . implode(',', array_map('intval', explode(',', (string)$h['ids']))) . ')');
        }
        // Homework for clients who do not take texts, and homework older than two days, is marked too,
        // so a later agreement (or texting set up later) never texts old homework.
        db()->exec("UPDATE homework h JOIN training_tracks t ON t.id = h.track_id JOIN clients c ON c.id = t.client_id SET h.sms_notified = 1
            WHERE h.sms_notified = 0 AND (c.sms_consent_at IS NULL OR c.sms_opted_out_at IS NOT NULL OR h.assigned_at < DATE_SUB(CURDATE(), INTERVAL 2 DAY))");
    } catch (Throwable $e) {
        error_log('session_reminders: texts skipped (' . get_class($e) . ')');
    }
    foreach ($textUnknown as $clientId) {
        notify_admin_users('A session reminder text may not have arrived (the texting service did not confirm it; it is not sent again, so it cannot arrive twice). See Text messages for the details.', 'client.php?id=' . $clientId);
    }
    $flushed = sms_flush_held();
    echo "Texts: {$texted} reminder(s), {$flushed} held text(s) sent.\n";
}
// The run itself completed: its heartbeat is recorded even when a reminder email
// failed (those are reported below, and the mail failure is noted for System health).
record_heartbeat('session_reminders');
if ($failed) {
    echo count($failed) . " session reminder(s) could not be sent.\n";
    foreach ($failed as [$clientId, $label]) {
        notify_admin_users('A session reminder could not be emailed for ' . $label . '. Contact the client directly.', 'client.php?id=' . $clientId);
    }
    exit(1);
}
