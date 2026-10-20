<?php
// Public release of information form (3.0.36) — tokenized link, typed-name e-signature.
// An adult client (or a guardian) agrees that one named recipient may receive the
// information listed, for the purpose given, for 1 year. Built like consent.php.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
enable_public_translation();
// Copy used only on this page (its Spanish lives here, beside the English).
register_translations([
    'Release of information' => 'Autorización para compartir información',
    'Error — Release of information' => 'Error — Autorización para compartir información',
    'Release signed' => 'Autorización firmada',
    'Release withdrawn' => 'Autorización retirada',
    'Release signed — thank you' => 'Autorización firmada — gracias',
    'Your release was recorded. Thank you.' => 'Su autorización quedó registrada. Gracias.',
    'Release of information for' => 'Autorización para compartir información de',
    'In this form, “we” means' => 'En este formulario, “nosotros” significa',
    'and “you” means the person who signs.' => 'y “usted” significa la persona que firma.',
    'You are signing for yourself.' => 'Usted firma por sí mismo/a.',
    'You are signing as the parent or guardian.' => 'Usted firma como padre, madre o tutor.',
    'The name on this form is' => 'El nombre en este formulario es',
    'When you sign, you allow us to share the information below with this recipient:' => 'Al firmar, usted nos permite compartir la información de abajo con este destinatario:',
    'Recipient' => 'Destinatario',
    'Information we may share' => 'Información que podemos compartir',
    'Why it is shared' => 'Por qué se comparte',
    'How long this release lasts' => 'Cuánto dura esta autorización',
    'One year from the day you sign, unless you withdraw it sooner.' => 'Un año desde el día en que firme, a menos que la retire antes.',
    'Valid until' => 'Válida hasta',
    'You do not have to sign this release to keep getting services from us.' => 'No tiene que firmar esta autorización para seguir recibiendo nuestros servicios.',
    'You can take back (withdraw) this release at any time. To do that, contact' => 'Puede retirar esta autorización en cualquier momento. Para hacerlo, comuníquese con',
    'Information already shared before you withdraw it cannot be taken back.' => 'La información que ya se compartió antes de que la retire no se puede recuperar.',
    'This release was withdrawn on' => 'Esta autorización se retiró el',
    'If you received a newer release form, please use the link in that email. Otherwise, contact' => 'Si recibió un formulario de autorización más reciente, use el enlace de ese correo. Si no, comuníquese con',
    'This release link is invalid or has been replaced. Please contact' => 'Este enlace de autorización no es válido o fue reemplazado. Comuníquese con',
    'This release link is invalid or has been replaced.' => 'Este enlace de autorización no es válido o fue reemplazado.',
    'Capital letters, accents and punctuation do not need to match.' => 'No importa si las mayúsculas, los acentos o la puntuación son diferentes.',
    'Type your full name to sign.' => 'Escriba su nombre completo para firmar.',
    'Type your full name as it appears on the form (120 characters maximum).' => 'Escriba su nombre completo como aparece en el formulario (120 caracteres como máximo).',
    'The name typed does not match the name on this form.' => 'El nombre escrito no coincide con el nombre en este formulario.',
    'To sign, type:' => 'Para firmar, escriba:',
    'This page was open for a long time, so your signature was not sent yet. Your typed name is still in the box. Please check it and choose Sign and submit again.' => 'Esta página estuvo abierta por mucho tiempo, así que su firma todavía no se envió. El nombre que escribió sigue en el cuadro. Revíselo y elija Firmar y enviar otra vez.',
    'VR counselor and agency' => 'Consejero/a de rehabilitación vocacional y su agencia',
    'Employer' => 'Empleador',
    'Doctor or clinic' => 'Médico o clínica',
    'School' => 'Escuela',
    'Family member' => 'Familiar',
    'Progress reports and attendance' => 'Informes de progreso y asistencia',
    'Assessments and recommendations' => 'Evaluaciones y recomendaciones',
    'Equipment and device information' => 'Información sobre equipos y dispositivos',
    'Vision and health information' => 'Información sobre la visión y la salud',
    'Employment information' => 'Información sobre el empleo',
]);
// As on consent.php: a lapsed session token signs nothing and keeps the typed name.
$csrfStale = $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SESSION['user_id'])
    && !(is_string($_POST['csrf'] ?? null) && hash_equals(csrf_token(), $_POST['csrf']));
