<?php
// Included by full_schema.php after v426_cases.php, against its synthetic database only:
// 4.27.0 client records: merge keeps a legal hold and the fields it dropped, a stale edit
// is refused, a closed referral is not converted, trash under hold is kept, and the full
// export leaves out another person's incident report.

// ---- Merging keeps the duplicate's hold and fills the VR case number ----
$db->exec("INSERT INTO clients(name,status,state) VALUES('Merge keep synthetic 427','Active Training','IA')"); $mk=(int)$db->lastInsertId();
$db->exec("INSERT INTO clients(name,status,state,vr_case_number,hold_at,hold_by,hold_reason) VALUES('Merge dupe synthetic 427','Active Training','IA','VR-427-SYN',NOW(),1,'Synthetic records request')"); $md=(int)$db->lastInsertId();
check(merge_fillable(['vr_case_number'=>null,'preferred_language'=>'en'],['vr_case_number'=>'VR-1','preferred_language'=>'es'])===['vr_case_number'=>'VR-1','preferred_language'=>'es'],
    'Merging can fill the VR case number and a language other than the default');
[$code,$html,$err]=$renderPage('client_merge',['keep'=>(string)$mk,'merge'=>(string)$md]);
check($code===0 && $err==='' && str_contains($html,'The hold will be kept') && str_contains($html,'VR case number: VR-427-SYN'),
    'The merge confirmation says the duplicate is on hold and that the hold will be kept'.($err?": $err":''));
[$code,,$err]=$renderPage('client_merge',['keep'=>(string)$mk,'merge'=>(string)$md],['do_merge'=>'1','keep'=>(string)$mk,'merge'=>(string)$md,'fill'=>['vr_case_number']]);
$kept=$db->query("SELECT hold_at,hold_by,hold_reason,vr_case_number FROM clients WHERE id=$mk")->fetch();
check($code===0 && $err==='' && (int)$db->query("SELECT COUNT(*) FROM clients WHERE id=$md")->fetchColumn()===0
    && $kept['hold_at']!==null && (int)$kept['hold_by']===1 && $kept['hold_reason']==='Synthetic records request' && $kept['vr_case_number']==='VR-427-SYN',
    'Merging carries the duplicate\'s legal hold onto the kept record and fills its VR case number'.($err?": $err":''));

// ---- A stale edit is refused, and keeps what was typed ----
$db->exec("INSERT INTO clients(name,status,state,client_type) VALUES('Stale edit synthetic 427','Active Training','IA','VR')"); $se=(int)$db->lastInsertId();
$staleForm=['name'=>'Stale edit typed name 427','status'=>'Active Training','client_type'=>'VR','state'=>'IA','loaded_at'=>'2000-01-01 00:00:00'];
[$code,$html,$err]=$renderPage('client_edit',['id'=>$se],$staleForm);
check($code===0 && $err==='' && $db->query("SELECT name FROM clients WHERE id=$se")->fetchColumn()==='Stale edit synthetic 427'
    && str_contains($html,'Someone else changed this record since you opened it') && str_contains($html,'Stale edit typed name 427'),
    'A save of a client changed since the form was opened is refused, and the typed changes are shown again'.($err?": $err":''));
$fresh=(string)$db->query("SELECT updated_at FROM clients WHERE id=$se")->fetchColumn();
[$code,,$err]=$renderPage('client_edit',['id'=>$se],['loaded_at'=>$fresh]+$staleForm);
check($code===0 && $err==='' && $db->query("SELECT name FROM clients WHERE id=$se")->fetchColumn()==='Stale edit typed name 427',
    'The same save from a form opened on the current record goes through'.($err?": $err":''));

// ---- A closed referral cannot be converted from a stale page ----
$db->exec("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name,referral_source,status) VALUES('Synthetic','Synthetic referral 427','Synthetic body','Synthetic counselor','Synthetic agency','referrer-427@example.invalid','555-0427','Closed convert synthetic 427','counselor','Closed')"); $cr=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('referrals',[],['id'=>(string)$cr,'convert'=>'1']);
check($code===0 && $err==='' && $db->query("SELECT status FROM referrals WHERE id=$cr")->fetchColumn()==='Closed'
    && (int)$db->query("SELECT COUNT(*) FROM clients WHERE name='Closed convert synthetic 427'")->fetchColumn()===0,
    'A closed referral is not converted into a client'.($err?": $err":''));

// ---- "Delete forever" in the trash is refused for a client on hold ----
$db->exec("INSERT INTO clients(name,status,state,hold_at,hold_by,hold_reason) VALUES('Trash hold synthetic 427','Active Training','IA',NOW(),1,'Synthetic litigation')"); $th=(int)$db->lastInsertId();
$db->prepare("INSERT INTO trash(entity,entity_id,label,payload,deleted_by) VALUES('client_notes',999427,'Synthetic held note',?,1)")
    ->execute([json_encode(['id'=>999427,'client_id'=>$th,'note_type'=>'Case Note','subject'=>'Held','body'=>'Held note','author_id'=>1])]); $tt=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('trash',[],['trash_id'=>(string)$tt,'purge'=>'1']);
