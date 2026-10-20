<?php
// Included by full_schema.php after the v430 files, against its synthetic database only:
// 4.31.0, confirm a session and land in its case note. Completing a session (the Schedule,
// a group's attendance, the app, voice) marks it as waiting for its note, with a task for its
// instructor; the website opens the full note editor for it (client, track, subject and the
// session named, focus on the first thing to write); a group goes through one note per
// client who attended; saving a note linked to the session clears the mark and completes the
// task; the Schedule and the dashboard list what is still to write; the app's add_note takes
// session_id and notes_to_write lists them; an old app that sends session_note still works.

// The 4.31.0 columns, added here when the schema this run installed does not have them yet
// (a page must work before the migration too: checked first).
$cols431=fn():int=>(int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
    AND ((TABLE_NAME='client_notes' AND COLUMN_NAME='session_id') OR (TABLE_NAME='scheduled_sessions' AND COLUMN_NAME IN ('note_pending','note_pending_since')))")->fetchColumn();

$g431=$db->query('SELECT group_id FROM users WHERE id=1')->fetchColumn();
$db->exec('UPDATE users SET group_id=NULL WHERE id=1');
$db->exec("INSERT INTO users(name,email,password_hash,role,is_active) VALUES('V431 Instructor','v431-instructor@example.invalid','test','instructor',1)"); $u431=(int)$db->lastInsertId();
$client431=function(string $name)use($db):array{
    $db->prepare("INSERT INTO clients(name,status,assigned_to) VALUES(?,'Active Training',1)")->execute([$name]); $c=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date,status) VALUES(?,?,'V431-AUTH','V431 Agency',100,50,?,?,'Active')")
        ->execute([$c,$name.' track',date('Y-m-d',strtotime('-60 days')),date('Y-m-d',strtotime('+60 days'))]);
    return [$c,(int)$db->lastInsertId()];
};
$sess431=function(int $c,?int $t,int $daysAgo,?string $group=null)use($db,$u431):int{
    $db->prepare("INSERT INTO scheduled_sessions(client_id,track_id,user_id,start_at,end_at,group_uid) VALUES(?,?,?,?,?,?)")
        ->execute([$c,$t,$u431,date('Y-m-d 09:00:00',strtotime("-$daysAgo days")),date('Y-m-d 10:00:00',strtotime("-$daysAgo days")),$group]);
    return (int)$db->lastInsertId();
};
// The session a rendered page saved (its messages and kept values cross the redirect there).
$sessOf431=function(callable $run)use($tmp):array{
    $before=glob($tmp.'/sess_*')?:[];
    $result=$run();
    $new=array_values(array_diff(glob($tmp.'/sess_*')?:[],$before));
    return [$result,count($new)===1?(string)file_get_contents($new[0]):''];
};
$renderWith431=function(string $page,array $query,array $add)use($renderPage):array{
    putenv('CMS_TEST_SESSION_ADD='.json_encode($add));
    try { return $renderPage($page,$query); } finally { putenv('CMS_TEST_SESSION_ADD'); }
};
$pending431=fn(int $sid):int=>(int)$db->query("SELECT note_pending FROM scheduled_sessions WHERE id=$sid")->fetchColumn();
$task431=fn(int $sid)=>$db->query("SELECT * FROM tasks WHERE auto_key='snote-$sid'")->fetch();

[$cA,$tA]=$client431('V431 Alma Single');

