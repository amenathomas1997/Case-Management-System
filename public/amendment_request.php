<?php
// Authorization burn-down + amendment request. For a track nearing its
// authorized hours or expiry, project when it will run out and draft a short,
// professional amendment-request letter to the VR counselor (optionally polished
// by AI). The letter is editable and never auto-sent — staff copy it or open it
// in their mail client.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('clients');

$trackId = (int)($_GET['track'] ?? $_POST['track'] ?? 0);
$tq = db()->prepare('SELECT t.*, c.id AS cid, c.name AS client, c.counselor_name, c.counselor_email, c.counselor_agency, c.counselor_verified
    FROM training_tracks t JOIN clients c ON c.id = t.client_id WHERE t.id = ?');
$tq->execute([$trackId]);
$track = $tq->fetch();
if (!$track) { flash('Training track not found.', 'error'); redirect('compliance.php'); }
scope_check_client_id((int)$track['cid'], $user);
// The letter (client name, authorization, hours or funds) is addressed for the user
// only to a counselor email staff have confirmed: one typed into the public referral
// form may be anyone's (eval_report.php and report.php refuse to send to it too).
$counselorEmail = trim((string)($track['counselor_email'] ?? ''));
$counselorConfirmed = $counselorEmail !== '' && (int)($track['counselor_verified'] ?? 0) === 1;

/* ---- POST: record the request as sent, or its reply (3.0.44, inc/amendments.php) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_request'])) {
    $key = web_idem_key($_POST);
    if (web_once_get($key) !== null) { flash('This request was already recorded (the form was sent twice).', 'info'); redirect('amendment_request.php?track=' . $trackId . '#requests-h'); }
    [$errs, $newId] = amendment_record($trackId, $_POST, (int)$user['id']);
    if ($errs) { keep_input(); keep_field_errors($errs); redirect('amendment_request.php?track=' . $trackId . '#record-h'); }
    web_once_set($key, $newId);
    flash('Recorded: amendment requested. The letter is on the track as a case note, and a follow-up task is due in 10 business days.');
    redirect('amendment_request.php?track=' . $trackId . '#requests-h');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_reply'])) {
    $rid = (int)($_POST['request_id'] ?? 0);
    $err = amendment_reply($rid, $trackId, is_string($_POST['reply_status'] ?? null) ? $_POST['reply_status'] : '',
        is_string($_POST['replied_on'] ?? null) ? $_POST['replied_on'] : '', is_string($_POST['reply_note'] ?? null) ? $_POST['reply_note'] : '', (int)$user['id']);
    if ($err !== null) { keep_input(); keep_field_errors(['reply_' . $rid => $err]); redirect('amendment_request.php?track=' . $trackId . '#requests-h'); }
    $st = (string)$_POST['reply_status'];
    if (in_array($st, ['Approved', 'Partly approved'], true)) {
        flash('Reply recorded: ' . strtolower($st) . '. Now enter the approved hours, dollars or end date on the track.');
        redirect('track_edit.php?id=' . $trackId . '&amend_request=' . $rid . '#amendment_reason');
    }
    flash('Reply recorded: ' . strtolower($st) . '.');
    redirect('amendment_request.php?track=' . $trackId . '#requests-h');
}

$r = track_rollup($trackId);
$worked = (float)$r['hours_worked']; $auth = (float)$r['authorized_hours'];
$remaining = (float)$r['hours_remaining'];
// Whole percent rounded DOWN, in exact hundredths of an hour, as compliance.php:
// rounding to nearest says "100%" while hours remain, and the comparison with the
// funds percentage below must use the same rule on both sides.
$authC = (int)round($auth * 100);
$pct = $authC > 0 ? intdiv((int)round($worked * 100) * 100, $authC) : 0;
// Authorized funds are also used up by mileage and expenses (track_rollup's
// funds_remaining), so a track can run out of money before its hours; Compliance
// lists it then too. Same whole-percent-rounded-down rule as compliance.php, in
// exact cents. When funds are used faster than hours, the letter argues from funds.
$fundsAuth = (float)$r['authorized_funds']; $fundsLeft = (float)$r['funds_remaining'];
$fundsUsed = max(0.0, $fundsAuth - $fundsLeft);
$fundsC = (int)round($fundsAuth * 100);
$fundsPct = $fundsC > 0 ? intdiv(($fundsC - (int)round($fundsLeft * 100)) * 100, $fundsC) : 0;
$otherCosts = (float)$r['mileage_amount'] + (float)$r['expenses_amount'];   // mileage + expenses
$fundsLimit = $fundsC > 0 && $fundsPct > $pct;

// Burn rate from the last 8 weeks of logged hours (and of spending: hours and
// mileage at each log's rate, rounded per line as track_rollup does, plus
// expenses), to project the run-out date. The weeks counted start no earlier
// than the track's start date or its first log, so a track active two weeks is
// not averaged over eight (at least 1 week). Spending is averaged from its own
// first item, a log or an expense, so an expense recorded before the first log
// is not spread over the few days since that log.
$br = db()->prepare("SELECT COALESCE(SUM(CASE WHEN w.work_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 55 DAY) AND CURDATE() THEN w.hours END),0) AS recent,
        COALESCE(SUM(CASE WHEN w.work_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 55 DAY) AND CURDATE()
            THEN ROUND(w.hours * COALESCE(w.hourly_rate, t.hourly_rate), 2) + ROUND(w.mileage * COALESCE(w.mileage_rate, t.mileage_rate), 2) END),0)
          + (SELECT COALESCE(SUM(amount),0) FROM track_expenses WHERE track_id = ? AND expense_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 55 DAY) AND CURDATE()) AS recent_spend,
        GREATEST(DATE_SUB(CURDATE(), INTERVAL 55 DAY), COALESCE(MIN(w.work_date), CURDATE()), COALESCE(CAST(? AS DATE), DATE_SUB(CURDATE(), INTERVAL 55 DAY))) AS since,
        GREATEST(DATE_SUB(CURDATE(), INTERVAL 55 DAY),
            LEAST(COALESCE(MIN(w.work_date), CURDATE()), COALESCE((SELECT MIN(expense_date) FROM track_expenses WHERE track_id = ?), CURDATE())),
            COALESCE(CAST(? AS DATE), DATE_SUB(CURDATE(), INTERVAL 55 DAY))) AS spend_since,
        CURDATE() AS today
    FROM work_logs w JOIN training_tracks t ON t.id = w.track_id WHERE w.track_id = ?");
$br->execute([$trackId, $track['start_date'] ?: null, $trackId, $track['start_date'] ?: null, $trackId]);
$burn = $br->fetch();
$recentHours = (float)$burn['recent'];
$rateSince = (string)$burn['since'];
$span = (new DateTime($rateSince))->diff(new DateTime((string)$burn['today']));
$rateDays = $span->invert ? 0 : $span->days + 1;   // inclusive of today
$weeklyRate = $recentHours / max(1, $rateDays / 7);
$spendSince = (string)$burn['spend_since'];
$sSpan = (new DateTime($spendSince))->diff(new DateTime((string)$burn['today']));
$spendDays = $sSpan->invert ? 0 : $sSpan->days + 1;
$weeklySpend = (float)$burn['recent_spend'] / max(1, $spendDays / 7);
$weeksLeft = $fundsLimit
    ? ($weeklySpend > 0 ? $fundsLeft / $weeklySpend : null)
    : ($weeklyRate > 0 ? $remaining / $weeklyRate : null);
// A projected date after the authorization expires (or, with no expiry date, more
// than a year away) is not quoted: the authorization ends first, or the date is
// too far off to mean anything, and a date years ahead would read as this year's.
// An authorization that has already expired gets no projection at all: every
// run-out date would be "after expiry", and the letter would say in the future
// tense that the period ends first.
$expired = $track['expiration_date'] && (string)$track['expiration_date'] < (string)$burn['today'];
$runOutDays = $weeksLeft !== null && !$expired ? $weeksLeft * 7 : null;
$runOutTs = $runOutDays !== null && $runOutDays <= 3660 ? strtotime('+' . (int)round($runOutDays) . ' days') : null;
$outlastsExpiry = $runOutDays !== null && $track['expiration_date']
    && ($runOutTs === null || date('Y-m-d', $runOutTs) > (string)$track['expiration_date']);
$runOutFar = $runOutDays !== null && !$outlastsExpiry && $runOutDays > 366;
$runOut = $runOutTs !== null && !$outlastsExpiry && !$runOutFar ? date('F j, Y', $runOutTs) : null;
$expDate = $track['expiration_date'] ? fmt_date($track['expiration_date']) : null;

// A requested top-up: roughly four more weeks at the current rate (rounded up to
// 5 hours), or 10 hours when there's no recent activity to extrapolate from.
$suggestAdd = $weeklyRate > 0 ? max(5, (int)(ceil($weeklyRate * 4 / 5) * 5)) : 10;
// When funds are the limit: roughly four more weeks of spending (rounded up to
// $50), or the value of 10 hours when there's no recent spending.
$suggestFunds = $weeklySpend > 0 ? max(50.0, ceil($weeklySpend * 4 / 50) * 50) : max(50.0, ceil((float)$r['hourly_rate'] * 10 / 50) * 50);

/** The baseline (no-AI) letter, with real names filled in. */
function baseline_letter(array $track, float $worked, float $auth, float $remaining, int $pct, ?string $runOut, ?string $expDate, int $suggestAdd, array $user, ?array $funds = null, bool $outlastsExpiry = false, bool $expired = false): string {
    $cn = trim((string)($track['counselor_name'] ?? '')) ?: 'Counselor';
    // "of the 40 authorized hours": the number alone, as fmt_hours() adds its own unit.
    $authNum = rtrim(rtrim(number_format($auth, 2), '0'), '.');
    $L = [];
    $L[] = 'Dear ' . $cn . ',';
    $L[] = '';
    $L[] = 'I am writing regarding the authorization for ' . $track['client'] . ' (authorization ' . ($track['auth_number'] ?: 'on file') . ', ' . $track['title'] . ').';
    $L[] = '';
    if ($funds) {
        // Funds are used faster than hours: mileage and expenses are billed against the same authorization.
        $L[] = 'As of today, ' . fmt_money($funds['used']) . ' of the ' . fmt_money($funds['auth']) . ' in authorized funds have been used (' . $funds['pct'] . '%), leaving ' . fmt_money($funds['left']) . ' remaining.'
            . ($funds['other'] > 0 ? ' This includes ' . fmt_money($funds['other']) . ' of mileage and expenses billed against the same authorization,' : ' The funds are being used faster than the hours,')
            . ' so the funds will run out before the authorized hours (' . fmt_hours($worked) . ' of ' . fmt_hours($auth) . ' used, ' . $pct . '%).'
            . ($runOut ? ' At the current rate of service, the authorized funds are projected to be exhausted around ' . $runOut . '.'
                : ($outlastsExpiry ? ' At the current rate of service, the authorization period ends before the authorized funds would be used up.' : ''))
            . ($expDate ? ($expired ? ' The authorization expired on ' : ' The authorization expires ') . $expDate . '.' : '');
        $L[] = '';
        $L[] = 'To continue services without interruption, I am requesting an amendment of approximately ' . fmt_money($funds['suggest']) . ' in additional authorized funds'
            . ($expDate ? ', and an extension of the authorization period as appropriate' : '') . '. The remaining goals and the justification for continued services are documented in the case file and can be shared on request.';
    } else {
        $L[] = 'As of today, ' . fmt_hours($worked) . ' of the ' . $authNum . ' authorized ' . ($authNum === '1' ? 'hour' : 'hours') . ' have been used (' . $pct . '%), leaving ' . fmt_hours($remaining) . ' remaining.'
            . ($runOut ? ' At the current rate of service, the authorized hours are projected to be exhausted around ' . $runOut . '.'
                : ($outlastsExpiry ? ' At the current rate of service, the authorization period ends before the authorized hours would be used up.' : ''))
            . ($expDate ? ($expired ? ' The authorization expired on ' : ' The authorization expires ') . $expDate . '.' : '');
        $L[] = '';
        $L[] = 'To continue services without interruption, I am requesting an amendment of approximately ' . $suggestAdd . ' additional hours'
            . ($expDate ? ', and an extension of the authorization period as appropriate' : '') . '. The remaining goals and the justification for continued services are documented in the case file and can be shared on request.';
    }
    $L[] = '';
    $L[] = 'Please let me know if you need any additional information to process this amendment. Thank you for your support of ' . $track['client'] . '.';
    $L[] = '';
    $L[] = 'Sincerely,';
    $L[] = $user['name'] . ' — ' . brand('org');
    return implode("\n", $L);
}

$fundsFacts = $fundsLimit ? ['auth' => $fundsAuth, 'used' => $fundsUsed, 'left' => $fundsLeft, 'pct' => $fundsPct, 'other' => $otherCosts, 'suggest' => $suggestFunds] : null;
$draft = baseline_letter($track, $worked, $auth, $remaining, $pct, $runOut, $expDate, $suggestAdd, $user, $fundsFacts, $outlastsExpiry, $expired);
$aiNote = '';
$aiFailed = false;
// The letter as it was in the box when "Draft the letter with AI" was pressed. Text
// the user changed is never replaced: the AI draft then goes in a box of its own.
$postedLetter = is_string($_POST['letter'] ?? null) ? str_replace("\r\n", "\n", $_POST['letter']) : '';
$letterEdited = trim($postedLetter) !== '' && trim($postedLetter) !== trim($draft);
$aiAlt = null;
// After a sign-in (session timeout) or a stale-token redirect, the letter as it was
// typed comes back as old input on a GET: it is shown instead of the standard letter.
$keptLetter = $_SERVER['REQUEST_METHOD'] !== 'POST' ? str_replace("\r\n", "\n", old_in('letter')) : '';
if (trim($keptLetter) !== '') $draft = $keptLetter;

// Optional AI polish. The prompt carries only facts and uses [CLIENT],
// [COUNSELOR] and [STAFF] as placeholders, which are substituted back locally —
// no client, counselor or staff name leaves the system. (The track title is
// free text, so with redaction on it is also scrubbed of those names.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_draft']) && ai_feature_enabled('draft')) {
    $facts = "Facts for an authorization amendment request to a vocational rehabilitation counselor:\n"
        . "- Service: " . $track['title'] . "\n- Hours authorized: " . fmt_hours($auth) . "\n- Hours used: " . fmt_hours($worked) . " (" . $pct . "%)\n- Hours remaining: " . fmt_hours($remaining) . "\n"
        . ($fundsLimit ? "- Funds authorized: " . fmt_money($fundsAuth) . "\n- Funds used: " . fmt_money($fundsUsed) . " (" . $fundsPct . "%), including " . fmt_money($otherCosts) . " of mileage and expenses\n- Funds remaining: " . fmt_money($fundsLeft) . "\n"
            . "- The authorized funds, not the hours, are the limit: they will run out before the hours do.\n" : '')
        . ($runOut ? "- Projected run-out " . ($fundsLimit ? 'of funds ' : '') . "at current pace: " . $runOut . "\n"
            : ($outlastsExpiry ? "- At the current pace the authorization period ends before the " . ($fundsLimit ? 'funds' : 'hours') . " are used up\n" : ''))
        . ($expDate ? ($expired ? "- Authorization already expired on: " : "- Authorization expires: ") . $expDate . "\n" : '')
        . ($fundsLimit ? "- Requesting about " . fmt_money($suggestFunds) . " in additional authorized funds" : "- Requesting about " . $suggestAdd . " additional hours") . ($expDate ? " and a period extension" : "") . ".\n"
        . "- Counselor: [COUNSELOR]\n- Prepared by: [STAFF] at " . brand('org') . "\n";
    $sys = ai_system_base() . ' Write a brief, professional authorization amendment request letter to a vocational rehabilitation counselor. '
        . 'Use [CLIENT] wherever the individual\'s name belongs, [COUNSELOR] for the counselor\'s name and [STAFF] for the sender\'s name, exactly as written — do not invent names. '
        . 'Facts only; no promises of outcomes. Keep it under 200 words.';
    $res = ai_run('draft', $sys, $facts, 600,
        ['name' => $track['client'], 'counselor_name' => $track['counselor_name'], 'counselor_email' => $track['counselor_email']], (int)$user['id']);
    if ($letterEdited) $draft = $postedLetter;
    if ($res['ok']) {
        $aiText = str_replace(['[CLIENT]', '[COUNSELOR]', '[STAFF]'],
            [$track['client'], $track['counselor_name'] ?: 'Counselor', $user['name']], $res['text']);
        // The flash is focused after the reload, so the outcome is announced once.
        if ($letterEdited) {
            $aiAlt = $aiText;
            $aiNote = 'Your letter is unchanged. The AI draft is in the box below it.';
            flash('Your letter is unchanged. The AI draft is in a separate box below it — review it, and use it only if you want it.');
        } else {
            $draft = $aiText;
            $aiNote = 'Draft generated by AI — review and edit before sending.';
            flash('The AI draft is in the letter box below — review and edit it before sending.');
        }
    } else {
        $aiFailed = true;
        $aiNote = $letterEdited ? 'AI draft unavailable. Your letter is unchanged.' : 'AI draft unavailable. Showing the standard letter.';
        flash('AI draft unavailable (' . $res['error'] . ').' . ($letterEdited ? ' Your letter is unchanged.' : ' The standard letter is shown instead.'), 'error');
    }
    audit($user['id'], 'ai.amendment_draft', 'track', $trackId);
}

