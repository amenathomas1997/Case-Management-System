<?php
// VR counselor self-service portal — tokenized link + emailed access code.
// Read-only view of the counselor's own caseload: authorizations, progress, reports.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
// A counselor has no account to sign back into. When the form's token no longer
// matches (the session lapsed while the page sat open), nothing is sent or
// checked and the portal comes back with the message kept (see below). It is
// kept as a draft, not through keep_input() as csrf_check() would, because the
// counselor must verify again first and old input lasts one page only.
$postedCsrf = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$csrfStale = $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SESSION['user_id'])
    && !(is_string($postedCsrf) && hash_equals(csrf_token(), $postedCsrf));
if (!$csrfStale) csrf_check();
// The page's own "stay in the portal" request (a script request: JSON only).
$keepalive = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['keepalive']);

$rawToken = $_GET['t'] ?? '';
$token = is_string($rawToken) ? preg_replace('/[^a-f0-9]/', '', $rawToken) : '';
$vrc = null;
if (strlen($token) === 48) {
    $s = db()->prepare('SELECT * FROM vrc_tokens WHERE token = ? AND (expires_at IS NULL OR expires_at > NOW())');
    $s->execute([$token]);
    $vrc = $s->fetch();
}
if (!$vrc && $keepalive) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false]);
    exit;
}
if (!$vrc) {
    // One message for every reason (unknown, expired or replaced), so the page never
    // says whether a link ever existed.
    page_header('Portal link not recognized');
    $contact = (string)(cfg('support_email') ?: cfg('mail_from'));
    $phoneContact = support_contact_html(); // "Call {phone} or email {email}" once a support phone is set
    echo '<div class="card auth-card"><h1>Link not recognized</h1><p>This portal link is not valid, has expired, or was replaced by a newer one.</p>'
        . ($phoneContact !== '' ? '<p>' . $phoneContact . ' for a new portal link.</p>'
            : ($contact !== '' ? '<p><a href="mailto:' . h($contact) . '">Email ' . h(brand('short')) . ' for a new portal link</a> (' . h($contact) . ').</p>'
            : '<p>Contact ' . h(brand('short')) . ' for a new portal link.</p>'))
        . '</div>';
    page_footer();
    exit;
}

$sessionKey = 'vrc_ok_' . (int)$vrc['id'];
$codeKey = 'vrc_code_' . (int)$vrc['id'];
$draftKey = 'vrc_draft_' . (int)$vrc['id'];
/** Keep a message that could not be sent yet, and the client it is about, to be
 *  refilled after the counselor verifies again. Kept whole: an over-long message
 *  is refused (not cut) when it is sent. */
$keepDraft = function () use ($draftKey): bool {
    $msg = trim(is_string($_POST['message'] ?? null) ? $_POST['message'] : '');
    if ($msg === '') return false;
    $_SESSION[$draftKey] = ['message' => $msg, 'about_client' => (int)($_POST['about_client'] ?? 0)];
    return true;
};

if ($csrfStale) {
    if ($keepalive) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false]);
        exit;
    }
    // Only this site's own form is kept to refill; another site's post is dropped.
    $kept = isset($_POST['send_message']) && same_origin_post() && $keepDraft();
    flash($kept ? 'This page was open for a long time, so your message was not sent yet. It was kept, with the client it is about, and will be in the message box again (you may be asked to verify first).'
        : 'This page was open for a long time, so nothing was sent or checked yet. If you were entering a code, choose Email me a code to get a new one.', 'error');
    redirect('vrc.php?t=' . $token);
}

/* "Stay in the portal": extends a verified session that has not yet ended. */
if ($keepalive) {
    $live = !empty($_SESSION[$sessionKey]) && time() - (int)($_SESSION[$sessionKey . '_at'] ?? 0) <= 3600;
    if ($live) $_SESSION[$sessionKey . '_at'] = time();
    http_response_code($live ? 200 : 401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => $live]);
    exit;
}

/* Verified portal sessions time out after 60 minutes of inactivity (the caseload
   page warns 5 minutes ahead and offers to stay; see the dialog below). */
$draftKept = false;
if (!empty($_SESSION[$sessionKey])) {
    if (time() - (int)($_SESSION[$sessionKey . '_at'] ?? 0) > 3600) {
        unset($_SESSION[$sessionKey], $_SESSION[$sessionKey . '_at']);
        // A message typed before the timeout survives re-verification.
        if (isset($_POST['send_message']) && ($draftKept = $keepDraft())) {
            flash('Your portal session timed out. Verify again to continue — the message you typed, and the client it is about, were kept and will reappear in the form.', 'error');
        } else {
            flash('Your portal session timed out. Verify again to continue.', 'error');
        }
    } else {
        $_SESSION[$sessionKey . '_at'] = time();
    }
}

