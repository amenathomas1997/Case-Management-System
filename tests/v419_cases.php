<?php
// Included by full_schema.php after v418_cases.php, against its synthetic database only:
// 4.19.0: the client's state is asked for at intake, and page errors are recorded for the watchdog.

// The state is required on the client form, the referral form and from an updated app.
$before=(int)$db->query("SELECT COUNT(*) FROM clients")->fetchColumn();
[$code,,$err]=$renderPage('client_edit',[],['name'=>'Stateless Synthetic Client','status'=>'Pending Assessment','client_type'=>'VR','state'=>'']);
check((int)$db->query("SELECT COUNT(*) FROM clients")->fetchColumn()===$before,'A new client with no state is not saved'.($err?": $err":''));
[$code,,$err]=$renderPage('client_edit',[],['name'=>'Stated Synthetic Client','status'=>'Pending Assessment','client_type'=>'VR','state'=>'HI']);
check($db->query("SELECT state FROM clients WHERE name='Stated Synthetic Client'")->fetchColumn()==='HI','With a state, the client is saved with it'.($err?": $err":''));
$db->exec("INSERT INTO clients(name,status,client_type) VALUES('Legacy Stateless Synthetic','Active Training','VR')"); $leg=(int)$db->lastInsertId();
[$code,,$err]=$renderPage('client_edit',['id'=>(string)$leg],['name'=>'Legacy Stateless Synthetic','status'=>'Active Training','client_type'=>'VR','phone'=>'515-555-0177','state'=>'']);
check($db->query("SELECT phone FROM clients WHERE id=$leg")->fetchColumn()==='515-555-0177','An older client with no state can still be saved (Data quality asks for it)'.($err?": $err":''));
$db->exec("UPDATE clients SET state='IA' WHERE id=$leg");
[$code,,$err]=$renderPage('client_edit',['id'=>(string)$leg],['name'=>'Legacy Stateless Synthetic','status'=>'Active Training','client_type'=>'VR','phone'=>'515-555-0178','state'=>'']);
check($db->query("SELECT phone FROM clients WHERE id=$leg")->fetchColumn()==='515-555-0177','A known state cannot be emptied');
$refBefore=(int)$db->query("SELECT COUNT(*) FROM referrals")->fetchColumn();
putenv('CMS_TEST_ANON=1');
[$code,$html,$err]=$renderPage('community_referral',[],['role'=>'self','referrer_name'=>'Synthetic Self Referrer','referrer_phone'=>'515-555-0123','body'=>'Synthetic need','client_state'=>'']);
check((int)$db->query("SELECT COUNT(*) FROM referrals")->fetchColumn()===$refBefore,'A referral with no state is not taken'.($err?": $err":''));
[$code,$html,$err]=$renderPage('community_referral',[],['role'=>'self','referrer_name'=>'Synthetic Stated Self Referrer','referrer_phone'=>'515-555-0124','body'=>'Synthetic need','client_state'=>'NM','income'=>'below_2000','vr_case'=>'no','requested_services'=>[referral_options('services')[0]]]);
putenv('CMS_TEST_ANON');
$selfRef=$db->query("SELECT client_state FROM referrals WHERE client_name='Synthetic Stated Self Referrer'")->fetchColumn();
if ($selfRef!=='NM') { preg_match_all('~class="(?:field-)?error[^"]*"[^>]*>(.*?)</~s',$html,$em); fwrite(STDERR,'SELFREF ERRORS: '.implode(' | ',array_map('strip_tags',$em[1])).PHP_EOL); }
check($selfRef==='NM','A self-referral with its state is taken, state and all'.($err?": $err":''));
$call('save_client',['name'=>'App Synthetic Client','status'=>'Pending Assessment','state'=>''],[],false,'Choose the state');
$r=$call('save_client',['name'=>'App Synthetic Client','status'=>'Pending Assessment','state'=>'AK']);
$d=$call('client',[],['id'=>(string)$r['client_id']]);
check($d['client']['state']==='AK' && array_key_exists('time_zone',$d['client']),'The apps send and read the state');
$r2=$call('save_client',['name'=>'Old App Synthetic Client','status'=>'Pending Assessment']);
check($r2['ok']===true,'An older app that sends no state still saves');
$dq=data_quality_checks(null);
$missing=array_values(array_filter($dq,fn($c)=>$c[0]==='missing'))[0][3];
check((bool)array_filter($missing,fn($m)=>$m['name']==='Old App Synthetic Client' && str_contains($m['detail'],'state')),'Data quality lists current clients with no state');

// Page errors are recorded (kind and place only) and the watchdog tells the administrators.
@unlink((string)app_error_log_path());
@unlink((string)app_error_log_path().'.lock');
app_error_record('PDOException','/x/clients.php',120);
app_error_record('PDOException','/x/clients.php',120);
app_error_record('Error','/x/invoices.php',88);
$errs=app_errors_since(time()-60);
check(count($errs)===2 && $errs[0]['where']==='clients.php:120' && count(app_errors_summary($errs))===2,'Errors are recorded by kind and place; the same one in the same place counts once a minute, so a flood cannot bury the rest');
check(app_errors_since(time()+60)===[],'Only errors since the last alert are told');
// The watchdog's cursor: told once, then quiet for the hour; nothing is lost.
$db->exec("DELETE FROM settings WHERE name IN ('errors_cursor','errors_alerted_at')");
$due=app_errors_due(time());
check($due!==null && count($due[1])===2,'New errors are due to be told');
app_errors_told($due[0]);
check(app_errors_due(time())===null,'Once told, nothing more is sent for an hour');
set_setting('errors_alerted_at',(string)(time()-3700));
check(app_errors_due(time()+5)===null,'After the hour, only errors since the last alert would be told (none here)');
[$code,$html,$err]=$renderPage('system_health',[]);
check(str_contains($html,'Page and job errors in the last 7 days (2)') && str_contains($html,'clients.php:120'),'System health lists them'.($err?": $err":''));
$wd=(string)file_get_contents($root.'/cron/watchdog.php');
check(str_contains($wd,'app_errors_due(time())') && str_contains($wd,'app_errors_told($upTo)'),'The watchdog emails new errors at most once an hour, moving on only once the email went');
@unlink((string)app_error_log_path());

