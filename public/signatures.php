<?php
// Documents to sign (4.23.0): send a client (or their guardian) consent to services, an equipment loan
// agreement or any document to sign by email (inc/signatures.php, sign.php), and see what was signed.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
csrf_check();
$user = require_login();
require_perm('clients');
$id = (int)($_GET['client'] ?? 0);
$stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute([$id]);
$client = $stmt->fetch();
if (!$client) { flash('Client not found.', 'error'); redirect('clients.php'); }
scope_check_client($client, $user);
$loans = signature_open_loans($id);
$here = 'signatures.php?client=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_signature'])) {
    if (!empty($client['archived_at'])) { flash('This client is archived; nothing can be sent.', 'error'); redirect($here); }
    [$errs, $v] = signature_input($_POST, $loans);
    if ($errs) { keep_input(); keep_field_errors($errs); redirect($here . '#send-h'); }
    $sent = signature_send($client, $v, $loans, (int)$user['id']);
    if ($sent['error']) { keep_input(); flash($sent['error'], 'error'); redirect($here . '#send-h'); }
    flash('Sent to ' . $v['signer_name'] . ' to sign.');
    redirect($here . '#list-h');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['withdraw'])) {
    $done = signature_withdraw((int)$_POST['withdraw'], $id, (int)$user['id']);
    flash($done ? 'Withdrawn. Its link no longer signs anything.' : 'That document was already withdrawn.', $done ? 'success' : 'error');
    redirect($here . '#list-h');
}

$list = client_signatures($id);
$minor = !empty($client['guardian_name']) && !empty($client['dob']) && $client['dob'] > date('Y-m-d', strtotime('-18 years'));
$old = fn(string $k, string $d = ''): string => old_in('kind') !== '' ? old_in($k) : $d;
$errs = form_errors();
page_header('Documents to sign: ' . $client['name'], $user, 'clients', [['clients.php', 'Clients'], ['client.php?id=' . $id, $client['name']]]);
?>
<h1>Documents to sign: <?= h($client['name']) ?></h1>
<section class="card" aria-labelledby="list-h">
  <h2 id="list-h" tabindex="-1">Sent to sign (<?= count($list) ?>)</h2>
  <?php if (!$list): ?><p>Nothing yet.</p><?php else: ?>
  <ul>
  <?php foreach ($list as $s): $st = signature_status($s); ?>
    <li><strong><?= h($s['title']) ?></strong>: <?= h($st) ?>. <?= $st === 'Signed' ? 'Signed by ' . h($s['signed_name']) . ' on ' . h(fmt_datetime($s['signed_at'])) : 'Sent to ' . h($s['signer_name']) . ' (' . h($s['signer_email']) . ') on ' . h(fmt_date($s['sent_at'])) ?><?= $st === 'Withdrawn' ? ', withdrawn ' . h(fmt_date($s['withdrawn_at'])) : '' ?>.
      <details><summary>What <?= $st === 'Signed' ? 'was signed' : 'it says' ?><span class="visually-hidden">: <?= h($s['title']) ?>, sent <?= h(fmt_date($s['sent_at'])) ?></span></summary><p><?= nl2br(h($s['body'])) ?></p></details>
      <?php if (in_array($st, ['Signed', 'Awaiting signature'], true)): ?>
      <form method="post" action="<?= h($here) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" name="withdraw" value="<?= (int)$s['id'] ?>" class="btn btn-secondary btn-sm">Withdraw<span class="visually-hidden"> <?= h($s['title']) ?>, sent <?= h(fmt_date($s['sent_at'])) ?></span></button></form>
      <?php endif; ?></li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php if (empty($client['archived_at'])): ?>
<section class="card" aria-labelledby="send-h">
  <h2 id="send-h" tabindex="-1">Send a document to sign</h2>
  <p>They get a private link by email, read the document and sign by typing their name. The wording for consent to services and loan agreements is on Administration, Public wording.</p>
  <form method="post" action="<?= h($here) ?>" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['kind' => 'What to send', 'signer_name' => 'Who signs', 'signer_email' => 'Their email', 'loan_id' => 'Device on loan', 'title' => 'Title', 'body' => 'What they agree to'], $loans ? [] : ['loan_id' => 'err-loan_id']) ?>
    <div class="field"><label for="kind">What to send <?= required_mark() ?></label>
      <select id="kind" name="kind" <?= field_aria('kind') ?>><?= kv_options(SIGN_KINDS, $old('kind', 'services'), 'Choose') ?></select><?= field_error('kind') ?></div>
    <div class="field"><label for="signer_role">Who signs</label>
      <select id="signer_role" name="signer_role"><?= kv_options(['Client' => 'The client', 'Guardian' => 'Their parent or guardian'], $old('signer_role', $minor ? 'Guardian' : 'Client'), 'Choose') ?></select></div>
    <div class="field"><label for="signer_name">Their full name <?= required_mark() ?></label>
      <input type="text" id="signer_name" name="signer_name" maxlength="120" autocomplete="off" value="<?= h($old('signer_name', (string)($minor ? $client['guardian_name'] : $client['name']))) ?>" <?= field_aria('signer_name') ?>><?= field_error('signer_name') ?></div>
    <div class="field"><label for="signer_email">Their email <?= required_mark() ?></label>
      <input type="email" id="signer_email" name="signer_email" maxlength="190" autocomplete="off" value="<?= h($old('signer_email', (string)($minor ? ($client['guardian_email'] ?? '') : ($client['email'] ?? '')))) ?>" <?= field_aria('signer_email') ?>><?= field_error('signer_email') ?></div>
    <?php if ($loans): ?>
    <div class="field"><label for="loan_id">Device on loan, for a loan agreement</label>
      <select id="loan_id" name="loan_id" <?= field_aria('loan_id') ?>><option value="">None</option><?php foreach ($loans as $lid => $l): ?><option value="<?= $lid ?>"<?= $old('loan_id') === (string)$lid ? ' selected' : '' ?>><?= h($l['name'] . ($l['serial'] ? ' (' . $l['serial'] . ')' : '') . ', lent ' . fmt_date($l['loaned_at'])) ?></option><?php endforeach; ?></select><?= field_error('loan_id') ?></div>
    <?php elseif (isset($errs['loan_id'])): ?><p class="field-error" id="err-loan_id">This client has no device on loan, so there is no loan agreement to send.</p><?php endif; ?>
    <div class="field"><label for="title">Title, for another document</label>
      <input type="text" id="title" name="title" maxlength="190" value="<?= h($old('title')) ?>" <?= field_aria('title') ?>><?= field_error('title') ?></div>
    <div class="field"><label for="body">What they agree to, for another document (a blank line starts a new paragraph)</label>
      <textarea id="body" name="body" rows="8" maxlength="20000" <?= field_aria('body') ?>><?= h($old('body')) ?></textarea><?= field_error('body') ?></div>
    <button type="submit" name="send_signature" value="1" class="btn btn-primary">Send to sign</button>
  </form>
</section>
<?php endif; ?>
<?php page_footer();
