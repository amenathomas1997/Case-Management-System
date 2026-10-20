<?php
// Public guardian consent form — tokenized link, typed-name e-signature.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
enable_public_translation();
// Copy used only on this page (its Spanish lives here, beside the English).
register_translations([
    'Consent recorded' => 'Consentimiento registrado',
    'Error — Consent to services' => 'Error — Consentimiento para servicios',
    'Your consent was recorded. Thank you.' => 'Su consentimiento quedó registrado. Gracias.',
    'In this form, “we” means' => 'En este formulario, “nosotros” significa',
    'and “you” means the parent or guardian who signs.' => 'y “usted” significa el padre, la madre o el tutor que firma.',
    'You are signing as the parent or guardian of' => 'Usted firma como padre, madre o tutor de',
    'The name on this form is' => 'El nombre en este formulario es',
    'When you sign, you agree that:' => 'Al firmar, usted acepta que:',
    "We may talk with the student's school." => 'Podemos hablar con la escuela del estudiante.',
    'If needed, we may also talk with the state agency that helps people with disabilities find work.' => 'Si es necesario, también podemos hablar con la agencia estatal que ayuda a las personas con discapacidades a encontrar trabajo.',
    'We share records only to plan services and to send reports the law requires.' => 'Compartimos expedientes solo para planificar servicios y para enviar los informes que exige la ley.',
    'We may contact you about scheduling and progress.' => 'Podemos comunicarnos con usted sobre las citas y el progreso.',
    'You can take back (withdraw) your consent at any time. To do that, contact' => 'Puede retirar su consentimiento en cualquier momento. Para hacerlo, comuníquese con',
    'Capital letters, accents and punctuation do not need to match.' => 'No importa si las mayúsculas, los acentos o la puntuación son diferentes.',
    'Type your full name to sign.' => 'Escriba su nombre completo para firmar.',
    'Type your full name as it appears on the form (120 characters maximum).' => 'Escriba su nombre completo como aparece en el formulario (120 caracteres como máximo).',
    'The name typed does not match the guardian name on this form.' => 'El nombre escrito no coincide con el nombre del padre, madre o tutor en este formulario.',
    'To sign, type:' => 'Para firmar, escriba:',
    'This page was open for a long time, so your signature was not sent yet. Your typed name is still in the box. Please check it and choose Sign and submit again.' => 'Esta página estuvo abierta por mucho tiempo, así que su firma todavía no se envió. El nombre que escribió sigue en el cuadro. Revíselo y elija Firmar y enviar otra vez.',
    'Consent withdrawn' => 'Consentimiento retirado',
    'This consent was withdrawn on' => 'Este consentimiento se retiró el',
    'It had been signed by' => 'Lo había firmado',
    'If you received a newer consent request, please use the link in that email. Otherwise, contact' => 'Si recibió una solicitud de consentimiento más reciente, use el enlace de ese correo. Si no, comuníquese con',
]);
// A public visitor has no account to sign back into. When the form's token no
// longer matches (the session lapsed while the page sat open), nothing is
// signed and this page is shown again at once with the typed name in place.
// (csrf_check()'s own handling refills through keep_input(), which never keeps
// a field named like "signature", so the name would be lost.)
$csrfStale = $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SESSION['user_id'])
    && !(is_string($_POST['csrf'] ?? null) && hash_equals(csrf_token(), $_POST['csrf']));
if (!$csrfStale) csrf_check();

