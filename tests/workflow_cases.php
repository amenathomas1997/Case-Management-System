<?php
// Included only by full_schema.php with its synthetic configuration.
require_once $root.'/public/inc/workflows.php';
$savedPerms=$GLOBALS['CURRENT_PERMS'];$savedScoped=$GLOBALS['CURRENT_SCOPED'];
$GLOBALS['CURRENT_PERMS']=array_keys(PERMISSION_KEYS);$GLOBALS['CURRENT_SCOPED']=false;
$refuseWorkflow=function(callable $f,string $message):void{try{$f();throw new RuntimeException('Expected refusal: '.$message);}catch(DomainException $e){check(true,$message);}};
$db->exec("INSERT INTO clients(name,assigned_to) VALUES('Workflow own client',1),('Workflow outside client',2)");$own=(int)$db->lastInsertId();$outside=$own+1;
$db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Workflow track','WF-1','Synthetic',2,100,?,?)")->execute([$own,date('Y-m-d'),date('Y-m-d',strtotime('+7 days'))]);$tid=(int)$db->lastInsertId();
$db->prepare("INSERT INTO homework(track_id,title,instructions,assigned_at) VALUES(?,'Workflow homework','Practice opening a document.',CURDATE())")->execute([$tid]);$hid=(int)$db->lastInsertId();
$db->prepare("INSERT INTO client_notes(client_id,subject,body) VALUES(?,'Workflow note','TODO: clarify the observed result')")->execute([$own]);$nid=(int)$db->lastInsertId();
$client=$db->query("SELECT * FROM clients WHERE id=$own")->fetch();
$practice=['homework_id'=>$hid,'practice_minutes'=>'15','difficulty'=>'Need help','practice_notes'=>'The text was difficult to read.','request_key'=>str_repeat('a',32)];
workflow_practice($practice,$client);workflow_practice($practice,$client);
check((int)$db->query("SELECT COUNT(*) FROM homework_practice WHERE homework_id=$hid")->fetchColumn()===1,'Repeated practice submission is saved once');
check((int)$db->query("SELECT COUNT(*) FROM tasks WHERE client_id=$own AND auto_key LIKE 'practice:%'")->fetchColumn()===1,'Homework help creates one staff task');
check($db->query("SELECT status FROM homework WHERE id=$hid")->fetchColumn()==='Assigned','Practice reports never mark homework complete');
$refuseWorkflow(fn()=>workflow_homework($hid,$outside),'Portal homework cannot cross client boundaries');
$refuseWorkflow(fn()=>workflow_practice(array_replace($practice,['practice_minutes'=>'1.5']),$client),'Practice requires whole minutes');
$GLOBALS['CURRENT_SCOPED']=true;$GLOBALS['CURRENT_PERMS']=['clients'];
$refuseWorkflow(fn()=>workflow_evidence($outside,1),'Case briefing refuses another caseload before an AI call');
$e=workflow_evidence($own,1);
check(isset($e['sources']['N'.$nid]) && count(array_filter(array_keys($e['sources']),fn($k)=>str_starts_with($k,'P')))===1,'Briefing includes permitted notes and client-reported practice');
$parsed=workflow_parse_briefing(json_encode(['items'=>[['type'=>'fact','text'=>'A result is awaiting clarification.','sources'=>['N'.$nid]]]]),$e['sources']);
check($parsed[0]['sources']['N'.$nid]['url']==='client.php?id='.$own,'Briefing links are resolved from server evidence');
$refuseWorkflow(fn()=>workflow_parse_briefing('{"items":[{"type":"fact","text":"Made up","sources":["N999999"]}]}',$e['sources']),'Invented citations are rejected');
$refuseWorkflow(fn()=>workflow_parse_briefing('{"items":[{"type":"fact","text":"Unsupported","sources":[]}]}',$e['sources']),'Uncited briefing claims are rejected');
foreach([''," \n\t"] as $blank) {
    $refuseWorkflow(fn()=>workflow_parse_briefing(json_encode(['items'=>[['type'=>'fact','text'=>$blank,'sources'=>['N'.$nid]]]]),$e['sources']),'Blank briefing text is rejected even with a valid citation');
}
$n=$db->query("SELECT * FROM client_notes WHERE id=$nid")->fetch();$fp=workflow_note_fingerprint($n);
workflow_acknowledge_note($nid,1,$fp,'Placeholder is intentionally retained pending evidence.');
check(!in_array($nid,array_column(workflow_review_notes(1),'id')),'Reviewed note flags leave the review queue');
$db->exec("UPDATE client_notes SET body='TODO: a changed observation' WHERE id=$nid");
check(in_array($nid,array_column(workflow_review_notes(1),'id')),'Changed notes reopen review flags');
$refuseWorkflow(fn()=>workflow_acknowledge_note($nid,1,$fp,'Old review'),'Stale documentation acknowledgment is rejected');
$items=workflow_action_items(['id'=>1]);check(str_contains(json_encode($items),'Workflow homework')||str_contains(json_encode($items),'Homework support requested'),'Action center includes homework support');
check(!str_contains(json_encode($items),'Workflow outside client'),'Action center does not expose another caseload');
$GLOBALS['CURRENT_SCOPED']=false;$GLOBALS['CURRENT_PERMS']=array_keys(PERMISSION_KEYS);
$start=strtotime('+1 day 10:00');$end=$start+3600;
check(workflow_authorization($tid,$start,$end)===[],'Within-limit booking has no authorization warning');
check(count(workflow_authorization($tid,$start,$end,4))>=2,'Recurring booking checks total hours and every occurrence date');
$book=['client_id'=>$own,'track_id'=>$tid,'staff_id'=>1,'date'=>date('Y-m-d',$start),'time'=>'10:00','duration'=>3];
$refused=$call('schedule_session',$book,[],false);check(str_contains($refused['error'],'Authorization review'),'Mobile scheduling requires explicit authorization warning acknowledgment');
check((int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE track_id=$tid")->fetchColumn()===0,'Refused booking creates no session');
require_once $root.'/public/inc/voice.php';
$voice=voice_execute('schedule_session',['client_id'=>$own,'track_id'=>$tid,'start_at'=>date('Y-m-d H:i:s',$start),'duration'=>180],['id'=>1]);
check(!$voice['ok']&&str_contains($voice['error'],'Authorization review'),'Voice scheduling directs authorization exceptions to explicit staff review');
$webBook=array_map('strval',$book)+['add_session'=>'1'];
[$code,$html,$err]=$renderPage('schedule',[],$webBook);
check($clean($code,$err)&&(int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE track_id=$tid")->fetchColumn()===0,'Web scheduling refuses an unacknowledged authorization exception');
$call('schedule_session',$book+['authorization_ack'=>true]);
check((int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE track_id=$tid")->fetchColumn()===1,'Explicitly approved exception permits scheduling');
$db->exec("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name) VALUES('Synthetic','Waitlist','Synthetic','Referrer','Agency','referrer@example.invalid','555','Workflow referral')");$rid=(int)$db->lastInsertId();
$wait=['referral_id'=>$rid,'expertise'=>'screen reader','languages'=>'English','areas'=>'Local','availability'=>'Morning','modality'=>'Remote','status'=>'Waiting'];
workflow_waitlist_save($wait,1);$refuseWorkflow(fn()=>workflow_waitlist_save($wait,1),'Waitlist rejects stale overwrites');
$wl=$db->query("SELECT * FROM referral_waitlist WHERE referral_id=$rid")->fetch();check($wl['availability']==='Morning'&&$wl['modality']==='Remote','Waitlist preserves matching requirements');
$db->exec("UPDATE referrals SET status='Converted' WHERE id=$rid");$refuseWorkflow(fn()=>workflow_waitlist_save($wait+['version'=>1],1),'Converted referrals cannot reenter the waitlist');
$usage=ai_log_usage('briefing',120,80,true,1,true,25,false);
$failedUsage=ai_log_usage('briefing',120,0,true,1,false,50,false);
check($usage>0 && (int)$db->query("SELECT succeeded FROM ai_usage WHERE id=$failedUsage")->fetchColumn()===0,'AI telemetry distinguishes failures from successes');
workflow_feedback($usage,1,'Helpful','Clear sources.');workflow_feedback($usage,1,'Incorrect','Needs review.');
check((int)$db->query("SELECT COUNT(*) FROM ai_feedback WHERE usage_id=$usage")->fetchColumn()===1,'Feedback updates instead of duplicating');
$GLOBALS['CURRENT_PERMS']=['clients'];$refuseWorkflow(fn()=>workflow_feedback($usage,2,'Helpful','Other user'),'Staff cannot rate another user’s AI request');
$GLOBALS['CURRENT_PERMS']=array_keys(PERMISSION_KEYS);
foreach(['actions','review','briefing','homework','waitlist','quality'] as $v){
    [$code,$html,$err]=$renderPage('workflows',['view'=>$v,'client_id'=>$own,'referral_id'=>$rid]);
    check($code===0 && $err==='' && str_contains($html,'<h1>'),'Workflow page renders: '.$v.($err?': '.$err:''));
}

$fund=at_save('funding',['title'=>'Workflow appeal','client_id'=>$own,'status'=>'Denied','denial_reason'=>'Missing trial summary','next_action'=>'Collect evidence','requested_amount'=>'123.45','appeal_deadline'=>date('Y-m-d'),'missing_documents'=>'Trial summary','supporting_evidence'=>'Practice reports','appeal_draft'=>'Staff draft'],1);
check(at_record($fund,1)['details']['requested_amount']==='123.45','Funding saves requested amounts and appeal evidence');
check($runCron()===0 && (int)$db->query("SELECT COUNT(*) FROM at_reminders WHERE record_id=$fund")->fetchColumn()>0,'Appeal deadline generates reminders even without a general due date');
$export=client_export_records($db,$own);
check(count($export['homework_practice'])===1 && !isset($export['homework_practice'][0]['request_key']) && count($export['documentation_reviews'])===1,'Case export includes practice and reviews without reusable submission keys');
$refuseWorkflow(fn()=>workflow_practice(array_replace($practice,['practice_minutes'=>'16']),$client),'A practice retry cannot silently change already saved values');
$db->exec("UPDATE tasks SET status='Done' WHERE auto_key LIKE 'practice:%' AND client_id=$own");
check(!in_array('Homework support',array_column(workflow_action_items(['id'=>1]),'kind')),'Completed support tasks leave the daily action center');
check(is_array($call('workflow_actions')['items']),'Mobile action center returns structured action items');
$refuseWorkflow(fn()=>workflow_homework_explain($hid,$client,'Please explain','en'),'Client-facing AI requires explicit opt-in');
// Exercise the real anonymous portal route, including a different client's forged homework id.
$workflowToken=$portalToken($own,date('Y-m-d H:i:s',strtotime('+30 days')));
[$code,$html,$err]=$anon('portal',['t'=>$workflowToken]);
check($clean($code,$err)&&str_contains($html,'Minutes practiced')&&!str_contains($html,'Ask AI to explain'),'Portal renders practice feedback while client AI is off');
$portalPractice=array_replace($practice,['request_key'=>str_repeat('b',32),'difficulty'=>'Comfortable','hw_practice'=>'1']);
[$code,$html,$err]=$anon('portal',['t'=>$workflowToken],$portalPractice);
check($clean($code,$err)&&(int)$db->query("SELECT COUNT(*) FROM homework_practice WHERE homework_id=$hid")->fetchColumn()===2,'Portal POST records its own homework update');
[$code,$html,$err]=$anon('portal',['t'=>$validPortal],array_replace($portalPractice,['request_key'=>str_repeat('c',32)]));
check($clean($code,$err)&&(int)$db->query("SELECT COUNT(*) FROM homework_practice WHERE homework_id=$hid")->fetchColumn()===2,'Portal POST cannot forge another client’s homework update');
$db->exec("UPDATE referrals SET status='New' WHERE id=$rid");
[$code,$html,$err]=$renderPage('workflows',['view'=>'waitlist','referral_id'=>$rid]);
check($clean($code,$err)&&str_contains($html,'Suggested instructors'),'Saved referral requirements render instructor matching');
// Per-client cap persists across different request keys and portal sessions.
for($i=0;$i<18;$i++)workflow_practice(array_replace($practice,['difficulty'=>'Comfortable','request_key'=>str_pad(dechex($i+1000),32,'0',STR_PAD_LEFT)]),$client);
$refuseWorkflow(fn()=>workflow_practice(array_replace($practice,['request_key'=>str_repeat('d',32)]),$client),'Practice daily cap is shared across submission keys');
$db->exec("UPDATE homework SET status='Completed' WHERE id=$hid");
[$code,$html,$err]=$anon('portal',['t'=>$workflowToken]);
check($clean($code,$err)&&!str_contains($html,'Minutes practiced'),'Completed homework no longer offers practice submission controls');
$GLOBALS['CURRENT_PERMS']=$savedPerms;$GLOBALS['CURRENT_SCOPED']=$savedScoped;