/* Access code verification (per browser session) */
if (empty($_SESSION[$sessionKey])) {
    // A message sent after the session had already ended (in another tab, say) is
    // kept for after verifying, as the page promised, and never dropped unseen.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message']) && !$draftKept) {
        flash($keepDraft()
            ? 'Your portal session had ended, so your message was not sent yet. Verify again to continue — the message you typed, and the client it is about, were kept and will reappear in the form.'
            : 'Your portal session had ended. Verify again to continue.', 'error');
        redirect('vrc.php?t=' . $token);
    }
    // Server-side limits per portal link, which no new browser session resets:
    // at most 10 codes a day (each allows 5 tries), and after 10 wrong codes in
    // 15 minutes the link refuses codes until that window passes (admins are told).
    $sendKey = 'vrc-send-' . (int)$vrc['id'];
    $failKey = 'vrc-fail-' . (int)$vrc['id'];
    $countSince = function (string $key, string $interval): int {
        $q = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL {$interval})");
        $q->execute([$key]);
        return (int)$q->fetchColumn();
    };
    // One rule for both buttons: while the link is paused, no code is sent or checked.
    $lockedOut = fn(): bool => $countSince($failKey, '15 MINUTE') >= 10;
    $lockedMsg = 'Too many incorrect codes were entered for this link. Wait 15 minutes, then send yourself a new code.';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_code'])) {
        // A code sent during the pause would only be thrown away on the next try,
        // and would use up one of the day's sends.
        if ($lockedOut()) {
            flash($lockedMsg, 'error');
            redirect('vrc.php?t=' . $token);
        }
        // Throttle: one code per 60 seconds per token, tracked server-side so
        // clearing cookies doesn't reset it (prevents inbox flooding).
        if ($countSince($sendKey, '60 SECOND') > 0) {
            flash('A code was just sent. Wait a minute before requesting another.', 'error');
            redirect('vrc.php?t=' . $token);
        }
        if ($countSince($sendKey, '24 HOUR') >= 10) {
            flash('Too many codes have been requested for this link today. Try again tomorrow, or contact ' . brand('short') . '.', 'error');
            redirect('vrc.php?t=' . $token);
        }
        db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)")
            ->execute([client_ip(), $sendKey]);
        $sendRow = (int)db()->lastInsertId();
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION[$codeKey] = ['hash' => password_hash($code, PASSWORD_DEFAULT), 'exp' => time() + 600, 'tries' => 0];
        [$subj, $body] = email_template('vrc_code', ['name' => $vrc['counselor_name'], 'code' => $code]);
        if (!send_app_mail($vrc['counselor_email'], $subj, $body)) {
            // Nothing went out: no code to wait for, and an immediate retry is allowed.
            unset($_SESSION[$codeKey]);
            db()->prepare('DELETE FROM login_attempts WHERE id = ?')->execute([$sendRow]);
            flash('We could not email a code just now. Please try again in a few minutes; if it keeps happening, contact ' . brand('short') . '.', 'error');
            redirect('vrc.php?t=' . $token);
        }
        flash('An access code was emailed to ' . $vrc['counselor_email'] . '. It works for 10 minutes.');
        redirect('vrc.php?t=' . $token);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_code'])) {
        if ($lockedOut()) {
            unset($_SESSION[$codeKey]);
            flash($lockedMsg, 'error');
            redirect('vrc.php?t=' . $token);
        }
        $c = $_SESSION[$codeKey] ?? null;
        $entered = preg_replace('/\D/', '', is_string($_POST['code'] ?? '') ? $_POST['code'] : '');
        // An empty or short entry is not a guess: it is said on the field and never
        // counts as a try or toward the link's lockout.
        if (!preg_match('/^\d{6}$/', $entered)) {
            keep_field_errors(['code' => $entered === '' ? 'Enter the 6-digit code from the email.' : 'The code has 6 digits. Check the email and enter all 6.']);
            redirect('vrc.php?t=' . $token);
        }
        $usedUp = $c && (int)($c['tries'] ?? 0) >= 5;
        if ($usedUp) {
            unset($_SESSION[$codeKey]);
            $c = null;
        }
        if ($c && $c['exp'] > time() && password_verify($entered, $c['hash'])) {
            session_regenerate_id(true);
            $_SESSION[$sessionKey] = true;
            $_SESSION[$sessionKey . '_at'] = time();
            unset($_SESSION[$codeKey]);
            portal_link_touch('vrc_tokens', 'id', (int)$vrc['id']); // open another six months (3.0.40)
            // Each opening of this external caseload view is kept in the audit trail
            // (last_used_at holds only the latest).
            audit(null, 'vrcportal.open', 'counselor', (int)$vrc['id'], $vrc['counselor_email']);
        } else {
            if (isset($_SESSION[$codeKey])) $_SESSION[$codeKey]['tries'] = (int)($_SESSION[$codeKey]['tries'] ?? 0) + 1;
            // Only a real guess (a live code was checked) counts toward the lockout,
            // so nobody can lock the link without first having a code sent.
            $liveCode = $c && $c['exp'] > time();
            if ($liveCode) {
                db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,?,0)")
                    ->execute([client_ip(), $failKey]);
                if ($countSince($failKey, '15 MINUTE') === 10) {
                    audit(null, 'vrcportal.lockout', 'counselor', null, $vrc['counselor_email']);
                    notify_admin_users('Counselor portal paused for 15 minutes after repeated wrong codes: ' . $vrc['counselor_name'], 'counselors.php');
                }
            }
            // Said on the field. A new code is suggested only when this one can no
            // longer work: a code with tries left is still good.
            $left = $liveCode ? max(0, 5 - (int)($_SESSION[$codeKey]['tries'] ?? 5)) : 0;
            if ($liveCode && $left > 0) $why = 'That code is not correct. Check the email and try again (' . $left . ($left === 1 ? ' try' : ' tries') . ' left).';
            elseif ($liveCode || $usedUp) $why = 'That code was entered wrongly 5 times, so it no longer works. Choose Email me a code to get a new one.';
            elseif ($c) $why = 'That code has expired (a code works for 10 minutes). Choose Email me a code to get a new one.';
            else $why = 'There is no code waiting for this browser. Choose Email me a code to get one.';
            keep_field_errors(['code' => $why]);
        }
        redirect('vrc.php?t=' . $token);
    }
    page_header((form_errors() ? 'Error — ' : '') . 'Counselor portal — verify');
    ?>
    <div class="card auth-card">
      <h1>Verify it's you</h1>
      <p>For your clients' privacy, we email a 6-digit code to <strong><?= h($vrc['counselor_email']) ?></strong> each time you open the portal in a new browser session.</p>
      <p>A verified session ends after 60 minutes without activity. The portal warns you 5 minutes before and lets you stay. If it does end, a message you were typing is kept and comes back after you verify again.</p>
      <form method="post" action="vrc.php?t=<?= h($token) ?>" class="stacked"><?= csrf_field() ?>
        <button type="submit" name="send_code" value="1" class="btn btn-secondary">Email me a code</button>
      </form>
      <form method="post" action="vrc.php?t=<?= h($token) ?>" class="stacked mt-1" novalidate><?= csrf_field() ?>
        <?= form_error_summary(['code' => '6-digit code']) ?>
        <div class="field">
          <label for="code">6-digit code</label>
          <p class="hint" id="code-hint">A code works for 10 minutes after it is emailed, and you can try it 5 times. Email me a code sends a new one.</p>
          <input type="text" id="code" name="code" class="otp-input" inputmode="numeric" maxlength="20" autocomplete="one-time-code" required <?= field_aria('code', 'code-hint') ?>>
          <?= field_error('code') ?>
        </div>
        <button type="submit" name="verify_code" value="1" class="btn btn-primary">Open my portal</button>
      </form>
    </div>
    <?php page_footer(); exit;
}

