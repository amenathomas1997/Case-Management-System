<?php
// Included only after the portal has validated its client token.
if(!isset($client['id']))return;
$learning=at_portal_learning((int)$client['id']);
if($learning):?>
<section class="card" aria-labelledby="at-learning-title"><h2 id="at-learning-title"><?=t('My learning library')?></h2>
<?php foreach($learning as $item):$personal=json_decode($item['details'],true);$resource=json_decode($item['resource_details'],true);?>
<?php // The status is translated when a translation exists, otherwise marked as English; the date is always marked.
$learnStatus=(string)$item['status'];?>
<?php // Staff-written resource text is marked lang="en" on the Spanish page (en()), as in portal.php. ?>
<article><h3><?=en((string)$item['title'])?></h3><p><?=__($learnStatus)===$learnStatus?en($learnStatus):h(__($learnStatus))?><?= $item['due_date']?' · '.t('due').' '.en(fmt_date($item['due_date'])):''?></p>
<p style="white-space:pre-wrap"><?=en((string)($personal['instructions']??''))?></p>
<?php foreach(['instructions'=>'Instructions / transcript','practice'=>'Practice exercise','accessibility'=>'Accessibility notes'] as $key=>$label):if(empty($resource[$key]))continue;?><h4><?=t($label)?></h4><p style="white-space:pre-wrap"><?=en((string)$resource[$key])?></p><?php endforeach;?>
<?php // The scheme is compared ignoring case, as staff-side validation does ("Https://" from an auto-capitalised field). ?>
<?php if(!empty($resource['url'])&&is_string($resource['url'])&&strtolower((string)parse_url($resource['url'],PHP_URL_SCHEME))==='https'):?><p><a href="<?=h($resource['url'])?>" rel="noreferrer"><?=t('Open learning resource')?><span class="visually-hidden"> — <?=en((string)$item['title'])?></span></a></p><?php endif;?>
<?php if(!empty($personal['feedback'])):?><h4><?=t('Instructor feedback')?></h4><p><?=nl2br(en((string)$personal['feedback']))?></p><?php endif;?>
<?php // Confirmed first, naming the item: the report and the instructor's alert cannot be taken back here.
// A closed case's portal sends nothing ($caseClosed comes from portal.php). ?>
<?php if(in_array($item['status'],['Assigned','Practicing'],true)&&empty($caseClosed)):?><form method="post" action="portal.php?t=<?=h($token)?>&amp;lang=<?=h(page_lang())?>" onsubmit="return confirm(<?=h(json_encode(__('Tell your instructor you practiced this item?')."\n".$item['title']."\n".__('Choose OK to tell your instructor, or Cancel to send nothing.'),JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP))?>);"><?=csrf_field()?><input type="hidden" name="at_learning_id" value="<?=(int)$item['id']?>"><button name="at_learning_done" value="1"><?=t('Tell my instructor I practiced')?><span class="visually-hidden"> — <?=en((string)$item['title'])?></span></button></form><?php endif;?></article>
<?php endforeach;?></section><?php endif;?>
