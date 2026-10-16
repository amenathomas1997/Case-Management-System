<?php
// Included by full_schema.php after case_outcomes_cases.php, against its synthetic database
// only: note versions, addenda and the 30-day lock; releases of information; vendor
// agreements (3.0.36).
$db->exec("INSERT INTO clients(name,status,email) VALUES('Records synthetic client','Active Training','records-client@example.invalid')"); $rc=(int)$db->lastInsertId();
$noteRow=fn(int $id)=>$db->query("SELECT * FROM client_notes WHERE id=$id")->fetch();

/* Editing a note keeps the earlier version */
$db->prepare("INSERT INTO client_notes(client_id,note_type,subject,body,author_id) VALUES(?,'Case Note','Original subject','Original body',1)")->execute([$rc]); $n1=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('client',['id'=>$rc,'tab'=>'notes'],['add_note'=>'1','note_id'=>(string)$n1,'note_type'=>'Case Note','note_format'=>'free','subject'=>'Edited subject','body'=>'Edited body']);
$rev=$db->query("SELECT * FROM client_note_revisions WHERE note_id=$n1")->fetchAll();
check($code===0 && $err==='' && $noteRow($n1)['body']==='Edited body' && count($rev)===1 && $rev[0]['body']==='Original body' && (int)$rev[0]['replaced_by']===1,
    'Editing a note on the website keeps the text it replaces as an earlier version' . ($err ? ": $err" : ''));
$call('update_note',['id'=>$n1,'subject'=>'App edited subject','body'=>'App edited body']);
check((int)$db->query("SELECT COUNT(*) FROM client_note_revisions WHERE note_id=$n1")->fetchColumn()===2,'Editing a note in the app keeps an earlier version too');
[$code,$out,$err]=$renderPage('client',['id'=>$rc,'tab'=>'notes']);
check($code===0 && $err==='' && str_contains($out,'Earlier versions (2)') && str_contains($out,'Original body'),'The client page shows a note\'s earlier versions' . ($err ? ": $err" : ''));

/* A note older than 30 days is locked: an addendum corrects it */
$db->prepare("INSERT INTO client_notes(client_id,note_type,subject,body,author_id,created_at) VALUES(?,'Case Note','Old locked subject','Old locked body',1,DATE_SUB(NOW(),INTERVAL 31 DAY))")->execute([$rc]); $old=(int)$db->lastInsertId();
check(note_locked($noteRow($old)) && !note_locked($noteRow($n1)),'A note locks 30 days after it was written');
[$code,,$err]=$renderPage('client',['id'=>$rc,'tab'=>'notes'],['add_note'=>'1','note_id'=>(string)$old,'note_type'=>'Case Note','note_format'=>'free','subject'=>'Changed','body'=>'Changed body']);
check($code===0 && $err==='' && $noteRow($old)['body']==='Old locked body' && (int)$db->query("SELECT COUNT(*) FROM client_note_revisions WHERE note_id=$old")->fetchColumn()===0,
    'A locked note cannot be changed on the website, even by an administrator' . ($err ? ": $err" : ''));
