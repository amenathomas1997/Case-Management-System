<?php
// Included by full_schema.php after v424_cases.php, against its synthetic database only:
// 4.25.0: the apps' new actions, signing in person, referral deadlines and the agency scorecard, and
// virus scanning of uploads (the scanner is answered by CMS_TEST_VIRUS).

// ---- The apps: client details, custom fields, offices, closure counts ----
$db->exec("INSERT INTO clients(name,status,email,email_verified,assigned_to,state) VALUES('App Synthetic Client','Active Training','app-client@example.invalid',1,1,'IA')"); $ac=(int)$db->lastInsertId();
$r=$call('client',[],['id'=>$ac]);
$cfIds=array_column($r['client']['custom_fields_edit'],'id');
check(in_array($cfA,$cfIds,true) && $r['client']['custom_fields_edit'][array_search($cfA,$cfIds)]['type']==='choice' && is_array($r['client']['offices']) && isset($r['client']['device_counts']['recommended']),'The apps get the fields to edit, the offices and the device counts');
$call('save_client',['id'=>$ac,'name'=>'App Synthetic Client','status'=>'Active Training','custom'=>[(string)$cfA=>'Program A (2027)',(string)$cfB=>'2026-02-03']]);
check(custom_values_for($ac)===[$cfA=>'Program A (2027)',$cfB=>'2026-02-03'],'The apps save custom fields');
$call('save_client',['id'=>$ac,'name'=>'App Synthetic Client','status'=>'Active Training','custom'=>[(string)$cfA=>'Not a program']],[],false,'choose one from the list');
$call('save_client',['id'=>$ac,'name'=>'App Synthetic Client','status'=>'Closed - Rehabilitated','closure_reason'=>'Employed','closure_at_recommended'=>'3','closure_at_acquired'=>'3','closure_at_in_use'=>'yes']);
$k=client_last_closure($ac);
check($k && (int)$k['at_recommended']===3 && (int)$k['at_in_use']===1,'The apps record the technology at closure');
$call('save_client',['id'=>$ac,'name'=>'App Synthetic Client','status'=>'Active Training']);

// ---- The apps: files with messages, and sharing ----
$call('post_message',['client_id'=>$ac,'channel'=>'client','body'=>'','file_b64'=>base64_encode((string)file_get_contents($pdf)),'filename'=>'plan.pdf']);
$am=$db->query("SELECT m.document_id, d.portal_shared, d.uploaded_via FROM messages m JOIN client_documents d ON d.id=m.document_id WHERE m.client_id=$ac")->fetch();
check($am && (int)$am['portal_shared']===1 && $am['uploaded_via']==='staff','The apps send a file with a message, shared on the client\'s portal');
$call('post_message',['client_id'=>$ac,'channel'=>'client','body'=>'x','file_b64'=>base64_encode('<html>not a pdf</html>'),'filename'=>'fake.pdf'],[],false,'not really');
$t=$call('message_thread',[],['client_id'=>$ac,'channel'=>'client']);
check((int)$t['messages'][0]['document_id']===(int)$am['document_id'] && $t['messages'][0]['document_title']==='plan.pdf','The apps see the file on the message');
$docs=$call('documents',[],['client_id'=>$ac])['documents'];
check($docs[0]['portal_shared']===true && $docs[0]['counselor_shared']===false,'The document list says what is shared');
$sh=$call('share_document',['client_id'=>$ac,'document_id'=>(int)$am['document_id'],'with'=>'counselor','on'=>true]);
check($sh['counselor_shared']===true,'The apps share a document with the counselor');
$call('share_document',['client_id'=>$ac,'document_id'=>999999,'with'=>'portal','on'=>true],[],false,'no longer');

