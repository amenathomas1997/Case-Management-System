<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__.'/inc/workflows.php';
require __DIR__.'/inc/layout.php';
$user=require_login();csrf_check();$actor=(int)$user['id'];
$view=is_string($_GET['view']??null)?$_GET['view']:'actions';
$titles=['actions'=>'Daily action center','review'=>'Documentation review','briefing'=>'AI case briefing','homework'=>'Homework support','waitlist'=>'Referral waitlist','quality'=>'AI quality'];
if(!isset($titles[$view])){http_response_code(404);exit('Page not found.');}
if(in_array($view,['review','briefing','homework'],true))require_perm('clients');
if($view==='waitlist')require_perm('referrals');
page_header($titles[$view],$user,'workflows');
echo '<h1>'.h($titles[$view]).'</h1><nav aria-label="Workflow tools" class="page-actions">';
foreach($titles as $key=>$title){if(in_array($key,['review','briefing','homework'],true)&&!can('clients'))continue;if($key==='waitlist'&&!can('referrals'))continue;echo '<a href="workflows.php?view='.$key.'"'.($view===$key?' aria-current="page"':'').'>'.h($title).'</a>';}
echo '</nav>';
if(!workflows_ready()){echo error_notice('Apply database migration 3.0.16 to enable these workflows.');page_footer();exit;}
$error=null;$briefing=null;$clientId=(int)($_POST['client_id']??$_GET['client_id']??0);
try{
    if($_SERVER['REQUEST_METHOD']==='POST'){
        foreach($_POST as $v)if(!is_scalar($v))throw new DomainException('Invalid form value.');
        if($view==='review'&&isset($_POST['review_note'])){workflow_acknowledge_note((int)$_POST['note_id'],$actor,(string)$_POST['fingerprint'],(string)$_POST['rationale']);echo '<p role="status">Review recorded. Editing the note will reopen any remaining flags.</p>';}
        if($view==='briefing'&&isset($_POST['generate']))$briefing=workflow_briefing($clientId,$actor,!empty($_POST['adapt']));
        if($view==='homework'&&isset($_POST['adapt']))$briefing=workflow_briefing($clientId,$actor,true);
        if($view==='waitlist'&&isset($_POST['save_waitlist'])){workflow_waitlist_save($_POST,$actor);echo '<p role="status">Waitlist saved. No client was reassigned and no session was booked.</p>';}
        if($view==='quality'&&isset($_POST['feedback'])){workflow_feedback((int)$_POST['usage_id'],$actor,(string)$_POST['rating'],(string)$_POST['comment']);echo '<p role="status">Feedback saved.</p>';}
    }
    if($clientId&&in_array($view,['briefing','homework'],true))workflow_client($clientId,$actor);
}catch(DomainException $e){$error=$e->getMessage();}
catch(Throwable $e){error_log('Workflow failed: '.get_class($e).' code='.$e->getCode());$error='The request could not be completed. Please try again.';}
if($error)echo error_notice($error);
if($view==='actions'):
$items=workflow_action_items($user);
?>
<p>Open tasks, referral follow-ups, session outcomes, documentation flags, authorization expirations, homework support, and AT service deadlines. Items are ordered by date. Assigned tasks retain their existing access rules.</p>
<?php if(!$items):?><p>No action items found.</p><?php endif;?>
<ol><?php foreach($items as $item):?><li><a href="<?=h($item['url'])?>"><?=h($item['title'])?></a> — <?=h($item['kind'])?><?= $item['due']?' · '.h($item['due']).($item['due']<date('Y-m-d')?' (overdue)':''):'' ?></li><?php endforeach;?></ol>
<?php elseif($view==='review'): ?>
<p>Checks the newest 1,000 accessible notes for missing sections and unresolved placeholders. These are review prompts, not a clinical assessment or a guarantee of accuracy. Fix the source note, or document why the flag is acceptable.</p>
<?php $notes=workflow_review_notes($actor);if(!$notes):?><p>No unresolved flags in the reviewed notes.</p><?php endif;?>
<?php foreach($notes as $n):?><section class="card"><h2><?=h($n['client_name'].' — '.$n['subject'])?></h2><p><?=h(implode('; ',$n['flags']))?></p><p><a href="client.php?id=<?=(int)$n['client_id']?>">Open the client record</a></p><details><summary>Review note text</summary><?=plain_text_html((string)$n['body'])?><?php foreach(['birp_behavior','birp_intervention','birp_response','birp_plan'] as $key)if(!empty($n[$key]))echo plain_text_html($n[$key]);?></details><form method="post" class="stacked"><?=csrf_field()?><input type="hidden" name="review_note" value="1"><input type="hidden" name="note_id" value="<?=(int)$n['id']?>"><input type="hidden" name="fingerprint" value="<?=h(workflow_note_fingerprint($n))?>"><label for="reason-<?=(int)$n['id']?>">Why is this flag acceptable?</label><textarea id="reason-<?=(int)$n['id']?>" name="rationale" maxlength="1000" required></textarea><button class="btn btn-secondary">Record review</button></form></section><?php endforeach;?>
<?php elseif($view==='briefing'||$view==='homework'): ?>
<form method="<?= $view==='briefing'?'post':'get' ?>" class="stacked"><?php if($view==='briefing')echo csrf_field();?><input type="hidden" name="view" value="<?=h($view)?>"><label for="brief-client">Client</label><select id="brief-client" name="client_id" required><option value="">Choose a client</option><?php foreach(at_choices('client',$actor) as $c):?><option value="<?=h($c['value'])?>" <?= (int)$c['value']===$clientId?'selected':'' ?>><?=h($c['label'])?></option><?php endforeach;?></select><button name="generate" value="1" class="btn btn-primary"><?= $view==='briefing'?'Generate briefing':'Show practice updates' ?></button></form>
<p>AI output is a draft. Verify its claims against the linked records. Generating a draft does not change assignments, client records, or billing. Briefings use up to 20 tracks, 10 goals per track, 15 recent notes and 10 recent homework assignments.</p>
<?php if($view==='homework'&&$clientId&&!$error):
$q=db()->prepare('SELECT p.*,h.title,h.id AS homework_id FROM homework_practice p JOIN homework h ON h.id=p.homework_id JOIN training_tracks t ON t.id=h.track_id WHERE t.client_id=? ORDER BY p.created_at DESC,p.id DESC LIMIT 100');$q->execute([$clientId]);$practice=$q->fetchAll();?>
<p>Newest 100 practice updates. Client reports do not mark homework complete.</p>
<?php foreach($practice as $p):?><section class="card"><h2><?=h($p['title'])?></h2><p><?=h($p['created_at'].' — '.$p['minutes'].' minutes — '.$p['difficulty'])?></p><?=plain_text_html($p['notes'])?><a href="homework_edit.php?id=<?=(int)$p['homework_id']?>">Review or edit this assignment</a></section><?php endforeach;?>
<?php if(!$practice):?><p>No practice updates for this client yet.</p><?php endif;?>
<form method="post"><?=csrf_field()?><input type="hidden" name="client_id" value="<?=$clientId?>"><button name="adapt" value="1" class="btn btn-secondary">Draft homework adaptations for review</button></form>
<?php endif;?>
<?php if($briefing):?><section class="card" aria-labelledby="draft-title"><h2 id="draft-title">AI draft — verify before use</h2><?php foreach($briefing['items'] as $item):?><h3><?= $item['type']==='fact'?'Record summary':'Proposed next step' ?></h3><?=plain_text_html($item['text'])?><ul><?php foreach($item['sources'] as $source):?><li><a href="<?=h($source['url'])?>"><?=h($source['label'])?></a></li><?php endforeach;?></ul><?php endforeach;?><a href="workflows.php?view=quality">Rate this AI request<?= $briefing['usage_id']?' (#'.(int)$briefing['usage_id'].')':'' ?></a></section><?php endif;?>
<?php elseif($view==='waitlist'):
$rid=(int)($_GET['referral_id']??$_POST['referral_id']??0);
$q=db()->query("SELECT r.id,r.client_name,r.created_at AS received_at,w.* FROM referrals r LEFT JOIN referral_waitlist w ON w.referral_id=r.id WHERE r.status<>'Converted'".(caseload_scoped()?' AND (r.assigned_to IS NULL OR r.assigned_to='.$actor.')':'').' ORDER BY r.created_at,r.id');$rows=$q->fetchAll();$selected=null;foreach($rows as $r)if((int)$r['id']===$rid)$selected=$r;
$waitValues=$selected;
if($selected&&$error&&isset($_POST['save_waitlist']))foreach(['version','expertise','languages','areas','availability','modality','status','followup_date'] as $key)if(is_scalar($_POST[$key]??null))$waitValues[$key]=(string)$_POST[$key];
?>
<p>Open referrals, oldest first. Matching considers recorded skills, language, service area, mode, and booked capacity. Staff must verify availability and caseload access before assigning. Converted referrals leave this queue.</p>
<ul><?php foreach($rows as $r):?><li><a href="workflows.php?view=waitlist&amp;referral_id=<?=(int)$r['id']?>"><?=h($r['client_name'])?></a> — <?=h($r['status']??'Not waitlisted')?> · received <?=h(substr($r['received_at'],0,10))?><?= $r['followup_date']?' · follow up '.h($r['followup_date']):'' ?></li><?php endforeach;?></ul>
<?php if($selected):?><h2><?=h($selected['client_name'])?></h2><a href="referrals.php?view=<?=$rid?>">Open referral to review or assign</a><form method="post" class="stacked"><?=csrf_field()?><input type="hidden" name="referral_id" value="<?=$rid?>"><input type="hidden" name="version" value="<?=(int)($waitValues['version']??0)?>">
<?php foreach(['expertise'=>'Skills needed (comma-separated)','languages'=>'Language / communication (comma-separated)','areas'=>'Service areas (comma-separated)','availability'=>'Client availability'] as $k=>$label):?><label for="wl-<?=$k?>"><?=h($label)?></label><textarea id="wl-<?=$k?>" name="<?=$k?>" maxlength="<?= $k==='availability'?1000:($k==='expertise'?500:190) ?>"><?=h($waitValues[$k]??'')?></textarea><?php endforeach;?>
<label for="wl-mode">Delivery mode</label><select id="wl-mode" name="modality"><?=select_options(['In person','Remote','Both'],$waitValues['modality']??'Both')?></select><label for="wl-status">Status</label><select id="wl-status" name="status"><?=select_options(['Waiting','Contacted','Ready','Closed'],$waitValues['status']??'Waiting')?></select><label for="wl-due">Follow-up date</label><input type="date" id="wl-due" name="followup_date" value="<?=h($waitValues['followup_date']??'')?>"><button name="save_waitlist" value="1" class="btn btn-primary">Save waitlist entry</button></form>
<?php if($selected['referral_id']&&at_can('schedule')):?><h3>Suggested instructors</h3><p>Matching uses saved requirements and capacity over the next seven days. A suggestion is not an assignment.</p><ul><?php foreach(at_matches($selected,$actor) as $m):?><li><?=h($m['name'])?> — <?=h($m['matched']?:'No exact skill matches')?>; missing: <?=h($m['missing']?:'none')?>; <?=h((string)$m['available_hours'])?> available hours; stated availability: <?=h($m['availability'])?></li><?php endforeach;?></ul><?php endif;?><?php endif;?>
<?php elseif($view==='quality'):
$where=can('admin')?'':' WHERE u.user_id='.$actor;
$q=db()->query('SELECT u.*,f.rating,f.comment FROM ai_usage u LEFT JOIN ai_feedback f ON f.usage_id=u.id AND f.user_id='.$actor.$where.' ORDER BY u.id DESC LIMIT 100');$usage=$q->fetchAll();
?>
<p>Newest 100 AI requests<?=can('admin')?' across the organization':' made by you'?>. Historical outcomes may be unknown. Character counts are usage estimates, not token counts or billing costs. Do not include client details in feedback.</p>
<?php if(can('admin')):?><p><a href="ai.php">Enable or disable AI features</a></p><?php $summary=db()->query("SELECT feature,COUNT(*) total,SUM(succeeded=0) failures,SUM(truncated=1) cut_off,ROUND(AVG(duration_ms)) latency FROM ai_usage WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY feature")->fetchAll();?><h2>Last 30 days</h2><ul><?php foreach($summary as $s):?><li><?=h($s['feature'])?>: <?=(int)$s['total']?> calls, <?=(int)$s['failures']?> failures, <?=(int)$s['cut_off']?> cut off, <?=(int)$s['latency']?> ms mean provider time</li><?php endforeach;?></ul><h2>Staff feedback</h2><?php foreach(db()->query('SELECT f.*,u.feature FROM ai_feedback f JOIN ai_usage u ON u.id=f.usage_id ORDER BY f.created_at DESC LIMIT 100')->fetchAll() as $f):?><p><?=h($f['feature'].' #'.$f['usage_id'].' — '.$f['rating'].': '.$f['comment'])?></p><?php endforeach;?><?php endif;?>
<?php foreach($usage as $u):?><details class="card"><summary>#<?=(int)$u['id']?> <?=h($u['feature'].' — '.$u['created_at'])?> — <?= $u['succeeded']===null?'Unknown':($u['succeeded']?'Succeeded':'Failed') ?><?= $u['truncated']?' (cut off)':'' ?></summary><p><?=h($u['provider'].' / '.$u['model'])?>; <?=(int)$u['chars_in']?> input characters, <?=(int)$u['chars_out']?> output characters.</p><form method="post"><?=csrf_field()?><input type="hidden" name="usage_id" value="<?=(int)$u['id']?>"><label for="rating-<?=(int)$u['id']?>">Rating</label><select name="rating" id="rating-<?=(int)$u['id']?>"><?=select_options(['Helpful','Incorrect','Missing information','Accessibility issue'],$u['rating'])?></select><label for="comment-<?=(int)$u['id']?>">Feedback (no client information)</label><textarea name="comment" id="comment-<?=(int)$u['id']?>" maxlength="1000"><?=h($u['comment']??'')?></textarea><button name="feedback" value="1" class="btn btn-secondary">Save feedback</button></form></details><?php endforeach;?>
<?php endif;page_footer();