/* Verified — show the caseload */
// Archived (retention-expired) clients are hidden here, as on every staff list, and so
// is a client whose counselor email has not been confirmed by staff (counselor_verified = 0).
$clients = db()->prepare("SELECT * FROM clients WHERE counselor_email = ? AND archived_at IS NULL AND counselor_verified = 1 ORDER BY name");
$clients->execute([strtolower($vrc['counselor_email'])]);
$clients = $clients->fetchAll();

$myClientIds = array_map(fn($c) => (int)$c['id'], $clients);
// Times here carry the time zone (4.27.0, e.g. "2:00 PM CDT"): a counselor may be in another one.
function vrc_when(?string $d): string {
    $ts = $d ? strtotime($d) : false;
    return $ts ? date('M j, Y g:i A T', $ts) : fmt_datetime($d);
}

/* ---- A file shared with the counselor (4.24.0): only their own clients', only what staff shared ---- */
if (isset($_GET['doc'])) {
    $dq = db()->prepare('SELECT * FROM client_documents WHERE id = ? AND counselor_shared = 1');
    $dq->execute([(int)$_GET['doc']]);
    $doc = $dq->fetch();
    if ($doc && in_array((int)$doc['client_id'], $myClientIds, true)) {
        audit(null, 'vrcportal.document', 'client', (int)$doc['client_id'], 'counselor downloaded document #' . (int)$doc['id']);
        exchange_send_file($doc);
    }
    flash('That file is not available here.', 'error');
    redirect('vrc.php?t=' . $token);
}

/* ---- POST: confirm receipt of a report here, without the email link (3.0.41) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_report'])) {
    // Only reports about clients still linked to this counselor (4.27.0).
    $ack = db()->prepare('UPDATE report_acks SET acked_at = NOW(), ack_ip = ? WHERE id = ? AND sent_to = ? AND acked_at IS NULL AND client_id IN ('
        . ($myClientIds ? implode(',', $myClientIds) : '0') . ')');
    $ack->execute([client_ip(), (int)($_POST['ack_id'] ?? 0), strtolower($vrc['counselor_email'])]);
    if ($ack->rowCount() === 1) {
        audit(null, 'vrcportal.ack', 'counselor', (int)$vrc['id'], 'report receipt #' . (int)$_POST['ack_id']);
        flash('Receipt confirmed. Thank you.');
    } else {
        flash('That report was already confirmed.', 'info');
    }
    redirect('vrc.php?t=' . $token . '#reports-h');
}

/* ---- A report opened again: the track's report as it reads today (3.0.41) ---- */
if (isset($_GET['report'])) {
    $rq = db()->prepare('SELECT ra.* FROM report_acks ra WHERE ra.id = ? AND ra.sent_to = ?');
    $rq->execute([(int)$_GET['report'], strtolower($vrc['counselor_email'])]);
    $ra = $rq->fetch();
    if ($ra && in_array((int)$ra['client_id'], $myClientIds, true)) {
        $raTitle = str_contains(mb_substr((string)($ra['report_text'] ?? ''), 0, 300), 'Final Report') ? 'Final report' : 'Progress report';
        page_header($raTitle);
        echo '<p><a href="vrc.php?t=' . h($token) . '#reports-h">Back to your caseload</a></p><h1>' . $raTitle . '</h1>';
        if (!empty($ra['report_text'])) {
            // The copy kept when it was sent (3.0.47): exactly what was emailed.
            echo '<p>This is the report as we emailed it to you on ' . h(fmt_date($ra['sent_at'])) . '.</p><div class="card" style="white-space:pre-wrap">' . h((string)$ra['report_text']) . '</div>';
        } else {
            require_once __DIR__ . '/inc/report_builder.php';
            $rep = build_track_report((int)$ra['track_id'], brand('org'), '', 2);
            echo '<p>This is the report as it reads today. The copy we emailed you on ' . h(fmt_date($ra['sent_at'])) . ' may differ.</p>'
                . ($rep ? $rep['html'] : '<p>This report is no longer available.</p>');
        }
        audit(null, 'vrcportal.report', 'client', (int)$ra['client_id'], 'counselor reopened a report');
        page_footer();
        exit;
    }
    flash('That report is not available here.', 'error');
    redirect('vrc.php?t=' . $token . '#reports-h');
}