// ---- Signing in person ----
$st=$call('start_signature',['client_id'=>$ac,'kind'=>'services','signer_role'=>'Client','signer_name'=>'App Synthetic Client']);
check($st['id']>0 && str_contains($st['body'],'You can stop services') && $st['signer_name']==='App Synthetic Client','Signing in person starts without an email');
$call('sign_in_person',['client_id'=>$ac,'id'=>$st['id'],'typed_name'=>'Somebody Else'],[],false,'does not match');
$png="\x89PNG\r\n\x1a\n".str_repeat("\0",32);
$done=$call('sign_in_person',['client_id'=>$ac,'id'=>$st['id'],'typed_name'=>'app synthetic client','signature_b64'=>base64_encode($png)]);
$row=$db->query('SELECT * FROM client_signatures WHERE id='.(int)$st['id'])->fetch();
check($row['signed_at']!==null && $row['method']==='In person' && is_file($tmp.'/'.$row['signature_path']) && $done['signatures'][0]['status']==='Signed' && $done['signatures'][0]['has_drawn_signature']===true,'The client signs in person with a typed name and a drawn signature');
check(!empty($call('sign_in_person',['client_id'=>$ac,'id'=>$st['id'],'typed_name'=>'App Synthetic Client'])['repeat']),'Signing it again (a retry) is recorded once');
$call('send_signature',['client_id'=>$ac,'kind'=>'services','signer_role'=>'Client','signer_name'=>'App Synthetic Client','signer_email'=>''],[],false,'email');
// On the website too.
[$code,,$err]=$renderPage('signatures',['client'=>(string)$ac],['sign_here'=>'1','kind'=>'document','signer_role'=>'Client','signer_name'=>'App Synthetic Client','signer_email'=>'','title'=>'Synthetic photo consent','body'=>'You may take photos of me using my devices.']);
$ws=$db->query("SELECT * FROM client_signatures WHERE client_id=$ac AND title='Synthetic photo consent'")->fetch();
check($ws && $ws['method']==='In person' && $ws['signed_at']===null,'Staff start signing in person on the website'.($err?": $err":''));
[$code,$html,$err]=$renderPage('signatures',['client'=>(string)$ac,'sign'=>(string)$ws['id']]);
check(str_contains($html,'You may take photos of me') && str_contains($html,'name="sign_now"'),'The page to hand over shows the document and the signature field'.($err?": $err":''));
[$code,,$err]=$renderPage('signatures',['client'=>(string)$ac],['sign_now'=>(string)$ws['id'],'signature'=>'App Synthetic Client']);
check($db->query('SELECT signed_name FROM client_signatures WHERE id='.(int)$ws['id'])->fetchColumn()==='App Synthetic Client','Typing the name there signs it'.($err?": $err":''));

// ---- Release of information from the apps ----
$ch=$call('release_choices',[]);
$call('request_release',['client_id'=>$ac,'signer_role'=>'Client','signer_name'=>'App Synthetic Client','signer_email'=>'app-client@example.invalid','recipient_type'=>$ch['recipient_types'][0],'recipient_name'=>'Synthetic Recipient','info'=>[$ch['info_items'][0]],'purpose'=>'To coordinate services']);
check((bool)$db->query("SELECT 1 FROM client_releases WHERE client_id=$ac AND recipient_name='Synthetic Recipient'")->fetchColumn(),'The apps request a release of information');
$call('request_release',['client_id'=>$ac,'signer_role'=>'Client','signer_name'=>'App Synthetic Client','signer_email'=>'app-client@example.invalid','recipient_type'=>'Nobody','recipient_name'=>'X','info'=>[],'purpose'=>''],[],false,'Choose who');

