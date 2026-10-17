<?php
// Included by full_schema.php after v43_cases.php, against its synthetic database only:
// 4.4.0's Lists and time limits page: form lists and the days and percent behind reminders.

/* Built-in values first */
check(cms_list_items('vision_statuses')===VISION_STATUSES && cms_list('score_labels')===SCORE_LABELS_DEFAULT && cms_number('auth_warn_pct')===80 && cms_days('auth_expiry_alert_days')===[7,14,30],'Every list and time limit starts as before');
[$code,$out,$err]=$renderPage('lists',[]);
check($code===0 && $err==='' && str_contains($out,'Lists and time limits') && str_contains($out,'Vision statuses') && str_contains($out,'Alert this many days before an authorization ends'),'Administrators see the lists and time limits' . ($err ? ": $err" : ''));

/* Lists */
[$code,,$err]=$renderPage('lists',[],['save_lists'=>'clients','client_types'=>implode("\n",CLIENT_TYPES),'note_types'=>implode("\n",NOTE_TYPES),'doc_categories'=>implode("\n",DOC_CATEGORIES),'vision_statuses'=>"Blind\nLow vision\nDeafblind",'closure_reasons'=>implode("\n",CLOSURE_REASONS),
    'referral_close_reasons'=>"Declined services | the person decided not to go ahead\nMoved | the person moved away"]);
