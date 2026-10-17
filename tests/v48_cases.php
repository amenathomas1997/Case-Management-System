<?php
// Included by full_schema.php after v47_cases.php, against its synthetic database only:
// 4.8.0's offline queue additions: a goal progress entry or a task completion sent again from
// the phone is recorded once and answered as done.
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Offline Synthetic Client','Active Training',1)"); $oc8=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Offline track','Active','OFF-1','Synthetic VR',10,50,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 30 DAY))")->execute([$oc8]); $ot8=(int)$db->lastInsertId();
$db->prepare("INSERT INTO smart_goals(track_id,specific_goal,measurable_metric) VALUES(?,'Synthetic offline goal','Synthetic metric')")->execute([$ot8]); $og8=(int)$db->lastInsertId();
$key=strtoupper('6b1f2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d');
$first=$call('update_goal',['id'=>$og8,'status'=>'In Progress','progress_pct'=>40,'note'=>'Synthetic','idem_key'=>$key]);
$again=$call('update_goal',['id'=>$og8,'status'=>'In Progress','progress_pct'=>40,'note'=>'Synthetic','idem_key'=>$key]);
check(empty($first['deduplicated']) && !empty($again['deduplicated']) && (int)$db->query("SELECT COUNT(*) FROM goal_progress WHERE goal_id=$og8")->fetchColumn()===1,'A goal progress entry sent again from the phone is recorded once');
add_task('Offline synthetic task',null,null,null,null,1,1);
$tid8=(int)$db->query("SELECT id FROM tasks WHERE title='Offline synthetic task'")->fetchColumn();
$call('complete_task',['id'=>$tid8,'idem_key'=>'7c2a1b3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d']);
$replay=$call('complete_task',['id'=>$tid8,'idem_key'=>'7c2a1b3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d']);
check(!empty($replay['deduplicated']) && $db->query("SELECT status FROM tasks WHERE id=$tid8")->fetchColumn()==='Done','A task completion sent again from the phone is answered as done');
$call('complete_task',['id'=>$tid8],[],false,'already in that state');
check(true,'Without a queue key, completing a done task is still refused, as before');

/* Review fixes */
$db->prepare("INSERT INTO smart_goals(track_id,specific_goal,measurable_metric,progress_pct,status) VALUES(?,'Synthetic stale goal','m',60,'In Progress')")->execute([$ot8]); $sg8=(int)$db->lastInsertId();
$stale=$call('update_goal',['id'=>$sg8,'status'=>'In Progress','progress_pct'=>40,'idem_key'=>'8d3b2c4e-5f6a-4b7c-9d8e-0f1a2b3c4d5e','expected_status'=>'In Progress','expected_pct'=>'20','recorded_at'=>date('c',time()-3600)]);
$sgNow=$db->query("SELECT progress_pct FROM smart_goals WHERE id=$sg8")->fetchColumn();
$hist=$db->query("SELECT progress_pct, created_at FROM goal_progress WHERE goal_id=$sg8")->fetch();
check(!empty($stale['kept_newer']) && (int)$sgNow===60 && (int)$hist['progress_pct']===40 && strtotime($hist['created_at'])<=time()-3500,'An offline goal entry written before someone else\'s newer progress goes into the history at its own time and keeps the newer progress');
$db->prepare("INSERT INTO homework(track_id,title,status,assigned_at) VALUES(?,'Synthetic queued homework','Assigned',NOW())")->execute([$ot8]); $hw8=(int)$db->lastInsertId();
$call('complete_homework',['id'=>$hw8]); $call('complete_homework',['id'=>$hw8]);
check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='homework.complete' AND entity_id=$hw8")->fetchColumn()===1,'Homework completed twice (a resend) is recorded and audited once');
[$code,$out,$err]=$renderPage('index',[]);
check(preg_match('~<a href="clients.php"[^>]*>Client records<span class="nav-hint-sr visually-hidden">: Find a person~',$out)===1 && !str_contains($out,'aria-describedby="nav-hint-'),'Each main menu link carries its hint inside the link, where JAWS reads it');
