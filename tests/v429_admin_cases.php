<?php
// Included by full_schema.php after the v428 files, against its synthetic database only:
// 4.29.0 (admin area): VR-only agency figures (P13), a renamed agency listed once, the guardian
// consent rule at 18 on Compliance, the scheduled-reports message, email templates using the one
// required-placeholder list, wording history capped by size, the period subtotal on progress
// reports, error summaries tied to their fields, pricing shown to the cent, and QuickBooks
// (a per-database sync lock, and a payment amount changed in QuickBooks updated here).

require_once $root.'/public/inc/report_period.php';
require_once $root.'/public/inc/scheduled_reports.php';
require_once $root.'/public/inc/report_builder.php';

// Pages here are administrator pages: run them as a legacy administrator, restored at the end.
$g429a=$db->query('SELECT group_id FROM users WHERE id=1')->fetchColumn();
$db->exec('UPDATE users SET group_id=NULL WHERE id=1');
$today429a=date('Y-m-d'); $from429a=date('Y-m-d',strtotime('-1 day'));
$period429a=['period'=>'custom','from'=>$from429a,'to'=>$today429a];

// ---- P13: private-pay and no-charge work is never counted as VR agency work ----
$db->exec("INSERT INTO funding_agencies(name,is_active) VALUES('V429-ADMIN VR',1)");
agencies_all(true);
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('V429-ADMIN VR Client','Active Training',1)"); $cv429a=(int)$db->lastInsertId();
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('V429-ADMIN Private Client','Active Training',1)"); $cp429a=(int)$db->lastInsertId();
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('V429-ADMIN NoCharge Client','Active Training',1)"); $cn429a=(int)$db->lastInsertId();
$track429a=function(int $client,string $title,string $mode)use($db):int{
    // The agency is left as prefilled ("V429-ADMIN VR") whatever "Who pays" says.
    $db->prepare("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,billing_mode,authorized_hours,hourly_rate,status) VALUES(?,?,?,'V429-ADMIN VR',?,40,50,'Active')")
        ->execute([$client,$title,'V429-ADMIN-'.$mode,$mode]);
    return (int)$db->lastInsertId();
};
$tv429a=$track429a($cv429a,'V429-ADMIN VR track','vr');
$tp429a=$track429a($cp429a,'V429-ADMIN private track','private');
$tn429a=$track429a($cn429a,'V429-ADMIN no-charge track','none');
$log429a=fn(int $t,float $h,?int $inv=null)=>$db->prepare('INSERT INTO work_logs(track_id,work_date,hours,description,is_billed,invoice_id,hourly_rate) VALUES(?,CURDATE(),?,?,?,?,50)')
    ->execute([$t,$h,'V429-ADMIN work',$inv?1:0,$inv]);
