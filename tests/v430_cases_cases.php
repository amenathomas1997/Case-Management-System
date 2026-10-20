<?php
// Included by full_schema.php after the v429 cases, against its synthetic database only:
// 4.30.0, case pages: the accessibility findings of the 2026-10-20 audit (cases area and the
// staff-page task walkthroughs). Made-up names only, prefixed V430-CASES.

$v430src=fn(string $f):string=>(string)file_get_contents(dirname(__DIR__).'/public/'.$f);
$db->exec("INSERT INTO users (name,email,password_hash,role) VALUES ('V430-CASES Instructor','v430-cases-a@example.invalid','test430a','instructor')"); $v430u=(int)$db->lastInsertId();
$db->prepare("INSERT INTO clients(name,status,client_type,state,assigned_to) VALUES('V430-CASES Client One','Active Training','VR','IA',?)")->execute([$v430u]); $v430c1=(int)$db->lastInsertId();
$db->prepare("INSERT INTO clients(name,status,client_type,state,assigned_to,preferred_language) VALUES('V430-CASES Client Two','Active Training','VR','IA',?,'es')")->execute([$v430u]); $v430c2=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,billing_mode,hourly_rate,authorized_hours) VALUES(?,'V430-CASES JAWS track','Active','V430-AUTH-1','V430-CASES Agency','vr',50,40)")->execute([$v430c1]); $v430t1=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,billing_mode,hourly_rate,authorized_hours) VALUES(?,'V430-CASES NVDA track','Active','V430-AUTH-2','V430-CASES Agency','vr',50,40)")->execute([$v430c2]); $v430t2=(int)$db->lastInsertId();

// ---- Schedule: the track list is plain names grouped by client, and follows the chosen client ----
[$code,$out,$err]=$renderPage('schedule',[]);
check($err==='' && str_contains($out,'<optgroup label="V430-CASES Client One" data-client="'.$v430c1.'">'),'Schedule a session groups the training tracks by client'.($err?": $err":''));
check(str_contains($out,'>V430-CASES JAWS track</option>') && !str_contains($out,'V430-CASES Client One — V430-CASES JAWS track'),'Each training track option is the track name alone, with no client prefix');
check(str_contains($out,'id="track_id_all"') && str_contains($out,'setTimeout(apply,700)'),'The track list is narrowed to the chosen client only after the choice settles');
check((bool)preg_match('/<select id="client_id" name="client_id" aria-required="true"/',$out) && !preg_match('/<select id="client_id"[^>]* required/',$out),'The Client list is aria-required, so it is not invalid before anything is sent');
putenv('CMS_TEST_SESSION_ADD='.json_encode(['old_input'=>['client_id'=>(string)$v430c2]]));
[$code,$out,$err]=$renderPage('schedule',[]);
putenv('CMS_TEST_SESSION_ADD');
$v430sel=preg_match('/<select id="track_id".*?<\/select>/s',$out,$v430m)?$v430m[0]:'';
check(str_contains($v430sel,'V430-CASES NVDA track') && !str_contains($v430sel,'V430-CASES JAWS track'),'With a client already chosen, only that client\'s tracks are listed'.($err?": $err":''));