$locked=$call('update_note',['id'=>$old,'subject'=>'Changed','body'=>'Changed body'],[],false,'locked');
check(($locked['locked']??false)===true && $noteRow($old)['body']==='Old locked body','The app is refused with locked true, and the note is unchanged');
$detail=$call('client',[],['id'=>$rc]);
$byId=array_column($detail['notes'],null,'id');
check(($byId[$old]['locked']??null)===true && ($byId[$old]['can_edit']??null)===false && ($byId[$n1]['versions']??0)===2,'The app is told which notes are locked and how many earlier versions each has');
[$code,$out,$err]=$renderPage('client',['id'=>$rc,'tab'=>'notes']);
check($code===0 && $err==='' && str_contains($out,'addendum_to='.$old) && !str_contains($out,'edit_note='.$old),'A locked note offers Add addendum instead of Edit' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('client',['id'=>$rc,'tab'=>'notes'],['add_note'=>'1','addendum_to'=>(string)$old,'note_type'=>'Case Note','note_format'=>'free','subject'=>'Addendum: Old locked subject','body'=>'Correction text']);
$add=$db->query("SELECT * FROM client_notes WHERE addendum_to=$old")->fetch();
check($code===0 && $err==='' && $add && $add['note_type']==='Addendum' && $add['body']==='Correction text','An addendum is saved as a new Addendum note linked to the original' . ($err ? ": $err" : ''));
$appAdd=$call('add_note',['client_id'=>$rc,'subject'=>'App addendum','body'=>'App correction','addendum_to'=>$old,'idem_key'=>'11111111-2222-4333-8444-555555555555']);
check((int)$db->query("SELECT COUNT(*) FROM client_notes WHERE addendum_to=$old AND note_type='Addendum'")->fetchColumn()===2,'The app adds an addendum the same way');
$db->exec("INSERT INTO clients(name,status) VALUES('Other records client','Active Training')"); $otherClient=(int)$db->lastInsertId();
$call('add_note',['client_id'=>$otherClient,'subject'=>'Cross addendum','body'=>'x','addendum_to'=>$old],[],false,'not on this client');
$db->exec("INSERT INTO settings(name,value) VALUES('note_lock_days','0') ON DUPLICATE KEY UPDATE value='0'");
check(!note_locked($noteRow($old)),'With the lock turned off, an old note can be edited again');
$db->exec("UPDATE settings SET value='30' WHERE name='note_lock_days'");

/* Releases of information */
$relRow=fn(int $id)=>$db->query("SELECT * FROM client_releases WHERE id=$id")->fetch();
check(release_warning($rc,'Employer')!==null,'With no release on file, sending to an employer is warned about');
[$code,$out,$err]=$renderPage('client',['id'=>$rc],['send_release'=>'1','signer_role'=>'Client','signer_name'=>'Records synthetic client','signer_email'=>'',
    'recipient_type'=>'Employer','recipient_name'=>'Synthetic Employer Inc','info'=>['Progress reports and attendance'],'purpose'=>'Job accommodations']);
check($code===0 && $err==='' && (int)$db->query("SELECT COUNT(*) FROM client_releases WHERE client_id=$rc")->fetchColumn()===0,'Emailing a release needs the signer\'s email' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('client',['id'=>$rc],['send_release'=>'1','signer_role'=>'Client','signer_name'=>'Records synthetic client','signer_email'=>'records-client@example.invalid',
    'recipient_type'=>'Employer','recipient_name'=>'Synthetic Employer Inc','recipient_email'=>'hr@example.invalid','info'=>['Progress reports and attendance','Equipment and device information'],'purpose'=>'Job accommodations']);
$sent=$db->query("SELECT * FROM client_releases WHERE client_id=$rc")->fetch();
check($code===0 && $err==='' && $sent && $sent['token'] && $sent['signed_at']===null && $sent['info_scope']==='Progress reports and attendance; Equipment and device information'
    && release_status($sent)==='Awaiting signature' && release_warning($rc,'Employer')!==null,'A release is emailed to sign, and does not count until it is signed' . ($err ? ": $err" : ''));
// Signing on the public page, with the typed name matched as on the consent form.
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,$out,$err]=$renderPage('release',['t'=>$sent['token']]);
check($code===0 && $err==='' && str_contains($out,'Synthetic Employer Inc') && str_contains($out,'Job accommodations') && str_contains($out,'name="signature"'),'The release page names the recipient, what is shared and why' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('release',['t'=>$sent['token'],'lang'=>'es']);
check($code===0 && $err==='' && str_contains($out,'Información que podemos compartir') && str_contains($out,'Empleador'),'The release page is in Spanish too' . ($err ? ": $err" : ''));
$renderPage('release',['t'=>$sent['token']],['signature'=>'Someone Else']);
check($relRow((int)$sent['id'])['signed_at']===null,'A name that does not match signs nothing');
[$code,,$err]=$renderPage('release',['t'=>$sent['token']],['signature'=>'records SYNTHETIC client.']);
putenv('CMS_TEST_ANON');
$signed=$relRow((int)$sent['id']);
check($code===0 && $err==='' && $signed['signed_at']!==null && $signed['expires_on']===date('Y-m-d',strtotime('+365 days'))
    && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE client_id=$rc AND subject='Release of information signed'")->fetchColumn()===1,
    'Signing records the release, valid for one year, with a case note' . ($err ? ": $err" : ''));
check(release_warning($rc,'Employer')===null && release_warning($rc,'Doctor or clinic')!==null && release_warning($rc,'Other','HR@example.invalid')===null,
    'A current release covers its recipient type and its named email, and nothing else');
$detail=$call('client',[],['id'=>$rc]);
check(($detail['releases'][0]['status']??'')==='Current' && ($detail['releases'][0]['recipient_name']??'')==='Synthetic Employer Inc','The app is given the client\'s releases and their state');
// A paper release, and its limits.
$paper=['record_release'=>'1','signer_role'=>'Client','signer_name'=>'Records synthetic client','recipient_type'=>'Doctor or clinic','recipient_name'=>'Synthetic Eye Clinic',
    'info'=>['Vision and health information'],'purpose'=>'Coordinate low vision care'];
$renderPage('client',['id'=>$rc],$paper+['signed_on'=>date('Y-m-d',strtotime('-400 days'))]);
check(release_warning($rc,'Doctor or clinic')!==null,'A paper release signed more than a year ago is refused as already expired');
[$code,,$err]=$renderPage('client',['id'=>$rc],$paper+['signed_on'=>date('Y-m-d',strtotime('-10 days'))]);
$pr=$db->query("SELECT * FROM client_releases WHERE client_id=$rc AND method='Paper'")->fetch();
check($code===0 && $err==='' && $pr && $pr['token']===null && $pr['expires_on']===date('Y-m-d',strtotime('-10 days +365 days')) && release_warning($rc,'Doctor or clinic')===null,
    'A paper release is recorded from the date it was signed' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('client',['id'=>$rc],['withdraw_release'=>'1','release_id'=>(string)$pr['id']]);
check($code===0 && $err==='' && release_status($relRow((int)$pr['id']))==='Withdrawn' && release_warning($rc,'Doctor or clinic')!==null,'A withdrawn release no longer covers its recipient' . ($err ? ": $err" : ''));
$db->exec("UPDATE client_releases SET expires_on=DATE_SUB(CURDATE(),INTERVAL 1 DAY) WHERE id=".(int)$sent['id']);
check(release_status($relRow((int)$sent['id']))==='Expired' && release_warning($rc,'Employer')!==null,'An expired release no longer covers its recipient');
[$code,$out,$err]=$renderPage('compliance',[]);
check($code===0 && $err==='' && str_contains($out,'Releases of information') && str_contains($out,'Synthetic Employer Inc'),'Compliance lists an expired release' . ($err ? ": $err" : ''));
// A current guardian consent covers the VR counselor and the school.
$db->exec("INSERT INTO clients(name,status) VALUES('Guardian covered synthetic','Active Training')"); $gc=(int)$db->lastInsertId();
$db->prepare("INSERT INTO consent_forms(client_id,guardian_name,guardian_email,token,signed_at,signed_name) VALUES(?,'Synthetic Guardian','g@example.invalid',?,NOW(),'Synthetic Guardian')")->execute([$gc,bin2hex(random_bytes(24))]);
check(release_warning($gc,'VR counselor and agency')===null && release_warning($gc,'Employer')!==null,'A current guardian consent covers the VR counselor, not an employer');
// Releases move with a merge and leave with a purge (client_id cascade).
check(in_array('client_releases',CLIENT_MERGE_TABLES,true) && in_array('client_note_revisions',CLIENT_MERGE_TABLES,true)
    && in_array('client_releases',CLIENT_EXPORT_TABLES,true) && in_array('client_note_revisions',CLIENT_EXPORT_TABLES,true),'Releases and earlier note versions are merged and exported with the record');

/* Email subjects carry no client names */
[$subj]=email_template('consent_request',['name'=>'G','client'=>'Subject Leak Client','link'=>'x']);
[$subjR]=email_template('release_request',['name'=>'Subject Leak Client','link'=>'x']);
check(!str_contains($subj,'Subject Leak Client') && !str_contains($subjR,'Subject Leak Client'),'Consent and release email subjects do not name the client');

/* Vendor agreements: a record the administrator keeps */
$ms=(int)$db->query("SELECT id FROM vendor_agreements WHERE vendor='Microsoft 365'")->fetchColumn();
check($ms>0 && (int)$db->query("SELECT COUNT(*) FROM vendor_agreements WHERE agreement_status='Not signed'")->fetchColumn()===4,'The four vendors in use are listed, none with a signed agreement');
[$code,,$err]=$renderPage('vendors',[],['save_vendor'=>'1','vendor_id'=>(string)$ms,'agreement_status'=>'Signed','signed_on'=>date('Y-m-d'),'renews_on'=>'','notes'=>'Synthetic BAA']);
check($code===0 && $err==='' && $db->query("SELECT agreement_status FROM vendor_agreements WHERE id=$ms")->fetchColumn()==='Signed','An administrator records a signed agreement' . ($err ? ": $err" : ''));
