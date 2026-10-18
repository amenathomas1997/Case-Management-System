<?php
// Included by full_schema.php after v423_cases.php, against its synthetic database only:
// 4.24.0: file exchange in the portals, technology outcomes at closure, and custom fields.

// ---- Upload content checks ----
$pdf=$tmp.'/synthetic-letter.pdf'; file_put_contents($pdf,"%PDF-1.4\n% synthetic\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
$fake=$tmp.'/not-really.pdf'; file_put_contents($fake,"<html><script>alert(1)</script></html>");
$png=$tmp.'/synthetic.png'; file_put_contents($png,"\x89PNG\r\n\x1a\n".str_repeat("\0",16));
$txt=$tmp.'/notes.txt'; file_put_contents($txt,"Plain synthetic notes.\n");
$page=$tmp.'/page.txt'; file_put_contents($page,"<!doctype html><p>x</p>");
check(upload_content_ok($pdf,'pdf') && !upload_content_ok($fake,'pdf') && upload_content_ok($png,'png') && !upload_content_ok($png,'jpg') && upload_content_ok($txt,'txt') && !upload_content_ok($page,'txt'),'An upload\'s content must be what its name says');
if (class_exists('ZipArchive')) {
    $docx=$tmp.'/synthetic.docx'; $z=new ZipArchive(); $z->open($docx,ZipArchive::CREATE); $z->addFromString('[Content_Types].xml','<Types/>'); $z->addFromString('word/document.xml','<w:document/>'); $z->close();
    $zip=$tmp.'/other.docx'; $z=new ZipArchive(); $z->open($zip,ZipArchive::CREATE); $z->addFromString('evil.exe','MZ'); $z->close();
    check(upload_content_ok($docx,'docx') && !upload_content_ok($docx,'xlsx') && !upload_content_ok($zip,'docx'),'A Word file must really be a Word file');
}

// ---- The client portal: a file with a message ----
$db->exec("INSERT INTO clients(name,status,email,email_verified,assigned_to,state,counselor_email,counselor_verified,counselor_name) VALUES('Exchange Synthetic Client','Active Training','exchange@example.invalid',1,1,'IA','exchange-vrc@example.invalid',1,'Exchange Counselor')"); $xc=(int)$db->lastInsertId();
$xt=bin2hex(random_bytes(24));
$db->prepare("INSERT INTO portal_tokens(client_id,token,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$xc,$xt]);
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION'); putenv('CMS_TEST_FILES='.json_encode(['msg_file'=>$pdf]));
[$code,,$err]=$renderPage('portal',['t'=>$xt],['send_message'=>'1','message'=>'']);
putenv('CMS_TEST_FILES='.json_encode(['msg_file'=>$fake]));
[$code,,$err2]=$renderPage('portal',['t'=>$xt],['send_message'=>'1','message'=>'Here is another']);
putenv('CMS_TEST_FILES');
$xm=$db->query("SELECT * FROM messages WHERE client_id=$xc AND channel='client' ORDER BY id")->fetchAll();
$xd=$db->query("SELECT * FROM client_documents WHERE client_id=$xc")->fetchAll();
check(count($xm)===1 && (int)$xm[0]['document_id']===(int)$xd[0]['id'] && count($xd)===1 && $xd[0]['uploaded_via']==='portal' && (int)$xd[0]['portal_shared']===1 && is_file($tmp.'/'.$xd[0]['path']),'A client sends a file with a message; a renamed page is refused'.($err.$err2?": $err $err2":''));
[$code,$html,$err]=$renderPage('portal',['t'=>$xt]);
check(str_contains($html,'File: <a href="portal.php?t='.$xt.'&amp;download='.(int)$xd[0]['id'].'"'),'The portal shows the file with the message'.($err?": $err":''));
putenv('CMS_TEST_ANON');
[$code,$html,$err]=$renderPage('messages',['client'=>(string)$xc,'channel'=>'client']);
check(str_contains($html,'download.php?doc='.(int)$xd[0]['id']),'Staff see the client\'s file in the conversation'.($err?": $err":''));

// ---- Staff: a reply with one of the client's documents, shared on the portal ----
$db->prepare("INSERT INTO client_documents(client_id,category,title,orig_name,path,uploaded_by) VALUES(?,'Report','Synthetic assessment','assessment.pdf','synthetic-assessment.pdf',1)")->execute([$xc]); $sd=(int)$db->lastInsertId();
copy($pdf,$tmp.'/synthetic-assessment.pdf');
[$code,,$err]=$renderPage('messages',[],['reply'=>'1','client_id'=>(string)$xc,'channel'=>'client','body'=>'Your assessment','attach_doc'=>(string)$sd,'idem'=>bin2hex(random_bytes(8))]);
$last=$db->query("SELECT document_id FROM messages WHERE client_id=$xc AND sender='staff' ORDER BY id DESC LIMIT 1")->fetchColumn();
check((int)$last===$sd && (int)$db->query("SELECT portal_shared FROM client_documents WHERE id=$sd")->fetchColumn()===1,'A staff reply carries a document, which is then shared on the client\'s portal'.($err?": $err":''));
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,$html,$err]=$renderPage('portal',['t'=>$xt]);
putenv('CMS_TEST_ANON');
check(str_contains($html,'Synthetic assessment'),'The shared document is on the client\'s portal'.($err?": $err":''));

// ---- Sharing with the VR counselor ----
[$code,,$err]=$renderPage('client',['id'=>(string)$xc,'tab'=>'documents'],['share_doc'=>'counselor:1','doc_id'=>(string)$sd]);
check((int)$db->query("SELECT counselor_shared FROM client_documents WHERE id=$sd")->fetchColumn()===1,'Staff share a document with the counselor'.($err?": $err":''));
$vt=bin2hex(random_bytes(24));
$db->prepare("INSERT INTO vrc_tokens(counselor_email,counselor_name,token,expires_at) VALUES('exchange-vrc@example.invalid','Exchange Counselor',?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$vt]); $vid=(int)$db->lastInsertId();
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION='.json_encode(['vrc_ok_'.$vid=>true,'vrc_ok_'.$vid.'_at'=>time()]));
[$code,$html,$err]=$renderPage('vrc',['t'=>$vt]);
check(str_contains($html,'Shared files') && str_contains($html,'doc='.$sd),'The counselor sees the shared file'.($err?": $err":''));
[$code,$out,$err]=$renderPage('vrc',['t'=>$vt,'doc'=>(string)$sd]);
check(str_starts_with($out,'%PDF'),'The counselor downloads it'.($err?": $err":''));
[$code,$out,$err]=$renderPage('vrc',['t'=>$vt,'doc'=>(string)$xd[0]['id']]);
check(!str_starts_with($out,'%PDF'),'A file not shared with the counselor is not given to them');
putenv('CMS_TEST_FILES='.json_encode(['msg_file'=>$pdf]));
[$code,,$err]=$renderPage('vrc',['t'=>$vt],['send_message'=>'1','about_client'=>(string)$xc,'message'=>'Signed authorization attached']);
putenv('CMS_TEST_FILES'); putenv('CMS_TEST_ANON');
$cm=$db->query("SELECT m.document_id, d.uploaded_via, d.counselor_shared, d.category FROM messages m JOIN client_documents d ON d.id=m.document_id WHERE m.client_id=$xc AND m.channel='counselor'")->fetch();
check($cm && $cm['uploaded_via']==='counselor' && (int)$cm['counselor_shared']===1 && $cm['category']==='From VR counselor','The counselor sends a file about a client'.($err?": $err":''));

// ---- Technology outcomes at closure ----
$db->prepare("INSERT INTO device_requests(client_id,device_name,status,delivered_at) VALUES(?,'Synthetic screen reader','Delivered',CURDATE()),(?,'Synthetic magnifier','Requested',NULL)")->execute([$xc,$xc]);
check(client_device_counts($xc)===[2,1],'The closure counts start from the device requests');
[$code,$html,$err]=$renderPage('client_edit',['id'=>(string)$xc]);
check(str_contains($html,'id="closure_at_recommended" name="closure_at_recommended" maxlength="2" value="2"'),'The closure form offers the device counts'.($err?": $err":''));
$before=outcome_measures(date('Y-m-d'),date('Y-m-d'));
[$code,,$err]=$renderPage('client_edit',['id'=>(string)$xc],['name'=>'Exchange Synthetic Client','status'=>'Closed - Rehabilitated','client_type'=>'VR','state'=>'IA','closure_reason'=>'Employed','closure_employed'=>'yes','closure_wage'=>'20','closure_at_recommended'=>'2','closure_at_acquired'=>'1','closure_at_in_use'=>'yes','closure_at_note'=>'Magnifier on order']);
$k=client_last_closure($xc);
check($k && (int)$k['at_recommended']===2 && (int)$k['at_acquired']===1 && (int)$k['at_in_use']===1 && $k['at_note']==='Magnifier on order','Closing a case records the technology recommended, acquired and in use'.($err?": $err":''));
$after=outcome_measures(date('Y-m-d'),date('Y-m-d'));
check($after['at_recommended']-$before['at_recommended']===2 && $after['at_acquired']-$before['at_acquired']===1 && $after['closures']-$before['closures']===1,'The outcome report counts them');
[$code,,$err]=$renderPage('client_edit',['id'=>(string)$xc],['name'=>'Exchange Synthetic Client','status'=>'Active Training','client_type'=>'VR','state'=>'IA']);
check(closure_input(['closure_reason'=>'Employed','closure_at_recommended'=>'many'])[1]['closure_at_recommended'] ?? false,'A device count must be a whole number');
[$code,$csv,$err]=$renderPage('rsa_report',['from'=>date('Y-m-01'),'to'=>date('Y-m-d'),'export'=>'1']);
check(str_contains($csv,'Devices Recommended') && str_contains($csv,'Hourly Wage'),'The RSA-911 download has the closure details'.($err?": $err":''));

// ---- Custom fields ----
[$code,,$err]=$renderPage('custom_fields',[],['save_field'=>'1','field_id'=>'0','label'=>'Synthetic funding program','label_es'=>'Programa sintético','type'=>'choice','options'=>"Program A\nProgram B",'required'=>'1','on_referral'=>'1','sort_order'=>'1']);
[$code,,$err2]=$renderPage('custom_fields',[],['save_field'=>'1','field_id'=>'0','label'=>'Synthetic start of services','type'=>'date','sort_order'=>'2']);
[$code,,$err3]=$renderPage('custom_fields',[],['save_field'=>'1','field_id'=>'0','label'=>'Bad choice','type'=>'choice','options'=>'Only one']);
custom_fields_all(false,true);
$cfA=(int)$db->query("SELECT id FROM custom_fields WHERE label='Synthetic funding program'")->fetchColumn();
$cfB=(int)$db->query("SELECT id FROM custom_fields WHERE label='Synthetic start of services'")->fetchColumn();
check($cfA && $cfB && !$db->query("SELECT id FROM custom_fields WHERE label='Bad choice'")->fetchColumn(),'Administrators build fields; a list needs two choices'.($err.$err2.$err3?": $err $err2 $err3":''));
$edit=['name'=>'Exchange Synthetic Client','status'=>'Active Training','client_type'=>'VR','state'=>'IA'];
[$code,$html,$err]=$renderPage('client_edit',['id'=>(string)$xc],$edit+['cf_'.$cfA=>'','cf_'.$cfB=>'2026-01-05']);
check(str_contains($html,'Synthetic funding program: this is required.') && !$db->query("SELECT 1 FROM custom_values WHERE client_id=$xc")->fetchColumn(),'A required field must be answered'.($err?": $err":''));
[$code,,$err]=$renderPage('client_edit',['id'=>(string)$xc],$edit+['cf_'.$cfA=>'Program B','cf_'.$cfB=>'2026-01-05']);
check(custom_values_for($xc)===[$cfA=>'Program B',$cfB=>'2026-01-05'],'Answers are saved with the client'.($err?": $err":''));
[$code,$html,$err]=$renderPage('client',['id'=>(string)$xc]);
check(str_contains($html,'<dt>Synthetic funding program</dt><dd>Program B</dd>'),'The client record shows them'.($err?": $err":''));
$r=$call('client',[],['id'=>$xc]);
check(in_array(['label'=>'Synthetic funding program','value'=>'Program B'],$r['client']['custom_fields'],true),'The apps get them');
$call('save_client',['id'=>$xc,'name'=>'Exchange Synthetic Client','status'=>'Active Training']);
check(custom_values_for($xc)[$cfA]==='Program B','An app that does not send them keeps them');
// On the public referral form, in Spanish too, carried to the client on conversion.
$db->exec("DELETE FROM login_attempts WHERE email='referral-portal'"); // earlier tests' submissions from this machine
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,$html,$err]=$renderPage('community_referral',['lang'=>'es']);
check(str_contains($html,'Programa sintético') && str_contains($html,'Algunas preguntas más') && !str_contains($html,'Synthetic start of services'),'Only the fields chosen are asked on the referral form, in Spanish there'.($err?": $err":''));
$refPost=['role'=>'counselor','referrer_name'=>'Synthetic Referrer','referrer_phone'=>'555-0100','referrer_email'=>'referrer@example.invalid','organization'=>'Synthetic Agency',
    'client_name'=>'Custom Synthetic Referral','body'=>'Synthetic referral.','referral_purpose'=>'Additional service','requested_services'=>['Assistive technology training'],'specific_needs'=>['Screen readers']];
[$code,$html,$err]=$renderPage('community_referral',[],$refPost+['cf_'.$cfA=>'Program C']);
check(!$db->query("SELECT id FROM referrals WHERE client_name='Custom Synthetic Referral'")->fetchColumn() && str_contains($html,'href="#cf_'.$cfA.'"'),'A choice not on the list is refused, linked from the error list'.($err?": $err":''));
[$code,,$err]=$renderPage('community_referral',[],$refPost+['cf_'.$cfA=>'Program A']);
putenv('CMS_TEST_ANON');
$cr=(int)$db->query("SELECT id FROM referrals WHERE client_name='Custom Synthetic Referral'")->fetchColumn();
check($cr && custom_values_for(null,$cr)===[$cfA=>'Program A'],'The referral keeps the answer'.($err?": $err":''));
[$code,$html,$err]=$renderPage('referrals',['view'=>(string)$cr]);
check(str_contains($html,'<dt>Synthetic funding program</dt><dd>Program A</dd>'),'Staff see it on the referral'.($err?": $err":''));
$call('convert_referral',['id'=>$cr]);
$crc=(int)$db->query("SELECT client_id FROM referrals WHERE id=$cr")->fetchColumn();
check(custom_values_for($crc)===[$cfA=>'Program A'],'Accepting the referral carries the answer to the client');
// Merging keeps the kept record's own answers and fills in the rest.
$db->beginTransaction(); move_client_records($db,$xc,$crc); $db->commit();
check(custom_values_for($xc)===[$cfA=>'Program B',$cfB=>'2026-01-05'] && custom_values_for($crc)===[],'Merging keeps the kept record\'s answers');
// Review fixes: a renamed option keeps its stored answer; staff files that are legitimately other shapes are
// accepted for staff only; sharing twice is not an error; the RSA columns describe the period's own closure.
$db->prepare("UPDATE custom_fields SET options=? WHERE id=?")->execute(["Program A (2027)\nProgram C",$cfA]); custom_fields_all(false,true);
[$code,$html,$err]=$renderPage('client_edit',['id'=>(string)$xc]);
check(str_contains($html,'<option value="Program B" selected>Program B (no longer on the list)</option>'),'An answer whose option was renamed is kept and said so'.($err?": $err":''));
[$code,,$err]=$renderPage('client_edit',['id'=>(string)$xc],$edit+['cf_'.$cfA=>'Program B','cf_'.$cfB=>'2026-01-05']);
check(custom_values_for($xc)[$cfA]==='Program B','Saving the client keeps it'.($err?": $err":''));
$u16=$tmp.'/unicode.txt'; file_put_contents($u16,"\xFF\xFEH\0i\0");
$xlsHtml=$tmp.'/export.xls'; file_put_contents($xlsHtml,"<html><table><tr><td>1</td></tr></table></html>");
check(upload_content_ok($u16,'txt',true) && !upload_content_ok($u16,'txt') && upload_content_ok($xlsHtml,'xls',true) && !upload_content_ok($xlsHtml,'xls'),'Staff exports in other shapes are accepted from staff only');
check(document_set_shared($sd,$xc,'portal',true,1) && document_set_shared($sd,$xc,'portal',true,1),'Sharing a document already shared is not an error');
check(client_closure_at($xc,date('Y-m-d H:i:s',strtotime('-3 days')))===null && (int)(client_closure_at($xc,date('Y-m-d H:i:s'))['at_recommended'] ?? 0)===2,'The closure details are those of the closure at that time');
