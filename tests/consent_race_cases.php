<?php
// Included by full_schema.php after session_record_cases.php, against its synthetic
// database only: a guardian consent request never silently replaces one sent since the
// page (or the app) last showed it (3.0.32), on the website and through the app.
$db->exec("INSERT INTO clients(name,status) VALUES('Consent race synthetic client','Active Training')"); $crClient=(int)$db->lastInsertId();
$crPending=fn():array=>$db->query("SELECT id FROM consent_forms WHERE client_id=$crClient AND signed_at IS NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$crPost=['request_consent'=>'1','guardian_name'=>'Synthetic guardian','guardian_email'=>'guardian-race@example.invalid'];
// First request from a page that showed none.
[$code,,$err]=$renderPage('client',['id'=>$crClient],$crPost+['consent_seen'=>'0']);
$first=$crPending();
check($code===0 && $err==='' && count($first)===1,'A consent request from a page showing none is sent' . ($err ? ": $err" : ''));
// A second tab drawn before that request: refused, and the first link stays.
[[$code,,$err],$sess]=$pageSession(fn()=>$renderPage('client',['id'=>$crClient],$crPost+['consent_seen'=>'0']));
check($code===0 && $err==='' && $crPending()===$first && str_contains($sess,'was already emailed'),
    'A second tab drawn before that request is refused, and the link already sent keeps working' . ($err ? ": $err" : ''));
// A resend from a page that showed the current request replaces it.
[$code,,$err]=$renderPage('client',['id'=>$crClient],$crPost+['consent_seen'=>(string)$first[0]]);
$second=$crPending();
check($code===0 && $err==='' && count($second)===1 && $second!==$first,'A resend from a page showing the current request replaces it' . ($err ? ": $err" : ''));
// The page carries the request it shows.
[$code,$out,$err]=$renderPage('client',['id'=>$crClient]);
check($code===0 && $err==='' && str_contains($out,'name="consent_seen" value="'.$second[0].'"'),'The consent form says which request the page showed' . ($err ? ": $err" : ''));
// The app: the same check with consent_seen.
$stale=$call('request_consent',['client_id'=>$crClient,'guardian_name'=>'Synthetic guardian','guardian_email'=>'guardian-race@example.invalid','consent_seen'=>(int)$first[0]],[],false);
check(str_contains((string)($stale['error']??''),'was already emailed') && $crPending()===$second,'The app cannot replace a consent request it did not see');
$call('request_consent',['client_id'=>$crClient,'guardian_name'=>'Synthetic guardian','guardian_email'=>'guardian-race@example.invalid','consent_seen'=>(int)$second[0]]);
check(count($crPending())===1 && $crPending()!==$second,'The app replaces the consent request it saw');
