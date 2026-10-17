<?php
// Included by full_schema.php after v420_cases.php, against its synthetic database only:
// 4.21.0: the AT Act report, and QuickBooks Online. Intuit is never called: CMS_TEST_QBO answers as
// QuickBooks would (by the longest matching address prefix).

// ---- AT Act report ----
check(at_act_fy('2025-10-01')===2026 && at_act_fy('2026-09-30')===2026 && at_act_range(2026)===['2025-10-01','2026-09-30'],'The federal fiscal year runs October to September');
check(at_act_type(['category'=>'Screen Reader','name'=>'x'])==='Vision' && at_act_type(['category'=>'AAC Device','name'=>'x'])==='Speech communication'
    && at_act_type(['category'=>'Screen Reader','at_type'=>'Hearing'])==='Hearing' && at_act_type(['category'=>'Other','name'=>'Laptop'])==='Computers and related','A device\'s AT type is the one chosen, else a guess from its category');
$fy=at_act_fy();
$r0=at_act_report($fy); // what earlier tests left this year; the checks below count the difference
$db->exec("INSERT INTO clients(name,status,assigned_to,state) VALUES('Loan Synthetic Client','Active Training',1,'IA')"); $lc=(int)$db->lastInsertId();
$db->exec("INSERT INTO equipment(name,category,status,value) VALUES('Synthetic video magnifier','Magnifier / CCTV','Available',2500)"); $e1=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('equipment',[],['loan_out'=>'1','equipment_id'=>(string)$e1,'client_id'=>(string)$lc,'due_date'=>date('Y-m-d',strtotime('+14 days')),'purpose'=>'decision','borrower_type'=>'family']);
$loan=$db->query("SELECT * FROM equipment_loans WHERE equipment_id=$e1")->fetch();
check($loan && $loan['purpose']==='decision' && $loan['borrower_type']==='family','A loan records why it was lent and who borrowed it'.($err?": $err":''));
$db->exec("INSERT INTO equipment(name,category,status,at_type) VALUES('Synthetic amplifier','Other','Available','Hearing')"); $e2=(int)$db->lastInsertId();
$db->prepare("INSERT INTO equipment_loans(equipment_id,client_id,loaned_at,returned_at) VALUES(?,?,CURDATE(),CURDATE())")->execute([$e2,$lc]); $l2=(int)$db->lastInsertId();
// A demonstration, and a reused device.
$demo=at_save('demo',['title'=>'Synthetic demo day','status'=>'Completed','demo_date'=>date('Y-m-d'),'at_type'=>'Vision','devices'=>'Two synthetic magnifiers','individuals'=>'3','family_members'=>'1','pro_education'=>'2','decision'=>AT_DECISIONS['meets']],1);
check($demo>0,'A demonstration is recorded under AT services');
$db->prepare("INSERT INTO at_records(kind,title,status,details,equipment_id,version) VALUES('reuse','Synthetic reuse','Redeployed',?,?,1)")->execute([json_encode(['estimated_value'=>'400.00']),$e1]);
$r=at_act_report($fy);
$d=fn(array $a, array $b, string ...$path)=>(function($x,$y,$p){foreach($p as $k){$x=$x[$k];$y=$y[$k];}return $x-$y;})($a,$b,$path);
check($d($r,$r0,'loans','total')===2 && $d($r,$r0,'loans','by_type','Vision')===1 && $d($r,$r0,'loans','by_type','Hearing')===1 && $d($r,$r0,'loans','by_purpose','decision')===1 && $d($r,$r0,'loans','by_purpose','')===1 && $d($r,$r0,'loans','by_borrower','family')===1,'Loans are counted by AT type, purpose and borrower (unrecorded ones counted as such)');
check($d($r,$r0,'demos','total')===1 && $d($r,$r0,'demos','by_type','Vision')===1 && $d($r,$r0,'demos','participants','individual')===3 && $d($r,$r0,'demos','participants','family')===1 && $d($r,$r0,'demos','participants','education')===2 && $d($r,$r0,'demos','decision','meets')===1,'Demonstrations are counted by type, participants and decision');
check($d($r,$r0,'reuse','total')===1 && abs(($r['reuse']['savings']-$r0['reuse']['savings'])-2500.0)<0.01,'Reused devices are counted, with their savings at what they cost new');
// A redeployment AT services made is reuse, not a short-term loan.
$db->prepare("INSERT INTO equipment_loans(equipment_id,client_id,loaned_at,notes) VALUES(?,?,CURDATE(),'AT service #999')")->execute([$e2,$lc]);
check(at_act_report($fy)['loans']['total']===$r['loans']['total'],'A device redeployed or lent as a repair loaner by AT services is not counted as a short-term loan');
check($d($r,$r0,'loans','decision','')===1,'A loan to help decide with no decision yet is counted as not recorded');
[$code,$html,$err]=$renderPage('at_act_report',['fy'=>(string)$fy]);
check(str_contains($html,'AT Act report, federal fiscal year '.$fy) && str_contains($html,'Loans to complete (') && str_contains($html,'Synthetic amplifier'),'The report page shows the counts and the loan to complete'.($err?": $err":''));
[$code,,$err]=$renderPage('at_act_report',['fy'=>(string)$fy],['save_loan'=>(string)$l2,'purpose_'.$l2=>'accommodation','borrower_'.$l2=>'individual']);
check($db->query("SELECT CONCAT(purpose,'/',borrower_type) FROM equipment_loans WHERE id=$l2")->fetchColumn()==='accommodation/individual','A loan\'s missing details are filled in from the report'.($err?": $err":''));
[$code,$csv,$err]=$renderPage('at_act_report',['fy'=>(string)$fy,'download'=>'1']);
check(str_contains($csv,'"Device demonstration",Participants,"People with disabilities",') && str_contains($csv,'"Device reutilization","Savings (estimated new cost)",All,'),'The report downloads as a spreadsheet'.($err?": $err":''));

