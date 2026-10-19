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
// The device is handed over for signing in person (4.27.0): only the hand-over page, its own Sign or Hand
// back post and the thank-you page run (kiosk_guard() has already refused anything else). No other form on
// this page is ever handled, and the staff page is never shown: anything left over ends the session.
$kiosk = is_array($_SESSION['kiosk_sign'] ?? null) ? $_SESSION['kiosk_sign'] : null;
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($kiosk && $isPost && (!isset($_POST['sign_now']) || (int)$_POST['sign_now'] !== (int)$kiosk['id'])) kiosk_end();

if (!$kiosk && $isPost && (isset($_POST['send_signature']) || isset($_POST['sign_here']))) {
    if (!empty($client['archived_at'])) { flash('This client is archived; nothing can be sent.', 'error'); redirect($here); }
    $inPerson = isset($_POST['sign_here']);
    [$errs, $v] = signature_input($_POST, $loans, $inPerson);
    if ($errs) { keep_input(); keep_field_errors($errs); redirect($here . '#send-h'); }
    // Signed here and now (4.25.0): nothing is emailed; the page to hand over opens.
    if ($inPerson) redirect($here . '&sign=' . signature_start_in_person($client, $v, $loans, (int)$user['id']));
    $sent = signature_send($client, $v, $loans, (int)$user['id']);
    if ($sent['error']) { keep_input(); flash($sent['error'], 'error'); redirect($here . '#send-h'); }
    flash('Sent to ' . $v['signer_name'] . ' to sign.');
    redirect($here . '#list-h');
}
if ($isPost && isset($_POST['sign_now'])) {
    $s = client_signature((int)$_POST['sign_now'], $id);
    if (isset($_POST['stop'])) {
        // Stopped without signing: the request is withdrawn; the hand-over ends with the next page.
        if ($s && empty($s['signed_at'])) signature_withdraw((int)$s['id'], $id, (int)$user['id']);
        redirect($here . '&signed=0');
    }
    $typed = trim(is_string($_POST['signature'] ?? null) ? $_POST['signature'] : '');
    $why = $s ? signature_sign_in_person($s, $typed, null, (int)$user['id']) : 'That document is no longer on the record.';
    if ($why !== null) { keep_input(); keep_field_errors(['signature' => $why]); redirect($here . '&sign=' . (int)$_POST['sign_now']); }
    redirect($here . '&signed=1');
}
// The device is still in the signer's hands (4.25.0): a page with nothing to follow. Any other page signs out.
if ($kiosk && !$isPost && isset($_GET['signed'])) {
    $GLOBALS['CMS_KIOSK'] = true;
    $es = ($_SESSION['kiosk_sign']['lang'] ?? '') === 'es';
    page_header($es ? 'Gracias' : 'Thank you');
    echo '<div class="card" style="max-width: 40rem; margin: 2rem auto;"' . ($es ? ' lang="es"' : '') . '><h1>' . ($_GET['signed'] === '1' ? ($es ? 'Quedó firmado. Gracias.' : 'It is signed. Thank you.') : ($es ? 'No se firmó.' : 'Not signed.'))
        . '</h1><p>' . ($es ? 'Devuelva el dispositivo, por favor.' : 'Please hand the device back.') . '</p></div>'
        . '<p style="text-align:center"><a class="btn btn-secondary" href="index.php">Staff: sign in again to carry on</a></p>';
    page_footer();
    exit;
}
if ($kiosk && $isPost) kiosk_end(); // its own post has redirected above; no other form runs during a hand-over
if ($isPost && isset($_POST['withdraw'])) {
    $res = signature_withdraw_result((int)$_POST['withdraw'], $id, (int)$user['id']);
    flash($res === 'withdrawn' ? 'Withdrawn. Its link no longer signs anything.'
        : ($res === 'signed' ? 'Nothing was changed: this document is already signed, so it cannot be withdrawn.' : 'That document was already withdrawn.'), $res === 'withdrawn' ? 'success' : 'error');
    redirect($here . '#list-h');
}

