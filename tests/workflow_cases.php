<?php
// Included only by full_schema.php with its synthetic configuration.
require_once $root.'/public/inc/workflows.php';
$savedPerms=$GLOBALS['CURRENT_PERMS'];$savedScoped=$GLOBALS['CURRENT_SCOPED'];
$GLOBALS['CURRENT_PERMS']=array_keys(PERMISSION_KEYS);$GLOBALS['CURRENT_SCOPED']=false;
// $expected: the start of the refusal's own message, so a refusal for another reason does not pass.
$refuseWorkflow=function(callable $f,string $message,?string $expected=null):void{try{$f();throw new RuntimeException('Expected refusal: '.$message);}catch(DomainException $e){if($expected!==null&&!str_starts_with($e->getMessage(),$expected))throw new RuntimeException($message.': refused for another reason: '.$e->getMessage());check(true,$message);}};
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
// A new request key: the one above is stored, so reusing it could be refused as a
// changed retry whatever the minutes; the refusal must be the minutes' own.
$refuseWorkflow(fn()=>workflow_practice(array_replace($practice,['practice_minutes'=>'1.5','request_key'=>str_repeat('e',32)]),$client),'Practice requires whole minutes','Minutes practiced');
check((int)$db->query("SELECT COUNT(*) FROM homework_practice WHERE homework_id=$hid")->fetchColumn()===1,'A refused practice update stores nothing');
// Real work for the client on another caseload (assigned to user 2): an Active
// authorization expiring within 30 days, a "Need help" practice report with its open
// support task, and a session that ended with no outcome recorded. A scoped user's
// action center must show none of it; an unscoped one shows all of it.
$db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Workflow outside track','WF-OUT','Synthetic',2,100,?,?)")->execute([$outside,date('Y-m-d'),date('Y-m-d',strtotime('+10 days'))]);$outsideTrack=(int)$db->lastInsertId();
$db->prepare("INSERT INTO homework(track_id,title,instructions,assigned_at) VALUES(?,'Workflow outside homework','Practice reading a label.',CURDATE())")->execute([$outsideTrack]);$outsideHomework=(int)$db->lastInsertId();
workflow_practice(['homework_id'=>$outsideHomework,'practice_minutes'=>'10','difficulty'=>'Need help','practice_notes'=>'','request_key'=>str_repeat('f',32)],$db->query("SELECT * FROM clients WHERE id=$outside")->fetch());
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at) VALUES(?,2,?,?)")->execute([$outside,date('Y-m-d H:i:s',strtotime('-2 days')),date('Y-m-d H:i:s',strtotime('-2 days')+3600)]);$outsideSession=(int)$db->lastInsertId();
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
$GLOBALS['CURRENT_PERMS']=['clients','schedule'];
$items=workflow_action_items(['id'=>1]);$titles=array_column($items,'title');
check(in_array('Homework support',array_column($items,'kind'),true)&&in_array('Workflow own client — Workflow homework',$titles,true),'Action center includes homework support');
check(!array_filter($titles,fn(string $t):bool=>str_contains($t,'Workflow outside client')),'Action center does not expose another caseload');
$GLOBALS['CURRENT_SCOPED']=false;$GLOBALS['CURRENT_PERMS']=array_keys(PERMISSION_KEYS);
$titles=array_column(workflow_action_items(['id'=>1]),'title');
check(count(array_intersect(['Workflow outside client — Workflow outside track','Workflow outside client — Workflow outside homework','Workflow outside client — record the session outcome'],$titles))===3,
    'An unscoped action center lists that client\'s expiring authorization, homework support request and missing session outcome');
