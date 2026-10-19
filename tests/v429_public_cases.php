<?php
// Included by full_schema.php after the 4.28.0 cases, against its synthetic database only:
// 4.29.0, the public area. An offered time never books with an instructor who was switched off
// or lost the client, and open offers are withdrawn on a caseload transfer and on deactivation;
// texts from clients alert an active instructor or the administrators and open the same task as
// the portal; a held text never goes to an old number; the referral confirmation uses the
// agency's own contact days; the staff header is English on a Spanish page; help gives the
// signing-link limit set; one announcement on Ask, Ask the knowledge base and Translate.

$v429pLangEs = require $root . '/public/inc/lang_es.php';
$v429pBase = strtotime('+25 days 10:00'); while ((int)date('N', $v429pBase) > 5) $v429pBase += 86400;
$v429pOffer = function (int $clientId, int $staffId, int $start) use ($db): array {
    $db->prepare("INSERT INTO portal_requests(client_id,type,status) VALUES(?,'appointment','New')")->execute([$clientId]);
    $rid = (int)$db->lastInsertId();
    $res = offer_times_save($rid, [$start], 60, $staffId, null, false, 'V429-PUBLIC Room', 1);
    $oid = (int)$db->query("SELECT id FROM portal_request_offers WHERE request_id=$rid AND status='Offered'")->fetchColumn();
    return [$rid, $oid, $res];
};
$v429pNotes = fn(int $uid, string $like): int => (int)$db->query('SELECT COUNT(*) FROM notifications WHERE user_id=' . $uid . ' AND message LIKE ' . $db->quote($like))->fetchColumn();