page_header('Amendment request', $user, 'clients');
?>
<p><a href="client.php?id=<?= (int)$track['cid'] ?>"><span aria-hidden="true">&larr; </span><?= h($track['client']) ?></a> <span aria-hidden="true">·</span> <a href="track.php?id=<?= $trackId ?>"><?= h($track['title']) ?></a></p>
<h1>Authorization amendment request</h1>

<div class="grid grid-4">
  <?php if ($fundsLimit): ?>
  <div class="card stat"><span class="stat-num"><?= $fundsPct ?>%</span><span class="stat-label">Authorized funds used</span></div>
  <div class="card stat"><span class="stat-num"><?= h(fmt_money($fundsLeft)) ?></span><span class="stat-label">Funds remaining</span></div>
  <?php else: ?>
  <div class="card stat"><span class="stat-num"><?= $pct ?>%</span><span class="stat-label">Authorized hours used</span></div>
  <div class="card stat"><span class="stat-num"><?= fmt_hours($remaining) ?></span><span class="stat-label">Hours remaining</span></div>
  <?php endif; ?>
  <div class="card stat"><span class="stat-num"><?= $runOut ? h(date('M j, Y', $runOutTs)) : ($outlastsExpiry ? 'After expiry' : ($runOutFar ? 'Over a year' : '—')) ?></span><span class="stat-label">Projected run-out</span></div>
  <div class="card stat"><span class="stat-num"><?= $expDate ? h(date('M j, Y', strtotime($track['expiration_date']))) : '—' ?></span><span class="stat-label"><?= $expired ? 'Expired' : 'Expires' ?></span></div>
