<?php
// Included by full_schema.php after v414_cases.php, against its synthetic database only:
// 4.15.0's staff agreements, which staff read and sign themselves.
$aid=(int)$db->query("SELECT id FROM staff_agreements WHERE counts_as_confidentiality=1")->fetchColumn();
$me=(string)$db->query("SELECT name FROM users WHERE id=1")->fetchColumn();
check($aid>0 && staff_agreement_status(1)===[],'A starting confidentiality agreement is there to edit, and nobody is asked to sign it while it is off');
[$code,,$err]=$renderPage('staff_agreements',[],['save_agreement'=>'1','agreement_id'=>(string)$aid,'title'=>'Confidentiality agreement','body'=>(string)$db->query("SELECT body FROM staff_agreements WHERE id=$aid")->fetchColumn(),'active'=>'1','counts_as_confidentiality'=>'1']);
check(staff_agreement_status(1)===[],'The starting text cannot be turned on before it is edited');
// The administrator turns it on, with their own text.
[$code,,$err]=$renderPage('staff_agreements',[],['save_agreement'=>'1','agreement_id'=>(string)$aid,'title'=>'Confidentiality agreement','body'=>"# Client information\n- I keep client information private.",'active'=>'1','counts_as_confidentiality'=>'1']);
$st=staff_agreement_status(1);
$v=(int)$db->query("SELECT version FROM staff_agreements WHERE id=$aid")->fetchColumn();
$hash=fn()=>staff_agreement_hash(...$db->query("SELECT title, body FROM staff_agreements WHERE id=$aid")->fetch(PDO::FETCH_NUM));
check(count($st)===1 && $st[0]['state']==='needed' && staff_agreements_needed(1)===1,'Once on, a staff member is asked to sign it' . ($err ? ": $err" : ''));
[$code,$html,$err]=$renderPage('index',[]);
check(str_contains($html,'An agreement to sign') && str_contains($html,'agreements.php'),'The Home page says there is an agreement to sign');
[$code,$html,$err]=$renderPage('agreements',['id'=>(string)$aid]);
check(str_contains($html,'<h3>Client information</h3>') && str_contains($html,'<li>I keep client information private.</li>') && str_contains($html,'Type your full name to sign'),'The agreement reads with its headings and list, and asks for the name' . ($err ? ": $err" : ''));
// Signing: the name must be the account's, and the box ticked.
$db->exec("UPDATE users SET confidentiality_signed_on=NULL WHERE id=1");
[$code,,$err]=$renderPage('agreements',[],['sign_agreement'=>'1','agreement_id'=>(string)$aid,'version'=>(string)$v,'text_hash'=>$hash(),'signed_name'=>$me]);
check(staff_agreements_needed(1)===1,'Without ticking that it was read, nothing is signed');
[$code,,$err]=$renderPage('agreements',[],['sign_agreement'=>'1','agreement_id'=>(string)$aid,'version'=>(string)$v,'text_hash'=>$hash(),'agree'=>'1','signed_name'=>'Someone Else']);
check(staff_agreements_needed(1)===1,'Another name is refused');
[$code,,$err]=$renderPage('agreements',[],['sign_agreement'=>'1','agreement_id'=>(string)$aid,'version'=>(string)$v,'text_hash'=>$hash(),'agree'=>'1','signed_name'=>' '.mb_strtolower($me).' ']);
$sig=$db->query("SELECT * FROM staff_agreement_signatures WHERE user_id=1 ORDER BY id DESC LIMIT 1")->fetch();
check(staff_agreements_needed(1)===0 && $sig && $sig['body']==="# Client information\n- I keep client information private." && $sig['via']==='website'
    && (string)$db->query("SELECT confidentiality_signed_on FROM users WHERE id=1")->fetchColumn()===date('Y-m-d'),'The account name signs it (case and spaces aside), keeps the exact text, and records the confidentiality date' . ($err ? ": $err" : ''));
