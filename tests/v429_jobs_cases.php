<?php
// Included by full_schema.php after the v428 cases, against its synthetic database only:
// 4.29.0 jobs and integrations (staging scrub and make_staging.sh failure handling, restore
// time zone, mig_3.0.31 run again, the Graph secret day count across the clock change,
// unknown mail outcomes for the counselor notice and scheduled reports, voice placeholders,
// live tokens kept out of the nightly backup, and Software update rollback file modes).
// The staging scrub itself is exercised end to end by tests/staging_scrub.php (two rows per table).

// ---- P15: the staging scrub gives UNIQUE columns per-row values; make_staging.sh never leaves a partial copy ----
require_once $root.'/dist/staging_scrub.php';
$scrub429=(string)file_get_contents($root.'/dist/staging_scrub.php');
check(str_contains($scrub429,'information_schema.statistics') && str_contains($scrub429,'non_unique = 0') && str_contains($scrub429,"CONCAT('[scrubbed ', \$n, ']')"),
    'The staging scrub finds every UNIQUE key from the schema and gives those columns a per-row value');
$sh429=(string)file_get_contents($root.'/dist/make_staging.sh');
check(str_contains($sh429,'trap cleanup EXIT') && str_contains($sh429,'drop_staging_tables ||') && preg_match('/DB_TOUCHED=1\s*\ndrop_staging_tables/',$sh429)===1,
    'make_staging.sh drops every staging table when any step after the load starts fails');
check(str_contains($sh429,'write_placeholder_config') && str_contains($sh429,'config.php.new') && str_contains($sh429,'was not written correctly'),
    'make_staging.sh keeps a stopping placeholder config.php until the staging one is written and checked (never the production config)');

// ---- P16: restore.php never assumes a time zone ----
$restore429=function(array $args) use ($root): array {
    $p=proc_open(array_merge([PHP_BINARY,$root.'/dist/restore.php'],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p),$out];
};
$priv429=$tmp.'/restore429'; @mkdir($priv429,0700,true); @unlink("$priv429/config.php");
$base429=['--zip='.$tmp.'/v429-no-such-backup.zip','--dsn=mysql:host=localhost;dbname=v429_none','--db-user=v429',"--private=$priv429"];
[$c1,$o1]=$restore429($base429);
check($c1===2 && str_contains($o1,'Missing --timezone') && str_contains($o1,'--timezone=America/New_York'),'Restoring without --timezone is refused, with an example');
[$c2,$o2]=$restore429(array_merge($base429,['--timezone=Mars/Olympus']));
check($c2===2 && str_contains($o2,'is not a time zone name'),'A misspelt time zone is refused');
[$c3,$o3]=$restore429(array_merge($base429,['--timezone=America/Denver']));
check($c3===1 && str_contains($o3,'Step 1 failed'),'A real time zone lets the restore go on (here to the missing backup file)');
file_put_contents("$priv429/config.php","<?php return ['app' => ['timezone' => 'America/Los_Angeles']];\n");
[$c4,$o4]=$restore429($base429);
[$c5,$o5]=$restore429(array_merge($base429,['--timezone=America/Chicago']));
check($c4===1 && str_contains($o4,'Step 1 failed') && $c5===2 && str_contains($o5,'America/Los_Angeles'),
    'With the old config.php in place its time zone is used, and a different --timezone is refused rather than guessed');
@unlink("$priv429/config.php");
check(str_contains((string)file_get_contents($root.'/dist/restore.php'),'Send each client a new portal link'),'The restore says portal and other links must be sent again');

// ---- mig_3.0.31 run again never confirms an email Client import left unconfirmed ----
$db->exec("INSERT INTO clients(name,email,email_verified,created_at) VALUES('V429-JOBS Old Referral Client','v429-jobs-old@example.invalid',0,'2026-10-01 09:00:00'),('V429-JOBS Imported Client','v429-jobs-import@example.invalid',0,NOW())");
$old429=(int)$db->query("SELECT id FROM clients WHERE email='v429-jobs-old@example.invalid'")->fetchColumn();
$imp429=(int)$db->query("SELECT id FROM clients WHERE email='v429-jobs-import@example.invalid'")->fetchColumn();
foreach (array_filter(array_map('trim',explode(';',preg_replace('/^--.*$/m','',(string)file_get_contents($root.'/dist/migrations/mig_3.0.31.sql'))))) as $st429) $db->exec($st429);
check((int)$db->query("SELECT email_verified FROM clients WHERE id=$old429")->fetchColumn()===1 && (int)$db->query("SELECT email_verified FROM clients WHERE id=$imp429")->fetchColumn()===0,
    'mig_3.0.31 run again confirms only emails from before 3.0.31, never a newer import waiting for staff');
check(file_get_contents($root.'/dist/migrations/mig_3.0.31.sql')===file_get_contents($root.'/public/inc/migrations/mig_3.0.31.sql'),'Both copies of mig_3.0.31 are the same');