// ---- Schedule: session outcomes are plain and distinct; the logged hours are in the row text ----
$v430past=date('Y-m-d',strtotime('-2 days'));
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,track_id,start_at,end_at,status) VALUES(?,?,?,?,?,'Scheduled')")->execute([$v430c1,$v430u,$v430t1,"$v430past 09:00:00","$v430past 10:00:00"]); $v430s=(int)$db->lastInsertId();
$v430wl=$db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description) VALUES(?,?,?,?)");
$v430wl->execute([$v430t1,$v430past,1.5,'V430-CASES morning lesson']); $v430w1=(int)$db->lastInsertId();
$v430wl->execute([$v430t1,$v430past,1.5,'V430-CASES afternoon lesson']);
[$code,$out,$err]=$renderPage('schedule',[]);
$v430st=preg_match('/<select id="st-'.$v430s.'".*?<\/select>/s',$out,$v430m)?$v430m[0]:'';
check($err==='' && str_contains($v430st,'>Completed, hours already logged as entry 1</option>') && str_contains($v430st,'>Completed, hours already logged as entry 2</option>') && str_contains($v430st,'>Completed, log new hours</option>'),'The outcome options are short and different from each other'.($err?": $err":''));
check(!str_contains($v430st,' — ') && !str_contains($v430st,'(') && !str_contains($v430st,'V430-CASES morning'),'No outcome option holds a sentence, a count or the log description');
check((bool)preg_match('/<label for="st-'.$v430s.'"><span class="visually-hidden">Update status for [^<]*<\/span><span class="hint" id="sl-'.$v430s.'">.*?entry 1, 1\.5 hours, V430-CASES morning lesson.*?<\/span><\/label>/s',$out) && !str_contains($out,'<p class="muted" id="sl-'),'The hours already logged that day are in the outcome list\'s own label, numbered like the options');

// ---- Schedule: a refused move links to "Move anyway" and marks only that box ----
$v430mk='move_'.$v430s;
putenv('CMS_TEST_SESSION_ADD='.json_encode(['field_errors'=>[$v430mk=>'Not moved yet: V430-CASES warning. Tick "Move anyway" and choose Move again if this time is right.'],
    'old_input'=>['session_id'=>(string)$v430s,'move_session'=>'1','move_date'=>$v430past,'move_time'=>'09:00','move_duration'=>'1','move_hash'=>str_repeat('a',64)]]));
[$code,$out,$err]=$renderPage('schedule',[]);
putenv('CMS_TEST_SESSION_ADD');
check($err==='' && str_contains($out,'href="#mc-'.$v430s.'"'),'The move error summary links to the Move anyway box'.($err?": $err":''));
check((bool)preg_match('/<input type="checkbox" id="mc-'.$v430s.'" name="move_confirm" value="1" aria-invalid="true" aria-describedby="err-'.$v430mk.'">/',$out),'Only the Move anyway box is marked invalid and tied to the error');
check(!preg_match('/id="(md|mt|ml)-'.$v430s.'"[^>]*aria-invalid/',$out),'The date, time and length are not each marked invalid');
check(substr_count($out,'id="err-'.$v430mk.'"')===1 && substr_count($out,'V430-CASES warning')===2,'The move error is shown once beside the box, besides the summary');
putenv('CMS_TEST_SESSION_ADD='.json_encode(['field_errors'=>[$v430mk=>'Enter the new date, a start time and a length from 0.25 to 12 hours.'],
    'old_input'=>['session_id'=>(string)$v430s,'move_session'=>'1','move_date'=>'','move_time'=>'','move_duration'=>'']]));
[$code,$out,$err]=$renderPage('schedule',[]);
putenv('CMS_TEST_SESSION_ADD');
check($err==='' && str_contains($out,'href="#mf-'.$v430s.'"') && str_contains($out,'<fieldset id="mf-'.$v430s.'" aria-describedby="err-'.$v430mk.'">') && !preg_match('/id="(md|mt|ml)-'.$v430s.'"[^>]*aria-invalid/',$out),'A time that cannot be read is one error on the date-and-time group'.($err?": $err":''));

