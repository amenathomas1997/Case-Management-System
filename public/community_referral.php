<?php
// Public assistive-technology referral form for all referral sources.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
enable_public_translation();
register_translations(referral_form_translations());
// Copy used only on this page ('Email' alone is the field label, a noun).
register_translations(['Email us at' => 'Escríbanos a', 'required: choose at least one' => 'obligatorio: elija al menos uno',
    'The date of birth cannot be in the future. Please check the year.' => 'La fecha de nacimiento no puede ser una fecha futura. Revise el año.',
    'Enter the date of birth as a valid date, from the year 1900 onwards.' => 'Escriba la fecha de nacimiento como una fecha válida, a partir del año 1900.',
    'Enter one valid email address for the person, for example name@example.org, or leave it blank.' => 'Escriba un solo correo electrónico válido de la persona, por ejemplo nombre@ejemplo.org, o déjelo en blanco.',
    'Please also correct the following:' => 'Corrija también lo siguiente:',
    'The referral could not be sent because of a problem on our side, and nothing was saved. Everything you typed is still here. Please try again in a few minutes, or email us.'
        => 'No se pudo enviar la referencia por un problema de nuestra parte, y no se guardó nada. Todo lo que escribió sigue aquí. Inténtelo de nuevo en unos minutos o escríbanos.']);
// A public visitor has no account to sign back into. When the form's token no
// longer matches (the session lapsed while the page sat open), show the form
// again with everything typed still in place — nothing is saved — instead of
// sending them to the staff sign-in page, which is what csrf_check() does.
$csrfStale = $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SESSION['user_id'])
    && !(is_string($_POST['csrf'] ?? null) && hash_equals(csrf_token(), $_POST['csrf']));
if (!$csrfStale) csrf_check();

// Referral alerts (in-app and email) sent per hour across all senders; see the save below.
const REFERRAL_ALERTS_PER_HOUR = 30;
const INCOME_BRACKETS = [
    'below_2000' => 'Below $2,000 per month',
    'above_2000' => '$2,000 per month or more',
];
// Preserve the multi-select and structured fields for explicit validation; drop other arrays so a
// crafted request (e.g. role[]=x) can't cause type errors.
foreach ($_POST as $k => $v) {
    if (!is_string($v) && !in_array($k, ['preets', 'specific_needs', 'referral_purpose', 'requested_services'], true)) unset($_POST[$k]);
}

