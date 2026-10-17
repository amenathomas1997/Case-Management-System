<?php
// Included by full_schema.php after v415_cases.php, against its synthetic database only:
// 4.16.0: staff training with a quiz, holding back client records, credentials, the exit
// acknowledgement and the staff compliance report.
$mid=(int)$db->query("SELECT id FROM training_modules WHERE title='Privacy training'")->fetchColumn();
check($mid>0 && training_status(1)===[],'A starting privacy training is there to edit, and nobody is asked to take it while it is off');
[$qs,$e]=training_parse_questions("? First?\n- no\n* yes\n? Second?\n* a\n- b");
check($e===null && count($qs)===2 && $qs[0]['correct']===1 && $qs[1]['correct']===0,'The quiz is read from ? questions, * right and - wrong answers');
check(training_parse_questions("? Q?\n- a\n- b")[1]!==null && training_parse_questions("? Q?\n* a\n* b")[1]!==null && training_parse_questions("- a")[1]!==null,'A question with no right answer, two right answers, or an answer before its question is refused');
// Editing the training while it is off leaves everyone's recorded dates alone.
$db->exec("UPDATE users SET privacy_training_on='2026-01-15' WHERE id=1");
[$code,,$err]=$renderPage('staff_training',[],['save_module'=>'1','module_id'=>(string)$mid,'title'=>'Privacy training','body'=>"# Why\n- Clients trust us.",'questions'=>"? Q?\n* a\n- b",'pass_pct'=>'80','required'=>'1','counts_as_privacy'=>'1']);
check((string)$db->query("SELECT privacy_training_on FROM users WHERE id=1")->fetchColumn()==='2026-01-15','Editing a training that is off keeps everyone\'s training dates' . ($err ? ": $err" : ''));
// The administrator writes and turns it on.
$quiz="? When may you open a client's record?\n- Whenever you like\n* When your work needs it\n? A stranger asks about a client. What do you do?\n* Check for consent first\n- Tell them";
[$code,,$err]=$renderPage('staff_training',[],['save_module'=>'1','module_id'=>(string)$mid,'title'=>'Privacy training','body'=>"# Why\n- Clients trust us.",'questions'=>$quiz,'pass_pct'=>'100','required'=>'1','counts_as_privacy'=>'1','active'=>'1']);
$st=training_status(1);
check(count($st)===1 && $st[0]['state']==='needed' && training_needed(1)===1,'Once on, staff are asked to take it' . ($err ? ": $err" : ''));
$m=$db->query("SELECT * FROM training_modules WHERE id=$mid")->fetch();
[$code,$html,$err]=$renderPage('training',['id'=>(string)$mid]);
check(str_contains($html,'<legend>1. When may you open') && !str_contains($html,'"correct"'),'The reading and questions show, with no sign of the right answers' . ($err ? ": $err" : ''));
// Wrong answers are kept and do not pass; right ones pass and record the date.
$db->exec("UPDATE users SET privacy_training_on=NULL WHERE id=1");
[$code,,$err]=$renderPage('training',[],['submit_training'=>'1','module_id'=>(string)$mid,'version'=>(string)$m['version'],'text_hash'=>training_hash($m),'answer'=>['0','0']]);
check(training_needed(1)===1 && (int)$db->query("SELECT COUNT(*) FROM training_completions WHERE user_id=1 AND passed=0")->fetchColumn()===1,'A failed attempt is kept and does not count');
[$code,,$err]=$renderPage('training',[],['submit_training'=>'1','module_id'=>(string)$mid,'version'=>(string)$m['version'],'text_hash'=>training_hash($m),'answer'=>['1','0']]);
check(training_needed(1)===0 && (string)$db->query("SELECT privacy_training_on FROM users WHERE id=1")->fetchColumn()===date('Y-m-d'),'Passing records the privacy training date');
$r=training_submit(1,$mid,(int)$m['version'],'0',[1,0],'website');
check($r['error']!==null && $r['changed'],'Answers to a training that changed while it was open are refused');
// The apps get the questions without the answers, and can submit.
$t=$call('training');
check(isset($t['training'][0]['questions'][0]['options']) && !str_contains(json_encode($t),'"correct"'),'The apps get the questions, never the right answers');
$res=$call('submit_training',['id'=>$mid,'version'=>(int)$m['version'],'text_hash'=>$t['training'][0]['text_hash'],'answers'=>[1,0]]);
check($res['passed']===true && $res['score']===2,'The apps can take the training');