// ---- Graph secret expiry: calendar days, across the spring clock change ----
$tz429=date_default_timezone_get(); date_default_timezone_set('America/Chicago');
check(ops_calendar_days_until('2027-03-31','2027-03-01')===30 && ops_calendar_days_until('2027-03-15','2027-03-01')===14
    && ops_calendar_days_until('2027-03-17','2027-03-10')===7 && ops_calendar_days_until('2027-03-15','2027-03-14')===1
    && ops_calendar_days_until('2027-03-14','2027-03-14')===0 && ops_calendar_days_until('2027-11-01','2027-11-08')===-7,
    'The Graph secret warnings count calendar days, so the 30, 14 and 7-day warnings are not skipped around the clock change');
$gs429=setting('graph_credential_expires');
set_setting('graph_credential_expires',date('Y-m-d',strtotime('+30 days')));
check(graph_secret_days_left()===30,'graph_secret_days_left counts from today');
set_setting('graph_credential_expires',(string)($gs429 ?? ''));
date_default_timezone_set($tz429);

// ---- Unknown mail outcome: the counselor 80% notice and the scheduled reports count it as sent ----
$db->exec("INSERT INTO clients(name,status,client_type,state,counselor_name,counselor_email,counselor_verified) VALUES('V429-JOBS Notice Client','Active Training','VR','IA','V429-JOBS Counselor','v429-jobs-counselor@example.invalid',1)");
$nc429=(int)$db->lastInsertId();
$db->prepare("INSERT INTO vrc_tokens(counselor_email,counselor_name,token,expires_at) VALUES('v429-jobs-counselor@example.invalid','V429-JOBS Counselor',?,DATE_ADD(NOW(),INTERVAL 30 DAY))")->execute([str_repeat('9',48)]);
$db->exec("INSERT INTO training_tracks(client_id,title,auth_number,funding_agency,billing_mode,authorized_hours) VALUES($nc429,'V429-JOBS track','V429-JOBS-AUTH','V429-JOBS VR Agency','vr',40)"); $nt429=(int)$db->lastInsertId();
putenv('CMS_TEST_MAIL_OUTCOME=unknown');
$sent429=counselor_auth_notice($nt429);
putenv('CMS_TEST_MAIL_OUTCOME');
check($sent429===true && ($GLOBALS['counselor_auth_notice_outcome'] ?? '')==='unknown','A counselor notice the mail service did not confirm counts as sent, so it is not emailed again tomorrow');
$da429=(string)file_get_contents($root.'/cron/daily_alerts.php'); $mr429=(string)file_get_contents($root.'/cron/monthly_reports.php');
check(str_contains($da429,"counselor_auth_notice_outcome") && str_contains($mr429,"elseif (\$o === 'unknown') { \$ok[] = \$to; \$unsure[] = \$to; }"),
    'The daily alerts report an unconfirmed counselor notice once, and a scheduled report whose sends were unconfirmed is marked for the period');

// ---- Voice: phone numbers, email addresses and 9-digit numbers are mapped back, not saved as placeholders ----
$said429='add a note for V429 client: her new cell is 515-555-0100, email v429.jobs@example.invalid, VR case 123456789, call 515-555-0100 back';
[$ptext429,$pmap429]=ai_pseudonymize_contacts($said429);
check(!str_contains($ptext429,'515-555-0100') && !str_contains($ptext429,'123456789') && !str_contains($ptext429,'v429.jobs@example.invalid')
    && substr_count($ptext429,'[PHONE 1]')===2 && str_contains($ptext429,'[EMAIL 1]') && str_contains($ptext429,'[SSN 1]') && ai_redact($ptext429)===$ptext429,
    'Numbers and addresses go to the AI as numbered placeholders, which redaction leaves alone');
$back429=voice_unpseudonymize(['action'=>'add_note','text'=>'New cell [PHONE 1], email [EMAIL 1], VR case [SSN 1].'],$pmap429);
check($back429['text']==='New cell 515-555-0100, email v429.jobs@example.invalid, VR case 123456789.','The model\'s reply has them put back, so the saved note holds what was said');

// ---- Backups: live link tokens never leave the server in a dump ----
$bk429=(string)file_get_contents($root.'/cron/backup.sh');
preg_match('/^TOKEN_COLUMNS="(.*)"$/m',$bk429,$tm429);
$tok429=explode(' ',$tm429[1] ?? '');
check(!array_diff(['portal_tokens.token','vrc_tokens.token','consent_forms.token','client_signatures.token','client_releases.token','report_acks.token','surveys.token','users.cal_token'],$tok429)
    && str_contains($bk429,'IGNORE_TOKENS') && str_contains($bk429,'token_tables_dump'),
    'The nightly backup dumps every table with a live link token without the token');