check($code===0 && $err==='' && cms_list_items('vision_statuses')===['Blind','Low vision','Deafblind'] && cms_list('referral_close_reasons')['Moved']==='the person moved away'
    && (string)(setting('list_closure_reasons') ?? '')==='','A business sets its own lists; one left as built in is not stored' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('lists',[],['save_lists'=>'clients','client_types'=>implode("\n",CLIENT_TYPES),'note_types'=>implode("\n",NOTE_TYPES),'doc_categories'=>implode("\n",DOC_CATEGORIES),'vision_statuses'=>"Blind\nBlind",'closure_reasons'=>'Done','referral_close_reasons'=>'No words here']);
check(cms_list_items('vision_statuses')===['Blind','Low vision','Deafblind'] && (string)(setting('list_closure_reasons') ?? '')==='','A list with a repeat, or a reason without its words, is refused and nothing in the group changes');
[$code,,$err]=$renderPage('lists',[],['save_lists'=>'services','service_types'=>implode("\n",SERVICE_TYPES),'tech_types'=>implode("\n",TECH_TYPES),'score_labels'=>"None\nSome\nHalf\nMost",'competency_levels'=>implode("\n",COMPETENCY_LEVELS),'incident_types'=>implode("\n",INCIDENT_TYPES),'booking_types'=>"Interpreter\nReader"]);
check(cms_list('score_labels')===SCORE_LABELS_DEFAULT && cms_list_items('booking_types')===BOOKING_TYPES,'A 0 to 4 scale needs exactly five levels, and nothing else in its group is saved');
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Lists Synthetic Client','Active Training',1)"); $lsc=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('client_edit',['id'=>$lsc],['name'=>'Lists Synthetic Client','status'=>'Active Training','client_type'=>'VR','vision_status'=>'Deafblind']);
check($db->query("SELECT vision_status FROM clients WHERE id=$lsc")->fetchColumn()==='Deafblind','A client record takes a choice from the business\'s own list' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('lists',[],['save_lists'=>'clients','client_types'=>implode("\n",CLIENT_TYPES),'note_types'=>implode("\n",NOTE_TYPES),'doc_categories'=>implode("\n",DOC_CATEGORIES),'vision_statuses_reset'=>'1','vision_statuses'=>'','closure_reasons'=>implode("\n",CLOSURE_REASONS),'referral_close_reasons_reset'=>'1','referral_close_reasons'=>'']);
check(cms_list_items('vision_statuses')===VISION_STATUSES && $db->query("SELECT vision_status FROM clients WHERE id=$lsc")->fetchColumn()==='Deafblind','Going back to the built-in list keeps the values records already have');
[$code,,$err]=$renderPage('client_edit',['id'=>$lsc],['name'=>'Lists Synthetic Client','status'=>'Active Training','client_type'=>'VR','vision_status'=>'Deafblind','phone'=>'555-0101']);
check($db->query("SELECT vision_status FROM clients WHERE id=$lsc")->fetchColumn()==='Deafblind','Saving such a client again keeps a vision status since removed from the list' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('client_edit',['id'=>$lsc]);
check(str_contains($out,'value="Deafblind" selected'),'The edit form still shows the removed value as chosen');

/* Time limits */
[$code,,$err]=$renderPage('lists',[],['save_lists'=>'times','auth_warn_pct'=>'90','auth_soon_days'=>'45','loan_due_days'=>'','counselor_link_days'=>'','sign_link_days'=>'','consent_valid_days'=>'730','release_valid_days'=>'','auth_expiry_alert_days'=>'30, 5, 60','closure_followup_days'=>'','device_followup_days'=>'14,28']);
check($code===0 && $err==='' && cms_number('auth_warn_pct')===90 && cms_number('auth_soon_days')===45 && cms_days('auth_expiry_alert_days')===[5,30,60] && cms_days('device_followup_days')===[14,28]
    && setting('consent_validity_days')==='730' && cms_number('loan_due_days')===60,'A business sets its own time limits; consent validity keeps its old setting name' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('lists',[],['save_lists'=>'times','auth_warn_pct'=>'150','auth_expiry_alert_days'=>'soon']);
check(cms_number('auth_warn_pct')===90 && cms_days('auth_expiry_alert_days')===[5,30,60],'A number out of range, or days that are not numbers, are refused');
[$code,$out,$err]=$renderPage('compliance',[]);
check($code===0 && str_contains($out,'at 90%+') && str_contains($out,'within 45 days'),'Compliance says the business\'s own warning and window' . ($err ? ": $err" : ''));
$renderPage('lists',[],['save_lists'=>'times']);
check(cms_number('auth_warn_pct')===80 && cms_days('device_followup_days')===[30,60,90] && (string)(setting('consent_validity_days') ?? '')==='','Empty boxes go back to the built-in values');

/* The daily alerts follow them */
set_setting('days_auth_expiry_alert_days','5, 30, 60'); set_setting('num_auth_warn_pct','70');
$db->exec("INSERT INTO clients(name,status,assigned_to) VALUES('Alert Days Client','Active Training',1)"); $adc=(int)$db->lastInsertId();
$db->prepare("INSERT INTO training_tracks(client_id,title,status,auth_number,funding_agency,authorized_hours,hourly_rate,start_date,expiration_date) VALUES(?,'Alert days track','Active','AD-1','Synthetic VR',100,50,DATE_SUB(CURDATE(),INTERVAL 10 DAY),DATE_ADD(CURDATE(),INTERVAL 50 DAY))")->execute([$adc]); $adt=(int)$db->lastInsertId();
$db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description,created_by) VALUES(?,CURDATE(),72,'Synthetic usage',1)")->execute([$adt]);
$cronRun('daily_alerts.php',true);
$marks=$db->query("SELECT GROUP_CONCAT(alert_key ORDER BY alert_key) FROM sent_alerts WHERE track_id=$adt")->fetchColumn();
check(str_contains((string)$marks,'exp60') && str_contains((string)$marks,'pct80'),'With custom days and percent, the 60-day and usage alerts are sent and marked');
$cronRun('daily_alerts.php',true);
check($db->query("SELECT GROUP_CONCAT(alert_key ORDER BY alert_key) FROM sent_alerts WHERE track_id=$adt")->fetchColumn()===$marks && (int)$db->query("SELECT COUNT(*) FROM sent_alerts WHERE track_id=$adt AND alert_key='pct80'")->fetchColumn()===1,'The next day nothing is sent again (72% stays above the clearing line of 65%)');
set_setting('days_auth_expiry_alert_days',''); set_setting('num_auth_warn_pct','');