if (!$csrfStale) csrf_check();

$rawToken = $_GET['t'] ?? '';
$token = is_string($rawToken) ? preg_replace('/[^a-f0-9]/', '', $rawToken) : '';
$rel = null;
if (strlen($token) === 48) {
    // Unsigned links expire after 60 days; signed ones stay viewable as a receipt.
    try {
        $stmt = db()->prepare('SELECT r.*, c.name AS client_name FROM client_releases r JOIN clients c ON c.id = r.client_id
            WHERE r.token = ? AND (r.signed_at IS NOT NULL OR r.sent_at > DATE_SUB(NOW(), INTERVAL ' . cms_number('sign_link_days') . ' DAY)) AND c.archived_at IS NULL');
        $stmt->execute([$token]);
        $rel = $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        $rel = null; // mig_3.0.36 not run yet
    }
}
$contact = (string)(cfg('support_email') ?: cfg('mail_from'));
$contactHtml = $contact !== '' ? h(brand('short')) . ' (<a href="mailto:' . h($contact) . '">' . h($contact) . '</a>)' : h(brand('short'));
if (support_contact_html(false) !== '') $contactHtml = h(brand('short')) . ': ' . support_contact_html(false);

if (!$rel) {
    page_header('Release of information');
    echo lang_toggle();
    $phoneContact = support_contact_html();
    echo '<div class="card auth-card"><h1>' . t('Link not recognized') . '</h1><p>'
        . ($phoneContact !== ''
            ? t('This release link is invalid or has been replaced.') . ' ' . $phoneContact . ' ' . t('for a new one.')
            : t('This release link is invalid or has been replaced. Please contact') . ' ' . $contactHtml . ' ' . t('for a new one.'))
        . '</p></div>';
    page_footer();
    exit;
}

$sigKey = 'release_sig_' . (int)$rel['id'];
$staleSig = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$rel['signed_at'] && empty($rel['withdrawn_at'])) {
    $signature = trim(is_string($_POST['signature'] ?? null) ? $_POST['signature'] : '');
    if ($csrfStale) {
        $staleSig = mb_check_encoding($signature, 'UTF-8') ? $signature : '';
    } else {
        $want = typed_name_key((string)$rel['signer_name']);
        $tooLong = mb_check_encoding($signature, 'UTF-8') && mb_strlen($signature) > 120;
        $matches = !$tooLong && mb_check_encoding($signature, 'UTF-8') && $signature !== ''
            && ($want !== '' ? typed_name_key($signature) === $want
                : mb_convert_case($signature, MB_CASE_FOLD, 'UTF-8') === mb_convert_case(trim((string)$rel['signer_name']), MB_CASE_FOLD, 'UTF-8'));
        if (!$matches) {
            $_SESSION[$sigKey] = mb_check_encoding($signature, 'UTF-8') ? $signature : '';
            keep_field_errors(['signature' => $signature === '' ? 'Type your full name to sign.'
                : ($tooLong ? 'Type your full name as it appears on the form (120 characters maximum).' : 'The name typed does not match the name on this form.')]);
            redirect('release.php?t=' . $token);
        }
        unset($_SESSION[$sigKey]);
        if (release_sign($rel, $signature)) {
            notify_admin_users('Release of information signed for ' . $rel['client_name'], 'client.php?id=' . (int)$rel['client_id'] . '#releases-h');
            flash_ui('Your release was recorded. Thank you.');
        }
        redirect('release.php?t=' . $token);
    }
}

$withdrawn = !empty($rel['withdrawn_at']);
// Record data is never put through t(); a list label is translated only when the dictionary has it whole.
$whole = fn(string $x): string => isset(translations()[$x]) ? h(__($x)) : en($x);
page_header($withdrawn ? 'Release withdrawn' : ($rel['signed_at'] ? 'Release signed' : (form_errors() || $staleSig !== null ? 'Error — Release of information' : 'Release of information')));
echo lang_toggle();
?>
<div class="card" style="max-width: 40rem; margin: 2rem auto;">
<?php if ($withdrawn): ?>
  <h1><?= t('Release withdrawn') ?></h1>
  <p><?= t('This release was withdrawn on') ?> <?= en(fmt_date($rel['withdrawn_at'])) ?>.</p>
  <p><?= t('If you received a newer release form, please use the link in that email. Otherwise, contact') ?> <?= $contactHtml ?>.</p>