// ---- Referral deadlines and the agency scorecard ----
$rd=referral_deadline_report(date('Y-m-d',strtotime('-1 day')),date('Y-m-d'));
$mine=array_values(array_filter($rd['rows'],fn($x)=>$x['client_name']==='Deadline Synthetic Referral'));
check($rd['referrals']>0 && $mine && $mine[0]['agency']==='Synthetic Deadline Agency' && $mine[0]['contact']==='on_time' && $mine[0]['assess']==='waiting','Each referral is judged against its agency\'s deadline');
$db->exec("UPDATE tasks SET status='Done', done_at=DATE_ADD(due_date, INTERVAL 1 DAY) WHERE auto_key='ref-contact-".(int)$mine[0]['id']."'");
$rd2=referral_deadline_report(date('Y-m-d',strtotime('-1 day')),date('Y-m-d'));
check($rd2['contacted_late']-$rd['contacted_late']===1,'A contact after the deadline counts as late');
[$code,$html,$err]=$renderPage('referral_deadlines',['from'=>date('Y-m-d',strtotime('-1 day')),'to'=>date('Y-m-d')]);
check(str_contains($html,'Synthetic Deadline Agency') && str_contains($html,'Late or overdue'),'The referral deadlines report shows it'.($err?": $err":''));
$api=$call('referral_deadlines',[]);
check(isset($api['contacted_on_time']) && !isset($api['rows']),'The apps get the totals only');
[$code,$html,$err]=$renderPage('agency_scorecard',['agency'=>'Synthetic Deadline Agency','from'=>date('Y-m-01'),'to'=>date('Y-m-d')]);
check(str_contains($html,'Agency scorecard: Synthetic Deadline Agency') && str_contains($html,'Contacted on time') && str_contains($html,'In its portal'),'The agency scorecard brings the agency together on one page'.($err?": $err":''));
[$code,$csv,$err]=$renderPage('agency_scorecard',['agency'=>'Synthetic Deadline Agency','from'=>date('Y-m-01'),'to'=>date('Y-m-d'),'download'=>'1']);
check(str_contains($csv,'Billing') && str_contains($csv,'Outcomes at closure'),'The scorecard downloads as a spreadsheet'.($err?": $err":''));

// ---- Virus scanning ----
check(virus_scan_target()===null && virus_scan_health()[0]===true,'Virus scanning is off until it is turned on');
putenv('CMS_TEST_VIRUS=infected');
check(str_contains((string)upload_virus_check($pdf),'harmful'),'An infected file is refused');
$call('post_message',['client_id'=>$ac,'channel'=>'client','body'=>'y','file_b64'=>base64_encode((string)file_get_contents($pdf)),'filename'=>'again.pdf'],[],false,'harmful');
putenv('CMS_TEST_VIRUS=error');
check(str_contains((string)upload_virus_check($pdf),'could not be checked'),'A file that cannot be checked is refused, not let through');
putenv('CMS_TEST_VIRUS=clean');
check(upload_virus_check($pdf)===null,'A clean file is kept');
putenv('CMS_TEST_VIRUS');