// The page to hand to the signer (4.25.0): the document and a typed-name signature, nothing else.
if (!$isPost && isset($_GET['sign']) && ($s = client_signature((int)$_GET['sign'], $id)) && signature_status($s) === 'Waiting to be signed here') {
    // From here the device is in the signer's hands: only this page opens until staff sign in again.
    $_SESSION['kiosk_sign'] = ['client' => $id, 'id' => (int)$s['id'], 'lang' => (string)($s['lang'] ?? '')];
    $GLOBALS['CMS_KIOSK'] = true;
    $es = ($s['lang'] ?? '') === 'es';
    $lang = in_array($s['lang'] ?? null, ['en', 'es'], true) ? ' lang="' . $s['lang'] . '"' : '';
    $paras = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', str_replace("\r", '', (string)$s['body']))), fn($p) => $p !== ''));
    page_header((form_errors() ? 'Error — ' : '') . $s['title']);
    ?>
<div class="card" style="max-width: 40rem; margin: 1rem auto;"<?= $es ? ' lang="es"' : '' ?>>
  <h1<?= $lang ?>><?= h($s['title']) ?></h1>
  <?php if ($es): ?>
  <p>En este documento, “nosotros” significa <?= h(brand('org')) ?> y “usted” significa la persona que firma. El nombre en este documento es <strong><?= h($s['signer_name']) ?></strong>. Al firmar, usted acepta lo siguiente:</p>
  <?php else: ?>
  <p>In this document, “we” means <?= h(brand('org')) ?> and “you” means the person who signs. The name on this document is <strong><?= h($s['signer_name']) ?></strong>. When you sign, you agree to the following:</p>
  <?php endif; ?>
  <div<?= $lang ?>><?php foreach ($paras as $p): $lines = array_values(array_filter(array_map('trim', explode("\n", $p)), fn($l) => $l !== '')); ?>
    <?= count($lines) > 1 ? '<ul><li>' . implode('</li><li>', array_map('h', $lines)) . '</li></ul>' : '<p>' . h($lines[0]) . '</p>' ?>
  <?php endforeach; ?></div>
  <form method="post" action="<?= h($here) ?>" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['signature' => $es ? 'Firma electrónica' : 'Electronic signature']) ?>
    <div class="field"><label for="signature"><?= $es ? 'Firma electrónica' : 'Electronic signature' ?> <?= required_mark() ?> <span class="hint"><?= $es ? 'Escriba su nombre completo (' . h($s['signer_name']) . ') para firmar. No importa si las mayúsculas, los acentos o la puntuación son diferentes.' : 'Type your full name (' . h($s['signer_name']) . ') to sign. Capital letters, accents and punctuation do not need to match.' ?></span></label>
      <input type="text" id="signature" name="signature" autocomplete="off" value="<?= h(old_in('signature')) ?>" <?= field_aria('signature') ?>><?= field_error('signature') ?></div>
    <button type="submit" name="sign_now" value="<?= (int)$s['id'] ?>" class="btn btn-primary"><?= $es ? 'Firmar' : 'Sign' ?></button>
  </form>
  <form method="post" action="<?= h($here) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="stop" value="1">
    <button type="submit" name="sign_now" value="<?= (int)$s['id'] ?>" class="btn btn-secondary"><?= $es ? 'Devolver sin firmar' : 'Hand back without signing' ?></button></form>
</div>
<?php
    page_footer();
    exit;
}
// The staff page is never shown on a handed-over device (Back after signing, a stale address).
if ($kiosk) kiosk_end();
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
    <li><strong><?= h($s['title']) ?></strong>: <?= h($st) ?>. <?= $st === 'Signed' ? 'Signed by ' . h($s['signed_name']) . ' on ' . h(fmt_datetime($s['signed_at'])) . (($s['method'] ?? '') === 'In person' ? ', in person' . (!empty($s['signature_path']) ? ' with a drawn signature' : '') : '') : ($st === 'Waiting to be signed here' ? 'For ' . h($s['signer_name']) . ' to sign here, started ' . h(fmt_date($s['sent_at'])) . ' <a href="' . h($here) . '&amp;sign=' . (int)$s['id'] . '">Sign it now<span class="visually-hidden">: ' . h($s['title']) . '</span></a>' : (($s['method'] ?? '') === 'In person' ? 'Started in person for ' . h($s['signer_name']) . ' on ' . h(fmt_date($s['sent_at'])) : 'Sent to ' . h($s['signer_name']) . ' (' . h($s['signer_email']) . ') on ' . h(fmt_date($s['sent_at'])))) ?><?= $st === 'Withdrawn' ? ', withdrawn ' . h(fmt_date($s['withdrawn_at'])) : '' ?>.
      <details><summary>What <?= $st === 'Signed' ? 'was signed' : 'it says' ?><span class="visually-hidden">: <?= h($s['title']) ?>, sent <?= h(fmt_date($s['sent_at'])) ?></span></summary><p><?= nl2br(h($s['body'])) ?></p></details>
      <?php if (in_array($st, ['Signed', 'Awaiting signature', 'Waiting to be signed here'], true)): ?>
      <form method="post" action="<?= h($here) ?>" class="inline-form"><?= csrf_field() ?><button type="submit" name="withdraw" value="<?= (int)$s['id'] ?>" class="btn btn-secondary btn-sm">Withdraw<span class="visually-hidden"> <?= h($s['title']) ?>, sent <?= h(fmt_date($s['sent_at'])) ?></span></button></form>
      <?php endif; ?></li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php if (empty($client['archived_at'])): ?>
