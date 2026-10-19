<?php
// Included by full_schema.php after v426_cases.php, against its synthetic database only:
// 4.27.0 (billing area): QuickBooks payments seen by their id, payments in QuickBooks removed
// only there, a zero balance there not settled here, the stored secrets left out of staging
// copies and backups, "mail off" for attachments, and the outcome of an email.

// ---- QuickBooks: a payment recorded here and sent there is not brought back as a second one ----
$db->exec("INSERT INTO settings(name,value) VALUES ('cfg.qbo.client_id','synthetic-qbo-id'),('cfg.qbo.client_secret','synthetic-qbo-secret'),('cfg.qbo.sandbox','yes') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$GLOBALS['CONFIG']['qbo']=['client_id'=>'synthetic-qbo-id','client_secret'=>'synthetic-qbo-secret','sandbox'=>'yes'];
qbo_store(['access_token'=>'synthetic-qbo-access-427','refresh_token'=>'synthetic-qbo-refresh-427','expires_in'=>3600],'2345678',null,1);
set_setting('qbo_since',date('Y-m-d',strtotime('+1 day'))); // no other test's invoice is sent: only the one linked below is checked
$b427='https://sandbox-quickbooks.api.intuit.com/v3/company/2345678';
$fq427=function(array $invoice, array $extra=[]) use ($b427){ putenv('CMS_TEST_QBO='.json_encode($extra+[
    "$b427/invoice/Q427"=>['body'=>['Invoice'=>$invoice+['Id'=>'Q427','TotalAmt'=>1000,'CustomerRef'=>['value'=>'C427']]]],
    "$b427/payment?requestid"=>['body'=>['Payment'=>['Id'=>'P427']]],
    "$b427/payment/P427"=>['body'=>['Payment'=>['Id'=>'P427','TxnDate'=>date('Y-m-d'),'Line'=>[['Amount'=>400,'LinkedTxn'=>[['TxnId'=>'Q427','TxnType'=>'Invoice']]]]]]],
])); };
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('QBO 427 Synthetic Client','Active Training',1)"); $qc427=(int)$db->lastInsertId();
$db->exec("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,authorized_hours) VALUES($qc427,'QBO 427 track','QBO-AUTH-427','Synthetic VR Agency',40)"); $qt427=(int)$db->lastInsertId();
$db->exec("INSERT INTO invoices(track_id,invoice_number,status,hours,hours_amount,total_amount,submitted_at,due_date,bill_mode,auth_number,funding_agency,hourly_rate,qbo_id)
    VALUES($qt427,'SYN-QBO-427','Submitted',20,1000,1000,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 30 DAY),'vr','QBO-AUTH-427','Synthetic VR Agency',50,'Q427')"); $qi427=(int)$db->lastInsertId();
check(invoice_apply($qi427,'payment',400,date('Y-m-d'),'CHK-427','Synthetic cheque',1)===null,'A $400 cheque is recorded on a $1,000 invoice');
$fq427(['Balance'=>1000,'LinkedTxn'=>[]]);
qbo_sync_all();
$pay427=$db->query("SELECT * FROM invoice_payments WHERE invoice_id=$qi427")->fetch();
check($pay427 && $pay427['qbo_id']==='P427' && $pay427['reference']==='CHK-427','The cheque is added in QuickBooks and keeps its own reference here');
// The next day QuickBooks lists it on the invoice: it is the same payment, not a new one.
$fq427(['Balance'=>600,'LinkedTxn'=>[['TxnId'=>'P427','TxnType'=>'Payment']]]);
qbo_sync_all(); qbo_sync_all();
$tot427=$db->query("SELECT paid_amount, status, qbo_error FROM invoices WHERE id=$qi427")->fetch();
check((int)$db->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id=$qi427")->fetchColumn()===1 && abs((float)$tot427['paid_amount']-400)<0.01 && $tot427['status']==='Submitted' && $tot427['qbo_error']===null,
    'A payment recorded here and sent to QuickBooks is not brought back as a second payment ($400 paid, not $800)');
// It cannot be removed here: it is voided in QuickBooks, and then removed at the next sync.
$why427=invoice_payment_remove((int)$pay427['id'],'Entered by mistake',1);
check(is_string($why427) && str_contains($why427,'void it there') && (int)$db->query("SELECT COUNT(*) FROM invoice_payments WHERE id=".(int)$pay427['id'])->fetchColumn()===1,'A payment that is in QuickBooks cannot be removed here');
$fq427(['Balance'=>1000,'LinkedTxn'=>[]],["$b427/payment/P427"=>['body'=>['Payment'=>['Id'=>'P427','TotalAmt'=>0,'Line'=>[],'PrivateNote'=>'Voided']]]]);
qbo_sync_all();
$tot427=$db->query("SELECT paid_amount, status FROM invoices WHERE id=$qi427")->fetch();
check((int)$db->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id=$qi427")->fetchColumn()===0 && (float)$tot427['paid_amount']===0.0 && $tot427['status']==='Submitted',
    'A payment voided in QuickBooks is removed here at the next sync, and the balance recalculated');
// A payment still applied there is never removed, even when the invoice's list leaves it out.
invoice_apply($qi427,'payment',400,date('Y-m-d'),'CHK-427B','Synthetic cheque',1);
$db->exec("UPDATE invoice_payments SET qbo_id='P427' WHERE invoice_id=$qi427");
$fq427(['Balance'=>600,'LinkedTxn'=>[]]);
qbo_sync_all();
check((int)$db->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id=$qi427")->fetchColumn()===1,'A payment QuickBooks still applies to the invoice is kept');
// Only "not found" there removes it here: another refusal records an error and removes nothing.
$fq427(['Balance'=>600,'LinkedTxn'=>[]],["$b427/payment/P427"=>['status'=>400,'body'=>['Fault'=>['Error'=>[['code'=>'2500','Message'=>'Synthetic other fault']]]]]]);
qbo_sync_all();
check((int)$db->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id=$qi427")->fetchColumn()===1 && str_contains((string)$db->query("SELECT qbo_error FROM invoices WHERE id=$qi427")->fetchColumn(),'could not be checked'),'A QuickBooks refusal other than "not found" removes no payment');
$fq427(['Balance'=>1000,'LinkedTxn'=>[]],["$b427/payment/P427"=>['status'=>400,'body'=>['Fault'=>['Error'=>[['code'=>'610','Message'=>'Object Not Found']]]]]]);
qbo_sync_all();
check((int)$db->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id=$qi427")->fetchColumn()===0,'A payment QuickBooks no longer has (fault 610) is removed here');
$db->exec("DELETE FROM invoice_payments WHERE invoice_id=$qi427"); invoice_retotal($qi427);
// Settled there by a credit memo, a discount or a void: staff are told, and it is not marked paid.
$fq427(['Balance'=>0,'LinkedTxn'=>[['TxnId'=>'CM1','TxnType'=>'CreditMemo']]]);
[,$bad427]=qbo_sync_all();
$st427=$db->query("SELECT qbo_status, qbo_error FROM invoices WHERE id=$qi427")->fetch();
check($bad427>=1 && $st427['qbo_status']===null && str_contains((string)$st427['qbo_error'],'Settled in QuickBooks by another kind of transaction'),'A zero balance in QuickBooks not covered by payments here is shown to staff, and the invoice is still checked');
invoice_apply($qi427,'payment',1000,date('Y-m-d'),'QuickBooks payment PX','Synthetic',1);
$db->exec("UPDATE invoice_payments SET qbo_id='PX' WHERE invoice_id=$qi427");
// 4.29.0: a linked payment is read back on every check (so a changed amount is followed): QuickBooks
// answers with the same $1,000 applied to this invoice.
$fq427(['Balance'=>0,'LinkedTxn'=>[['TxnId'=>'PX','TxnType'=>'Payment']]],
    ["$b427/payment/PX"=>['body'=>['Payment'=>['Id'=>'PX','TxnDate'=>date('Y-m-d'),'Line'=>[['Amount'=>1000,'LinkedTxn'=>[['TxnId'=>'Q427','TxnType'=>'Invoice']]]]]]]]);
qbo_sync_all();
check($db->query("SELECT qbo_status FROM invoices WHERE id=$qi427")->fetchColumn()==='paid','Paid in QuickBooks and covered here: marked paid');
$px427=(int)$db->query("SELECT id FROM invoice_payments WHERE invoice_id=$qi427")->fetchColumn();
$why427=invoice_payment_remove($px427,'Entered by mistake',1);
check(is_string($why427) && str_contains($why427,'void it there') && $db->query("SELECT qbo_status FROM invoices WHERE id=$qi427")->fetchColumn()===null,'On a settled invoice the removal is refused, and the invoice is checked again so the void there is seen');
// An invoice deleted in QuickBooks is no longer checked: its payment can be removed here.
$db->exec("UPDATE invoices SET qbo_status='missing' WHERE id=$qi427");
check(invoice_payment_remove($px427,'Entered by mistake',1)===null && (int)$db->query("SELECT COUNT(*) FROM invoice_payments WHERE id=$px427")->fetchColumn()===0,'A payment on an invoice deleted in QuickBooks can be removed here');
// Connecting another company forgets the first one's payment ids too.
invoice_apply($qi427,'payment',100,date('Y-m-d'),'CHK-427C','Synthetic',1);
$db->exec("UPDATE invoice_payments SET qbo_id='P999' WHERE invoice_id=$qi427");
qbo_forget_company();
check((int)$db->query("SELECT COUNT(*) FROM invoice_payments WHERE qbo_id IS NOT NULL")->fetchColumn()===0 && (int)$db->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id=$qi427")->fetchColumn()===1,'Another company is never asked about the first one\'s payments (their ids are forgotten, the payments kept)');
putenv('CMS_TEST_QBO');
$db->exec("DELETE FROM qbo_connection"); $db->exec("DELETE FROM settings WHERE name LIKE 'cfg.qbo.%'"); unset($GLOBALS['CONFIG']['qbo']);
// Without a QuickBooks connection, nothing would ever remove it: it is removed here.
$db->exec("UPDATE invoices SET qbo_id='Q427', qbo_status=NULL WHERE id=$qi427"); $db->exec("UPDATE invoice_payments SET qbo_id='P998' WHERE invoice_id=$qi427");
check(invoice_payment_remove((int)$db->query("SELECT id FROM invoice_payments WHERE invoice_id=$qi427")->fetchColumn(),'Entered by mistake',1)===null,'With QuickBooks disconnected, a payment that was sent there can be removed here');

// ---- Staging copies and backups leave every stored secret behind ----
require_once __DIR__.'/../dist/staging_scrub.php';
$secrets427=array_map(fn($k)=>'cfg.'.$k, array_keys(array_filter(CONFIG_OVERRIDES, fn($o)=>$o[1]==='secret')));
check(in_array('cfg.sms.auth_token',$secrets427,true) && in_array('cfg.qbo.client_secret',$secrets427,true),'The Twilio token and the QuickBooks secret are marked secret on Settings');
$matches427=function(string $where, string $name) use ($db): bool { $q=$db->prepare("SELECT COUNT(*) FROM (SELECT CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci AS name) s WHERE $where"); $q->execute([$name]); return (int)$q->fetchColumn()===1; };
$scrubWhere=staging_scrub_settings_where($db);
$missed=array_values(array_filter(array_merge($secrets427,['cfg.sms.account_sid','cfg.graph.tenant_id','cfg.graph.client_id','cfg.graph.sender','cfg.meet.zoom_client_id','cfg.meet.ms_client_id','cfg.qbo.client_id','ai_api_key','entra_client_secret','qbo_realm']),fn($n)=>!$matches427($scrubWhere,$n)));
check($missed===[],'A staging copy loses every stored secret and provider account'.($missed?': '.implode(', ',$missed).' kept':''));
check(!$matches427($scrubWhere,'cfg.app.timezone') && !$matches427($scrubWhere,'billing_terms_days') && !$matches427($scrubWhere,'cfg.brand.name'),'...and keeps the ordinary settings');
check(in_array('qbo_connection',STAGING_SCRUB_EMPTY,true) && in_array('meeting_accounts',STAGING_SCRUB_EMPTY,true),'A staging copy has no QuickBooks or meeting connection tokens');
$sh427=(string)file_get_contents(__DIR__.'/../cron/backup.sh');
preg_match('/^SECRET_SETTINGS="(.*)"$/m',$sh427,$m1); preg_match('/^SETTINGS_WHERE="(.*)"$/m',$sh427,$m2);
$backupWhere=str_replace('$SECRET_SETTINGS',$m1[1]??'',$m2[1]??'');
$inBackup=array_values(array_filter(array_merge($secrets427,['ai_api_key','entra_client_secret']),fn($n)=>$matches427($backupWhere,$n)));
check($backupWhere!=='' && $inBackup===[] && str_contains($m1[1]??'',"'cfg.sms.auth_token'"),'The nightly backup leaves out every stored secret, the Twilio token included'.($inBackup?': '.implode(', ',$inBackup).' kept':''));
check($matches427($backupWhere,'cfg.app.timezone') && $matches427($backupWhere,'billing_terms_days'),'...and keeps the ordinary settings');
$off427=(string)file_get_contents(__DIR__.'/../cron/weekly_offsite.php');
check(str_contains($off427,'the Twilio Auth Token') && str_contains($off427,'QuickBooks app Client secret') && str_contains($off427,'meeting app client secrets') && str_contains($off427,'Microsoft Graph client secret'),'The restore instructions list the Twilio token among the secrets to enter again');

// ---- Mail: "mail off" holds for attachments; the outcome of an email ----
$sink427=sys_get_temp_dir().'/cms_sink_427_'.bin2hex(random_bytes(4)); mkdir($sink427,0700);
$mailWas=[$GLOBALS['CONFIG']['mail_disabled']??null,$GLOBALS['CONFIG']['mail_sink']??null];
$GLOBALS['CONFIG']['mail_disabled']=true; $GLOBALS['CONFIG']['mail_sink']=$sink427;
$okAttach=send_app_mail_attach('staff-427@example.invalid','Synthetic invite','Body',[['name'=>'invite.ics','mime'=>'text/calendar','bytes'=>'BEGIN:VCALENDAR']]);
$kept427=glob($sink427.'/*.txt')?:[];
check($okAttach && count($kept427)===1 && str_contains((string)file_get_contents($kept427[0]),'invite.ics'),'With mail off (a staging copy), an invitation with an attachment is kept on the server, not sent');
foreach ($kept427 as $f) @unlink($f); @rmdir($sink427);
if ($mailWas[0]===null) unset($GLOBALS['CONFIG']['mail_disabled']); else $GLOBALS['CONFIG']['mail_disabled']=$mailWas[0];
if ($mailWas[1]===null) unset($GLOBALS['CONFIG']['mail_sink']); else $GLOBALS['CONFIG']['mail_sink']=$mailWas[1];
check(send_app_mail_outcome('not an address','Synthetic','Body')==='refused','An email that cannot have gone is reported refused (not unknown)');

// ---- Authorization emails: a counselor address from the text is not confirmed ----
check(!authorization_counselor_confirmed(['source'=>'Pasted','counselor_email'=>'pat@example.invalid','sender_email'=>null]),'A counselor address in a pasted email is not confirmed');
check(!authorization_counselor_confirmed(['source'=>'Mailbox','counselor_email'=>'pat@example.invalid','sender_email'=>'staff@example.invalid']),'...nor one in an email staff forwarded');
check(authorization_counselor_confirmed(['source'=>'Mailbox','counselor_email'=>'Pat@example.invalid','sender_email'=>'pat@example.invalid']),'...but the counselor who sent it to the mailbox themselves is');
check(!str_contains(CONFIG_OVERRIDES['app.timezone'][0],'shown in the new zone') && str_contains(CONFIG_OVERRIDES['app.timezone'][0],'keep their clock time'),'The time-zone setting says truthfully what happens to recorded times');

// ---- Trash: nothing of a client on hold is deleted for good ----
$db->exec("INSERT INTO clients(name,status,assigned_to,hold_at,hold_reason) VALUES('Hold 427 Synthetic Client','Active Training',1,NOW(),'Synthetic legal hold')"); $hc427=(int)$db->lastInsertId();
$db->prepare("INSERT INTO trash(entity,entity_id,label,payload,deleted_by) VALUES('client_notes',?,?,?,1)")->execute([990427,'Synthetic held note',json_encode(['id'=>990427,'client_id'=>$hc427,'body'=>'Synthetic'])]); $ht427=(int)$db->lastInsertId();
check(purge_trash($ht427)===null && (int)$db->query("SELECT COUNT(*) FROM trash WHERE id=$ht427")->fetchColumn()===1,'An item of a client on hold is not deleted for good');
$db->exec("UPDATE clients SET hold_at=NULL, hold_reason=NULL WHERE id=$hc427");
check(purge_trash($ht427)!==null && (int)$db->query("SELECT COUNT(*) FROM trash WHERE id=$ht427")->fetchColumn()===0,'...and is once the hold is lifted');
