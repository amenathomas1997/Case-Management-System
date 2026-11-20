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

// 4.38.0: the visibility rule, the waiting list, the review and each step are shared with the
// apps (inc/authorization_email.php), so both read, start and dismiss exactly alike.
$load = fn(int $id): ?array => incoming_authorization_for($id, $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['read_email'])) {
    $res = incoming_authorization_paste(is_string($_POST['email_text'] ?? null) ? $_POST['email_text'] : '', $user);
    if (!$res['ok']) {
        keep_input();
        keep_field_errors($res['errors']);
        redirect('authorizations.php#paste-h');
    }
    if ($res['id'] === null) { flash($res['message'], 'info'); redirect('authorizations.php'); }
    redirect('authorizations.php?id=' . $res['id'] . '#review-h');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['start_track']) || isset($_POST['dismiss']))) {
    $a = $load((int)($_POST['id'] ?? 0));
    if (!$a || $a['status'] !== 'New') { flash('That authorization email was already handled.', 'error'); redirect('authorizations.php'); }
    if (isset($_POST['dismiss'])) {
        $res = incoming_authorization_dismiss($a, $user);
        flash($res['message'], $res['ok'] ? 'success' : 'error');
        redirect('authorizations.php');
    }
    $cid = ($_POST['client_id'] ?? '') === 'other' ? (int)($_POST['other_client'] ?? 0) : (int)($_POST['client_id'] ?? 0);
    $res = incoming_authorization_start($a, $cid, !empty($_POST['set_counselor']), $user);
    if (!$res['ok']) {
        // 4.30.0: tied to the client choice (not a flash), and what was chosen is kept.
        keep_input();
        keep_field_errors($res['errors']);
        redirect('authorizations.php?id=' . (int)$a['id'] . '#client-choice');
    }
    foreach ($res['notices'] as $notice) flash($notice, 'info');
    $_SESSION['auth_prefill'] = [$cid => $res['prefill']];
    redirect('track_edit.php?client_id=' . $cid . '&from_auth=' . (int)$a['id']);
}

// Waiting emails this user may see.
$waiting = incoming_authorizations_waiting($user);
$review = isset($_GET['id']) ? $load((int)$_GET['id']) : null;

page_header('Incoming authorizations', $user, 'authorizations');
?>
<h1>Incoming authorizations</h1>
<p>Paste an approved authorization email, or forward it to the CMS mailbox, and the track form is filled in from it for you to check.</p>

<?php if ($review): $rv = incoming_authorization_review($review, $user);
    $matches = $rv['match_rows']; $preset = $rv['preset_client_id'];
    // After a refused choice, what was chosen comes back (4.30.0).
    $keptChoice = old_in('client_id'); $keptOther = (int)old_in('other_client');
    if ($keptChoice === 'other') $preset = 0; elseif (ctype_digit($keptChoice)) $preset = (int)$keptChoice;
    $firstChoice = $matches ? 'client-choice-' . (int)$matches[0]['id'] : 'client-choice-other'; ?>
<section class="card" aria-labelledby="review-h">
  <h2 id="review-h">Authorization <?= h($review['auth_number']) ?></h2>
  <?php if ($review['status'] !== 'New'): ?><p><?= h($review['status']) ?><?= $review['track_id'] ? ': <a href="track.php?id=' . (int)$review['track_id'] . '">open the track</a>' : '' ?>.</p><?php endif; ?>
  <?php if ($rv['not_approved']): ?><p><strong>This email does not say the authorization was approved.</strong> Check it with the counselor before starting a track.</p><?php endif; ?>
  <?php foreach ($rv['already_rows'] as $t): ?><p><strong>Already on a track:</strong> <a href="track.php?id=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a> for <?= h($t['client_name']) ?><?= $t['amounts_text'] ?>.</p><?php endforeach; ?>
  <dl class="props">
    <?php foreach ($rv['details'] as [$label, $value]): ?><dt><?= h($label) ?></dt><dd><?= h($value) ?></dd>
    <?php endforeach; ?>
  </dl>
  <?php if ($review['status'] === 'New'): ?>
  <form method="post" action="authorizations.php?id=<?= (int)$review['id'] ?>" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['client_id' => 'Which client is this for?'], ['client_id' => $firstChoice]) ?>
    <input type="hidden" name="id" value="<?= (int)$review['id'] ?>">
    <fieldset id="client-choice" role="radiogroup" aria-required="true" <?= field_aria('client_id') ?>><legend>Which client is this for? <?= required_mark() ?></legend>
      <?= field_error('client_id') ?>
      <?php foreach ($rv['matches'] as [$mid, $mtext]): ?>
        <label><input type="radio" id="client-choice-<?= $mid ?>" name="client_id" value="<?= $mid ?>"<?= $preset === $mid ? ' checked' : '' ?>> <?= h($mtext) ?></label>
      <?php endforeach; ?>
        <label><input type="radio" id="client-choice-other" name="client_id" value="other"<?= $preset === 0 ? ' checked' : '' ?>> Another client, chosen below</label>
      <?php if (!$matches): ?><p>No client record is named <?= h((string)$review['client_name']) ?>. <a href="client_edit.php">Add the client</a> first, or choose another client below.</p><?php endif; ?>
      <label for="other_client">Another client</label>
      <select id="other_client" name="other_client"><option value="">None</option><?php foreach ($rv['other_clients'] as [$oid, $oname]): ?><option value="<?= $oid ?>"<?= $keptOther === $oid ? ' selected' : '' ?>><?= h($oname) ?></option><?php endforeach; ?></select>
    </fieldset>
    <?php if ($rv['counselor']): /* 4.27.0: pre-ticked only when the counselor sent it themselves */ ?>
    <label><input type="checkbox" name="set_counselor" value="1"<?= $rv['counselor']['confirmed'] && $preset && ($pm = array_values(array_filter($matches, fn($m) => (int)$m['id'] === $preset))) && trim((string)$pm[0]['counselor_email']) === '' ? ' checked' : '' ?>> Record <?= h($rv['counselor']['text']) ?> as the client's VR counselor<?= $rv['counselor']['confirmed'] ? '' : ' (unconfirmed until checked on the client\'s edit page)' ?></label>
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
    <li><a href="authorizations.php?id=<?= (int)$w['id'] ?>#review-h"><?= h($w['auth_number']) ?></a>: <?= h(incoming_authorization_row_rest($w)) ?></li>
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
