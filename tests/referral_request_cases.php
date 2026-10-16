<?php
// Runs inside the isolated full_schema.php harness; notifications use a no-op mail transport.
// The public form is posted by an anonymous visitor (no staff session), as it is
// in real use; staff pages in between are rendered signed in.
$publicForm = function (array $query, ?array $post = null) use ($renderPage): array {
    putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
    try { return $renderPage('community_referral', $query, $post); } finally { putenv('CMS_TEST_ANON'); }
};
$baseReferral = ['role'=>'counselor','referrer_name'=>'Synthetic Referrer','referrer_phone'=>'555-0100','referrer_email'=>'referrer@example.invalid','organization'=>'Test agency','client_name'=>'Structured Referral Test','body'=>'Synthetic referral workflow test.','referral_purpose'=>'Additional service','requested_services'=>['Assistive technology training'],'specific_needs'=>['Screen readers','Braille devices','Screen readers']];
[$code,$out,$err]=$publicForm(['role'=>'school']);
check($code===0&&$err===''&&!str_contains($out,'name="preets[]"')&&!str_contains($out,'Pre-employment transition services needed')&&!str_contains($out,'Job exploration counseling')&&!str_contains($out,'value="Braille literacy"')&&!str_contains($out,'value="Independent living skills"')&&str_contains($out,'Assistive technology for daily living'),'School referral form offers only technology services and needs');
$before = (int)$db->query('SELECT COUNT(*) FROM referrals')->fetchColumn();
[$code,$out,$err] = $publicForm([], $baseReferral);
$ref = $db->query('SELECT * FROM referrals ORDER BY id DESC LIMIT 1')->fetch();
check($code===0 && $err==='' && (int)$db->query('SELECT COUNT(*) FROM referrals')->fetchColumn()===$before+1
    && $ref['referral_purpose']==='Additional service' && $ref['requested_service']==='Assistive technology training'
    && $ref['specific_needs']==='Screen readers; Braille devices','Structured referral saves purpose, service and multiple needs, deduplicating selections');
$rid=(int)$ref['id'];
[$code,$out,$err]=$renderPage('referrals',['view'=>$rid]);
check($code===0 && str_contains($out,'Additional service') && str_contains($out,'Screen readers; Braille devices'),'Staff review displays the new referral fields');
$detail=$call('referral',[],['id'=>$rid])['referral'];
check(str_contains($detail['body'],'Referral purpose: Additional service') && str_contains($detail['body'],'Specific needs: Screen readers; Braille devices'),'Existing mobile clients receive structured details in the referral description');
$call('convert_referral',['id'=>$rid]);
$cid=(int)$db->query("SELECT client_id FROM referrals WHERE id=$rid")->fetchColumn();
// The counselor email typed on the public form is stored, but unconfirmed: no
// automatic report goes to it until staff confirm it on the client's edit page.
$converted=$db->query("SELECT counselor_email,counselor_verified FROM clients WHERE id=$cid")->fetch();
check($converted && $converted['counselor_email']==='referrer@example.invalid' && (int)$converted['counselor_verified']===0,'A counselor email from the public referral form is kept on conversion but marked unconfirmed');
$note=$db->query("SELECT body FROM client_notes WHERE client_id=$cid AND note_type='Intake' ORDER BY id DESC LIMIT 1")->fetchColumn();
check(str_contains($note,'Referral purpose: Additional service') && str_contains($note,'Specific needs: Screen readers; Braille devices'),'Mobile conversion preserves the structured request in the intake note');
foreach ([['referral_purpose'=>['New referral']],['requested_services'=>['Forged service']],['requested_services'=>[]],['requested_services'=>'Assessment'],['specific_needs'=>'Screen readers'],['specific_needs'=>[['nested']]]] as $bad) {
    $before=(int)$db->query('SELECT COUNT(*) FROM referrals')->fetchColumn();
    [$code,$out,$err]=$publicForm([],array_replace($baseReferral,$bad));
    check($code===0 && $err==='' && str_contains($out,'Please choose') && (int)$db->query('SELECT COUNT(*) FROM referrals')->fetchColumn()===$before,'Malformed structured referral input is rejected without saving or PHP errors');
}
// Retired non-AT services cannot be submitted from an old or crafted form.
foreach (['Counseling Request','Pre-ETS Request','Rehab Teaching Request','Independent Living Skills Request'] as $retired) {
    $old=$baseReferral;unset($old['referral_purpose'],$old['requested_services'],$old['specific_needs']);$old['note_type']=$retired;
    $before=(int)$db->query('SELECT COUNT(*) FROM referrals')->fetchColumn();
    [$code,$out,$err]=$publicForm([],$old);
    check($code===0&&$err===''&&str_contains($out,'assistive technology referrals only')&&(int)$db->query('SELECT COUNT(*) FROM referrals')->fetchColumn()===$before,'Retired non-AT referral is refused: '.$retired);
}
$before=(int)$db->query('SELECT COUNT(*) FROM referrals')->fetchColumn();
[$code,$out,$err]=$publicForm([],array_replace($baseReferral,['role'=>'school','guardian_name'=>'Guardian','guardian_phone'=>'555-0101','preets'=>['Job exploration counseling']]));
check($code===0&&$err===''&&str_contains($out,'assistive technology referrals only')&&(int)$db->query('SELECT COUNT(*) FROM referrals')->fetchColumn()===$before,'A stale school Pre-ETS selection is refused rather than silently filed as AT');
// A service withdrawn while the form was open is unticked and named in the message; the valid tick stays.
$before=(int)$db->query('SELECT COUNT(*) FROM referrals')->fetchColumn();
[$code,$out,$err]=$publicForm([],array_replace($baseReferral,['requested_services'=>['Assessment','Withdrawn synthetic service']]));
check($code===0 && $err==='' && str_contains($out,'no longer offered') && str_contains($out,'value="Assessment" checked') && (int)$db->query('SELECT COUNT(*) FROM referrals')->fetchColumn()===$before,
    'A withdrawn service is refused with a message saying it is no longer offered, keeping the valid service ticked');
