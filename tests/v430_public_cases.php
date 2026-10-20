<?php
// Included by full_schema.php after the 4.29.0 cases, against its synthetic database only:
// 4.30.0 (accessibility), the public area. The staff code check's error is a focused summary and is
// inside the field's label; custom referral questions are required on the control and their English is
// marked on the Spanish form; the role radios speak once; group errors sit on the radios, before them;
// a turned-down referral is answered in Spanish; a Spanish client keeps the Spanish email after the
// English one is edited; the portal's Spanish dates, booking list, file label and new-link check; the
// masked address; the accessibility statement in Spanish.

$v430pLangEs = require $root . '/public/inc/lang_es.php';
require_once $root . '/public/inc/custom_fields.php';
require_once $root . '/public/inc/case_outcomes.php';

// ---- Staff code check: a focused summary, and the error inside the label (no aria-describedby) ----
$v430pUser = $db->query('SELECT * FROM users WHERE id = 1')->fetch();
putenv('CMS_TEST_ANON=1');
putenv('CMS_TEST_SESSION=' . json_encode(['pending_user_id' => 1, 'pending_at' => time(), 'pending_credential_version' => credential_version($v430pUser)]));
[$code, $v430pVerify, $err] = $renderPage('verify', [], ['code' => '12']);
putenv('CMS_TEST_ANON'); putenv('CMS_TEST_SESSION');
check($code === 0 && str_contains($v430pVerify, 'id="form-error-summary"') && str_contains($v430pVerify, 'href="#code"')
    && preg_match('~<label for="code">.*?<span class="field-error" id="err-code"[^>]*>Enter the 6-digit code[^<]*</span></label>~s', $v430pVerify) === 1,
    'A refused sign-in code is a focused error summary linked to the field, and the error is said inside the label' . ($err ? ": $err" : ''));
check(preg_match('~<input[^>]*id="code"[^>]*>~', $v430pVerify, $v430pM) === 1 && str_contains($v430pM[0], 'aria-invalid="true"')
    && !str_contains($v430pM[0], 'aria-describedby') && !str_contains($v430pM[0], 'autofocus'),
    'The code field is marked invalid, has no aria-describedby, and does not take focus from the summary');

// ---- Custom referral questions: required on the control, English marked on the Spanish form ----
$db->exec("INSERT INTO custom_fields(label,label_es,hint,type,options,required,on_referral,sort_order) VALUES('V430-PUBLIC Question',NULL,'V430-PUBLIC hint','choice','Alpha\nBeta',1,1,90)");
$v430pF1 = (int)$db->lastInsertId();
$db->exec("INSERT INTO custom_fields(label,label_es,hint,type,required,on_referral,sort_order) VALUES('V430-PUBLIC Spanish one','V430-PUBLIC Pregunta',NULL,'text',0,1,91)");
$v430pF2 = (int)$db->lastInsertId();
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION=' . json_encode(['lang' => 'es']));
[$code, $v430pRefEs, $err] = $renderPage('community_referral', ['lang' => 'es']);
putenv('CMS_TEST_SESSION');
[$code2, $v430pRefEn, $err2] = $renderPage('community_referral', []);
putenv('CMS_TEST_ANON');
check($code === 0 && str_contains($v430pRefEs, '<label for="cf_' . $v430pF1 . '"><span lang="en">V430-PUBLIC Question</span>')
    && str_contains($v430pRefEs, '<span class="hint"><span lang="en">V430-PUBLIC hint</span></span>')
    && str_contains($v430pRefEs, '<option value="Alpha" lang="en">Alpha</option>')
    && str_contains($v430pRefEs, '<label for="cf_' . $v430pF2 . '">V430-PUBLIC Pregunta</label>'),
    'On the Spanish referral form a custom question\'s English label, hint and options are marked lang="en", and a Spanish label is not' . ($err ? ": $err" : ''));
check($code2 === 0 && preg_match('~<select id="cf_' . $v430pF1 . '"[^>]*aria-required="true"~', $v430pRefEn) === 1
    && preg_match('~<input[^>]*id="cf_' . $v430pF2 . '"[^>]*>~', $v430pRefEn, $v430pM) === 1 && !str_contains($v430pM[0], 'aria-required')
    && !str_contains($v430pRefEn, 'lang="en">V430-PUBLIC Question'),
    'A required custom question is required on the control itself; an optional one is not, and nothing is marked English on the English form' . ($err2 ? ": $err2" : ''));