$log429a($tv429a,2.0); $log429a($tp429a,3.0); $log429a($tn429a,5.0);
// A VR track whose work was invoiced to the client (private bill mode) is not VR work; a track switched
// to private pay after its work was invoiced to VR still counts that invoiced work as VR.
$db->exec("INSERT INTO invoices(track_id,invoice_number,status,hours,hours_amount,total_amount,submitted_at,bill_mode,funding_agency)
    VALUES($tv429a,'SYN-429A-PP','Submitted',1,50,50,CURDATE(),'private','V429-ADMIN VR')"); $ipp429a=(int)$db->lastInsertId();
$log429a($tv429a,1.0,$ipp429a);
$db->exec("INSERT INTO invoices(track_id,invoice_number,status,hours,hours_amount,total_amount,submitted_at,bill_mode,funding_agency)
    VALUES($tp429a,'SYN-429A-VR','Submitted',4,200,200,CURDATE(),'vr','V429-ADMIN VR')"); $ivr429a=(int)$db->lastInsertId();
$log429a($tp429a,4.0,$ivr429a);

$m429a=funder_metrics('V429-ADMIN VR',$from429a,$today429a);
check(abs((float)$m429a['totals']['hrs']-6.0)<0.001,'Funder metrics count VR work only: 2 unbilled VR hours and 4 hours invoiced to VR (not the private, no-charge or privately invoiced hours)');
check((int)$m429a['servedTotal']===2,'Funder metrics: clients served are those with VR work (the VR client, and the private-pay client\'s VR-invoiced work)');
$fa429a=array_values(array_filter(funder_agencies(),fn($a)=>$a['funding_agency']==='V429-ADMIN VR'));
check(count($fa429a)===1 && (int)$fa429a[0]['n']===1,'The funder agency list counts only VR tracks for the agency (one, not three)');

[$code,$html,$err]=$renderPage('agency_report',['agency'=>'V429-ADMIN VR']+$period429a);
check($code===0 && $err==='' && str_contains($html,'V429-ADMIN VR Client') && !str_contains($html,'V429-ADMIN NoCharge Client') && str_contains($html,'6.00'),
    'The agency billing summary lists VR work only (6 hours), never the no-charge client'.($err?": $err":''));
$db->exec("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,billing_mode,authorized_hours,hourly_rate) VALUES($cp429a,'V429-ADMIN private elsewhere','V429-ADMIN-PO','V429-ADMIN PrivOnly Agency','private',10,50)");
[$code,$html,$err]=$renderPage('agency_report',$period429a);
check($code===0 && $err==='' && str_contains($html,'<option value="V429-ADMIN VR"') && !str_contains($html,'V429-ADMIN PrivOnly Agency'),
    'The agency billing summary picker lists only agencies with VR work, not one named only on a private-pay track'.($err?": $err":''));
[$code,$csv,$err]=$renderPage('rsa_report',['export'=>1]+$period429a);
check($err==='' && str_contains($csv,'V429-ADMIN VR Client') && !str_contains($csv,'V429-ADMIN NoCharge Client'),'The RSA-911 export leaves out a no-charge client whose track names the VR agency'.($err?": $err":''));
[$code,$html,$err]=$renderPage('agency_scorecard',['agency'=>'V429-ADMIN VR']+$period429a);
check($code===0 && $err==='' && str_contains($html,'6.00'),'The agency scorecard counts 6 VR hours, not the private and no-charge hours'.($err?": $err":''));
[$code,$html,$err]=$renderPage('analytics',[]);
check($code===0 && $err==='' && str_contains($html,'Private pay (not an agency)') && str_contains($html,'No charge (not an agency)'),
    'Analytics lists private-pay and no-charge work on rows of their own, not under the agency'.($err?": $err":''));
[$code,$html,$err]=$renderPage('utilization',$period429a);
check($code===0 && $err==='','The staff utilization page still runs (all staff hours count there)'.($err?": $err":''));

// ---- A renamed agency is listed once in the funder picker and email, and grouped once in analytics ----
$db->exec("INSERT INTO funding_agencies(name,is_active) VALUES('V429-ADMIN Old VR',1)"); $ag429a=(int)$db->lastInsertId();
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('V429-ADMIN Rename Client','Active Training',1)"); $cr429a=(int)$db->lastInsertId();
$db->exec("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,billing_mode,authorized_hours,hourly_rate) VALUES($cr429a,'V429-ADMIN rename track','V429-ADMIN-REN','V429-ADMIN Old VR','vr',40,50)"); $tr429a=(int)$db->lastInsertId();
$db->exec("INSERT INTO invoices(track_id,invoice_number,status,hours,hours_amount,total_amount,submitted_at,bill_mode,funding_agency)
    VALUES($tr429a,'SYN-429A-REN','Submitted',2,100,100,CURDATE(),'vr','V429-ADMIN Old VR')"); $ir429a=(int)$db->lastInsertId();
$log429a($tr429a,2.0,$ir429a); $log429a($tr429a,1.0);
// Renamed as funding_agencies.php does it: the agency, its tracks and the rename record; sent invoices keep the old name.
$db->exec("UPDATE funding_agencies SET name='V429-ADMIN New VR' WHERE id=$ag429a");
$db->exec("UPDATE training_tracks SET funding_agency='V429-ADMIN New VR' WHERE id=$tr429a");
agency_rename_record('V429-ADMIN Old VR','V429-ADMIN New VR'); agencies_all(true);
$names429a=array_column(funder_agencies(),'funding_agency');
check(in_array('V429-ADMIN New VR',$names429a,true) && !in_array('V429-ADMIN Old VR',$names429a,true),'After a rename, the funder agency list names the agency once, by its new name');
$heads429a=array_column(scheduled_report_sections('funder',$from429a,$today429a),'heading');
check(in_array('V429-ADMIN New VR',$heads429a,true) && !in_array('V429-ADMIN Old VR',$heads429a,true),'The monthly funder email has one section for the renamed agency, not one per name');
[$code,$html,$err]=$renderPage('funder_report',['agency'=>'V429-ADMIN Old VR']+$period429a);
check($code===0 && $err==='' && substr_count($html,'>V429-ADMIN New VR</option>')===1 && !str_contains($html,'>V429-ADMIN Old VR</option>') /* 4.30.0: plain option names */,'The funder report picker lists the renamed agency once'.($err?": $err":''));
[$code,$html,$err]=$renderPage('agency_report',$period429a);
check($code===0 && $err==='' && str_contains($html,'<option value="V429-ADMIN New VR"') && !str_contains($html,'<option value="V429-ADMIN Old VR"'),'The agency billing summary picker lists the renamed agency once'.($err?": $err":''));
[$code,$html,$err]=$renderPage('analytics',[]);
check($code===0 && $err==='' && str_contains($html,'>V429-ADMIN New VR</th>') && !str_contains($html,'>V429-ADMIN Old VR</th>'),'Analytics groups the renamed agency\'s old invoices under its new name'.($err?": $err":''));

// ---- Compliance: a guardian's consent signed before the client turned 18 no longer counts ----
$db->prepare("INSERT INTO clients(name,status,assigned_to,dob) VALUES('V429-ADMIN Turned 18','Active Training',1,?)")->execute([date('Y-m-d',strtotime('-18 years -1 month'))]); $c18429a=(int)$db->lastInsertId();
$db->prepare("INSERT INTO consent_forms(client_id,token,guardian_name,guardian_email,signed_at,signed_name,sent_at) VALUES(?,?,?,?,?,?,?)")
    ->execute([$c18429a,str_repeat('9',48),'V429-ADMIN Guardian','v429.guardian@example.invalid',date('Y-m-d H:i:s',strtotime('-5 months')),'V429-ADMIN Guardian',date('Y-m-d H:i:s',strtotime('-5 months'))]);
[$code,$html,$err]=$renderPage('compliance',[]);
$noRel429a=strpos($html,'No release or consent on file');
$noRelList429a=$noRel429a===false ? '' : substr($html,$noRel429a,(int)strpos($html,'</ul>',$noRel429a)-$noRel429a);
check($code===0 && $err==='' && str_contains($noRelList429a,'V429-ADMIN Turned 18'),
    'A client who turned 18 with only a guardian\'s earlier consent is listed under "No release or consent on file"'.($err?": $err":''));
check(str_contains($html,'Signed by a guardian before the client turned 18'),'Consents to renew says the guardian\'s consent was signed before the client turned 18');

// ---- Scheduled reports: only the business summary ticked ----
[$code,,$err]=$renderPage('admin',[],['save_scheduled_reports'=>'1','sr_business'=>'1','sr_recipients'=>'v429.admin@example.invalid','sr_day'=>'5','sr_period'=>array_key_first(SCHEDULED_REPORT_PERIODS)]);
$aud429a=(string)$db->query("SELECT detail FROM audit_log WHERE action='settings.scheduled_reports' ORDER BY id DESC LIMIT 1")->fetchColumn();
check($err==='' && str_contains($aud429a,'business=on') && setting('scheduled_reports_business_enabled')==='1','Saving only the business summary is kept and audited with the business setting'.($err?": $err":''));
$adminSrc429a=(string)file_get_contents($root.'/public/admin.php');
check(str_contains($adminSrc429a,"\$srBusiness ? 'the business summary' : ''"),'The confirmation names the business summary, so ticking only it is not "nothing will be emailed"');
set_setting('scheduled_reports_business_enabled','0');

// ---- Email templates: the Spanish and "Document to sign" placeholders are required too ----
$db->exec("DELETE FROM settings WHERE name IN ('tpl_consent_request_es_body','tpl_sign_request_body')");
[$code,,$err]=$renderPage('email_templates',['key'=>'consent_request_es'],['save_tpl'=>'1','subject'=>'','body'=>'V429-ADMIN cuerpo sin enlace']);
check($err==='' && setting('tpl_consent_request_es_body')===null,'A Spanish consent request body without {link} is refused, not saved and silently ignored'.($err?": $err":''));
[$code,,$err]=$renderPage('email_templates',['key'=>'sign_request'],['save_tpl'=>'1','subject'=>'','body'=>'V429-ADMIN no link']);
check($err==='' && setting('tpl_sign_request_body')===null,'A "Document to sign" body without {link} is refused');
[$code,,$err]=$renderPage('email_templates',['key'=>'consent_request_es'],['save_tpl'=>'1','subject'=>'','body'=>'V429-ADMIN cuerpo {link}']);
check($err==='' && setting('tpl_consent_request_es_body')==='V429-ADMIN cuerpo {link}','A Spanish body with {link} is saved');
$db->exec("DELETE FROM settings WHERE name='tpl_consent_request_es_body'");
[$code,$html,$err]=$renderPage('email_templates',['key'=>'consent_request_es']);
check($code===0 && $err==='' && str_contains($html,'required: must include {link}') && str_contains($html,'Back to Administration') && !str_contains($html,'Back to My Account'),
    'The body label says {link} is required, and the back link names Administration'.($err?": $err":''));
[$code,$html,$err]=$renderPage('ai',[]);
check($code===0 && $err==='' && str_contains($html,'Back to Administration') && !str_contains($html,'Back to My Account'),'The AI page\'s back link names Administration'.($err?": $err":''));

// ---- Wording: the history is capped by size, so a long text can always be changed again ----
$wk429a=array_key_first(PUBLIC_WORDING);
$big429a=[]; for($i=0;$i<50;$i++) $big429a[]=['until'=>date('c'),'by'=>1,'text'=>str_repeat('ñ',1250).' V429-ADMIN '.$i];
set_setting('wording_'.$wk429a.'_history',(string)json_encode(array_slice($big429a,0,24),JSON_UNESCAPED_UNICODE));
set_setting('wording_'.$wk429a,str_repeat('é',2000));
$post429a=['save_wording'=>'1']; foreach(PUBLIC_WORDING as $k=>$_) { $post429a[$k]=(string)(setting('wording_'.$k)??''); $post429a[$k.'_es']=(string)(setting('wording_'.$k.'_es')??''); }
$post429a[$wk429a]='V429-ADMIN new wording';
[$code,,$err]=$renderPage('wording',[],$post429a);
$hist429a=(string)setting('wording_'.$wk429a.'_history');
check($err==='' && setting('wording_'.$wk429a)==='V429-ADMIN new wording' && strlen($hist429a)<=60000 && str_contains($hist429a,'éé'),
    'A wording change with a long history is saved, the history kept under the column size with the replaced text first'.($err?": $err":''));
set_setting('wording_'.$wk429a,''); set_setting('wording_'.$wk429a.'_history','[]');

// ---- Progress report for a period: the row under the period's lines is their subtotal ----
$db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description,hourly_rate) VALUES(?,?,10,'V429-ADMIN old work',50)")->execute([$tv429a,date('Y-m-d',strtotime('-60 days'))]);
$rep429a=build_track_report($tv429a,'V429-ADMIN Preparer','',1,['since'=>date('Y-m-d',strtotime('-7 days'))]);
check($rep429a!==null && str_contains($rep429a['html'],'Total for the period') && str_contains($rep429a['html'],'Authorization to date')
    && str_contains($rep429a['text'],'TOTAL FOR THE PERIOD: 3.00 hours') && str_contains($rep429a['text'],'AUTHORIZATION TO DATE: 13.00 hours'),
    'A report for a period shows the period subtotal (3 hours) and labels the all-time 13 hours "Authorization to date"');
$all429a=build_track_report($tv429a,'V429-ADMIN Preparer');
check($all429a!==null && str_contains($all429a['text'],'TOTALS: 13.00 hours') && !str_contains($all429a['html'],'Authorization to date'),'A full report keeps its single Total row');

// ---- Settings: a group with one refused value saves none of it, and says so ----
set_setting('cfg.app.session_timeout_minutes','');
[$code,,$err]=$renderPage('settings',[],['save_group'=>'signin','app_session_timeout_minutes'=>'45','app_otp_expiry_minutes'=>'99']);
check($err==='' && (string)setting('cfg.app.session_timeout_minutes')==='','A Sign-in group with one invalid value saves nothing, the valid box included'.($err?": $err":''));
putenv('CMS_TEST_SESSION_ADD='.json_encode(['field_errors'=>['app_otp_expiry_minutes'=>'Sign-in codes last: enter a whole number from 3 to 30.']]));
[$code,$html,$err]=$renderPage('settings',[]);
check($code===0 && $err==='' && str_contains($html,'id="form-error-summary"') && str_contains($html,'href="#app_otp_expiry_minutes"'),'The refused Settings save is announced by an error summary linked to the box'.($err?": $err":''));
putenv('CMS_TEST_SESSION_ADD='.json_encode(['field_errors'=>['app_timezone'=>'Time zone: choose a time zone from the list.']]));
[$code,$html,$err]=$renderPage('settings',[]);
check($code===0 && $err==='' && preg_match('/<select id="app_timezone"[^>]*aria-invalid="true"/',$html) && str_contains($html,'id="err-app_timezone"'),'The time zone select carries its error'.($err?": $err":''));
// Phone apps.
putenv('CMS_TEST_SESSION_ADD='.json_encode(['field_errors'=>['app_id'=>'Use lower-case letters.']]));
[$code,$html,$err]=$renderPage('phone_apps',[]);
check($code===0 && $err==='' && str_contains($html,'id="form-error-summary"') && str_contains($html,'href="#app_id"'),'A refused app kit is announced by an error summary'.($err?": $err":''));
// Replace counselor: each error on its own box.
$db->exec("INSERT INTO clients(name,status,assigned_to,counselor_name,counselor_email,counselor_verified) VALUES('V429-ADMIN Counselor Client','Active Training',1,'V429-ADMIN Counselor','v429.counselor@example.invalid',1)");
[$code,,$err]=$renderPage('counselors',[],['replace_counselor'=>'1','email'=>'v429.counselor@example.invalid','new_name'=>'','new_email'=>'not-an-email']);
check($err==='' && (int)$db->query("SELECT COUNT(*) FROM clients WHERE counselor_email='v429.counselor@example.invalid'")->fetchColumn()===1,'A Replace counselor with errors changes nothing');
$rk429a='new_'.sprintf('%u',crc32('v429.counselor@example.invalid'));
putenv('CMS_TEST_SESSION_ADD='.json_encode(['field_errors'=>[$rk429a.'-e'=>'Enter the new counselor\'s email address.',$rk429a.'-n'=>'Enter the new counselor\'s name (120 characters or fewer).']]));
[$code,$html,$err]=$renderPage('counselors',[]);
check($code===0 && $err==='' && preg_match('/id="'.$rk429a.'-e"[^>]*aria-invalid="true"/',$html) && str_contains($html,'id="err-'.$rk429a.'-e"')
    && str_contains($html,'id="'.$rk429a.'-form" open') && str_contains($html,'id="form-error-summary"'),'Replace counselor errors are on the email and name boxes, with a summary and the form open'.($err?": $err":''));
// Client requests: the outcome error is on the Outcome box; offer errors open the offer form.
$db->exec("INSERT INTO portal_requests(client_id,type,status,details) VALUES($cv429a,'records','New','V429-ADMIN records')"); $pr429a=(int)$db->lastInsertId();
$db->exec("INSERT INTO portal_requests(client_id,type,status,details) VALUES($cv429a,'appointment','New','V429-ADMIN appointment')"); $pa429a=(int)$db->lastInsertId();
putenv('CMS_TEST_SESSION_ADD='.json_encode(['field_errors'=>['outcome_'.$pr429a=>'Say why the request is refused; the client sees it.','offer_t2-'.$pa429a=>'This time could not be read. Enter a date and a time.']]));
[$code,$html,$err]=$renderPage('portal_requests',[]);
putenv('CMS_TEST_SESSION_ADD');
check($code===0 && $err==='' && preg_match('/id="o-'.$pr429a.'"[^>]*aria-invalid="true"/',$html) && !preg_match('/id="s-'.$pr429a.'"[^>]*aria-invalid/',$html),
    'A records request outcome error is on the Outcome box, not the Status select'.($err?": $err":''));
check(preg_match('/id="t2-'.$pa429a.'"[^>]*aria-invalid="true"/',$html) && str_contains($html,'id="offer-'.$pa429a.'" open'),'An unreadable offered time is marked on its box and the offer form is open');
$db->exec("DELETE FROM portal_requests WHERE id IN ($pr429a,$pa429a)");

// ---- Pricing: the free-below income is shown to the cent ----
set_setting('billing_private_threshold','1999.50');
[$code,$html,$err]=$renderPage('pricing',[]);
check($code===0 && $err==='' && str_contains($html,'value="1999.50"'),'The free-below income shows 1999.50, so saving the page does not round it to 2000'.($err?": $err":''));

// ---- QuickBooks: a per-database sync lock, and a payment changed there is changed here ----
$db->exec("INSERT INTO settings(name,value) VALUES ('cfg.qbo.client_id','synthetic-qbo-id'),('cfg.qbo.client_secret','synthetic-qbo-secret'),('cfg.qbo.sandbox','yes') ON DUPLICATE KEY UPDATE value=VALUES(value)");
$GLOBALS['CONFIG']['qbo']=['client_id'=>'synthetic-qbo-id','client_secret'=>'synthetic-qbo-secret','sandbox'=>'yes'];
qbo_store(['access_token'=>'synthetic-qbo-access-429','refresh_token'=>'synthetic-qbo-refresh-429','expires_in'=>3600],'3456789',null,1);
set_setting('qbo_since',date('Y-m-d',strtotime('+1 day')));
$b429a='https://sandbox-quickbooks.api.intuit.com/v3/company/3456789';
$db->exec("INSERT INTO invoices(track_id,invoice_number,status,hours,hours_amount,total_amount,submitted_at,due_date,bill_mode,funding_agency,hourly_rate,qbo_id)
    VALUES($tv429a,'SYN-QBO-429A','Submitted',20,1000,1000,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 30 DAY),'vr','V429-ADMIN VR',50,'Q429A')"); $qi429a=(int)$db->lastInsertId();
$db->exec("INSERT INTO invoice_payments(invoice_id,kind,amount,received_on,reference,note,qbo_id) VALUES($qi429a,'payment',400,CURDATE(),'QuickBooks payment P429A','Recorded in QuickBooks','P429A')");
invoice_retotal($qi429a);
$newDate429a=date('Y-m-d',strtotime('-2 days'));
putenv('CMS_TEST_QBO='.json_encode([
    "$b429a/invoice/Q429A"=>['body'=>['Invoice'=>['Id'=>'Q429A','TotalAmt'=>1000,'Balance'=>700,'CustomerRef'=>['value'=>'C429'],'LinkedTxn'=>[['TxnId'=>'P429A','TxnType'=>'Payment']]]]],
    "$b429a/payment/P429A"=>['body'=>['Payment'=>['Id'=>'P429A','TxnDate'=>$newDate429a,'Line'=>[['Amount'=>300,'LinkedTxn'=>[['TxnId'=>'Q429A','TxnType'=>'Invoice']]]]]]],
]));
// Another installation on the same database server holding the old, server-wide lock name does not stop this sync.
$other429a=new PDO($dsn, getenv('CMS_TEST_USER') ?: 'root', getenv('CMS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$other429a->query("SELECT GET_LOCK('cms_qbo_sync', 0)");
qbo_sync_all();
$other429a->query("SELECT RELEASE_LOCK('cms_qbo_sync')"); $other429a=null;
putenv('CMS_TEST_QBO');
$pay429a=$db->query("SELECT amount, received_on FROM invoice_payments WHERE invoice_id=$qi429a")->fetchAll();
check(count($pay429a)===1 && abs((float)$pay429a[0]['amount']-300)<0.001 && $pay429a[0]['received_on']===$newDate429a,
    'A payment corrected in QuickBooks from $400 to $300 (and its date) is corrected here, even while another installation holds the old lock name');
check(abs((float)$db->query("SELECT paid_amount FROM invoices WHERE id=$qi429a")->fetchColumn()-300)<0.001
    && str_contains((string)$db->query("SELECT detail FROM audit_log WHERE action='invoice.payment_change' AND entity_id=$qi429a ORDER BY id DESC LIMIT 1")->fetchColumn(),'is now'),
    'The invoice total paid is recalculated to $300 and the change is audited');
$db->exec("DELETE FROM invoice_payments WHERE invoice_id=$qi429a");
// Nothing changed (the same payments linked, the same balance on both sides): the payments are not read
// again, so a sync does not spend a request on each one. The fake would refuse that read.
$db->exec("INSERT INTO invoice_payments(invoice_id,kind,amount,received_on,reference,note,qbo_id) VALUES($qi429a,'payment',400,CURDATE(),'QuickBooks payment P429B','Recorded in QuickBooks','P429B')");
invoice_retotal($qi429a);
$db->exec("UPDATE invoices SET qbo_error=NULL, qbo_synced_at=NULL WHERE id=$qi429a");
$fqb429a=fn(array $inv, array $extra=[])=>putenv('CMS_TEST_QBO='.json_encode($extra+[
    "$b429a/invoice/Q429A"=>['body'=>['Invoice'=>$inv+['Id'=>'Q429A','TotalAmt'=>1000,'CustomerRef'=>['value'=>'C429']]]],
    "$b429a/payment/P429B"=>['status'=>500,'body'=>null],
]));
$fqb429a(['Balance'=>600,'LinkedTxn'=>[['TxnId'=>'P429B','TxnType'=>'Payment']]]);
qbo_sync_all();
$st429a=$db->query("SELECT qbo_error, qbo_synced_at FROM invoices WHERE id=$qi429a")->fetch();
check($st429a['qbo_error']===null && $st429a['qbo_synced_at']!==null,'An invoice whose payments and balance agree with QuickBooks is checked without reading each payment again');
// The balance differs (an amount changed there): the payment is read, and a refused read is reported.
$fqb429a(['Balance'=>700,'LinkedTxn'=>[['TxnId'=>'P429B','TxnType'=>'Payment']]]);
qbo_sync_all();
check(str_contains((string)$db->query("SELECT qbo_error FROM invoices WHERE id=$qi429a")->fetchColumn(),'could not be checked'),'When the balance differs, the linked payment is read again (amount changes are still followed)');
// Too many requests (429): tried again next run, the invoice not marked as failed.
$db->exec("UPDATE invoices SET qbo_error=NULL, qbo_synced_at=NULL WHERE id=$qi429a");
$fqb429a([],["$b429a/invoice/Q429A"=>['status'=>429,'body'=>null]]);
qbo_sync_all();
$st429a=$db->query("SELECT qbo_error, qbo_synced_at FROM invoices WHERE id=$qi429a")->fetch();
check($st429a['qbo_error']===null && $st429a['qbo_synced_at']===null && qbo_throttled(),'A QuickBooks "too many requests" reply fails no invoice: it is left for the next run');
putenv('CMS_TEST_QBO');
$db->exec("DELETE FROM invoice_payments WHERE invoice_id=$qi429a");
$db->exec("DELETE FROM qbo_connection"); $db->exec("DELETE FROM settings WHERE name LIKE 'cfg.qbo.%'"); unset($GLOBALS['CONFIG']['qbo']);

$db->prepare('UPDATE users SET group_id=? WHERE id=1')->execute([$g429a!==false && $g429a!==null ? (int)$g429a : null]);