<?php else: ?>
  <h1><?= $rel['signed_at'] ? t('Release signed — thank you') : t('Release of information for') . ' ' . h($rel['client_name']) ?></h1>
  <?php if ($rel['signed_at']): ?>
  <p><?= t('Signed by') ?> <strong><?= h($rel['signed_name']) ?></strong> <?= h(__('on')) ?> <?= en(fmt_datetime($rel['signed_at'])) ?>.</p>
  <p><?= t('You can print or save this page for your records.') ?></p>
  <?php else: ?>
  <p><?= t('In this form, “we” means') ?> <?= h(brand('org')) ?> <?= t('and “you” means the person who signs.') ?></p>
  <p><?= $rel['signer_role'] === 'Guardian' ? t('You are signing as the parent or guardian.') : t('You are signing for yourself.') ?> <?= t('The name on this form is') ?> <strong><?= h($rel['signer_name']) ?></strong>.</p>
  <p><?= t('When you sign, you allow us to share the information below with this recipient:') ?></p>
  <?php endif; ?>
  <dl class="props">
    <dt><?= t('Recipient') ?></dt><dd><?= h($rel['recipient_name']) ?> (<?= $whole($rel['recipient_type']) ?>)</dd>
    <dt><?= t('Information we may share') ?></dt><dd><ul><?php foreach (explode('; ', (string)$rel['info_scope']) as $item): ?><li><?= $whole($item) ?></li><?php endforeach; ?></ul></dd>
    <dt><?= t('Why it is shared') ?></dt><dd><?= en((string)$rel['purpose']) ?></dd>
    <?php if ($rel['signed_at']): ?>
    <dt><?= t('Valid until') ?></dt><dd><?= en(fmt_date($rel['expires_on'])) ?></dd>
    <?php else: ?>
    <dt><?= t('How long this release lasts') ?></dt><dd><?= t('One year from the day you sign, unless you withdraw it sooner.') ?></dd>
    <?php endif; ?>
  </dl>
  <p><?= t('You do not have to sign this release to keep getting services from us.') ?></p>
  <p><?= t('You can take back (withdraw) this release at any time. To do that, contact') ?> <?= $contactHtml ?>. <?= t('Information already shared before you withdraw it cannot be taken back.') ?></p>
  <?php if (!$rel['signed_at']): ?>
  <?php $typedSig = $staleSig ?? (is_string($_SESSION[$sigKey] ?? null) ? $_SESSION[$sigKey] : ''); unset($_SESSION[$sigKey]); ?>
  <?php if ($staleSig !== null): ?>
  <div class="alert alert-error" id="form-errors" tabindex="-1"><span class="visually-hidden"><?= t('Error:') ?> </span><?= t('This page was open for a long time, so your signature was not sent yet. Your typed name is still in the box. Please check it and choose Sign and submit again.') ?></div>
  <script>(window.cmsFocusAfterLoad||function(f){document.addEventListener("DOMContentLoaded",f);})(function(){var e=document.getElementById("form-errors");if(e)e.focus();});</script>
  <?php endif; ?>
  <form method="post" action="release.php?t=<?= h($token) ?>&amp;lang=<?= h(page_lang()) ?>" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary(['signature' => '']) ?>
    <p><?= t('Fields marked with an asterisk (') ?><span aria-hidden="true">*</span><span class="visually-hidden"><?= t('asterisk') ?></span><?= t(') are required.') ?></p>
    <div class="field">
      <label for="signature"><?= t('Electronic signature') ?> <?= required_mark() ?> <span class="hint"><?= t('Type your full name (') ?><?= h($rel['signer_name']) ?><?= t(') to sign') ?>. <?= t('Capital letters, accents and punctuation do not need to match.') ?></span></label>
      <input type="text" id="signature" name="signature" required autocomplete="name" value="<?= h($typedSig) ?>" <?= field_aria('signature') ?>>
      <?php if (has_field_error('signature')): ?><p class="field-error" id="err-signature"><?= t(form_errors()['signature']) ?> <?= t('To sign, type:') ?> <strong><?= h($rel['signer_name']) ?></strong></p><?php endif; ?>
    </div>
    <button type="submit" class="btn btn-primary"><?= t('Sign and submit') ?></button>
  </form>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php page_footer();
