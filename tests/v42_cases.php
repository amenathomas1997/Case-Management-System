<?php
// Included by full_schema.php after v41_cases.php, against its synthetic database only:
// 4.2.0's pricing rules, set on screen (Pricing rules), and how they quote referrals and
// guard tracks for someone with an open VR case.
$pubForm = function (array $post) use ($renderPage): array {
    putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
    try { return $renderPage('community_referral', [], $post); } finally { putenv('CMS_TEST_ANON'); }
};
$selfRef = ['role'=>'self','referrer_name'=>'Pricing Synthetic Person','referrer_phone'=>'555-0142','referrer_email'=>'','body'=>'Synthetic pricing referral.',
    'referral_purpose'=>'New referral','requested_services'=>['Assistive technology training'],'specific_needs'=>['Screen readers'],'consent'=>'1'];

/* The defaults are the owner's rules */
$p=pricing_settings();
check($p['vr_first'] && $p['sliding_on'] && $p['youth_age']===18 && $p['vr_conflict']==='warn' && $p['ask']==='self','The pricing rules start as the owner chose: VR first, free below the limit, a parent\'s income under 18, warn, self-referrals asked');
check(pricing_quote('yes',true)===null && pricing_quote('no',true)===0.0 && pricing_quote('unsure',false)===$p['personal_rate'],'An open VR case is billed to VR; otherwise free below the limit, else the personal rate');
check(pricing_asks_role('self') && !pricing_asks_role('family') && !pricing_asks_role('counselor'),'Only people referring themselves are asked, by default');