// Staff pages build the same controls (custom_fields_html with $public false): required is not tied to the public form.
$v430pCfSrc = (string)file_get_contents($root . '/public/inc/custom_fields.php');
check(str_contains($v430pCfSrc, "\$aria = trim(field_aria(\$id) . (\$required ? ' aria-required=\"true\"' : ''));")
    && str_contains($v430pCfSrc, "\$es = \$public && function_exists('page_lang') && page_lang() === 'es';"),
    'Staff pages get the required custom field required on the control too, and English is marked only on a Spanish public page');

// ---- Referral role radios: one announcement after the choice rests, the state's autofill for self ----
$v430pRefSrc = (string)file_get_contents($root . '/public/community_referral.php');
check(str_contains($v430pRefSrc, 'clearTimeout(announceTimer);') && str_contains($v430pRefSrc, '}, 700);') && !str_contains($v430pRefSrc, '}, 50);'),
    'The role announcement waits until the choice has rested, so arrowing through the roles is not repeated');
check(preg_match('~<select id="client_state"[^>]*autocomplete="off"~', $v430pRefEn) === 1, 'The state is not autofilled as the referrer\'s own when someone else is referred');
putenv('CMS_TEST_ANON=1');
[$code, $v430pRefSelf, $err] = $renderPage('community_referral', ['role' => 'self']);
// A referral sent with no role: the error is before the choices, and each choice is marked invalid.
[$code3, $v430pRefErr, $err3] = $renderPage('community_referral', [], ['referrer_name' => 'V430-PUBLIC Referrer', 'body' => 'V430-PUBLIC need']);
putenv('CMS_TEST_ANON');
check($code === 0 && preg_match('~<select id="client_state"[^>]*autocomplete="address-level1"~', $v430pRefSelf) === 1,
    'Someone referring themselves gets the state autofilled from their own address' . ($err ? ": $err" : ''));
check($code3 === 0 && preg_match('~id="err-role".*?name="role"~s', $v430pRefErr) === 1 && preg_match('~<input type="radio" name="role" id="role-0"[^>]*aria-invalid="true"~', $v430pRefErr) === 1,
    'An unanswered role group shows its error before the choices, and each choice is marked invalid' . ($err3 ? ": $err3" : ''));
$db->exec("UPDATE custom_fields SET is_active=0 WHERE id IN ($v430pF1,$v430pF2)");

// ---- Survey and device check-in: each radio of an unanswered group is marked invalid ----
$v430pSurvey = (string)file_get_contents($root . '/public/survey.php');
check(substr_count($v430pSurvey, "required<?= has_field_error('satisfaction') ? ' aria-invalid=\"true\"' : '' ?>") === 1
    && substr_count($v430pSurvey, "required<?= has_field_error('goals_met') ? ' aria-invalid=\"true\"' : '' ?>") === 1,
    'The survey marks each radio of an unanswered question invalid');

// A survey answered on the Spanish page records that its answers were given in Spanish.
$v430pSTok = bin2hex(random_bytes(24));
$db->prepare("INSERT INTO surveys(client_id,token) VALUES(1,?)")->execute([$v430pSTok]);
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION=' . json_encode(['lang' => 'es']));
[$code, , $err] = $renderPage('survey', ['t' => $v430pSTok, 'lang' => 'es'], ['satisfaction' => '9', 'goals_met' => '8', 'comments' => '']);
putenv('CMS_TEST_ANON'); putenv('CMS_TEST_SESSION');
$v430pSRow = $db->query('SELECT completed_at, answer_lang FROM surveys WHERE token=' . $db->quote($v430pSTok))->fetch();
check($v430pSRow['completed_at'] !== null && $v430pSRow['answer_lang'] === 'es', 'A survey answered in Spanish is saved with answer_lang es' . ($err ? ": $err" : ''));

