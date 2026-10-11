<?php
require __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/at_services.php';
require_once __DIR__.'/inc/at_ai.php';
require __DIR__.'/inc/layout.php';
$user=require_login();csrf_check();$actor=(int)$user['id'];
$kind=(string)($_GET['kind']??'');$id=(int)($_GET['id']??0);$error=null;$record=null;$fields=[];$detail=null;$def=null;$contextClient=(int)($_GET['client_id']??0);
// Issuing equipment is a two-step action: the choices are checked and read back
// (client, device, return date) before the loan is created, and a problem is shown
// on the control it is about with what was chosen kept.
$issueIn=null;$issueErrors=[];$issueReview=null;
if(!at_ready()){
    http_response_code(503);page_header('AT services',$user,'at_services');
    echo '<h1>AT services</h1><p>This update needs the AT services database migration. Ask your administrator to apply version 2.73.0 before using these workflows.</p>';
    page_footer();exit;
}
try{
    if($contextClient)at_client($contextClient,$actor);
    if($id){$record=at_record($id,$actor);$kind=$record['kind'];}
    $def=$kind?at_definition($kind):null;
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(isset($_POST['save'])){$id=at_save($kind,$_POST,$actor);flash('AT service record saved.');redirect('at_services.php?id='.$id);}
        if($record&&(isset($_POST['issue'])||isset($_POST['issue_change']))){
            $due=$_POST['loan_due']??'';
            $issueIn=['client'=>(int)($_POST['loan_client_id']??0),'equipment'=>(int)($_POST['loan_equipment_id']??0),'due'=>is_scalar($due)?trim((string)$due):''];
        }
        if($record&&isset($_POST['issue'])){
            if($issueIn['client']<=0)$issueErrors['loan_client_id']='Client is required: choose who receives the equipment.';
            if($issueIn['equipment']<=0)$issueErrors['loan_equipment_id']='Equipment is required: choose the device to issue.';
            elseif($record['kind']==='repair'&&$issueIn['equipment']===(int)$record['equipment_id'])$issueErrors['loan_equipment_id']='Equipment: choose a replacement different from the device under repair.';
            if($issueIn['due']!==''&&!valid_date($issueIn['due']))$issueErrors['loan_due']='Return date: use a valid date, or leave it blank.';
            if(!$issueErrors&&($_POST['confirm_issue']??'')==='1'){at_issue($id,$issueIn['equipment'],$issueIn['client'],$issueIn['due']?:null,$actor);flash('Equipment issued and linked to this record.');redirect('at_services.php?id='.$id);}
            if(!$issueErrors){
                $issueClient=at_client($issueIn['client'],$actor);
                $q=db()->prepare('SELECT name,status FROM equipment WHERE id=?');$q->execute([$issueIn['equipment']]);$issueItem=$q->fetch();
                if(!$issueItem)$issueErrors['loan_equipment_id']='Equipment: that item no longer exists; choose another.';
                elseif($issueItem['status']!=='Available')$issueErrors['loan_equipment_id']='Equipment: '.$issueItem['name'].' is not available (status: '.$issueItem['status'].'); choose another.';
                else $issueReview=['client_name'=>$issueClient['name'],'equipment_name'=>$issueItem['name']]+$issueIn;
            }
        }
        if(isset($_POST['return'])){at_return($id,$actor);flash('Equipment returned.');redirect('at_services.php?id='.$id);}
    }
    if($record)$detail=at_detail($id,$actor);
}catch(DomainException $e){$error=$e->getMessage();if(!$def){http_response_code(403);$kind='';$record=null;}}
catch(Throwable $e){error_log('AT web: '.get_class($e).' code='.$e->getCode().' at '.basename($e->getFile()).':'.$e->getLine());$error='Could not complete this action. Reload and try again; your changes were not confirmed.';}
// A refused issue that is about one of its choices is shown on that control.
if($error!==null&&$record&&$issueIn!==null&&isset($_POST['issue'])){
    $issueKey=match($error){
        'Client not found on your caseload.','Choose the repair client and an open repair.','Choose a client whose case is open: a redeployed device cannot go to a closed or archived client.'=>'loan_client_id',
        'Choose a replacement different from the device under repair.','That item is no longer available.','That item already has an open loan.','Complete the equipment repair or reuse checks before lending this item.'=>'loan_equipment_id',
        'Use a valid loan return date.'=>'loan_due',
        default=>null};
    if($issueKey!==null){$issueErrors[$issueKey]=['loan_client_id'=>'Client: ','loan_equipment_id'=>'Equipment: ','loan_due'=>'Return date: '][$issueKey].lcfirst($error);$error=null;}
}
// A failed action still shows the record's current summary (such as whether its loaner is out).
if($record&&$detail===null){try{$detail=at_detail($id,$actor);}catch(Throwable $e){$detail=null;}}
// The browser title names the workflow or record (and flags an error), so it is
// clear which AT services page has loaded.
$pageTitle='AT services';
if($record)$pageTitle=(isset($_GET['edit'])?'Edit ':'').$record['title'].' — AT services';
elseif($def)$pageTitle=(isset($_GET['new'])?'New record: ':'').$def['label'].' — AT services';
page_header(($error||$issueErrors?'Error — ':'').$pageTitle,$user,'at_services');
?>
<h1>AT services</h1>
<p><a href="at_services.php">All AT services</a> · <a href="followups.php">Existing loan follow-ups</a></p>
<?php if($error&&!$def)echo error_notice($error);?>
<?php if(!$kind):?>
<div class="grid grid-2">
<?php foreach(at_catalog() as $key=>$d):if(!at_can($d['perm'])||in_array($key,$contextClient?['trial_option','rate']:['trial_option','learning','license_seat','rate'],true))continue;?>
<section class="card"><h2><a href="at_services.php?kind=<?=h($key)?><?= $contextClient && $d['client']?'&amp;client_id='.$contextClient:'' ?>"><?=h($d['label'])?></a></h2><p><?=h($d['description'])?></p></section>
<?php endforeach;?></div>
<?php elseif(isset($def)): ?>
<h2><?=h($def['label'])?></h2><p><?=h($def['description'])?></p>
<?php
$values=$record?at_values($record):[];
foreach(['client_id','parent_id','equipment_id','track_id','request_id'] as $key)if(!$record && !empty($_GET[$key]))$values[$key]=(string)(int)$_GET[$key];
if($error && $_SERVER['REQUEST_METHOD']==='POST')$values=array_merge($values,array_filter($_POST,'is_scalar'));
// A new record opened for a client (from the client's page, a procurement request or
// "Add …" on a parent record) lists only that client's tracks, documents and records;
// the client was checked against the caseload above.
$fields=at_fields($kind,$actor,$record?(int)$record['client_id']:$contextClient,$record);
$new=isset($_GET['new']);$edit=isset($_GET['edit'])||$new||($error&&isset($_POST['save']));
$mayEdit=($kind!=='resource'||at_can('templates'))&&($kind!=='staff_profile'||at_can('admin')||!$record||(int)$record['staff_id']===$actor);
if($kind==='reuse'&&$record&&$record['status']==='Redeployed')$mayEdit=false;
// A failed action is announced once, by moving focus to its message. Validation
// messages name the field by its label ("<Label> is required.", "<Label>: …"),
// so a message about one field is matched back to its control: it becomes a
// summary link to that control plus an inline error on it. Anything else (or a
// message whose wording no longer matches) is shown as a plain notice.
$errorKey=null;
if($error&&$edit&&$mayEdit){
    if($error==='Enter a title of up to 190 characters.')$errorKey='title';
    elseif($error==='Invalid status.')$errorKey='status';
    else foreach($fields as $f)if(str_starts_with($error,$f['label'].' is ')||str_starts_with($error,$f['label'].': ')||($f['type']==='url'&&$error==='Use an HTTPS link without embedded credentials.')){$errorKey=$f['key'];break;}
}
// Someone else saved the record after this form was opened. The form keeps what
// this user typed (for copying) and the version they started from, so saving it
// again is refused too; the current version is one link away.
$conflict=$error==='This record changed. Reload it before saving.'&&isset($_POST['save'])&&$record;
$formVersion=$error&&isset($_POST['save'])?(int)($_POST['version']??0):(int)($record['version']??0);
if($errorKey!==null){set_form_errors([$errorKey=>$error]);echo form_error_summary([$errorKey=>''],[$errorKey=>'at-'.$errorKey]);}
elseif($conflict&&!$mayEdit){
// The record can no longer be edited here (a reuse record that was just redeployed),
// so no form is drawn: what this user typed is shown read-only for copying instead.?><div class="alert alert-error" id="form-errors" tabindex="-1"><strong class="alert-label">Error:</strong> Someone else changed this record after you opened it, and it can no longer be edited here, so your changes were not saved. What you typed is shown below, read-only, so you can copy it. <a href="at_services.php?id=<?=$id?>">Open the current version</a>.</div>
<script>document.addEventListener('DOMContentLoaded',function(){var e=document.getElementById('form-errors');if(e)e.focus();});</script>
<section class="card" aria-labelledby="at-typed-h"><h3 id="at-typed-h">What you typed (not saved)</h3>
<?php foreach(array_merge([['key'=>'title','label'=>'Title','type'=>'text','options'=>[]],['key'=>'status','label'=>'Status','type'=>'text','options'=>[]]],$fields) as $f):$raw=$_POST[$f['key']]??'';$v=is_scalar($raw)?trim((string)$raw):'';if($v==='')continue;foreach($f['options'] as $o)if($o['value']===$v){$v=$o['label'];break;}?>
<label for="at-typed-<?=h($f['key'])?>"><?=h($f['label'])?></label><textarea id="at-typed-<?=h($f['key'])?>" rows="<?=$f['type']==='textarea'?4:1?>" readonly><?=h($v)?></textarea>
<?php endforeach;?></section>
<?php }
elseif($conflict){?><div class="alert alert-error" id="form-errors" tabindex="-1"><strong class="alert-label">Error:</strong> Someone else saved this record after you opened it, so your changes were not saved. What you typed is still in the form below so you can copy it; saving this form again will be refused. <a href="at_services.php?id=<?=$id?>&amp;edit=1">Reload the current version</a> and enter your changes there.</div>
<script>document.addEventListener('DOMContentLoaded',function(){var e=document.getElementById('form-errors');if(e)e.focus();});</script>
<?php }
elseif($error)echo error_notice($error);
?>
<?php if(!$record&&!$edit):?>
<p><?php if($mayEdit):?><a class="btn btn-primary" href="at_services.php?kind=<?=h($kind)?>&amp;new=1&amp;client_id=<?=$contextClient?>">Add record<span class="visually-hidden">: <?=h($def['label'])?></span></a><?php endif;?>
<?php if($kind==='resource'):?><a href="at_services.php?kind=learning">Assigned learning</a><?php endif;?>
<?php if($kind==='license'):?><a href="at_services.php?kind=license_seat">License assignments</a><?php endif;?>
<?php if($kind==='cost'):?><a href="at_services.php?kind=rate">Manage labor rates</a><?php endif;?></p>
<form method="get" class="inline-form"><input type="hidden" name="kind" value="<?=h($kind)?>"><input type="hidden" name="client_id" value="<?=$contextClient?>"><label for="at-search">Filter records</label><input type="search" id="at-search" name="q" value="<?=h((string)($_GET['q']??''))?>"><button>Search</button></form>
<div class="table-scroll" tabindex="0" role="region" aria-labelledby="at-list-cap"><table><caption id="at-list-cap"><?=h($def['label'])?></caption><thead><tr><th scope="col">Record</th><th scope="col">Status</th><th scope="col">Due / effective date</th><th scope="col">Updated</th></tr></thead><tbody>
<?php $rows=at_list($kind,$actor,(int)($_GET['client_id']??0));$shown=0;foreach($rows as $r):if(!empty($_GET['q'])&&mb_stripos($r['title'].' '.$r['status'],(string)$_GET['q'])===false)continue;$shown++;$v=(array)$r['values'];?>
<tr><th scope="row"><a href="at_services.php?id=<?=$r['id']?>"><?=h($r['title'])?></a></th><td><?=h($r['status'])?></td><td><?=h($v['due_date']?:'—')?></td><td><?=h($r['updated_at'])?></td></tr>
<?php endforeach;if(!$shown):?><tr><td colspan="4">No matching records.</td></tr><?php endif;?></tbody></table></div>
<?php if($kind==='staff_profile'):?>
<?php // The match runs before the form is drawn, so a problem with the date can be tied to the date field.
$matches=null;$matchError=null;
if(isset($_GET['match'])){try{$matches=at_matches($_GET,$actor);}catch(DomainException $e){$matchError=$e->getMessage();}}
$badDate=$matchError==='Choose a valid matching date.';?>
<section class="card"><h3>Match instructors</h3><form method="get" class="stacked"><input type="hidden" name="kind" value="staff_profile">
<?php foreach(['expertise'=>'Expertise needed','languages'=>'Language / communication','areas'=>'Service area','date'=>'Week starting'] as $k=>$label):$bad=$badDate&&$k==='date';?><label for="need-<?=$k?>"><?=h($label)?></label><input type="<?=$k==='date'?'date':'text'?>" id="need-<?=$k?>" name="<?=$k?>" value="<?=h((string)($_GET[$k]??($k==='date'?date('Y-m-d'):'')))?>"<?=$bad?' aria-invalid="true" aria-describedby="need-date-err"':''?>><?php if($bad):?><p class="field-error" id="need-date-err"><?=h($matchError)?></p><?php endif;endforeach;?>
<label for="need-modality">Delivery mode</label><select id="need-modality" name="modality"><option value="">Any</option><?php foreach(['In person','Remote'] as $m):?><option <?=($_GET['modality']??'')===$m?'selected':''?>><?=h($m)?></option><?php endforeach;?></select><button name="match" value="1">Find matches</button></form>
<?php // The outcome of a search is announced by moving focus to it once: to the date
// field when the date was the problem, otherwise to the result count.
if($badDate):?><script>document.addEventListener('DOMContentLoaded',function(){var e=document.getElementById('need-date');if(e)e.focus();});</script>
<?php elseif($matchError!==null):echo error_notice($matchError);
elseif($matches!==null):?><p id="match-results" tabindex="-1"><strong><?=$matches?count($matches).' matching instructor'.(count($matches)===1?'':'s'):'No available instructor profiles.'?></strong></p>
<script>document.addEventListener('DOMContentLoaded',function(){var e=document.getElementById('match-results');if(e)e.focus();});</script>
<?php foreach($matches as $m):?><article><h4><?=h($m['name'])?></h4><p>Matched: <?=h($m['matched']?:'None specified')?>. Missing: <?=h($m['missing']?:'None')?>.</p><p><?=h((string)$m['available_hours'])?> estimated hours available of <?=h((string)$m['capacity_hours'])?> this week. <?=h($m['availability'])?></p><a href="schedule.php">Open scheduling<span class="visually-hidden"> to book <?=h($m['name'])?></span></a></article><?php endforeach;endif;?></section>
<?php endif;?>
<?php if($kind==='cost'): $profit=at_profitability($actor);?>
<h3>Profitability by training track</h3><p>Submitted and paid invoice totals minus recorded expenses and labor cost. Missing labor rates make the margin incomplete. This excludes unrecorded overhead and unbilled revenue.</p>
<div class="table-scroll" tabindex="0" role="region" aria-labelledby="at-profit-cap"><table><caption id="at-profit-cap">Service profitability</caption><thead><tr><?php foreach(['Client / track','Service / funder','Submitted to funder','Received','Direct cost','Margin','Hours missing cost rate'] as $h):?><th scope="col"><?=h($h)?></th><?php endforeach;?></tr></thead><tbody>
<?php foreach($profit as $p):?><tr><th scope="row"><?=h($p['client'].' / '.$p['title'])?></th><td><?=h($p['service'].' / '.$p['funder'])?></td><?php foreach(['invoiced','received','cost','margin'] as $k):?><td><?=h(fmt_money($p[$k]))?></td><?php endforeach;?><td><?=h((string)$p['unpriced_hours'])?></td></tr><?php endforeach;?></tbody></table></div>
<?php foreach(['service'=>'By service','contract'=>'By linked contract'] as $groupKey=>$heading):?>
<h3><?=h($heading)?></h3><div class="table-scroll" tabindex="0" role="region" aria-labelledby="at-profit-<?=$groupKey?>-cap"><table><caption id="at-profit-<?=$groupKey?>-cap">Profitability <?=h(lcfirst($heading))?></caption><thead><tr><th scope="col">Group</th><th scope="col">Submitted to funder</th><th scope="col">Received</th><th scope="col">Direct cost</th><th scope="col">Margin</th><th scope="col">Hours missing cost rate</th></tr></thead><tbody>
<?php foreach(at_profit_groups($profit,$groupKey) as $label=>$totals):?><tr><th scope="row"><?=h($label)?></th><?php foreach(['invoiced','received','cost','margin'] as $metric):?><td><?=h(fmt_money($totals[$metric]))?></td><?php endforeach;?><td><?=h((string)$totals['unpriced_hours'])?></td></tr><?php endforeach;?></tbody></table></div>
<?php endforeach;?><p>Tracks linked to multiple contracts are grouped as unallocated rather than counted against each contract.</p>
<?php endif;?>
<?php endif;?>
<?php if($record && !$edit):?>
<h3><?=h($record['title'])?></h3><p>Status: <?=h($record['status'])?>. <?php if($mayEdit):?><a href="at_services.php?id=<?=$id?>&amp;edit=1">Edit record<span class="visually-hidden">: <?=h($record['title'])?></span></a><?php endif;?> · <a href="at_services.php?kind=<?=h($kind)?>">Back to list<span class="visually-hidden">: <?=h($def['label'])?></span></a></p>
<?php if($record['client_id']):?><p><a href="client.php?id=<?=(int)$record['client_id']?>">Open client record</a></p><?php endif;?>
<?php if($record['track_id']):?><p><a href="track.php?id=<?=(int)$record['track_id']?>">Open training track</a></p><?php endif;?>
<dl><?php foreach($fields as $f):$v=$values[$f['key']]??'';if($v==='')continue;$label=$v;foreach($f['options'] as $opt)if($opt['value']===$v)$label=$opt['label'];?><dt><strong><?=h($f['label'])?></strong></dt><dd style="white-space:pre-wrap"><?php if($f['type']==='url'):?><a href="<?=h($v)?>" rel="noreferrer"><?=h($v)?></a><?php else:?><?=h($label)?><?php endif;?></dd><?php endforeach;?></dl>
<?php foreach((array)($detail['summary']??[]) as $label=>$v):?><p><strong><?=h($label)?>:</strong> <?=h($v)?></p><?php endforeach;?>
<?php $childKind=['trial'=>'trial_option','setup'=>'outcome','resource'=>'learning','license'=>'license_seat'][$kind]??null;if($childKind):?>
<h3><?=h(at_catalog()[$childKind]['label'])?></h3><?php // Only a published resource can be assigned (at_choices lists Published resources only).
if($kind==='resource'&&$record['status']!=='Published'):?><p>Publish this resource before assigning it to a client.</p><?php else:?><p><a class="btn btn-secondary" href="at_services.php?kind=<?=h($childKind)?>&amp;new=1&amp;parent_id=<?=$id?>&amp;client_id=<?=(int)$record['client_id']?>">Add <?=h(strtolower(at_catalog()[$childKind]['label']))?></a></p><?php endif;?>
<?php // Trial options use the same labels as their form (with the score scale); a blank value says so.
$optionLabels=array_column(at_catalog()['trial_option']['fields'],'label','key');
foreach((array)($detail['children']??[]) as $child):$cv=(array)$child['values'];?><section class="card"><h4><a href="at_services.php?id=<?=$child['id']?>"><?=h($child['title'])?></a> — <?=h($child['status'])?><?=$kind==='trial'&&(string)($values['preferred_option']??'')===(string)$child['id']?' (recommended)':''?></h4><?php if($kind==='trial'):foreach(['device','tested_on','task_results','independence','ease','preference','cost','barriers'] as $k):$cell=trim((string)($cv[$k]??''));if($cell!==''&&$k==='cost')$cell=fmt_money((float)$cell);elseif($cell!==''&&$k==='tested_on')$cell=fmt_date($cell);?><p><strong><?=h($optionLabels[$k])?>:</strong> <?=$cell===''?'Not recorded':nl2br(h($cell))?></p><?php endforeach;else:?><p>Due: <?=h($cv['due_date']??'')?></p><?php endif;?></section><?php endforeach;?>
<?php if($kind==='trial'):?><p><a class="btn btn-secondary" href="at_report.php?id=<?=$id?>">Download comparison and recommendation (HTML file)</a></p><?php endif;endif;?>
<?php if(in_array($kind,['repair','reuse'],true)):?>
<section class="card"><h3><?= $kind==='repair'?'Loaner support':'Redeploy device'?></h3>
<?php // Return is offered only while the linked loan is still out; a new loaner only once it is back.
$loanOpen=!empty($record['details']['loan_id'])&&(((array)($detail['summary']??[]))['Returned']??'')==='Not returned';
if($loanOpen):?><form method="post"><?=csrf_field()?><button name="return" value="1">Return linked equipment</button></form><?php endif;?>
<?php if(!$loanOpen&&(($kind==='repair'&&!in_array($record['status'],['Resolved','Cancelled'],true))||($kind==='reuse'&&$record['status']==='Ready'))):?>
<?php if($issueReview):// The choices are read back, and focus moves to them once, before the loan is created.?>
<div id="issue-review" tabindex="-1"><h4>Check before issuing</h4>
<p>Issue <strong><?=h($issueReview['equipment_name'])?></strong> to <strong><?=h($issueReview['client_name'])?></strong>, <?=$issueReview['due']!==''?'to be returned by '.h(fmt_date($issueReview['due'])):'with no return date'?>.</p>
<p><?=$kind==='reuse'?'This creates the loan, schedules the device follow-ups and marks this reuse record Redeployed; a redeployed record can no longer be edited.':'This creates a temporary loan. Return the loaner before closing the repair.'?></p>
<form method="post" class="page-actions"><?=csrf_field()?><input type="hidden" name="loan_client_id" value="<?=(int)$issueReview['client']?>"><input type="hidden" name="loan_equipment_id" value="<?=(int)$issueReview['equipment']?>"><input type="hidden" name="loan_due" value="<?=h($issueReview['due'])?>"><input type="hidden" name="confirm_issue" value="1">
<button class="btn btn-primary" name="issue" value="1">Confirm: issue <?=h($issueReview['equipment_name'])?> to <?=h($issueReview['client_name'])?></button>
<button class="btn btn-secondary" name="issue_change" value="1">Change the client, equipment or date</button></form></div>
<script>document.addEventListener('DOMContentLoaded',function(){var e=document.getElementById('issue-review');if(e)e.focus();});</script>
<?php else:
// Nothing is preselected that the user has not chosen, even the one choice the record
// allows, so each choice is made on purpose and "required" and the server catch an
// untouched one. After a refusal or "Change", what was chosen is kept. A redeployed
// device goes only to a client whose case is open; a repair loaner only from the
// items available now.
$pickClient=$issueIn['client']??0;
$pickEquipment=$issueIn['equipment']??0;
$issueAvailable=$kind==='repair'?array_flip(array_map('intval',db()->query("SELECT id FROM equipment WHERE status='Available'")->fetchAll(PDO::FETCH_COLUMN))):null;
if($issueErrors){set_form_errors($issueErrors);echo form_error_summary(['loan_client_id'=>'','loan_equipment_id'=>'','loan_due'=>''],['loan_client_id'=>'loan-client','loan_equipment_id'=>'loan-equipment','loan_due'=>'loan-due']);}?>
<form method="post" class="stacked"><?=csrf_field()?>
<label for="loan-client">Client</label><select id="loan-client" name="loan_client_id" required <?=field_aria('loan_client_id')?>><option value="">Choose a client…</option><?php foreach(at_issue_clients($record,$actor) as $o):?><option value="<?=h($o['value'])?>" <?=(int)$o['value']===$pickClient?'selected':''?>><?=h($o['label'])?></option><?php endforeach;?></select><?=field_error('loan_client_id')?>
<label for="loan-equipment">Equipment</label><select id="loan-equipment" name="loan_equipment_id" required <?=field_aria('loan_equipment_id')?>><option value="">Choose equipment…</option><?php foreach(at_choices('equipment',$actor) as $o):if($kind==='reuse'&&(int)$o['value']!==(int)$record['equipment_id'])continue;if($kind==='repair'&&((int)$o['value']===(int)$record['equipment_id']||!isset($issueAvailable[(int)$o['value']])))continue;?><option value="<?=h($o['value'])?>" <?=(int)$o['value']===$pickEquipment?'selected':''?>><?=h($o['label'])?></option><?php endforeach;?></select><?=field_error('loan_equipment_id')?>
<label for="loan-due">Return date (optional)</label><input type="date" id="loan-due" name="loan_due" value="<?=h($issueIn['due']??'')?>" <?=field_aria('loan_due')?>><?=field_error('loan_due')?><button name="issue" value="1">Review and issue equipment</button></form><?php endif;endif;?></section>
<?php endif;?>
<?php endif;?>
<?php if($edit&&$mayEdit):?>
<h3><?=$record?'Edit record: '.h($record['title']):'New record'?></h3>
<form method="post" class="stacked"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="version" value="<?=$formVersion?>">
<label for="at-title">Title (required)</label><input type="text" id="at-title" name="title" maxlength="190" required value="<?=h((string)($values['title']??$record['title']??''))?>" <?=field_aria('title')?>><?=field_error('title')?>
<label for="at-status">Status</label><select id="at-status" name="status" <?=field_aria('status')?>><?php foreach($def['statuses'] as $s):?><option <?=($values['status']??$record['status']??$def['statuses'][0])===$s?'selected':''?>><?=h($s)?></option><?php endforeach;?></select><?=field_error('status')?>
<?php foreach($fields as $f):$v=(string)($values[$f['key']]??'');$key=$f['key'];
// What the record was created with and the server refuses to change (its client, its
// parent record, a repair or reuse record's device) is shown read-only, not offered as a choice.
$fixed=$record&&($key==='client_id'||$key==='parent_id'||($key==='equipment_id'&&in_array($kind,['repair','reuse'],true)));?><label for="at-<?=h($key)?>"><?=h($f['label'])?><?=$f['required']&&!$fixed?' (required)':''?></label>
<?php if($fixed):$shown=$v===''?'None':'Current selection (not in your list)';foreach($f['options'] as $o)if($o['value']===$v){$shown=$o['label'];break;}?><span class="hint" id="at-<?=h($key)?>-fixed">Cannot be changed after the record is created.</span><input type="text" id="at-<?=h($key)?>" value="<?=h($shown)?>" readonly <?=field_aria($key,'at-'.$key.'-fixed')?>><input type="hidden" name="<?=h($key)?>" value="<?=h($v)?>">
<?php elseif(in_array($f['type'],['reference','select'],true)):?><select id="at-<?=h($key)?>" name="<?=h($key)?>" <?=$f['required']?'required':''?> <?=field_aria($key)?>><option value="">Choose…</option><?php $found=false;foreach($f['options'] as $o):if($o['value']===$v)$found=true;?><option value="<?=h($o['value'])?>" <?=$o['value']===$v?'selected':''?>><?=h($o['label'])?></option><?php endforeach;if($v!==''&&!$found):?><option value="<?=h($v)?>" selected><?=$record&&$f['type']==='reference'?'Current selection (not in your list; kept if unchanged)':'Not available to choose — pick another option'?></option><?php endif;?></select>
<?php elseif($f['type']==='textarea'):?><textarea id="at-<?=h($key)?>" name="<?=h($key)?>" rows="4" maxlength="10000" <?=$f['required']?'required':''?> <?=field_aria($key)?>><?=h($v)?></textarea>
<?php else:$type=match($f['type']){'date'=>'date','money','integer','score'=>'number','url'=>'url',default=>'text'};?><input id="at-<?=h($key)?>" name="<?=h($key)?>" type="<?=$type?>" value="<?=h($v)?>" <?=$type==='number'?'min="'.($f['type']==='money'?'0':'1').'" step="'.($f['type']==='money'?'0.01':'1').'"':''?> <?=$f['type']==='score'?'max="5"':''?> <?=$f['required']?'required':''?> <?=field_aria($key)?>><?php endif;?>
<?=field_error($key)?><?php endforeach; at_ai_controls($kind,$def['fields']); ?><button class="btn btn-primary" name="save" value="1">Save record</button><a href="at_services.php?<?= $id?'id='.$id:'kind='.h($kind)?>">Cancel</a></form>
<?php endif;?>
<?php endif;page_footer(); ?>