// ---- Schedule: a double booking is a plain check, not an authorization review ----
$v430day=date('Y-m-d',strtotime('+11 days'));
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,?,?,?,'Scheduled')")->execute([$v430c1,$v430u,"$v430day 10:00:00","$v430day 11:00:00"]);
$v430book=['add_session'=>'1','client_id'=>(string)$v430c2,'date'=>$v430day,'time'=>'10:00','duration'=>'1','staff_id'=>(string)$v430u,'plan_pattern'=>'weekly','repeat_weeks'=>'1'];
[[$code,,$err],$v430sess]=$pageSession(fn()=>$renderPage('schedule',[],$v430book));
check($err==='' && str_contains($v430sess,'Check before booking: V430-CASES Instructor already has a session') && !str_contains($v430sess,'Authorization review'),'A double booking is called a check before booking, not an authorization review'.($err?": $err":''));
check(str_contains($v430sess,'then tick "I reviewed these warnings" if this booking') && !str_contains($v430sess,'box below'),'The warning names the approval box without pointing the wrong way');
$v430ack='Check before booking: V430-CASES test line. Review the track and the calendar, then tick "I reviewed these warnings" if this booking is still appropriate.';
putenv('CMS_TEST_SESSION_ADD='.json_encode(['field_errors'=>['authorization_ack'=>$v430ack],'old_input'=>['add_session'=>'1','authorization_hash'=>str_repeat('b',64),'client_id'=>(string)$v430c2]]));
[$code,$out,$err]=$renderPage('schedule',[]);
putenv('CMS_TEST_SESSION_ADD');
check($err==='' && str_contains($out,'href="#authorization_ack" style="display:inline-flex; align-items:center; min-height:2.75rem;">Check before booking:') && !str_contains($out,'Authorization approval:'),'The summary link reads the warning once, with no second label'.($err?": $err":''));
check(str_contains($out,'I reviewed these warnings and approve this booking despite them.') && !str_contains($out,'these authorization warnings'),'The approval box does not call a double booking an authorization warning');

// ---- Client record: notes are named by date; tabs open on the section heading ----
$db->prepare("INSERT INTO client_notes (client_id,note_type,subject,body,author_id,created_at) VALUES (?,'Case Note','V430-CASES Weekly session','First week',1,'2026-10-03 10:00:00')")->execute([$v430c1]);
[$code,$out,$err]=$renderPage('client',['id'=>(string)$v430c1,'tab'=>'notes']);
check($err==='' && str_contains($out,'<h3>V430-CASES Weekly session<span class="muted">, Oct 3, 2026</span></h3>'),'A note heading gives the subject and the date'.($err?": $err":''));
check(str_contains($out,'Edit<span class="visually-hidden"> note from Oct 3, 2026: V430-CASES Weekly session</span>'),'The Edit link names the note\'s date as well as its subject');
check(str_contains($out,'tab=notes#tab-panel"') && str_contains($out,'<h2 id="tab-panel" tabindex="-1" class="visually-hidden">Client Notes</h2>'),'Record tabs open the page on the section\'s own heading');
check(str_contains($v430src('client.php'),'Add addendum<span class="visually-hidden"> to the note from <?= $nWhen ?>') && str_contains($v430src('track.php'),'Add addendum<span class="visually-hidden"> to the note from <?= $tnWhen ?>'),'Add addendum links name the note\'s date on the client and track pages');

// ---- Client record: progress read once; releases and holds required; the documents confirm ----
[$code,$out,$err]=$renderPage('client',['id'=>(string)$v430c1]);
check($err==='' && str_contains($out,'aria-label="Hours used, V430-CASES JAWS track"') && !str_contains($out,'<label for="prog-'.$v430t1.'"'),'The overview bar has a short name, so the hours are not read twice'.($err?": $err":''));
check((bool)preg_match('/id="rel_signer" name="signer_name" maxlength="120" required/',$out) && (bool)preg_match('/id="rel_rname"[^>]* required/',$out) && (bool)preg_match('/id="rel_purpose"[^>]* required/',$out),'The release form\'s starred fields are programmatically required');
$v430c=$v430src('client.php');
check(str_contains($v430c,"has_field_error('info') ? ' aria-invalid=\"true\"'") && str_contains($v430c,'<label for="hold_reason">Hold reason <?= required_mark() ?></label>'),'Refused release information marks its boxes invalid, and the hold reason is marked required');
check(str_contains($v430c,"'Move the document \"' . \$doc['title'] . '\" to the trash?") && str_contains($v430c,'<input type="file" id="document" name="document" aria-required="true"'),'Moving a document to the trash names it, and the file chooser is not invalid on load');
check(str_contains($v430c,'aria-label="Scored assessments table"') && str_contains($v430c,'<caption class="visually-hidden">Scored assessments, newest first</caption>') && str_contains($v430c,'<th scope="row"><?= h($sc[\'instrument\']) ?>'),'The scored assessments table has a caption, row headers and its own region name');