$rawToken = $_GET['t'] ?? '';
$token = is_string($rawToken) ? preg_replace('/[^a-f0-9]/', '', $rawToken) : '';
$form = null;
if (strlen($token) === 48) {
    // Unsigned links expire after 60 days; signed ones stay viewable as a receipt.
    // Nothing is greeted by name or written into a retention-archived record, as on
    // the portal and the survey: its links all read as not recognized.
    $stmt = db()->prepare('SELECT f.*, c.name AS client_name FROM consent_forms f JOIN clients c ON c.id = f.client_id
        WHERE f.token = ? AND (f.signed_at IS NOT NULL OR f.sent_at > DATE_SUB(NOW(), INTERVAL ' . cms_number('sign_link_days') . ' DAY)) AND c.archived_at IS NULL');
    $stmt->execute([$token]);
    $form = $stmt->fetch();
}

if (!$form) {
    page_header('Consent form');
    // The language switch is offered here too, as on the portal: a fresh browser
    // starts in English whatever language the guardian reads.
    echo lang_toggle();
    $linkContact = (string)(cfg('support_email') ?: cfg('mail_from'));
    $phoneContact = support_contact_html(); // "Call {phone} or email {email}" once a support phone is set
    echo '<div class="card auth-card"><h1>' . t('Link not recognized') . '</h1><p>'
        . ($phoneContact !== ''
            ? t('This consent link is invalid or has been replaced.') . ' ' . $phoneContact . ' ' . t('for a new one.')
            : t('This consent link is invalid or has been replaced. Please contact') . ' '
            . ($linkContact !== '' ? h(brand('short')) . ' (<a href="mailto:' . h($linkContact) . '">' . h($linkContact) . '</a>)' : h(brand('short'))) . ' ' . t('for a new one.'))
        . '</p></div>';
    page_footer();
    exit;
}

/** A typed name compared the way a person hears and types it: case, accents,
 *  spaces and punctuation are ignored (a screen reader at default punctuation
 *  does not speak the period in "Mary J. Smith" or the apostrophe in "O'Brien",
 *  and a US keyboard types "Jose" for "José"), so only the letters must match. */
function consent_name_key(string $s): string {
    return typed_name_key($s); // inc/records_privacy.php, shared with release.php
}

// keep_input() never keeps a field named like "signature" (its credential filter),
// so a rejected typed name is refilled from its own session key instead.
$sigKey = 'consent_sig_' . (int)$form['id'];
$staleSig = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$form['signed_at']) {
    $signature = trim(is_string($_POST['signature'] ?? null) ? $_POST['signature'] : '');
    if ($csrfStale) {
        // Nothing is signed; the same page comes back with the name still typed.
        $staleSig = mb_check_encoding($signature, 'UTF-8') ? $signature : '';
    } else {
        $want = consent_name_key((string)$form['guardian_name']);
        // The stored signature holds 120 characters, the same as the name on the form;
        // a longer one (padded with spaces or punctuation the match ignores) is refused.
        $tooLong = mb_check_encoding($signature, 'UTF-8') && mb_strlen($signature) > 120;
        $matches = !$tooLong && mb_check_encoding($signature, 'UTF-8') && $signature !== ''
            && ($want !== '' ? consent_name_key($signature) === $want
                : mb_convert_case($signature, MB_CASE_FOLD, 'UTF-8') === mb_convert_case(trim((string)$form['guardian_name']), MB_CASE_FOLD, 'UTF-8'));
        if (!$matches) {
            $_SESSION[$sigKey] = mb_check_encoding($signature, 'UTF-8') ? $signature : '';
            // On the field (and translated), not a flash. The name to type is added
            // beside the message where it is shown (record data never goes through t()).
            keep_field_errors(['signature' => $signature === '' ? 'Type your full name to sign.'
                : ($tooLong ? 'Type your full name as it appears on the form (120 characters maximum).' : 'The name typed does not match the guardian name on this form.')]);
            redirect('consent.php?t=' . $token);
        }
        unset($_SESSION[$sigKey]);
        db()->beginTransaction();
        try {
            // The signature and its case note must commit together.
            $sign = db()->prepare('UPDATE consent_forms SET signed_at = NOW(), signed_name = ?, sign_ip = ? WHERE id = ? AND signed_at IS NULL');
            $sign->execute([$signature, client_ip(), $form['id']]);
            if ($sign->rowCount() === 1) {
                db()->prepare('INSERT INTO client_notes (client_id, note_type, subject, body, author_id) VALUES (?,?,?,?,NULL)')
                    ->execute([(int)$form['client_id'], 'Case Note', 'Guardian consent signed',
                        "Consent to services electronically signed by {$signature} ({$form['guardian_email']}) on " . date('F j, Y g:i A') . '.']);
            }
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
        if ($sign->rowCount() === 1) {
            notify_admin_users("Guardian consent signed for {$form['client_name']}", 'client.php?id=' . (int)$form['client_id']);
            // Announced once on the next page (focused, with an earcon), as on the portal.
            flash_ui('Your consent was recorded. Thank you.');
        }
        redirect('consent.php?t=' . $token);
    }
}

// The title names the result: a withdrawn consent, a receipt, a form with an error,
// or the form. A withdrawn one never says that nothing more is needed: staff may
// have sent a newer request to sign.
$withdrawn = $form['signed_at'] && !empty($form['withdrawn_at']);
// The contact the footer gives, for withdrawing consent or asking questions.
$contact = (string)(cfg('support_email') ?: cfg('mail_from'));
$contactHtml = $contact !== '' ? h(brand('short')) . ' (<a href="mailto:' . h($contact) . '">' . h($contact) . '</a>)' : h(brand('short'));
// With a support phone set: "contact {org}: call {phone} or email {email}".
if (support_contact_html(false) !== '') $contactHtml = h(brand('short')) . ': ' . support_contact_html(false);
// The services asked for on this student's referral, when the record has them: named
// here rather than assuming the guardian was told about them in a conversation.
$services = [];
if (!$form['signed_at']) {
    try {
        $sq = db()->prepare("SELECT requested_service FROM referrals WHERE client_id = ? AND requested_service IS NOT NULL AND requested_service <> '' ORDER BY created_at DESC, id DESC LIMIT 1");
        $sq->execute([(int)$form['client_id']]);
        $services = array_values(array_filter(array_map('trim', explode('; ', (string)$sq->fetchColumn())), fn($x) => $x !== '' && $x !== 'Not sure'));
    } catch (Throwable $e) { $services = []; }
    // An administrator's Spanish for each service label, as on the referral form.
    if ($services) register_translations(referral_form_translations());
}
// A stored service label: translated when the dictionary has the whole label, else kept in English and marked so.
$whole = fn(string $x): string => isset(translations()[$x]) ? h(__($x)) : en($x);
page_header($withdrawn ? 'Consent withdrawn' : ($form['signed_at'] ? 'Consent recorded' : (form_errors() || $staleSig !== null ? 'Error — Consent to services' : 'Consent to services')));
echo lang_toggle();
?>
<div class="card" style="max-width: 40rem; margin: 2rem auto;">
<?php if ($withdrawn): ?>
  <h1><?= t('Consent withdrawn') ?></h1>
  <p><?= t('This consent was withdrawn on') ?> <?= en(fmt_date($form['withdrawn_at'])) ?>. <?= t('It had been signed by') ?> <strong><?= h($form['signed_name']) ?></strong> <?= h(__('on')) ?> <?= en(fmt_date($form['signed_at'])) ?>.</p>
  <p><?= t('If you received a newer consent request, please use the link in that email. Otherwise, contact') ?> <?= $contactHtml ?>.</p>
<?php elseif ($form['signed_at']): ?>
  <h1><?= t('Consent recorded — thank you') ?></h1>
  <p><?= t('Signed by') ?> <strong><?= h($form['signed_name']) ?></strong> <?= h(__('on')) ?> <?= en(fmt_datetime($form['signed_at'])) ?><?= t('. A record has been placed in') ?> <?= h($form['client_name']) ?><?= t('\'s file. No further action is needed.') ?></p>
  <p><?= t('You can print or save this page for your records.') ?></p>
  <p><?= t('You can take back (withdraw) your consent at any time. To do that, contact') ?> <?= $contactHtml ?>.</p>
<?php else: ?>
  <h1><?= t('Consent for services —') ?> <?= h($form['client_name']) ?></h1>
  <p><?= h(brand('org')) ?> <?= wording_html('consent_about') // Public wording (4.3.0) ?></p>
  <?php // Short sentences, one point of view: "we" is the organization, "you" the guardian. ?>
  <p><?= t('In this form, “we” means') ?> <?= h(brand('short')) ?> <?= t('and “you” means the parent or guardian who signs.') ?></p>
  <p><?= t('You are signing as the parent or guardian of') ?> <strong><?= h($form['client_name']) ?></strong>. <?= t('The name on this form is') ?> <strong><?= h($form['guardian_name']) ?></strong>.</p>
  <p><?= t('When you sign, you agree that:') ?></p>
  <ul>
    <?php if ($services): ?>
    <li><?= t('We may give this student the services requested for them:') ?>
      <ul><?php foreach ($services as $svc): ?><li><?= $whole($svc) ?></li><?php endforeach; ?></ul></li>
    <?php else: ?>
    <li><?= t('We may give this student the services described at the top of this page. We will explain each one before it starts.') ?></li>
    <?php endif; ?>
    <?php foreach (wording_lines_html('consent_points') as $point): ?><li><?= $point ?></li><?php endforeach; ?>
  </ul>
  <p><?= t('You can take back (withdraw) your consent at any time. To do that, contact') ?> <?= $contactHtml ?>.</p>
  <?php $typedSig = $staleSig ?? (is_string($_SESSION[$sigKey] ?? null) ? $_SESSION[$sigKey] : ''); unset($_SESSION[$sigKey]); ?>
  <?php if ($staleSig !== null): // Announced once, by moving focus to it (no alert role). ?>
  <div class="alert alert-error" id="form-errors" tabindex="-1"><span class="visually-hidden"><?= t('Error:') ?> </span><?= t('This page was open for a long time, so your signature was not sent yet. Your typed name is still in the box. Please check it and choose Sign and submit again.') ?></div>
  <script>(window.cmsFocusAfterLoad||function(f){document.addEventListener("DOMContentLoaded",f);})(function(){var e=document.getElementById("form-errors");if(e)e.focus();});</script>
  <?php endif; ?>
  <?php // The page language rides the form, so the reply keeps it even if the session lapsed. ?>
  <form method="post" action="consent.php?t=<?= h($token) ?>&amp;lang=<?= h(page_lang()) ?>" class="stacked" novalidate>
    <?= csrf_field() ?>
    <?= form_error_summary(['signature' => '']) ?>
    <p><?= t('Fields marked with an asterisk (') ?><span aria-hidden="true">*</span><span class="visually-hidden"><?= t('asterisk') ?></span><?= t(') are required.') ?></p>
    <div class="field">
      <label for="signature"><?= t('Electronic signature') ?> <?= required_mark() ?> <span class="hint"><?= t('Type your full name (') ?><?= h($form['guardian_name']) ?><?= t(') to sign') ?>. <?= t('Capital letters, accents and punctuation do not need to match.') ?></span></label>
      <input type="text" id="signature" name="signature" required autocomplete="name" value="<?= h($typedSig) ?>" <?= field_aria('signature') ?>>
      <?php // The field error, with the exact name to type added after the message. ?>
      <?php if (has_field_error('signature')): ?><p class="field-error" id="err-signature"><?= t(form_errors()['signature']) ?> <?= t('To sign, type:') ?> <strong><?= h($form['guardian_name']) ?></strong></p><?php endif; ?>
    </div>
    <button type="submit" class="btn btn-primary"><?= t('Sign and submit') ?></button>
  </form>
<?php endif; ?>
</div>
<?php page_footer();
