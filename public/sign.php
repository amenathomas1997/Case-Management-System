<?php
// Public signing page (4.23.0): a document sent to sign (inc/signatures.php), read and signed by typing
// the signer's name, as on the release of information. The words shown are the ones kept with the
// request when it was sent. Signed pages stay viewable as a receipt.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
enable_public_translation();
register_translations([
    'Document to sign' => 'Documento para firmar',
    'Error — Document to sign' => 'Error — Documento para firmar',
    'Document signed' => 'Documento firmado',
    'Document signed — thank you' => 'Documento firmado — gracias',
    'Document withdrawn' => 'Documento retirado',
    'Your signature was recorded. Thank you.' => 'Su firma quedó registrada. Gracias.',
    'In this document, “we” means' => 'En este documento, “nosotros” significa',
    'and “you” means the person who signs.' => 'y “usted” significa la persona que firma.',
    'You are signing for yourself.' => 'Usted firma por sí mismo/a.',
    'You are signing as the parent or guardian.' => 'Usted firma como padre, madre o tutor.',
    'The name on this document is' => 'El nombre en este documento es',
    'When you sign, you agree to the following:' => 'Al firmar, usted acepta lo siguiente:',
    'Signed by' => 'Firmado por',
    'You can print or save this page for your records.' => 'Puede imprimir o guardar esta página para sus registros.',
    'This document was withdrawn on' => 'Este documento se retiró el',
    'If you received a newer document, please use the link in that email. Otherwise, contact' => 'Si recibió un documento más reciente, use el enlace de ese correo. Si no, comuníquese con',
    'This link is invalid or has been replaced.' => 'Este enlace no es válido o fue reemplazado.',
    'This link is invalid or has been replaced. Please contact' => 'Este enlace no es válido o fue reemplazado. Comuníquese con',
    'for a new one.' => 'para obtener uno nuevo.',
    'Capital letters, accents and punctuation do not need to match.' => 'No importa si las mayúsculas, los acentos o la puntuación son diferentes.',
    'Type your full name to sign.' => 'Escriba su nombre completo para firmar.',
    'Type your full name as it appears on the document (120 characters maximum).' => 'Escriba su nombre completo como aparece en el documento (120 caracteres como máximo).',
    'The name typed does not match the name on this document.' => 'El nombre escrito no coincide con el nombre en este documento.',
    'To sign, type:' => 'Para firmar, escriba:',
    'If you have questions before signing, contact' => 'Si tiene preguntas antes de firmar, comuníquese con',
    'This page was open for a long time, so your signature was not sent yet. Your typed name is still in the box. Please check it and choose Sign and submit again.' => 'Esta página estuvo abierta por mucho tiempo, así que su firma todavía no se envió. El nombre que escribió sigue en el cuadro. Revíselo y elija Firmar y enviar otra vez.',
]);
// As on release.php: a lapsed session token signs nothing and keeps the typed name.
$csrfStale = $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SESSION['user_id'])
    && !(is_string($_POST['csrf'] ?? null) && hash_equals(csrf_token(), $_POST['csrf']));
if (!$csrfStale) csrf_check();

$rawToken = $_GET['t'] ?? '';
$token = is_string($rawToken) ? preg_replace('/[^a-f0-9]/', '', $rawToken) : '';
$doc = null;
if (strlen($token) === 48) {
    // Unsigned links expire; signed ones stay viewable as a receipt. Nothing of an archived client is shown.
    try {
        $stmt = db()->prepare('SELECT s.*, c.name AS client_name FROM client_signatures s JOIN clients c ON c.id = s.client_id
            WHERE s.token = ? AND (s.signed_at IS NOT NULL OR s.sent_at > DATE_SUB(NOW(), INTERVAL ' . cms_number('sign_link_days') . ' DAY)) AND c.archived_at IS NULL');
        $stmt->execute([$token]);
        $doc = $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        $doc = null; // mig_4.23.0 not run yet
    }
}
$contact = (string)(cfg('support_email') ?: cfg('mail_from'));
$contactHtml = $contact !== '' ? h(brand('short')) . ' (<a href="mailto:' . h($contact) . '">' . h($contact) . '</a>)' : h(brand('short'));
if (support_contact_html(false) !== '') $contactHtml = h(brand('short')) . ': ' . support_contact_html(false);

if (!$doc) {
    page_header('Document to sign');
    echo lang_toggle();
    $phoneContact = support_contact_html();
    echo '<div class="card auth-card"><h1>' . t('Link not recognized') . '</h1><p>'
        . ($phoneContact !== '' ? t('This link is invalid or has been replaced.') . ' ' . $phoneContact . ' ' . t('for a new one.')
            : t('This link is invalid or has been replaced. Please contact') . ' ' . $contactHtml . ' ' . t('for a new one.'))
        . '</p></div>';
    page_footer();
    exit;
}

$sigKey = 'sign_sig_' . (int)$doc['id'];
$staleSig = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$doc['signed_at'] && empty($doc['withdrawn_at'])) {
    $signature = trim(is_string($_POST['signature'] ?? null) ? $_POST['signature'] : '');
    if ($csrfStale) {
        $staleSig = mb_check_encoding($signature, 'UTF-8') ? $signature : '';
    } else {
        $want = typed_name_key((string)$doc['signer_name']);
        $tooLong = mb_check_encoding($signature, 'UTF-8') && mb_strlen($signature) > 120;
        $matches = !$tooLong && mb_check_encoding($signature, 'UTF-8') && $signature !== ''
            && ($want !== '' ? typed_name_key($signature) === $want
                : mb_convert_case($signature, MB_CASE_FOLD, 'UTF-8') === mb_convert_case(trim((string)$doc['signer_name']), MB_CASE_FOLD, 'UTF-8'));
        if (!$matches) {
            $_SESSION[$sigKey] = mb_check_encoding($signature, 'UTF-8') ? $signature : '';
            keep_field_errors(['signature' => $signature === '' ? 'Type your full name to sign.'
                : ($tooLong ? 'Type your full name as it appears on the document (120 characters maximum).' : 'The name typed does not match the name on this document.')]);
            redirect('sign.php?t=' . $token);
        }
        unset($_SESSION[$sigKey]);
        if (signature_sign($doc, $signature)) {
            notify_admin_users('Signed: ' . $doc['title'] . ' for ' . $doc['client_name'], 'signatures.php?client=' . (int)$doc['client_id']);
            flash_ui('Your signature was recorded. Thank you.');
        }
        redirect('sign.php?t=' . $token);
    }
}

