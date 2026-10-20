<?php
// Included by full_schema.php after the v429 files, against its synthetic database only:
// 4.30.0 (admin area, accessibility): letterhead PDFs read in order with their title, subject and
// language set, and a signed, expiring link to the accessible copy that needs no staff sign-in;
// error summaries and field errors instead of flash-only refusals (custom reports, incoming
// authorizations, users, my account, equipment, QuickBooks, invoices, client requests, System
// health, credentials); plain option labels; report-period dates tied to their fields; required
// fields marked; hints inside labels; Spanish setting values and survey comments marked lang="es".

require_once $root.'/public/inc/app_pdf.php';
require_once $root.'/public/inc/report_period.php';

// Pages here are administrator pages: run them as a legacy administrator, restored at the end.
$g430a=$db->query('SELECT group_id FROM users WHERE id=1')->fetchColumn();
$db->exec('UPDATE users SET group_id=NULL WHERE id=1');
// A page rendered with session values (field errors, kept input) from a refused save; cleared after.
$renderWith430a=function(string $page,array $query,array $add)use($renderPage):array{
    putenv('CMS_TEST_SESSION_ADD='.json_encode($add));
    try { return $renderPage($page,$query); } finally { putenv('CMS_TEST_SESSION_ADD'); }
};
$src430a=fn(string $f):string=>(string)file_get_contents($root.'/public/'.$f);

