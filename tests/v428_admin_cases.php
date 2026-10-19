<?php
// Included by full_schema.php after the v427 files, against its synthetic database only:
// 4.28.0 (admin area): Draft invoices re-addressed by their own bill mode (N7), QuickBooks payments
// sent before anything linked is imported (N13), a renamed agency still matched by every report,
// a Denied or Void reprint never "TOTAL DUE", the data quality label, one error announcement on
// VR portal and Remediation, and hints inside their labels.

require_once $root.'/public/inc/report_period.php';
require_once $root.'/public/inc/scheduled_reports.php';
require_once $root.'/public/inc/vr_portal.php';

// ---- N7: draft_invoices_readdress() rebuilds each Draft by its own bill mode ----
$db->exec("INSERT INTO funding_agencies(name,bill_to,is_active) VALUES('V428 Synthetic VR Agency','PO Box 428\nSynthetic City, IA 50000',1)");
agencies_all(true);
$db->exec("INSERT INTO clients(name,status,assigned_to,address,email,email_verified,counselor_name,counselor_agency,counselor_email,counselor_verified)
    VALUES('V428 Billed Client','Active Training',1,'1 Synthetic Road','v428.client@example.invalid',1,'Old Counselor','V428 Synthetic VR Agency','old.counselor@example.invalid',1)"); $c428=(int)$db->lastInsertId();
$db->exec("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,billing_mode,authorized_hours) VALUES($c428,'V428 VR track','V428-AUTH','V428 Synthetic VR Agency','vr',40)"); $tv428=(int)$db->lastInsertId();
$db->exec("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,billing_mode,authorized_hours) VALUES($c428,'V428 private track','','','private',40)"); $tp428=(int)$db->lastInsertId();
$ins428=function(int $track,string $num,string $status,string $mode,string $agency,string $billTo)use($db):int{
    $db->prepare("INSERT INTO invoices(track_id,invoice_number,status,hours,hours_amount,total_amount,bill_mode,funding_agency,bill_to) VALUES(?,?,?,1,50,50,?,?,?)")
        ->execute([$track,$num,$status,$mode,$agency,$billTo]);
    return (int)$db->lastInsertId();
};
$vrDraft428=$ins428($tv428,'SYN-428-VR','Draft','vr','V428 Synthetic VR Agency','stale');
$ppDraft428=$ins428($tp428,'SYN-428-PP','Draft','private','Private pay',"V428 Billed Client\n1 Synthetic Road\nv428.client@example.invalid");
$vrSent428=$ins428($tv428,'SYN-428-SENT','Submitted','vr','V428 Synthetic VR Agency','As sent');
// The counselor changes; the new email is not yet confirmed.
$db->exec("UPDATE clients SET counselor_name='New Counselor', counselor_email='new.counselor@example.invalid', counselor_verified=0 WHERE id=$c428");
$n428=draft_invoices_readdress($c428);
$bt428=fn(int $id):string=>(string)$db->query("SELECT bill_to FROM invoices WHERE id=$id")->fetchColumn();
check($n428===1,'Only the VR Draft is re-addressed when the counselor changes (one changed)');
check($bt428($vrDraft428)==="PO Box 428\nSynthetic City, IA 50000\nNew Counselor\nV428 Synthetic VR Agency",
    'A VR Draft keeps the agency\'s billing address, then the new counselor, with an unconfirmed email left off');
check(str_starts_with($bt428($ppDraft428),'V428 Billed Client') && !str_contains($bt428($ppDraft428),'Counselor'),'A private-pay Draft stays billed to the client, not the VR counselor');
check($bt428($vrSent428)==='As sent','A sent invoice keeps the Bill to it was sent with');
$db->exec("UPDATE clients SET counselor_verified=1 WHERE id=$c428");
check(draft_invoices_readdress($c428)===1 && str_ends_with($bt428($vrDraft428),"\nnew.counselor@example.invalid"),'Once confirmed, the counselor\'s email is added to the VR Draft');
check(draft_invoices_readdress($c428)===0,'Nothing changes when the Bill to is already right');
// A private-pay client who moves: the private-pay Draft follows the client's address.
$db->exec("UPDATE clients SET address='2 Synthetic Avenue' WHERE id=$c428");
check(draft_invoices_readdress($c428)===1 && str_contains($bt428($ppDraft428),'2 Synthetic Avenue'),'A private-pay Draft follows the client\'s new address');

// An older VR Draft with no agency of its own uses its track's, so it keeps the agency's billing address.
$db->exec("INSERT INTO invoices(track_id,invoice_number,status,hours,hours_amount,total_amount,bill_mode,funding_agency,bill_to) VALUES($tv428,'SYN-428-NOAG','Draft',1,50,50,'vr',NULL,'stale')"); $noAg428=(int)$db->lastInsertId();
draft_invoices_readdress($c428);
check(str_starts_with($bt428($noAg428),"PO Box 428\nSynthetic City, IA 50000\n"),'A VR Draft with no agency of its own keeps its track agency\'s billing address');
// Editing the client's address on the client page re-addresses the private-pay Draft (no counselor change).
[$code,,$err]=$renderPage('client_edit',['id'=>$c428],['name'=>'V428 Billed Client','status'=>'Active Training','client_type'=>'VR','address'=>'3 Synthetic Lane',
    'email'=>'v428.client@example.invalid','counselor_name'=>'New Counselor','counselor_email'=>'new.counselor@example.invalid','counselor_agency'=>'V428 Synthetic VR Agency']);
check(str_contains($bt428($ppDraft428),'3 Synthetic Lane') && !str_contains($bt428($ppDraft428),'Counselor'),'Changing the client\'s address on the client page re-addresses the private-pay Draft'.($err?": $err":''));

// ---- Renamed agency: every report and filter still finds the invoices sent under the old name ----
$db->exec("INSERT INTO funding_agencies(name,is_active) VALUES('V428 IA VR',1)"); $ag428=(int)$db->lastInsertId();
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('V428 Rename Client','Active Training',1)"); $rc428=(int)$db->lastInsertId();
$db->exec("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,authorized_hours,hourly_rate) VALUES($rc428,'V428 rename track','V428-REN','V428 IA VR',40,50)"); $rt428=(int)$db->lastInsertId();
$db->exec("INSERT INTO invoices(track_id,invoice_number,status,hours,hours_amount,total_amount,submitted_at,bill_mode,auth_number,funding_agency,hourly_rate,portal_status)
    VALUES($rt428,'SYN-428-REN','Submitted',2,100,100,CURDATE(),'vr','V428-REN','V428 IA VR',50,NULL)"); $ri428=(int)$db->lastInsertId();
$db->exec("INSERT INTO work_logs(track_id,work_date,hours,description,is_billed,invoice_id,hourly_rate) VALUES($rt428,CURDATE(),2,'V428 billed work',1,$ri428,50)");
// Renamed on Funding agencies, as funding_agencies.php does it.
$db->exec("UPDATE funding_agencies SET name='V428 Iowa VR' WHERE id=$ag428");
$db->exec("UPDATE training_tracks SET funding_agency='V428 Iowa VR' WHERE funding_agency='V428 IA VR'");
agency_rename_record('V428 IA VR','V428 Iowa VR');
agencies_all(true);
$sp428=agency_spellings('V428 Iowa VR');
check(in_array('V428 IA VR',$sp428,true) && in_array('V428 Iowa VR',$sp428,true),'A renamed agency is matched under its old name too');
check(agency_resolve('v428  ia vr')==='V428 Iowa VR','An old name (any case or spacing) resolves to the agency\'s current name');
$fm428=funder_metrics('V428 Iowa VR',date('Y-m-d',strtotime('-1 day')),date('Y-m-d'));
check(abs((float)$fm428['totals']['hrs']-2)<0.01,'The funder report counts hours invoiced before the rename under the new name');
set_setting('vr_portal_since',date('Y-m-d',strtotime('-1 day')));
check(in_array($ri428,array_map(fn($r)=>(int)$r['id'],vr_portal_lists('V428 Iowa VR')['enter']),true),'The VR portal lists an invoice sent under the old name for the renamed agency');
[$code,$html,$err]=$renderPage('agency_report',['agency'=>'V428 Iowa VR','period'=>'custom','from'=>date('Y-m-d',strtotime('-1 day')),'to'=>date('Y-m-d')]);
check(str_contains($html,'V428 Rename Client'),'The agency billing summary for the new name includes work invoiced under the old name'.($err?": $err":''));
[$code,$html,$err]=$renderPage('invoices',['agency'=>'V428 Iowa VR']);
check(str_contains($html,'SYN-428-REN'),'The Invoices agency filter finds an invoice sent under the old name'.($err?": $err":''));
// A new agency later given the old name is that new agency, not the renamed one.
$db->exec("INSERT INTO funding_agencies(name,is_active) VALUES('V428 IA VR',1)"); agencies_all(true);
check(agency_resolve('V428 IA VR')==='V428 IA VR','An old name listed again for another agency is that agency');
$db->exec("DELETE FROM funding_agencies WHERE name='V428 IA VR'"); agencies_all(true);

// ---- A Denied or Void invoice is never reprinted as "TOTAL DUE" ----
$db->exec("UPDATE invoices SET status='Denied', closed_reason='Synthetic denial' WHERE id=$vrSent428");
[$code,$html,$err]=$renderPage('invoice_pdf',['invoice'=>$vrSent428,'format'=>'html']);
check(str_contains($html,'INVOICE TOTAL (DENIED, NOTHING DUE)') && !str_contains($html,'TOTAL DUE<'),'A Denied invoice\'s total is labelled with its status, not "TOTAL DUE"'.($err?": $err":''));

// ---- Data quality: a blank email is never "same email" ----
$db->exec("INSERT INTO clients(name,status,dob,email) VALUES('Alpha V428dq','Active Training','1980-04-28',''),('Beta V428dq','Active Training','1980-04-28','')");
$dq428=array_values(array_filter(array_column(data_quality_checks(null),3,0)['duplicates'],fn($r)=>str_contains((string)$r['name'],'V428dq')));
check($dq428 && str_starts_with((string)$dq428[0]['detail'],'same date of birth and last name'),'Two clients with no email matched on birth date and last name are not labelled "same email"');

// ---- N13: QuickBooks payments recorded here are sent before linked ones are imported ----
$db->exec("INSERT INTO settings(name,value) VALUES ('cfg.qbo.client_id','synthetic-qbo-id'),('cfg.qbo.client_secret','synthetic-qbo-secret'),('cfg.qbo.sandbox','yes') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$GLOBALS['CONFIG']['qbo']=['client_id'=>'synthetic-qbo-id','client_secret'=>'synthetic-qbo-secret','sandbox'=>'yes'];
qbo_store(['access_token'=>'synthetic-qbo-access-428','refresh_token'=>'synthetic-qbo-refresh-428','expires_in'=>3600],'2345678',null,1);
set_setting('qbo_since',date('Y-m-d',strtotime('+1 day')));
$b428='https://sandbox-quickbooks.api.intuit.com/v3/company/2345678';
$fq428=function(array $invoice, array $extra=[]) use ($b428){ putenv('CMS_TEST_QBO='.json_encode($extra+[
    "$b428/invoice/Q428"=>['body'=>['Invoice'=>$invoice+['Id'=>'Q428','TotalAmt'=>1000,'CustomerRef'=>['value'=>'C428']]]],
    "$b428/payment?requestid"=>['body'=>['Payment'=>['Id'=>'P428']]],
    "$b428/payment/P428"=>['body'=>['Payment'=>['Id'=>'P428','TxnDate'=>date('Y-m-d'),'Line'=>[['Amount'=>400,'LinkedTxn'=>[['TxnId'=>'Q428','TxnType'=>'Invoice']]]]]]],
])); };
$db->exec("INSERT INTO invoices(track_id,invoice_number,status,hours,hours_amount,total_amount,submitted_at,due_date,bill_mode,auth_number,funding_agency,hourly_rate,qbo_id)
    VALUES($tv428,'SYN-QBO-428','Submitted',20,1000,1000,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 30 DAY),'vr','V428-AUTH','V428 Synthetic VR Agency',50,'Q428')"); $qi428=(int)$db->lastInsertId();
check(invoice_apply($qi428,'payment',400,date('Y-m-d'),'CHK-428','Synthetic cheque',1)===null,'A $400 cheque is recorded on a $1,000 invoice');
// It was sent before and QuickBooks made it, but the reply was lost: QuickBooks already lists it.
$fq428(['Balance'=>600,'LinkedTxn'=>[['TxnId'=>'P428','TxnType'=>'Payment']]]);
qbo_sync_all();
$rows428=$db->query("SELECT qbo_id, reference FROM invoice_payments WHERE invoice_id=$qi428")->fetchAll();
$inv428=$db->query("SELECT paid_amount, status, qbo_error, qbo_status FROM invoices WHERE id=$qi428")->fetch();
check(count($rows428)===1 && $rows428[0]['qbo_id']==='P428' && $rows428[0]['reference']==='CHK-428' && abs((float)$inv428['paid_amount']-400)<0.01 && $inv428['qbo_error']===null && $inv428['qbo_status']===null,
    'A payment sent with its reply lost gets its QuickBooks id back and is not imported as a second payment ($400 paid, not $800)');
// A copy imported before 4.28.0 (the double count) is recognised as the same payment and removed.
$db->exec("UPDATE invoice_payments SET qbo_id=NULL WHERE invoice_id=$qi428");
$db->exec("INSERT INTO invoice_payments(invoice_id,kind,amount,received_on,reference,note,qbo_id) VALUES($qi428,'payment',400,CURDATE(),'QuickBooks payment P428','Recorded in QuickBooks','P428')");
invoice_retotal($qi428);
qbo_sync_all();
check((int)$db->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id=$qi428")->fetchColumn()===1
    && $db->query("SELECT reference FROM invoice_payments WHERE invoice_id=$qi428")->fetchColumn()==='CHK-428'
    && abs((float)$db->query("SELECT paid_amount FROM invoices WHERE id=$qi428")->fetchColumn()-400)<0.01,
    'A payment imported twice before 4.28.0 is joined back to the one recorded here, and the balance recalculated');
// A payment removed here before the sync reaches it is never sent.
$db->exec("DELETE FROM invoice_payments WHERE invoice_id=$qi428"); invoice_retotal($qi428);
check(invoice_apply($qi428,'payment',100,date('Y-m-d'),'CHK-428B','Synthetic',1)===null,'Another payment is recorded');
$pb428=(int)$db->query("SELECT id FROM invoice_payments WHERE invoice_id=$qi428")->fetchColumn();
check(invoice_payment_remove($pb428,'Entered by mistake',1)===null,'It is removed before the sync (not yet in QuickBooks)');
$fq428(['Balance'=>1000,'LinkedTxn'=>[]],["$b428/payment?requestid"=>['status'=>500,'body'=>null]]);
qbo_sync_all();
check((int)$db->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id=$qi428")->fetchColumn()===0
    && $db->query("SELECT qbo_error FROM invoices WHERE id=$qi428")->fetchColumn()===null,'A removed payment is not sent (no send was even attempted)');
// While a payment recorded here is still unsent, nothing linked there is imported.
check(invoice_apply($qi428,'payment',400,date('Y-m-d'),'CHK-428C','Synthetic',1)===null,'A third payment is recorded');
$fq428(['Balance'=>600,'LinkedTxn'=>[['TxnId'=>'P428','TxnType'=>'Payment']]],["$b428/payment?requestid"=>['status'=>500,'body'=>null]]);
qbo_sync_all();
check((int)$db->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id=$qi428")->fetchColumn()===1
    && str_contains((string)$db->query("SELECT qbo_error FROM invoices WHERE id=$qi428")->fetchColumn(),'could not be added'),
    'When the send fails, the payment QuickBooks lists is not imported beside the unsent one');
putenv('CMS_TEST_QBO');
$db->exec("DELETE FROM invoice_payments WHERE invoice_id=$qi428");
$db->exec("DELETE FROM qbo_connection"); $db->exec("DELETE FROM settings WHERE name LIKE 'cfg.qbo.%'"); unset($GLOBALS['CONFIG']['qbo']);

// ---- One announcement per error on VR portal and Remediation ----
$db->exec("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,billing_mode,authorized_hours) VALUES($c428,'V428 portal track','V428-PORTAL','V428 Synthetic VR Agency','vr',10)"); $pt428=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('vr_portal',[],['track_id'=>(string)$pt428,'track_state'=>'returned','note'=>'']);
$vrSrc=(string)file_get_contents($root.'/public/vr_portal.php');
check(!str_contains($vrSrc,"flash('Not saved") && str_contains($vrSrc,'form_error_summary()'),'VR portal shows a refused save once: on the field and in the summary, with no flash too');
$remSrc=(string)file_get_contents($root.'/public/remediate.php');
check(!str_contains($remSrc,"flash('Paste some content first.'") && !str_contains($remSrc,"flash('That is too much text") && str_contains($remSrc,'form_error_summary('),'Remediation shows a refused transform once, on the field and in the summary');

// ---- Hints sit inside their labels (no aria-describedby hint ids) ----
foreach (['admin','ai','audit','templates','curriculum','curriculum_ai','email_templates','invoices','remediate','inc/report_period'] as $f428) {
    $src428=(string)file_get_contents($root.'/public/'.$f428.'.php');
    check(!preg_match('/-hint[\'"]/',$src428) && !preg_match('/<span class="hint" id=/',$src428),"Hints in $f428.php are inside their labels");
}
$rp428=report_period_form('agency_report.php',['preset'=>'custom','from'=>date('Y-m-d'),'to'=>date('Y-m-d')]);
check(str_contains($rp428,'<label for="period">Period <span class="hint">') && !str_contains($rp428,'aria-describedby'),'The report period hint is read with its label');
