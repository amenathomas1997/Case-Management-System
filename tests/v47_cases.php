<?php
// Included by full_schema.php after v46_cases.php, against its synthetic database only:
// 4.7.0's custom report builder.
[$code,$out,$err]=$renderPage('custom_reports',[]);
check($code===0 && $err==='' && str_contains($out,'Build a report') && str_contains($out,'name="cols[]"'),'The report builder opens' . ($err ? ": $err" : ''));
foreach (array_keys(custom_report_subjects()) as $sub) {
    $fieldKeys=array_keys(custom_report_subjects()[$sub][3]);
    $res=custom_report_run(custom_report_clean(['subject'=>$sub,'cols'=>$fieldKeys]),1);
    check(is_array($res['rows']) && count($res['head'])===count($fieldKeys),'Every column of '.$sub.' runs');
}
$def=custom_report_clean(['subject'=>'work','cols'=>['client','hours','value','nonsense'],'group'=>'client','sort'=>'hours; DROP TABLE x','from'=>'2000-01-01','to'=>date('Y-m-d')]);
check($def['cols']===['client','hours','value'] && $def['sort']==='','Unknown columns and sorts are dropped, never put into the query');
$g=custom_report_run($def,1);
check($g['grouped'] && $g['head']===['Client','How many','Total hours','Total value'],'Grouping gives how many and totals for each');
[$code,$out,$err]=$renderPage('custom_reports',['subject'=>'tracks','cols'=>['client','auth_number','funding_agency'],'run'=>'1']);
check($code===0 && $err==='' && str_contains($out,'<th scope="col">Authorization</th>') && str_contains($out,'Download as a spreadsheet'),'A report is shown as a table with a download' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('custom_reports',[],['save_report'=>'1','report_name'=>'Synthetic hours by client','subject'=>'work','cols'=>['client','hours'],'group'=>'client','shared'=>'1']);
$sr=$db->query("SELECT * FROM saved_reports WHERE name='Synthetic hours by client'")->fetch();
check($sr && (int)$sr['shared']===1 && json_decode($sr['definition'],true)['group']==='client','A report is saved, and can be shared' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('custom_reports',['saved'=>(string)$sr['id']]);
check($code===0 && str_contains($out,'Synthetic hours by client:') && str_contains($out,'How many'),'A saved report runs from the list' . ($err ? ": $err" : ''));
/* Caseload */
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Report Other Caseload','Active Training',3)");
$all=custom_report_run(custom_report_clean(['subject'=>'clients','cols'=>['name']]),1,10000);
$GLOBALS['CURRENT_SCOPED']=true;
$mine=custom_report_run(custom_report_clean(['subject'=>'clients','cols'=>['name']]),1,10000);
$GLOBALS['CURRENT_SCOPED']=false;
check(in_array(['Report Other Caseload'],$all['rows'],true) && !in_array(['Report Other Caseload'],$mine['rows'],true),'Someone limited to their own caseload sees only their own clients in a custom report');
/* Review fixes */
$db->prepare("INSERT INTO referrals(reason,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name,status,assigned_to,client_id) VALUES('r','s','b','','','','','Converted Elsewhere Person','Converted',NULL,?)")
    ->execute([(int)$db->query("SELECT id FROM clients WHERE name='Report Other Caseload'")->fetchColumn()]);
$GLOBALS['CURRENT_SCOPED']=true;
$refMine=custom_report_run(custom_report_clean(['subject'=>'referrals','cols'=>['client']]),1,10000);
$GLOBALS['CURRENT_SCOPED']=false;
check(!in_array(['Converted Elsewhere Person'],$refMine['rows'],true),'A converted referral whose client is on another caseload is not in a scoped referrals report');
$permsBefore=$GLOBALS['CURRENT_PERMS']; $GLOBALS['CURRENT_PERMS']=['analytics','clients'];
check(custom_report_clean(['subject'=>'invoices'])['subject']==='clients' && custom_report_clean(['subject'=>'referrals'])['subject']==='clients','Invoices need the billing permission and referrals the referrals permission');
$GLOBALS['CURRENT_PERMS']=$permsBefore;
$gh=custom_report_run(custom_report_clean(['subject'=>'tracks','cols'=>['funding_agency','hours_used'],'group'=>'funding_agency']),1);
check($gh['grouped'] && $gh['head'][2]==='Total hours logged','Hours logged can be totalled by group');
check(custom_report_cell('0.00','number')==='0' && custom_report_csv_value('1234.5','money')==='1234.5' && custom_report_cell('14:30:00','time')==='2:30 PM','Zero shows as 0, spreadsheet amounts are plain numbers, and times read as times');
$db->exec("UPDATE saved_reports SET owner_id=3 WHERE id=".(int)$sr['id']);
$permsBefore=$GLOBALS['CURRENT_PERMS'];
[$code,,$err]=$renderPage('custom_reports',[],['delete_report'=>'1','report_id'=>(string)$sr['id']]);
check((int)$db->query("SELECT COUNT(*) FROM saved_reports WHERE id=".(int)$sr['id'])->fetchColumn()===0,'An administrator can delete any saved report');
$db->prepare("INSERT INTO saved_reports(name,owner_id,shared,definition) VALUES('Not yours',3,1,'{}')")->execute(); $ny=(int)$db->lastInsertId();
$db->exec("UPDATE saved_reports SET owner_id=1 WHERE id=$ny");
[$code,,$err]=$renderPage('custom_reports',[],['delete_report'=>'1','report_id'=>(string)$ny]);
check((int)$db->query("SELECT COUNT(*) FROM saved_reports WHERE id=$ny")->fetchColumn()===0,'Its owner can delete a saved report');