</div>

<section class="card">
  <h2>Hours and funds used so far</h2>
  <div class="progress-wrap">
    <label for="burn" class="progress-caption"><?= fmt_hours($worked) ?> of <?= fmt_hours($auth) ?> used (<?= $pct ?>%) · <?= fmt_hours($remaining) ?> remaining</label>
    <progress id="burn" class="hours" max="100" value="<?= min(100, $pct) ?>"><?= $pct ?>%</progress>
  </div>
  <?php if ($fundsC > 0): ?>
  <div class="progress-wrap">
    <label for="burn-funds" class="progress-caption"><?= h(fmt_money($fundsUsed)) ?> of <?= h(fmt_money($fundsAuth)) ?> in funds used (<?= $fundsPct ?>%), including <?= h(fmt_money($otherCosts)) ?> of mileage and expenses · <?= h(fmt_money($fundsLeft)) ?> remaining</label>
    <progress id="burn-funds" class="hours" max="100" value="<?= min(100, $fundsPct) ?>"><?= $fundsPct ?>%</progress>
  </div>
  <?php endif; ?>
  <?php if ($fundsLimit): ?>
  <p class="muted"><?= $weeklySpend > 0 ? 'The funds are being used faster than the hours. Recent spending: ' . h(fmt_money($weeklySpend)) . ' per week ' . ($spendDays >= 56 ? 'over the last 8 weeks.' : 'since ' . h(fmt_date($spendSince)) . '.') . ($expired ? ' The authorization has already expired, so no run-out date is projected.' : ($runOut ? ' At this pace the funds run out around ' . h($runOut) . '.' : ($outlastsExpiry ? ' At this pace the authorization period ends before the funds are used up.' : ($runOutFar ? ' At this pace they last more than a year.' : '')))) : 'The funds are being used faster than the hours. Nothing was spent in the last 8 weeks, so a run-out date can\'t be projected yet.' ?></p>
  <?php else: ?>
  <p class="muted"><?= $weeklyRate > 0 ? 'Recent pace: ' . fmt_hours(round($weeklyRate, 2)) . ' per week ' . ($rateDays >= 56 ? 'over the last 8 weeks.' : 'since ' . h(fmt_date($rateSince)) . '.') . ($expired ? ' The authorization has already expired, so no run-out date is projected.' : ($runOut ? ' At this pace the hours run out around ' . h($runOut) . '.' : ($outlastsExpiry ? ' At this pace the authorization period ends before the hours are used up.' : ($runOutFar ? ' At this pace they last more than a year.' : '')))) : 'No hours logged in the last 8 weeks, so a run-out date can\'t be projected yet.' ?></p>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Request letter to the counselor</h2>
  <p class="muted">
    To: <?= $track['counselor_name'] ? h($track['counselor_name']) : '<span class="muted">no counselor name on file</span>' ?>
    <?= $track['counselor_email'] ? ' &lt;' . h($track['counselor_email']) . '&gt;' : '' ?>
    <?= $track['counselor_agency'] ? ' · ' . h($track['counselor_agency']) : '' ?>
  </p>
  <?php if ($counselorEmail !== '' && !$counselorConfirmed): ?>
  <p class="hint"><strong>Counselor email not confirmed:</strong> <?= h($counselorEmail) ?> has not been checked (an address from the public referral form, for example, may not be the counselor's), so this page does not open the letter in email addressed to it. <a href="client_edit.php?id=<?= (int)$track['cid'] ?>">Confirm the counselor email on <?= h($track['client']) ?>'s profile</a>, or copy the letter and send it to an address you have checked.</p>
  <?php endif; ?>
  <?php if ($aiNote): ?><p class="<?= $aiFailed ? 'field-error' : 'muted' ?>"><?= h($aiNote) ?></p><?php endif; ?>
  <label for="letter" class="visually-hidden">Amendment request letter</label>
  <?php /* Sent with "Draft the letter with AI" (form attribute), so edits are kept. */ ?>
  <textarea id="letter" name="letter" form="ai-letter-form" rows="16" style="width:100%;font-family:inherit"><?= h($draft) ?></textarea>
  <?php if ($aiAlt !== null): ?>
  <label for="ai-letter">AI draft <span class="hint">your letter above is unchanged; use this only if you want it</span></label>
  <textarea id="ai-letter" rows="12" style="width:100%;font-family:inherit"><?= h($aiAlt) ?></textarea>
  <div class="page-actions" style="margin-top:.5rem">
    <button type="button" class="btn btn-secondary btn-sm" id="use-ai-letter">Use the AI draft as the letter</button>
    <button type="button" class="btn btn-secondary btn-sm" id="restore-letter" hidden>Restore my letter</button>
  </div>
  <?php endif; ?>
  <div class="page-actions" style="margin-top:.5rem">
    <button type="button" class="btn btn-secondary btn-sm" id="copy-letter">Copy letter</button>
    <span id="copy-status" role="status" class="muted"></span>
    <?php if ($counselorConfirmed): ?>
    <a class="btn btn-primary btn-sm" id="mailbtn" href="#">Open in email to <?= h($track['counselor_name'] ?: 'counselor') ?></a>
    <?php endif; ?>
    <?php if (ai_feature_enabled('draft')): ?>
    <form method="post" action="amendment_request.php?track=<?= $trackId ?>" class="inline-form" id="ai-letter-form"><?= csrf_field() ?>
      <input type="hidden" name="track" value="<?= $trackId ?>">
      <button type="submit" name="ai_draft" value="1" class="btn btn-secondary btn-sm" aria-describedby="ai-letter-hint">Draft the letter with AI</button>
    </form>
    <?php endif; ?>
  </div>
  <?php if (ai_feature_enabled('draft')): ?>
  <p class="hint" id="ai-letter-hint">The AI drafts from the standard letter's facts. If you have changed the letter, your text is kept and the AI draft appears in a separate box.</p>
  <?php endif; ?>
  <p class="muted">The letter is a draft for you to review and send — nothing is emailed automatically. When you record the approved change on the track, it is saved to the amendment history.</p>
</section>
<?php $history = amendment_history($trackId); ?>
<section class="card" aria-labelledby="record-h">
  <h2 id="record-h">Record the request as sent</h2>
  <p class="muted">After you send the letter, record it here. It is kept on the track as a case note, and a follow-up task reminds you if no reply comes.</p>
  <form method="post" action="amendment_request.php?track=<?= $trackId ?>" class="stacked" id="record-form" novalidate><?= csrf_field() ?><?= web_idem_field() ?>
    <input type="hidden" name="track" value="<?= $trackId ?>">
    <input type="hidden" name="letter" id="record-letter" value="">
    <?= form_error_summary(['requested_on' => 'Date sent', 'hours' => 'Hours requested', 'amount' => 'Dollars requested', 'new_end' => 'New end date', 'letter' => 'Letter'],
        ['requested_on' => 'ar_on', 'hours' => 'ar_hours', 'amount' => 'ar_amount', 'new_end' => 'ar_end']) ?>
    <div class="field"><label for="ar_on">Date sent</label><input type="date" id="ar_on" name="requested_on" value="<?= h(old_in('requested_on', date('Y-m-d'))) ?>" <?= field_aria('requested_on') ?>><?= field_error('requested_on') ?></div>
    <div class="field"><label for="ar_to">Sent to</label><input type="text" id="ar_to" name="sent_to" maxlength="190" value="<?= h(old_in('sent_to', trim(($track['counselor_name'] ?? '') . ($counselorEmail !== '' ? ' <' . $counselorEmail . '>' : '')))) ?>"></div>
    <div class="field"><label for="ar_hours">Additional hours requested</label><input type="text" inputmode="decimal" id="ar_hours" name="hours" value="<?= h(old_in('hours')) ?>" <?= field_aria('hours') ?>><?= field_error('hours') ?></div>
    <div class="field"><label for="ar_amount">Additional dollars requested</label><input type="text" inputmode="decimal" id="ar_amount" name="amount" value="<?= h(old_in('amount')) ?>" <?= field_aria('amount') ?>><?= field_error('amount') ?></div>
    <div class="field"><label for="ar_end">New end date requested</label><input type="date" id="ar_end" name="new_end" value="<?= h(old_in('new_end')) ?>" <?= field_aria('new_end') ?>><?= field_error('new_end') ?></div>
    <button type="submit" name="record_request" value="1" class="btn btn-primary">Record request as sent</button>
  </form>
</section>
<?php if ($history): ?>
<section class="card" aria-labelledby="requests-h">
  <h2 id="requests-h">Requests and replies (<?= count($history) ?>)</h2>
  <?php foreach ($history as $a): $aid = (int)$a['id']; ?>
  <h3 id="req-<?= $aid ?>">Requested <?= fmt_date($a['requested_on']) ?>: <?= h($a['status']) ?></h3>
  <p><?= h(implode(', ', array_filter([$a['hours_requested'] !== null ? fmt_hours((float)$a['hours_requested']) . ' more' : '', $a['amount_requested'] !== null ? fmt_money((float)$a['amount_requested']) . ' more' : '', $a['new_end_date'] ? 'end date ' . fmt_date($a['new_end_date']) : '']))) ?><?= $a['sent_to'] ? ', sent to ' . h($a['sent_to']) : '' ?>.
    <?= $a['replied_on'] ? ' Reply ' . fmt_date($a['replied_on']) . ($a['reply_note'] ? ': ' . h($a['reply_note']) : '') . '.' : '' ?>
    <?= $a['amendment_id'] ? ' The approved change is recorded on the track.' : (in_array($a['status'], ['Approved', 'Partly approved'], true) ? ' <a href="track_edit.php?id=' . $trackId . '&amp;amend_request=' . $aid . '#amendment_reason">Enter the approved change on the track</a>.' : '') ?></p>
  <?php if ($a['status'] === 'Awaiting reply'): ?>
  <form method="post" action="amendment_request.php?track=<?= $trackId ?>" class="stacked" novalidate><?= csrf_field() ?>
    <input type="hidden" name="track" value="<?= $trackId ?>"><input type="hidden" name="request_id" value="<?= $aid ?>">
    <?= field_error('reply_' . $aid) ?>
    <div class="field"><label for="rs-<?= $aid ?>">Reply</label><select id="rs-<?= $aid ?>" name="reply_status" <?= field_aria('reply_' . $aid) ?>><option value="">Choose the reply</option><?= select_options(AMENDMENT_REPLY_STATUSES, null) ?></select></div>
    <div class="field"><label for="rd-<?= $aid ?>">Date of the reply</label><input type="date" id="rd-<?= $aid ?>" name="replied_on" value="<?= date('Y-m-d') ?>"></div>
    <div class="field"><label for="rn-<?= $aid ?>">Note</label><input type="text" id="rn-<?= $aid ?>" name="reply_note" maxlength="255"></div>
    <button type="submit" name="record_reply" value="1" class="btn btn-secondary btn-sm">Record reply<span class="visually-hidden"> to the request of <?= fmt_date($a['requested_on']) ?></span></button>
  </form>
  <?php endif; ?>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<script>
// The letter as it reads now goes with "Record request as sent" (3.0.44).
document.getElementById('record-form').addEventListener('submit', function () {
  var l = document.getElementById('letter'); if (l) document.getElementById('record-letter').value = l.value;
});
// Success is reported only once the clipboard write has actually succeeded.
document.getElementById('copy-letter').addEventListener('click', function () {
  var s = document.getElementById('copy-status');
  s.textContent = '';
  (navigator.clipboard ? navigator.clipboard.writeText(document.getElementById('letter').value) : Promise.reject())
    .then(function () { s.textContent = 'Letter copied to clipboard.'; })
    .catch(function () { s.textContent = 'Could not copy automatically — select the letter text and copy it.'; });
});
<?php if ($aiAlt !== null): ?>
// Put the AI draft in the letter box, keeping the user's own letter to restore.
(function () {
  var letter = document.getElementById('letter'), ai = document.getElementById('ai-letter'),
      use = document.getElementById('use-ai-letter'), restore = document.getElementById('restore-letter'),
      s = document.getElementById('copy-status'), mine = null;
  use.addEventListener('click', function () {
    if (mine === null || letter.value !== ai.value) mine = letter.value;
    letter.value = ai.value; restore.hidden = false;
    s.textContent = 'The AI draft is now the letter. Your own letter can be restored with "Restore my letter".';
    letter.focus();
  });
  restore.addEventListener('click', function () {
    if (mine !== null) letter.value = mine;
    restore.hidden = true; s.textContent = 'Your own letter is back in the letter box.';
    letter.focus();
  });
})();
<?php endif; ?>
</script>
<?php if ($counselorConfirmed): ?>
<script>
document.getElementById('mailbtn').addEventListener('click', function (e) {
  e.preventDefault();
  var body = encodeURIComponent(document.getElementById('letter').value);
  var subj = encodeURIComponent('Authorization amendment request — ' + <?= json_encode($track['auth_number'] ?: $track['title'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
  // Both halves of the address are URL-encoded, so a "?", "&" or "," in it can never
  // add a recipient or a header to the letter's email.
  var to = <?= json_encode($counselorEmail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, at = to.lastIndexOf('@');
  var addr = at > 0 ? encodeURIComponent(to.slice(0, at)) + '@' + encodeURIComponent(to.slice(at + 1)) : '';
  window.location.href = 'mailto:' + addr + '?subject=' + subj + '&body=' + body;
});
</script>
<?php endif; ?>
<?php page_footer();