// A site with update checks off is told how to turn them on, not that a server could not be reached.
[[$code,,$err],$usess]=$pageSession(fn()=>$renderPage('admin',[],['check_updates'=>'1']));
check(str_contains($usess,'Checking for updates is off') && !str_contains($usess,'could not be reached'),'Check for updates says when checking is off, and how to turn it on'.($err?": $err":''));

// The installer's cron lines run each job when the job itself says it runs (an off-site copy every night, not weekly).
preg_match_all('~<code>([0-9*/,]+ [0-9*/,]+ [0-9*/,]+ [0-9*/,]+ [0-9*/,]+) (?:php )?<\?= htmlspecialchars\(\$cronDir\) \?>/([a-z_]+\.(?:php|sh))</code>~', (string)file_get_contents($root.'/dist/install.php'), $cl, PREG_SET_ORDER);
$cronWrong=[];
foreach ($cl as [, $when, $script]) {
    if (preg_match('/Cron: (\S+ \S+ \S+ \S+ \S+)/', (string)file_get_contents($root.'/cron/'.$script), $hm) && trim($hm[1], ' (') !== $when) $cronWrong[]="$script ($when, the job says {$hm[1]})";
}
check(count($cl)>=8 && $cronWrong===[],'The installer\'s cron lines match each job\'s own schedule'.($cronWrong?': '.implode(', ',$cronWrong):''));
check(str_contains((string)file_get_contents($root.'/dist/make_dist.sh'),'cp dist/restore.php "$STAGE/restore.php"'),'The install bundle carries restore.php, which the runbook and Help rely on');
// The referral form asks every referrer, self-referrers included, for the state.
putenv('CMS_TEST_ANON=1');
[$code,$html,$err]=$renderPage('community_referral',[]);
putenv('CMS_TEST_ANON');
check((bool)preg_match('~</fieldset>\s*<\?php|<div class="field">\s*<label for="client_state">~',$html) && !preg_match('~data-role="[^"]*"[^>]*>(?:(?!</fieldset>).)*id="client_state"~s',$html) && str_contains($html,'id="client_state" name="client_state" required'),'The state is asked of every referrer, not inside a section hidden from self-referrers');

// The organisation's main time zone: its own place on Settings, named on System health, and on the update check.
[$code,$html,$err]=$renderPage('settings',[]);
check(str_contains($html,'Time zone') && str_contains($html,'main time zone') && str_contains($html,'>Central<'),'Settings has a Time zone section, with the zones by name'.($err?": $err":''));
set_setting('update_checked','2026-10-17T14:30:00-05:00');
check(update_checked_words()==='October 17, 2026 at 2:30 pm CDT','When updates were last checked is shown in the time zone, with its name');
[$code,$html,$err]=$renderPage('system_health',[]);
check(str_contains($html,'<dt>Time zone</dt>') && str_contains($html,'Central (C'),'System health names the time zone in use'.($err?": $err":''));

// 4.19.1: a site that never chose an update source checks GitHub; an old check is never shown as current.
$savedUrl=$GLOBALS['CONFIG']['app']['update_url'] ?? null;
unset($GLOBALS['CONFIG']['app']['update_url']);
check(update_source()==='github','A site that never chose an update source checks GitHub releases');
$GLOBALS['CONFIG']['app']['update_url']='off';
check(update_source()==='off','Only an explicit off turns checking off');
if ($savedUrl===null) unset($GLOBALS['CONFIG']['app']['update_url']); else $GLOBALS['CONFIG']['app']['update_url']=$savedUrl;
set_setting('update_latest','3.0.55'); set_setting('update_checked','2026-10-16 17:23');
$db->exec("INSERT INTO settings(name,value) VALUES('cfg.app.update_url','github') ON DUPLICATE KEY UPDATE value='github'");
[$code,$html,$err]=$renderPage('admin',[]);
check(str_contains($html,'Not checked since this version was installed') && !str_contains($html,'Up to date (last checked October 16'),'A check from before this version was installed is not shown as current'.($err?": $err":''));
set_setting('update_attempted',date('c'));
[$code,$html,$err]=$renderPage('admin',[]);
check(str_contains($html,'could not reach the update server'),'A failed check is said, with its time');
$db->exec("DELETE FROM settings WHERE name IN ('cfg.app.update_url','update_latest','update_checked','update_attempted')");
// An older site's cron folder (no cron_config.php, the jobs find the app themselves) is still updated.
$od=$tmp.'/old_layout'; @mkdir("$od/cron",0777,true); file_put_contents("$od/cron/watchdog.php",'<?php');
$sp=$GLOBALS['CMS_PRIVATE_DIR'] ?? null; $GLOBALS['CMS_PRIVATE_DIR']=$od;
check(updater_cron_dir()==="$od/cron",'The updater finds an older site\'s jobs folder by its jobs');
if ($sp===null) unset($GLOBALS['CMS_PRIVATE_DIR']); else $GLOBALS['CMS_PRIVATE_DIR']=$sp;
