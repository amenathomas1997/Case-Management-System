<?php
// Included by full_schema.php after reports_cases.php, against its synthetic database only:
// spreadsheet downloads, client filters, the client timeline, scoring direction, and the
// business summary in the scheduled email (3.0.50).
foreach (array_keys(LIST_EXPORTS) as $lk) {
    [$hd,$rows]=list_export_rows($lk,1);
    check($hd && is_array($rows) && (!$rows || count($rows[0])===count($hd)),"The $lk download has a value for every column");
}
[$code,$out,$err]=$renderPage('exports',['list'=>'clients']);
check($code===0 && str_contains($out,'Name,Status') && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='report.export' AND detail LIKE 'Clients list CSV%'")->fetchColumn()>=1,'A list downloads as CSV, and the download is audited' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('exports',[]);
check($code===0 && $err==='' && str_contains($out,'Download equipment loans (CSV)'),'The downloads page lists what the account may download' . ($err ? ": $err" : ''));

/* Client filters */
$db->exec("INSERT INTO clients(name,status,client_type,school,assigned_to) VALUES('Filter Student','Active Training','Pre-ETS','Synthetic High School',1)"); $fsc=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate) VALUES(?,'Filter track','Active','FL-1','Filter Agency',5,50)")->execute([$fsc]);
[$code,$out,$err]=$renderPage('clients',['who'=>'','type'=>'Pre-ETS','agency'=>'Filter Agency','school'=>'Synthetic High']);
check($code===0 && $err==='' && str_contains($out,'Filter Student') && !str_contains($out,'Quality Duplicate'),'The client list filters by type, funding agency and school' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('clients',['who'=>'me','type'=>'Pre-ETS','export'=>'1']);
check($code===0 && str_contains($out,'Filter Student') && !str_contains($out,'Quality Duplicate'),'The filtered client list downloads past the screen limit' . ($err ? ": $err" : ''));

/* Timeline */
$tl=client_timeline($fc);
$whats=implode("\n",array_column($tl,'what'));
check(str_contains($whats,'Case closed') && str_contains($whats,'Outcome survey answered') && str_contains($whats,'Status changed'),'The timeline gathers status changes, the closure and the survey');
check($tl===array_values($tl) && strcmp($tl[0]['at'],end($tl)['at'])>=0,'The timeline is newest first');
$sd=client_status_days($fc);
check($sd['days'] && isset($sd['days']['Closed - Rehabilitated']) && $sd['total']>=0,'Days in each status');
[$code,$out,$err]=$renderPage('client_timeline',['id'=>(string)$fc]);
check($code===0 && $err==='' && str_contains($out,'Time in each status') && str_contains($out,'<h3>'),'The timeline page has month headings' . ($err ? ": $err" : ''));

/* Scoring direction, and the percentage fix */
$db->exec("INSERT INTO assessment_instruments(name,scale_min,scale_max,higher_is_better) VALUES('Lower better synthetic',1,5,0)"); $lbi=(int)$db->lastInsertId();
$db->exec("INSERT INTO assessment_instrument_items(instrument_id,label,sort) VALUES($lbi,'Item A',1),($lbi,'Item B',2)");
foreach ([['2026-01-01',10,100.0],['2026-02-01',4,30.0]] as [$on,$tot,$pct]) {
    $db->prepare("INSERT INTO client_scored_assessments(client_id,instrument_id,assessed_on,total_score,max_score,pct) VALUES(?,?,?,?,10,?)")->execute([$fc,$lbi,$on,$tot,$pct]); $sid=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO client_scored_assessment_items(scored_id,item_id,score) VALUES(?,1,?),(?,2,?)")->execute([$sid,intdiv($tot,2),$sid,$tot-intdiv($tot,2)]);
}
$db->exec(file_get_contents($root.'/dist/migrations/mig_3.0.50.sql'));
check(array_map('floatval',$db->query("SELECT pct FROM client_scored_assessments WHERE instrument_id=$lbi ORDER BY assessed_on")->fetchAll(PDO::FETCH_COLUMN))===[100.0,25.0],'Old percentages are recalculated on the instrument scale');
[$code,$out,$err]=$renderPage('client',['id'=>(string)$fc,'tab'=>'assessments']);
check($code===0 && str_contains($out,'since baseline, better'),'A falling score on a lower-is-better instrument reads as better' . ($err ? ": $err" : ''));

/* Business summary in the scheduled email */
$secs=scheduled_report_sections('business',date('Y-m-01'),date('Y-m-d'));
check($secs[0]['heading']==='This period compared' && count($secs[0]['table']['rows'])===count(BUSINESS_FIGURE_LABELS),'The scheduled email can carry the business summary');
[$code,,$err]=$renderPage('admin',[],['save_scheduled_reports'=>'1','sr_business'=>'1','sr_recipients'=>'boss@example.test','sr_day'=>'2','sr_period'=>'last_month']);
check($code===0 && scheduled_reports_config()['business']===true,'Administration turns on the scheduled business summary' . ($err ? ": $err" : ''));
check(array_sum($sd['days'])===$sd['total'],'Days in each status add up to the length of stay');
