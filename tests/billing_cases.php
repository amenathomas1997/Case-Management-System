<?php
// Included by full_schema.php after consent_race_cases.php, against its synthetic
// database only: getting paid (3.0.34). Billing details on invoices, payments with real
// dates, write-offs, denied and void invoices, authorization limits that refuse work,
// and private-pay and no-charge tracks.
$db->exec("INSERT INTO clients(name,status,address,email,email_verified) VALUES('Billing synthetic client','Active Training','1 Synthetic St','billing-client@example.invalid',1)"); $bc=(int)$db->lastInsertId();
$newTrack=function(string $title,string $mode,float $hours,float $rate,?float $amount=null)use($db,$bc):int{
    $db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,authorized_hours,authorized_amount,hourly_rate,start_date,expiration_date,billing_mode) VALUES(?,?,?,?,?,?,?,?,?,?)")
        ->execute([$bc,$title,$mode==='vr'?'BILL-34':'',$mode==='vr'?'Billing agency':($mode==='private'?'Private pay':'No charge'),$hours,$amount,$rate,date('Y-m-d',strtotime('-60 days')),date('Y-m-d',strtotime('+60 days')),$mode]);
    return (int)$db->lastInsertId();
};
$logs=fn(int $t):int=>(int)$db->query("SELECT COUNT(*) FROM work_logs WHERE track_id=$t")->fetchColumn();
$web=fn(int $t,string $h,string $date)=>$renderPage('track',['id'=>$t],['add_log'=>'1','hours'=>$h,'mileage'=>'','work_date'=>$date,'description'=>'Billing synthetic session']);
$d1=date('Y-m-d',strtotime('-20 days')); $d2=date('Y-m-d',strtotime('-19 days'));

/* Billing details on the invoice */
[$code,,$err]=$renderPage('admin',[],['save_billing'=>'1','billing_vendor_number'=>'VEND-77','billing_tax_id'=>'12-3456789','billing_remit_to'=>"Synthetic Org\n2 Remit Ave",
    'billing_phone'=>'555-0177','billing_email'=>'billing@example.invalid','billing_terms_days'=>'45','billing_private_rate'=>'20','billing_private_threshold'=>'2500']);
check($code===0 && $err==='' && setting('billing_vendor_number')==='VEND-77' && billing_settings()['terms_days']===45 && abs(billing_settings()['private_rate']-20.0)<0.001,
    'Administration saves the billing details' . ($err ? ": $err" : ''));

/* Authorized hours and dollars refuse work over them */
$hoursTrack=$newTrack('Billing hours-limit track','vr',2,50);
[$code,,$err]=$web($hoursTrack,'2',$d1);
[[$code2,,$err2],$sess]=$pageSession(fn()=>$web($hoursTrack,'0.5',$d2));
check($code===0 && $err==='' && $logs($hoursTrack)===1 && $code2===0 && $err2==='' && str_contains($sess,'0.5 hours over'),
    'Hours over the authorized hours are refused on the website, saying how far over' . ($err.$err2 ? ": $err$err2" : ''));
$refused=$call('log_hours',['track_id'=>$hoursTrack,'hours'=>0.5,'date'=>$d2,'description'=>'App over the limit','authorization_ack'=>true],[],false,'authorized');
check($logs($hoursTrack)===1 && str_contains($refused['error'],'Record the amendment'),'The app is refused too, and an acknowledgement does not override it');
$dollarTrack=$newTrack('Billing dollar-limit track','vr',10,50,100.00);
[$code,,$err]=$web($dollarTrack,'2',$d1);
[[$code2,,],$sess]=$pageSession(fn()=>$web($dollarTrack,'0.25',$d2));
check($code===0 && $err==='' && $logs($dollarTrack)===1 && str_contains($sess,'$12.50 over'),'Work over the authorized dollar amount is refused, saying how far over');
$expRefused=$call('add_expense',['track_id'=>$dollarTrack,'amount'=>5,'date'=>$d2,'description'=>'Synthetic expense'],[],false,'authorized');
check((int)$db->query("SELECT COUNT(*) FROM track_expenses WHERE track_id=$dollarTrack")->fetchColumn()===0,'An expense over the authorized amount is refused');
check(track_rollup($dollarTrack)['funds_remaining']===0.0 && abs(track_rollup($dollarTrack)['authorized_funds']-100.0)<0.001,'The dollar limit is the authorized amount when one is entered');