/* ---- POST: message to the provider (becomes a task + alert + email) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    // Line breaks counted as the browser counts them (one character each).
    $msg = str_replace("\r\n", "\n", trim(is_string($_POST['message'] ?? '') ? $_POST['message'] : ''));
    $msgClientId = (int)($_POST['about_client'] ?? 0) ?: null;
    // The message may only reference this counselor's own clients.
    if ($msgClientId !== null && !in_array($msgClientId, array_map(fn($c) => (int)$c['id'], $clients), true)) $msgClientId = null;
    // A file can go with a message about a client (4.24.0); then the message itself may be left empty.
    $msgFile = is_array($_FILES['msg_file'] ?? null) && (int)($_FILES['msg_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $_FILES['msg_file'] : null;
    if ($msgFile !== null && $msgClientId === null) {
        keep_input();
        keep_field_errors(['about_client' => 'Choose which client the file is about, then choose the file again.']);
        redirect('vrc.php?t=' . $token . '#msg-h');
    }
    if ($msg === '' && $msgFile === null) {
        keep_input();
        keep_field_errors(['message' => 'Type a message first.']);
        redirect('vrc.php?t=' . $token);
    }
    // Refused before the once-a-minute allowance is used, never cut: the thread, the
    // task and the email then always hold the same, whole message.
    if (mb_strlen($msg) > 5000) {
        keep_input();
        keep_field_errors(['message' => 'Your message is too long (5,000 characters maximum). Shorten it, or send it in two parts — what you typed is still in the box.']);
        redirect('vrc.php?t=' . $token);
    }
    // The same message posted again within the minute (a double press of Send) was
    // already sent: it is not sent twice, refilled, or reported as an error.
    $sentKey = 'vrc_sent_' . (int)$vrc['id'];
    $sentPrint = hash('sha256', (int)$msgClientId . "\n" . $msg . "\n" . ($msgFile !== null ? (string)($msgFile['name'] ?? '') . ':' . (int)($msgFile['size'] ?? 0) : ''));
    $lastSent = $_SESSION[$sentKey] ?? null;
    if (is_array($lastSent) && hash_equals((string)($lastSent['fp'] ?? ''), $sentPrint) && time() - (int)($lastSent['at'] ?? 0) < 60) {
        unset($_SESSION[$draftKey]);
        flash('That message was already sent a moment ago, so it was not sent again.', 'info');
        redirect('vrc.php?t=' . $token);
    }
    // One message per minute per portal link.
    $rl = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)");
    $rl->execute(['vrc-msg-' . (int)$vrc['id']]);
    if ((int)$rl->fetchColumn() > 0) {
        keep_input();
        flash('Please wait a minute between messages.', 'error');
        redirect('vrc.php?t=' . $token);
    }
    // At most 50 messages a day per portal link, as on the client portal: each one opens
    // a task and alerts and emails every administrator. Administrators are told once a
    // day when the ceiling is reached.
    $day = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $day->execute(['vrc-msg-' . (int)$vrc['id']]);
    if ((int)$day->fetchColumn() >= 50) {
        $day->execute(['vrc-msg-cap-' . (int)$vrc['id']]);
        if ((int)$day->fetchColumn() === 0) {
            db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)")
                ->execute([client_ip(), 'vrc-msg-cap-' . (int)$vrc['id']]);
            notify_admin_users('Counselor portal daily message limit reached for ' . $vrc['counselor_name'] . '. If this was not the counselor, turn off their portal link.', 'counselors.php');
        }
        keep_input();
        flash('You have reached today\'s limit of 50 messages through the portal, so this one was not sent. It is still in the box: email ' . brand('short') . ' instead, or send it tomorrow.', 'error');
        redirect('vrc.php?t=' . $token);
    }
    $savedDoc = null;
    if ($msgFile !== null) {
        // At most 10 files an hour per portal link, as on the client portal.
        $up = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)");
        $up->execute(['vrc-up-' . (int)$vrc['id']]);
        if ((int)$up->fetchColumn() >= 10) { keep_input(); flash('Please wait a while before sending more files.', 'error'); redirect('vrc.php?t=' . $token); }
        $saved = exchange_save_upload($msgFile, $msgClientId, '', 'counselor');
        if ($saved['error'] !== null) { keep_input(); keep_field_errors(['msg_file' => $saved['error'] . ' Choose the file again.']); redirect('vrc.php?t=' . $token . '#msg-h'); }
        $savedDoc = (int)$saved['id'];
        db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)")->execute([client_ip(), 'vrc-up-' . (int)$vrc['id']]);
        if ($msg === '') $msg = 'Sent a file.';
    }
    db()->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)")
        ->execute([client_ip(), 'vrc-msg-' . (int)$vrc['id']]);
    $clientName = null;
    foreach ($clients as $c) if ((int)$c['id'] === $msgClientId) $clientName = $c['name'];
    // Store in the counselor message thread when it is about a specific client,
    // so staff can reply in context from the message center.
    if ($msgClientId) {
        $mid = post_message($msgClientId, 'counselor', 'counselor', null, $msg);
        if ($savedDoc) message_attach($mid, $savedDoc);
    }
    // A general message is tied to no client, and an unassigned task with no client
    // is shown to every staff account, whatever its caseload or permissions. Its
    // free text can still name a client, so it is assigned to an administrator
    // (administrators see every task; nobody else sees one assigned to another).
    $admins = $msgClientId ? [] : effective_admin_user_ids();
    add_task('Reply to counselor ' . $vrc['counselor_name'] . ($clientName ? ' re ' . $clientName : ''),
        $msg, $msgClientId, null, business_days_from(2), $admins[0] ?? null, null);
    notify_admin_users('Message from counselor ' . $vrc['counselor_name'] . ($clientName ? ' re ' . $clientName : ''), $msgClientId ? 'messages.php?client=' . $msgClientId . '&channel=counselor' : 'tasks.php');
    notify_admins('Counselor message from ' . $vrc['counselor_name'],
        "From: {$vrc['counselor_name']} <{$vrc['counselor_email']}>"
        . ($clientName ? "\nRegarding: {$clientName}" : '')
        . "\n\n{$msg}\n\nThis is also on your task list: " . cfg('base_url') . "/tasks.php\n");
    unset($_SESSION[$draftKey]);
    $_SESSION[$sentKey] = ['fp' => $sentPrint, 'at' => time()];
    flash('Message sent — the ' . brand('short') . ' team will get back to you.');
    redirect('vrc.php?t=' . $token);
}

$reports = db()->prepare("SELECT ra.id, ra.client_id, ra.sent_at, ra.acked_at, c.name AS client_name, t.title
    FROM report_acks ra JOIN clients c ON c.id = ra.client_id JOIN training_tracks t ON t.id = ra.track_id
    WHERE ra.sent_to = ? AND c.archived_at IS NULL AND c.counselor_verified = 1 AND LOWER(c.counselor_email) = ?
    ORDER BY ra.sent_at DESC LIMIT 25");
// Only clients still linked to this counselor (4.27.0): a former counselor no longer sees their reports.
$reports->execute([strtolower($vrc['counselor_email']), strtolower($vrc['counselor_email'])]);
$reports = $reports->fetchAll();

foreach ($clients as $c) record_client_view(null, (int)$c['id'], 'counselor portal (' . $vrc['counselor_email'] . ')');
page_header('Your caseload');
?>
<h1>Welcome, <?= h($vrc['counselor_name']) ?></h1>
<p class="muted">Your clients receiving services from <?= h(brand('org')) ?>.
   <a class="btn btn-secondary btn-sm" href="community_referral.php?role=counselor">Refer a new client</a></p>

<?php if (!$clients): ?><p>No active clients are linked to your email yet. Clients you referred appear here once our team confirms them, usually within 2 business days.</p><?php endif; ?>
<?php foreach ($clients as $c): ?>
<section class="card">
  <h2><?= h($c['name']) ?> <?= status_badge($c['status']) ?></h2>
  <?php
  // The client's most recent completed session and the next one booked, beside
  // the hours: a counselor sees at a glance whether training is under way.
  $lastQ = db()->prepare("SELECT start_at FROM scheduled_sessions WHERE client_id = ? AND status = 'Completed' ORDER BY start_at DESC LIMIT 1");
  $lastQ->execute([(int)$c['id']]);
  $lastSession = $lastQ->fetchColumn();
  $nextQ = db()->prepare("SELECT start_at FROM scheduled_sessions WHERE client_id = ? AND status = 'Scheduled' AND start_at >= NOW() ORDER BY start_at LIMIT 1");
  $nextQ->execute([(int)$c['id']]);
  $nextSession = $nextQ->fetchColumn();
  $tracks = db()->prepare("SELECT * FROM training_tracks WHERE client_id = ? ORDER BY status = 'Active' DESC");
  $tracks->execute([(int)$c['id']]);
  foreach ($tracks->fetchAll() as $t):
      $r = track_rollup((int)$t['id']);
      $g = db()->prepare("SELECT COUNT(*) total, SUM(status = 'Achieved') achieved FROM smart_goals WHERE track_id = ?");
      $g->execute([(int)$t['id']]);
      $goals = $g->fetch();
  ?>
  <h3><?= h($t['title']) ?> (<?= h($t['status']) ?>)</h3>
  <dl class="props">
    <dt>Authorization</dt><dd><?= h($t['auth_number']) ?> · <?= h($t['service_type'] ?? '') ?> · expires <?= fmt_date($t['expiration_date'], true) ?></dd>
    <dt>Hours</dt><dd><?= fmt_hours($r['hours_worked']) ?> used of <?= fmt_hours($r['authorized_hours']) ?> (<?= fmt_hours($r['hours_remaining']) ?> remaining)</dd>
    <dt>Last session</dt><dd><?= $lastSession ? fmt_date($lastSession) : 'None completed yet' ?></dd>
    <dt>Next session</dt><dd><?= $nextSession ? h(vrc_when($nextSession)) : 'None scheduled' ?></dd>
    <dt>Goals</dt><dd><?= (int)($goals['achieved'] ?? 0) ?> of <?= (int)($goals['total'] ?? 0) ?> achieved</dd>
  </dl>
  <div class="progress-wrap">
    <label for="vp-<?= (int)$t['id'] ?>" class="progress-caption">Authorized hours used: <?= $r['pct_used'] ?>%</label>
    <progress id="vp-<?= (int)$t['id'] ?>" class="hours" max="100" value="<?= $r['pct_used'] ?>"></progress>
  </div>
  <?php endforeach; ?>
  <?php // Attendance over the last 90 days (3.0.41): where a missed-session email points.
  $att = db()->prepare("SELECT start_at, status FROM scheduled_sessions WHERE client_id = ? AND start_at < NOW() AND start_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
      AND status IN ('Completed','No Show','Cancelled') ORDER BY start_at DESC");
  $att->execute([(int)$c['id']]);
  $att = $att->fetchAll();
  $count = fn(string $st) => count(array_filter($att, fn($a) => $a['status'] === $st)); ?>
  <h3 id="att-<?= (int)$c['id'] ?>">Attendance, last 90 days</h3>
  <?php if (!$att): ?><p class="muted">No past sessions in the last 90 days.</p><?php else: ?>
  <p><?= $count('Completed') ?> attended, <?= $count('No Show') ?> missed, <?= $count('Cancelled') ?> cancelled.</p>
  <details><summary>Each session<span class="visually-hidden"> for <?= h($c['name']) ?></span></summary>
    <ul><?php foreach ($att as $a): ?><li><?= h(vrc_when($a['start_at'])) ?>: <?= h(['Completed' => 'attended', 'No Show' => 'missed', 'Cancelled' => 'cancelled'][$a['status']]) ?></li><?php endforeach; ?></ul>
  </details>
  <?php endif; ?>
  <?php // Files shared with the counselor (4.24.0): what staff shared and what the counselor sent.
  $sf = db()->prepare('SELECT id, title, created_at, uploaded_via FROM client_documents WHERE client_id = ? AND counselor_shared = 1 ORDER BY created_at DESC LIMIT 50');
  $sf->execute([(int)$c['id']]);
  $sharedFiles = $sf->fetchAll();
  if ($sharedFiles): ?>
  <h3>Shared files</h3>
  <ul><?php foreach ($sharedFiles as $f): ?><li><a href="vrc.php?t=<?= h($token) ?>&amp;doc=<?= (int)$f['id'] ?>"><?= h($f['title']) ?><span class="visually-hidden"> for <?= h($c['name']) ?> (downloads a file)</span></a> <span class="muted">· <?= ($f['uploaded_via'] ?? '') === 'counselor' ? 'sent through the counselor portal' : 'from ' . h(brand('short')) ?>, <?= fmt_date($f['created_at']) ?></span></li><?php endforeach; ?></ul>
  <?php endif; ?>
</section>
<?php endforeach; ?>

<section class="card">
  <h2 id="reports-h">Reports we've sent you</h2>
  <?php if (!$reports): ?><p class="muted">None yet.</p><?php else: ?>
  <div class="table-scroll" tabindex="0" role="region" aria-labelledby="reports-cap"><table>
    <caption class="visually-hidden" id="reports-cap">Progress reports sent to you, and whether receipt was confirmed</caption>
    <thead><tr><th scope="col">Sent</th><th scope="col">Client / track</th><th scope="col">Receipt</th></tr></thead>
    <tbody>
    <?php foreach ($reports as $rp): ?>
      <tr><td><?= h(vrc_when($rp['sent_at'])) ?></td>
          <th scope="row"><?= in_array((int)$rp['client_id'], $myClientIds, true) ? '<a href="vrc.php?t=' . h($token) . '&amp;report=' . (int)$rp['id'] . '">' . h($rp['client_name']) . ' — ' . h($rp['title']) . '</a>' : h($rp['client_name']) . ' — ' . h($rp['title']) ?></th>
          <td><?php if ($rp['acked_at']): ?>Confirmed <?= h(vrc_when($rp['acked_at'])) ?>
            <?php else: ?><form method="post" action="vrc.php?t=<?= h($token) ?>" class="inline-form"><?= csrf_field() ?>
              <input type="hidden" name="ack_id" value="<?= (int)$rp['id'] ?>">
              <button type="submit" name="confirm_report" value="1" class="btn btn-secondary btn-sm">Confirm receipt<span class="visually-hidden">: <?= h($rp['client_name']) ?>, sent <?= fmt_date($rp['sent_at']) ?></span></button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php
$cids = array_map(fn($c) => (int)$c['id'], $clients);
$convo = [];
if ($cids) {
    $in = implode(',', array_fill(0, count($cids), '?'));
    // Grouped by client, up to the 50 most recent messages each: staff replies are
    // read only here, so one busy client's thread never pushes another's out of view.
    $cq = db()->prepare("SELECT x.* FROM (SELECT m.id, m.client_id, m.sender, m.body, m.created_at, c.name AS client_name, d.id AS doc_id, d.title AS doc_title,
            ROW_NUMBER() OVER (PARTITION BY m.client_id ORDER BY m.created_at DESC, m.id DESC) AS rn,
            COUNT(*) OVER (PARTITION BY m.client_id) AS total
        FROM messages m JOIN clients c ON c.id = m.client_id LEFT JOIN client_documents d ON d.id = m.document_id AND d.client_id = m.client_id AND d.counselor_shared = 1
        WHERE m.channel = 'counselor' AND m.client_id IN ($in)) x
        WHERE x.rn <= 50 ORDER BY x.client_name, x.client_id, x.created_at, x.id");
    $cq->execute($cids);
    foreach ($cq->fetchAll() as $m) $convo[(int)$m['client_id']][] = $m;
}
if ($convo): ?>
<section class="card" aria-labelledby="conv-h">
  <h2 id="conv-h">Your conversation</h2>
  <?php /* One list per client, under a heading naming the client, with the sender
           named before each message for screen readers; role="list" keeps the list
           semantics that list-style:none removes in Safari. */ ?>
  <?php // A counselor's messages are stored without saying which counselor sent them, and a
  // client can change counselor, so they are labelled "Counselor", never "You". ?>
  <?php foreach ($convo as $thread): $first = $thread[0]; $shown = count($thread); $total = (int)$first['total']; ?>
  <h3 id="conv-<?= (int)$first['client_id'] ?>">About <?= h($first['client_name']) ?></h3>
  <?php if ($total > $shown): ?><p class="muted">Showing the <?= $shown ?> most recent of <?= $total ?> messages about <?= h($first['client_name']) ?>. Ask the <?= h(brand('short')) ?> team if you need an earlier one.</p><?php endif; ?>
  <ol class="msg-thread" role="list" aria-labelledby="conv-<?= (int)$first['client_id'] ?>">
    <?php foreach ($thread as $m): $who = $m['sender'] === 'counselor' ? 'Counselor' : brand('short'); ?>
      <li class="msg <?= $m['sender'] === 'counselor' ? 'out' : 'in' ?>">
        <span class="visually-hidden"><?= h($who) ?>:</span>
        <div class="msg-body"><?= nl2br(h($m['body'])) ?><?php if (!empty($m['doc_id'])): ?><br>File: <a href="vrc.php?t=<?= h($token) ?>&amp;doc=<?= (int)$m['doc_id'] ?>"<?= $m['sender'] === 'counselor' ? ' style="color:#fff"' : '' ?>><?= h((string)$m['doc_title']) ?><span class="visually-hidden"> (downloads a file)</span></a><?php endif; ?></div>
        <div class="msg-meta"><span aria-hidden="true"><?= h($who) ?> · </span><?= h(date('M j, Y, g:i A T', strtotime($m['created_at']))) ?></div>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php endforeach; ?>
  <?php /* Sent messages use a fixed navy, not the configurable brand colour: white
           text 10.4:1 and the time line 8.8:1 (AAA). Text lines stay near 70 characters. */ ?>
  <style>.msg-thread{display:flex;flex-direction:column;gap:.5rem;margin:0;padding:0;list-style:none}.msg{max-width:85%;padding:.5rem .7rem;border-radius:var(--radius)}.msg-body{max-width:70ch}.msg.in{align-self:flex-start;background:var(--bg);border:1px solid var(--line)}.msg.out{align-self:flex-end;background:#1e3a8a;color:#fff}.msg-meta{font-size:.75rem;color:var(--ink-soft);margin-top:.25rem}.msg.out .msg-meta{color:#e5ecff}</style>
</section>
<?php endif; ?>
<section class="card" aria-labelledby="msg-h">
  <h2 id="msg-h">Send us a message</h2>
  <p class="muted">Your message goes to the <?= h(brand('short')) ?> team's task list and email, so you do not need to call. We aim to reply within 2 business days.</p>
  <form method="post" action="vrc.php?t=<?= h($token) ?>" class="stacked" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary(['message' => 'Your message', 'about_client' => 'About a client', 'msg_file' => 'Attach a file']) ?>
    <?php // A message kept over a timeout comes back with the client it was about
    // (an older kept draft is the message text alone).
    $draft = $_SESSION[$draftKey] ?? null;
    $draftMsg = is_array($draft) ? (string)($draft['message'] ?? '') : (is_string($draft) ? $draft : '');
    $pickAbout = old_in('about_client', is_array($draft) && (int)($draft['about_client'] ?? 0) > 0 ? (string)(int)$draft['about_client'] : ''); ?>
    <?php if ($clients): ?>
    <div class="field"><label for="about_client">About a client <span class="hint">optional</span></label>
      <select id="about_client" name="about_client" <?= field_aria('about_client') ?>><option value="">— General —</option>
        <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $pickAbout === (string)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
      </select><?= field_error('about_client') ?></div>
    <?php endif; ?>
    <?php // No maxlength: a browser would cut a long paste silently. The server refuses
    // an over-long message with the whole text left in the box. ?>
    <div class="field"><label for="message">Your message <span class="hint">required unless you attach a file</span></label>
      <p class="hint" id="message-hint">Up to 5,000 characters.</p>
      <textarea id="message" name="message" rows="4" <?= field_aria('message', 'message-hint') ?>><?= h(old_in('message', $draftMsg)) ?></textarea><?= field_error('message') ?></div>
    <?php if ($clients): ?>
    <div class="field"><label for="msg_file">Attach a file about the client chosen above <span class="hint">optional; PDF, Word, Excel, text or image, up to 15 MB</span></label>
      <input type="file" id="msg_file" name="msg_file" accept=".pdf,.docx,.xlsx,.txt,.rtf,.png,.jpg,.jpeg" <?= field_aria('msg_file') ?>><?= field_error('msg_file') ?></div>
    <?php endif; ?>
    <button type="submit" name="send_message" value="1" class="btn btn-primary">Send message</button>
  </form>
</section>
<?php /* Inactivity warning (WCAG 2.2.1): reading the caseload sends no requests, so
   5 minutes before the 60-minute limit this dialog offers to stay, and typing a
   message keeps the portal open by itself. If the session does end, the dialog
   says so: what was typed stays on the page and is kept when it is sent. */ ?>
<div id="vrc-timeout" class="timeout-dialog" role="alertdialog" aria-modal="true" aria-labelledby="vt-title" aria-describedby="vt-desc" hidden
  data-warn-ms="<?= (3600 - 300) * 1000 ?>" data-end-ms="<?= 3600 * 1000 ?>" data-url="vrc.php?t=<?= h($token) ?>&amp;keepalive=1" data-csrf="<?= h(csrf_token()) ?>"
  data-error="We could not keep the portal open. Check your connection, then choose Stay in the portal again.">
  <h2 id="vt-title">Are you still there?</h2>
  <p id="vt-desc">For your clients' privacy, the portal closes after 60 minutes without activity. It will close in about 5 minutes. Choose Stay in the portal to keep it open.</p>
  <p id="vt-ended" hidden>Your portal session has ended. A message you were typing is still in the box: when you choose Send message, you will be asked to verify again and the message will be kept.</p>
  <div id="vt-live" role="alert" class="field-error"></div>
  <button type="button" class="btn btn-primary" id="vt-stay">Stay in the portal</button>
  <button type="button" class="btn btn-secondary" id="vt-close">Return to the page</button>
</div>
<script>
(function () {
  var d = document.getElementById('vrc-timeout');
  if (!d || !window.fetch) return;
  var stay = document.getElementById('vt-stay'), closeBtn = document.getElementById('vt-close'), live = document.getElementById('vt-live');
  var warnTimer, endTimer, last = Date.now(), lastFocus = null, pending = false, ended = false;
  function arm() {
    clearTimeout(warnTimer); clearTimeout(endTimer); last = Date.now();
    warnTimer = setTimeout(show, Number(d.dataset.warnMs));
    endTimer = setTimeout(end, Number(d.dataset.endMs));
  }
  function show() {
    if (!d.hidden) return;
    lastFocus = document.activeElement;
    d.hidden = false;
    (stay.hidden ? closeBtn : stay).focus();
  }
  function hide() {
    d.hidden = true;
    // A screen-reader user reading in browse mode often has nothing focused (the
    // body): return them to the main content rather than losing their place.
    var back = lastFocus && lastFocus.isConnected && lastFocus !== document.body && lastFocus !== document.documentElement
      ? lastFocus : document.getElementById('main');
    if (back) back.focus();
  }
  function end() {
    ended = true;
    clearTimeout(warnTimer);
    live.textContent = '';
    document.getElementById('vt-desc').hidden = true;
    stay.hidden = true;
    document.getElementById('vt-ended').hidden = false;
    d.setAttribute('aria-describedby', 'vt-ended');
    if (d.hidden) show(); else closeBtn.focus();
  }
  function ping(fromButton) {
    if (pending || ended) return;
    pending = true;
    fetch(d.dataset.url, {method: 'POST', credentials: 'same-origin', headers: {'X-CSRF-Token': d.dataset.csrf}})
      .then(function (r) {
        if (r.ok) { arm(); if (fromButton) hide(); }
        else if (r.status === 401) end();
        else if (fromButton) live.textContent = d.dataset.error;
      })
      .catch(function () { if (fromButton) live.textContent = d.dataset.error; })
      .then(function () { pending = false; });
  }
  stay.addEventListener('click', function () { live.textContent = ''; ping(true); });
  closeBtn.addEventListener('click', hide);
  // A modal dialog keeps keyboard focus inside it while open: Tab and Shift+Tab wrap
  // between its buttons, and focus that leaves it (a click behind it) is brought back.
  document.addEventListener('keydown', function (e) {
    if (d.hidden) return;
    if (e.key === 'Escape') { e.preventDefault(); hide(); return; }
    if (e.key !== 'Tab') return;
    var controls = Array.prototype.filter.call(d.querySelectorAll('button'), function (el) { return !el.disabled && !el.hidden; });
    if (!controls.length) return;
    var firstC = controls[0], lastC = controls[controls.length - 1];
    if (e.shiftKey && (document.activeElement === firstC || !d.contains(document.activeElement))) { e.preventDefault(); lastC.focus(); }
    else if (!e.shiftKey && (document.activeElement === lastC || !d.contains(document.activeElement))) { e.preventDefault(); firstC.focus(); }
  });
  document.addEventListener('focusin', function (e) { if (!d.hidden && !d.contains(e.target)) closeBtn.focus(); });
  // Typing is activity: at most one keepalive every 5 minutes while the dialog is closed.
  document.addEventListener('input', function () { if (!ended && d.hidden && Date.now() - last > 300000) ping(false); });
  arm();
})();
</script>
<?php page_footer();