// ---- A turned-down referral is answered in the language it was sent in ----
$v430pSink = sys_get_temp_dir() . '/v430p-mail-' . bin2hex(random_bytes(4));
mkdir($v430pSink, 0700);
$v430pCfg = $GLOBALS['CONFIG'];
$GLOBALS['CONFIG']['mail_disabled'] = true; $GLOBALS['CONFIG']['mail_sink'] = $v430pSink;
$v430pRefRow = function (string $lang) use ($db): int {
    $db->prepare("INSERT INTO referrals(reason,subject,body,status,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name,preferred_language,created_at) VALUES('V430-PUBLIC','V430-PUBLIC referral','V430-PUBLIC body','New','V430-PUBLIC Referrer','','v430-public@example.invalid','','V430-PUBLIC Person',?,'2026-10-02 10:00:00')")->execute([$lang]);
    return (int)$db->lastInsertId();
};
$v430pEs = $v430pRefRow('es');
check(referral_close($v430pEs, 'Not eligible', '', 1)['error'] === null, 'A Spanish referral is closed');
$v430pMail = implode("\n", array_map('file_get_contents', glob($v430pSink . '/*.txt') ?: []));
check(str_contains($v430pMail, 'Subject: Su referido REF-') && str_contains($v430pMail, 'No pudimos aceptarlo: lo que se pide está fuera de los servicios que podemos ofrecer.')
    && str_contains($v430pMail, 'recibido el viernes, 2 de octubre de 2026') && !str_contains($v430pMail, 'We were not able'),
    'A referral sent on the Spanish form is turned down in Spanish, with a Spanish date and reason');
array_map('unlink', glob($v430pSink . '/*.txt') ?: []);
$v430pEn = $v430pRefRow('en');
referral_close($v430pEn, 'Not eligible', '', 1);
$v430pMail = implode("\n", array_map('file_get_contents', glob($v430pSink . '/*.txt') ?: []));
check(str_contains($v430pMail, 'We were not able to accept it: the request is outside the services we can provide.'), 'An English referral is still turned down in English');
array_map('unlink', glob($v430pSink . '/*.txt') ?: []); rmdir($v430pSink);
$GLOBALS['CONFIG'] = $v430pCfg;
check(isset(EMAIL_TEMPLATES['referral_closed_es']) && (EMAIL_TEMPLATE_REQUIRED['referral_closed_es'] ?? '') === '{ref}', 'The Spanish referral outcome is an editable template that must keep the reference number');
// Every client email with an English version has a Spanish one.
foreach (['portal_link', 'consent_request', 'release_request', 'sign_request', 'message_notice', 'request_closed', 'portal_expiring', 'survey_invite',
    'session_booked_client', 'session_moved_client', 'session_cancelled_client', 'loan_due_client', 'loan_overdue_client', 'times_offered', 'session_reminder_client', 'referral_closed'] as $v430pKey) {
    check(isset(EMAIL_TEMPLATES[$v430pKey . '_es']), "The client email $v430pKey has a Spanish version");
}

// ---- The client's session reminder is in Spanish for a Spanish-speaking client ----
$db->exec("INSERT INTO clients(name,status,assigned_to,preferred_language,email,email_verified) VALUES('V430-PUBLIC Reminder Cliente','Active Training',1,'es','v430-public-es@example.invalid',1)"); $v430pRc = (int)$db->lastInsertId();
$db->exec("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status,is_remote) VALUES($v430pRc,1,DATE_ADD(NOW(),INTERVAL 20 HOUR),DATE_ADD(NOW(),INTERVAL 21 HOUR),'Scheduled',1)");
$v430pRs = (int)$db->lastInsertId();
$v430pRTs = strtotime((string)$db->query("SELECT start_at FROM scheduled_sessions WHERE id=$v430pRs")->fetchColumn());
[$code, $v430pRMail] = $cronRun('session_reminders.php', true);
// The mail log may hold encoded bodies: decode quoted-printable and base64 parts before reading.
$v430pRDec = quoted_printable_decode($v430pRMail);
foreach (preg_split('/\R\R/', $v430pRMail) as $v430pPart) if (preg_match('~^[A-Za-z0-9+/=\r\n]{40,}$~', trim($v430pPart))) $v430pRDec .= "\n" . base64_decode(trim($v430pPart));
check($code === 0 && str_contains($v430pRDec, 'Cuándo: ' . when_es($v430pRTs)) && str_contains($v430pRDec, 'Dónde: en línea')
    && str_contains($v430pRDec, '¿Necesita otra hora?') && !str_contains($v430pRDec, 'Hello V430-PUBLIC Reminder Cliente'),
    'A Spanish-speaking client\'s session reminder is the Spanish one, with a Spanish date, place and reschedule line');