$mysql429=trim((string)shell_exec('command -v mariadb || command -v mysql'));
if ($mysql429!=='' && trim((string)shell_exec('command -v mysqldump'))!=='' && preg_match('/.*dbname=([A-Za-z0-9_]+)/',$dsn,$dbn429)) {
    preg_match('/host=([^;]+)/',$dsn,$hn429);
    $bdir429=$tmp.'/backup429'; @mkdir("$bdir429/uploads",0700,true); @mkdir("$bdir429/cron",0700,true);
    file_put_contents("$bdir429/db.cnf","[client]\nhost=".($hn429[1] ?? 'localhost')."\nuser=".(getenv('CMS_TEST_USER') ?: 'root')."\npassword=\"".addcslashes((string)(getenv('CMS_TEST_PASSWORD') ?: ''),'"\\')."\"\n"); chmod("$bdir429/db.cnf",0600);
    copy($root.'/cron/backup.sh',"$bdir429/cron/backup.sh");
    $ptok429='V429JOBSPORTALTOKEN'.str_repeat('p',29); $ctok429='V429JOBSCALTOKEN'.str_repeat('c',32);
    $db->prepare("INSERT INTO portal_tokens(client_id,token) VALUES(?,?)")->execute([$nc429,$ptok429]);
    $db->prepare("INSERT INTO users(name,email,password_hash,role,cal_token) VALUES('V429-JOBS O''Brien é','v429-jobs-cal@example.invalid','x','instructor',?)")->execute([$ctok429]);
    $env429='CMS_PRIVATE_DIR='.escapeshellarg($bdir429).' CMS_DB_NAME='.escapeshellarg($dbn429[1]).' CMS_DB_CNF='.escapeshellarg("$bdir429/db.cnf");
    exec("$env429 bash ".escapeshellarg("$bdir429/cron/backup.sh").' 2>&1',$bo429,$bc429);
    $dumps429=glob("$bdir429/backups/cms_*.sql.gz") ?: [];
    $sql429=$dumps429 ? (string)gzdecode((string)file_get_contents($dumps429[0])) : '';
    check($bc429===0 && $sql429!=='' && !str_contains($sql429,$ptok429) && !str_contains($sql429,$ctok429)
        && str_contains($sql429,'INSERT INTO `portal_tokens`') && str_contains($sql429,'v429-jobs-cal@example.invalid') && str_contains($sql429,'CREATE TABLE `users`'),
        'A real nightly dump keeps the portal and user rows but none of their live tokens'.($bc429 ? ': '.implode(' ',$bo429) : ''));
    $db->exec("DELETE FROM portal_tokens WHERE token='$ptok429'"); $db->exec("DELETE FROM users WHERE email='v429-jobs-cal@example.invalid'");
}

// ---- Software update rollback keeps file modes (cron/backup.sh stays executable) ----
require_once $root.'/public/inc/updater.php';
$site429=$tmp.'/upd429'; @mkdir("$site429/docroot",0777,true); @mkdir("$site429/private/cron",0777,true);
file_put_contents("$site429/private/cron/watchdog.php",'<?php // marks the cron folder');
file_put_contents("$site429/private/cron/backup.sh","#!/bin/bash\necho new\n"); chmod("$site429/private/cron/backup.sh",0644);
file_put_contents("$site429/private/cron/old.sh","#!/bin/bash\necho old\n"); chmod("$site429/private/cron/old.sh",0644);
$savedPrivate429=$GLOBALS['CMS_PRIVATE_DIR'] ?? null; $GLOBALS['CMS_PRIVATE_DIR']="$site429/private";
putenv("CMS_TEST_UPDATE_ROOT=$site429/docroot");
$bz429="$tmp/backup429.zip"; @unlink($bz429);
$z429=new ZipArchive(); $z429->open($bz429,ZipArchive::CREATE);
$z429->addFromString('cron/backup.sh',"#!/bin/bash\necho previous\n");
$z429->addFromString('cron/old.sh',"#!/bin/bash\necho previous old\n");
$z429->addFromString('added.json','[]'); $z429->addFromString('modes.json',json_encode(['cron/backup.sh'=>0750]));
$z429->close();
$ok429=updater_restore($bz429);
clearstatcache();
check($ok429 && file_get_contents("$site429/private/cron/backup.sh")==="#!/bin/bash\necho previous\n" && (fileperms("$site429/private/cron/backup.sh") & 0777)===0750
    && (fileperms("$site429/private/cron/old.sh") & 0100)!==0,
    'Putting the previous files back restores each recorded mode, and a cron script stays executable even from an older backup');
$GLOBALS['CMS_PRIVATE_DIR']=$savedPrivate429; putenv('CMS_TEST_UPDATE_ROOT');
check(str_contains((string)file_get_contents($root.'/public/inc/updater.php'),"addFromString('modes.json'"),'Each install records the modes of the files it replaces');