// ---- Before the migration: completing a session still works and opens the editor ----
if ($cols431()===0) {
    $sPre=$sess431($cA,$tA,9);
    [[$code,,$err]]=$sessOf431(fn()=>$renderPage('schedule',[],['set_status'=>'1','session_id'=>(string)$sPre,'to'=>'Completed']));
    check($code===0 && $err==='' && $db->query("SELECT status FROM scheduled_sessions WHERE id=$sPre")->fetchColumn()==='Completed',
        'Before the 4.31.0 migration a session is still completed from the Schedule'.($err?": $err":''));
    [$code,$html,$err]=$renderPage('client',['id'=>$cA,'tab'=>'notes','session'=>$sPre]);
    check($code===0 && $err==='' && str_contains($html,'Case note for V431 Alma Single, session on'),'Before the migration the note editor still opens for the session'.($err?": $err":''));
    [$code,,$err]=$renderPage('client',['id'=>$cA,'tab'=>'notes'],['add_note'=>'1','subject'=>'V431 pre-migration note','body'=>'Written before the migration','session_id'=>(string)$sPre,'track_id'=>(string)$tA,'idem_key'=>'43100000-0000-4000-8000-000000000001']);
    check($code===0 && $err==='' && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE subject='V431 pre-migration note' AND track_id=$tA")->fetchColumn()===1,
        'Before the migration a note written for a session is saved (not linked)'.($err?": $err":''));
    [$code,$html,$err]=$renderPage('index',[]);
    check($code===0 && $err==='' && !str_contains($html,'Notes to write'),'Before the migration the dashboard has no Notes to write list'.($err?": $err":''));
    $res=$call('notes_to_write');
    check(($res['sessions']??null)===[] && ($res['total']??null)===0,'Before the migration notes_to_write answers an empty list');
}
// The columns this run's schema lacks (the schema and mig_4.31.0.sql normally have them all).
if ($cols431()<3) {
    foreach (preg_split('/;\s*$/m',(string)preg_replace('/^\s*--.*$/m','',"
        ALTER TABLE `client_notes` ADD COLUMN IF NOT EXISTS `session_id` int(10) unsigned DEFAULT NULL AFTER `addendum_to`;
        ALTER TABLE `client_notes` ADD KEY IF NOT EXISTS `idx_note_session` (`session_id`);
        ALTER TABLE `client_notes` DROP FOREIGN KEY IF EXISTS `fk_note_session`;
        ALTER TABLE `client_notes` ADD CONSTRAINT `fk_note_session` FOREIGN KEY (`session_id`) REFERENCES `scheduled_sessions` (`id`) ON DELETE SET NULL;
        ALTER TABLE `scheduled_sessions` ADD COLUMN IF NOT EXISTS `note_pending` tinyint(1) NOT NULL DEFAULT 0 AFTER `work_log_id`;
        ALTER TABLE `scheduled_sessions` ADD COLUMN IF NOT EXISTS `note_pending_since` datetime DEFAULT NULL AFTER `note_pending`;
        ALTER TABLE `scheduled_sessions` ADD KEY IF NOT EXISTS `idx_note_pending` (`note_pending`, `user_id`);")) as $st431) if (trim($st431)!=='') $db->exec($st431);
}
check($cols431()===3,'client_notes.session_id, scheduled_sessions.note_pending and note_pending_since exist');
// The scheduled job's run, as if an hour had passed since the given sessions were completed.
$hourLater431=function(array $sids)use($db):int{
    $db->exec('UPDATE scheduled_sessions SET note_pending_since=DATE_SUB(NOW(),INTERVAL 61 MINUTE) WHERE id IN ('.implode(',',array_map('intval',$sids)).') AND note_pending=1');
    return sessnote_add_due_tasks();
};
sessnote_ready(true);

// ---- One session on the Schedule: confirm, then land in its case note ----
$s1=$sess431($cA,$tA,2);
[$code,$html,$err]=$renderPage('schedule',[]);
check($code===0 && $err==='' && str_contains($html,'id="sh-'.$s1.'"') && !str_contains($html,'id="sn-'.$s1.'"') && !str_contains($html,'name="session_note"')
    && str_contains($html,'Hours, miles and goal<span class="visually-hidden"> for the') && !str_contains($html,'Write the session note'),
    'The Schedule row has hours, miles and goal, and no session note box or "Write the session note" link'.($err?": $err":''));
[[$code,,$err],$sessData]=$sessOf431(fn()=>$renderPage('schedule',[],['set_status'=>'1','session_id'=>(string)$s1,'to'=>'Completed','session_hours'=>'1','session_note'=>'Typed by an old page']));
check($code===0 && $err==='' && $db->query("SELECT status FROM scheduled_sessions WHERE id=$s1")->fetchColumn()==='Completed' && $pending431($s1)===1
    && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE body='Typed by an old page'")->fetchColumn()===0 && !$task431($s1),
    'Confirming a session marks its case note as still to write, with no task yet'.($err?": $err":''));
check(str_contains($sessData,'sessnote_msg') && str_contains($sessData,'"sid";i:'.$s1.';') && str_contains($sessData,'Completed: the session with V431 Alma Single')
    && !str_contains($sessData,'s:5:"flash"') && str_contains($sessData,'sessnote_body') && str_contains($sessData,'Typed by an old page'),
    'Confirming takes the user to the note editor, which says what was completed; a note typed on an old Schedule page goes with it');
// The task is for a session STILL without its note: the scheduled job adds it an hour after completion.
check(sessnote_add_due_tasks()===0 && !$task431($s1),'Within the hour after completion the scheduled job adds no task');
check($hourLater431([$s1])===1,'An hour later the scheduled job adds the task');
$t1=$task431($s1);
check($t1 && $t1['status']==='Open' && (int)$t1['assigned_to']===$u431 && $t1['due_date']===sessnote_next_business_day(date('Y-m-d',strtotime('-2 days'))) && (int)$t1['client_id']===$cA
    && $t1['title']==="Write the case note for V431 Alma Single's session on ".fmt_date(date('Y-m-d',strtotime('-2 days'))),
    'The task goes to the session\'s instructor, due the next business day after the session');
check(sessnote_add_due_tasks()===0 && (int)$db->query("SELECT COUNT(*) FROM tasks WHERE auto_key='snote-$s1'")->fetchColumn()===1,'The case-note task is never added twice for one session');
check(sessnote_next_business_day('2026-10-16')==='2026-10-19' && sessnote_next_business_day('2026-10-19')==='2026-10-20','The next business day after a Friday is the Monday');
check(str_contains((string)file_get_contents($root.'/cron/watchdog.php'),'sessnote_add_due_tasks()'),'The ten-minute watchdog job adds the case-note tasks');
// Someone who can open the client: the instructor, else the client's assigned instructor, else unassigned (administrators see it).
[$cX,$tX]=$client431('V431 Xena Assign');
$sX1=$sess431($cX,$tX,7); $sX2=$sess431($cX,$tX,8);
$db->exec("UPDATE scheduled_sessions SET status='Completed', note_pending=1, note_pending_since=NOW() WHERE id IN ($sX1,$sX2)");
$db->exec("UPDATE users SET is_active=0 WHERE id=$u431");
$hourLater431([$sX1]);
$db->exec("UPDATE clients SET assigned_to=$u431 WHERE id=$cX");
$hourLater431([$sX2]);
$db->exec("UPDATE users SET is_active=1 WHERE id=$u431");
check((int)$task431($sX1)['assigned_to']===1 && $task431($sX2)['assigned_to']===null,
    'An instructor who cannot open the client hands the task to the client\'s assigned instructor, else it is left for administrators');

$when1=fmt_date(date('Y-m-d',strtotime('-2 days')));
[$code,$html,$err]=$renderWith431('client',['id'=>$cA,'tab'=>'notes','session'=>$s1],['sessnote_msg'=>['sid'=>$s1,'msg'=>'Completed: the session V431 synthetic message.']]);
check($code===0 && $err==='' && str_contains($html,'<summary accesskey="n" id="addnote-h">Case note for V431 Alma Single, session on '.$when1.'</summary>')
    && preg_match('~<details id="addnote" open>~',$html)===1
    && str_contains($html,'name="subject" required maxlength="255" value="Session on '.$when1.'"')
    && preg_match('~<option value="'.$tA.'" selected>V431 Alma Single track</option>~',$html)===1
    && str_contains($html,'<input type="hidden" name="session_id" value="'.$s1.'">')
    && str_contains($html,'name="note_type"') && str_contains($html,'name="note_format"') && str_contains($html,'Generate structured note'),
    'The full note editor opens for the session: client and session in the heading, subject, the session\'s track chosen, note type and format'.($err?": $err":''));
check(str_contains($html,'<label for="body">Narrative note') && str_contains($html,'<p class="alert alert-success" id="sessnote-result">Completed: the session V431 synthetic message.</p>')
    && str_contains($html,'cmsFocusAfterLoad') && str_contains($html,'document.getElementById("body")')
    && str_contains($html,'s.textContent="Completed: the session V431 synthetic message. Case note for V431 Alma Single, session on '.$when1.'. "')
    && str_contains($html,'f.addEventListener("blur",function(){s.remove();},{once:true})'),
    'Focus goes to the note; what was completed and where the user is are read once with it, and the label keeps its own short name');
[$code,$html,$err]=$renderWith431('client',['id'=>$cA,'tab'=>'notes','session'=>$s1],['sessnote_body'=>['sid'=>$s1,'body'=>'Typed by an old page']]);
check($code===0 && $err==='' && !str_contains($html,'sessnote-result') && str_contains($html,'s.textContent="Case note for V431 Alma Single, session on '.$when1.'. "')
    && str_contains($html,'name="body" rows="4" >Typed by an old page</textarea>'),
    'Opened again, the editor says where the user is (no completion message), with the note typed on an old Schedule page in the note'.($err?": $err":''));

// The Schedule and the dashboard say the note is not written yet, with the way to write it.
[$code,$html,$err]=$renderPage('schedule',[]);
check($code===0 && $err==='' && str_contains($html,'<strong>Note not written yet</strong> <a href="client.php?id='.$cA.'&amp;tab=notes&amp;session='.$s1.'#addnote-h">Write it now<span class="visually-hidden">: the case note for the session with V431 Alma Single'),
    'The completed session on the Schedule says "Note not written yet" with a "Write it now" link'.($err?": $err":''));
[$code,$html,$err]=$renderPage('index',[]);
check($code===0 && $err==='' && str_contains($html,'id="notes-to-write-h"') && preg_match('~Notes to write \(\d+~',$html)===1
    && str_contains($html,'href="client.php?id='.$cA.'&amp;tab=notes&amp;session='.$s1.'&amp;back=dashboard#addnote-h">V431 Alma Single, session on '.$when1.'</a>'),
    'The dashboard lists the note under Notes to write, linked to its editor'.($err?": $err":''));

// Saving the note links it, clears the mark and completes the task; back to the Schedule.
[[$code,,$err],$sessData]=$sessOf431(fn()=>$renderPage('client',['id'=>$cA,'tab'=>'notes'],['add_note'=>'1','note_type'=>'Case Note','note_format'=>'free','subject'=>'Session on '.$when1,
    'body'=>'V431 single session note','session_id'=>(string)$s1,'session_back'=>'schedule','track_id'=>(string)$tA,'idem_key'=>'43100000-0000-4000-8000-000000000002']));
$n1=$db->query("SELECT * FROM client_notes WHERE body='V431 single session note'")->fetch();
check($code===0 && $err==='' && $n1 && (int)$n1['session_id']===$s1 && (int)$n1['track_id']===$tA && $pending431($s1)===0 && $task431($s1)['status']==='Done'
    && str_contains($sessData,"Case note saved for V431 Alma Single's session on ".$when1),
    'Saving the note links it to the session and its track, clears "not written yet" and completes the task'.($err?": $err":''));
[$code,$html,$err]=$renderPage('schedule',[]);
check($code===0 && $err==='' && !str_contains($html,'session='.$s1.'#addnote-h'),'The Schedule no longer asks for that note'.($err?": $err":''));
// A track that is another client's is refused, with the note kept in the form.
[$cOther,$tOther]=$client431('V431 Other Client');
[[$code,,$err],$sessData]=$sessOf431(fn()=>$renderPage('client',['id'=>$cA,'tab'=>'notes'],['add_note'=>'1','subject'=>'V431 wrong track','body'=>'Kept','track_id'=>(string)$tOther]));
check($code===0 && $err==='' && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE subject='V431 wrong track'")->fetchColumn()===0 && str_contains($sessData,'track_id'),
    'A training track of another client is refused on the note, nothing saved'.($err?": $err":''));
// The picker sets a plain note's track, and an edit can change it.
$call431Before=(int)$db->query('SELECT COUNT(*) FROM client_notes')->fetchColumn();
[$code,,$err]=$renderPage('client',['id'=>$cA,'tab'=>'notes'],['add_note'=>'1','subject'=>'V431 general note','body'=>'General','track_id'=>'0','idem_key'=>'43100000-0000-4000-8000-000000000003']);
check($code===0 && $err==='' && $db->query("SELECT track_id FROM client_notes WHERE subject='V431 general note'")->fetchColumn()===null,'A note with no track chosen is a general note'.($err?": $err":''));

// ---- A group: one note per client who attended, then back to the Schedule ----
[$cB,$tB]=$client431('V431 Bea Group');
[$cC,$tC]=$client431('V431 Cal Group');
[$cD,$tD]=$client431('V431 Dee Group');
[$cE,$tE]=$client431('V431 Eve Group');
$guid='abcabc431431';
$gB=$sess431($cB,$tB,3,$guid); $gC=$sess431($cC,$tC,3,$guid); $gD=$sess431($cD,$tD,3,$guid); $gE=$sess431($cE,$tE,3,$guid);
[[$code,,$err],$sessData]=$sessOf431(fn()=>$renderPage('schedule',[],['complete_group'=>'1','group_uid'=>$guid,'attendance_form'=>'1',
    'listed'=>[(string)$gB,(string)$gC,(string)$gD,(string)$gE],'attended'=>[(string)$gB,(string)$gC,(string)$gD]]));
check($code===0 && $err==='' && $pending431($gB)===1 && $pending431($gC)===1 && $pending431($gD)===1 && $pending431($gE)===0
    && $db->query("SELECT status FROM scheduled_sessions WHERE id=$gE")->fetchColumn()==='No Show',
    'Completing a group marks a case note to write for each client who attended, none for the No Show'.($err?": $err":''));
check($hourLater431([$gB,$gC,$gD,$gE])===3 && $task431($gB) && $task431($gD) && !$task431($gE),'An hour later each attendee still without a note has a task, the No Show none');
check(str_contains($sessData,'"sid";i:'.$gB.';') && str_contains($sessData,'Next: the case note for each client who attended'),
    'After the group, the user lands in the first attendee\'s note');
$whenG=fmt_date(date('Y-m-d',strtotime('-3 days')));
[$code,$html,$err]=$renderPage('client',['id'=>$cB,'tab'=>'notes','session'=>$gB]);
check($code===0 && $err==='' && str_contains($html,'Case note for V431 Bea Group, session on '.$whenG.' (1 of 3 in the group)')
    && str_contains($html,'href="client.php?id='.$cC.'&amp;tab=notes&amp;session='.$gC.'#addnote-h">Skip for now<span class="visually-hidden">: go to V431 Cal Group, 2 of 3; this note stays on Notes to write</span></a>'),
    'The first attendee\'s note says 1 of 3, and Skip for now goes to the next'.($err?": $err":''));
[[$code,,$err],$sessData]=$sessOf431(fn()=>$renderPage('client',['id'=>$cB,'tab'=>'notes'],['add_note'=>'1','subject'=>'Session on '.$whenG,'body'=>'V431 Bea note','session_id'=>(string)$gB,'track_id'=>(string)$tB,'idem_key'=>'43100000-0000-4000-8000-000000000004']));
check($code===0 && $err==='' && $pending431($gB)===0 && str_contains($sessData,'Next: V431 Cal Group, 2 of 3.') && str_contains($sessData,'"sid";i:'.$gC.';'),
    'Saving goes to the next attendee still without a note ("Next: V431 Cal Group, 2 of 3")'.($err?": $err":''));
// Cal is skipped (the Skip link opens Dee's note); Dee's note is the last, so it goes back to the Schedule.
[$code,$html,$err]=$renderPage('client',['id'=>$cC,'tab'=>'notes','session'=>$gC]);
check($code===0 && $err==='' && str_contains($html,'(2 of 3 in the group)') && str_contains($html,'session='.$gD.'#addnote-h">Skip for now'),'The second note skips to the third'.($err?": $err":''));
[$code,$html,$err]=$renderPage('client',['id'=>$cD,'tab'=>'notes','session'=>$gD]);
check($code===0 && $err==='' && str_contains($html,'href="schedule.php#s-'.$gD.'">Skip for now<span class="visually-hidden">: back to the Schedule'),'The last note\'s Skip for now goes back to the Schedule'.($err?": $err":''));
[[$code,,$err],$sessData]=$sessOf431(fn()=>$renderPage('client',['id'=>$cD,'tab'=>'notes'],['add_note'=>'1','subject'=>'Session on '.$whenG,'body'=>'V431 Dee note','session_id'=>(string)$gD,'idem_key'=>'43100000-0000-4000-8000-000000000005']));
check($code===0 && $err==='' && $pending431($gD)===0 && $pending431($gC)===1 && str_contains($sessData,'Still to write for this group: V431 Cal Group.') && !str_contains($sessData,'sessnote_msg'),
    'Saving the last note goes back to the Schedule, naming the skipped note still to write'.($err?": $err":''));

// ---- The app: complete_session, then add_note with session_id ----
[$cF,$tF]=$client431('V431 Fay App');
$sF=$sess431($cF,$tF,4);
$done=$call('complete_session',['session_id'=>$sF,'idem_key'=>'V431-COMPLETE-0001']);
check(($done['session_id']??null)===$sF && ($done['client_id']??null)===$cF && ($done['client_name']??null)==='V431 Fay App' && ($done['track_id']??null)===$tF
    && ($done['session_date']??null)===date('Y-m-d',strtotime('-4 days')) && ($done['note_subject']??null)==='Session on '.fmt_date(date('Y-m-d',strtotime('-4 days')))
    && ($done['note_pending']??null)===true && ($done['note_saved']??null)===false && $pending431($sF)===1 && $hourLater431([$sF])===1 && $task431($sF),
    'complete_session answers the client, track, session, date and note subject, and the note is marked still to write');
$again=$call('complete_session',['session_id'=>$sF,'idem_key'=>'V431-COMPLETE-0001']);
check(($again['repeat']??null)===true && ($again['session_id']??null)===$sF && ($again['client_id']??null)===$cF,
    'complete_session sent again with its key after a lost reply answers the first reply quietly');
$list=$call('notes_to_write');
$row=current(array_filter($list['sessions']??[],fn($r)=>($r['session_id']??0)===$sF));
check(is_array($row) && array_keys($row)===['session_id','client_id','client_name','track_id','session_date','start_at','note_subject'] && $row['client_id']===$cF
    && $row['note_subject']===$done['note_subject'] && ($list['total']??0)>=2 && ($list['sessions'][0]['start_at']??'')>=($list['sessions'][count($list['sessions'])-1]['start_at']??''),
    'notes_to_write lists the sessions still waiting for a note, newest first, with total');
check(!array_filter($list['sessions'],fn($r)=>in_array($r['session_id'],[$s1,$gB,$gD],true)),'notes_to_write leaves out sessions whose note is written');
$wrong=$call('add_note',['client_id'=>$cF,'subject'=>'V431 wrong session','body'=>'x','session_id'=>$gC],[],false,'belongs to a different client');
check((int)$db->query("SELECT COUNT(*) FROM client_notes WHERE subject='V431 wrong session'")->fetchColumn()===0,'add_note refuses a session of another client, nothing saved');
// A session of this client that is not completed (its queued completion was refused): saved, unlinked, and said.
$sOpen=$sess431($cF,$tF,10);
$unlinked=$call('add_note',['client_id'=>$cF,'subject'=>'V431 unlinked note','body'=>'Kept','session_id'=>$sOpen,'idem_key'=>'43100000-0000-4000-8000-000000000007']);
check(array_key_exists('session_id',$unlinked) && $unlinked['session_id']===null && str_contains((string)($unlinked['warning']??''),'not linked to the session')
    && $db->query("SELECT session_id FROM client_notes WHERE subject='V431 unlinked note'")->fetchColumn()===null,
    'add_note for a session not completed still saves the note, unlinked, with a warning');
$noteKey='43100000-0000-4000-8000-000000000006';
$added=$call('add_note',['client_id'=>$cF,'track_id'=>$tF,'subject'=>$done['note_subject'],'body'=>'V431 app note','session_id'=>$sF,'idem_key'=>$noteKey]);
check(($added['session_id']??null)===$sF && (int)$db->query("SELECT session_id FROM client_notes WHERE idem_key='$noteKey'")->fetchColumn()===$sF
    && $pending431($sF)===0 && $task431($sF)['status']==='Done',
    'add_note with session_id links the note, clears "not written yet" and completes the task');
$replay=$call('add_note',['client_id'=>$cF,'track_id'=>$tF,'subject'=>$done['note_subject'],'body'=>'V431 app note','session_id'=>$sF,'idem_key'=>$noteKey]);
check(($replay['deduplicated']??null)===true && ($replay['session_id']??null)===$sF && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE idem_key='$noteKey'")->fetchColumn()===1,
    'A queued offline note sent again is saved once and still answers its session');
$list=$call('notes_to_write');
check(!array_filter($list['sessions'],fn($r)=>$r['session_id']===$sF),'The written note leaves notes_to_write');
// An app build before 4.31.0 still sends the note with the completion: saved, linked, nothing left to write.
$sOld=$sess431($cF,$tF,5);
$old=$call('complete_session',['session_id'=>$sOld,'session_note'=>'V431 old app note']);
check(($old['note_saved']??null)===true && ($old['note_pending']??null)===false && $pending431($sOld)===0 && !$task431($sOld)
    && (int)$db->query("SELECT session_id FROM client_notes WHERE body='V431 old app note'")->fetchColumn()===$sOld,
    'An older app that sends session_note with complete_session still has it saved, linked to the session, with nothing left to write');

// ---- Voice ----
$GLOBALS['CURRENT_PERMS']=array_keys(PERMISSION_KEYS); $GLOBALS['CURRENT_SCOPED']=false;
$sV=$sess431($cF,$tF,6);
$v=voice_execute('complete_session',['session_id'=>$sV],['id'=>1,'name'=>'Test Admin','role'=>'admin']);
check(($v['ok']??false)===true && $pending431($sV)===1 && !$task431($sV) && ($v['url']??'')===sessnote_editor_url($cF,$sV) && str_contains((string)($v['speak']??''),'case note is still to write'),
    'Completing a session by voice marks its note still to write and links to the note editor');

// Clean up what this file added.
foreach ([$cA,$cOther,$cB,$cC,$cD,$cE,$cF,$cX] as $c) {
    $db->exec("DELETE FROM tasks WHERE client_id=$c");
    $db->exec("DELETE FROM client_notes WHERE client_id=$c");
    $db->exec("DELETE FROM scheduled_sessions WHERE client_id=$c");
    $db->exec("DELETE w FROM work_logs w JOIN training_tracks t ON t.id=w.track_id WHERE t.client_id=$c");
    $db->exec("DELETE FROM training_tracks WHERE client_id=$c");
    $db->exec("DELETE FROM clients WHERE id=$c");
}
$db->exec("DELETE FROM users WHERE id=$u431");
$db->prepare('UPDATE users SET group_id=? WHERE id=1')->execute([$g431===false?null:$g431]);