$db->exec("UPDATE scheduled_sessions SET status='Cancelled' WHERE id=$outsideSession");
$db->exec("UPDATE tasks SET status='Done' WHERE auto_key LIKE 'practice:%' AND client_id=$outside");
$start=strtotime('+1 day 10:00');$end=$start+3600;
check(workflow_authorization($tid,$start,$end)===[],'Within-limit booking has no authorization warning');
check(count(workflow_authorization($tid,$start,$end,4))>=2,'Recurring booking checks total hours and every occurrence date');
$book=['client_id'=>$own,'track_id'=>$tid,'staff_id'=>1,'date'=>date('Y-m-d',$start),'time'=>'10:00','duration'=>3];
$refused=$call('schedule_session',$book,[],false);check(str_contains($refused['error'],'Authorization review'),'Mobile scheduling requires explicit authorization warning acknowledgment');
// An app build from before 3.0.16 sends no authorization_ack and has no control to
// approve the warning, so it is sent to the website; a current build is asked to approve it.
check(str_contains($refused['error'],'Book it on the website Schedule page')&&($refused['authorization_warnings']??[])!==[],'A pre-3.0.16 app is sent to the website to approve an authorization exception, with the warnings listed');
$refused=$call('schedule_session',$book+['authorization_ack'=>false],[],false);
check(str_contains($refused['error'],'explicitly approve')&&($refused['authorization_warnings']??[])!==[],'The current app is asked to approve the warning');
check((int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE track_id=$tid")->fetchColumn()===0,'Refused booking creates no session');
require_once $root.'/public/inc/voice.php';
$voice=voice_execute('schedule_session',['client_id'=>$own,'track_id'=>$tid,'start_at'=>date('Y-m-d H:i:s',$start),'duration'=>180],['id'=>1]);
check(!$voice['ok']&&str_contains($voice['error'],'Authorization review'),'Voice scheduling directs authorization exceptions to explicit staff review');
$webBook=array_map('strval',$book)+['add_session'=>'1'];
[[$code,$html,$err],$sess]=$pageSession(fn()=>$renderPage('schedule',[],$webBook));
check($clean($code,$err)&&str_contains($sess,'Authorization review')&&(int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE track_id=$tid")->fetchColumn()===0,'Web scheduling refuses an unacknowledged authorization exception');
// Positive control: the same booking, approved with the box drawn beside those
// warnings (it carries their fingerprint, kept in the session for the form), is
// booked, so the refusal above came from the authorization review and not from
// scope, the form token or validation. It is removed so the app check below starts clean.
$shownHash=preg_match('/authorization_hash";s:64:"([0-9a-f]{64})"/',$sess,$shownMatch)?$shownMatch[1]:'';
// The approval counts only beside the warnings it was drawn with: a ticked box sent
// without their fingerprint, or with another one, books nothing.
foreach(['',str_repeat('0',64)] as $otherHash){
    [$code,$html,$err]=$renderPage('schedule',[],$webBook+['authorization_ack'=>'1','authorization_hash'=>$otherHash]);
    check($clean($code,$err)&&(int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE track_id=$tid")->fetchColumn()===0,
        'Web scheduling refuses an approval sent '.($otherHash===''?'without':'with another').' fingerprint of the warnings shown'.($err?': '.$err:''));
}
[$code,$html,$err]=$renderPage('schedule',[],$webBook+['authorization_ack'=>'1','authorization_hash'=>$shownHash]);
check($clean($code,$err)&&$shownHash!==''&&(int)$db->query("SELECT COUNT(*) FROM scheduled_sessions WHERE track_id=$tid")->fetchColumn()===1,
    'Web scheduling books the session once the warnings shown are explicitly approved (control for the refusal above)'.($err?': '.$err:''));
$db->exec("DELETE FROM scheduled_sessions WHERE track_id=$tid");
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
// The referral leaves the converted state, so the waitlist view can show it.
$db->exec("UPDATE referrals SET status='New' WHERE id=$rid");
// Each view must show its own content, not only its heading: a view that showed
// only an error notice, or nothing, would otherwise pass.
$viewMarkers=['actions'=>'Workflow own client — Workflow homework','review'=>'Workflow own client — Workflow note','briefing'=>'selected>Workflow own client</option>',
    'homework'=>'15 minutes — Need help','waitlist'=>'>Workflow referral</h2>','quality'=>'#'.$usage.' briefing'];
foreach($viewMarkers as $v=>$marker){
    [$code,$html,$err]=$renderPage('workflows',['view'=>$v,'client_id'=>$own,'referral_id'=>$rid]);
    check($code===0 && $err==='' && str_contains($html,$marker) && !str_contains($html,'alert alert-error'),'Workflow page shows its own content: '.$v.($err?': '.$err:''));
}
// Without the admin permission the AI quality view lists only the user's own requests.
$db->exec("INSERT INTO ai_usage(user_id,feature,provider,model,chars_in,chars_out,redacted) VALUES(2,'briefing','openai','synthetic',1,1,1)");$otherUsage=(int)$db->lastInsertId();
$db->exec("UPDATE `groups` SET perms='clients;referrals',own_caseload_only=1 WHERE id=999; UPDATE users SET group_id=999 WHERE id=1");
[$code,$html,$err]=$renderPage('workflows',['view'=>'quality']);
$db->exec("UPDATE `groups` SET perms='clients;schedule' WHERE id=999; UPDATE users SET group_id=NULL WHERE id=1");
check($code===0 && $err==='' && str_contains($html,'#'.$usage.' briefing') && !str_contains($html,'#'.$otherUsage.' briefing'),'Staff without the admin permission see only their own AI requests on the AI quality page'.($err?': '.$err:''));

$fund=at_save('funding',['title'=>'Workflow appeal','client_id'=>$own,'status'=>'Denied','denial_reason'=>'Missing trial summary','next_action'=>'Collect evidence','requested_amount'=>'123.45','appeal_deadline'=>date('Y-m-d'),'missing_documents'=>'Trial summary','supporting_evidence'=>'Practice reports','appeal_draft'=>'Staff draft'],1);
check(at_record($fund,1)['details']['requested_amount']==='123.45','Funding saves requested amounts and appeal evidence');
check($runCron()===0 && (int)$db->query("SELECT COUNT(*) FROM at_reminders WHERE record_id=$fund")->fetchColumn()>0,'Appeal deadline generates reminders even without a general due date');
$export=client_export_records($db,$own);
check(count($export['homework_practice'])===1 && !isset($export['homework_practice'][0]['request_key']) && count($export['documentation_reviews'])===1,'Case export includes practice and reviews without reusable submission keys');
$refuseWorkflow(fn()=>workflow_practice(array_replace($practice,['practice_minutes'=>'16']),$client),'A practice retry cannot silently change already saved values');
$db->exec("UPDATE tasks SET status='Done' WHERE auto_key LIKE 'practice:%' AND client_id=$own");
check(!in_array('Homework support',array_column(workflow_action_items(['id'=>1]),'kind')),'Completed support tasks leave the daily action center');
$fixtures['workflow_actions']=$call('workflow_actions');
check(count($fixtures['workflow_actions']['items'])>0,'Mobile action center returns structured action items');
$refuseWorkflow(fn()=>workflow_homework_explain($hid,$client,'Please explain','en'),'With AI off, a client\'s request for an AI explanation is refused','AI explanations are not enabled');
// Exercise the real anonymous portal route, including a different client's forged homework id.
$workflowToken=$portalToken($own,date('Y-m-d H:i:s',strtotime('+30 days')));
[$code,$html,$err]=$anon('portal',['t'=>$workflowToken]);
check($clean($code,$err)&&str_contains($html,'Minutes practiced')&&!str_contains($html,'Ask AI to explain'),'Portal renders practice feedback while client AI is off');
$portalPractice=array_replace($practice,['request_key'=>str_repeat('b',32),'difficulty'=>'Comfortable','hw_practice'=>'1']);
[$code,$html,$err]=$anon('portal',['t'=>$workflowToken],$portalPractice);
check($clean($code,$err)&&(int)$db->query("SELECT COUNT(*) FROM homework_practice WHERE homework_id=$hid")->fetchColumn()===2,'Portal POST records its own homework update');
[$code,$html,$err]=$anon('portal',['t'=>$validPortal],array_replace($portalPractice,['request_key'=>str_repeat('c',32)]));
check($clean($code,$err)&&(int)$db->query("SELECT COUNT(*) FROM homework_practice WHERE homework_id=$hid")->fetchColumn()===2,'Portal POST cannot forge another client’s homework update');

// AI switched on, through a stand-in for the provider (no network call): ai_http()
// in a copy of inc/ai.php answers with $tmp/ai_reply.json and keeps each request body
// in $tmp/ai_request.json. ai_cfg() is cached per process, so every check with AI on
// runs in a page or API subprocess.
$originalCore=file_get_contents($tmp.'/core.php');$aiSource=file_get_contents($root.'/public/inc/ai.php');
$aiStart=strpos($aiSource,'function ai_http(');$aiEnd=$aiStart===false?false:strpos($aiSource,"/**\n * Send a system",$aiStart);
if($aiStart===false||$aiEnd===false)throw new RuntimeException('Test harness: could not locate ai_http() in inc/ai.php. Refusing to continue: the real network transport would be used.');
$stub='function ai_http(string $url,array $headers,string $body): array { file_put_contents('.var_export($tmp.'/ai_request.json',true).',$body); $reply=json_decode((string)file_get_contents('.var_export($tmp.'/ai_reply.json',true).'),true); return [200,["choices"=>[["message"=>["content"=>$reply["content"]],"finish_reason"=>$reply["finish"]]]],""]; }'."\n\n";
file_put_contents($tmp.'/ai_workflow.php',substr($aiSource,0,$aiStart).$stub.substr($aiSource,$aiEnd));
file_put_contents($tmp.'/core.php',swap_required(var_export($root.'/public/inc',true)." . '/ai.php'",var_export($tmp.'/ai_workflow.php',true),$originalCore,'the temporary core.php (AI transport stub)'));
$aiReply=function(string $content,string $finish='stop')use($tmp):void{file_put_contents($tmp.'/ai_reply.json',json_encode(['content'=>$content,'finish'=>$finish]));};
foreach(['ai_enabled'=>'1','ai_api_key'=>$secrets['ai'],'ai_provider'=>'openai','ai_redact'=>'1'] as $key=>$value)$db->prepare('INSERT INTO settings(`name`,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute([$key,$value]);
$db->exec("DELETE FROM settings WHERE `name` IN ('ai_feat_portal_homework','ai_feat_briefing')");
try{
    // Client-facing AI needs its own switch: AI on for staff is not enough.
    $explain=['hw_explain'=>'1','homework_id'=>(string)$hid,'homework_question'=>'What does the first step mean?'];
    $aiReply('Synthetic plain-language explanation');
    [$code,$html,$err]=$anon('portal',['t'=>$workflowToken]);
    check($clean($code,$err)&&str_contains($html,'Minutes practiced')&&!str_contains($html,'Ask AI to explain'),'With AI on for staff, the portal offers no AI explanation until client-facing AI is turned on');
    [$code,$html,$err]=$anon('portal',['t'=>$workflowToken],$explain);
    check($clean($code,$err)&&str_contains($html,'This feature isn')&&!str_contains($html,'Synthetic plain-language explanation'),'A portal request for an AI explanation is refused while client-facing AI is off');
    $db->exec("INSERT INTO settings(`name`,value) VALUES('ai_feat_portal_homework','1') ON DUPLICATE KEY UPDATE value='1'");
    [$code,$html,$err]=$anon('portal',['t'=>$workflowToken]);
    check($clean($code,$err)&&str_contains($html,'Ask AI to explain'),'Once client-facing AI is turned on, the portal offers an explanation');
    // The answer is kept for the page the request redirects to (so a refresh never asks again).
    [[$code,$html,$err],$sess]=$pageSession(fn()=>$anon('portal',['t'=>$workflowToken],$explain));
    $sent=(string)file_get_contents($tmp.'/ai_request.json');
    check($clean($code,$err)&&str_contains($sess,'Synthetic plain-language explanation')&&str_contains($sent,'Practice opening a document')&&!str_contains($sent,'Workflow own client'),
        'The explanation is made for the client, and the provider receives the homework without the client\'s name');
    [$code,$html,$err]=$anon('portal',['t'=>$workflowToken],null,['hw_explanation'=>['token'=>$workflowToken,'homework_id'=>$hid,'question'=>$explain['homework_question'],'text'=>'Synthetic plain-language explanation','truncated'=>false]]);
    check($clean($code,$err)&&str_contains($html,'Synthetic plain-language explanation'),'The explanation is shown to the client inside the homework it answers');
    // The AI case briefing: cited sources resolved on the server, the client's name
    // redacted, one audit row per draft (written by workflow_briefing() itself), and a
    // reply that is cut off, cites a record it was not given, or cites nothing is refused.
    $briefingAudits=fn():int=>(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='ai.draft' AND entity_id=$own")->fetchColumn();
    $lastUsage=fn(string $column):int=>(int)$db->query("SELECT `$column` FROM ai_usage ORDER BY id DESC LIMIT 1")->fetchColumn();
    $cited=json_encode(['items'=>[['type'=>'fact','text'=>'A result is awaiting clarification.','sources'=>['N'.$nid]]]]);
    $auditsBefore=$briefingAudits();
    $aiReply($cited);
    $brief=$call('workflow_briefing',['client_id'=>$own]);
    $sent=(string)file_get_contents($tmp.'/ai_request.json');
    check(($brief['items'][0]['sources']['N'.$nid]['url']??'')==='client.php?id='.$own && str_contains($sent,'N'.$nid) && !str_contains($sent,'Workflow own client'),
        'An AI case briefing in the app cites the records it was given, with links built on the server, and the provider never receives the client\'s name');
    check($briefingAudits()===$auditsBefore+1,'An AI case briefing in the app is audited once');
    $fixtures['workflow_briefing']=$brief;
    $aiReply($cited,'length');
    $call('workflow_briefing',['client_id'=>$own],[],false,'cut off');
    check($lastUsage('truncated')===1,'A briefing cut off at the output limit is refused and recorded as cut off');
    $aiReply(json_encode(['items'=>[['type'=>'fact','text'=>'Made up','sources'=>['N999999']]]]));
    $call('workflow_briefing',['client_id'=>$own],[],false,'outside this briefing');
    check($lastUsage('succeeded')===0,'A briefing that cites a record it was not given is refused and its AI request recorded as failed');
    $aiReply(json_encode(['items'=>[['type'=>'fact','text'=>'Unsupported','sources'=>[]]]]));
    $call('workflow_briefing',['client_id'=>$own],[],false,'omitted supporting sources');
    check($lastUsage('succeeded')===0,'An uncited briefing is refused and its AI request recorded as failed');
    // The website: the briefing and the homework adaptations are drafts on the page.
    $aiReply($cited);
    $auditsBefore=$briefingAudits();
    [$code,$html,$err]=$renderPage('workflows',['view'=>'briefing'],['generate'=>'1','client_id'=>(string)$own]);
    check($code===0&&$err===''&&str_contains($html,'AI draft — verify before use')&&str_contains($html,'href="client.php?id='.$own.'"')&&$briefingAudits()===$auditsBefore+1,
        'A web AI case briefing is shown as a draft linked to its sources and audited once'.($err?': '.$err:''));
    $homeworkCount=(int)$db->query('SELECT COUNT(*) FROM homework')->fetchColumn();
    [$code,$html,$err]=$renderPage('workflows',['view'=>'homework','client_id'=>(string)$own],['adapt'=>'1','client_id'=>(string)$own]);
    $sent=(string)file_get_contents($tmp.'/ai_request.json');
    check($code===0&&$err===''&&str_contains($html,'AI draft — verify before use')&&str_contains($sent,'homework adaptations')
        &&(int)$db->query('SELECT COUNT(*) FROM homework')->fetchColumn()===$homeworkCount,'Homework adaptations are a draft for staff review: nothing is assigned'.($err?': '.$err:''));
}finally{file_put_contents($tmp.'/core.php',$originalCore);$db->exec("UPDATE settings SET value='0' WHERE `name`='ai_enabled'");$db->exec("DELETE FROM settings WHERE `name`='ai_feat_portal_homework'");}
// The app's Action center and AI case briefing screens decode these real responses.
$p=proc_open(['python3',$root.'/tests/ios_models.py'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode($fixtures));fclose($pipes[0]);echo stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
check(proc_close($p)===0,'iOS decodes the action center and the AI case briefing: '.$errors);
[$code,$html,$err]=$renderPage('workflows',['view'=>'waitlist','referral_id'=>$rid]);
check($clean($code,$err)&&str_contains($html,'Suggested instructors'),'Saved referral requirements render instructor matching');
// Per-client cap persists across different request keys and portal sessions.
for($i=0;$i<18;$i++)workflow_practice(array_replace($practice,['difficulty'=>'Comfortable','request_key'=>str_pad(dechex($i+1000),32,'0',STR_PAD_LEFT)]),$client);
$refuseWorkflow(fn()=>workflow_practice(array_replace($practice,['request_key'=>str_repeat('d',32)]),$client),'Practice daily cap is shared across submission keys');
$db->exec("UPDATE homework SET status='Completed' WHERE id=$hid");
[$code,$html,$err]=$anon('portal',['t'=>$workflowToken]);
check($clean($code,$err)&&!str_contains($html,'Minutes practiced'),'Completed homework no longer offers practice submission controls');
// Note review: "todo" is an everyday Spanish word; TODO in capitals is still a placeholder.
check(!in_array('Unresolved placeholder',workflow_note_flags(['subject'=>'x','body'=>'Practiqué todo','note_format'=>'free']),true)
    &&in_array('Unresolved placeholder',workflow_note_flags(['subject'=>'x','body'=>'TODO','note_format'=>'free']),true),'A Spanish "todo" is not flagged as a placeholder; TODO in capitals still is');
// A portal contact-details note quotes the client's old address and access notes, which
// redaction cannot recognise, so it never goes to the AI provider in a briefing.
$db->prepare("INSERT INTO client_notes(client_id,subject,body,author_id) VALUES(?,'Contact details / access needs changed by client (portal)','Synthetic previous address',NULL)")->execute([$own]);$portalNote=(int)$db->lastInsertId();
$evidence=workflow_evidence($own,1);
check(!isset($evidence['sources']['N'.$portalNote])&&isset($evidence['sources']['N'.$nid])&&is_int($evidence['omitted']),'A portal contact-details note is left out of the AI briefing evidence, and the count of records left out is a number');
$db->exec("DELETE FROM client_notes WHERE id=$portalNote");
// Closing a waitlist entry settles the referral's contact task; a follow-up year typed
// with too few digits is refused with the field named.
$db->exec("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name) VALUES('Synthetic','Waitlist close','Synthetic','Referrer','Agency','referrer@example.invalid','555','Workflow closed referral')");$closedRid=(int)$db->lastInsertId();
$db->prepare("INSERT INTO tasks(title,referral_id,auto_key) VALUES('Contact the referred person',?,?)")->execute([$closedRid,'ref-contact-'.$closedRid]);
$closedWait=['referral_id'=>$closedRid,'expertise'=>'','languages'=>'','areas'=>'','availability'=>'','modality'=>'Both','status'=>'Closed'];
$refuseWorkflow(fn()=>workflow_waitlist_save($closedWait+['followup_date'=>'0026-10-20'],1),'A waitlist follow-up date with a two-digit year is refused','Follow-up date:');
workflow_waitlist_save($closedWait,1);
check($db->query("SELECT status FROM tasks WHERE auto_key='ref-contact-$closedRid'")->fetchColumn()==='Done','Closing a waitlist entry completes the referral\'s contact task');
// Booked time counts to the quarter hour per session, as completing it logs: a 50-minute
// session on a 2-hour authorization is 0.75 hours, leaving room for 1.25 more.
$db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Workflow quarter-hour track','WF-Q','Synthetic',2,100,?,?)")->execute([$own,date('Y-m-d'),date('Y-m-d',strtotime('+30 days'))]);$quarterTrack=(int)$db->lastInsertId();
$quarterStart=strtotime('+2 days 09:00');
$db->prepare("INSERT INTO scheduled_sessions(client_id,track_id,user_id,start_at,end_at) VALUES(?,?,1,?,?)")->execute([$own,$quarterTrack,date('Y-m-d H:i:s',$quarterStart),date('Y-m-d H:i:s',$quarterStart+3000)]);
$quarterNext=strtotime('+3 days 09:00');
$overBooked=workflow_authorization($quarterTrack,$quarterNext,$quarterNext+5400);
check(workflow_authorization($quarterTrack,$quarterNext,$quarterNext+4500)===[]&&count($overBooked)===1&&str_contains($overBooked[0],'0.75 scheduled'),'A 50-minute session counts as 0.75 hours of a 2-hour authorization');
$db->exec("UPDATE scheduled_sessions SET status='Cancelled' WHERE track_id=$quarterTrack");
// Practice notes are counted as the browser counts them: a Windows line break is one character.
$longNotes=str_repeat(str_repeat('a',165)."\r\n",12).str_repeat('a',8);
workflow_practice(['homework_id'=>$outsideHomework,'practice_minutes'=>'20','difficulty'=>'Comfortable','practice_notes'=>$longNotes,'request_key'=>str_repeat('9',32)],$db->query("SELECT * FROM clients WHERE id=$outside")->fetch());
check((int)$db->query("SELECT CHAR_LENGTH(notes) FROM homework_practice WHERE request_key='".str_repeat('9',32)."'")->fetchColumn()===2000,'Practice notes of 1,988 characters and 12 line breaks fit the 2,000-character limit');
$GLOBALS['CURRENT_PERMS']=$savedPerms;$GLOBALS['CURRENT_SCOPED']=$savedScoped;