check($code===0 && $err==='' && (int)$db->query("SELECT COUNT(*) FROM trash WHERE id=$tt")->fetchColumn()===1,
    'Delete forever refuses a trash entry whose client is on hold'.($err?": $err":''));
$db->exec("DELETE FROM trash WHERE id=$tt");

// ---- The full export leaves out an incident reported by someone else ----
require_once $root.'/public/inc/client_export.php';
$db->exec("INSERT INTO clients(name,status,state) VALUES('Export incident synthetic 427','Active Training','IA')"); $ei=(int)$db->lastInsertId();
$db->prepare("INSERT INTO incidents(client_id,reported_by,incident_date,description) VALUES(?,1,CURDATE(),'Synthetic own incident 427')")->execute([$ei]);
$db->prepare("INSERT INTO incidents(client_id,reported_by,incident_date,description) VALUES(?,NULL,CURDATE(),'Synthetic other incident 427')")->execute([$ei]);
$permsBefore=$GLOBALS['CURRENT_PERMS'];
$GLOBALS['CURRENT_PERMS']=['clients'];
$narrow=client_export_records($db,$ei,['id'=>1]);
$GLOBALS['CURRENT_PERMS']=array_keys(PERMISSION_KEYS);
$full=client_export_records($db,$ei,['id'=>1]);
$GLOBALS['CURRENT_PERMS']=$permsBefore;
check(count($narrow['incidents'])===1 && $narrow['incidents'][0]['description']==='Synthetic own incident 427' && isset($narrow['incidents_not_included'])
    && !isset($narrow['sms_log']) && isset($narrow['sms_log_not_included']),
    'The export leaves out another person\'s incident report and the texting log for staff who cannot see them, and says so');
check(count($full['incidents'])===2 && isset($full['sms_log']),'An administrator\'s export still has every incident report and the texting log');

// ---- A refused edit keeps this person's own changes and shows the other person's ----
$db->exec("INSERT INTO clients(name,status,state,client_type) VALUES('Conflict synthetic 427','Active Training','IA','VR')"); $ce=(int)$db->lastInsertId();
[$code,$html,$err]=$renderPage('client_edit',['id'=>$ce]);
preg_match('/name="loaded_at" value="([^"]*)"/',$html,$mLoaded); preg_match('/name="orig" value="([^"]*)"/',$html,$mOrig);
$opened=['loaded_at'=>html_entity_decode($mLoaded[1]??'',ENT_QUOTES),'orig'=>html_entity_decode($mOrig[1]??'',ENT_QUOTES)];
check($code===0 && $err==='' && $opened['loaded_at']!=='' && is_array(json_decode($opened['orig'],true)),'The edit form carries the version and the values it was opened with'.($err?": $err":''));
// A colleague puts the case on hold meanwhile.
$db->exec("UPDATE clients SET status='On Hold', on_hold_reason='Synthetic colleague', updated_at=DATE_ADD(updated_at, INTERVAL 1 MINUTE) WHERE id=$ce");
[$code,$html,$err]=$renderPage('client_edit',['id'=>$ce],$opened+['name'=>'Conflict typed name 427','status'=>'Active Training','client_type'=>'VR','state'=>'IA']);
check($code===0 && $err==='' && $db->query("SELECT name FROM clients WHERE id=$ce")->fetchColumn()==='Conflict synthetic 427'
    && str_contains($html,'Someone else changed Status since you opened this record') && str_contains($html,'Conflict typed name 427')
    && preg_match('/<option value="On Hold"[^>]*selected/',$html)===1,
    'A refused edit names the field the other person changed, shows its saved value, and keeps this person\'s own change'.($err?": $err":''));

// ---- A trashed client record on hold is not deleted forever ----
$db->prepare("INSERT INTO trash(entity,entity_id,label,payload,deleted_by) VALUES('clients',999428,'Synthetic held client',?,1)")
    ->execute([json_encode(['id'=>999428,'name'=>'Synthetic held client','hold_at'=>date('Y-m-d H:i:s'),'hold_reason'=>'Synthetic subpoena'])]); $tc=(int)$db->lastInsertId();
[[$code,,$err],$sess]=$pageSession(fn()=>$renderPage('trash',[],['trash_id'=>(string)$tc,'purge'=>'1']));
check($code===0 && $err==='' && (int)$db->query("SELECT COUNT(*) FROM trash WHERE id=$tc")->fetchColumn()===1 && str_contains($sess,'on hold'),
    'Delete forever refuses a trashed client record that is on hold, and says so'.($err?": $err":''));
$db->exec("DELETE FROM trash WHERE id=$tc");
