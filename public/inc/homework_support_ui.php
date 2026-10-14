<?php // Included inside the token-scoped homework list; $h_, $client and $token come from portal.php.
$hwPosted=(int)($_POST['homework_id']??0)===(int)$h_['id'];
$hwValue=fn(string $key):string=>$hwPosted&&is_scalar($_POST[$key]??null)?(string)$_POST[$key]:'';
// Interface copy goes through t() (Spanish where translated, otherwise marked English); the
// homework title is record data, so en(). Repeated controls name the homework in hidden text.
$hwId=(int)$h_['id'];$hwName='<span class="visually-hidden"> — '.en((string)$h_['title']).'</span>';
// A refused update keeps what was typed and marks each field that was wrong (the message
// itself is announced once, at the top of the page).
$hwErrors=$hwPosted&&isset($_POST['hw_practice'])?workflow_practice_errors($_POST):[];
$hwField=fn(string $field,string $id,string $hint=''):string=>(isset($hwErrors[$field])?'aria-invalid="true" ':'').(($ids=array_filter([$hint,isset($hwErrors[$field])?$id.'-err':'']))?'aria-describedby="'.h(implode(' ',$ids)).'"':'');
$hwFieldError=fn(string $field,string $id):string=>isset($hwErrors[$field])?'<p class="field-error" id="'.h($id).'-err">'.t($hwErrors[$field]).'</p>':'';
// Any other refusal (portal.php's 'hw_error': a daily limit, an out-of-date form, the question)
// is tied to the control the message above links to: the minutes, or the question.
$hwRefused=$hwPosted&&has_field_error('hw_error');
$hwOnMinutes=$hwRefused&&!$hwErrors&&isset($_POST['hw_practice']);$hwOnQuestion=$hwRefused&&!isset($_POST['hw_practice']);
// A submission key is reused only while nothing was saved under it: once it was, a corrected
// update gets a new key instead of being refused as a changed copy of the saved one.
$hwKey=$hwValue('request_key');
if(preg_match('/^[a-f0-9]{32}$/D',$hwKey)){$hwQ=db()->prepare('SELECT 1 FROM homework_practice WHERE request_key=?');$hwQ->execute([$hwKey]);if($hwQ->fetchColumn())$hwKey='';}else $hwKey='';
?>
<details<?= $hwRefused?' open':'' ?>><summary><?=t('Practice feedback and help')?> — <?=en((string)$h_['title'])?></summary>
<form method="post" action="portal.php?t=<?=h($token)?>&amp;lang=<?=h(page_lang())?>" class="stacked">
<?=csrf_field()?><input type="hidden" name="homework_id" value="<?=$hwId?>"><input type="hidden" name="request_key" value="<?=h($hwKey!==''?$hwKey:bin2hex(random_bytes(16)))?>">
<p class="hint" id="hw-prac-note-<?=$hwId?>"><?=t('Your instructor can read this update. “Need help” adds a follow-up task. This does not mark your homework complete.')?></p>
<label for="hw-min-<?=$hwId?>"><?=t('Minutes practiced')?><?=$hwName?></label><input type="number" name="practice_minutes" id="hw-min-<?=$hwId?>" min="1" max="1440" value="<?=h($hwValue('practice_minutes'))?>" required <?=$hwField('practice_minutes','hw-min-'.$hwId)?><?=$hwOnMinutes?field_aria('hw_error'):''?>><?=$hwFieldError('practice_minutes','hw-min-'.$hwId)?><?=$hwOnMinutes?field_error('hw_error'):''?>
<label for="hw-difficulty-<?=$hwId?>"><?=t('How did it go?')?><?=$hwName?></label><select name="difficulty" id="hw-difficulty-<?=$hwId?>" required <?=$hwField('difficulty','hw-difficulty-'.$hwId)?>><option value=""<?= page_lang()==='es'&&__('Choose how it went')==='Choose how it went'?' lang="en"':'' ?>><?=h(__('Choose how it went'))?></option><?=select_options(['Comfortable','Challenging','Need help'],$hwValue('difficulty'))?></select><?=$hwFieldError('difficulty','hw-difficulty-'.$hwId)?>
<label for="hw-notes-<?=$hwId?>"><?=t('What worked or was difficult?')?><?=$hwName?></label><textarea name="practice_notes" id="hw-notes-<?=$hwId?>" maxlength="2000" <?=$hwField('practice_notes','hw-notes-'.$hwId,'hw-prac-note-'.$hwId)?>><?=h($hwValue('practice_notes'))?></textarea><?=$hwFieldError('practice_notes','hw-notes-'.$hwId)?>
<button class="btn btn-secondary" name="hw_practice" value="1"><?=t('Share practice update')?><?=$hwName?></button>
</form>
<?php if(ai_feature_enabled('portal_homework')): ?>
<form method="post" action="portal.php?t=<?=h($token)?>&amp;lang=<?=h(page_lang())?>" class="stacked"><?=csrf_field()?><input type="hidden" name="homework_id" value="<?=$hwId?>">
<p class="hint" id="hw-ai-note-<?=$hwId?>"><?=t('This sends the homework instructions and your question to our AI service. Avoid including private information. AI can make mistakes; follow your instructor’s assignment and ask them before trying anything unclear or unsafe. Up to 10 explanations a day.')?></p>
<label for="hw-question-<?=$hwId?>"><?=t('Ask for an explanation')?><?=$hwName?></label><textarea id="hw-question-<?=$hwId?>" name="homework_question" maxlength="1000" required <?=$hwOnQuestion?field_aria('hw_error','hw-ai-note-'.$hwId):'aria-describedby="hw-ai-note-'.$hwId.'"'?>><?=h($hwValue('homework_question'))?></textarea><?=$hwOnQuestion?field_error('hw_error'):''?>
<button class="btn btn-secondary" name="hw_explain" value="1" aria-describedby="hw-ai-note-<?=$hwId?>"><?=t('Ask AI to explain')?><?=$hwName?></button></form>
<?php endif; ?>
</details>