$withdrawn = !empty($doc['withdrawn_at']);
page_header($withdrawn ? 'Document withdrawn' : ($doc['signed_at'] ? 'Document signed' : (form_errors() || $staleSig !== null ? 'Error — Document to sign' : 'Document to sign')));
echo lang_toggle();
// The document's own words are as kept when it was sent (record data): shown as written, never translated, and
// marked with their language when it is known and differs from the page's.
// A document staff wrote ("Another document") has no language stored: staff write in the
// English-only staff app, so on a page that is not English it is marked English (4.28.0),
// as staff-written text is everywhere else on the public pages.
$docLangCode = in_array($doc['lang'] ?? null, ['en', 'es'], true) ? (string)$doc['lang'] : 'en';
$docLang = $docLangCode !== page_lang() ? ' lang="' . $docLangCode . '"' : '';
$paras = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', str_replace("\r", '', (string)$doc['body']))), fn($p) => $p !== ''));
?>
<div class="card" style="max-width: 40rem; margin: 2rem auto;">
<?php if ($withdrawn): ?>
  <h1><?= t('Document withdrawn') ?></h1>
  <p><?= t('This document was withdrawn on') ?> <?= en(fmt_date($doc['withdrawn_at'])) ?>.</p>
  <p><?= t('If you received a newer document, please use the link in that email. Otherwise, contact') ?> <?= $contactHtml ?>.</p>
<?php else: ?>
  <h1><?= $doc['signed_at'] ? t('Document signed — thank you') : '<span' . $docLang . '>' . h($doc['title']) . '</span>' ?></h1>
  <?php if ($doc['signed_at']): ?>
  <p><?= t('Signed by') ?> <strong><?= h($doc['signed_name']) ?></strong> <?= h(__('on')) ?> <?= en(fmt_datetime($doc['signed_at'])) ?>.</p>
  <p><?= t('You can print or save this page for your records.') ?></p>
  <h2<?= $docLang ?>><?= h($doc['title']) ?></h2>
  <?php else: ?>
  <p><?= t('In this document, “we” means') ?> <?= h(brand('org')) ?> <?= t('and “you” means the person who signs.') ?></p>
  <p><?= $doc['signer_role'] === 'Guardian' ? t('You are signing as the parent or guardian.') : t('You are signing for yourself.') ?> <?= t('The name on this document is') ?> <strong><?= h($doc['signer_name']) ?></strong>.</p>
  <p><?= t('When you sign, you agree to the following:') ?></p>
  <?php endif; ?>
  <div<?= $docLang ?>>
  <?php foreach ($paras as $p): $lines = array_values(array_filter(array_map('trim', explode("\n", $p)), fn($l) => $l !== '')); ?>
    <?php if (count($lines) > 1): ?><ul><?php foreach ($lines as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ul>
    <?php else: ?><p><?= h($lines[0]) ?></p><?php endif; ?>
  <?php endforeach; ?>
  </div>
  <?php if (!$doc['signed_at']): ?>
  <p><?= t('If you have questions before signing, contact') ?> <?= $contactHtml ?>.</p>
  <?php $typedSig = $staleSig ?? (is_string($_SESSION[$sigKey] ?? null) ? $_SESSION[$sigKey] : ''); unset($_SESSION[$sigKey]); ?>
  <?php if ($staleSig !== null): ?>
  <div class="alert alert-error" id="form-errors" tabindex="-1"><span class="visually-hidden"><?= t('Error:') ?> </span><?= t('This page was open for a long time, so your signature was not sent yet. Your typed name is still in the box. Please check it and choose Sign and submit again.') ?></div>
  <script>document.addEventListener("DOMContentLoaded",function(){var e=document.getElementById("form-errors");if(e)e.focus();});</script>
  <?php endif; ?>
  <form method="post" action="sign.php?t=<?= h($token) ?>&amp;lang=<?= h(page_lang()) ?>" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary(['signature' => '']) ?>
    <div class="field">
      <label for="signature"><?= t('Electronic signature') ?> <span aria-hidden="true">*</span><span class="visually-hidden"> (<?= t('required') ?>)</span> <span class="hint"><?= t('Type your full name (') ?><?= h($doc['signer_name']) ?><?= t(') to sign') ?>. <?= t('Capital letters, accents and punctuation do not need to match.') ?></span></label>
      <input type="text" id="signature" name="signature" required autocomplete="name" value="<?= h($typedSig) ?>" <?= field_aria('signature') ?>>
      <?php if (has_field_error('signature')): ?><p class="field-error" id="err-signature"><?= t(form_errors()['signature']) ?> <?= t('To sign, type:') ?> <strong><?= h($doc['signer_name']) ?></strong></p><?php endif; ?>
    </div>
    <button type="submit" class="btn btn-primary"><?= t('Sign and submit') ?></button>
  </form>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php page_footer();