// ---- QuickBooks Online ----
check(qbo_app()===null,'QuickBooks is offered only once its app keys are set');
$db->exec("INSERT INTO settings(name,value) VALUES ('cfg.qbo.client_id','synthetic-qbo-id'),('cfg.qbo.client_secret','synthetic-qbo-secret'),('cfg.qbo.sandbox','yes') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$GLOBALS['CONFIG']['qbo']=['client_id'=>'synthetic-qbo-id','client_secret'=>'synthetic-qbo-secret','sandbox'=>'yes'];
check(qbo_app()['api']==='https://sandbox-quickbooks.api.intuit.com','The sandbox company is used when chosen');
qbo_store(['access_token'=>'synthetic-qbo-access','refresh_token'=>'synthetic-qbo-refresh','expires_in'=>3600,'x_refresh_token_expires_in'=>8640000],'1234567',null,1);
$row=$db->query("SELECT * FROM qbo_connection")->fetch();
check($row && (int)$row['sandbox']===1 && !str_contains($row['access_token'].$row['refresh_token'],'synthetic-qbo') && qbo_access_token()==='synthetic-qbo-access','The connection is kept, its tokens only encrypted');
check(setting('qbo_since')===date('Y-m-d'),'Connecting starts from today: older invoices are never sent');
$GLOBALS['CONFIG']['qbo']['sandbox']='no';
check(qbo_connection()===null,'Switching off the sandbox needs the real company connected (sandbox ids are never used there)');
$GLOBALS['CONFIG']['qbo']['sandbox']='yes';
$base='https://sandbox-quickbooks.api.intuit.com/v3/company/1234567';
$q=fn(string $s)=>"$base/query?query=".rawurlencode($s);
$fakeQ=function(array $extra) use ($base,$q){ putenv('CMS_TEST_QBO='.json_encode($extra+[
    $q('select Id, Active from Customer')=>['body'=>['QueryResponse'=>[]]],
    "$base/customer"=>['body'=>['Customer'=>['Id'=>'C1']]],
    $q('select Id from Invoice')=>['body'=>['QueryResponse'=>[]]],
    $q('select Id from Item')=>['body'=>['QueryResponse'=>[]]],
    $q('select Id from Account')=>['body'=>['QueryResponse'=>['Account'=>[['Id'=>'A1']]]]],
    "$base/item"=>['body'=>['Item'=>['Id'=>'I1']]],
    "$base/invoice?requestid"=>['body'=>['Invoice'=>['Id'=>'Q1']]],
    "$base/invoice/"=>['body'=>['Invoice'=>['Id'=>'Q1','TotalAmt'=>107.2,'Balance'=>107.2,'CustomerRef'=>['value'=>'C1'],'LinkedTxn'=>[]]]],
])); };
$fakeQ([]);
$db->exec("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,authorized_hours) VALUES($lc,'QBO track','QBO-AUTH-9','Synthetic VR Agency',20)"); $qt=(int)$db->lastInsertId();
$db->exec("INSERT INTO invoices(track_id,invoice_number,status,hours,hours_amount,mileage,mileage_amount,total_amount,submitted_at,due_date,bill_mode,auth_number,funding_agency,hourly_rate,mileage_rate) VALUES($qt,'SYN-QBO-1','Submitted',2,100,11,7.20,107.20,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 30 DAY),'vr','QBO-AUTH-9','Synthetic VR Agency',50,0.655)"); $qi=(int)$db->lastInsertId();
$db->exec("INSERT INTO invoices(track_id,invoice_number,status,hours,hours_amount,total_amount,submitted_at,bill_mode,funding_agency) VALUES($qt,'SYN-QBO-OLD','Paid',1,50,50,DATE_SUB(CURDATE(),INTERVAL 90 DAY),'vr','Synthetic VR Agency')"); $qold=(int)$db->lastInsertId();
$inv=$db->query("SELECT i.*, t.client_id, c.name AS client_name FROM invoices i JOIN training_tracks t ON t.id=i.track_id JOIN clients c ON c.id=t.client_id WHERE i.id=$qi")->fetch();
$body=qbo_invoice_body($inv,'C1',['hours'=>'I1','mileage'=>'I1','expenses'=>'I1']);
check(qbo_bill_name($inv)==='Synthetic VR Agency' && !str_contains(json_encode($body),'Loan Synthetic Client') && str_contains(json_encode($body),'QBO-AUTH-9') && count($body['Line'])===2 && $body['DocNumber']==='SYN-QBO-1','A VR invoice bills the agency, with the authorization number and no client name');
check(($body['Line'][0]['SalesItemLineDetail']['Qty'] ?? null)===2.0 && !isset($body['Line'][1]['SalesItemLineDetail']['Qty']) && str_contains($body['Line'][1]['Description'],'11 at $0.655'),'Quantity and rate are sent only when they make the amount exactly (11 miles at $0.655 is $7.21, but $7.20 was billed)');
check(qbo_bill_name(['bill_mode'=>'private','client_name'=>'Pat Private','client_id'=>7])==='Private pay client 7','A private-pay client is not named unless the organisation chose that');
$GLOBALS['CONFIG']['qbo']['private_names']='yes';
check(qbo_bill_name(['bill_mode'=>'private','client_name'=>'Pat Private','client_id'=>7])==='Pat Private' && qbo_clean_name('State VR: Region 2')==='State VR - Region 2','...and can be; colons (sub-customer marks) are replaced');
unset($GLOBALS['CONFIG']['qbo']['private_names']);
[$ok,$bad]=qbo_sync_all();
$qrow=$db->query("SELECT qbo_id, qbo_error FROM invoices WHERE id=$qi")->fetch();
check($qrow['qbo_id']==='Q1' && $qrow['qbo_error']===null && setting('qbo_item_at_services')==='I1' && $db->query("SELECT qbo_id FROM qbo_customers")->fetchColumn()==='C1','A submitted invoice is sent, with its customer and service item made once');
check($db->query("SELECT qbo_id FROM invoices WHERE id=$qold")->fetchColumn()===null,'An invoice from before QuickBooks was connected is never sent');
// Payments both ways: one made in QuickBooks comes here once, with its own date; one recorded here goes there once.
$fakeQ(["$base/invoice/Q1"=>['body'=>['Invoice'=>['Id'=>'Q1','TotalAmt'=>107.2,'Balance'=>7.2,'SyncToken'=>'3','CustomerRef'=>['value'=>'C1'],'LinkedTxn'=>[['TxnId'=>'P9','TxnType'=>'Payment']]]]],
    "$base/payment/P9"=>['body'=>['Payment'=>['Id'=>'P9','TxnDate'=>date('Y-m-d',strtotime('-2 days')),'Line'=>[['Amount'=>100,'LinkedTxn'=>[['TxnId'=>'Q1','TxnType'=>'Invoice']]]]]]],
    "$base/payment?requestid"=>['body'=>['Payment'=>['Id'=>'P10']]]]);
qbo_sync_all();
$p9=$db->query("SELECT * FROM invoice_payments WHERE invoice_id=$qi AND reference='QuickBooks payment P9'")->fetch();
check($p9 && abs((float)$p9['amount']-100)<0.01 && $p9['received_on']===date('Y-m-d',strtotime('-2 days')) && $p9['qbo_id']==='P9','A payment made in QuickBooks is recorded here once, on its own date');
qbo_sync_all();
check((int)$db->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id=$qi")->fetchColumn()===1,'...and not again on the next run');
invoice_apply($qi,'payment',7.20,date('Y-m-d'),'CHK-55','Synthetic cheque',1);
qbo_sync_all();
check($db->query("SELECT qbo_id FROM invoice_payments WHERE invoice_id=$qi AND reference='CHK-55'")->fetchColumn()==='P10','A payment recorded here is added in QuickBooks once');
// Voided here after it was sent: voided there.
$db->exec("UPDATE invoices SET status='Void', qbo_status=NULL WHERE id=$qi");
$fakeQ(["$base/invoice/Q1"=>['body'=>['Invoice'=>['Id'=>'Q1','SyncToken'=>'4']]],"$base/invoice?operation=void"=>['body'=>['Invoice'=>['Id'=>'Q1']]]]);
qbo_sync_all();
check($db->query("SELECT qbo_status FROM invoices WHERE id=$qi")->fetchColumn()==='voided','An invoice voided here is voided in QuickBooks too');
// Sent before with the reply lost: the existing invoice is used, not made twice.
$db->exec("INSERT INTO invoices(track_id,invoice_number,status,hours,hours_amount,total_amount,submitted_at,bill_mode,funding_agency) VALUES($qt,'SYN-QBO-3','Submitted',1,50,50,CURDATE(),'vr','Synthetic VR Agency')"); $qi3=(int)$db->lastInsertId();
$fakeQ([$q('select Id from Invoice')=>['body'=>['QueryResponse'=>['Invoice'=>[['Id'=>'Q77']]]]],"$base/invoice?requestid"=>['status'=>500,'body'=>[]]]);
qbo_sync_all();
check($db->query("SELECT qbo_id FROM invoices WHERE id=$qi3")->fetchColumn()==='Q77','An invoice already in QuickBooks under its number is linked, not sent again');
// A refusal is kept on the invoice and listed.
putenv('CMS_TEST_QBO='.json_encode([$q('select Id, Active from Customer')=>['status'=>500,'body'=>[]]]));
$db->exec("DELETE FROM qbo_customers");
$db->exec("INSERT INTO invoices(track_id,invoice_number,status,hours,hours_amount,total_amount,submitted_at,bill_mode,funding_agency) VALUES($qt,'SYN-QBO-2','Submitted',1,50,50,CURDATE(),'vr','Another Synthetic Agency')"); $qi2=(int)$db->lastInsertId();
[,$bad2]=qbo_sync_all();
check($bad2>=1 && str_contains((string)$db->query("SELECT qbo_error FROM invoices WHERE id=$qi2")->fetchColumn(),'could not be searched'),'An invoice QuickBooks cannot take keeps the reason');
[$code,$html,$err]=$renderPage('qbo',[]);
check(str_contains($html,'Connected') && str_contains($html,'SYN-QBO-2') && str_contains($html,'Send invoices submitted on or after'),'The QuickBooks page shows the connection, the start date and the invoices that could not be sent'.($err?": $err":''));
// Another company starts afresh.
qbo_store(['access_token'=>'a','refresh_token'=>'b','expires_in'=>3600],'7654321',null,1);
check((int)$db->query("SELECT COUNT(*) FROM qbo_customers")->fetchColumn()===0 && $db->query("SELECT qbo_id FROM invoices WHERE id=$qi3")->fetchColumn()===null,'Connecting another company forgets the first one\'s customers and invoice links');
putenv('CMS_TEST_QBO');
$db->exec("DELETE FROM qbo_connection"); $db->exec("DELETE FROM settings WHERE name LIKE 'cfg.qbo.%'"); unset($GLOBALS['CONFIG']['qbo']);
