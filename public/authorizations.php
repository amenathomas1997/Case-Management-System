<?php
// Incoming authorizations (3.0.48): approved-authorization emails, pasted here or forwarded
// to the CMS mailbox (cron/ingest_email.php), read into a new track for staff to confirm.
// Nothing is sent to AI. A caseload-scoped user sees only emails matched to their own
// clients; an email not yet matched to a client is for everyone else to place.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('clients');
$scoped = caseload_scoped();

/** The email, if this user may see it. */
$load = function (int $id) use ($scoped, $user): ?array {
    $a = incoming_authorization($id);
    if (!$a) return null;
    if ($scoped) {
        // An email not yet matched is the scoped user's own to place when they pasted it.
        if (!$a['client_id']) return (int)$a['created_by'] === (int)$user['id'] ? $a : null;
        $c = db()->prepare('SELECT assigned_to FROM clients WHERE id = ?');
        $c->execute([(int)$a['client_id']]);
        if ((int)$c->fetchColumn() !== (int)$user['id']) return null;
    }
    return $a;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['read_email'])) {
    $text = is_string($_POST['email_text'] ?? null) ? trim($_POST['email_text']) : '';
    $p = $text !== '' ? authorization_email_parse($text) : null;
    if (!$p) {
        keep_input();
        keep_field_errors(['email_text' => $text === '' ? 'Paste the authorization email first.' : 'No authorization number was found in that text. Paste the whole email, including the line "Authorization Number:".']);
        redirect('authorizations.php#paste-h');
    }
    $id = authorization_email_store($p, $text, 'Pasted', null, (int)$user['id']);
    $row = $load($id);
    if (!$row) { flash('Authorization ' . $p['auth_number'] . ' was read, but its client is not on your caseload. An administrator can place it.', 'info'); redirect('authorizations.php'); }
    audit($user['id'], 'authorization.email', 'incoming_authorization', $id, 'pasted ' . $p['auth_number']);
    redirect('authorizations.php?id=' . $id . '#review-h');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['start_track']) || isset($_POST['dismiss']))) {
    $a = $load((int)($_POST['id'] ?? 0));
    if (!$a || $a['status'] !== 'New') { flash('That authorization email was already handled.', 'error'); redirect('authorizations.php'); }
    if (isset($_POST['dismiss'])) {
        incoming_authorization_handled((int)$a['id'], 'Dismissed', null, (int)$user['id']);
        flash('Authorization ' . $a['auth_number'] . ' dismissed.');
        redirect('authorizations.php');
    }
    $cid = ($_POST['client_id'] ?? '') === 'other' ? (int)($_POST['other_client'] ?? 0) : (int)($_POST['client_id'] ?? 0);
    $c = db()->prepare('SELECT id, name, assigned_to, counselor_name, counselor_email FROM clients WHERE id = ? AND archived_at IS NULL');
    $c->execute([$cid]);
    $client = $c->fetch();
    if (!$client || ($scoped && (int)$client['assigned_to'] !== (int)$user['id'])) {
        // 4.30.0: tied to the client choice (not a flash), and what was chosen is kept.
        keep_input();
        keep_field_errors(['client_id' => 'Choose the client this authorization is for.']);
        redirect('authorizations.php?id=' . (int)$a['id'] . '#client-choice');
    }
    db()->prepare('UPDATE incoming_authorizations SET client_id = ? WHERE id = ?')->execute([$cid, (int)$a['id']]);
    $counselorKept = !empty($_POST['set_counselor']) && trim((string)$client['counselor_email']) !== '' && strcasecmp(trim((string)$client['counselor_email']), (string)$a['counselor_email']) !== 0;
    if ($counselorKept) flash($client['name'] . ' already has a different VR counselor (' . $client['counselor_email'] . '), so it was not changed. Change it on the client\'s edit page if the counselor has changed.', 'info');
    if (!empty($_POST['set_counselor']) && $a['counselor_email'] && !$counselorKept) {
        // 4.27.0: confirmed only when the counselor sent it to the mailbox themselves; an address read
        // from the email's text is recorded unconfirmed (an already-confirmed same address stays confirmed).
        $confirmed = authorization_counselor_confirmed($a) ? 1 : 0;
        db()->prepare('UPDATE clients SET counselor_verified = IF(LOWER(COALESCE(counselor_email, \'\')) = LOWER(?), GREATEST(counselor_verified, ?), ?), counselor_name = ?, counselor_email = ? WHERE id = ?')
            ->execute([$a['counselor_email'], $confirmed, $confirmed, $a['counselor_name'] ?: $client['counselor_name'], $a['counselor_email'], $cid]);
        audit($user['id'], 'client.counselor', 'client', $cid, 'from authorization ' . $a['auth_number'] . ': ' . ($client['counselor_email'] ?: 'none') . ' -> ' . $a['counselor_email'] . ($confirmed ? '' : ' (unconfirmed)'));
        if (!$confirmed && strcasecmp(trim((string)$client['counselor_email']), (string)$a['counselor_email']) !== 0) {
            flash($a['counselor_email'] . ' is recorded as ' . $client['name'] . '\'s VR counselor, unconfirmed: no report goes to it until it is confirmed on the client\'s edit page.', 'info');
        }
    }
    $_SESSION['auth_prefill'] = [$cid => authorization_track_prefill($a, $cid)];
    redirect('track_edit.php?client_id=' . $cid . '&from_auth=' . (int)$a['id']);
}