// ---- P12: an offered time with a switched-off instructor is never booked ----
$db->exec("INSERT INTO users(name,email,password_hash,role) VALUES('V429-PUBLIC Instructor B','v429-public-b@example.invalid','x','instructor')"); $v429pB = (int)$db->lastInsertId();
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('V429-PUBLIC Offer Client','Active Training',1)"); $v429pC = (int)$db->lastInsertId();
$db->prepare("INSERT INTO portal_tokens(client_id,token,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$v429pC, bin2hex(random_bytes(24))]);
[$v429pR1, $v429pO1, $v429pRes1] = $v429pOffer($v429pC, $v429pB, $v429pBase);
check($v429pRes1['error'] === null && $v429pO1 > 0, 'A time with an active instructor can be offered');
$db->exec("UPDATE users SET is_active=0 WHERE id=$v429pB");
$v429pBefore = $v429pNotes(1, 'A client chose % with an instructor who can no longer take it%');
$v429pConf = offer_confirm($v429pO1, $v429pC);
check($v429pConf['error'] !== null && str_contains($v429pConf['error'], 'no longer available') && $v429pConf['session_id'] === null
    && $db->query("SELECT status FROM portal_request_offers WHERE id=$v429pO1")->fetchColumn() === 'Expired'
    && !(int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$v429pC")->fetchColumn(),
    'A time offered with an instructor switched off since is not booked, and the offer expires');
check($v429pNotes(1, 'A client chose % with an instructor who can no longer take it%') === $v429pBefore + 1
    && !$v429pNotes($v429pB, 'A client chose%'),
    'The client\'s active instructor is told to offer new times, not the switched-off account');
check(isset($v429pLangEs['That time is no longer available. Your instructor has been told and will offer new times.']), 'The client sees that refusal in Spanish too');
$db->exec("UPDATE users SET is_active=1 WHERE id=$v429pB");
// A caseload-limited instructor the client has since been moved away from.
$db->exec("INSERT INTO `groups`(name,perms,own_caseload_only) VALUES('V429-PUBLIC scoped group','clients;schedule',1)"); $v429pG = (int)$db->lastInsertId();
$db->prepare("INSERT INTO users(name,email,password_hash,role,group_id) VALUES('V429-PUBLIC Scoped D','v429-public-d@example.invalid','x','instructor',?)")->execute([$v429pG]); $v429pD = (int)$db->lastInsertId();
$db->exec("UPDATE clients SET assigned_to=$v429pD WHERE id=$v429pC");
[, $v429pO2, $v429pRes2] = $v429pOffer($v429pC, $v429pD, $v429pBase + 86400);
check($v429pRes2['error'] === null && $v429pO2 > 0, 'A caseload-limited instructor assigned the client can be offered');
$db->exec("UPDATE clients SET assigned_to=1 WHERE id=$v429pC");
$v429pConf2 = offer_confirm($v429pO2, $v429pC);
check($v429pConf2['error'] !== null && $db->query("SELECT status FROM portal_request_offers WHERE id=$v429pO2")->fetchColumn() === 'Expired'
    && !(int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$v429pC")->fetchColumn(),
    'A time with a caseload-limited instructor the client was moved away from is not booked');

// ---- P12: a caseload transfer withdraws the open offers, and tells the new instructor ----
$db->exec("INSERT INTO users(name,email,password_hash,role) VALUES('V429-PUBLIC Instructor E','v429-public-e@example.invalid','x','instructor')"); $v429pE = (int)$db->lastInsertId();
$db->exec("UPDATE clients SET assigned_to=$v429pE WHERE id=$v429pC");
[, $v429pO3, $v429pRes3] = $v429pOffer($v429pC, $v429pE, $v429pBase + 2 * 86400);
check($v429pRes3['error'] === null && $v429pO3 > 0, 'A time with the assigned instructor can be offered before the transfer');
$v429pTold = $v429pNotes(1, 'Times offered to V429-PUBLIC were withdrawn%');
[$code, , $err] = $renderPage('users', ['id' => (string)$v429pE], ['transfer_caseload' => '1', 'uid' => (string)$v429pE, 'to_user' => '1']);
check((int)$db->query("SELECT assigned_to FROM clients WHERE id=$v429pC")->fetchColumn() === 1
    && $db->query("SELECT status FROM portal_request_offers WHERE id=$v429pO3")->fetchColumn() === 'Withdrawn',
    'Transferring a caseload withdraws the times still offered with the instructor' . ($err ? ": $err" : ''));
check($v429pNotes(1, 'Times offered to V429-PUBLIC were withdrawn%') === $v429pTold + 1, 'The client\'s new instructor is told to offer new times');
check(offer_confirm($v429pO3, $v429pC)['error'] !== null && !(int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE client_id=$v429pC")->fetchColumn(),
    'A withdrawn time can no longer be chosen');

// ---- P12: switching an account off withdraws the open offers ----
[, $v429pO4, $v429pRes4] = $v429pOffer($v429pC, $v429pB, $v429pBase + 3 * 86400);
check($v429pRes4['error'] === null && $v429pO4 > 0, 'A time with another active instructor can be offered');
[$code, , $err] = $renderPage('users', ['id' => (string)$v429pB], ['save_user' => '1', 'uid' => (string)$v429pB, 'name' => 'V429-PUBLIC Instructor B',
    'email' => 'v429-public-b@example.invalid', 'role' => 'instructor', 'weekly_hours' => '20']);
check((int)$db->query("SELECT is_active FROM users WHERE id=$v429pB")->fetchColumn() === 0
    && $db->query("SELECT status FROM portal_request_offers WHERE id=$v429pO4")->fetchColumn() === 'Withdrawn',
    'Switching an account off withdraws the times still offered with it' . ($err ? ": $err" : ''));
check(str_contains((string)file_get_contents($root . '/public/inc/staff_accounts.php'), 'offer_withdraw_for_staff('),
    'A Microsoft 365 departure withdraws the offers too');

// ---- Texts from clients alert an active instructor, else the administrators, with a task ----
$GLOBALS['CONFIG']['sms'] = ['account_sid' => 'AC-synthetic', 'auth_token' => 'synthetic-token', 'from' => '+15155550100'];
putenv('CMS_TEST_SMS=ok');
$db->exec("INSERT INTO clients(name,assigned_to,status,phone,preferred_language,sms_consent_at) VALUES('V429-PUBLIC Texter',$v429pB,'Active Training','515-555-0429','en',NOW())"); $v429pT = (int)$db->lastInsertId();
$db->exec("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES($v429pT,$v429pB,DATE_ADD(NOW(),INTERVAL 30 HOUR),DATE_ADD(NOW(),INTERVAL 31 HOUR),'Scheduled')"); $v429pTS = (int)$db->lastInsertId();
$v429pAdminX = $v429pNotes(1, 'V429-PUBLIC asked by text to cancel%');
sms_inbound('+15155550429', 'X', 'SM-V429-PUBLIC-1');
$v429pReq = $db->query("SELECT * FROM portal_requests WHERE client_id=$v429pT AND type='cancel' ORDER BY id DESC LIMIT 1")->fetch();
check($v429pReq && $v429pNotes(1, 'V429-PUBLIC asked by text to cancel%') === $v429pAdminX + 1 && !$v429pNotes($v429pB, '%asked by text%'),
    'A cancel by text from a client whose instructor was switched off alerts the administrators, not the dead account');
$v429pTask = $db->query("SELECT * FROM tasks WHERE auto_key='preq-" . (int)($v429pReq['id'] ?? 0) . "'")->fetch();
check($v429pTask && $v429pTask['assigned_to'] === null && (int)$v429pTask['client_id'] === $v429pT,
    'A request by text opens the same follow-up task a portal request does (unassigned when nobody active holds the client)');
$db->exec("INSERT INTO users(name,email,password_hash,role) VALUES('V429-PUBLIC Instructor F','v429-public-f@example.invalid','x','instructor')"); $v429pF = (int)$db->lastInsertId();
$db->exec("UPDATE clients SET assigned_to=$v429pF WHERE id=$v429pT");
sms_inbound('+15155550429', 'R', 'SM-V429-PUBLIC-2');
check($v429pNotes($v429pF, 'V429-PUBLIC asked by text to reschedule%') === 1, 'With the session\'s instructor switched off, the active assigned instructor is told');
$v429pReqR = (int)$db->query("SELECT id FROM portal_requests WHERE client_id=$v429pT AND type='reschedule' ORDER BY id DESC LIMIT 1")->fetchColumn();
check((int)$db->query("SELECT assigned_to FROM tasks WHERE auto_key='preq-$v429pReqR'")->fetchColumn() === $v429pF, 'The task goes to the active assigned instructor');
sms_inbound('+15155550429', 'V429-PUBLIC running late today', 'SM-V429-PUBLIC-3');
check($v429pNotes($v429pF, 'New text message from V429-PUBLIC%') === 1, 'Any other text alerts the active assigned instructor');
$db->exec("UPDATE users SET is_active=0 WHERE id=$v429pF");
$v429pAdminMsg = $v429pNotes(1, 'New text message from a client with no active assigned staff member%');
sms_inbound('+15155550429', 'V429-PUBLIC are we still on', 'SM-V429-PUBLIC-4');
check($v429pNotes(1, 'New text message from a client with no active assigned staff member%') === $v429pAdminMsg + 1 && $v429pNotes($v429pF, 'New text message%') === 1,
    'A text from a client whose instructor was switched off alerts the administrators');
// A Spanish-speaking client's request is stored in English for staff, and read in Spanish on their portal.
$db->exec("UPDATE clients SET preferred_language='es' WHERE id=$v429pT");
$db->exec("UPDATE scheduled_sessions SET client_reply=NULL WHERE id=$v429pTS");
sms_inbound('+15155550429', 'R', 'SM-V429-PUBLIC-5');
$v429pEsReq = $db->query("SELECT id, details FROM portal_requests WHERE client_id=$v429pT AND type='reschedule' ORDER BY id DESC LIMIT 1")->fetch();
$v429pEsTask = (string)$db->query("SELECT details FROM tasks WHERE auto_key='preq-" . (int)$v429pEsReq['id'] . "'")->fetchColumn();
check(str_starts_with((string)$v429pEsReq['details'], 'Asked by text to reschedule the session on ') && str_starts_with($v429pEsTask, 'Asked by text to reschedule')
    && !str_contains((string)$v429pEsReq['details'], 'Pidió'),
    'A reschedule by text from a Spanish-speaking client is stored in English for staff, in the request and its task');
$v429pTok = bin2hex(random_bytes(24));
$db->prepare("INSERT INTO portal_tokens(client_id,token,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$v429pT, $v429pTok]);
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION=' . json_encode(['lang' => 'es']));
[$code, $v429pPortalEs, $err] = $renderPage('portal', ['t' => $v429pTok, 'lang' => 'es']);
putenv('CMS_TEST_ANON'); putenv('CMS_TEST_SESSION');
check($code === 0 && str_contains($v429pPortalEs, 'Pidió por mensaje de texto cambiar la sesión del <span lang="en">') && !str_contains($v429pPortalEs, 'Asked by text to reschedule'),
    'On the Spanish portal the request by text reads in Spanish, with the stored time marked English' . ($err ? ": $err" : ''));

// ---- A held text never goes to an old number ----
$db->exec("UPDATE clients SET preferred_language='en' WHERE id=$v429pT");
$db->exec("INSERT INTO sms_log(direction,client_id,phone,body,purpose,status,send_after) VALUES('out',$v429pT,'+15155550429','V429-PUBLIC held','message','held',DATE_SUB(NOW(),INTERVAL 1 MINUTE))"); $v429pH1 = (int)$db->lastInsertId();
$db->exec("UPDATE clients SET phone='515-555-0430' WHERE id=$v429pT");
sms_flush_held();
$v429pRow = $db->query("SELECT status, provider_sid, error FROM sms_log WHERE id=$v429pH1")->fetch();
check($v429pRow['status'] === 'blocked' && $v429pRow['provider_sid'] === null && str_contains((string)$v429pRow['error'], 'number changed'),
    'A held text is not sent once the client\'s number has changed');
$db->exec("INSERT INTO sms_log(direction,client_id,phone,body,purpose,status,send_after) VALUES('out',$v429pT,'+15155550430','V429-PUBLIC held two','message','held',DATE_SUB(NOW(),INTERVAL 1 MINUTE))"); $v429pH2 = (int)$db->lastInsertId();
sms_flush_held();
check($db->query("SELECT status FROM sms_log WHERE id=$v429pH2")->fetchColumn() === 'sent', 'A held text to the current number still goes');

// ---- The referral confirmation date follows the agency's contact days ----
$v429pRef = (string)file_get_contents($root . '/public/community_referral.php');
check(str_contains($v429pRef, '$contactBy = business_days_from(isset($contactDays) ? (int)$contactDays : 3);') && !str_contains($v429pRef, 'within 3 business days'),
    'The referral confirmation promises the same date as the staff task, and the form names no fixed number of days');
check(isset($v429pLangEs['After you send the referral, we will tell you the date we will contact you by.']) && isset($v429pLangEs['We will contact you within a few business days.']),
    'The new referral wording is translated');

// ---- The staff header and menu are English, and marked so, on a Spanish public page ----
putenv('CMS_TEST_SESSION_ADD=' . json_encode(['lang' => 'es']));
[$code, $v429pHtml, $err] = $renderPage('community_referral', ['lang' => 'es']);
putenv('CMS_TEST_SESSION_ADD');
check($code === 0 && preg_match('~<html[^>]*lang="es"~', $v429pHtml) === 1 && preg_match('~<header class="site-header"[^>]*lang="en"~', $v429pHtml) === 1
    && preg_match('~<nav class="wrap page-actions[^>]*lang="en"~', $v429pHtml) === 1 && preg_match('~cms-page-navigation.*?Go back~s', $v429pHtml) === 1,
    'Staff on a Spanish public page get the staff header and shortcuts in English, marked lang="en"' . ($err ? ": $err" : ''));
check(!str_contains($v429pHtml, 'aria-describedby="hint-back"') && str_contains($v429pHtml, 'nav-hint-sr visually-hidden">: Returns to the page you came from'),
    'The shortcut hints are inside their links, not joined by aria-describedby');

// ---- Booking options carry plain text (a span inside an option is dropped) ----
$v429pPortal = (string)file_get_contents($root . '/public/portal.php');
check(!str_contains($v429pPortal, "en((string)\$bs['location'])"), 'The self-booking place is plain text inside its option');

// ---- Help gives the signing-link limit an administrator set ----
set_setting(cms_number_setting('sign_link_days'), '30');
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code, $v429pHelp, $err] = $renderPage('help', []);
putenv('CMS_TEST_ANON');
check($code === 0 && str_contains($v429pHelp, 'A consent link stops working 30 days after it is sent') && !str_contains($v429pHelp, '60 days after it is sent'),
    'Help gives the signing-link limit set on Lists and time limits' . ($err ? ": $err" : ''));
set_setting(cms_number_setting('sign_link_days'), '60');
[$code, $v429pHelpAdmin, $err] = $renderPage('help', []);
check(str_contains($v429pHelpAdmin, '--timezone=YOUR_TIME_ZONE') && str_contains($v429pHelpAdmin, 'meeting_tokens.key'),
    'Help\'s restore steps give the time zone and the key file to copy' . ($err ? ": $err" : ''));
$v429pRunbook = (string)file_get_contents($root . '/RUNBOOK.md');
check(str_contains($v429pRunbook, '--timezone=<your time zone>') && str_contains($v429pRunbook, 'meeting_tokens.key'), 'The runbook\'s restore command gives the time zone and the key file');

// ---- One announcement, with a summary, on Ask, Ask the knowledge base and Translate ----
foreach (['ask.php' => "form_error_summary(['question'", 'kb_ask.php' => "form_error_summary(['question'", 'translate.php' => "form_error_summary(['other'"] as $v429pF2 => $v429pSum) {
    $v429pSrc = (string)file_get_contents($root . '/public/' . $v429pF2);
    check(str_contains($v429pSrc, $v429pSum) && !str_contains($v429pSrc, "flash('Type a question to ask.'") && !str_contains($v429pSrc, "flash(implode(' ', \$errs)"),
        "$v429pF2 announces a field error once, with a summary");
}
[$code, $v429pTr, $err] = $renderPage('translate', [], ['go' => '1', 'lang' => 'Spanish', 'content' => '']);
check($code === 0 && (str_contains($v429pTr, 'id="form-error-summary"') || str_contains($v429pTr, 'turned off')) && substr_count($v429pTr, 'Paste the text to translate.') <= 2,
    'Translate with no text shows the summary and the field error, not a banner as well' . ($err ? ": $err" : ''));

// ---- A switched-off account keeps its Microsoft identity when its email changes ----
$db->exec("INSERT INTO users(name,email,password_hash,role,is_active,entra_oid) VALUES('V429-PUBLIC Oid Off','v429-public-oid@example.invalid','x','instructor',0,'v429-public-oid-1')"); $v429pOid = (int)$db->lastInsertId();
$v429pSave = fn(string $email, bool $on) => $renderPage('users', ['id' => (string)$v429pOid], ['save_user' => '1', 'uid' => (string)$v429pOid, 'name' => 'V429-PUBLIC Oid Off',
    'email' => $email, 'role' => 'instructor', 'weekly_hours' => '10'] + ($on ? ['is_active' => '1'] : []));
[$code, , $err] = $v429pSave('v429-public-oid2@example.invalid', false);
$v429pU = $db->query("SELECT email, entra_oid FROM users WHERE id=$v429pOid")->fetch();
check($v429pU['email'] === 'v429-public-oid2@example.invalid' && $v429pU['entra_oid'] === 'v429-public-oid-1',
    'Changing a switched-off account\'s email keeps its Microsoft identity, so it is still refused at sign-in' . ($err ? ": $err" : ''));
[$code, , $err] = $v429pSave('v429-public-oid3@example.invalid', true);
$v429pU = $db->query("SELECT is_active, entra_oid FROM users WHERE id=$v429pOid")->fetch();
check((int)$v429pU['is_active'] === 1 && $v429pU['entra_oid'] === null,
    'Reactivating the account with a new email removes the Microsoft sign-in link' . ($err ? ": $err" : ''));