// ---- Letterhead PDFs: properties, reading order, and the accessible copy by signed link ----
$db->exec("INSERT INTO funding_agencies(name,is_active) VALUES('V430-ADMIN Agency',1)");
agencies_all(true);
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('V430-ADMIN PDF Client','Active Training',1)"); $c430a=(int)$db->lastInsertId();
$db->exec("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,billing_mode,authorized_hours,hourly_rate,status)
    VALUES($c430a,'V430-ADMIN JAWS training','V430-ADMIN-AUTH','V430-ADMIN Agency','vr',40,50,'Active')"); $t430a=(int)$db->lastInsertId();
$db->exec("INSERT INTO invoices(track_id,invoice_number,status,hours,hours_amount,total_amount,bill_mode,auth_number,funding_agency,bill_to,hourly_rate)
    VALUES($t430a,'SYN-430A-PDF','Draft',2,100,100,'vr','V430-ADMIN-AUTH','V430-ADMIN Agency','V430-ADMIN Counselor Line',50)"); $i430a=(int)$db->lastInsertId();
$db->exec("INSERT INTO work_logs(track_id,work_date,hours,description,is_billed,invoice_id,hourly_rate) VALUES($t430a,CURDATE(),2,'V430-ADMIN keyboard lesson',1,$i430a,50)");

[$code,$pdf430a,$err]=$renderPage('invoice_pdf',['invoice'=>$i430a]);
check($err==='' && str_starts_with($pdf430a,'%PDF') && str_contains($pdf430a,'/Lang (en-US)') && str_contains($pdf430a,'/Subject (') && str_contains($pdf430a,'/Title ('),
    'The invoice PDF has its title, subject and language set'.($err?": $err":''));
check(preg_match('~/URI \((https://example\.invalid/accessible_copy\.php\?k=([0-9a-f]{24})\.(\d+)\.([A-Za-z0-9_-]{22}))\)~',$pdf430a,$lk430a)===1,
    'The invoice PDF links to its accessible copy without a sign-in (accessible_copy.php with a signed key)');
$exp430a=(int)$lk430a[3];
// A second download of the same document, same content, gets the same link (no new public copy each time).
[$code,$pdfB430a,$err]=$renderPage('invoice_pdf',['invoice'=>$i430a]);
check($err==='' && str_contains($pdfB430a,'/URI ('.$lk430a[1].')') && count(array_filter(doc_link_all(),fn($m)=>($m['entity_id']??null)===$i430a && ($m['entity']??'')==='invoice'))===1,'Downloading the same invoice again reuses its accessible copy and link'.($err?": $err":''));
$meta430a=json_decode((string)file_get_contents(doc_link_dir().'/'.$lk430a[2].'.json'),true);
check(($meta430a['client_ids']??null)===[$c430a] && ($meta430a['entity']??'')==='invoice','The copy records the client it is about');
check(abs($exp430a-(time()+90*86400))<300,'The link to the accessible copy works for 90 days');
// The page text, in the order it is written to the PDF (what text extraction and screen readers follow).
$text430a='';
if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s',$pdf430a,$st430a)) foreach ($st430a[1] as $s430a) { $u430a=@gzuncompress($s430a); $text430a.=$u430a===false?$s430a:$u430a; }
$pos430a=fn(string $s)=>strpos($text430a,'('.doc_pdf_text($s).')');
$po430a=[$pos430a(brand('org')),$pos430a(brand('tagline')),$pos430a('INVOICE'),$pos430a('Invoice #: SYN-430A-PDF')];
check(!in_array(false,$po430a,true) && $po430a[0]<$po430a[1] && $po430a[1]<$po430a[2] && $po430a[2]<$po430a[3],
    'The letterhead is read one column at a time: organisation and tagline, then INVOICE and its number (not interleaved line by line)');
$pb430a=[$pos430a('BILL TO'),$pos430a('V430-ADMIN Agency'),$pos430a('SERVICE AUTHORIZATION'),$pos430a('Authorization number: V430-ADMIN-AUTH')];
check(!in_array(false,$pb430a,true) && $pb430a[0]<$pb430a[1] && $pb430a[1]<$pb430a[2] && $pb430a[2]<$pb430a[3],
    'Bill to is read whole, its heading first, before the service authorization column');

// The copy opens for someone who is not signed in, and the opening is audited.
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
[$code,$html,$err]=$renderPage('accessible_copy',['k'=>$lk430a[2].'.'.$lk430a[3].'.'.$lk430a[4]]);
check($err==='' && str_contains($html,'<html lang="en"') && str_contains($html,'<h1>Invoice — V430-ADMIN PDF Client</h1>') && str_contains($html,'SYN-430A-PDF')
    && str_contains($html,'<th scope="col">Hours</th>') && !str_contains($html,'Dashboard / return to CMS'),
    'The accessible copy opens without a sign-in: headings, the line-item table with its headers, and no staff links'.($err?": $err":''));
check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='document.link_view' AND entity='invoice' AND entity_id=$i430a AND user_id IS NULL")->fetchColumn()===1,
    'Opening the accessible copy by link is listed in the audit trail');
// A changed signature, or another expiry, opens nothing.
$bad430a=$lk430a[2].'.'.$lk430a[3].'.'.strrev($lk430a[4]);
[$code,$html,$err]=$renderPage('accessible_copy',['k'=>$bad430a]);
check($err==='' && str_contains($html,'This link does not open a document') && !str_contains($html,'SYN-430A-PDF'),'A link whose signature was changed opens nothing'.($err?": $err":''));
[$code,$html,$err]=$renderPage('accessible_copy',['k'=>$lk430a[2].'.'.($exp430a+86400).'.'.$lk430a[4]]);
check($err==='' && str_contains($html,'This link does not open a document'),'A link whose expiry was extended opens nothing'.($err?": $err":''));
// An expired link (signed, but past its time) says so, and the copy is removed.
$key430a=doc_link_key();
$past430a=time()-60;
[$code,$html,$err]=$renderPage('accessible_copy',['k'=>$lk430a[2].'.'.$past430a.'.'.doc_link_sign($lk430a[2],$past430a,(string)$key430a)]);
putenv('CMS_TEST_ANON');
check($err==='' && str_contains($html,'This link has expired') && !str_contains($html,'SYN-430A-PDF') && !is_file(doc_link_dir().'/'.$lk430a[2].'.html'),
    'An expired link says it has expired, and its copy is removed'.($err?": $err":''));
// A permanently purged client's copies are removed, including those of a duplicate merged into them.
$cp430a=doc_link_create('<p>V430-ADMIN purge copy</p>','invoice',null,'V430-ADMIN purge',990430);
$cm430a=doc_link_create('<p>V430-ADMIN merged copy</p>','invoice',null,'V430-ADMIN merged',990431);
doc_link_add_client(990431,990430);
$idOf430a=fn(?array $l)=>$l?substr((string)strstr($l['url'],'k='),2,24):'';
check($cp430a!==null && $cm430a!==null && doc_link_forget_client(990430)===2 && !is_file(doc_link_dir().'/'.$idOf430a($cp430a).'.html') && !is_file(doc_link_dir().'/'.$idOf430a($cm430a).'.html'),
    'Purging a client removes their accessible copies, and those of a duplicate merged into them');
check(str_contains($src430a('client.php'),'doc_link_forget_client($id)') && str_contains((string)file_get_contents($root.'/public/inc/client_merge.php'),'doc_link_add_client($mergeId, $keepId)'),
    'The purge removes the client\'s copies, and a merge hands the duplicate\'s copies to the kept client');
// The signed-in HTML version is still there.
[$code,$html,$err]=$renderPage('invoice_pdf',['invoice'=>$i430a,'format'=>'html']);
check($err==='' && str_contains($html,'SYN-430A-PDF') && str_contains($html,'Dashboard / return to CMS'),'The signed-in accessible HTML download still works'.($err?": $err":''));

// Funder and outcome report PDFs: the same properties and link.
$period430a=['period'=>'custom','from'=>date('Y-m-01'),'to'=>date('Y-m-d')];
[$code,$fpdf430a,$err]=$renderPage('funder_report',['agency'=>'V430-ADMIN Agency','pdf'=>1]+$period430a);
check($err==='' && str_starts_with($fpdf430a,'%PDF') && str_contains($fpdf430a,'/Subject (') && str_contains($fpdf430a,'/URI (https://example.invalid/accessible_copy.php?k='),
    'The funder report PDF has a subject and a link to its accessible copy'.($err?": $err":''));
[$code,$opdf430a,$err]=$renderPage('outcomes',['pdf'=>1]+$period430a);
check($err==='' && str_starts_with($opdf430a,'%PDF') && str_contains($opdf430a,'/Subject (') && str_contains($opdf430a,'/URI (https://example.invalid/accessible_copy.php?k='),
    'The outcome report PDF has a subject and a link to its accessible copy'.($err?": $err":''));
[$code,$html,$err]=$renderPage('funder_report',['agency'=>'V430-ADMIN Agency','html'=>1]+$period430a);
check($err==='' && str_contains($html,'<h1>Funder Outcome Report — V430-ADMIN Agency</h1>'),'The funder report HTML download still works'.($err?": $err":''));

// ---- Funder report: plain agency names; billed-only agencies grouped; the count said after Run ----
$db->exec("INSERT INTO invoices(track_id,invoice_number,status,hours,hours_amount,total_amount,bill_mode,funding_agency) VALUES($t430a,'SYN-430A-OLD','Submitted',1,50,50,'vr','V430-ADMIN Billed Only')");
$iold430a=(int)$db->lastInsertId();
[$code,$html,$err]=$renderPage('funder_report',['agency'=>'V430-ADMIN Agency']+$period430a);
check($code===0 && $err==='' && preg_match('~<option value="V430-ADMIN Agency" selected>V430-ADMIN Agency</option>~',$html)
    && preg_match('~<optgroup label="Billed work only">.*?<option value="V430-ADMIN Billed Only">V430-ADMIN Billed Only</option>.*?</optgroup>~s',$html)
    && !str_contains($html,'training track</option>') && !str_contains($html,'billed work only</option>') && str_contains($html,'1 current training track.'),
    'Funder report options are plain agency names, billed-only agencies are grouped, and the track count is said on the page'.($err?": $err":''));
$db->exec("DELETE FROM invoices WHERE id=$iold430a");

// ---- Report period: a date that could not be read is on its field, said once ----
[$code,$html,$err]=$renderPage('funder_report',['agency'=>'V430-ADMIN Agency','from'=>'not-a-date','to'=>date('Y-m-d')]);
check($code===0 && $err==='' && preg_match('~<input type="date" id="from" name="from" value="[^"]*" aria-invalid="true" aria-describedby="err-from">~',$html)
    && str_contains($html,'id="err-from"') && str_contains($html,'href="#from"') && substr_count($html,'From date could not be read')===2,
    'An unreadable From date is marked on the field, with a summary link, and not repeated in a stray paragraph'.($err?": $err":''));
check(str_contains(report_period_form('outcomes.php',['preset'=>'custom','from'=>'2026-01-01','to'=>'2026-01-31']),'novalidate')
    && !str_contains(report_period_form('outcomes.php',['preset'=>'custom','from'=>'2026-01-01','to'=>'2026-01-31']),'aria-invalid'),'A period form without errors marks nothing');

// ---- Custom reports: a scrollable labelled result region; a refused save has a summary ----
[$code,$html,$err]=$renderPage('custom_reports',['run'=>1]);
check($code===0 && $err==='' && str_contains($html,'<div class="table-scroll" tabindex="0" role="region" aria-labelledby="result-cap">') && str_contains($html,'id="result-cap"')
    && !str_contains($html,'table-wrap'),'Custom report results are in a keyboard-scrollable region named by the table caption'.($err?": $err":''));
$sr430a=(int)$db->query('SELECT COUNT(*) FROM saved_reports')->fetchColumn();
[$code,,$err]=$renderPage('custom_reports',[],['save_report'=>'1','report_name'=>'','subject'=>'clients']);
check($err==='' && (int)$db->query('SELECT COUNT(*) FROM saved_reports')->fetchColumn()===$sr430a,'A custom report with no name is not saved');
[$code,$html,$err]=$renderWith430a('custom_reports',['run'=>1],['field_errors'=>['report_name'=>'Name the report (120 characters or fewer) to save it.']]);
check($code===0 && $err==='' && str_contains($html,'id="form-error-summary"') && str_contains($html,'href="#report_name"')
    && preg_match('~id="report_name"[^>]*required aria-required="true"~',$html),'A refused custom report name is announced by a summary, and the name is marked required'.($err?": $err":''));

// ---- Incoming authorizations: the pasted email and the client choice ----
$ia430a=(int)$db->query('SELECT COUNT(*) FROM incoming_authorizations')->fetchColumn();
[$code,,$err]=$renderPage('authorizations',[],['read_email'=>'1','email_text'=>'']);
check($err==='' && (int)$db->query('SELECT COUNT(*) FROM incoming_authorizations')->fetchColumn()===$ia430a,'An empty pasted authorization email is refused');
[$code,$html,$err]=$renderWith430a('authorizations',[],['field_errors'=>['email_text'=>'Paste the authorization email first.']]);
check($code===0 && $err==='' && str_contains($html,'id="form-error-summary"') && str_contains($html,'href="#email_text"'),'A refused pasted email is announced by a summary'.($err?": $err":''));
$a430=$src430a('authorizations.php');
check(!str_contains($a430,"flash('Choose the client this authorization is for.'") && str_contains($a430,"keep_field_errors(['client_id' =>"),'A missing client choice is on the client choice, not a flash');

// ---- Users, My account: the summaries cover every field error ----
[$code,$html,$err]=$renderWith430a('users',['id'=>1],['field_errors'=>['privacy_training_on'=>'Privacy training date: enter a date that has passed, or leave it empty.']]);
check($code===0 && $err==='' && str_contains($html,'href="#u_priv"') && str_contains($html,'id="form-error-summary"'),'A wrong privacy training date is in the Users summary'.($err?": $err":''));
check(preg_match('~<label for="to_user">Move their .{0,400}<span class="hint">Open referrals go only~s',$html)===1,'The caseload transfer help is inside its label'.($err?": $err":''));
[$code,$html,$err]=$renderWith430a('account',[],['field_errors'=>['mobile'=>'Enter a mobile number such as 515-555-0100, or leave it empty.'],'old_input'=>['mobile'=>'V430-12']]);
check($code===0 && $err==='' && str_contains($html,'href="#mobile"') && str_contains($html,'value="V430-12"'),'A refused mobile number has a summary and keeps what was typed'.($err?": $err":''));
check(str_contains($html,'id="pk_pw_err"') && preg_match('~id="pk_pw" required aria-required="true"~',$html) && str_contains($html,'Choose Add a passkey on this device to try again')
    && str_contains($html,'pw.focus()') && !str_contains($html,"setAttribute('aria-describedby','pk_err')"),
    'Adding a passkey: the password is required, a wrong one is said in its label with focus on it, and the cancel message names the real button');
check(preg_match('~<label for="current_password">Current password <span~',$html) && preg_match('~id="current_password" name="current_password" required aria-required="true"~',$html),
    'The password change fields are visibly marked required');

// ---- Equipment: reservation refusals on the item; plain option labels grouped by status ----
$db->exec("INSERT INTO equipment(name,serial,status) VALUES('V430-ADMIN Magnifier','V430-SN','Available')"); $eq430a=(int)$db->lastInsertId();
$db->exec("INSERT INTO equipment(name,status) VALUES('V430-ADMIN Retired Reader','Retired')"); $er430a=(int)$db->lastInsertId();
$db->exec("INSERT INTO equipment_reservations(equipment_id,client_id,status) VALUES($eq430a,$c430a,'Reserved')");
$rv430a=(int)$db->query('SELECT COUNT(*) FROM equipment_reservations')->fetchColumn();
[$code,,$err]=$renderPage('equipment',[],['add_reservation'=>'1','equipment_id'=>(string)$er430a,'client_id'=>(string)$c430a,'notes'=>'V430-ADMIN kept note']);
check($err==='' && (int)$db->query('SELECT COUNT(*) FROM equipment_reservations')->fetchColumn()===$rv430a,'A retired item cannot be reserved');
[$code,$html,$err]=$renderPage('equipment',[]);
check($code===0 && $err==='' && preg_match('~<optgroup label="Held by a reservation">(?:(?!</optgroup>).)*?<option value="'.$eq430a.'">V430-ADMIN Magnifier \(serial number V430-SN\)</option>~s',$html)
    && !str_contains($html,'— held by a reservation</option>') && !str_contains($html,'V430-ADMIN Magnifier (Available)')
    && preg_match('~<optgroup label="Available">.*?V430-ADMIN Magnifier \(serial number V430-SN\)</option>~s',$html),
    'Equipment options are the name and serial number, grouped by hold and by status'.($err?": $err":''));
check(preg_match('~id="res-form" novalidate~',$html)===1,'The reservation form uses the site\'s error summary, not the browser bubble');
$e430=$src430a('equipment.php');
check(!str_contains($e430,"flash('That item is retired and cannot be reserved.'"),'A reservation refused under the lock is on the item field, not a flash');
[$code,$html,$err]=$renderPage('equipment',['scan'=>$eq430a]);
$scanOk430a=$code===0 && $err==='';
$db->exec("UPDATE equipment SET status='Repair' WHERE id=$eq430a");
[$code,$h2430a,$err]=$renderWith430a('equipment',['scan'=>$eq430a],['field_errors'=>['loan_equipment_id'=>'Item: that item is no longer available; choose another.']]);
$db->exec("UPDATE equipment SET status='Available' WHERE id=$eq430a");
check($code===0 && $err==='' && str_contains($h2430a,'The item was not lent. Item: that item is no longer available') && str_contains($h2430a,'id="form-errors"'),
    'A loan refused from the item page is said there even when the item can no longer be lent'.($err?": $err":''));
check($scanOk430a && preg_match('~<form method="post" action="equipment.php" class="stacked" novalidate>.{0,1200}name="from_scan"~s',$html)
    && preg_match('~id="sc_client" name="client_id" required aria-required="true"~',$html),'The item page loan form marks the client required and uses the site\'s summary'.($err?": $err":''));

// ---- QuickBooks first date, System health expiry date: on the field, typed value kept ----
$since430a=setting('qbo_since');
[$code,,$err]=$renderPage('qbo',[],['qbo_since'=>'1','since'=>'31/31/2026']);
check($err==='' && setting('qbo_since')===$since430a,'An unreadable QuickBooks first date is not saved');
$q430=$src430a('qbo.php');
check(!str_contains($q430,"flash('Enter the first date as a full date.'") && str_contains($q430,"keep_field_errors(['since' =>") && str_contains($q430,"old_in('since',"),
    'The QuickBooks first date error is on the field and the typed date is kept');
$ge430a=setting('graph_credential_expires');
[$code,,$err]=$renderPage('system_health',[],['save_graph_expiry'=>'1','graph_expires'=>'V430-bad']);
check($err==='' && setting('graph_credential_expires')===$ge430a,'An unreadable Graph secret expiry date is not saved');
[$code,$html,$err]=$renderWith430a('system_health',[],['field_errors'=>['graph_expires'=>'Enter the expiry date as a date within the next three years, or leave it empty.'],'old_input'=>['graph_expires'=>'V430-bad']]);
check($code===0 && $err==='' && preg_match('~id="graph_expires" name="graph_expires" value="V430-bad" aria-invalid="true"~',$html) && str_contains($html,'href="#graph_expires"'),
    'The Graph secret expiry error is on the field, with a summary, and the typed value is kept'.($err?": $err":''));

// ---- Invoices and client requests: nothing ticked is on the boxes ----
$db->exec("INSERT INTO invoices(track_id,invoice_number,status,hours,hours_amount,total_amount,bill_mode) VALUES($t430a,'SYN-430A-D1','Draft',1,50,50,'vr'),($t430a,'SYN-430A-D2','Draft',1,50,50,'vr')");
[$code,,$err]=$renderPage('invoices',[],['bulk_submit'=>'1','bulk_submitted_on'=>date('Y-m-d')]);
check($err==='' && (int)$db->query("SELECT COUNT(*) FROM invoices WHERE invoice_number IN ('SYN-430A-D1','SYN-430A-D2') AND status='Draft'")->fetchColumn()===2,'Marking no invoices submitted changes nothing');
[$code,$html,$err]=$renderWith430a('invoices',[],['field_errors'=>['bulk_ids'=>'Tick at least one draft invoice.']]);
check($code===0 && $err==='' && str_contains($html,'<details open><summary id="bulk-h">') && preg_match('~href="#bulk-\d+"~',$html) && str_contains($html,'id="err-bulk_ids"'),
    'Nothing ticked is said on the draft invoice boxes, in a summary, with the form open'.($err?": $err":''));
check(str_contains($src430a('invoices.php'),"keep_field_errors(['bulk_submitted_on' => 'The date is before"),
    'A date before every chosen invoice was created is on the date field');
$db->exec("INSERT INTO portal_requests(client_id,type,status,details) VALUES($c430a,'appointment','New','V430-ADMIN one'),($c430a,'message','New','V430-ADMIN two')");
$pr430a=(int)$db->query("SELECT COUNT(*) FROM portal_requests WHERE details LIKE 'V430-ADMIN%' AND status='New'")->fetchColumn();
[$code,,$err]=$renderPage('portal_requests',[],['bulk_close'=>'1','bulk_to'=>'Declined']);
check($err==='' && (int)$db->query("SELECT COUNT(*) FROM portal_requests WHERE details LIKE 'V430-ADMIN%' AND status='New'")->fetchColumn()===$pr430a,'Closing no requests changes nothing');
[$code,$html,$err]=$renderWith430a('portal_requests',[],['field_errors'=>['bulk_ids'=>'Tick at least one open request.'],'old_input'=>['bulk_to'=>'Declined','bulk_close'=>'1']]);
check($code===0 && $err==='' && str_contains($html,'<details open><summary id="bulk-h">') && str_contains($html,'id="err-bulk_ids"') && str_contains($html,'value="Declined" checked'),
    'Nothing ticked is said on the request boxes, and Declined stays chosen'.($err?": $err":''));
$db->exec("DELETE FROM portal_requests WHERE details LIKE 'V430-ADMIN%'");

// ---- Credentials: the Other name error is on the name field ----
$cr430a=(int)$db->query('SELECT COUNT(*) FROM staff_credentials')->fetchColumn();
[$code,,$err]=$renderPage('credentials',[],['save_credential'=>'1','credential_id'=>'0','user_id'=>'1','type'=>'Other','other_name'=>'']);
check($err==='' && (int)$db->query('SELECT COUNT(*) FROM staff_credentials')->fetchColumn()===$cr430a,'A credential under Other with no name is not saved');
[$code,$html,$err]=$renderWith430a('credentials',[],['field_errors'=>['other_name'=>'Name the credential, since Other is chosen.'],'old_input'=>['credential_id'=>'0','type'=>'Other']]);
check($code===0 && $err==='' && preg_match('~id="on" name="other_name"[^>]*aria-invalid="true"~',$html) && str_contains($html,'href="#on"') && !preg_match('~id="ty" name="type" aria-invalid~',$html),
    'The missing Other name is marked on the name field, not the Credential list'.($err?": $err":''));

// ---- Plain option labels: templates and the audit client filter ----
[$code,$html,$err]=$renderPage('templates',[]);
check($code===0 && $err==='' && str_contains($html,'<option value="">All areas</option>'),'Templates: "All areas" has no count'.($err?": $err":''));
$db->exec("INSERT INTO clients(name,status) VALUES('V430-ADMIN Twin','Active Training'),('V430-ADMIN Twin','Active Training')"); $tw430a=(int)$db->lastInsertId();
$db->exec("INSERT INTO clients(name,status,archived_at) VALUES('V430-ADMIN Archived','Closed',NOW())"); $ar430a=(int)$db->lastInsertId();
[$code,$html,$err]=$renderPage('audit',[]);
check($code===0 && $err==='' && str_contains($html,'>V430-ADMIN Twin #'.$tw430a.'</option>') && str_contains($html,'<option value="'.$c430a.'">V430-ADMIN PDF Client</option>')
    && preg_match('~<optgroup label="Archived">.*?<option value="'.$ar430a.'">V430-ADMIN Archived</option>~s',$html) && !str_contains($html,' — #'),
    'Audit client options are plain names, the record number only for a shared name, archived clients grouped'.($err?": $err":''));

// ---- Hints inside labels; required marks ----
[$code,$html,$err]=$renderPage('admin',[]);
check($code===0 && $err==='' && str_contains($html,'<label for="note_lock_days">Lock case notes for editing after <span class="hint">A locked note')
    && str_contains($html,'<label for="retention_years">Record retention: auto-archive clients closed for <span class="hint">Archived records')
    && !str_contains($html,'Full name *</label>') && !str_contains($html,'Permission group * <span'),
    'Administration: the note lock and retention help are inside their labels, and required fields use the required mark'.($err?": $err":''));
[$code,$html,$err]=$renderPage('email_templates',['key'=>'referral_closed']);
check($code===0 && $err==='' && preg_match('~<label for="tpl_body">Body <span class="hint">[^<]*Available placeholders: <code>~',$html) && !str_contains($html,'<p class="hint">Available placeholders'),
    'Email templates: the placeholders are listed inside the Body label'.($err?": $err":''));
foreach (['vendors.php'=>'id="nv_vendor" name="vendor" maxlength="120" required aria-required="true"','funding_agencies.php'=>'name="name" maxlength="190" required aria-required="true"',
    'vr_portal.php'=>'name="note" maxlength="255" required aria-required="true"','staff_training.php'=>'name="questions" rows="12" required aria-required="true"',
    'staff_agreements.php'=>'id="nb" name="body" rows="18" required aria-required="true"','update.php'=>'name="have_backup" value="1" required aria-required="true"',
    'templates.php'=>'<fieldset role="radiogroup" aria-required="true"','at_services.php'=>'name="loan_equipment_id" required aria-required="true"'] as $f430a=>$needle430a)
    check(str_contains($src430a($f430a),$needle430a),"Required fields on $f430a are required in the markup too");
foreach (['access_bookings.php','curriculum.php','programs.php','score_assessment.php','at_services.php','staff_training.php','staff_agreements.php','admin.php'] as $f430a)
    check(str_contains($src430a($f430a),'Fields marked with an asterisk'),"$f430a says what the asterisk means");
foreach (['procurement.php'=>3,'at_services.php'=>2] as $f430a=>$n430a) check(substr_count($src430a($f430a),'novalidate')>=$n430a,"Forms on $f430a use the site's error summary (novalidate)");
check(!str_contains($src430a('procurement.php'),"flash('Choose whether the request was denied or cancelled."),'Procurement: a missing deny or cancel choice is on the choice, not a flash');
check(!str_contains($src430a('at_services.php'),"' — held by a reservation'"),'AT services equipment options carry no status suffix');

// ---- Spanish setting values ----
[$code,$html,$err]=$renderPage('settings',[]);
check($code===0 && $err==='' && preg_match('~id="brand_privacy_law_es"[^>]*lang="es"~',$html) && !preg_match('~id="brand_privacy_law"[^>]*lang="es"~',$html),
    'Settings: a Spanish value (a key ending in _es) is marked lang="es"'.($err?": $err":''));

// ---- Survey comments in Spanish are marked ----
$db->exec("INSERT INTO clients(name,status,preferred_language) VALUES('V430-ADMIN Spanish Client','Closed - Successful','es')"); $ces430a=(int)$db->lastInsertId();
$db->exec("INSERT INTO surveys(client_id,token,completed_at,satisfaction,goals_met,comments,channel) VALUES($ces430a,'".str_repeat('a',40)."v430esA1',NOW(),9,9,'V430 Muy buena capacitación','Email')");
$db->exec("INSERT INTO surveys(client_id,token,completed_at,satisfaction,goals_met,comments,channel) VALUES($c430a,'".str_repeat('b',40)."v430enA1',NOW(),8,8,'V430 Very good training','Email')"); $sen430a=(int)$db->lastInsertId();
$hasLang430a=(int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='surveys' AND COLUMN_NAME='answer_lang'")->fetchColumn()===1;
if ($hasLang430a) $db->exec("INSERT INTO surveys(client_id,token,completed_at,satisfaction,goals_met,comments,channel,answer_lang) VALUES($c430a,'".str_repeat('c',40)."v430esB1',NOW(),7,7,'V430 Respondido en español','Email','es')");
[$code,$html,$err]=$renderPage('survey_responses',$period430a);
check($code===0 && $err==='' && str_contains($html,'<span lang="es">V430 Muy buena capacitación</span>') && str_contains($html,'<td>V430 Very good training</td>')
    && (!$hasLang430a || str_contains($html,'<span lang="es">V430 Respondido en español</span>')),
    'A survey comment answered in Spanish is marked lang="es"; an English one is not'.($err?": $err":''));
$db->exec("DELETE FROM surveys WHERE comments LIKE 'V430 %'");

$db->exec("DELETE FROM equipment_reservations WHERE equipment_id=$eq430a");
$db->exec("DELETE FROM equipment WHERE id IN ($eq430a,$er430a)");
$db->prepare('UPDATE users SET group_id=? WHERE id=1')->execute([$g430a!==false && $g430a!==null ? (int)$g430a : null]);