// Waiting emails this user may see.
$wq = db()->prepare("SELECT a.*, c.name AS matched_name FROM incoming_authorizations a LEFT JOIN clients c ON c.id = a.client_id WHERE a.status = 'New'"
    . ($scoped ? ' AND (c.assigned_to = ? OR (a.client_id IS NULL AND a.created_by = ?))' : '') . ' ORDER BY a.created_at DESC LIMIT 200');
$wq->execute($scoped ? [(int)$user['id'], (int)$user['id']] : []);
$waiting = $wq->fetchAll();
$review = isset($_GET['id']) ? $load((int)$_GET['id']) : null;

page_header('Incoming authorizations', $user, 'authorizations');
$yn = fn($v) => $v === null || $v === '' ? 'not given' : $v;
?>
<h1>Incoming authorizations</h1>
<p>Paste an approved authorization email, or forward it to the CMS mailbox, and the track form is filled in from it for you to check.</p>

<?php if ($review): $p = authorization_email_parse((string)$review['email_text']) ?? ['not_approved' => false];
    $matches = authorization_email_clients((string)$review['client_name'], (string)$review['counselor_email']);
    $already = array_values(array_filter(authorization_email_tracks((string)$review['auth_number']), fn($t) => !$scoped || (int)$t['assigned_to'] === (int)$user['id']));
    $matches = array_values(array_filter($matches, fn($m) => empty($m['archived_at']) && (!$scoped || (int)$m['assigned_to'] === (int)$user['id'])));
    $preset = $review['client_id'] ? (int)$review['client_id'] : (count($matches) === 1 ? (int)$matches[0]['id'] : 0);
    // After a refused choice, what was chosen comes back (4.30.0).
    $keptChoice = old_in('client_id'); $keptOther = (int)old_in('other_client');
    if ($keptChoice === 'other') $preset = 0; elseif (ctype_digit($keptChoice)) $preset = (int)$keptChoice;
    $firstChoice = $matches ? 'client-choice-' . (int)$matches[0]['id'] : 'client-choice-other'; ?>
