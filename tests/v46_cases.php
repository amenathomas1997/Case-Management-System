<?php
// Included by full_schema.php after v45_cases.php, against its synthetic database only:
// 4.6.0's last time limits (reminder lead times, the documentation review windows) and the
// organisation's default mileage rate.
check(cms_number('session_reminder_days')===1 && cms_number('at_reminder_days')===7 && cms_number('doc_audit_note_days')===60 && cms_number('doc_audit_activity_days')===30,'The new time limits start at the built-in values');
[$code,,$err]=$renderPage('lists',[],['save_lists'=>'times','session_reminder_days'=>'3','doc_audit_note_days'=>'90','doc_audit_activity_days'=>'14','at_reminder_days'=>'']);
check($code===0 && $err==='' && cms_number('session_reminder_days')===3 && cms_number('doc_audit_note_days')===90 && cms_number('doc_audit_activity_days')===14,'A business sets its own reminder and review windows' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('doc_audit',[]);
check($code===0 && $err==='' && str_contains($out,'in the last 90 days') && str_contains($out,'logged in the last 14 days'),'The documentation review uses and says the business\'s own windows' . ($err ? ": $err" : ''));
$renderPage('lists',[],['save_lists'=>'times']);

/* Default mileage rate */
[$code,,$err]=$renderPage('pricing',[],['save_pricing'=>'1','personal_rate'=>'17.50','free_below'=>'2000','youth_age'=>'18','vr_conflict'=>'warn','ask'=>'self','vr_first'=>'1','sliding_on'=>'1','default_mileage'=>'0.700']);
check(pricing_settings()['default_mileage']===0.7,'Administrators set a default mileage rate' . ($err ? ": $err" : ''));
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Mileage Default Client','Active Training',1)"); $mdc=(int)$db->lastInsertId();
$tk=['billing_mode'=>'vr','authorized_hours'=>'10','hourly_rate'=>'50','mileage_rate'=>'','status'=>'Active','service_type'=>'Assistive Technology','start_date'=>date('Y-m-d'),'expiration_date'=>date('Y-m-d',strtotime('+60 days'))];
[$code,,$err]=$renderPage('track_edit',['client_id'=>(string)$mdc],['title'=>'Mileage default track','auth_number'=>'MD-1','funding_agency'=>'Synthetic No Rate Agency']+$tk);
[$code,,$err]=$renderPage('track_edit',['client_id'=>(string)$mdc],['title'=>'Mileage agency track','auth_number'=>'MD-2','funding_agency'=>'Synthetic Missouri Agency']+$tk);
check((float)$db->query("SELECT mileage_rate FROM training_tracks WHERE title='Mileage default track'")->fetchColumn()===0.7 && (float)$db->query("SELECT mileage_rate FROM training_tracks WHERE title='Mileage agency track'")->fetchColumn()===0.655,'A new track with no mileage rate takes the agency\'s, else the organisation\'s default' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('pricing',[],['save_pricing'=>'1','personal_rate'=>'17.50','free_below'=>'2000','youth_age'=>'18','vr_conflict'=>'warn','ask'=>'self','vr_first'=>'1','sliding_on'=>'1','default_mileage'=>'lots']);
check(pricing_settings()['default_mileage']===0.7,'A mileage rate that is not a number is refused');
set_setting('pricing_default_mileage','');