[$code,$out,$err]=$publicForm([],array_replace($baseReferral,['body'=>'','specific_needs'=>['Screen readers','Device setup']]));
check($code===0 && str_contains($out,'value="Screen readers" checked') && str_contains($out,'value="Device setup" checked') && str_contains($out,'value="Additional service" selected'),'Validation errors retain the purpose and all selected needs');
[$code,$out,$err]=$publicForm(['lang'=>'es']);
check($code===0 && str_contains($out,'Motivo de la referencia') && str_contains($out,'Lectores de pantalla'),'The reorganized public form is translated into Spanish');
$legacy=$baseReferral;unset($legacy['referral_purpose'],$legacy['requested_service'],$legacy['requested_services'],$legacy['specific_needs']);$legacy['note_type']='Magnification Training Request';
[$code,$out,$err]=$publicForm([],$legacy);
$legacyRow=$db->query('SELECT * FROM referrals ORDER BY id DESC LIMIT 1')->fetch();
check($code===0 && $legacyRow['referral_purpose']===null && $legacyRow['note_type']==='Magnification Training Request','A referral form opened before the upgrade retains its original type');
[$code,$out,$err]=$renderPage('referrals',['view'=>$legacyRow['id']]);
check($code===0 && str_contains($out,'Original referral type') && str_contains($out,'Magnification Training Request'),'Historic referrals display their original type without guessing a purpose');
// A school referral for AT does not automatically become a Pre-ETS client.
$db->exec("DELETE FROM login_attempts WHERE email='referral-portal'");
[$code,$out,$err]=$publicForm([],array_replace($baseReferral,['role'=>'school','guardian_name'=>'Synthetic Guardian','guardian_phone'=>'555-0101','referral_purpose'=>'Plan amendment','requested_services'=>['Assessment'],'specific_needs'=>[]]));
$pre=(int)$db->query('SELECT MAX(id) FROM referrals')->fetchColumn();
[$code,$out,$err]=$renderPage('referrals',['view'=>$pre],['id'=>(string)$pre,'convert'=>'1']);
$preRow=$db->query("SELECT c.client_type,c.id FROM clients c JOIN referrals r ON r.client_id=c.id WHERE r.id=$pre")->fetch();
check($code===0 && ($preRow['client_type']??'')==='VR','Web conversion keeps school AT referrals out of the Pre-ETS workflow');
check(str_contains((string)$db->query('SELECT body FROM client_notes WHERE client_id='.(int)$preRow['id'].' ORDER BY id DESC LIMIT 1')->fetchColumn(),'Referral purpose: Plan amendment'),'Web conversion preserves structured request details');
$multi=$baseReferral;$multi['requested_services']=['Recommendation','Assessment','Demonstration'];
[$code,$out,$err]=$publicForm([],$multi);
$multiRow=$db->query('SELECT * FROM referrals ORDER BY id DESC LIMIT 1')->fetch();
check($code===0 && $err==='' && $multiRow['requested_service']==='Assessment; Demonstration; Recommendation' && $multiRow['note_type']==='Rehab Technology','A VR counselor can ask for several technology services; they are stored in catalog order as a Rehab Technology referral');
check(referral_is_preets(['referral_source'=>'school','note_type'=>'Initial Intake']) && !referral_is_preets(['referral_source'=>'school','referral_purpose'=>'New referral','requested_service'=>'Assistive technology training']) && !referral_is_preets(['referral_source'=>'counselor','referral_purpose'=>'New referral','requested_service'=>'Assessment']),'Historical school referrals retain their classification; new school and counselor AT requests are not Pre-ETS');
$db->exec("DELETE FROM login_attempts WHERE email='referral-portal'");

