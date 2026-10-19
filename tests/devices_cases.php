<?php
// Included by full_schema.php after privacy_incident_cases.php, against its synthetic database
// only: one phone at a time (sign out, report lost and wipe) and the Spanish privacy notice (3.0.39).

/* A phone reported lost wipes itself at its next request */
$phone=bin2hex(random_bytes(32));
$db->prepare("INSERT INTO api_tokens(user_id,token_hash,expires_at,device_name) VALUES(1,?,DATE_ADD(NOW(),INTERVAL 30 DAY),'Synthetic iPhone')")->execute([hash('sha256',$phone)]); $phoneId=(int)$db->lastInsertId();
$other=bin2hex(random_bytes(32));
$db->prepare("INSERT INTO api_tokens(user_id,token_hash,expires_at,device_name) VALUES(1,?,DATE_ADD(NOW(),INTERVAL 30 DAY),'Second synthetic iPhone')")->execute([hash('sha256',$other)]); $otherId=(int)$db->lastInsertId();
[$code,$out,$err]=$renderPage('account',[]);
check($code===0 && $err==='' && str_contains($out,'Synthetic iPhone') && str_contains($out,'Report lost and wipe'),'My Account lists the phones signed in to the app' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('account',[],['device_lost'=>'1','device_id'=>(string)$phoneId]);
check($code===0 && $err==='' && $db->query("SELECT wipe_requested_at FROM api_tokens WHERE id=$phoneId")->fetchColumn()!==null,'A phone is reported lost' . ($err ? ": $err" : ''));
putenv('CMS_TEST_TOKEN='.$phone);
$wiped=$call('me',[],[],false);
putenv('CMS_TEST_TOKEN');
check(($wiped['wipe']??null)===true && $db->query("SELECT wiped_at FROM api_tokens WHERE id=$phoneId")->fetchColumn()!==null
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='user.device_wiped'")->fetchColumn()>=1,'Its next request tells the app to wipe itself, and the session ends');
putenv('CMS_TEST_TOKEN='.$phone);
$again=$call('me',[],[],false);
putenv('CMS_TEST_TOKEN');
check(($again['wipe']??null)===true,'A wiped session keeps being told to wipe until the app confirms (4.27.0)');
putenv('CMS_TEST_TOKEN='.$other);
check(isset($call('me')['name']),'Another phone of the same account keeps working');
putenv('CMS_TEST_TOKEN');
[$code,,$err]=$renderPage('users',['id'=>1],['uid'=>'1','device_signout'=>'1','device_id'=>(string)$otherId]);
check($code===0 && $err==='' && (int)$db->query("SELECT COUNT(*) FROM api_tokens WHERE id=$otherId")->fetchColumn()===0,'An administrator signs one phone out' . ($err ? ": $err" : ''));

/* The privacy notice in Spanish */
set_setting('cfg.brand.hipaa','yes'); // the HIPAA section shows when Settings says HIPAA applies (4.3.0)
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,$out,$err]=$renderPage('privacy',['lang'=>'es']);
putenv('CMS_TEST_ANON');
check($code===0 && $err==='' && str_contains($out,'Aviso de privacidad') && str_contains($out,'Sus derechos bajo HIPAA') && str_contains($out,'lang="es"'),'The privacy notice is in Spanish' . ($err ? ": $err" : ''));
