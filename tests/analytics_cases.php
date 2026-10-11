<?php
$db->exec("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,hourly_rate,mileage_rate) VALUES(1,'Historical rate check','TEST-HISTORY','AUDIT-RATE-FUNDER',99,9)");$tid=(int)$db->lastInsertId();
// Work is dated with PHP's date(), the same clock the pages and the API filter by.
$db->prepare('INSERT INTO work_logs(track_id,work_date,hours,mileage,hourly_rate,mileage_rate,description,created_by) VALUES(?,?,2,5,10,1,?,1)')->execute([$tid,date('Y-m-d'),'Synthetic historical rates']);
$db->prepare("INSERT INTO invoices(track_id,invoice_number,status,total_amount,paid_amount) VALUES(?,'TEST-PARTIAL','Submitted',100,30),(?,'TEST-SHORT-PAID','Paid',100,80),(?,'TEST-UNSENT','Draft',9876,NULL)")->execute([$tid,$tid,$tid]);
$source=swap_required("require __DIR__ . '/inc/bootstrap.php';",'require '.var_export($tmp.'/bootstrap.php',true).';',file_get_contents($root.'/public/analytics.php'),'public/analytics.php');
$source=str_replace('__DIR__',var_export($root.'/public',true),$source);file_put_contents($tmp.'/analytics.php',$source);
$p=proc_open([PHP_BINARY,'-d','display_errors=stderr','-d','log_errors=0',$root.'/tests/full_schema.php','page',$tmp.'/analytics.php','{}'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
$html=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
check($code===0&&$err===''&&preg_match('~AUDIT-RATE-FUNDER</t[dh]>.*?\$25\.00</td>~s',$html)===1,'Analytics preserves historical hourly and mileage rate snapshots');
check(preg_match('~\$90\.00</span>\s*<span class="stat-label">Invoiced, not yet paid~',$html)===1,'Analytics excludes drafts and subtracts partial payments from outstanding invoices');
// A distinct client outside the actor's caseload must not influence any aggregate/export.
$db->exec("INSERT INTO clients(name,assigned_to) VALUES('Outside report client',2)");$outside=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,rsa_category,hourly_rate) VALUES(?,'Outside report track','OUTSIDE','OUTSIDE-AGENCY','OUTSIDE-CATEGORY',999)")->execute([$outside]);$outsideTrack=(int)$db->lastInsertId();
$db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description,created_by) VALUES(?,?,77,'Outside work',2)")->execute([$outsideTrack,date('Y-m-d')]);
$db->exec("UPDATE groups SET perms='analytics;billing;schedule;clients',own_caseload_only=1 WHERE id=999; UPDATE users SET group_id=999 WHERE id=1; UPDATE clients SET assigned_to=1 WHERE id=1");
// Each route must render its own content (so a refusal or an empty page fails)
// and show nothing of the outside client: its name, track, category or the
// 77 hours x 999 = 76,923 of value logged on it.
foreach(['analytics'=>[[[],'Business analytics']],'outcomes'=>[[[],'Outcome report'],[['export'=>1],'OUTCOME REPORT'],[['html'=>1],'Hours by Rehabilitation Services Administration (RSA) service category']],
         'funder_report'=>[[[],'Funder outcome report'],[['agency'=>'OUTSIDE-AGENCY'],'Funder outcome report']],'utilization'=>[[[],'Staff billable utilization'],[['export'=>1],'STAFF UTILIZATION']],
         'agency_report'=>[[[],'Agency billing summary']],'capacity'=>[[[],'Historical rate check']]] as $route=>$queries){
    $src=swap_required("require __DIR__ . '/inc/bootstrap.php';",'require '.var_export($tmp.'/bootstrap.php',true).';',file_get_contents($root.'/public/'.$route.'.php'),"public/$route.php");
    $src=str_replace('__DIR__',var_export($root.'/public',true),$src);file_put_contents($tmp.'/'.$route.'.php',$src);
    foreach($queries as [$query,$marker]){
        $p=proc_open([PHP_BINARY,'-d','display_errors=stderr','-d','log_errors=0',$root.'/tests/full_schema.php','page',$tmp.'/'.$route.'.php',json_encode($query)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
        $leaks=array_filter(['OUTSIDE-CATEGORY','76,923','Outside report client','Outside report track'],fn($s)=>str_contains($out,$s));
        check($code===0&&$err===''&&str_contains($out,$marker)&&!$leaks,"Scoped $route ".json_encode($query)." shows its own content and excludes outside-caseload work: $err".implode(', ',$leaks));
        if($route==='analytics'||$route==='agency_report')check(!str_contains($out,'OUTSIDE-AGENCY'),"$route does not list outside funders");
        // The CSV writes plain numbers: no 76923 value and no other staff member's 77 hours.
        if($route==='utilization'&&isset($query['export']))check(!str_contains($out,'76923')&&!preg_match('/(^|,)77(\.0+)?(,|$)/m',$out),'Scoped utilization CSV omits outside-caseload hours and value');
    }
}
$r=$call('utilization',[],['from'=>date('Y-m-d'),'to'=>date('Y-m-d')]);
check($r['total_delivered']<77,'Mobile utilization excludes outside-caseload hours');
$db->exec('UPDATE users SET group_id=NULL WHERE id=1');
$r=$call('utilization',[],['from'=>date('Y-m-d'),'to'=>date('Y-m-d')]);
check($r['total_delivered']>=77,'Unrestricted utilization retains organization-wide activity');