// The referrer gives a phone or an email: one is enough, none is refused.
$before=(int)$db->query('SELECT COUNT(*) FROM referrals')->fetchColumn();
[$code,$out,$err]=$publicForm([],array_replace($baseReferral,['referrer_phone'=>'','referrer_email'=>'']));
check($code===0 && $err==='' && str_contains($out,'Give at least one way to reach you.') && (int)$db->query('SELECT COUNT(*) FROM referrals')->fetchColumn()===$before,'A referral with neither phone nor email is refused');
[$code,$out,$err]=$publicForm([],array_replace($baseReferral,['referrer_email'=>'']));
$phoneOnly=$db->query('SELECT * FROM referrals ORDER BY id DESC LIMIT 1')->fetch();
check($code===0 && $err==='' && (int)$db->query('SELECT COUNT(*) FROM referrals')->fetchColumn()===$before+1 && (string)$phoneOnly['counselor_email']==='' && $phoneOnly['counselor_phone']==='555-0100','A referral with a phone and no email is saved and confirmed');
[$code,$out,$err]=$publicForm([],array_replace($baseReferral,['role'=>'self','referrer_email'=>'','income'=>'below_2000','vr_case'=>'no','referral_purpose'=>'New referral']));
$selfPhone=$db->query('SELECT * FROM referrals ORDER BY id DESC LIMIT 1')->fetch();
check($code===0 && $err==='' && $selfPhone['referral_source']==='self' && $selfPhone['client_email']===null,'A self-referral without an email stores no client email');
[$code,$out,$err]=$publicForm([],array_replace($baseReferral,['referrer_phone'=>'','referrer_email'=>'not-an-address']));
check($code===0 && str_contains($out,'enter one valid address'),'An email given without a phone must be a valid address');
[$code,$out,$err]=$publicForm(['lang'=>'es'],array_replace($baseReferral,['referrer_phone'=>'','referrer_email'=>'']));
check($code===0 && str_contains($out,'Indique al menos una forma de contactarle.'),'The phone-or-email message is in Spanish on the Spanish form');
// The language the form was sent in is stored on the referral and copied to the client on conversion.
[$code,$out,$err]=$publicForm(['lang'=>'es'],$baseReferral);
$esRef=$db->query('SELECT * FROM referrals ORDER BY id DESC LIMIT 1')->fetch();
check($code===0 && $err==='' && $esRef['preferred_language']==='es','A referral sent on the Spanish form records Spanish as the preferred language');
$db->exec("INSERT INTO clients(name,status) VALUES('Language Synthetic','Pending Assessment')"); $langClient=(int)$db->lastInsertId();
apply_referral_language($langClient,$esRef);
check($db->query("SELECT preferred_language FROM clients WHERE id=$langClient")->fetchColumn()==='es' && client_lang($langClient)==='es','apply_referral_language() copies the referral language to the client');
// The client's emails follow their language unless the English wording was customised.
[$esSubj]=email_template_for_client('portal_link',$langClient,['name'=>'X','link'=>'https://example.invalid/p']);
check(str_starts_with($esSubj,'Su portal'),'A Spanish-speaking client gets the Spanish portal invitation');
$db->exec("INSERT INTO settings(name,value) VALUES('tpl_portal_link_subject','Custom English subject') ON DUPLICATE KEY UPDATE value=VALUES(value)");
[$customSubj]=email_template_for_client('portal_link',$langClient,['name'=>'X','link'=>'https://example.invalid/p']);
check($customSubj==='Custom English subject','A customised English template is kept for every client');
$db->exec("DELETE FROM settings WHERE name='tpl_portal_link_subject'");
[$enSubj]=email_template_for_client('portal_link',['id'=>$langClient,'preferred_language'=>'en'],['name'=>'X','link'=>'https://example.invalid/p']);
check(str_starts_with($enSubj,'Your '),'An English-speaking client gets the English portal invitation');
check(when_es(mktime(14,0,0,3,3,2026))==='martes, 3 de marzo a las 2:00 p. m. '.date('T',mktime(14,0,0,3,3,2026)),'Spanish dates read in Spanish');
$db->exec("DELETE FROM clients WHERE id=$langClient");
$db->exec("DELETE FROM login_attempts WHERE email='referral-portal'");