<section class="card" aria-labelledby="send-h">
  <h2 id="send-h" tabindex="-1">Send a document to sign</h2>
  <p>Send it by email: they get a private link, read the document and sign by typing their name. Or, with them here, choose Sign here now and hand them this device (no email is needed then). The wording for consent to services and loan agreements is on Administration, Public wording.</p>
  <form method="post" action="<?= h($here) ?>" class="stacked" novalidate><?= csrf_field() ?>
    <?= form_error_summary(['kind' => 'What to send', 'signer_name' => 'Who signs', 'signer_email' => 'Their email', 'loan_id' => 'Device on loan', 'title' => 'Title', 'body' => 'What they agree to'], $loans ? [] : ['loan_id' => 'err-loan_id']) ?>
    <div class="field"><label for="kind">What to send <?= required_mark() ?></label>
      <select id="kind" name="kind" <?= field_aria('kind') ?>><?= kv_options(SIGN_KINDS, $old('kind', 'services'), 'Choose') ?></select><?= field_error('kind') ?></div>
    <div class="field"><label for="signer_role">Who signs</label>
      <select id="signer_role" name="signer_role"><?= kv_options(['Client' => 'The client', 'Guardian' => 'Their parent or guardian'], $old('signer_role', $minor ? 'Guardian' : 'Client'), 'Choose') ?></select></div>
    <div class="field"><label for="signer_name">Their full name <?= required_mark() ?></label>
      <input type="text" id="signer_name" name="signer_name" maxlength="120" autocomplete="off" value="<?= h($old('signer_name', (string)($minor ? $client['guardian_name'] : $client['name']))) ?>" <?= field_aria('signer_name') ?>><?= field_error('signer_name') ?></div>
    <div class="field"><label for="signer_email">Their email (needed to send it by email)</label>
      <input type="email" id="signer_email" name="signer_email" maxlength="190" autocomplete="off" value="<?= h($old('signer_email', (string)($minor ? ($client['guardian_email'] ?? '') : ($client['email'] ?? '')))) ?>" <?= field_aria('signer_email') ?>><?= field_error('signer_email') ?></div>
    <?php if ($loans): ?>
    <div class="field"><label for="loan_id">Device on loan, for a loan agreement</label>
      <select id="loan_id" name="loan_id" <?= field_aria('loan_id') ?>><option value="">None</option><?php foreach ($loans as $lid => $l): ?><option value="<?= $lid ?>"<?= $old('loan_id') === (string)$lid ? ' selected' : '' ?>><?= h($l['name'] . ($l['serial'] ? ' (' . $l['serial'] . ')' : '') . ', lent ' . fmt_date($l['loaned_at'])) ?></option><?php endforeach; ?></select><?= field_error('loan_id') ?></div>
    <?php elseif (isset($errs['loan_id'])): ?><p class="field-error" id="err-loan_id">This client has no device on loan, so there is no loan agreement to send.</p><?php endif; ?>
    <div class="field"><label for="title">Title, for another document</label>
      <input type="text" id="title" name="title" maxlength="190" value="<?= h($old('title')) ?>" <?= field_aria('title') ?>><?= field_error('title') ?></div>
    <div class="field"><label for="body">What they agree to, for another document (a blank line starts a new paragraph)</label>
      <textarea id="body" name="body" rows="8" maxlength="20000" <?= field_aria('body') ?>><?= h($old('body')) ?></textarea><?= field_error('body') ?></div>
    <button type="submit" name="send_signature" value="1" class="btn btn-primary">Send to sign by email</button>
    <button type="submit" name="sign_here" value="1" class="btn btn-secondary">Sign here now, in person</button>
  </form>
</section>
<?php endif; ?>
<?php page_footer();
