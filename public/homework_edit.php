<?php
require __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/at_homework.php';
require_once __DIR__.'/inc/at_ai.php';
require __DIR__.'/inc/layout.php';
$user=require_login();csrf_check();$id=(int)($_GET['id']??0);$error=null;
try{$homework=at_homework_load($id,(int)$user['id']);}
catch(DomainException $e){http_response_code(403);page_header('Error — Edit homework',$user,'clients');echo '<h1>Edit homework</h1>'.error_notice($e->getMessage());page_footer();exit;}
// The page names whose assignment this is, since homework titles repeat across clients.
$q=db()->prepare('SELECT t.title AS track_title,c.name AS client_name FROM training_tracks t JOIN clients c ON c.id=t.client_id WHERE t.id=?');$q->execute([(int)$homework['track_id']]);
$owner=$q->fetch()?:['track_title'=>'','client_name'=>''];
// The version the form was opened on is posted as edit_version (a name a sign-in draft
// keeps, unlike *_token), so a draft put back after signing in again is still checked
// against changes made meanwhile. Forms opened before this change post edit_token.
$formToken=old_in('edit_version',at_homework_token($homework));
if($_SERVER['REQUEST_METHOD']==='POST'){
    // After an edit conflict the user can choose to save their version over the saved
    // one: that box carries the saved version's token, so a later change is still refused.
    $input=$_POST;
    if(isset($_POST['edit_version'])&&is_string($_POST['edit_version']))$input['edit_token']=$_POST['edit_version'];
    if(isset($_POST['replace_token'])&&is_string($_POST['replace_token']))$input['edit_token']=$_POST['replace_token'];
    $formToken=is_scalar($input['edit_token']??null)?(string)$input['edit_token']:'';
    try{at_homework_update($id,$input,(int)$user['id']);flash('Homework updated.');redirect('track.php?id='.(int)$homework['track_id'].'#homework');}
    catch(DomainException $e){$error=$e->getMessage();}
    catch(Throwable $e){error_log('Homework update failed: '.get_class($e).' code='.$e->getCode().' at '.basename($e->getFile()).':'.$e->getLine());$error='Could not save the assignment. Your changes were not confirmed.';}
}
// Someone (or the client, by reporting it done) changed the assignment after this form
// was opened. $homework was read before the save, so it is the saved version.
$conflict=$error==='This assignment changed. Reload it before saving.';
// A validation message is tied to the control it is about, so the summary links
// to that control and the message is read with it. The validator reports title
// and instructions together, so the posted values say which one it means.
$fieldErrors=[];
if($error!==null&&str_starts_with($error,'Use a valid due date'))$fieldErrors['due_date']=$error;
elseif($error==='Use a title up to 190 characters and instructions up to 65,535 bytes (about 65,000 plain characters).'){
    $postedTitle=trim((string)($_POST['title']??''));
    if($postedTitle===''||mb_strlen($postedTitle)>190)$fieldErrors['title']=$postedTitle===''?'Enter a title.':'Use a title of up to 190 characters.';
    // Counted as the validator counts it, in bytes: each line break is one.
    if(strlen(trim(str_replace(["\r\n","\r"],"\n",(string)($_POST['instructions']??''))))>65535)$fieldErrors['instructions']='Use instructions of up to 65,535 bytes (about 65,000 plain characters; accented letters and symbols count as 2 to 4 bytes each).';
}
page_header(($error?'Error — ':'').'Edit homework: '.$homework['title'],$user,'clients');
$back='track.php?id='.(int)$homework['track_id'].'#homework';
?>
<h1>Edit homework: <?=h($homework['title'])?></h1>
<p>Client: <a href="client.php?id=<?=(int)$homework['client_id']?>"><?=h($owner['client_name'])?></a> · Training track: <a href="<?=h($back)?>"><?=h($owner['track_title'])?></a></p>
<p>Review revisions before saving. Editing does not mark homework completed or reopen a completed assignment.</p>
<?php // Announced once, by moving focus to it: a summary linking to each field, or a plain notice.
if($fieldErrors){set_form_errors($fieldErrors);echo form_error_summary(['title'=>'Title','instructions'=>'Instructions','due_date'=>'Due date'],['title'=>'hw-title','instructions'=>'hw-instructions','due_date'=>'hw-due_date']);}
elseif($conflict){
    $typed=trim(str_replace(["\r\n","\r"],"\n",(string)($_POST['instructions']??'')));$saved=trim(str_replace(["\r\n","\r"],"\n",(string)($homework['instructions']??'')));?>
<div class="alert alert-error" id="form-errors" tabindex="-1"><span class="visually-hidden">Error: </span>This assignment changed after you opened it, so your changes were not saved. What you typed is still in the form below. The saved version now has:
<ul><li>Title: <?=h($homework['title'])?></li>
<li>Instructions: <?=$typed===$saved?'the same as what you typed':'different from what you typed'?></li>
<li>Due date: <?=$homework['due_date']?h(fmt_date($homework['due_date'])):'none'?></li>
<li>Status: <?=h($homework['status'])?></li>
<li>Client reported it done: <?=$homework['client_reported_at']?h(fmt_datetime($homework['client_reported_at'])):'no'?></li></ul>
To keep your version, tick “Save my version over the saved one” at the end of the form and save again. Or <a href="homework_edit.php?id=<?=$id?>">discard your changes and load the saved version</a>.</div>
<script>document.addEventListener('DOMContentLoaded',function(){var e=document.getElementById('form-errors');if(e)e.focus();});</script>
<?php }
elseif($error)echo error_notice($error);?>
<form method="post" class="stacked"><?=csrf_field()?>
<input type="hidden" name="edit_version" value="<?=h($formToken)?>">
<?php // Limits are stated up front; an over-long title is refused on its field, not cut short.
// What was posted, else a draft put back after signing in again, else the saved value.
foreach(['title'=>['Title','Up to 190 characters.'],'instructions'=>['Instructions','Up to 65,535 bytes, about 65,000 plain characters.'],'due_date'=>['Due date','']] as $key=>[$label,$hint]):$value=is_string($_POST[$key]??null)?$_POST[$key]:old_in($key,(string)($homework[$key]??''));$hintId=$hint!==''?'hw-'.$key.'-hint':'';?><label for="hw-<?=$key?>"><?=$label?><?=$key==='title'?' (required)':''?><?php if($hint!==''):?> <span class="hint"><?=$hint?></span><?php endif;?></label><?php if($key==='instructions'):?><textarea id="hw-<?=$key?>" name="<?=$key?>" rows="8" <?=field_aria($key)?>><?=h($value)?></textarea><?php else:?><input id="hw-<?=$key?>" name="<?=$key?>" type="<?=$key==='due_date'?'date':'text'?>" value="<?=h($value)?>" <?=$key==='title'?'required':''?> <?=field_aria($key)?>><?php endif;?><?=field_error($key)?><?php endforeach;?>
<?php at_ai_controls('homework',[at_field('instructions','Instructions','textarea'),at_field('title','Title')],['track_id'=>(string)$homework['track_id']]);?>
<?php if($conflict):?><label><input type="checkbox" name="replace_token" value="<?=h(at_homework_token($homework))?>"> Save my version over the saved one<?=$homework['client_reported_at']?' (a changed title or instructions also clears the client’s report that it is done)':''?></label><?php endif;?>
<div class="page-actions"><button class="btn btn-primary">Save reviewed changes</button> <a class="btn btn-secondary" href="<?=h($back)?>">Cancel<span class="visually-hidden"> and return to <?=h($owner['track_title'])?></span></a></div></form>
<?php page_footer(); ?>