/* The page saves them; wrong values are refused */
[$code,$out,$err]=$renderPage('pricing',[]);
check($code===0 && $err==='' && str_contains($out,'Pricing rules') && str_contains($out,'Use a parent\'s income for anyone younger than'),'Administrators see every pricing rule on one page' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('pricing',[],['save_pricing'=>'1','personal_rate'=>'0','free_below'=>'abc','youth_age'=>'99','vr_conflict'=>'maybe','ask'=>'x']);
check(setting('pricing_vr_conflict')===null && abs(billing_settings()['private_rate']-$p['personal_rate'])<0.001,'Wrong pricing values are refused and nothing changes');
[$code,,$err]=$renderPage('pricing',[],['save_pricing'=>'1','personal_rate'=>'25.00','free_below'=>'3000','youth_age'=>'21','vr_conflict'=>'block','ask'=>'all','vr_first'=>'1']);
$p2=pricing_settings();
check($code===0 && $err==='' && abs($p2['personal_rate']-25.0)<0.001 && abs($p2['free_below']-3000.0)<0.001 && $p2['youth_age']===21 && $p2['vr_conflict']==='block' && $p2['ask']==='all' && !$p2['sliding_on'],'Another business sets its own rate, limit, youth age and rules' . ($err ? ": $err" : ''));
check(pricing_quote('no',true)===25.0 && pricing_asks_role('family') && !pricing_asks_role('agency'),'With the sliding fee off, nobody is quoted free; asking everyone reaches family referrals but not agencies');

/* The referral form follows them */
[$code,$out,$err]=$pubForm([]);
check(!str_contains($out,'name="income"') && str_contains($out,'name="vr_case"'),'With the sliding fee off, the form does not ask about income');
[$code,,$err]=$renderPage('pricing',[],['save_pricing'=>'1','personal_rate'=>'17.50','free_below'=>'2000','youth_age'=>'18','vr_conflict'=>'warn','ask'=>'self','vr_first'=>'1','sliding_on'=>'1']);
[$code,$out,$err]=$pubForm([]);
check(str_contains($out,'Do you have an open case with a vocational rehabilitation (VR) agency?') && str_contains($out,'If you are under 18, give your parents') && str_contains($out,'name="income"'),'The form asks about an open VR case, and a youth gives their parents\' income');
[$code,$out,$err]=$pubForm($selfRef+['income'=>'below_2000']);
check(str_contains($out,'Open VR case: please choose one.'),'The open VR case question must be answered');
$before=(int)$db->query('SELECT MAX(id) FROM referrals')->fetchColumn();
[$code,$out,$err]=$pubForm($selfRef+['income'=>'below_2000','vr_case'=>'yes']);
$vrRef=$db->query("SELECT * FROM referrals WHERE id>$before ORDER BY id DESC LIMIT 1")->fetch();
check($vrRef && $vrRef['vr_case_open']==='yes' && $vrRef['quoted_fee']===null,'Someone with an open VR case is quoted the VR rate, not free' . ($err ? ": $err" : ''));
[$code,$out,$err]=$pubForm($selfRef+['income'=>'below_2000','vr_case'=>'no','referrer_name'=>'Pricing Synthetic Free']);
$freeRef=$db->query("SELECT * FROM referrals WHERE id>".(int)$vrRef["id"]." ORDER BY id DESC LIMIT 1")->fetch();
check($freeRef && $freeRef['vr_case_open']==='no' && (float)$freeRef['quoted_fee']===0.0,'Without a VR case, income below the limit is free');
[$code,$out,$err]=$renderPage('referrals',['view'=>(int)$vrRef['id']]);
check($code===0 && str_contains($out,'The VR rate, billed to their VR agency') && str_contains($out,'Open VR case'),'Staff see the answer and the VR rate on the referral' . ($err ? ": $err" : ''));

/* Tracks for someone with an open VR case */
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Open VR Synthetic Client','Active Training',1)"); $ovc=(int)$db->lastInsertId();
$db->prepare("UPDATE referrals SET client_id=? WHERE id=?")->execute([$ovc,(int)$vrRef['id']]);
check(client_open_vr_case($ovc),'A referral that said yes counts as an open VR case');
[$code,$out,$err]=$renderPage('track_edit',['client_id'=>(string)$ovc]);
check($code===0 && preg_match('/value="vr"[^>]*checked|value="vr"[^>]*selected/',$out)===1,'A new track for them starts as VR or agency authorization' . ($err ? ": $err" : ''));
$privTrack=['title'=>'Pricing private track','billing_mode'=>'private','auth_number'=>'','funding_agency'=>'','authorized_hours'=>'0','hourly_rate'=>'17.50','status'=>'Active','service_type'=>'Assistive Technology'];
[[$code,,$err],$sess]=$pageSession(fn()=>$renderPage('track_edit',['client_id'=>(string)$ovc],$privTrack));
check((int)$db->query("SELECT COUNT(*) FROM training_tracks WHERE client_id=$ovc AND title='Pricing private track'")->fetchColumn()===1 && str_contains($sess,'open VR case'),'By default a personal-rate track is saved, with a warning' . ($err ? ": $err" : ''));
set_setting('pricing_vr_conflict','block');
$renderPage('track_edit',['client_id'=>(string)$ovc],['title'=>'Pricing free track','billing_mode'=>'none']+$privTrack);
check((int)$db->query("SELECT COUNT(*) FROM training_tracks WHERE client_id=$ovc AND title='Pricing free track'")->fetchColumn()===0,'Set to refuse, free training for them is not saved');
$appSave=$call('save_track',['client_id'=>$ovc,'title'=>'Pricing app free','billing_mode'=>'none','status'=>'Active'],[],false);
check(str_contains(json_encode($appSave),'open VR case'),'The app is refused the same way');
$pt=(int)$db->query("SELECT id FROM training_tracks WHERE client_id=$ovc AND title='Pricing private track'")->fetchColumn();
[$code,,$err]=$renderPage('track_edit',['id'=>(string)$pt],['title'=>'Pricing private track renamed']+$privTrack);
check($db->query("SELECT title FROM training_tracks WHERE id=$pt")->fetchColumn()==='Pricing private track renamed','Even set to refuse, an existing personal track can still be edited' . ($err ? ": $err" : ''));
set_setting('pricing_vr_conflict','warn');
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Expired VR Synthetic Client','Active Training',1)"); $evc=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Expired VR','Active','EXP-1','Synthetic VR',10,50,DATE_SUB(CURDATE(),INTERVAL 90 DAY),DATE_SUB(CURDATE(),INTERVAL 1 DAY))")->execute([$evc]);
$db->prepare("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name,client_id,vr_case_open) VALUES('r','s','b','','','','','Expired VR Synthetic Client',?,'yes')")->execute([$evc]);
check(!client_open_vr_case($evc),'An expired authorization, or a referral answer after a VR track already existed, is not an open VR case');