check((int)$db->query("SELECT reminder_sent FROM scheduled_sessions WHERE id=$v430pRs")->fetchColumn() === 1, 'The Spanish reminder is marked sent');

// ---- An edited English template no longer replaces the Spanish one ----
$db->exec("INSERT INTO clients(name,status,assigned_to,preferred_language) VALUES('V430-PUBLIC Spanish Client','Active Training',1,'es')"); $v430pEsClient = (int)$db->lastInsertId();
set_setting('tpl_portal_link_body', "V430-PUBLIC English wording {link}");
[$v430pSubj, $v430pBody] = email_template_for_client('portal_link', $v430pEsClient, ['name' => 'V430', 'link' => 'https://example.invalid/x']);
check(str_starts_with($v430pBody, 'Hola, V430:') && !str_contains($v430pBody, 'V430-PUBLIC English wording'), 'A Spanish client keeps the built-in Spanish email after the English one is edited');
set_setting('tpl_portal_link_es_body', "V430-PUBLIC Spanish wording {link}");
[, $v430pBody] = email_template_for_client('portal_link', $v430pEsClient, ['name' => 'V430', 'link' => 'https://example.invalid/x']);
check(str_starts_with($v430pBody, 'V430-PUBLIC Spanish wording'), 'An administrator\'s own Spanish wording is used for a Spanish client');
[, $v430pBody] = email_template_for_client('portal_link', 1, ['name' => 'V430', 'link' => 'https://example.invalid/x']);
check(str_starts_with($v430pBody, 'V430-PUBLIC English wording'), 'The edited English wording still goes to English-speaking clients');
set_setting('tpl_portal_link_body', ''); set_setting('tpl_portal_link_es_body', '');

// ---- Portal: Spanish dates, the booking list, the file label, no doubled heading ----
$v430pTok = bin2hex(random_bytes(24));
$db->prepare("INSERT INTO portal_tokens(client_id,token,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$v430pEsClient, $v430pTok]);
$v430pStart = strtotime('+9 days 10:00');
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,1,?,?,'Scheduled')")->execute([$v430pEsClient, date('Y-m-d H:i:s', $v430pStart), date('Y-m-d H:i:s', $v430pStart + 3600)]);
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION=' . json_encode(['lang' => 'es']));
[$code, $v430pPortalEs, $err] = $renderPage('portal', ['t' => $v430pTok, 'lang' => 'es']);
putenv('CMS_TEST_SESSION');
[$code2, $v430pPortalEn, $err2] = $renderPage('portal', ['t' => $v430pTok]);
putenv('CMS_TEST_ANON');
$v430pDayEs = when_es($v430pStart, false);
check($code === 0 && str_contains($v430pPortalEs, '<strong>' . h(when_es($v430pStart)) . '</strong>') && !str_contains($v430pPortalEs, '<strong><span lang="en">' . date('l', $v430pStart)),
    'Upcoming sessions on the Spanish portal give Spanish dates' . ($err ? ": $err" : ''));
check(str_contains($v430pPortalEs, '<span class="visually-hidden"> <span lang="es">(descarga un archivo)</span></span></a></div>'),
    '"Add to calendar" says that it downloads a file');
// (The client prefers Spanish, so the portal is Spanish on both renders.)
check($code2 === 0 && preg_match('~<label for="document"><span lang="es">Elija un archivo</span> <span class="hint"><span lang="es">\(obligatorio; PDF, Office, texto o imagen, hasta 15 MB\)</span></span></label><input type="file" id="document"[^>]*aria-required="true"~', $v430pPortalEn) === 1
    && !preg_match('~<input type="file" id="document"[^>]*\srequired[\s>]~', $v430pPortalEn),
    'The file to share names the kinds and size of file in its label, and is not exposed as invalid before sending' . ($err2 ? ": $err2" : ''));
