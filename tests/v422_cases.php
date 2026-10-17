<?php
// Included by full_schema.php after v421_cases.php, against its synthetic database only:
// 4.22.0: offices in one company (inc/offices.php).

check(!offices_on() && office_input('1')===null && office_zone_for(['office_id'=>1])===null,'With no offices, nothing about offices applies');
[$code,$html,$err]=$renderPage('clients',[]);
check(!str_contains($html,'id="office"'),'With no offices the client list has no office filter'.($err?": $err":''));
[$code,$csv,$err]=$renderPage('clients',['who'=>'','export'=>'csv']);
check(str_contains($csv,'Active tracks') && !str_contains($csv,'Office'),'With no offices the client download has no Office column'.($err?": $err":''));
[$code,,$err]=$renderPage('offices',[],['save_office'=>'1','office_id'=>'0','name'=>'Synthetic North Office','address'=>'1 Synthetic Way','phone'=>'555-0100','time_zone'=>'America/Denver']);
[$code,,$err2]=$renderPage('offices',[],['save_office'=>'1','office_id'=>'0','name'=>'Synthetic South Office','address'=>'','phone'=>'','time_zone'=>'']);
offices_all(false,true);
$north=(int)$db->query("SELECT id FROM offices WHERE name='Synthetic North Office'")->fetchColumn();
$south=(int)$db->query("SELECT id FROM offices WHERE name='Synthetic South Office'")->fetchColumn();
check($north>0 && $south>0 && offices_on() && office_get($north)['time_zone']==='America/Denver' && office_get($south)['time_zone']===null,'Administrators add offices with an address, phone and time zone'.($err.$err2?": $err $err2":''));
[$code,,$err]=$renderPage('offices',[],['save_office'=>'1','office_id'=>'0','name'=>'Synthetic North Office','address'=>'','phone'=>'','time_zone'=>'']);
check((int)$db->query("SELECT COUNT(*) FROM offices WHERE name='Synthetic North Office'")->fetchColumn()===1,'Two offices cannot share a name');
[$code,,$err]=$renderPage('offices',[],['save_office'=>'1','office_id'=>'0','name'=>'Synthetic Moon Office','address'=>'','phone'=>'','time_zone'=>'Mars/Base']);
check(!$db->query("SELECT id FROM offices WHERE name='Synthetic Moon Office'")->fetchColumn(),'An office\'s time zone must be one from the list');

// Staff and clients are placed in an office.
$db->prepare('UPDATE users SET office_id=? WHERE id=1')->execute([$north]);
$base=['name'=>'Office Synthetic Client','status'=>'Active Training','client_type'=>'VR','state'=>'IA'];
[$code,,$err]=$renderPage('client_edit',[],$base);
$oc=$db->query("SELECT id,office_id FROM clients WHERE name='Office Synthetic Client'")->fetch();
check($oc && (int)$oc['office_id']===$north,'A new client starts in the office of the person adding them'.($err?": $err":''));
$oc=(int)$oc['id'];
[$code,,$err]=$renderPage('client_edit',['id'=>$oc],['office_id'=>(string)$south]+$base);
check((int)$db->query("SELECT office_id FROM clients WHERE id=$oc")->fetchColumn()===$south,'A client is moved to another office on their edit page'.($err?": $err":''));
[$code,$html,$err]=$renderPage('client',['id'=>$oc]);
check(str_contains($html,'Synthetic South Office'),'The client page names their office'.($err?": $err":''));
[$code,,$err]=$renderPage('client_edit',['id'=>$oc],['office_id'=>'999999']+$base);
check((int)$db->query("SELECT office_id FROM clients WHERE id=$oc")->fetchColumn()===$south,'An office that does not exist is refused, and the stored one kept');

// Time zones: the client's own, then their state's, then their office's.
$db->prepare('UPDATE clients SET office_id=? WHERE id=?')->execute([$north,$oc]);
check(client_zone(['state'=>null,'time_zone'=>null,'office_id'=>$north])==='America/Denver' && client_zone(['state'=>'AZ','time_zone'=>null,'office_id'=>$north])==='America/Phoenix'
    && client_zone(['state'=>null,'time_zone'=>'America/New_York','office_id'=>$north])==='America/New_York' && client_zone(['client_state'=>null,'client_time_zone'=>null,'client_office_id'=>$north])==='America/Denver' && client_zone(['state'=>null,'time_zone'=>null,'office_id'=>$south])===null,'A client with no state of their own follows their office\'s time zone');