// ---- Review fixes ----
// A client created in the apps with custom answers still starts in the creator's office.
$db->exec("UPDATE users SET office_id=$south WHERE id=1");
$nc=$call('save_client',['id'=>0,'name'=>'Office App Synthetic Client','status'=>'Pending Assessment','state'=>'IA','custom'=>[(string)$cfA=>'Program C']]);
check((int)$db->query("SELECT office_id FROM clients WHERE name='Office App Synthetic Client'")->fetchColumn()===$south,'A client added in the apps with custom answers starts in the creator\'s office');
$db->exec('UPDATE users SET office_id=NULL WHERE id=1');
// Signing in person signs only a document started for it; it is recorded who handed the device over.
[$code,,$err]=$renderPage('signatures',['client'=>(string)$ac],['send_signature'=>'1','kind'=>'services','signer_role'=>'Client','signer_name'=>'App Synthetic Client','signer_email'=>'app-client@example.invalid']);
$em=(int)$db->query("SELECT id FROM client_signatures WHERE client_id=$ac AND method='Email' ORDER BY id DESC LIMIT 1")->fetchColumn();
$call('sign_in_person',['client_id'=>$ac,'id'=>$em,'typed_name'=>'App Synthetic Client'],[],false,'sent by email');
check($db->query("SELECT signed_at FROM client_signatures WHERE id=$em")->fetchColumn()===null,'A document sent by email cannot be signed in person');
$a1=$call('start_signature',['client_id'=>$ac,'kind'=>'services','signer_role'=>'Client','signer_name'=>'App Synthetic Client']);
$a2=$call('start_signature',['client_id'=>$ac,'kind'=>'services','signer_role'=>'Client','signer_name'=>'App Synthetic Client']);
check($a1['id']===$a2['id'],'Starting the same document again reuses it');
$call('sign_in_person',['client_id'=>$ac,'id'=>$a1['id'],'typed_name'=>'App Synthetic Client']);
check((bool)$db->query("SELECT 1 FROM audit_log WHERE action='signature.signed_in_person' AND entity_id=$ac")->fetchColumn() && str_contains((string)$db->query("SELECT body FROM client_notes WHERE client_id=$ac ORDER BY id DESC LIMIT 1")->fetchColumn(),'Signed in person on the device of'),'Who handed the device over is recorded');
// On the website, a device handed over for signing opens nothing else.
$w=$call('start_signature',['client_id'=>$ac,'kind'=>'document','signer_role'=>'Client','signer_name'=>'App Synthetic Client','title'=>'Synthetic kiosk','body'=>'Synthetic words.']);
putenv('CMS_TEST_SESSION_ADD='.json_encode(['kiosk_sign'=>['client'=>$ac,'id'=>$w['id'],'lang'=>'']]));
[$code,$html,$err]=$renderPage('signatures',['client'=>(string)$ac,'sign'=>(string)$w['id']]);
check(str_contains($html,'Synthetic words.') && !str_contains($html,'id="main-nav"') && !str_contains($html,'client.php?id='),'The page to hand over has no staff menu or links'.($err?": $err":''));
[$code,$html,$err]=$renderPage('clients',[]);
putenv('CMS_TEST_SESSION_ADD');
check(!str_contains($html,'App Synthetic Client'),'Any other page signs the staff member out while the device is handed over'.($err?": $err":''));
// A referral closed without service is not counted as contacted.
$db->exec("INSERT INTO referrals(client_name,reason,subject,body,status,counselor_name,counselor_agency,counselor_email,counselor_phone) VALUES('Closed Synthetic Referral','x','x','x','Closed','C','Synthetic Deadline Agency','c@example.invalid','')"); $crf=(int)$db->lastInsertId();
add_task('Contact new referral: Closed', null, null, $crf, date('Y-m-d'), null, null, 'ref-contact-' . $crf);
$db->exec("UPDATE tasks SET status='Done', done_at=NOW() WHERE auto_key='ref-contact-$crf'");
$row=array_values(array_filter(referral_deadline_report(date('Y-m-d'),date('Y-m-d'))['rows'],fn($x)=>$x['id']===$crf));
check($row && $row[0]['contact']==='closed','A referral closed without service is not counted as contacted on time');

// ---- The iPhone app's models decode these replies, with the right values ----
{ // as full_schema.php runs it for the other screens
    $fx = ['client425' => $call('client', [], ['id' => $ac]), 'message_thread425' => $call('message_thread', [], ['client_id' => $ac, 'channel' => 'client']),
        'documents425' => $call('documents', [], ['client_id' => $ac]), 'signatures425' => $call('signatures', [], ['client_id' => $ac]),
        'signature425' => $call('signature', [], ['client_id' => $ac, 'id' => $st['id']]), 'release_choices425' => $call('release_choices', []),
        'referral_deadlines425' => $call('referral_deadlines', []), 'form_options425' => $call('form_options', [])];
    $p = proc_open(['python3', $root . '/tests/ios_models.py'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($fx)); fclose($pipes[0]);
    echo stream_get_contents($pipes[1]); fclose($pipes[1]);
    $modelErr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if ($modelErr !== '') fwrite(STDERR, $modelErr);
    check(proc_close($p) === 0, 'The iPhone app decodes the 4.25.0 replies with the right values');
}