/* Each VR agency's own rate */
$db->exec("INSERT INTO funding_agencies(name,state,hourly_rate,mileage_rate) VALUES('Synthetic Missouri Agency','MO',60.00,0.655),('Synthetic Minnesota Agency','MN',80.00,NULL)"); agencies_all(true);
check(agency_hourly_rate('synthetic missouri agency')===60.0 && agency_mileage_rate('Synthetic Missouri Agency')===0.655 && agency_hourly_rate('Synthetic Minnesota Agency')===80.0 && agency_mileage_rate('Synthetic Minnesota Agency')===null && agency_hourly_rate('Unknown agency')===null,'Each agency keeps its own rates');
[$code,$out,$err]=$renderPage('pricing',[]);
check(str_contains($out,'VR agency rates') && str_contains($out,'$60.00') && str_contains($out,'$80.00'),'Pricing rules lists every agency\'s rate');
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Missouri Rate Client','Active Training',1)"); $mrc=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('track_edit',['client_id'=>(string)$mrc],['title'=>'Missouri rate track','billing_mode'=>'vr','auth_number'=>'MO-RATE-1','funding_agency'=>'Synthetic Missouri Agency','authorized_hours'=>'10','hourly_rate'=>'','mileage_rate'=>'','status'=>'Active','service_type'=>'Assistive Technology','start_date'=>date('Y-m-d'),'expiration_date'=>date('Y-m-d',strtotime('+60 days'))]);
$mrt=$db->query("SELECT hourly_rate,mileage_rate FROM training_tracks WHERE title='Missouri rate track'")->fetch();
check($mrt && (float)$mrt['hourly_rate']===60.0 && (float)$mrt['mileage_rate']===0.655,'A VR track with no rate typed charges its agency\'s rates' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('track_edit',['client_id'=>(string)$mrc],['title'=>'Minnesota typed rate','billing_mode'=>'vr','auth_number'=>'MN-RATE-1','funding_agency'=>'Synthetic Minnesota Agency','authorized_hours'=>'10','hourly_rate'=>'95','mileage_rate'=>'','status'=>'Active','service_type'=>'Assistive Technology','start_date'=>date('Y-m-d'),'expiration_date'=>date('Y-m-d',strtotime('+60 days'))]);
check((float)$db->query("SELECT hourly_rate FROM training_tracks WHERE title='Minnesota typed rate'")->fetchColumn()===95.0,'A rate typed from the authorization is kept');
[$code,,$err]=$renderPage('funding_agencies',[],['save_agency'=>'1','agency_id'=>'0','name'=>'Synthetic Bad Rate Agency','hourly_rate'=>'forty']);
check($db->query("SELECT COUNT(*) FROM funding_agencies WHERE name='Synthetic Bad Rate Agency'")->fetchColumn()==0,'A rate that is not a number is refused');
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('App Rate Synthetic Client','Active Training',1)"); $arc=(int)$db->lastInsertId();
$appTrack=$call('save_track',['client_id'=>$arc,'title'=>'App Missouri','billing_mode'=>'vr','auth_number'=>'MO-APP-1','funding_agency'=>'Synthetic Missouri Agency','authorized_hours'=>'10','hourly_rate'=>'','mileage_rate'=>'','status'=>'Active','start_date'=>date('Y-m-d'),'expiration_date'=>date('Y-m-d',strtotime('+30 days'))]);
check((float)$db->query("SELECT hourly_rate FROM training_tracks WHERE title='App Missouri'")->fetchColumn()===60.0,'In the app, a blank rate on a new VR track takes the agency\'s rate');