// ---- Track: progress, goal templates, corrections ----
$v430wl->execute([$v430t1,date('Y-m-d'),0.5,'V430-CASES short check-in']);
[$code,$out,$err]=$renderPage('track',['id'=>(string)$v430t1]);
check($err==='' && str_contains($out,'aria-label="Hours used"') && !str_contains($out,'<label for="prog-main"'),'The track\'s usage bar is not named with the same figures as the text'.($err?": $err":''));
check(str_contains($out,'Correct<span class="visually-hidden"> the log entry from '.date('M j, Y').', 0.5 hours: V430-CASES short check-in</span>'),'Correct names the entry\'s date, hours and activity');
check(str_contains($out,'Save correction<span class="visually-hidden"> for the log entry from '.date('M j, Y').', 0.5 hours: V430-CASES short check-in</span>'),'Save correction names the same entry');
$v430t=$v430src('track.php');
check(str_contains($v430t,'<option value="">All areas</option>') && str_contains($v430t,'timer=setTimeout(settled,700)') && str_contains($v430t,'function settled(){timer=null;query();}') && !str_contains($v430t,'setTimeout(query,250)'),'The goal template Area has no count, and the search announces once after typing pauses');

// ---- Clients and referrals: their lists have headings ----
[$code,$out,$err]=$renderPage('clients',['q'=>'V430-CASES Client','who'=>'']);
check($err==='' && str_contains($out,'<h2 id="clients-h" tabindex="-1">Clients (2)</h2>') && str_contains($out,'aria-labelledby="clients-h"'),'The client list has a heading with the number of matches'.($err?": $err":''));
check(str_contains($v430src('clients.php'),"(isset(\$keep['export']) ? '' : '#clients-h')"),'Apply filters opens the list on its results heading');
$db->exec("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name,status) VALUES('Synthetic','Synthetic','Synthetic','V430 counselor','V430-CASES Agency','v430-counselor@example.invalid','555-0150','V430-CASES Referral Person','New')"); $v430r=(int)$db->lastInsertId();
[$code,$out,$err]=$renderPage('referrals',['view'=>(string)$v430r]);
check($err==='' && (bool)preg_match('/<h2 id="ref-list-h">Open referrals \(\d+\)<\/h2>/',$out) && str_contains($out,'aria-labelledby="ref-list-h"'),'The referral list has its own heading while a referral is open'.($err?": $err":''));
check(str_contains($out,'Mark the referral for V430-CASES Referral Person as reviewed?'),'Mark as reviewed names the referral');

// ---- Confirmations name the item ----
check(str_contains($v430src('tasks.php'),"'Delete the task ' . \$taskLabel . ' permanently?'") && str_contains($v430src('trash.php'),"'Permanently delete ' . \$itemName . '? This cannot be undone.'"),'Deleting a task or a trash item names it');
check(str_contains($v430src('intake.php'),'Draft assessment with AI<?php if ($generated): ?><span class="visually-hidden">: replaces the draft below') && !str_contains($v430src('intake.php'),'<p class="hint">Drafting again'),'The intake redraft note is inside the button');