check(!str_contains($v430pPortalEn, '<h3 class="visually-hidden">Update my details</h3>'), '"Update my details" is not read twice');
$v430pPortalSrc = (string)file_get_contents($root . '/public/portal.php');
check(str_contains($v430pPortalSrc, '<optgroup label="<?= h($bsDay) ?>">') && str_contains($v430pPortalSrc, "<select id=\"slot\" name=\"slot\" aria-required=\"true\"")
    && str_contains($v430pPortalSrc, "if(!s||!s.value)return true;") && str_contains($v430pPortalSrc, "<label for=\"slot\"><?= t('Time with your instructor') ?> <?= required_mark() ?></label>"),
    'Booking times are grouped by day, the time is required, and "Book this time?" comes only once a time is chosen');

// ---- Portal "Email me a new link": a blank or mistyped address is refused on the field ----
putenv('CMS_TEST_ANON=1');
[$code, $v430pLink, $err] = $renderPage('portal', [], ['new_link' => '1', 'new_link_email' => 'name@gmail']);
[$code2, $v430pLinkBlank, $err2] = $renderPage('portal', [], ['new_link' => '1', 'new_link_email' => '']);
putenv('CMS_TEST_ANON');
check($code === 0 && str_contains($v430pLink, 'id="form-error-summary"') && str_contains($v430pLink, 'Enter your email address written like name@example.com.')
    && preg_match('~<input type="email" id="new_link_email"[^>]*aria-invalid="true"~', $v430pLink) === 1 && str_contains($v430pLink, 'value="name@gmail"')
    && !str_contains($v430pLink, 'a new link is on its way'),
    'A mistyped address for a new portal link is refused on the field, with the summary' . ($err ? ": $err" : ''));
check($code2 === 0 && str_contains($v430pLinkBlank, 'Enter your email address.') && !str_contains($v430pLinkBlank, 'a new link is on its way'), 'A blank address for a new portal link is refused' . ($err2 ? ": $err2" : ''));

// ---- The masked address on the report confirmation is not read as stars ----
$v430pAck = (string)file_get_contents($root . '/public/ack.php');
check(str_contains($v430pAck, "'<span aria-hidden=\"true\">' . str_repeat('*'") && str_contains($v430pAck, ', hidden letters, '), 'The masked address hides its stars from screen readers');

// ---- The accessibility statement in Spanish, with the language switch ----
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION=' . json_encode(['lang' => 'es']));
[$code, $v430pA11yEs, $err] = $renderPage('accessibility', ['lang' => 'es']);
putenv('CMS_TEST_SESSION');
[$code2, $v430pA11yEn, $err2] = $renderPage('accessibility', []);
putenv('CMS_TEST_ANON');
check($code === 0 && preg_match('~<html[^>]*lang="es"~', $v430pA11yEs) === 1 && str_contains($v430pA11yEs, '<h1>Accesibilidad</h1>') && !str_contains($v430pA11yEs, 'This system is meant to be usable'),
    'The accessibility statement is in Spanish on a Spanish page' . ($err ? ": $err" : ''));
check($code2 === 0 && str_contains($v430pA11yEn, '<h1>Accessibility</h1>') && str_contains($v430pA11yEn, 'lang=es'), 'The English statement offers the Spanish version' . ($err2 ? ": $err2" : ''));

// ---- Signing pages use the shared required mark; new wording is translated ----
foreach (['sign.php', 'consent.php', 'release.php'] as $v430pF) {
    $v430pSrc = (string)file_get_contents($root . '/public/' . $v430pF);
    check(str_contains($v430pSrc, "<label for=\"signature\"><?= t('Electronic signature') ?> <?= required_mark() ?>") && preg_match('~<input type="text" id="signature"[^>]*\brequired\b~', $v430pSrc) === 1,
        "$v430pF marks the signature required in the label (in the page language) and on the field");
}
foreach (['(required)', 'Accessibility', 'Enter your email address.', 'Enter your email address written like name@example.com.', '(required; PDF, Office, text or image, up to 15 MB)'] as $v430pS) {
    check(isset($v430pLangEs[$v430pS]), "Spanish is given for \"$v430pS\"");
}