// Only a form from this release posts the structured fields; an older form still
// open in a browser posts note_type and is accepted as a legacy referral.
$structured = array_key_exists('referral_purpose', $_POST) || array_key_exists('requested_services', $_POST);
$request = referral_request_input($_POST);
$errors = [];
$old = fn(string $k) => h(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
// Administrator-authored options and hints are rendered whole: translated only
// when the administrator (or the dictionary) gives the entire string, otherwise
// kept in English and marked so, never split into mixed-language fragments.
// A translation spelled the same as the English is still Spanish, so the dictionary
// entry decides, not a comparison of the two strings.
$whole = fn(string $x): string => isset(translations()[$x]) ? h(__($x)) : en($x);
// By-reference captures: $errors is filled AFTER these helpers are defined.
$err = function (string $k) use (&$errors): string {
    return isset($errors[$k]) ? '<p class="field-error" id="err-' . $k . '">' . t($errors[$k]) . '</p>' : '';
};
$aria = function (string $k, string $describe = '') use (&$errors): string {
    $ids = [];
    if (isset($errors[$k])) $ids[] = 'err-' . $k;
    if ($describe !== '') $ids[] = $describe;
    if (!$ids) return '';
    return (isset($errors[$k]) ? 'aria-invalid="true" ' : '') . 'aria-describedby="' . implode(' ', $ids) . '"';
};
$role = $_POST['role'] ?? (is_string($_GET['role'] ?? '') ? ($_GET['role'] ?? '') : '');
// One form, one referral: each form carries a key, remembered in this visitor's
// session (with what was sent) once its referral is saved. A double click, or
// the same page sent again, then gets the same confirmation instead of filing a
// second referral; requests in one session run one at a time, so a second
// click waits for the first. A page edited and sent again is a new referral.
$formKey = is_string($_POST['form_key'] ?? null) && preg_match('/^[0-9a-f]{32}$/D', $_POST['form_key']) ? $_POST['form_key'] : bin2hex(random_bytes(16));
$formSig = hash('sha256', json_encode(array_diff_key($_POST, ['csrf' => 1, 'csrf_uid' => 1, 'form_key' => 1])) ?: '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['website'] ?? '') !== '') redirect('community_referral.php?submitted=1');
    $sent = $_SESSION['referral_form_keys'][$formKey] ?? null;
    if (is_array($sent) && hash_equals((string)($sent['sig'] ?? ''), $formSig)) redirect('community_referral.php?submitted=1' . (string)($sent['done'] ?? ''));
    if ($csrfStale) $errors['form'] = 'This page was open for a long time, so the referral was not sent yet. Everything you typed is still here. Please review it and choose Send referral again.';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    // One IPv6 connection can rotate freely within its /64 network, so that
    // network is the unit counted (an IPv4-mapped address stays whole).
    $ipBin = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? inet_pton($ip) : false;
    if (is_string($ipBin) && strlen($ipBin) === 16 && substr($ipBin, 0, 12) !== str_repeat("\0", 10) . "\xff\xff") {
        $ip = inet_ntop(substr($ipBin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
    $rl = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = 'referral-portal' AND ip = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $rl->execute([$ip]);
    if ((int)$rl->fetchColumn() >= 5) {
        $errors['form'] = 'Too many submissions from this connection. Please try again later or contact us directly.';
    }

    if (!isset(REFERRER_ROLES[$role]) || !in_array($role, referral_form_config()['roles'], true)) $errors['role'] = 'Please choose which best describes you.';
    // The page script only hides the sections that do not apply to the chosen
    // role, so their inputs still post. Drop them here: answers typed under
    // another role (a relative's date of birth and address, a guardian, a
    // consent tick) must never be checked or stored with this referral.
    $roleFields = [
        'client_name' => ['counselor', 'school', 'medical', 'family', 'agency', 'other'],
        'client_dob' => ['counselor', 'school', 'medical', 'family', 'agency', 'other'],
        'client_phone' => ['counselor', 'school', 'medical', 'family', 'agency', 'other'],
        'client_email' => ['counselor', 'school', 'medical', 'family', 'agency', 'other'],
        'client_address' => ['counselor', 'school', 'medical', 'family', 'agency', 'other'],
        'organization' => ['counselor', 'school', 'medical', 'agency', 'other'],
        'grade_level' => ['school'], 'guardian_name' => ['school'], 'guardian_phone' => ['school'], 'preets' => ['school'],
        'relationship' => ['family'], 'contact_first' => ['family'],
        'consent' => ['medical', 'family', 'agency', 'other'],
    ];
    if (isset(REFERRER_ROLES[$role])) {
        foreach ($roleFields as $k => $roles) if (!in_array($role, $roles, true)) unset($_POST[$k]);
    }

    $required = ['referrer_name' => 'Your name', 'referrer_phone' => 'Your phone'];
    if ($role !== 'self') $required['client_name'] = "The person's name";
    foreach ($required as $k => $label) {
        if (trim($_POST[$k] ?? '') === '') $errors[$k] = $label . ' is required.';
    }
    // Written for this question, so the message matches the visible prompt.
    if (trim($_POST['body'] ?? '') === '') $errors['body'] = 'Please tell us what help is needed.';
    $refEmail = trim($_POST['referrer_email'] ?? '');
    if ($refEmail === '' || !filter_var($refEmail, FILTER_VALIDATE_EMAIL)) $errors['referrer_email'] = 'A valid email for you is required.';
    // Bad dates or over-length names would otherwise crash the insert and lose the referral.
    // Same bounds as a staff edit: a mistyped year would reach RSA-911 as an age.
    $dobIn = trim($_POST['client_dob'] ?? '');
    if ($dobIn !== '' && !valid_date($dobIn, 1900, (int)date('Y'))) {
        $errors['client_dob'] = 'Enter the date of birth as a valid date, from the year 1900 onwards.';
    } elseif ($dobIn !== '' && $dobIn > date('Y-m-d')) {
        $errors['client_dob'] = 'The date of birth cannot be in the future. Please check the year.';
    }
    // The person's email becomes the client's and receives session reminders,
    // so it must be one real address (never a list of several).
    $clientEmailIn = trim($_POST['client_email'] ?? '');
    if ($clientEmailIn !== '' && mb_strlen($clientEmailIn) <= 190 && !filter_var($clientEmailIn, FILTER_VALIDATE_EMAIL)) {
        $errors['client_email'] = 'Enter one valid email address for the person, for example name@example.org, or leave it blank.';
    }
    // Every free-text field is checked against its column size in the referrals
    // table, so an over-long value gets a message on that field.
    $limits = [
        'referrer_name' => [120, 'Your name'], 'client_name' => [120, "The person's name"],
        'referrer_email' => [190, 'Your email'], 'referrer_phone' => [40, 'Your phone'],
        'organization' => [190, 'The organization name'], 'relationship' => [80, 'Your relationship to the person'],
        'client_phone' => [40, 'Their phone'], 'client_email' => [190, 'Their email'], 'client_address' => [255, 'Their address'],
        'grade_level' => [40, 'Grade level'], 'guardian_name' => [120, 'Parent / guardian name'], 'guardian_phone' => [40, 'Parent / guardian phone'],
        'body' => [8000, 'The description of the need'],
    ];
    foreach ($limits as $k => [$max, $label]) {
        if (mb_strlen(trim($_POST[$k] ?? '')) > $max) $errors[$k] = $label . ' is too long (' . number_format($max) . ' characters maximum).';
    }

    $organization = trim($_POST['organization'] ?? '');
    $consent = !empty($_POST['consent']);
    $income = $_POST['income'] ?? '';

    if ($role === 'counselor') {
        if ($organization === '') $errors['organization'] = 'Agency / district is required.';
    } elseif ($role === 'self') {
        if (!isset(INCOME_BRACKETS[$income])) $errors['income'] = 'Please choose your monthly income range.';
    } elseif ($role === 'school') {
        if ($organization === '') $errors['organization'] = 'School / district is required.';
        if (trim($_POST['guardian_name'] ?? '') === '') $errors['guardian_name'] = "Parent/guardian name is required for student referrals.";
        if (trim($_POST['guardian_phone'] ?? '') === '') $errors['guardian_phone'] = 'Parent/guardian phone is required for student referrals.';
    } elseif ($role === 'medical') {
        if ($organization === '') $errors['organization'] = 'Practice / clinic name is required.';
        if (!$consent) $errors['consent'] = 'Please confirm the patient has agreed to be contacted.';
    } elseif ($role === 'family') {
        if (trim($_POST['relationship'] ?? '') === '') $errors['relationship'] = 'Your relationship to the person is required.';
        if (!$consent) $errors['consent'] = 'Please confirm the person knows about this referral.';
        if (!in_array($_POST['contact_first'] ?? '', ['client','referrer'], true)) $errors['contact_first'] = 'Tell us who we should contact first.';
    } elseif ($role === 'agency' || $role === 'other') {
        if ($role === 'agency' && $organization === '') $errors['organization'] = 'Agency / organization name is required.';
        if (!$consent) $errors['consent'] = 'Please confirm the person is aware of this referral.';
    }

    if ($structured) $errors = array_merge($errors, $request['errors']);
    // Refuse retired services from stale forms as well as crafted submissions.
    // Do not silently file a request for a service the business does not offer.
    if (!empty($_POST['preets']) || (!$structured && isset($_POST['note_type']) && !in_array($_POST['note_type'], REFERRAL_AT_NOTE_TYPES, true))) {
        $errors['form'] = 'This form accepts assistive technology referrals only. Please review the current service choices and submit again.';
    }
    if (!$errors) {
        // A form opened before this release still posts its original note_type.
        $noteType = $structured ? referral_request_note_type($request['purpose'], $request['service']) : match (true) {
            $role === 'counselor' && in_array($_POST['note_type'] ?? '', REFERRAL_AT_NOTE_TYPES, true) => $_POST['note_type'],
            default => 'Initial Intake',
        };
        $requestFields = [
            'referral_purpose' => $structured ? $request['purpose'] : ($role === 'school' ? 'New referral' : null),
            'requested_service' => $structured && $request['service'] !== '' ? $request['service'] : null,
            'specific_needs' => $structured && $request['needs'] ? implode('; ', $request['needs']) : null,
        ];
        $orgLabel = match ($role) {
            'counselor' => $organization,
            'self' => 'Self-referral',
            'school' => $organization,
            'medical' => $organization,
            'family' => 'Family/caregiver — ' . trim($_POST['relationship'] ?? ''),
            'agency' => $organization,
            default => $organization !== '' ? $organization : 'Employer/other',
        };
        $fee = null;
        if ($role === 'self') $fee = $income === 'below_2000' ? 0.00 : 17.50;
        $clientName = $role === 'self' ? trim($_POST['referrer_name']) : trim($_POST['client_name']);
        $clientPhone = $role === 'self' ? trim($_POST['referrer_phone']) : (trim($_POST['client_phone'] ?? '') ?: null);
        $clientEmail = $role === 'self' ? strtolower($refEmail) : (trim($_POST['client_email'] ?? '') ?: null);
        // The referral, its contact task and the rate-limit count are saved
        // together or not at all: the page never confirms a referral nobody is
        // asked to follow up.
        $refSaved = false;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO referrals (reason, note_type, subject, body,
                    counselor_name, counselor_agency, counselor_email, counselor_phone,
                    client_name, client_dob, client_phone, client_email, client_address,
                    referral_source, school, grade_level, guardian_name, guardian_phone,
                    relationship, contact_first, consent_confirmed, preets_services, income_bracket, quoted_fee, referral_purpose, requested_service, specific_needs)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
                REFERRER_ROLES[$role] . ' referral', $noteType,
                mb_substr(ucfirst($role) . ' referral: ' . $clientName, 0, 255),
                // Cap free-text public inputs so an anonymous submitter can't store multi-MB values.
                mb_substr(trim($_POST['body']), 0, 8000),
                mb_substr(trim($_POST['referrer_name']), 0, 120), mb_substr($orgLabel, 0, 190), strtolower($refEmail), mb_substr(trim($_POST['referrer_phone']), 0, 40),
                $clientName,
                valid_date($_POST['client_dob'] ?? ''),
                $clientPhone,
                $clientEmail,
                mb_substr(trim($_POST['client_address'] ?? ''), 0, 255) ?: null,
                $role,
                $role === 'school' ? $organization : null,
                mb_substr(trim($_POST['grade_level'] ?? ''), 0, 40) ?: null,
                mb_substr(trim($_POST['guardian_name'] ?? ''), 0, 120) ?: null,
                mb_substr(trim($_POST['guardian_phone'] ?? ''), 0, 40) ?: null,
                mb_substr(trim($_POST['relationship'] ?? ''), 0, 80) ?: null,
                in_array($_POST['contact_first'] ?? '', ['client','referrer'], true) ? $_POST['contact_first'] : null,
                $consent ? 1 : 0,
                null, // Historical Pre-ETS data remains readable; new AT referrals never populate it.
                $role === 'self' ? INCOME_BRACKETS[$income] : null,
                $fee, $requestFields['referral_purpose'], $requestFields['requested_service'], $requestFields['specific_needs'],
            ]);
            $refId = (int)$pdo->lastInsertId();
            if (!add_task('Contact new referral: ' . $clientName,
                'Reach out to the referral within 3 business days of receipt.',
                null, $refId, business_days_from(3), null, null, 'ref-contact-' . $refId)) {
                throw new RuntimeException('The contact task for a new referral could not be created.');
            }
            $pdo->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?, 'referral-portal', 1)")->execute([$ip]);
            $pdo->commit();
            $refSaved = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // Logged by class and code only: the message can quote what was typed.
            error_log('Referral save failed: ' . get_class($e) . ' (code ' . $e->getCode() . ')');
            $errors['form'] = 'The referral could not be sent because of a problem on our side, and nothing was saved. Everything you typed is still here. Please try again in a few minutes, or email us.';
        }
    }
    if (!$errors && $refSaved) {
        $done = $fee !== null ? '&fee=' . ($fee > 0 ? 'paid' : 'free') : '';
        // Remembered for this form (newest 20 kept), so the same page sent again
        // is answered with this confirmation instead of a second referral.
        $_SESSION['referral_form_keys'] = array_slice(($_SESSION['referral_form_keys'] ?? []) + [$formKey => ['sig' => $formSig, 'done' => $done]], -20, null, true);
        // Site-wide ceiling on the alerts (not on the referrals): past it, every
        // referral is still saved with its task, but administrators get one
        // notice instead of a message and email per referral until the hour's
        // count drops, so a flood cannot bury them or use up the mail quota.
        $hourTotal = (int)db()->query("SELECT COUNT(*) FROM login_attempts WHERE email = 'referral-portal' AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn();
        if ($hourTotal > REFERRAL_ALERTS_PER_HOUR) {
            // One notice per hour of flood, claimed atomically on a marker row
            // (its updated_at is the last notice): concurrent submissions that
            // all count past the limit cannot all skip it, or all send it.
            $claim = db()->prepare("INSERT INTO settings (name, value) VALUES ('referral_flood_notice', ?)
                ON DUPLICATE KEY UPDATE value = IF(updated_at < DATE_SUB(NOW(), INTERVAL 1 HOUR), VALUES(value), value)");
            $claim->execute([date('Y-m-d H:i:s')]);
            if ($claim->rowCount() > 0) {
                notify_admin_users('Many referrals are arriving: alerts paused for now', 'referrals.php');
                notify_admins('Many referrals are arriving through the referral form',
                    'More than ' . REFERRAL_ALERTS_PER_HOUR . " referrals arrived through the public referral form in the last hour, so the alert for each new referral is paused until the rate drops.\n\n"
                    . "Every referral is still saved, with its contact task. Review them here: " . cfg('base_url') . "/referrals.php\n");
            }
            redirect('community_referral.php?submitted=1' . $done);
        }
        notify_admin_users('New ' . strtoupper($role) . ' referral: ' . $clientName, 'referrals.php?view=' . $refId);
        // The email goes out from the organisation's own mailbox, so it carries
        // none of the anonymous submitter's free text (names, organisation):
        // those are read on the referrals page after signing in.
        notify_admins('New referral received (#' . $refId . ')',
            "A new referral arrived via the referral form.\n\n"
            . 'Referral number: ' . $refId . "\n"
            . 'Referred as: ' . REFERRER_ROLES[$role] . "\n"
            . ($structured ? referral_request_summary($requestFields) . "\n" : '')
            . ($fee !== null ? 'Fee determination: ' . ($fee > 0 ? '$' . number_format($fee, 2) . '/hour' : 'Free') . "\n" : '')
            . "\nThe referrer's and the person's details are on the referrals page. Review it here: " . cfg('base_url') . '/referrals.php?view=' . $refId . "\n");
        redirect('community_referral.php?submitted=1' . $done);
    }
}

$saved = isset($_GET['submitted']);
page_header($saved ? 'Referral sent' : ($errors ? 'Error — Community Referral' : 'Community Referral'));
echo lang_toggle();
?>
<?php if ($saved): ?>
<h1><?= t('Referral sent') ?></h1>
<?php // Announced by moving focus to it once (as flash messages are): a status
// region present at page load is often not read after the redirect. ?>
  <div class="alert alert-success" id="referral-sent" tabindex="-1">
    <strong><?= t('Thank you — the referral has been received.') ?></strong> <?= t('We will follow up soon.') ?> </div>
  <script>document.addEventListener('DOMContentLoaded',function(){var s=document.getElementById('referral-sent');if(s)s.focus();if(window.Earcon)Earcon.play('success');});</script>
  <?php if (($feeResult = $_GET['fee'] ?? '') !== ''): ?>
  <div class="card">
    <h2><?= t('Your cost') ?></h2>
    <?php if ($feeResult === 'free'): ?>
      <p><strong><?= t('Based on the income you reported, our services are provided to you at no cost.') ?></strong></p>
    <?php else: ?>
      <p><strong><?= t('Based on the income you reported, our services cost $17.50 per hour of training.') ?></strong> <?= t('We\'ll go over the details when we call — no payment is needed now.') ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <p><a class="btn btn-secondary" href="community_referral.php"><?= t('Submit another referral') ?></a></p>
<?php else: ?>
<h1><?= t('Refer someone to') ?> <?= h(brand('org')) ?></h1>
<p><?= t('Request assistive technology assessment, training, demonstrations, or recommendations for devices, apps, and software.') ?></p>
<p><?= t('Use this form to refer yourself or someone else. First, tell us who you are. Then we\'ll show only the questions you need to answer. Fields marked with an asterisk (') ?><span aria-hidden="true">*</span><span class="visually-hidden"><?= t('asterisk') ?></span><?= t(') are required.') ?></p>
<?php $helpContact = (string)(cfg('support_email') ?: cfg('mail_from')); // the same contact as the shared footer ?>
<p><strong><?= t('Need help with this form, or unsure which choice fits?') ?></strong> <?php if ($helpContact !== ''): ?><?= t('Email us at') ?> <a href="mailto:<?= h($helpContact) ?>"><?= h($helpContact) ?></a> <?= t('and a person will help you.') ?> <?php endif; ?><?= t('After you send the referral, we will contact you within 3 business days.') ?></p>

<?php // The message is announced by moving focus to it once, so it carries no
// alert role: a focused live region is read twice by some screen readers.
// A form-level message (stale page, too many submissions) is followed by the
// field errors found in the same check, so one round trip fixes everything.
$fieldErrors = array_diff_key($errors, ['form' => 1]);
if ($errors): ?>
<div class="alert alert-error" id="form-errors" tabindex="-1" autofocus>
  <?php if (isset($errors['form'])): ?>
  <p><?= t($errors['form']) ?></p>
  <?php if ($fieldErrors): ?><p><strong><?= t('Please also correct the following:') ?></strong></p><?php endif; ?>
  <?php else: ?>
  <p><strong><?= t('Please correct the following and resubmit') ?></strong> <?= t('— everything you typed is still in the form:') ?></p>
  <?php endif; ?>
  <?php if ($fieldErrors): ?>
  <ul>
    <?php foreach ($fieldErrors as $k => $msg): ?>
    <?php // Each link lands on the control the message is about (a group's first one).
    $errAnchors = ['role' => 'role-0', 'income' => 'income-0', 'contact_first' => 'cf-0',
        'consent' => 'consent-box', 'requested_services' => 'svc-0', 'specific_needs' => 'need-0']; ?>
    <?php // A stand-alone link, so it gets the full 44px target height (2.5.5), as in form_error_summary(). ?>
    <li><a href="#<?= h($errAnchors[$k] ?? $k) ?>" style="display:inline-flex; align-items:center; min-height:2.75rem;"><?= t($msg) ?></a></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($errors): ?><script>document.addEventListener('DOMContentLoaded',function(){var a=document.getElementById('form-errors');if(a)a.focus();});</script><?php endif; ?>

<?php // The page language rides on the form address: if the session lapses while the
// form is open, the page that comes back (and its notice) is still in that language. ?>
<form method="post" action="community_referral.php?lang=<?= h(page_lang()) ?>" class="stacked" id="referral-form" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="form_key" value="<?= h($formKey) ?>">
  <div class="honeypot" aria-hidden="true">
    <label for="website"><?= t('Leave this field empty') ?></label>
    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
  </div>

  <fieldset id="role-group" <?= isset($errors['role']) ? 'aria-describedby="err-role"' : '' ?>>
    <legend><h2 class="legend-h"><?= t('I am referring as a…') ?> <span aria-hidden="true">*</span></h2></legend>
    <?php $roleNum = 0; foreach (REFERRER_ROLES as $value => $label): if (!in_array($value, referral_form_config()['roles'], true)) continue; ?>
    <div class="field">
      <label><input type="radio" name="role" id="role-<?= $roleNum++ ?>" value="<?= h($value) ?>" <?= $role === $value ? 'checked' : '' ?> required data-role-radio> <?= t($label) ?></label>
    </div>
    <?php endforeach; ?>
    <?= $err('role') ?>
  </fieldset>

  <fieldset>
    <legend><h2 class="legend-h"><?= t('About you') ?></h2></legend>
    <div class="field">
      <label for="referrer_name"><?= t('Your name') ?> <span aria-hidden="true">*</span></label>
      <input type="text" id="referrer_name" name="referrer_name" required maxlength="120" autocomplete="name" value="<?= $old('referrer_name') ?>" <?= $aria('referrer_name') ?>>
      <?= $err('referrer_name') ?>
    </div>
    <div class="field">
      <label for="referrer_email"><?= t('Your email') ?> <span aria-hidden="true">*</span></label>
      <input type="email" id="referrer_email" name="referrer_email" required maxlength="190" autocomplete="email" value="<?= $old('referrer_email') ?>" <?= $aria('referrer_email') ?>>
      <?= $err('referrer_email') ?>
    </div>
    <div class="field">
      <label for="referrer_phone"><?= t('Your phone') ?> <span aria-hidden="true">*</span></label>
      <input type="tel" id="referrer_phone" name="referrer_phone" required maxlength="40" autocomplete="tel" value="<?= $old('referrer_phone') ?>" <?= $aria('referrer_phone') ?>>
      <?= $err('referrer_phone') ?>
    </div>
    <div class="field" data-role="counselor school medical agency other">
      <label for="organization"><?= t('Agency / school / practice / organization') ?> <span data-role-req="counselor school medical agency" hidden aria-hidden="true">*</span></label>
      <input type="text" id="organization" name="organization" maxlength="190" autocomplete="organization" value="<?= $old('organization') ?>"
             data-req-roles="counselor school medical agency" <?= $aria('organization', 'org-hint') ?>>
      <p class="hint" id="org-hint"><?= t('Required for counselor, school, medical, and agency referrals.') ?></p>
      <?= $err('organization') ?>
    </div>
    <div class="field" data-role="family">
      <label for="relationship"><?= t('Your relationship to the person') ?> <span data-role-req="family" hidden aria-hidden="true">*</span> <span class="hint"><?= t('e.g., daughter, spouse, friend — required for family referrals') ?></span></label>
      <input type="text" id="relationship" name="relationship" maxlength="80" value="<?= $old('relationship') ?>"
             data-req-roles="family" <?= $aria('relationship') ?>>
      <?= $err('relationship') ?>
    </div>
  </fieldset>

  <fieldset>
    <legend><h2 class="legend-h"><?= t('What help is needed?') ?></h2></legend>
    <div class="field">
      <label for="referral_purpose"><?= t('Referral purpose') ?></label>
      <select id="referral_purpose" name="referral_purpose" required <?= $aria('referral_purpose', 'purpose-hint') ?>><?= select_options(referral_options('purposes'), is_string($request['purpose']) ? $request['purpose'] : null) ?></select>
      <p class="hint" id="purpose-hint"><?= $whole(referral_form_config()['hints']['purpose']['en']) ?></p>
      <?= $err('referral_purpose') ?>
    </div>
    <fieldset class="field" id="requested-services-group" aria-describedby="services-hint<?= isset($errors['requested_services']) ? ' err-requested_services' : '' ?>">
      <legend><?= t('Assistive technology services requested') ?><?= referral_form_config()['min_services'] > 0 ? ' <span aria-hidden="true">*</span><span class="visually-hidden"> (' . t('required: choose at least one') . ')</span>' : '' ?></legend>
      <p class="hint" id="services-hint"><?= $whole(referral_form_config()['hints']['services']['en']) ?></p>
      <?php $pickedServices = $request['service'] !== '' ? explode('; ', $request['service']) : []; ?>
      <?php foreach (referral_options('services') as $svcNum => $svc): $svcHint = referral_service_hint($svc); ?>
      <label class="check"><input type="checkbox" name="requested_services[]" id="svc-<?= (int)$svcNum ?>" value="<?= h($svc) ?>" <?= in_array($svc, $pickedServices, true) ? 'checked' : '' ?>> <?= $whole($svc) ?>
        <?php if ($svcHint !== ''): ?><span class="hint">(<?= $whole($svcHint) ?>)</span><?php endif; ?></label>
      <?php endforeach; ?>
      <?= $err('requested_services') ?>
    </fieldset>
    <?php if (referral_options('needs')): ?>
    <fieldset aria-describedby="needs-hint<?= isset($errors['specific_needs']) ? ' err-specific_needs' : '' ?>">
      <legend><?= t('Specific needs (optional)') ?></legend>
      <p class="hint" id="needs-hint"><?= $whole(referral_form_config()['hints']['needs']['en']) ?></p>
      <?php foreach (referral_options('needs') as $needNum => $need): ?>
      <label class="check"><input type="checkbox" name="specific_needs[]" id="need-<?= (int)$needNum ?>" value="<?= h($need) ?>" <?= in_array($need, $request['needs'], true) ? 'checked' : '' ?>> <?= $whole($need) ?></label>
      <?php endforeach; ?>
      <?= $err('specific_needs') ?>
    </fieldset>
    <?php endif; ?>
  </fieldset>

  <fieldset data-role="self" id="income-group" <?= isset($errors['income']) ? 'aria-describedby="err-income"' : '' ?>>
    <legend><h2 class="legend-h"><?= t('Monthly income') ?> <span aria-hidden="true">*</span></h2></legend>
    <p class="hint"><?= t('If you earn less than $2,000 a month, our services are free. If you earn $2,000 or more, training costs $17.50 an hour.') ?></p>
    <?php $incomeNum = 0; foreach (INCOME_BRACKETS as $value => $label): ?>
    <div class="field">
      <label><input type="radio" name="income" id="income-<?= $incomeNum++ ?>" value="<?= h($value) ?>" <?= ($_POST['income'] ?? '') === $value ? 'checked' : '' ?> data-req-roles="self">
        <?= t($label) ?> — <strong><?= t($value === 'below_2000' ? 'free' : '$17.50 per hour') ?></strong></label>
    </div>
    <?php endforeach; ?>
    <?= $err('income') ?>
  </fieldset>

  <fieldset data-role="counselor school medical family agency other">
    <legend><h2 class="legend-h"><?= t('About the person you\'re referring') ?></h2></legend>
    <div class="field">
      <label for="client_name"><?= t('Their full name') ?> <span aria-hidden="true">*</span></label>
      <input type="text" id="client_name" name="client_name" maxlength="120" value="<?= $old('client_name') ?>"
             data-req-roles="counselor school medical family agency other" <?= $aria('client_name') ?>>
      <?= $err('client_name') ?>
    </div>
    <div class="field"><label for="client_dob"><?= t('Date of birth') ?></label>
      <input type="date" id="client_dob" name="client_dob" max="<?= date('Y-m-d') ?>" value="<?= $old('client_dob') ?>" <?= $aria('client_dob') ?>>
      <?= $err('client_dob') ?></div>
    <div class="field"><label for="client_phone"><?= t('Their phone') ?></label>
      <input type="tel" id="client_phone" name="client_phone" maxlength="40" value="<?= $old('client_phone') ?>" <?= $aria('client_phone') ?>>
      <?= $err('client_phone') ?></div>
    <div class="field"><label for="client_email"><?= t('Their email') ?></label>
      <input type="email" id="client_email" name="client_email" maxlength="190" value="<?= $old('client_email') ?>" <?= $aria('client_email') ?>>
      <?= $err('client_email') ?></div>
    <div class="field"><label for="client_address"><?= t('Their address') ?></label>
      <input type="text" id="client_address" name="client_address" maxlength="255" value="<?= $old('client_address') ?>" <?= $aria('client_address') ?>>
      <?= $err('client_address') ?></div>

    <div class="field" data-role="school">
      <label for="grade_level"><?= t('Grade level') ?></label>
      <input type="text" id="grade_level" name="grade_level" maxlength="40" value="<?= $old('grade_level') ?>" <?= $aria('grade_level') ?>>
      <?= $err('grade_level') ?>
    </div>
    <div class="field" data-role="school">
      <label for="guardian_name"><?= t('Parent / guardian name') ?> <span aria-hidden="true">*</span></label>
      <input type="text" id="guardian_name" name="guardian_name" maxlength="120" value="<?= $old('guardian_name') ?>"
             data-req-roles="school" <?= $aria('guardian_name') ?>>
      <?= $err('guardian_name') ?>
    </div>
    <div class="field" data-role="school">
      <label for="guardian_phone"><?= t('Parent / guardian phone') ?> <span aria-hidden="true">*</span></label>
      <input type="tel" id="guardian_phone" name="guardian_phone" maxlength="40" value="<?= $old('guardian_phone') ?>"
             data-req-roles="school" <?= $aria('guardian_phone') ?>>
      <?= $err('guardian_phone') ?>
    </div>
  </fieldset>

  <?php // The visible question is the textarea's label (it sits in the section
  // heading). Someone referring themselves is asked in the second person; the
  // page script shows the wording for the chosen role, and a hidden wording is
  // left out of the label and the description. ?>
  <div class="field" id="need-section">
    <h2 class="legend-h"><label for="body"><span data-other-text<?= $role === 'self' ? ' hidden' : '' ?>><?= t('What does this person need help with?') ?></span><span data-self-text<?= $role === 'self' ? '' : ' hidden' ?>><?= t('What do you need help with?') ?></span> <span aria-hidden="true">*</span></label></h2>
    <p class="hint" id="body-hint"><span data-other-text<?= $role === 'self' ? ' hidden' : '' ?>><?= t('Tell us which tasks they want technology to help with, the devices or software they use, and any accessibility barriers.') ?></span><span data-self-text<?= $role === 'self' ? '' : ' hidden' ?>><?= t('Tell us which tasks you want technology to help with, the devices or software you use, and any accessibility barriers.') ?></span></p>
    <textarea id="body" name="body" required
      aria-describedby="body-hint<?= isset($errors['body']) ? ' err-body' : '' ?>" <?= isset($errors['body']) ? 'aria-invalid="true"' : '' ?>><?= $old('body') ?></textarea>
    <?= $err('body') ?>
  </div>

  <fieldset data-role="medical family agency other">
    <legend><h2 class="legend-h"><?= t('Contact and consent') ?></h2></legend>
    <fieldset class="field" data-role="family" id="contact-first-group" <?= isset($errors['contact_first']) ? 'aria-describedby="err-contact_first"' : '' ?>>
      <legend><h3 class="legend-h"><?= t('Who should we contact first?') ?> <span aria-hidden="true">*</span></h3></legend>
      <label><input type="radio" name="contact_first" id="cf-0" value="client" <?= ($_POST['contact_first'] ?? '') === 'client' ? 'checked' : '' ?> data-req-roles="family"> <?= t('The person themselves') ?></label>
      <label><input type="radio" name="contact_first" value="referrer" <?= ($_POST['contact_first'] ?? '') === 'referrer' ? 'checked' : '' ?> data-req-roles="family"> <?= t('Me first') ?></label>
      <?= $err('contact_first') ?>
    </fieldset>
    <div class="field" data-role="medical family agency other">
      <label><input type="checkbox" id="consent-box" name="consent" value="1" <?= !empty($_POST['consent']) ? 'checked' : '' ?>
          data-req-roles="medical family agency other" <?= $aria('consent') ?>> <?= t('The person (or their guardian) knows about this referral and agrees to be contacted.') ?> <span aria-hidden="true">*</span></label>
      <?= $err('consent') ?>
    </div>
  </fieldset>

  <button type="submit" class="btn btn-primary"><?= t('Send referral') ?></button>
</form>

<script>
// Progressive enhancement: show only the fields for the chosen role.
// Without JavaScript, all sections stay visible and server-side validation applies.
(function () {
    var radios = document.querySelectorAll('[data-role-radio]');
    var sections = document.querySelectorAll('[data-role]');
    var reqFields = document.querySelectorAll('[data-req-roles]');
    var reqMarks = document.querySelectorAll('[data-role-req]');
    function update() {
        var chosen = document.querySelector('[data-role-radio]:checked');
        var role = chosen ? chosen.value : null;
        sections.forEach(function (el) {
            var roles = el.getAttribute('data-role').split(' ');
            el.hidden = role !== null && roles.indexOf(role) === -1;
        });
        // Mark conditionally-required fields as required for the chosen role,
        // so assistive tech announces them correctly (WCAG 3.3.2).
        reqFields.forEach(function (el) {
            el.required = role !== null && el.getAttribute('data-req-roles').split(' ').indexOf(role) !== -1;
        });
        reqMarks.forEach(function (el) {
            el.hidden = role === null || el.getAttribute('data-role-req').split(' ').indexOf(role) === -1;
            el.setAttribute('aria-hidden', 'true');
        });
    }
    // Wording for someone referring themselves (second person) or someone else.
    var selfText = document.querySelectorAll('[data-self-text]');
    var otherText = document.querySelectorAll('[data-other-text]');
    function wording() {
        var chosen = document.querySelector('[data-role-radio]:checked');
        var self = !!chosen && chosen.value === 'self';
        selfText.forEach(function (el) { el.hidden = !self; });
        otherText.forEach(function (el) { el.hidden = self; });
    }
    radios.forEach(function (r) { r.addEventListener('change', update); r.addEventListener('change', wording); });
    update();
    wording();
})();
// A date only partly typed (for example no year) is posted as blank, which the
// server cannot tell from an empty field, so the date would be lost without a
// word. Stop the submission and say so on the field.
(function () {
    var form = document.getElementById('referral-form');
    var dob = document.getElementById('client_dob');
    if (!form || !dob) return;
    var msg = <?= json_encode(__('Enter the complete date of birth (month, day and four-digit year), or clear the field.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var baseDescribed = dob.getAttribute('aria-describedby') || '';
    var baseInvalid = dob.getAttribute('aria-invalid');
    form.addEventListener('submit', function (event) {
        var old = document.getElementById('err-client_dob-partial');
        if (old) {
            old.parentNode.removeChild(old);
            if (baseDescribed) dob.setAttribute('aria-describedby', baseDescribed); else dob.removeAttribute('aria-describedby');
            if (baseInvalid) dob.setAttribute('aria-invalid', baseInvalid); else dob.removeAttribute('aria-invalid');
        }
        if (dob.closest('[hidden]') || !dob.validity || !dob.validity.badInput) return;
        event.preventDefault();
        var p = document.createElement('p');
        p.className = 'field-error';
        p.id = 'err-client_dob-partial';
        p.textContent = msg;
        dob.insertAdjacentElement('afterend', p);
        dob.setAttribute('aria-invalid', 'true');
        dob.setAttribute('aria-describedby', (baseDescribed ? baseDescribed + ' ' : '') + p.id);
        dob.focus();
    });
})();
</script>
<?php endif; ?>
<?php page_footer();