// ---- Merge: the review is part of the page, the legend is short, the confirm names both ----
$db->exec("INSERT INTO clients(name,status,client_type,state,email) VALUES('V430-CASES Keep','Active Training','VR','IA',NULL)"); $v430k=(int)$db->lastInsertId();
$db->exec("INSERT INTO clients(name,status,client_type,state,email) VALUES('V430-CASES Dup','Active Training','VR','IA','v430-dup@example.invalid')"); $v430d=(int)$db->lastInsertId();
[$code,$out,$err]=$renderPage('client_merge',['keep'=>(string)$v430k,'merge'=>(string)$v430d]);
check($err==='' && !str_contains($out,'<div class="alert alert-error" role="alert">') && str_contains($out,'<section class="alert alert-error" aria-labelledby="merge-check-h">') && str_contains($out,'<h2 id="merge-check-h" style="margin-top:0">Check before merging</h2>'),'The merge review is a section with a heading, not an alert'.($err?": $err":''));
check(str_contains($out,'to fill from the duplicate <span class="hint">untick a value to leave it out</span></legend>') && str_contains($out,'copied only when ticked: reminders, portal links'),'The legend is short; the email explanation is on the email box');
check(str_contains($out,'Merge V430-CASES Dup (#'.$v430d.') into V430-CASES Keep (#'.$v430k.') and remove the duplicate?'),'The merge confirm names both records');
[$code,$out,$err]=$renderPage('client_merge',['keep'=>'','merge'=>'']);
check($err==='' && str_contains($out,'id="form-error-summary"') && str_contains($out,'Choose the record to keep.') && (bool)preg_match('/<select id="keep" name="keep" aria-required="true" aria-invalid="true"/',$out),'Review with no records chosen says which is missing'.($err?": $err":''));

// ---- Messages: the client list is not invalid before use, and an empty choice is said ----
[$code,$out,$err]=$renderPage('messages',[]);
check($err==='' && str_contains($out,'<select id="open_client" name="client" aria-required="true"') && !str_contains($out,'<select id="open_client" name="client" required'),'The Messages client list is aria-required'.($err?": $err":''));
[$code,$out,$err]=$renderPage('messages',['client'=>'','channel'=>'client']);
check($err==='' && str_contains($out,'Choose a client, then choose Open conversation.') && str_contains($out,'href="#open_client"'),'Open conversation with no client chosen is answered with a summary'.($err?": $err":''));

// ---- Signing, follow-ups and letters ----
$v430sig=$v430src('signatures.php');
check(str_contains($v430sig,'id="signature" name="signature" autocomplete="off" required') && str_contains($v430sig,'(obligatorio)') && str_contains($v430sig,'id="signer_name" name="signer_name" maxlength="120" autocomplete="off" required'),'The signing fields are required, and the Spanish signature label says obligatorio');
check(str_contains($v430src('followups.php'),'<legend>Still in use? <?= required_mark() ?></legend>'),'Still in use? shows the required mark');
[$code,$out,$err]=$renderPage('letters',['client'=>(string)$v430c2,'letter'=>'welcome']);
check($err==='' && str_contains($out,'this client prefers Spanish; the wording below is in English') && str_contains($out,'required lang="en"'),'A letter for a Spanish-preferring client says the wording is English and is marked so'.($err?": $err":''));
check(str_contains($out,'<label for="body">Letter <span aria-hidden="true">*</span>'),'The letter body is marked required');
[$code,$out,$err]=$renderPage('letters',[],['client'=>(string)$v430c2,'letter'=>'welcome','title'=>'V430-CASES Carta','body'=>'Estimado cliente','letter_lang'=>'es','download'=>'1']);
check(str_contains($out,'<html lang="es">') && str_contains($out,'Estimado cliente'),'A letter written in Spanish is saved marked lang="es"'.($err?": $err":''));
[$code,$out,$err]=$renderPage('letters',[],['client'=>(string)$v430c1,'letter'=>'welcome','title'=>'V430-CASES Letter','body'=>'Dear client','download'=>'1']);
check(str_contains($out,'<html lang="en">'),'An English letter is marked lang="en"'.($err?": $err":''));
