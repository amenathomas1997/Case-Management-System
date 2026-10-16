<?php
// Included by full_schema.php after release_3021_cases.php, against its synthetic
// database only: one form for a session's hours and note (3.0.31). On the track,
// Record a session saves the hours, the note, or both, and a structured note; on the
// schedule, Mark completed takes the hours (prefilled), miles and the note.
$db->exec("INSERT INTO clients(name,status) VALUES('Session record synthetic client','Active Training')"); $srClient=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,authorized_hours,hourly_rate,mileage_rate,start_date,expiration_date) VALUES(?,'Session record track','SR-31','Synthetic agency',40,50,0.5,?,?)")
    ->execute([$srClient,date('Y-m-d',strtotime('-30 days')),date('Y-m-d',strtotime('+60 days'))]); $srTrack=(int)$db->lastInsertId();
$srLogs=fn():int=>(int)$db->query("SELECT COUNT(*) FROM work_logs WHERE track_id=$srTrack")->fetchColumn();
$srNotes=fn():int=>(int)$db->query("SELECT COUNT(*) FROM client_notes WHERE track_id=$srTrack")->fetchColumn();

// A note on its own: Hours worked left blank.
[$code,,$err]=$renderPage('track',['id'=>$srTrack],['add_log'=>'1','work_date'=>date('Y-m-d'),'hours'=>'','description'=>'','body'=>'Phone call with the learner about the next session.']);
check($code===0 && $err==='' && $srLogs()===0 && $srNotes()===1
    && $db->query("SELECT subject FROM client_notes WHERE track_id=$srTrack")->fetchColumn()==='Session on '.fmt_date(date('Y-m-d')),
    'Record a session with no hours saves the note on its own, titled with the date' . ($err ? ": $err" : ''));

// Hours and a structured (BIRP) note together, sent twice: saved once.
$srKey='5E55A0A1-0000-4000-8000-000000000031';
$srPost=['add_log'=>'1','idem_key'=>$srKey,'work_date'=>date('Y-m-d'),'hours'=>'1.5','mileage'=>'8','description'=>'Screen reader practice',
    'note_type'=>'Case Note','note_format'=>'BIRP','subject'=>'Synthetic BIRP session','sec1'=>'Behavior synthetic','sec2'=>'Intervention synthetic','sec3'=>'Response synthetic','sec4'=>'Plan synthetic'];
[$code,,$err]=$renderPage('track',['id'=>$srTrack],$srPost);
$srNote=$db->query("SELECT * FROM client_notes WHERE track_id=$srTrack AND subject='Synthetic BIRP session'")->fetch();
check($code===0 && $err==='' && $srLogs()===1 && $srNote && $srNote['note_format']==='BIRP' && $srNote['birp_plan']==='Plan synthetic' && $srNote['idem_key']===$srKey
    && (float)$db->query("SELECT mileage FROM work_logs WHERE track_id=$srTrack")->fetchColumn()===8.0,
    'Record a session saves the hours and a structured note together' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('track',['id'=>$srTrack],$srPost);
check($code===0 && $err==='' && $srLogs()===1 && $srNotes()===2,'The same session sent twice is saved once' . ($err ? ": $err" : ''));

// Neither hours nor a note: refused, nothing saved, the hours field says why.
[[$code,,$err],$sess]=$pageSession(fn()=>$renderPage('track',['id'=>$srTrack],['add_log'=>'1','work_date'=>date('Y-m-d'),'hours'=>'','description'=>'']));
check($code===0 && $err==='' && $srLogs()===1 && $srNotes()===2 && str_contains($sess,'or write a session note'),
    'Record a session with neither hours nor a note saves nothing and says what to enter' . ($err ? ": $err" : ''));
// Hours without a description are still refused (the invoice needs it), and the note with them is not saved alone.
[$code,,$err]=$renderPage('track',['id'=>$srTrack],['add_log'=>'1','work_date'=>date('Y-m-d'),'hours'=>'1','description'=>'','body'=>'Note kept until fixed']);
check($code===0 && $err==='' && $srLogs()===1 && $srNotes()===2,'Hours with no activity description are refused, and the note typed with them waits' . ($err ? ": $err" : ''));
// The page shows one session form with the note fields, and no separate track note form.
[$code,$out,$err]=$renderPage('track',['id'=>$srTrack]);
check($code===0 && $err==='' && str_contains($out,'Record a session') && str_contains($out,'name="note_format"') && !str_contains($out,'name="add_track_note"') && substr_count($out,'name="add_log"')===1,
    'The track page has one Record a session form holding the note fields' . ($err ? ": $err" : ''));

// The schedule: completing a session with the hours changed, miles and a note.
$past=date('Y-m-d H:i:s',strtotime('-3 hours')); $pastEnd=date('Y-m-d H:i:s',strtotime('-2 hours'));
$db->prepare("INSERT INTO scheduled_sessions(client_id,track_id,user_id,start_at,end_at) VALUES(?,?,1,?,?)")->execute([$srClient,$srTrack,date('Y-m-d 08:00:00',strtotime('-1 day')),date('Y-m-d 09:00:00',strtotime('-1 day'))]); $srSess=(int)$db->lastInsertId();
[$code,$out,$err]=$renderPage('schedule',[]);
check($code===0 && $err==='' && str_contains($out,'id="sh-'.$srSess.'"') && str_contains($out,'id="sm-'.$srSess.'"'),
    'A started session on the schedule offers hours (prefilled), miles and the note' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('schedule',[],['set_status'=>'1','session_id'=>(string)$srSess,'to'=>'Cancelled','session_hours'=>'1','session_miles'=>'5']);
check($code===0 && $err==='' && $db->query("SELECT status FROM scheduled_sessions WHERE id=$srSess")->fetchColumn()==='Scheduled',
    'Miles typed for a cancelled session are refused with nothing changed' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('schedule',[],['set_status'=>'1','session_id'=>(string)$srSess,'to'=>'Completed','session_hours'=>'1.75','session_miles'=>'12','session_note'=>'Completed with changed hours']);
$srWl=$db->query("SELECT w.hours,w.mileage FROM scheduled_sessions s JOIN work_logs w ON w.id=s.work_log_id WHERE s.id=$srSess")->fetch();
check($code===0 && $err==='' && $srWl && (float)$srWl['hours']===1.75 && (float)$srWl['mileage']===12.0
    && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE track_id=$srTrack AND body LIKE '%Completed with changed hours%'")->fetchColumn()===1,
    'Mark completed logs the hours and miles typed in the row, with the note' . ($err ? ": $err" : ''));

// The app: log_hours takes the session note, saved with the hours; a replay is one entry.
$apiKey='5E55A0A1-0000-4000-8000-000000000032';
$apiLog=['track_id'=>$srTrack,'hours'=>1,'date'=>date('Y-m-d',strtotime('-2 days')),'description'=>'App session','session_note'=>'App session note synthetic','idem_key'=>$apiKey];
$apiRes=$call('log_hours',$apiLog);
check(($apiRes['note_saved']??null)===true && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE track_id=$srTrack AND body='App session note synthetic' AND idem_key='$apiKey'")->fetchColumn()===1,
    'The app logs hours and the session note in one step');
$replay=$call('log_hours',$apiLog);
check(($replay['deduplicated']??null)===true && (int)$db->query("SELECT COUNT(*) FROM client_notes WHERE track_id=$srTrack AND body='App session note synthetic'")->fetchColumn()===1,
    'A replayed app entry saves its hours and note once');