// Holding back client records: a new staff member who has not signed or passed.
set_setting('access_hold','new');
$db->exec("INSERT INTO users (id,name,email,password_hash,role,created_at) VALUES (71,'Hold Synthetic Staff','hold-staff@example.invalid','test','instructor',DATE_ADD(NOW(), INTERVAL 1 SECOND))");
$newRow=$db->query("SELECT * FROM users WHERE id=71")->fetch();
check(access_hold_reasons($newRow)===['Privacy training'],'A new staff member is held until the required training is passed');
$held=access_hold_apply($newRow, resolve_perms($newRow));
check(!in_array('clients',$held['perms'],true) && !in_array('schedule',$held['perms'],true),'While held, client areas are taken away');
$oldRow=$db->query("SELECT * FROM users WHERE id=1")->fetch();
check(access_hold_reasons($oldRow)===[],'Someone who has passed is not held');
// Held app users are told why.
$db->prepare("INSERT INTO api_tokens (user_id,token_hash,expires_at) VALUES (71,?,DATE_ADD(NOW(), INTERVAL 1 DAY))")->execute([hash('sha256', str_repeat('7',64))]);
putenv('CMS_TEST_TOKEN='.str_repeat('7',64));
$call('clients',[],[],false,'Client records open once you finish');
putenv('CMS_TEST_TOKEN');
// Turning on "everyone" asks first when it would hold someone now.
set_setting('access_hold','new');
[$code,,$err]=$renderPage('staff_training',[],['save_hold'=>'1','access_hold'=>'all','access_hold_days'=>'1']);
check(access_hold_mode()==='new','Holding everyone is not saved until the people it holds now are confirmed');
[$code,,$err]=$renderPage('staff_training',[],['save_hold'=>'1','access_hold'=>'all','access_hold_days'=>'0','confirm_hold'=>'1']);
check(access_hold_mode()==='new','Holding everyone needs at least a day to catch up');
set_setting('access_hold','off');
$GLOBALS['ACCESS_HOLD']=[];
$db->exec("DELETE FROM api_tokens WHERE user_id=71");
$db->exec("DELETE FROM users WHERE id=71");

// Credentials.
[$code,,$err]=$renderPage('credentials',[],['save_credential'=>'1','credential_id'=>'0','user_id'=>'1','type'=>'CPR / First Aid','issued_on'=>date('Y-m-d',strtotime('-700 days')),'expires_on'=>date('Y-m-d',strtotime('+20 days'))]);
$c=$db->query("SELECT * FROM staff_credentials WHERE user_id=1")->fetch();
check($c && $c['name']==='CPR / First Aid' && credential_state($c)[0]==='soon','A credential is kept with its expiry, and one expiring within 60 days is flagged' . ($err ? ": $err" : ''));
check(in_array('CPR / First Aid: ' . credential_state($c)[1], array_merge(...array_column(array_filter(staff_compliance_list(),fn($u)=>(int)$u['id']===1),'notes')), true),'Compliance names a credential expiring soon');
$db->exec("UPDATE staff_credentials SET notes='Card in the office' WHERE user_id=1");
$call('save_credential',['id'=>(int)$c['id'],'name'=>'CPR / First Aid','expires_on'=>date('Y-m-d',strtotime('+25 days'))]);
check((string)$db->query("SELECT notes FROM staff_credentials WHERE id=".(int)$c['id'])->fetchColumn()==='Card in the office','Saving from an app keeps notes the app does not show');
$own=$call('credentials');
check($own['credentials'][0]['name']==='CPR / First Aid','The apps show your own credentials');
$db->exec("INSERT INTO users (id,name,email,password_hash,role) VALUES (72,'Other Synthetic Staff','other-staff@example.invalid','test','instructor')");
$db->exec("INSERT INTO staff_credentials (user_id,name) VALUES (72,'Background check')");
$theirs=(int)$db->query("SELECT id FROM staff_credentials WHERE user_id=72")->fetchColumn();
$call('delete_credential',['id'=>$theirs],[],false,'not found');
check((int)$db->query("SELECT COUNT(*) FROM staff_credentials WHERE user_id=72")->fetchColumn()===1,'Someone cannot remove another person\'s credential from the apps');

// The exit acknowledgement.
$eid=(int)$db->query("SELECT id FROM staff_agreements WHERE kind='exit'")->fetchColumn();
$db->prepare("UPDATE staff_agreements SET active=1, body=? WHERE id=?")->execute(["# Before I leave\n- I returned my devices.", $eid]);
check(!array_filter(staff_agreement_status(72),fn($a)=>$a['kind']==='exit'),'The exit acknowledgement is not asked of staff who are not leaving');
[$x,$e]=staff_agreement_sign(72,$eid,'Other Synthetic Staff',1,'website',staff_agreement_hash(...$db->query("SELECT title, body FROM staff_agreements WHERE id=$eid")->fetch(PDO::FETCH_NUM)));
check($x===null,'Someone who is not leaving cannot sign the exit acknowledgement');
[$code,,$err]=$renderPage('users',[],['request_exit'=>'1','uid'=>'72']);
check((bool)array_filter(staff_agreement_status(72),fn($a)=>$a['kind']==='exit' && $a['state']==='needed'),'Once asked, the person leaving is asked to sign it' . ($err ? ": $err" : ''));
set_setting('access_hold','all'); set_setting('access_hold_days','0');
check(!in_array('Exit acknowledgement', access_hold_reasons($db->query("SELECT * FROM users WHERE id=72")->fetch()), true),'The exit acknowledgement never holds back client records');
set_setting('access_hold','off');

// The report.
[$code,$html,$err]=$renderPage('staff_report',[]);
check(str_contains($html,'Staff compliance report') && str_contains($html,'Privacy training') && str_contains($html,'CPR / First Aid'),'The report lists training and credentials' . ($err ? ": $err" : ''));
[$code,$csv,$err]=$renderPage('staff_report',['download'=>'1']);
$csv=preg_replace('/^\xEF\xBB\xBF/','',$csv);
check((str_starts_with($csv,'"Staff member",Kind,Item,Status,Date,Detail') || str_starts_with($csv,'Staff member,Kind,Item,Status,Date,Detail')) && str_contains($csv,'CPR / First Aid'),'The report downloads as a spreadsheet, readable with accented names');
$db->exec("DELETE FROM users WHERE id=72");
$db->exec("UPDATE staff_agreements SET active=0"); $db->exec("UPDATE training_modules SET active=0");
