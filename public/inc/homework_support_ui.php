<?php // Included inside the token-scoped homework list; $h_, $client and $token come from portal.php.
$hwPosted=(int)($_POST['homework_id']??0)===(int)$h_['id'];
$hwValue=fn(string $key):string=>$hwPosted&&is_scalar($_POST[$key]??null)?(string)$_POST[$key]:'';
?>
<details lang="en"><summary>Practice feedback and help — <?=h($h_['title'])?></summary>
<form method="post" action="portal.php?t=<?=h($token)?>" class="stacked">
<?=csrf_field()?><input type="hidden" name="homework_id" value="<?=(int)$h_['id']?>"><input type="hidden" name="request_key" value="<?=h(preg_match('/^[a-f0-9]{32}$/D',$hwValue('request_key'))?$hwValue('request_key'):bin2hex(random_bytes(16)))?>">
<label for="hw-min-<?=(int)$h_['id']?>">Minutes practiced</label><input type="number" name="practice_minutes" id="hw-min-<?=(int)$h_['id']?>" min="1" max="1440" value="<?=h($hwValue('practice_minutes'))?>" required>
<label for="hw-difficulty-<?=(int)$h_['id']?>">How did it go?</label><select name="difficulty" id="hw-difficulty-<?=(int)$h_['id']?>"><?=select_options(['Comfortable','Challenging','Need help'],$hwValue('difficulty'))?></select>
<label for="hw-notes-<?=(int)$h_['id']?>">What worked or was difficult?</label><textarea name="practice_notes" id="hw-notes-<?=(int)$h_['id']?>" maxlength="2000"><?=h($hwValue('practice_notes'))?></textarea>
<p>Your instructor can read this update. “Need help” adds a follow-up task. This does not mark your homework complete.</p>
<button class="btn btn-secondary" name="hw_practice" value="1">Share practice update</button>
</form>
<?php if(ai_feature_enabled('portal_homework')): ?>
<form method="post" action="portal.php?t=<?=h($token)?>" class="stacked"><?=csrf_field()?><input type="hidden" name="homework_id" value="<?=(int)$h_['id']?>">
<label for="hw-question-<?=(int)$h_['id']?>">Ask for an explanation</label><textarea id="hw-question-<?=(int)$h_['id']?>" name="homework_question" maxlength="1000" required><?=h($hwValue('homework_question'))?></textarea>
<p>This sends the homework instructions and your question to our AI service. Avoid including private information. AI can make mistakes; follow your instructor’s assignment and ask them before trying anything unclear or unsafe. Up to 10 explanations a day.</p>
<button class="btn btn-secondary" name="hw_explain" value="1">Ask AI to explain</button></form>
<?php endif; ?>
</details>