// A closed office stays on the records it is on, but is not newly chosen.
[$code,,$err]=$renderPage('offices',[],['set_active'=>(string)$north,'active'=>'0']);
offices_all(false,true);
check((int)office_get($north)['is_active']===0 && office_input((string)$north)===null && office_input((string)$north,$north)===$north,'A closed office can no longer be chosen'.($err?": $err":''));
[$code,,$err]=$renderPage('client_edit',['id'=>$oc],['office_id'=>(string)$north]+$base);
check((int)$db->query("SELECT office_id FROM clients WHERE id=$oc")->fetchColumn()===$north,'Saving a client still in a closed office keeps it'.($err?": $err":''));
$db->prepare('UPDATE clients SET office_id=? WHERE id=?')->execute([$south,$oc]);
[$code,$html,$err]=$renderPage('client_edit',['id'=>$oc],['office_id'=>(string)$north]+$base);
check((int)$db->query("SELECT office_id FROM clients WHERE id=$oc")->fetchColumn()===$south,'Choosing an office closed since the form opened changes nothing');
$db->prepare('UPDATE users SET office_id=? WHERE id=1')->execute([$north]);
[$code,,$err]=$renderPage('client_edit',[],['name'=>'Closed Office Synthetic Client']+$base);
check($db->query("SELECT office_id FROM clients WHERE name='Closed Office Synthetic Client'")->fetchColumn()===null,'A new client is not put in the creator\'s office once it is closed'.($err?": $err":''));
$db->prepare('UPDATE clients SET office_id=? WHERE id=?')->execute([$north,$oc]);
[$code,,$err]=$renderPage('offices',[],['set_active'=>(string)$north,'active'=>'1']);
offices_all(false,true);

// Filters and the download.
$db->exec("INSERT INTO clients(name,status,assigned_to,state,office_id) VALUES('Southern Synthetic Client','Active Training',1,'IA',$south)");
[$code,$html,$err]=$renderPage('clients',['office'=>(string)$north,'who'=>'']);
check(str_contains($html,'Office Synthetic Client') && !str_contains($html,'Southern Synthetic Client') && str_contains($html,'id="office"'),'The client list shows one office'.($err?": $err":''));
[$code,$csv,$err]=$renderPage('clients',['office'=>(string)$south,'who'=>'','export'=>'csv']);
check(str_contains($csv,'Office') && str_contains($csv,'Southern Synthetic Client') && str_contains($csv,'Synthetic South Office') && !str_contains($csv,'Office Synthetic Client'),'The client download has the office, and keeps the filter'.($err?": $err":''));
[$code,$html,$err]=$renderPage('schedule',['office'=>(string)$south]);
check(str_contains($html,'id="up_office"'),'The upcoming sessions list can be filtered by office'.($err?": $err":''));

// Staff office.
$su=$db->query("SELECT * FROM users WHERE id=1")->fetch();
[$code,,$err]=$renderPage('users',[],['save_user'=>'1','uid'=>'1','name'=>$su['name'],'email'=>$su['email'],'role'=>$su['role'],'group_id'=>(string)($su['group_id']??''),'weekly_hours'=>(string)($su['weekly_hours']??''),'is_active'=>'1','office_id'=>(string)$south]);
check((int)$db->query("SELECT office_id FROM users WHERE id=1")->fetchColumn()===$south,'Staff are placed in an office on their user page'.($err?": $err":''));

// The phone apps.
$r=$call('client',[],['id'=>$oc]);
check($r['client']['office_id']===$north && $r['client']['office']==='Synthetic North Office','The apps get the client\'s office');
$call('save_client',['id'=>$oc,'name'=>'Office Synthetic Client','status'=>'Active Training','office_id'=>$south]);
check((int)$db->query("SELECT office_id FROM clients WHERE id=$oc")->fetchColumn()===$south,'The apps can move a client to another office');
$call('save_client',['id'=>$oc,'name'=>'Office Synthetic Client','status'=>'Active Training']);
check((int)$db->query("SELECT office_id FROM clients WHERE id=$oc")->fetchColumn()===$south,'An app that sends no office keeps the stored one');
$call('save_client',['id'=>$oc,'name'=>'Office Synthetic Client','status'=>'Active Training','office_id'=>999999],[],false,'office');
$call('save_client',['id'=>$oc,'name'=>'Office Synthetic Client','status'=>'Active Training','office_id'=>'0']);
check($db->query("SELECT office_id FROM clients WHERE id=$oc")->fetchColumn()===null,'An office of "0" means none, as 0 does');
$call('save_client',['id'=>$oc,'name'=>'Office Synthetic Client','status'=>'Active Training','office_id'=>null]);
check($db->query("SELECT office_id FROM clients WHERE id=$oc")->fetchColumn()===null,'The apps can take a client out of any office');
// Every other way a client is added starts them in the creator's office; a merge brings it over.
$db->prepare('UPDATE users SET office_id=? WHERE id=1')->execute([$south]);
$db->exec("INSERT INTO clients(name,status) VALUES('Placed Synthetic Client','Pending Assessment')"); $pc=(int)$db->lastInsertId();
office_place_new_client($pc,1);
check((int)$db->query("SELECT office_id FROM clients WHERE id=$pc")->fetchColumn()===$south,'A client added by referral, import or voice starts in the creator\'s office');
check(merge_fillable(['office_id'=>null],['office_id'=>$south])===['office_id'=>$south],'Merging duplicates can bring the office over');
$db->exec('UPDATE users SET office_id=NULL WHERE id=1');
