<?php
// Included by full_schema.php after ops_cases.php, against its synthetic database only:
// settings changed on screen and the database upgrade page (3.0.54).
check(config_override_save('app.support_email','not an email',1)!==null && config_override_save('app.otp_expiry_minutes','99',1)!==null
    && config_override_save('brand.color','#ffff00',1)!==null && config_override_save('app.timezone','Europe/Paris',1)!==null,'Settings are checked before they are saved');
check(config_override_save('app.support_email','help@example.test',1)===null && setting('cfg.app.support_email')==='help@example.test','A setting is saved');
$saved=$GLOBALS['CONFIG'];
config_overrides_apply();
check(cfg('support_email')==='help@example.test','A saved setting replaces config.php\'s');
config_override_save('app.support_email','',1);
$GLOBALS['CONFIG']=$saved;
check((string)setting('cfg.app.support_email')==='' ,'An empty setting goes back to config.php');
check(brand_rgb('color_dark')===array_map('hexdec',str_split(ltrim(brand('color_dark'),'#'),2)) && brand_contrast_ratio('#1e3a8a')>7,'The PDFs use the brand colour, and colours are checked for contrast');
[$code,$out,$err]=$renderPage('settings',[]);
check($code===0 && $err==='' && str_contains($out,'Microsoft Graph email') && str_contains($out,'type="password"') && !str_contains($out,'client_secret" value='),'The Settings page never shows the secret' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('settings',[],['save_group'=>'graph','graph_client_secret'=>'synthetic-secret-value','graph_tenant_id'=>'','graph_client_id'=>'','graph_sender'=>'']);
check($code===0 && setting('cfg.graph.client_secret')==='synthetic-secret-value' && !str_contains((string)$db->query("SELECT detail FROM audit_log WHERE action='settings.config' ORDER BY id DESC LIMIT 1")->fetchColumn(),'synthetic-secret'),'A secret is stored and never written to the audit log' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('settings',[],['save_group'=>'graph','graph_client_secret'=>'','graph_client_secret_clear'=>'1']);
check((string)setting('cfg.graph.client_secret')==='','A stored secret can be removed');

/* Database upgrade */
$dist=array_map('basename',glob($root.'/dist/migrations/mig_*.sql')); $inc=array_map('basename',glob($root.'/public/inc/migrations/mig_*.sql'));
sort($dist); sort($inc);
$same=$dist===$inc; foreach ($dist as $f) $same=$same && file_get_contents($root.'/dist/migrations/'.$f)===file_get_contents($root.'/public/inc/migrations/'.$f);
check($same,'The migrations the upgrade page runs are the same as dist/migrations');
set_setting('schema_version','3.0.53');
check(array_keys(pending_migrations())===['3.0.54'],'The upgrade page finds the newer migrations');
[$code,$out,$err]=$renderPage('upgrade',[],['run_upgrade'=>'1']);
check(setting('schema_version')==='3.0.53','The upgrade does not run without a backup confirmed' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('upgrade',[],['run_upgrade'=>'1','have_backup'=>'1']);
check($code===0 && $err==='' && setting('schema_version')===DB_SCHEMA_VERSION && str_contains($out,'Version 3.0.54 applied'),'The upgrade applies each change and reports it' . ($err ? ": $err" : ''));
[$code,$out,$err]=$renderPage('upgrade',[]);
check(str_contains($out,'The database is up to date'),'The database is then up to date');
foreach (glob($root.'/dist/migrations/mig_*.sql') as $mf) {
    $bad=false;
    $open=false; // inside a quoted string, counting across lines ('' is an escaped quote)
    foreach (file($mf) as $ln) {
        if (stripos(ltrim($ln),'DELIMITER')===0) $bad=true;
        if (!$open && preg_match('/^\s*--/',$ln)) continue;
        if (substr_count(str_replace("''",'',$ln),"'")%2===1) $open=!$open;
        if ($open && preg_match('/;\s*$/',$ln)) $bad=true;
    }
    check(!$bad && !$open,basename($mf).' splits cleanly for the upgrade page (no DELIMITER, no statement end inside a string)');
}
$ignore=$GLOBALS['CONFIG']; $GLOBALS['CONFIG']['app']['ignore_db_settings']=true;
set_setting('cfg.app.support_email','ignored@example.test');
config_overrides_apply();
check(cfg('support_email')!=='ignored@example.test','ignore_db_settings in config.php makes config.php win');
set_setting('cfg.app.support_email',''); $GLOBALS['CONFIG']=$ignore;