// Records submitted by the earlier structured form retain an explicit Pre-ETS choice.
foreach (['New referral','Plan amendment'] as $oldPurpose) {
    $db->prepare("INSERT INTO referrals(reason,note_type,subject,body,counselor_name,counselor_agency,counselor_email,counselor_phone,client_name,referral_source,referral_purpose,requested_service) VALUES('Synthetic',?,'Historical Pre-ETS','Synthetic note','Referrer','Agency','referrer@example.invalid','555-0100','Historical student','counselor',?,'Pre-ETS')")
        ->execute([$oldPurpose==='Plan amendment'?'Plan Amendment':'Pre-ETS Request',$oldPurpose]);
    $oldId=(int)$db->lastInsertId();
    $call('convert_referral',['id'=>$oldId]);
    check($db->query("SELECT c.client_type FROM clients c JOIN referrals r ON r.client_id=c.id WHERE r.id=$oldId")->fetchColumn()==='Pre-ETS', 'Historical counselor Pre-ETS request retains its client type: '.$oldPurpose);
}

// Administrator-configured lists drive the public form, its validation and its Spanish labels.
$db->prepare("INSERT INTO settings (name, value) VALUES ('referral_form', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
    ->execute([json_encode(['purposes'=>[['en'=>'New referral','es'=>'Nueva referencia']],'services'=>[['en'=>'Braille display setup','es'=>'Configuración de pantalla braille'],['en'=>'Assessment','es'=>'Evaluación']],'needs'=>[],'roles'=>['counselor','self'],'min_services'=>1,'hints'=>['services'=>['en'=>'Pick the technology help you need.','es'=>'Elija la ayuda tecnológica que necesita.']]])]);
[$code,$out,$err]=$publicForm([],array_replace($baseReferral,['referral_purpose'=>'New referral','requested_services'=>['Braille display setup'],'specific_needs'=>[]]));
$custom=$db->query('SELECT * FROM referrals ORDER BY id DESC LIMIT 1')->fetch();
check($code===0 && $err==='' && $custom['requested_service']==='Braille display setup' && $custom['specific_needs']===null,'A custom service list is accepted and saved; an empty needs list hides that question');
[$code,$out,$err]=$publicForm([],array_replace($baseReferral,['referral_purpose'=>'New referral','requested_services'=>['Demonstration'],'specific_needs'=>[]]));
check($code===0 && str_contains($out,'Please choose at least one service') && (int)$db->query('SELECT COUNT(*) FROM referrals')->fetchColumn()===(int)$custom['id'],'A built-in service that the administrator removed is refused');
[$code,$out,$err]=$publicForm([],array_replace($baseReferral,['role'=>'medical','referral_purpose'=>'New referral','requested_services'=>['Assessment'],'specific_needs'=>[]]));
check($code===0 && str_contains($out,'I am referring as a…: please choose the one that fits best') && !str_contains($out,'value="medical"'),'A referrer kind the administrator switched off is neither offered nor accepted');
[$code,$out,$err]=$publicForm(['lang'=>'es']);
check($code===0 && str_contains($out,'Configuración de pantalla braille') && str_contains($out,'Elija la ayuda tecnológica') && !str_contains($out,'Necesidades específicas'),'Administrator Spanish labels and hints appear on the translated form');
[$code,$out,$err]=$renderPage('referral_settings',[]);
check($code===0 && $err==='' && str_contains($out,'Braille display setup | Configuración de pantalla braille') && str_contains($out,'customized'),'The referral form settings page shows the saved lists');
check(str_contains($out,'id="role_counselor" value="counselor" checked') && str_contains($out,'id="role_self" value="self" checked') && !str_contains($out,'id="role_medical" value="medical" checked'),
    'The referral form settings page ticks only the referrer kinds the administrator kept');
$db->exec("DELETE FROM settings WHERE name='referral_form'");
[$code,$out,$err]=$renderPage('referral_settings',[]);
check($code===0 && $err==='' && count(array_filter(REFERRAL_ROLE_KEYS,fn($key)=>str_contains($out,'id="role_'.$key.'" value="'.$key.'" checked')))===7,
    'With the built-in form, the referral form settings page ticks all seven referrer kinds' . ($err ? ": $err" : ''));
// A renamed built-in purpose keeps its meaning: 'Plan change', in the place of
// 'Plan amendment', still files the referral as a Plan Amendment. Checked in a
// fresh process, as the form settings are read once per request.
$db->prepare("INSERT INTO settings (name, value) VALUES ('referral_form', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
    ->execute([json_encode(['purposes'=>[['en'=>'New referral'],['en'=>'Additional service'],['en'=>'Plan change']]])]);
file_put_contents($tmp.'/note_type.php','<?php require '.var_export($tmp.'/core.php',true).'; echo referral_request_note_type(\'Plan change\', \'Assessment\');');
$p=proc_open([PHP_BINARY,'-d','display_errors=stderr',$tmp.'/note_type.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
$noteType=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
check($code===0 && $err==='' && $noteType==='Plan Amendment','A built-in purpose the administrator renamed in place keeps its referral type' . ($err ? ": $err" : ''));
$db->exec("DELETE FROM settings WHERE name='referral_form'");
$db->exec("DELETE FROM login_attempts WHERE email='referral-portal'");