<section class="card" aria-labelledby="review-h">
  <h2 id="review-h">Authorization <?= h($review['auth_number']) ?></h2>
  <?php if ($review['status'] !== 'New'): ?><p><?= h($review['status']) ?><?= $review['track_id'] ? ': <a href="track.php?id=' . (int)$review['track_id'] . '">open the track</a>' : '' ?>.</p><?php endif; ?>
  <?php if (!empty($p['not_approved'])): ?><p><strong>This email does not say the authorization was approved.</strong> Check it with the counselor before starting a track.</p><?php endif; ?>
  <?php foreach ($already as $t): ?><p><strong>Already on a track:</strong> <a href="track.php?id=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a> for <?= h($t['client_name']) ?><?= $t['authorized_amount'] !== null && $review['amount'] !== null && (float)$t['authorized_amount'] !== (float)$review['amount'] ? ' (authorized ' . fmt_money((float)$t['authorized_amount']) . ' there, ' . fmt_money((float)$review['amount']) . ' in this email: if it changed, record an amendment on the track)' : '' ?>.</p><?php endforeach; ?>
  <dl class="props">
    <dt>Client name in the email</dt><dd><?= h($yn($review['client_name'])) ?></dd>
    <dt>Service category</dt><dd><?= h($yn($review['service_category'])) ?></dd>
    <dt>Amount</dt><dd><?= $review['amount'] !== null ? fmt_money((float)$review['amount']) : 'not given' ?></dd>
    <dt>Hours</dt><dd><?= $review['hours'] !== null ? fmt_hours((float)$review['hours']) : 'not given (worked out from the amount at the client\'s last hourly rate, if there is one)' ?></dd>
    <dt>Approved</dt><dd><?= $review['approved_on'] ? fmt_date($review['approved_on']) : 'not given' ?></dd>
    <dt>Dates</dt><dd><?= $review['start_date'] || $review['end_date'] ? h(($review['start_date'] ? fmt_date($review['start_date']) : 'no start') . ' to ' . ($review['end_date'] ? fmt_date($review['end_date']) : 'no end date')) : 'not given (the approval date is used as the start)' ?></dd>
    <dt>Counselor</dt><dd><?= h(trim(($review['counselor_name'] ?? '') . ($review['counselor_email'] ? ', ' . $review['counselor_email'] : '')) ?: 'not given') ?></dd>
    <dt>Funding agency</dt><dd><?= h($yn($review['agency'])) ?></dd>
    <dt>Received</dt><dd><?= fmt_date($review['created_at']) ?>, <?= $review['source'] === 'Mailbox' ? 'forwarded to the CMS mailbox' : 'pasted' ?></dd>
  </dl>
  <?php if ($review['status'] === 'New'): ?>
  <form method="post" action="authorizations.php?id=<?= (int)$review['id'] ?>" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['client_id' => 'Which client is this for?'], ['client_id' => $firstChoice]) ?>
    <input type="hidden" name="id" value="<?= (int)$review['id'] ?>">
    <fieldset id="client-choice" role="radiogroup" aria-required="true" <?= field_aria('client_id') ?>><legend>Which client is this for? <?= required_mark() ?></legend>
      <?= field_error('client_id') ?>
      <?php foreach ($matches as $m): ?>
        <label><input type="radio" id="client-choice-<?= (int)$m['id'] ?>" name="client_id" value="<?= (int)$m['id'] ?>"<?= $preset === (int)$m['id'] ? ' checked' : '' ?>> <?= h($m['name']) ?> (<?= h($m['status']) ?><?= $m['dob'] ? ', born ' . fmt_date($m['dob']) : '' ?><?= $m['instructor'] ? ', ' . h($m['instructor']) . '\'s caseload' : '' ?><?= $m['same_counselor'] ? ', same counselor' : '' ?>)</label>
      <?php endforeach; ?>
        <label><input type="radio" id="client-choice-other" name="client_id" value="other"<?= $preset === 0 ? ' checked' : '' ?>> Another client, chosen below</label>
      <?php if (!$matches): ?><p>No client record is named <?= h((string)$review['client_name']) ?>. <a href="client_edit.php">Add the client</a> first, or choose another client below.</p><?php endif; ?>
      <?php $oq = db()->prepare('SELECT id, name FROM clients WHERE archived_at IS NULL' . ($scoped ? ' AND assigned_to = ?' : '') . ' ORDER BY name LIMIT 2000');
            $oq->execute($scoped ? [(int)$user['id']] : []); ?>
      <label for="other_client">Another client</label>
      <select id="other_client" name="other_client"><option value="">None</option><?php foreach ($oq->fetchAll() as $o): ?><option value="<?= (int)$o['id'] ?>"<?= $keptOther === (int)$o['id'] ? ' selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?></select>
    </fieldset>
    <?php if ($review['counselor_email']): ?>
    <?php $confirmedCounselor = authorization_counselor_confirmed($review); /* 4.27.0: pre-ticked only when the counselor sent it themselves */ ?>
    <label><input type="checkbox" name="set_counselor" value="1"<?= $confirmedCounselor && $preset && ($pm = array_values(array_filter($matches, fn($m) => (int)$m['id'] === $preset))) && trim((string)$pm[0]['counselor_email']) === '' ? ' checked' : '' ?>> Record <?= h(trim(($review['counselor_name'] ?: '') . ' ' . $review['counselor_email'])) ?> as the client's VR counselor<?= $confirmedCounselor ? '' : ' (unconfirmed until checked on the client\'s edit page)' ?></label>
    <?php endif; ?>
    <p><button type="submit" name="start_track" value="1" class="btn btn-primary">Start the track from this authorization</button>
      <button type="submit" name="dismiss" value="1" class="btn btn-secondary" onclick="return confirm('Dismiss authorization <?= h(addslashes((string)$review['auth_number'])) ?> without starting a track?');">Dismiss</button></p>
  </form>
  <?php endif; ?>
  <details><summary>The email as received</summary><div class="card" style="white-space:pre-wrap"><?= h((string)$review['email_text']) ?></div></details>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="waiting-h">
  <h2 id="waiting-h">Waiting (<?= count($waiting) ?>)</h2>
  <?php if (!$waiting): ?><p>No authorization emails are waiting.</p><?php else: ?>
  <ul>
    <?php foreach ($waiting as $w): ?>
    <li><a href="authorizations.php?id=<?= (int)$w['id'] ?>#review-h"><?= h($w['auth_number']) ?></a>: <?= h((string)($w['matched_name'] ?? $w['client_name'] ?? 'no client name')) ?><?= $w['matched_name'] ? '' : ' (not matched to a client yet)' ?><?= $w['amount'] !== null ? ', ' . fmt_money((float)$w['amount']) : '' ?>, received <?= fmt_date($w['created_at']) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<section class="card" aria-labelledby="paste-h">
  <h2 id="paste-h">Paste an authorization email</h2>
  <form method="post" action="authorizations.php" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['email_text' => 'The authorization email']) ?>
    <div class="field"><label for="email_text">The authorization email, including the line Authorization Number <?= required_mark() ?></label>
      <textarea id="email_text" name="email_text" rows="10" style="width:100%" required aria-required="true" <?= field_aria('email_text') ?>><?= h(old_in('email_text')) ?></textarea><?= field_error('email_text') ?></div>
    <button type="submit" name="read_email" value="1" class="btn btn-primary">Read the email</button>
  </form>
</section>
<?php page_footer();
