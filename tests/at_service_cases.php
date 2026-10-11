<?php
// Included by full_schema.php, using only its temporary configuration and synthetic DB.
require_once $root.'/public/inc/at_services.php';
$GLOBALS['CURRENT_PERMS']=array_keys(PERMISSION_KEYS);$GLOBALS['CURRENT_SCOPED']=false;
$db->exec("INSERT INTO users(id,name,email,password_hash,role) VALUES(2,'Synthetic instructor','instructor@example.invalid','test','instructor')");
$db->exec("INSERT INTO clients(id,name,assigned_to) VALUES(3,'AT synthetic client',1),(4,'Outside caseload',2)");
$db->exec("INSERT INTO training_tracks(id,client_id,title,auth_number,funding_agency,hourly_rate) VALUES(100,3,'AT training','AT-100','State VR',100),(101,4,'Outside track','AT-101','State VR',100)");
$db->exec("INSERT INTO equipment(id,name,status) VALUES(100,'Broken device','Repair'),(101,'Loaner','Available'),(102,'Donated device','Available')");
$save=function(string $kind,array $values,array $extra=[])use($call):int{return (int)$call('at_save',['kind'=>$kind,'title'=>'Synthetic '.$kind,'values'=>$values]+$extra)['id'];};
$edit=function(int $id,array $changes=[],?string $status=null)use($call):array{$r=$call('at_detail',[],['id'=>$id])['record'];return $call('at_save',['id'=>$id,'kind'=>$r['kind'],'version'=>$r['version'],'title'=>$r['title'],'status'=>$status??$r['status'],'values'=>array_replace($r['values'],$changes)]);};
$rejectEdit=function(int $id,array $changes=[],?string $status=null)use($call):array{$r=$call('at_detail',[],['id'=>$id])['record'];return $call('at_save',['id'=>$id,'kind'=>$r['kind'],'version'=>$r['version'],'title'=>$r['title'],'status'=>$status??$r['status'],'values'=>array_replace($r['values'],$changes)],[],false);};
$base=['client_id'=>'3','track_id'=>'100'];
// Every required field is supplied, so the compound value is the only defect and
// the refusal must come from the single-value guard, not a missing field.
$validTrial=$base+['goals'=>'Read a document independently','context'=>'Home office'];
$refused=$call('at_save',['kind'=>'trial','title'=>['invalid'],'values'=>$validTrial],[],false);
check(str_starts_with((string)($refused['error']??''),'Record fields must contain a single value')||($refused['error']??'')==='Invalid input.','AT records refuse a compound title before casting it');
$refused=$call('at_save',['kind'=>'trial','title'=>'Synthetic trial','values'=>['client_id'=>['3']]+$validTrial],[],false);
check(str_starts_with((string)($refused['error']??''),'Record fields must contain a single value'),'AT records refuse a compound identifier before casting it');
$trial=$save('trial',$base+['goals'=>'Read a document independently','context'=>'Home office']);
$option=$save('trial_option',['client_id'=>'3','parent_id'=>(string)$trial,'device'=>'Synthetic reader','task_results'=>'Read 4 of 5 passages','independence'=>'4','ease'=>'3','cost'=>'199.50'],['status'=>'Trialled']);
$refused=$rejectEdit($trial,[],'Completed');
check(str_starts_with((string)($refused['error']??''),'Recommended trial option is required'),'A comparison completed without a recommended option is refused with a message that names the field');
$edit($trial,['preferred_option'=>(string)$option,'rationale'=>'Meets the stated reading goal'],'Completed');
check($call('at_detail',[],['id'=>$trial])['record']['status']==='Completed','Device comparisons require a tested option and rationale');
$stale=$call('at_detail',[],['id'=>$trial])['record'];$edit($trial,['rationale'=>'Updated explanation']);
$call('at_save',['kind'=>'trial','id'=>$trial,'version'=>$stale['version'],'title'=>$stale['title'],'status'=>'Completed','values'=>$stale['values']],[],false);
check(true,'Stale edits are rejected instead of overwriting newer work');
$setup=$save('setup',$base+['equipment_id'=>'100','device'=>'Synthetic reader','delivered_on'=>'2026-10-01','versions'=>'1.0','settings'=>'Large text','instructions'=>'Enable large text'],['status'=>'Active']);
$edit($setup,[]);$detail=$call('at_detail',[],['id'=>$setup]);
check(count($detail['children'])===3&&$detail['children'][0]['values']['due_date']==='2026-10-31','Active delivery schedules 30/60/90-day outcomes once');
// A setup made Active before the device was delivered gets its outcome checks once the delivery date is added.
$lateSetup=$save('setup',$base+['device'=>'Synthetic magnifier','versions'=>'2.0','settings'=>'Zoom 4x','instructions'=>'Turn on zoom'],['status'=>'Active']);
check(count($call('at_detail',[],['id'=>$lateSetup])['children'])===0,'An active setup without a delivery date has no outcome checks yet');
$edit($lateSetup,['delivered_on'=>'2026-10-02']);
check(count($call('at_detail',[],['id'=>$lateSetup])['children'])===3,'Adding the delivery date to an active setup schedules its 30/60/90-day outcomes');
$outcome=$detail['children'][0]['id'];$refused=$rejectEdit($outcome,[],'Completed');
check(str_starts_with((string)($refused['error']??''),'Completion date is required'),'An outcome completed without a completion date is refused with a message that names the field');
$edit($outcome,['completed_on'=>'2026-10-31','in_use'=>'Daily','independence'=>'4','confidence'=>'5'],'Completed');
check($call('at_detail',[],['id'=>$outcome])['record']['status']==='Completed','Outcome completion requires observed use and scores');
$resource=$save('resource',['goals'=>'Practice reading','format'=>'Text','instructions'=>'Step one: open the document','practice'=>'Read one page','url'=>'https://example.invalid/guide'],['status'=>'Published']);
$learning=$save('learning',$base+['parent_id'=>(string)$resource,'instructions'=>'Practice for ten minutes','due_date'=>'2026-10-10']);
at_portal_report($learning,4);check(at_record($learning,1)['status']==='Assigned','Portal cannot update another client’s learning');
at_portal_report($learning,3);check(at_record($learning,1)['status']==='Client reported'&&count(at_portal_learning(3))===1,'Assigned learning appears in the portal and client reports remain distinct');
$edit($resource,[],'Archived');
check(count(at_portal_learning(3))===0,'Archived resources disappear from the client portal');
$edit($learning,['feedback'=>'Practice reviewed after archival'],'Completed');
$learningDetail=$call('at_detail',[],['id'=>$learning]);
$parentField=array_values(array_filter($learningDetail['fields'],fn($f)=>$f['key']==='parent_id'))[0];
check(in_array((string)$resource,array_column($parentField['options'],'value'),true),'Existing learning retains its archived resource in edit choices');
// Every other required field is filled in, so the archived parent is the only reason to refuse.
$archived=$call('at_save',['kind'=>'learning','title'=>'New archived assignment','values'=>$base+['parent_id'=>(string)$resource,'instructions'=>'Practice for ten minutes','due_date'=>'2026-10-10']],[],false);
check($learningDetail['record']['status']==='Completed'&&str_starts_with((string)($archived['error']??''),'Published resource:'),'Existing learning can close after archival while new assignments to the archived resource are rejected');
$edit($resource,[],'Published');
$call('at_save',['kind'=>'resource','title'=>'Unsafe URL','values'=>['goals'=>'Test','format'=>'Text','instructions'=>'test','url'=>'javascript:alert(1)']],[],false);
$repair=$save('repair',['client_id'=>'3','equipment_id'=>'100','device'=>'Broken reader','issue'=>'No power']);
$loan=$call('at_issue',['id'=>$repair,'client_id'=>3,'equipment_id'=>101,'due_date'=>'2026-11-01'])['id'];
check((int)$db->query('SELECT COUNT(*) FROM equipment_followups WHERE loan_id='.(int)$loan)->fetchColumn()===0,'A temporary repair loaner gets no "still in use?" follow-ups');
$call('at_issue',['id'=>$repair,'client_id'=>3,'equipment_id'=>101],[],false);$rejectEdit($repair,['resolution'=>'Repaired'],'Resolved');
$call('at_return',['id'=>$repair]);$edit($repair,['resolution'=>'Repaired and tested'],'Resolved');
check((int)$db->query("SELECT COUNT(*) FROM equipment_loans WHERE id=$loan AND returned_at IS NOT NULL")->fetchColumn()===1,'Repair loaners use actual inventory and must return before closure');
// Editing only the notes of a closed repair leaves a status staff have since set by hand.
$db->exec("UPDATE equipment SET status='Repair' WHERE id=100");
$edit($repair,['resolution'=>'Repaired, tested and labelled']);
check($db->query('SELECT status FROM equipment WHERE id=100')->fetchColumn()==='Repair','Editing a closed repair does not overwrite the device status set since');
$db->exec("UPDATE equipment SET status='Available' WHERE id=100");
// A repair opened on an available device and then cancelled puts it back as available.
$db->exec("INSERT INTO equipment(id,name,status) VALUES(110,'Spare synthetic device','Available')");
$cancelled=$save('repair',['client_id'=>'3','equipment_id'=>'110','device'=>'Spare synthetic device','issue'=>'Reported by mistake']);
check($db->query('SELECT status FROM equipment WHERE id=110')->fetchColumn()==='Repair','Opening a repair takes the device out of service');
$edit($cancelled,[],'Cancelled');
check($db->query('SELECT status FROM equipment WHERE id=110')->fetchColumn()==='Available','Cancelling a repair restores the device to the status it had before');
$reuse=$save('reuse',['equipment_id'=>'102','received_on'=>'2026-10-01','inspection'=>'No','sanitized'=>'No','data_wiped'=>'No','repairs_done'=>'No']);
check($db->query('SELECT status FROM equipment WHERE id=102')->fetchColumn()==='Repair','Reuse intake quarantines equipment');
$refused=$rejectEdit($reuse,[],'Ready');
check(str_starts_with((string)($refused['error']??''),'Safety inspection passed: choose Yes'),'A reuse device made Ready without a passed inspection is refused with a message that names the field');
$edit($reuse,['inspection'=>'Yes','sanitized'=>'Yes','data_wiped'=>'Not applicable','repairs_done'=>'Yes','work_done'=>'Checked, sanitized and tested'],'Ready');
$reuseLoan=(int)$call('at_issue',['id'=>$reuse,'client_id'=>3,'equipment_id'=>102])['id'];
check($db->query('SELECT status FROM equipment WHERE id=102')->fetchColumn()==='On Loan','Ready reuse devices redeploy through the real inventory workflow');
check((int)$db->query('SELECT COUNT(*) FROM equipment_followups WHERE loan_id='.$reuseLoan)->fetchColumn()===3,'A redeployed device is a real provision and gets its three follow-ups');
$license=$save('license',['vendor'=>'Synthetic vendor','product'=>'Reader','seats'=>'1','due_date'=>'2099-01-01','responsible'=>'Business'],['status'=>'Active']);
$seat=$save('license_seat',['client_id'=>'3','parent_id'=>(string)$license]);
$call('at_save',['kind'=>'license_seat','title'=>'Over-allocation','values'=>['client_id'=>'4','parent_id'=>(string)$license]],[],false);
$edit($seat,[],'Released');$save('license_seat',['client_id'=>'4','parent_id'=>(string)$license]);
check($call('at_detail',[],['id'=>$license])['summary']['Available seats']==='0','License seats cannot be overallocated and can be released and reassigned');
$profile=$save('staff_profile',['staff_id'=>'1','expertise'=>'Screen reader, magnification','languages'=>'English, ASL','areas'=>'Chicago','modality'=>'Both','availability'=>'Weekdays']);
$matches=$call('at_matches',[],['expertise'=>'Screen reader','languages'=>'English','areas'=>'Chicago','modality'=>'Remote'])['matches'];
check(count($matches)===1&&$matches[0]['missing']===''&&$matches[0]['score']===3,'Instructor matching combines skills, communication, location and schedule capacity');
$rate=$save('rate',['staff_id'=>'1','due_date'=>'2026-01-01','hourly_cost'=>'40']);
$cost=$save('cost',$base+['spent_on'=>'2026-10-01','category'=>'Travel','amount'=>'10.00']);
$db->exec("INSERT INTO work_logs(track_id,work_date,hours,description,created_by) VALUES(100,'2026-10-01',2,'Synthetic work',1)");
$db->exec("INSERT INTO invoices(track_id,invoice_number,total_amount,status,paid_amount) VALUES(100,'AT-SYNTHETIC',200,'Paid',200)");$invoice=(int)$db->lastInsertId();
$profits=$call('at_profitability')['rows'];$p=array_values(array_filter($profits,fn($r)=>$r['id']===100))[0];
check($p['invoiced']==200&&$p['received']==200&&$p['cost']==90&&$p['margin']==110&&$p['unpriced_hours']==0,'Profitability combines effective labor cost, direct costs and actual invoices');
$db->exec("INSERT INTO client_documents(client_id,category,title,orig_name,path) VALUES(3,'Other','Authorization','authorization.txt','synthetic.txt')");$doc=(int)$db->lastInsertId();
$funding=$save('funding',$base+['next_action'=>'Submit evidence']);$rejectEdit($funding,[],'Paid');
$edit($funding,['authorization'=>'AT-100','authorization_document'=>(string)$doc,'service_evidence'=>'Yes','report_ready'=>'Not applicable','invoice_id'=>(string)$invoice],'Paid');
check($call('at_detail',[],['id'=>$funding])['summary']['Received']==='200.00','Funding workflow requires evidence and reconciles with actual payments');
$denied=$save('funding',$base+['next_action'=>'Prepare an appeal']);$refused=$rejectEdit($denied,[],'Denied');
check(str_starts_with((string)($refused['error']??''),'Denial / appeal reason is required'),'A funding record marked Denied without a reason is refused with a message that names the field');
$rejectEdit($setup,['track_id'=>'101']);
$call('at_save',['kind'=>'cost','title'=>'Bad cost','values'=>$base+['spent_on'=>'2026-10-01','category'=>'Travel','amount'=>'-10']],[],false);
check(true,'Cross-client references and invalid amounts are rejected');
$db->exec("UPDATE groups SET perms='clients;equipment;schedule',own_caseload_only=1 WHERE id=999; UPDATE users SET group_id=999 WHERE id=1");
$call('at_profitability',[],[],false);$call('at_detail',[],['id'=>$rate],false);$call('at_detail',[],['id'=>$cost],false);
$catalog=$call('at_catalog')['modules'];check(!in_array('cost',array_column($catalog,'id'),true)&&!in_array('rate',array_column($catalog,'id'),true),'Staff without admin or billing cannot access profitability');
$scopedLicense=$call('at_detail',[],['id'=>$license]);check(count($scopedLicense['children'])===1&&$scopedLicense['summary']['Available seats']==='0','Shared license shows aggregate capacity without exposing other caseload assignments');
$call('at_save',['kind'=>'setup','title'=>'Outside client','values'=>['client_id'=>'4','device'=>'test','settings'=>'test','instructions'=>'test']],[],false);
$call('at_save',['kind'=>'staff_profile','title'=>'Other staff','values'=>['staff_id'=>'2','expertise'=>'test','languages'=>'English','areas'=>'Chicago','modality'=>'Remote','availability'=>'Weekdays']],[],false);
$call('at_save',['kind'=>'resource','title'=>'Unauthorized library edit','values'=>['goals'=>'test','format'=>'Text','instructions'=>'test']],[],false);
check(true,'Caseload scope and staff/library edit permissions are enforced');
$db->exec("UPDATE groups SET perms='billing' WHERE id=999");
check(count($call('at_catalog')['modules'])===count(at_catalog()),'Billing can access all AT service modules');
$call('at_profitability');$call('at_detail',[],['id'=>$rate]);$call('at_detail',[],['id'=>$cost]);
$edit($resource,['instructions'=>'Revised by billing']);
$call('at_save',['kind'=>'setup','title'=>'Outside client','values'=>['client_id'=>'4','device'=>'test','settings'=>'test','instructions'=>'test']],[],false);
check(true,'Billing can edit shared resources and access costs while caseload scope remains enforced');
$db->exec('UPDATE users SET group_id=NULL WHERE id=1');
require __DIR__.'/at_extended_cases.php';
$db->exec("UPDATE settings SET value='1' WHERE name IN ('ai_enabled','ai_feat_draft')");
$source=file_get_contents($root.'/public/at_services.php');$source=swap_required("require __DIR__.'/inc/bootstrap.php';",'require '.var_export($tmp.'/bootstrap.php',true).';',$source,'public/at_services.php');$source=str_replace('__DIR__',var_export($root.'/public',true),$source);file_put_contents($tmp.'/at_page.php',$source);
// Each page must show what was asked for — the catalog, the workflow's own
// heading, or the record itself — and not an error page ("AT services" alone is
// in the navigation and heading of every response, error pages included).
$queries=[[[],'href="at_services.php?kind=trial"']];
foreach(at_catalog() as $kind=>$definition){$label='>'.htmlspecialchars($definition['label'],ENT_QUOTES,'UTF-8').'</h2>';$queries[]=[['kind'=>$kind],$label];$queries[]=[['kind'=>$kind,'new'=>1],$label];}
foreach([$trial,$setup,$repair,$reuse,$license,$funding,$resource] as $rid)$queries[]=[['id'=>$rid],htmlspecialchars(at_record($rid,1)['title'],ENT_QUOTES,'UTF-8')];
foreach($queries as [$query,$marker]){
    $process=proc_open([PHP_BINARY,'-d','display_errors=stderr',$root.'/tests/full_schema.php','page',$tmp.'/at_page.php',json_encode($query)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $html=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    check($code===0&&!preg_match('/Fatal error|Warning|Notice|Uncaught|Deprecated/',$errors)&&str_contains($html,$marker)&&!preg_match('~<title[^>]*>Error ~',$html),'AT web '.json_encode($query)." renders its own content: $errors");
    if(isset($query['new']) && array_filter(at_catalog()[$query['kind']]['fields'],fn($f)=>in_array($f['type'],['text','textarea'],true)))check(str_contains($html,'Work on this with AI'),'AI review controls render for '.$query['kind']);
    if(getenv('CMS_TEST_PREVIEW_DIR')==='/tmp/cms-at-preview') {
        $preview='/tmp/cms-at-preview';if(!is_dir($preview))mkdir($preview,0700);
        $filename=isset($query['kind'])?'at-'.$query['kind'].(isset($query['new'])?'-new':''):(isset($query['id'])?'record-'.$query['id']:'index');
        file_put_contents($preview.'/'.$filename.'.html',$html);
    }
}
// AI was switched on above only for the AT pages' review controls. Everything
// after this runs with the real AI transport and a stored provider key, so AI is
// switched off again: no later step can reach a provider over the network.
$db->exec("UPDATE settings SET value='0' WHERE name='ai_enabled'");
