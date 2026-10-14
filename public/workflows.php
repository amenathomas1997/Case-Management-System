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
$ready=workflows_ready();
$error=null;$briefing=null;$clientId=(int)($_POST['client_id']??$_GET['client_id']??0);
// Results are announced as on every other page, so the POST is handled before page_header():
// a saved change is flashed and the page reloads beside the next item (post/redirect/get), so
// focus moves to the message and the earcon plays; a refusal is shown on the control it is
// about, with what was typed kept, and the browser title starts 'Error — '.
if($ready)try{
    if($_SERVER['REQUEST_METHOD']==='POST'){
        foreach($_POST as $v)if(!is_scalar($v))throw new DomainException('Invalid form value.');
        if($view==='review'&&isset($_POST['review_note'])){
            $name=workflow_acknowledge_note((int)($_POST['note_id']??0),$actor,(string)($_POST['fingerprint']??''),(string)($_POST['rationale']??''));
            $next=(int)($_POST['next_note']??0);
            flash('Review recorded for '.$name.'. Editing the note will reopen any remaining flags.');
            redirect('workflows.php?view=review'.($next?'#note-'.$next:''));
        }
        if($view==='briefing'&&isset($_POST['generate']))$briefing=workflow_briefing($clientId,$actor,!empty($_POST['adapt']));
        if($view==='homework'&&isset($_POST['adapt']))$briefing=workflow_briefing($clientId,$actor,true);
        if($view==='waitlist'&&isset($_POST['save_waitlist'])){
            $savedReferral=workflow_waitlist_save($_POST,$actor);
            flash('Waitlist entry saved. No client was reassigned and no session was booked.');
            redirect('workflows.php?view=waitlist&referral_id='.$savedReferral.'#wl-h');
        }
        if($view==='quality'&&isset($_POST['feedback'])){
            $usageId=(int)($_POST['usage_id']??0);
            workflow_feedback($usageId,$actor,(string)($_POST['rating']??''),(string)($_POST['comment']??''));
            flash('Feedback saved for AI request #'.$usageId.'.');
            redirect('workflows.php?view=quality#usage-'.$usageId);
        }
    }
    if($clientId&&in_array($view,['briefing','homework'],true))workflow_client($clientId,$actor);
}catch(DomainException $e){$error=$e->getMessage();}
catch(Throwable $e){error_log('Workflow failed: '.get_class($e).' code='.$e->getCode());$error='The request could not be completed. Please try again.';}
page_header(($error!==null||!$ready?'Error — ':'').$titles[$view],$user,'workflows');
echo '<h1>'.h($titles[$view]).'</h1><nav aria-label="Workflow tools" class="page-actions">';
foreach($titles as $key=>$title){if(in_array($key,['review','briefing','homework'],true)&&!can('clients'))continue;if($key==='waitlist'&&!can('referrals'))continue;echo '<a href="workflows.php?view='.$key.'"'.($view===$key?' aria-current="page"':'').'>'.h($title).'</a>';}
echo '</nav>';
if(!$ready){echo error_notice('Apply database migration 3.0.16 to enable these workflows.');page_footer();exit;}
// The review, waitlist and AI quality views show a refusal on the field it is about (below).
if($error!==null&&in_array($view,['actions','briefing','homework'],true))echo error_notice($error);
if($view==='actions'):
$items=workflow_action_items($user);
?>
<p>Open tasks, referral follow-ups, session outcomes, documentation flags, authorization expirations, homework support, and AT service deadlines. Items are ordered by date. Assigned tasks retain their existing access rules.</p>
<?php if(!$items):?><p>No action items found.</p><?php endif;?>
<ol><?php foreach($items as $item):?><li><a href="<?=h($item['url'])?>"><?=h($item['title'])?></a> — <?=h($item['kind'])?><?= $item['due']?' · '.h($item['due']).($item['due']<date('Y-m-d')?' (overdue)':''):'' ?></li><?php endforeach;?></ol>
<?php elseif($view==='review'):
$notes=workflow_review_notes($actor);
$noteName=fn(array $n):string=>$n['client_name'].' — '.(trim((string)$n['subject'])!==''?$n['subject']:'untitled note #'.(int)$n['id']);
// A refused review is shown on that note's rationale, naming the note, with what was typed kept.
$failedNote=$error!==null&&isset($_POST['review_note'])?(int)($_POST['note_id']??0):0;$failedName=null;
foreach($notes as $n)if((int)$n['id']===$failedNote)$failedName=$noteName($n);
if($failedName!==null){set_form_errors(['reason-'.$failedNote=>$error]);echo form_error_summary(['reason-'.$failedNote=>'Why is this flag acceptable? ('.$failedName.')']);}
elseif($error!==null)echo error_notice($error);
// A draft put back after signing in again refills its own note's rationale with the fingerprint
// it was written against, so an edit made to the note meanwhile is still caught when it is sent.
$restoredNote=$failedNote?0:(int)old_in('note_id');
?>
<p>Checks the newest 1,000 accessible notes for missing sections and unresolved placeholders. These are review prompts, not a clinical assessment or a guarantee of accuracy. Fix the source note, or document why the flag is acceptable.</p>
<?php if(!$notes):?><p>No unresolved flags in the reviewed notes.</p><?php endif;?>
<?php // Repeated controls carry the note's name in visually-hidden text; a recorded review returns to the next card (else the previous one).
foreach($notes as $i=>$n):$nid=(int)$n['id'];$name=$noteName($n);$ctx='<span class="visually-hidden"> — '.h($name).'</span>';?><section class="card" id="note-<?=$nid?>"><h2><?=h($name)?></h2><p><?=h(implode('; ',$n['flags']))?></p><p><a href="client.php?id=<?=(int)$n['client_id']?>">Open the client record<?=$ctx?></a></p><details><summary>Review note text<?=$ctx?></summary><?=plain_text_html((string)$n['body'])?><?php foreach(['birp_behavior','birp_intervention','birp_response','birp_plan'] as $key)if(!empty($n[$key]))echo plain_text_html($n[$key]);?></details><form method="post" class="stacked"><?=csrf_field()?><input type="hidden" name="review_note" value="1"><input type="hidden" name="note_id" value="<?=$nid?>"><input type="hidden" name="next_note" value="<?=(int)($notes[$i+1]['id']??$notes[$i-1]['id']??0)?>"><input type="hidden" name="fingerprint" value="<?=h($nid===$restoredNote&&old_in('fingerprint')!==''?old_in('fingerprint'):workflow_note_fingerprint($n))?>"><label for="reason-<?=$nid?>">Why is this flag acceptable?<?=$ctx?></label><textarea id="reason-<?=$nid?>" name="rationale" maxlength="1000" required <?=field_aria('reason-'.$nid)?>><?=h($nid===$failedNote?(string)($_POST['rationale']??''):($nid===$restoredNote?old_in('rationale'):''))?></textarea><?=field_error('reason-'.$nid)?><button class="btn btn-secondary">Record review<?=$ctx?></button></form></section><?php endforeach;?>
<?php elseif($view==='briefing'||$view==='homework'): ?>
<form method="<?= $view==='briefing'?'post':'get' ?>" class="stacked"><?php if($view==='briefing')echo csrf_field();?><input type="hidden" name="view" value="<?=h($view)?>"><label for="brief-client">Client</label><select id="brief-client" name="client_id" required><option value="">Choose a client</option><?php foreach(at_choices('client',$actor) as $c):?><option value="<?=h($c['value'])?>" <?= (int)$c['value']===$clientId?'selected':'' ?>><?=h($c['label'])?></option><?php endforeach;?></select><button name="generate" value="1" class="btn btn-primary"><?= $view==='briefing'?'Generate briefing':'Show practice updates' ?></button></form>
<p>AI output is a draft. Verify its claims against the linked records. Generating a draft does not change assignments, client records, or billing. Briefings use the 15 newest notes first, then up to 20 tracks (active tracks first) with 10 goals each, the 10 newest homework assignments and the 10 newest client practice reports, up to 40,000 characters in all. The draft says when records were left out.</p>
<?php if($view==='homework'&&$clientId&&!$error):
$q=db()->prepare('SELECT p.*,h.title,h.id AS homework_id FROM homework_practice p JOIN homework h ON h.id=p.homework_id JOIN training_tracks t ON t.id=h.track_id WHERE t.client_id=? ORDER BY p.created_at DESC,p.id DESC LIMIT 100');$q->execute([$clientId]);$practice=$q->fetchAll();?>
<p>Newest 100 practice updates. Client reports do not mark homework complete.</p>
<?php foreach($practice as $p):?><section class="card"><h2><?=h($p['title'].' — '.fmt_datetime($p['created_at']))?></h2><p><?=h($p['minutes'].' minutes — '.$p['difficulty'])?></p><?=plain_text_html($p['notes'])?><a href="homework_edit.php?id=<?=(int)$p['homework_id']?>">Review or edit this assignment<span class="visually-hidden"> — <?=h($p['title'])?></span></a></section><?php endforeach;?>
<?php if(!$practice):?><p>No practice updates for this client yet.</p><?php endif;?>
<form method="post"><?=csrf_field()?><input type="hidden" name="client_id" value="<?=$clientId?>"><button name="adapt" value="1" class="btn btn-secondary">Draft homework adaptations for review</button></form>
<?php endif;?>
<?php if($briefing):// The new draft is announced by moving focus to its heading, with the success earcon. ?><section class="card" aria-labelledby="draft-title"><h2 id="draft-title" tabindex="-1">AI draft — verify before use</h2><?php if($briefing['omitted']):?><p><?=(int)$briefing['omitted']?> older or lower-priority record<?= $briefing['omitted']===1?' was':'s were' ?> left out to fit the AI size limit, so this draft may be incomplete.</p><?php endif;?><?php foreach($briefing['items'] as $item):?><h3><?= $item['type']==='fact'?'Record summary':'Proposed next step' ?></h3><?=plain_text_html($item['text'])?><ul><?php foreach($item['sources'] as $source):?><li><a href="<?=h($source['url'])?>"><?=h($source['label'])?></a></li><?php endforeach;?></ul><?php endforeach;?><a href="workflows.php?view=quality">Rate this AI request<?= $briefing['usage_id']?' (#'.(int)$briefing['usage_id'].')':'' ?></a></section>
<script>document.addEventListener("DOMContentLoaded",function(){var d=document.getElementById("draft-title");if(d){d.focus();if(window.Earcon)Earcon.play("success");}});</script><?php endif;?>
<?php elseif($view==='waitlist'):
$rid=(int)($_GET['referral_id']??$_POST['referral_id']??0);
// Closed entries are listed after every open one.
$q=db()->query("SELECT r.id,r.client_name,r.created_at AS received_at,w.* FROM referrals r LEFT JOIN referral_waitlist w ON w.referral_id=r.id WHERE r.status<>'Converted'".(caseload_scoped()?' AND (r.assigned_to IS NULL OR r.assigned_to='.$actor.')':'')." ORDER BY COALESCE(w.status,'')='Closed',r.created_at,r.id");$rows=$q->fetchAll();$selected=null;foreach($rows as $r)if((int)$r['id']===$rid)$selected=$r;
$waitValues=$selected;
$waitPosted=$selected&&$error!==null&&isset($_POST['save_waitlist']);
// Someone else saved first: the form keeps what this user typed but carries the version now
// saved, so saving again knowingly replaces it; the saved entry is listed before the form.
$waitConflict=$waitPosted&&str_starts_with($error,'Someone else saved this waitlist entry');
if($waitPosted){foreach(['version','expertise','languages','areas','availability','modality','status','followup_date'] as $key)if(($key!=='version'||!$waitConflict)&&is_scalar($_POST[$key]??null))$waitValues[$key]=(string)$_POST[$key];}
// A draft put back after signing in again refills this referral's form with the version it was
// written against, so a save made by someone else meanwhile is still caught when it is sent.
elseif($selected&&(int)old_in('referral_id')===$rid)foreach(['version','expertise','languages','areas','availability','modality','status','followup_date'] as $key)$waitValues[$key]=old_in($key,(string)($waitValues[$key]??''));
// A refusal names its field first ('Follow-up date: …'), so it is shown on that control.
$waitField=null;
if($waitPosted)foreach(['Status: '=>['status','wl-status'],'Delivery mode: '=>['modality','wl-mode'],'Skills needed: '=>['expertise','wl-expertise'],'Language / communication: '=>['languages','wl-languages'],'Service areas: '=>['areas','wl-areas'],'Client availability: '=>['availability','wl-availability'],'Follow-up date: '=>['followup_date','wl-due']] as $prefix=>$f)if(str_starts_with($error,$prefix))$waitField=$f;
if($waitField){set_form_errors([$waitField[0]=>$error]);echo form_error_summary([$waitField[0]=>''],[$waitField[0]=>$waitField[1]]);}
elseif($waitConflict)echo error_notice($error.' The entry as it is now saved is listed below, then the form with what you typed. Choose Save waitlist entry to replace the saved entry with what you typed.');
elseif($error!==null)echo error_notice($error);
?>
<p>Open referrals, oldest first; closed waitlist entries are listed last. Matching considers recorded skills, language, service area, mode, and booked capacity. Staff must verify availability and caseload access before assigning. Converted referrals leave this queue.</p>
<ul><?php foreach($rows as $r):?><li><a href="workflows.php?view=waitlist&amp;referral_id=<?=(int)$r['id']?>"><?=h($r['client_name'])?></a> — <?=h($r['status']??'Not waitlisted')?> · received <?=h(substr($r['received_at'],0,10))?><?= $r['followup_date']?' · follow up '.h($r['followup_date']):'' ?></li><?php endforeach;?></ul>
<?php if($selected):?><h2 id="wl-h"><?=h($selected['client_name'])?></h2><a href="referrals.php?view=<?=$rid?>">Open referral to review or assign</a>
<?php if($waitConflict):?><section class="card" aria-labelledby="wl-saved-h"><h3 id="wl-saved-h">Waitlist entry as now saved</h3><ul><?php foreach(['status'=>'Status','modality'=>'Delivery mode','followup_date'=>'Follow-up date','expertise'=>'Skills needed','languages'=>'Language / communication','areas'=>'Service areas','availability'=>'Client availability'] as $k=>$label):?><li><?=h($label)?>: <?=h((string)($selected[$k]??'')!==''?(string)$selected[$k]:'(blank)')?></li><?php endforeach;?></ul></section><?php endif;?>
<form method="post" class="stacked"><?=csrf_field()?><input type="hidden" name="referral_id" value="<?=$rid?>"><input type="hidden" name="version" value="<?=(int)($waitValues['version']??0)?>">
<?php foreach(['expertise'=>'Skills needed (comma-separated)','languages'=>'Language / communication (comma-separated)','areas'=>'Service areas (comma-separated)','availability'=>'Client availability'] as $k=>$label):?><label for="wl-<?=$k?>"><?=h($label)?></label><textarea id="wl-<?=$k?>" name="<?=$k?>" maxlength="<?= $k==='availability'?1000:($k==='expertise'?500:190) ?>" <?=field_aria($k)?>><?=h($waitValues[$k]??'')?></textarea><?=field_error($k)?><?php endforeach;?>
<label for="wl-mode">Delivery mode</label><select id="wl-mode" name="modality" <?=field_aria('modality')?>><?=select_options(['In person','Remote','Both'],$waitValues['modality']??'Both')?></select><?=field_error('modality')?><label for="wl-status">Status</label><select id="wl-status" name="status" <?=field_aria('status')?>><?=select_options(['Waiting','Contacted','Ready','Closed'],$waitValues['status']??'Waiting')?></select><?=field_error('status')?><label for="wl-due">Follow-up date</label><input type="date" id="wl-due" name="followup_date" value="<?=h($waitValues['followup_date']??'')?>" <?=field_aria('followup_date')?>><?=field_error('followup_date')?><button name="save_waitlist" value="1" class="btn btn-primary">Save waitlist entry</button></form>
<?php // 'Both' means the client can meet either way, so it does not narrow the suggestions.
if($selected['referral_id']&&at_can('schedule')):?><h3>Suggested instructors</h3><p>Matching uses saved requirements and capacity over the next seven days. A suggestion is not an assignment.</p><ul><?php foreach(at_matches(['modality'=>$selected['modality']==='Both'?'':(string)$selected['modality']]+$selected,$actor) as $m):?><li><?=h($m['name'])?> — <?=h($m['matched']?:'No exact skill matches')?>; missing: <?=h($m['missing']?:'none')?>; <?=h((string)$m['available_hours'])?> available hours; stated availability: <?=h($m['availability'])?></li><?php endforeach;?></ul><?php endif;?><?php endif;?>
<?php elseif($view==='quality'):
$where=can('admin')?'':' WHERE u.user_id='.$actor;
$q=db()->query('SELECT u.*,f.rating,f.comment FROM ai_usage u LEFT JOIN ai_feedback f ON f.usage_id=u.id AND f.user_id='.$actor.$where.' ORDER BY u.id DESC LIMIT 100');$usage=$q->fetchAll();
// A refused rating is shown on its field, in its request (left open), with what was typed kept.
$failedUsage=$error!==null&&isset($_POST['feedback'])?(int)($_POST['usage_id']??0):0;$failedField=null;
if($failedUsage&&in_array($failedUsage,array_map('intval',array_column($usage,'id')),true)){if(str_starts_with($error,'Rating: '))$failedField='rating-'.$failedUsage;elseif(str_starts_with($error,'Feedback: '))$failedField='comment-'.$failedUsage;}
if($failedField){set_form_errors([$failedField=>$error]);echo form_error_summary([$failedField=>'']);}
elseif($error!==null)echo error_notice($error);
?>
<p>Newest 100 AI requests<?=can('admin')?' across the organization':' made by you'?>. Historical outcomes may be unknown. Character counts are usage estimates, not token counts or billing costs. Do not include client details in feedback.</p>
<?php // Features that do not record an outcome are counted as such, not as successes or 0 ms.
if(can('admin')):?><p><a href="ai.php">Enable or disable AI features</a></p><?php $summary=db()->query("SELECT feature,COUNT(*) total,SUM(succeeded=0) failures,SUM(succeeded IS NULL) unknown,SUM(truncated=1) cut_off,ROUND(AVG(duration_ms)) latency FROM ai_usage WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY feature")->fetchAll();?><h2>Last 30 days</h2><ul><?php foreach($summary as $s):?><li><?=h($s['feature'])?>: <?=(int)$s['total']?> calls, <?=(int)$s['failures']?> failures, <?=(int)$s['cut_off']?> cut off, <?=(int)$s['unknown']?> with no recorded outcome; mean provider time: <?= $s['latency']===null?'not recorded':(int)$s['latency'].' ms' ?></li><?php endforeach;?></ul><h2>Staff feedback</h2><?php foreach(db()->query('SELECT f.*,u.feature FROM ai_feedback f JOIN ai_usage u ON u.id=f.usage_id ORDER BY f.created_at DESC LIMIT 100')->fetchAll() as $f):?><p><?=h($f['feature'].' #'.$f['usage_id'].' — '.$f['rating'].': '.$f['comment'])?></p><?php endforeach;?><?php endif;?>
<?php // Each request's controls name it in visually-hidden text; a saved rating returns here, beside that request.
foreach($usage as $u):$usageId=(int)$u['id'];$failed=$usageId===$failedUsage;$ctx='<span class="visually-hidden"> for request #'.$usageId.' ('.h($u['feature']).')</span>';?><div id="usage-<?=$usageId?>"><details class="card"<?= $failed?' open':'' ?>><summary>#<?=$usageId?> <?=h($u['feature'].' — '.$u['created_at'])?> — <?= $u['succeeded']===null?'Unknown':($u['succeeded']?'Succeeded':'Failed') ?><?= $u['truncated']?' (cut off)':'' ?></summary><p><?=h($u['provider'].' / '.$u['model'])?>; <?=(int)$u['chars_in']?> input characters, <?=(int)$u['chars_out']?> output characters.</p><form method="post"><?=csrf_field()?><input type="hidden" name="usage_id" value="<?=$usageId?>"><label for="rating-<?=$usageId?>">Rating<?=$ctx?></label><select name="rating" id="rating-<?=$usageId?>" <?=field_aria('rating-'.$usageId)?>><?=select_options(['Helpful','Incorrect','Missing information','Accessibility issue'],$failed?(string)($_POST['rating']??''):$u['rating'])?></select><?=field_error('rating-'.$usageId)?><label for="comment-<?=$usageId?>">Feedback (no client information)<?=$ctx?></label><textarea name="comment" id="comment-<?=$usageId?>" maxlength="1000" <?=field_aria('comment-'.$usageId)?>><?=h($failed?(string)($_POST['comment']??''):($u['comment']??''))?></textarea><?=field_error('comment-'.$usageId)?><button name="feedback" value="1" class="btn btn-secondary">Save feedback<?=$ctx?></button></form></details></div><?php endforeach;?>
<?php endif;page_footer();