check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='agreement.sign'")->fetchColumn()===1,'Signing is in the audit log');
[$code,$html,$err]=$renderPage('agreements',['copy'=>(string)$sig['id']]);
check(str_contains($html,'your signed copy') && str_contains($html,'I keep client information private.'),'The signed copy can be read');
$db->exec("UPDATE staff_agreement_signatures SET signed_at = DATE_SUB(signed_at, INTERVAL 1 MINUTE)"); // signed before the correction below
// A small correction asks nobody again; a real change does.
[$code,,$err]=$renderPage('staff_agreements',[],['save_agreement'=>'1','agreement_id'=>(string)$aid,'title'=>'Confidentiality agreement','body'=>"# Client information\n- I keep client information private!",'active'=>'1','counts_as_confidentiality'=>'1','small_correction'=>'1']);
check(staff_agreements_needed(1)===0,'A small correction does not ask anyone to sign again');
check((int)$db->query("SELECT COUNT(*) FROM staff_agreement_revisions WHERE agreement_id=$aid AND small_correction=1")->fetchColumn()===1,'A small correction is kept in the history, with the text before and after');
[$code,$html,$err]=$renderPage('agreements',['copy'=>(string)$sig['id']]);
check(str_contains($html,'Small corrections were made') && str_contains($html,'I keep client information private.'),'A signed copy says a correction came later, and still shows what was signed');
[$code,,$err]=$renderPage('staff_agreements',[],['save_agreement'=>'1','agreement_id'=>(string)$aid,'title'=>'Confidentiality agreement','body'=>"# Client information\n- I keep client information private!\n- A whole new promise.",'active'=>'1','counts_as_confidentiality'=>'1','small_correction'=>'1']);
check(!str_contains((string)$db->query("SELECT body FROM staff_agreements WHERE id=$aid")->fetchColumn(),'A whole new promise'),'A change bigger than a correction is refused as a correction');
[$x,$e]=staff_agreement_sign(1,$aid,$me,$v,'website',staff_agreement_hash('Confidentiality agreement',"# Client information\n- I keep client information private."));
check($x===null && str_contains((string)$e,'changed while you had it open'),'Signing text that was corrected while it was open is refused');
[$code,,$err]=$renderPage('staff_agreements',[],['save_agreement'=>'1','agreement_id'=>(string)$aid,'title'=>'Confidentiality agreement','body'=>"# Client information\n- I keep client information private.\n- I lock my phone.",'active'=>'1','counts_as_confidentiality'=>'1']);
$st=staff_agreement_status(1);
check($st[0]['state']==='needed' && $st[0]['why']==='The text changed since you signed it' && (int)$st[0]['version']===$v+1,'Changing the text asks everyone to sign again');
check($db->query("SELECT confidentiality_signed_on FROM users WHERE id=1")->fetchColumn()===null,'A new version of the confidentiality agreement clears the date the reminders follow');
// A version changed while the page was open is not signed.
[$sid,$e]=staff_agreement_sign(1,$aid,$me,$v,'website',$hash());
check($sid===null && str_contains((string)$e,'changed while you had it open'),'Signing an older version than the one now asked for is refused');
// The phone apps.
$r=$call('agreements');
check($r['needed']===1 && $r['agreements'][0]['version']===$v+1 && str_contains($r['agreements'][0]['body'],'I lock my phone'),'The apps get the agreement to sign');
putenv('CMS_TEST_APP_VERSION=4.15.0 (4) Android');
$call('sign_agreement',['id'=>$aid,'version'=>$v+1,'signed_name'=>$me,'text_hash'=>$hash()],[],false,'Confirm that you have read');
$call('sign_agreement',['id'=>$aid,'version'=>$v+1,'agree'=>true,'signed_name'=>$me,'text_hash'=>'0'],[],false,'changed while you had it open');
$r=$call('sign_agreement',['id'=>$aid,'version'=>$v+1,'agree'=>true,'signed_name'=>$me,'text_hash'=>$r['agreements'][0]['text_hash']]);
putenv('CMS_TEST_APP_VERSION');
check(staff_agreements_needed(1)===0 && $db->query("SELECT via FROM staff_agreement_signatures WHERE id=".(int)$r['signature_id'])->fetchColumn()==='Android app','An agreement can be signed in the apps, which is recorded');
$copy=$call('agreement_copy',[],['id'=>(string)$r['signature_id']]);
check(str_contains($copy['copy']['body'],'I lock my phone'),'The apps can read a signed copy');
[$code,$html,$err]=$renderPage('staff_agreements',[]);
check(str_contains($html,'1 of ') && str_contains($html,'Signed copy'),'The administrator sees who has signed' . ($err ? ": $err" : ''));
$row=$db->query("SELECT * FROM users WHERE id=1")->fetch();
[$code,,$err]=$renderPage('users',[],['save_user'=>'1','id'=>'1','user_id'=>'1','name'=>$row['name'],'email'=>$row['email'],'role'=>$row['role'],'group_id'=>(string)($row['group_id'] ?? ''),'weekly_hours'=>'20','is_active'=>'1','confidentiality_signed_on'=>'2020-01-01','privacy_training_on'=>'']);
check((string)$db->query("SELECT confidentiality_signed_on FROM users WHERE id=1")->fetchColumn()!=='2020-01-01','A typed confidentiality date is ignored while staff sign the agreement themselves');
// Compliance names someone who has not signed.
$db->exec("INSERT INTO users (id,name,email,password_hash,role) VALUES (61,'Agreement Synthetic Staff','agreement-staff@example.invalid','test','instructor')");
$notes=array_column(array_filter(staff_compliance_list(),fn($u)=>(int)$u['id']===61),'notes');
check($notes && in_array('Confidentiality agreement: not signed yet',$notes[0],true),'Compliance names staff who still need to sign');
$db->exec("DELETE FROM users WHERE id=61");
$db->exec("UPDATE staff_agreements SET active=0");
