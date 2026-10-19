<?php
// Included by full_schema.php after v426_cases.php, against its synthetic database only:
// 4.27.0: staff pages (imports, custom fields, search, the client list's remembered filter).

// ---- Authorizations: one number in two letter cases is one authorization ----
$db->exec("INSERT INTO clients(name,status,client_type,state) VALUES('V427 Import Synthetic Client','Active Training','VR','IA')"); $v427c=(int)$db->lastInsertId();
$v427a=$tmp.'/v427_auth_import.csv';
file_put_contents($v427a,"client_name,auth_number,funding_agency,authorized_hours,hourly_rate\nV427 Import Synthetic Client,AUTH-427-1,State VR,10,50\nV427 Import Synthetic Client, auth-427-1 ,State VR,10,50\n");
putenv('CMS_TEST_FILES='.json_encode(['csv'=>$v427a])); putenv('CMS_TEST_SESSION_ADD='.json_encode(['client_import_tokens'=>['tok427a']]));
[$code,$out,$err]=$renderPage('client_import',[],['do_import'=>'1','kind'=>'authorizations','import_token'=>'tok427a']);
check((int)$db->query("SELECT COUNT(*) FROM training_tracks WHERE LOWER(TRIM(auth_number))='auth-427-1'")->fetchColumn()===1 && str_contains($out,'already on row 2 of this file'),
    'One import file cannot put the same authorization (in another letter case) on two tracks'.($err?": $err":''));

// ---- Clients: a second row with the same email is the same person ----
$v427c2=$tmp.'/v427_client_import.csv';
file_put_contents($v427c2,"name,email,client_type\nV427 First Synthetic,v427-same@example.test,VR\nV427 Second Synthetic,V427-same@example.test,VR\n");
putenv('CMS_TEST_FILES='.json_encode(['csv'=>$v427c2])); putenv('CMS_TEST_SESSION_ADD');
[$code,$out,$err]=$renderPage('client_import',[],['do_import'=>'1','kind'=>'clients','dry_run'=>'1']);
check($code===0 && str_contains($out,'Same person as row 2 of this file') && str_contains($out,'1 row(s) would import'),
    'A client import skips a row with the same email as an earlier row of the file'.($err?": $err":''));
putenv('CMS_TEST_FILES');

// ---- Custom fields: a type change that would not fit the answers given is refused ----
[$code,,$err]=$renderPage('custom_fields',[],['save_field'=>'1','field_id'=>'0','label'=>'V427 synthetic text field','type'=>'text','sort_order'=>'5']);
custom_fields_all(false,true);
$v427f=(int)$db->query("SELECT id FROM custom_fields WHERE label='V427 synthetic text field'")->fetchColumn();
$db->prepare('INSERT INTO custom_values (field_id, client_id, value) VALUES (?,?,?)')->execute([$v427f,$v427c,'next spring']);
[$code,,$err]=$renderPage('custom_fields',[],['save_field'=>'1','field_id'=>(string)$v427f,'label'=>'V427 synthetic text field','type'=>'date','sort_order'=>'5','is_active'=>'1']);
check($v427f>0 && $db->query("SELECT type FROM custom_fields WHERE id=$v427f")->fetchColumn()==='text'
    && $db->query("SELECT value FROM custom_values WHERE field_id=$v427f AND client_id=$v427c")->fetchColumn()==='next spring',
    'A field\'s type cannot change to one its answers do not fit, and the answers are kept'.($err?": $err":''));
check(custom_field_misfits(['type'=>'date','options'=>null],$v427f)===1 && custom_field_misfits(['type'=>'textarea','options'=>null],$v427f)===0,'The answers that would not fit a new type are counted');
[$vals,$errs]=custom_input(['cf_'.$v427f=>'next spring'],[['id'=>$v427f,'type'=>'date','required'=>0,'label'=>'V427 synthetic text field','label_es'=>'','options'=>null]],false,[$v427f=>'next spring']);
check($errs===[] && $vals[$v427f]==='next spring','An answer sent back unchanged is kept even when it no longer fits the type');
// A field made a date before this release, with a text answer: the edit page shows the answer rather than a blank date box.
$db->exec("UPDATE custom_fields SET type='date' WHERE id=$v427f");
[$code,$html,$err]=$renderPage('client_edit',['id'=>(string)$v427c]);
check(str_contains($html,'id="cf_'.$v427f.'" name="cf_'.$v427f.'" maxlength="255" value="next spring"'),'A saved answer that is not a date is shown, not blanked by a date box'.($err?": $err":''));
$db->exec("DELETE FROM custom_values WHERE field_id=$v427f"); $db->exec("DELETE FROM custom_fields WHERE id=$v427f"); custom_fields_all(false,true);

// ---- Search: underscores are text, not wildcards ----
$db->exec("INSERT INTO clients(name,status,client_type,state) VALUES('V427 Search Synthetic','Active Training','VR','IA')");
[$code,$html,$err]=$renderPage('search',['q'=>'__']);
check($code===0 && !str_contains($html,'V427 Search Synthetic') && !str_contains($html,'V427 Import Synthetic Client'),'A search for two underscores matches only names with underscores'.($err?": $err":''));
[$code,$html,$err]=$renderPage('search',['q'=>'V427 Search']);
check(str_contains($html,'V427 Search Synthetic'),'A plain search still finds the client'.($err?": $err":''));

// ---- The client list remembers a caseload choice only when the filter form is sent ----
set_setting('clients_filter_1','');
[$code,,$err]=$renderPage('clients',['who'=>'unassigned']);
check(setting('clients_filter_1')==='','A link to the client list with a caseload does not change the remembered choice'.($err?": $err":''));
[$code,,$err]=$renderPage('clients',['who'=>'unassigned','save'=>'1']);
check(setting('clients_filter_1')==='','A GET link with save=1 does not change it either (4.28.0)'.($err?": $err":''));
[$code,,$err]=$renderPage('clients',[],['filter_clients'=>'1','who'=>'unassigned']);
check(setting('clients_filter_1')==='unassigned','Sending the filter form remembers the choice'.($err?": $err":''));
set_setting('clients_filter_1','');
