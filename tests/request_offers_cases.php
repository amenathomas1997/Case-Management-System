<?php
// Included by full_schema.php after portal_letters_cases.php, against its synthetic database
// only: times offered on a portal request and confirmed by the client (3.0.52).
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Offer Person','Active Training',1)"); $oc=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Offer track','Active','OF-1','Synthetic VR',20,50,DATE_SUB(CURDATE(),INTERVAL 10 DAY),DATE_ADD(CURDATE(),INTERVAL 90 DAY))")->execute([$oc]); $ot=(int)$db->lastInsertId();
$otok=bin2hex(random_bytes(24));
$db->prepare("INSERT INTO portal_tokens(client_id,token,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([$oc,$otok]);
$db->prepare("INSERT INTO portal_requests(client_id,type,details,status) VALUES(?,'appointment','Any weekday morning','New')")->execute([$oc]); $or=(int)$db->lastInsertId();
$base=strtotime('+12 days 09:00'); while ((int)date('N',$base) > 5) $base+=86400;
// The instructor is already busy at the second time.
$db->prepare("UPDATE scheduled_sessions SET status='Cancelled' WHERE user_id=1 AND status='Scheduled' AND start_at BETWEEN ? AND ?")->execute([date('Y-m-d H:i:s',$base-3600),date('Y-m-d H:i:s',$base+2*86400+7200)]);
$db->prepare("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,status) VALUES(?,1,?,?,'Scheduled')")->execute([$ac,date('Y-m-d H:i:s',$base+86400),date('Y-m-d H:i:s',$base+86400+3600)]); $busy=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('portal_requests',[],['offer_times'=>'1','id'=>(string)$or,'time1'=>date('Y-m-d\TH:i',$base),'time2'=>date('Y-m-d\TH:i',$base+86400),'time3'=>date('Y-m-d\TH:i',$base+2*86400),'minutes'=>'60','staff'=>'1','track_id'=>(string)$ot,'location'=>'Office']);
$offers=$db->query("SELECT * FROM portal_request_offers WHERE request_id=$or AND status='Offered' ORDER BY start_at")->fetchAll();
check($code===0 && $err==='' && count($offers)===2 && $db->query("SELECT status FROM portal_requests WHERE id=$or")->fetchColumn()==='In Progress','Times are offered, and one that clashes with the instructor\'s calendar is refused' . ($err ? ": $err" : ''));
$r=offer_times_save($or,[time()+600],60,1,$ot,false,'',1);
check($r['error']!==null && $r['offered']===0,'A time less than an hour away is not offered');
[$code,$out,$err]=$renderPage('portal',['t'=>$otok]);
check($code===0 && $err==='' && str_contains($out,'Choose a time') && str_contains($out,'Confirm this time'),'The client sees the offered times' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('portal',['t'=>$otok],['confirm_offer'=>'1','offer_id'=>(string)$offers[1]['id']]);
$booked=$db->query("SELECT * FROM scheduled_sessions WHERE client_id=$oc AND status='Scheduled'")->fetch();
check($code===0 && $booked && $booked['start_at']===$offers[1]['start_at'] && (int)$booked['track_id']===$ot
    && $db->query("SELECT status FROM portal_requests WHERE id=$or")->fetchColumn()==='Done'
    && $db->query("SELECT status FROM portal_request_offers WHERE id={$offers[0]['id']}")->fetchColumn()==='Withdrawn','Confirming books the session, withdraws the other times and closes the request' . ($err ? ": $err" : ''));
check(offer_confirm((int)$offers[1]['id'],$oc)['error']!==null,'A time is booked once');

/* Reschedule: the session moves */
$db->prepare("INSERT INTO portal_requests(client_id,type,session_id,status) VALUES(?,'reschedule',?,'New')")->execute([$oc,(int)$booked['id']]); $rr=(int)$db->lastInsertId();
$new=$base+3*86400+3600*4;
$r=offer_times_save($rr,[$new],60,1,null,true,'',1);
$ro=$db->query("SELECT id FROM portal_request_offers WHERE request_id=$rr AND status='Offered'")->fetchColumn();
check($r['error']===null && $ro,'Times can be offered to move a session');
$res=offer_confirm((int)$ro,$oc);
check($res['error']===null && (int)$res['session_id']===(int)$booked['id'] && $db->query("SELECT start_at FROM scheduled_sessions WHERE id={$booked['id']}")->fetchColumn()===date('Y-m-d H:i:s',$new),'Confirming a reschedule moves the session');
check(offer_confirm((int)$ro,$ac)['error']!==null,'A client cannot confirm another client\'s offer');

/* Review fixes */
$db->prepare("INSERT INTO portal_requests(client_id,type,status) VALUES(?,'appointment','New')")->execute([$ac]); $nr=(int)$db->lastInsertId();
check(str_contains((string)offer_times_save($nr,[$base+5*86400],60,1,null,false,'',1)['error'],'no working portal link'),'Times are not offered to a client with no portal link');
$db->prepare("INSERT INTO portal_requests(client_id,type,status) VALUES(?,'appointment','New')")->execute([$oc]); $tr2=(int)$db->lastInsertId();
$r=offer_times_save($tr2,[$base+6*86400,$base+6*86400+7200],60,1,$ot,false,'',1);
$two=array_map('intval',$db->query("SELECT id FROM portal_request_offers WHERE request_id=$tr2 AND status='Offered' ORDER BY start_at")->fetchAll(PDO::FETCH_COLUMN));
check($r['offered']===2 && offer_confirm($two[0],$oc)['error']===null && offer_confirm($two[1],$oc)['error']!==null
    && (int)$db->query("SELECT COUNT(*) FROM portal_request_offers WHERE request_id=$tr2 AND status='Chosen'")->fetchColumn()===1,'Only one time on a request can be booked');