/* Submit with a date, pay in part, write off the rest */
$invId=generate_invoice($hoursTrack,1);
$db->exec("UPDATE invoices SET created_at=DATE_SUB(NOW(), INTERVAL 18 DAY) WHERE id=$invId"); // generated earlier, sent later
$submitOn=date('Y-m-d',strtotime('-15 days'));
[$code,,$err]=$renderPage('invoices',[],['set_status'=>'1','id'=>(string)$invId,'to'=>'Submitted','submitted_on'=>date('Y-m-d',strtotime('-30 days'))]);
check($code===0 && $err==='' && $db->query("SELECT status FROM invoices WHERE id=$invId")->fetchColumn()==='Draft','A submission date before the invoice was created is refused' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('invoices',[],['set_status'=>'1','id'=>(string)$invId,'to'=>'Submitted','submitted_on'=>$submitOn]);
$inv=$db->query("SELECT * FROM invoices WHERE id=$invId")->fetch();
check($code===0 && $err==='' && $inv['status']==='Submitted' && $inv['submitted_at']===$submitOn && $inv['due_date']===date('Y-m-d',strtotime($submitOn.' +45 days')),
    'Marking an invoice submitted keeps the date it was sent, and the due date follows the terms' . ($err ? ": $err" : ''));
$paidOn=date('Y-m-d',strtotime('-5 days'));
[$code,,$err]=$renderPage('invoices',[],['apply_payment'=>'1','id'=>(string)$invId,'kind'=>'payment','amount'=>'80.00','received_on'=>$paidOn,'reference'=>'EFT-1']);
$inv=$db->query("SELECT * FROM invoices WHERE id=$invId")->fetch();
check($code===0 && $err==='' && $inv['status']==='Submitted' && (float)$inv['paid_amount']===80.0 && $inv['paid_at']===$paidOn && abs(invoice_balance($inv)-20.0)<0.001,
    'A part payment is recorded with the date it was received, and the invoice stays open for the rest' . ($err ? ": $err" : ''));
check(invoice_apply($invId,'payment',50.0,date('Y-m-d'),null,null,1)!==null && (float)$db->query("SELECT paid_amount FROM invoices WHERE id=$invId")->fetchColumn()===80.0,
    'A payment larger than the balance is refused');
check(invoice_apply($invId,'writeoff',20.0,date('Y-m-d'),null,'',1)!==null,'A write-off needs a reason');
[$code,,$err]=$renderPage('invoices',[],['apply_payment'=>'1','id'=>(string)$invId,'kind'=>'writeoff','amount'=>'20.00','received_on'=>date('Y-m-d'),'note'=>'Agency paid the authorized rate only']);
$inv=$db->query("SELECT * FROM invoices WHERE id=$invId")->fetch();
check($code===0 && $err==='' && $inv['status']==='Paid' && (float)$inv['written_off']===20.0 && invoice_balance($inv)===0.0
    && (int)$db->query("SELECT COUNT(*) FROM invoices i WHERE i.id=$invId AND ".INVOICE_OPEN_SQL)->fetchColumn()===0
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='invoice.write_off' AND entity_id=$invId")->fetchColumn()===1,
    'Writing off the rest settles the invoice: Paid, nothing outstanding, and the write-off is audited' . ($err ? ": $err" : ''));
$payId=(int)$db->query("SELECT id FROM invoice_payments WHERE invoice_id=$invId AND kind='payment'")->fetchColumn();
check(invoice_payment_remove($payId,'Recorded against the wrong invoice',1)===null && $db->query("SELECT status FROM invoices WHERE id=$invId")->fetchColumn()==='Submitted'
    && abs(invoice_balance($db->query("SELECT * FROM invoices WHERE id=$invId")->fetch())-80.0)<0.001,'Removing a payment reopens the invoice for that amount');
[$code,$html,$err]=$renderPage('invoice_pdf',['invoice'=>$invId,'format'=>'html']);
check($code===0 && $err==='' && str_contains($html,'VEND-77') && str_contains($html,'2 Remit Ave') && str_contains($html,fmt_date(date('Y-m-d',strtotime($submitOn.' +45 days')))) && str_contains($html,'BALANCE DUE'),
    'The invoice shows the vendor number, the remit-to address, the due date and the balance' . ($err ? ": $err" : ''));

/* Denied: kept on record, its work released and billed again */
$denyTrack=$newTrack('Billing denied track','vr',10,50);
$web($denyTrack,'1',$d1);
$denyInv=generate_invoice($denyTrack,1); invoice_submit($denyInv,date('Y-m-d'));
[$code,,$err]=$renderPage('invoices',[],['close_invoice'=>'1','id'=>(string)$denyInv,'to'=>'Denied','reason'=>'Wrong authorization number']);
$released=(int)$db->query("SELECT COUNT(*) FROM work_logs WHERE track_id=$denyTrack AND is_billed=0 AND invoice_id IS NULL")->fetchColumn();
check($code===0 && $err==='' && $db->query("SELECT CONCAT(status,'|',closed_reason) FROM invoices WHERE id=$denyInv")->fetchColumn()==='Denied|Wrong authorization number' && $released===1,
    'A denied invoice stays on record with its reason, and its work is released' . ($err ? ": $err" : ''));
$rebill=generate_invoice($denyTrack,1);
check($rebill!==null && $rebill!==$denyInv && abs(track_rollup($denyTrack)['invoiced_amount']-50.0)<0.001,'The released work is invoiced again, and the denied invoice no longer counts as invoiced');
invoice_submit($rebill,date('Y-m-d')); invoice_apply($rebill,'payment',10.0,date('Y-m-d'),null,null,1);
check(invoice_close($rebill,'Void','Sent twice',1)!==null && $db->query("SELECT status FROM invoices WHERE id=$rebill")->fetchColumn()==='Submitted','An invoice with a payment recorded cannot be closed');

/* Private pay and no charge */
$privTrack=$newTrack('Billing private track','private',0,20);
[$code,,$err]=$web($privTrack,'1.5',$d1);
$privInv=generate_invoice($privTrack,1);
$pi=$db->query("SELECT * FROM invoices WHERE id=$privInv")->fetch();
check($code===0 && $err==='' && $pi['bill_mode']==='private' && $pi['funding_agency']==='Private pay' && str_contains((string)$pi['bill_to'],'Billing synthetic client') && str_contains((string)$pi['bill_to'],'1 Synthetic St') && (float)$pi['total_amount']===30.0,
    'A private-pay track is invoiced to the client at its fee, with no authorization needed' . ($err ? ": $err" : ''));
$freeTrack=$newTrack('Billing no-charge track','none',0,0);
[$code,,$err]=$web($freeTrack,'2',$d1);
check($code===0 && $err==='' && $logs($freeTrack)===1 && (float)$db->query("SELECT hourly_rate FROM work_logs WHERE track_id=$freeTrack")->fetchColumn()===0.0 && generate_invoice($freeTrack,1)===null,
    'A no-charge track logs hours at $0 and is never invoiced' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('track_edit',['client_id'=>$bc],['title'=>'Billing private via form','billing_mode'=>'private','auth_number'=>'','funding_agency'=>'','authorized_hours'=>'0','hourly_rate'=>'20','status'=>'Active','service_type'=>'Assistive Technology']);
check($code===0 && $err==='' && $db->query("SELECT CONCAT(billing_mode,'|',funding_agency) FROM training_tracks WHERE title='Billing private via form'")->fetchColumn()==='private|Private pay',
    'A private-pay track can be created without an authorization number' . ($err ? ": $err" : ''));

/* The referral form's fee rule follows the billing settings */
[$code,$html,$err]=$renderPage('community_referral',[]);
check($code===0 && $err==='' && str_contains($html,'$20.00 per hour') && str_contains($html,'$2,500 per month'),'The public referral form quotes the fee and income threshold from the billing settings' . ($err ? ": $err" : ''));
