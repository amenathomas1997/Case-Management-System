<?php
// Included by full_schema.php after v417_cases.php, against its synthetic database only:
// 4.18.0's app kit (an installation's own phone apps).
require_once $root.'/public/inc/appkit.php';

// The shipped source matches the apps' files in this checkout (dist/make_appkit.sh was run).
$kitZip=new ZipArchive(); $kitZip->open($root.'/public/inc/appkit/app-source.zip');
$tracked=array_filter(explode("\n",trim((string)shell_exec('cd '.escapeshellarg($root).' && git ls-files -- android ios'))),
    fn($f)=>$f!=='' && !preg_match('~^android/play-store/|^android/PLAY_STORE\.md$|^ios/(TESTFLIGHT\.md|testflight\.sh|ExportOptions\.plist)$|^ios/[^/]+\.xcodeproj/~',$f));
$stale=[];
foreach ($tracked as $f) if ($kitZip->getFromName($f)!==file_get_contents($root.'/'.$f)) $stale[]=$f;
check($stale===[] && $kitZip->numFiles===count($tracked)+1 && $kitZip->locateName('LICENSE')!==false,'The app source in the kit matches the apps\' files (run dist/make_appkit.sh)'.($stale?': '.implode(', ',array_slice($stale,0,5)):''));
check($kitZip->locateName('android/play-store/icon-512.png')===false && $kitZip->locateName('ios/TESTFLIGHT.md')===false,'This vendor\'s own store materials are not in the kit');
$kitZip->close();

// Defaults come from the branding and the server; wrong values are refused.
check(appkit_server()==='example.invalid' && appkit_default_id()==='invalid.example','The kit signs in to this installation, with an app ID made from its address');
[$v,$e]=appkit_check(['app_id'=>'org.example_site.cms','display_name'=>'Bad "name"','spoken_name'=>'','signin_title'=>str_repeat('x',81),'apple_team'=>'ABC']);
check(isset($e['app_id'],$e['display_name'],$e['spoken_name'],$e['signin_title'],$e['apple_team']),'App IDs with underscores (or capitals or hyphens), names with quotes, empty or long names, and a short team ID are refused');
[$code,,$err]=$renderPage('phone_apps',[],['download_kit'=>'1','app_id'=>'Not An Id','display_name'=>'Kit','spoken_name'=>'Kit','signin_title'=>'Kit','apple_team'=>'']);
check($db->query("SELECT COUNT(*) FROM settings WHERE name='appkit_app_id'")->fetchColumn()==0,'A refused kit saves nothing'.($err?": $err":''));
[$code,$html,$err]=$renderPage('phone_apps',[]);
check(str_contains($html,'Download the app kit') && str_contains($html,'example.invalid'),'The Phone apps page offers the kit for this installation'.($err?": $err":''));

// An organisation's own icon and values go into the kit.
$im=imagecreatetruecolor(600,600); imagefill($im,0,0,imagecolorallocate($im,200,30,30)); imagepng($im,$tmp.'/kit_icon.png');
check(brand_icon_save($tmp.'/kit_icon.png')===null,'A synthetic brand icon is saved');
$post=['download_kit'=>'1','app_id'=>'org.synthetic.cms','display_name'=>'Synthétic AT','spoken_name'=>'Synthetic A T Services','signin_title'=>'Synthetic AT (staff)','apple_team'=>'abcde12345'];
[$code,$out,$err]=$renderPage('phone_apps',[],$post);
file_put_contents($tmp.'/kit.zip',$out);
$k=new ZipArchive(); $opened=$k->open($tmp.'/kit.zip')===true;
check($opened && $err==='','The kit downloads as a zip'.($err?": $err":''));
$gp=(string)$k->getFromName('android/gradle.properties'); $py=(string)$k->getFromName('ios/project.yml');
check(str_contains($gp,"cms.appId=org.synthetic.cms\n") && str_contains($gp,'cms.displayName=Synth\\u00E9tic AT') && str_contains($gp,"cms.defaultServer=example.invalid\n") && str_contains($gp,'cms.signinTitle=Synthetic AT (staff)'),'Android gets the app ID, names (non-ASCII escaped) and server');
check(str_contains($py,'CMS_BUNDLE_ID: org.synthetic.cms') && str_contains($py,'CMS_TEAM: "ABCDE12345"') && str_contains($py,'CMS_DISPLAY_NAME: "Synthétic AT"') && str_contains($py,'CMS_DEFAULT_SERVER: "example.invalid"') && !str_contains($py,'cms'),'The iPhone app gets the same, with no trace of this vendor');
$ai=imagecreatefromstring((string)$k->getFromName('android/app/src/main/res/mipmap-xxxhdpi/ic_launcher.png'));
$iosIcon=null; for ($i=0;$i<$k->numFiles;$i++) if (preg_match('~AppIcon\.appiconset/.+\.png$~',(string)$k->getNameIndex($i))) $iosIcon=imagecreatefromstring((string)$k->getFromIndex($i));
check($ai && imagesx($ai)===192 && (imagecolorat($ai,96,96)>>16 & 0xFF)>150 && $iosIcon && imagesx($iosIcon)===1024 && (imagecolorat($iosIcon,512,512)>>16 & 0xFF)>150,'The kit carries the organisation\'s own icon, at each size');
check(str_contains((string)$k->getFromName('BUILD-YOUR-APPS.md'),'org.synthetic.cms') && str_contains((string)$k->getFromName('BUILD-YOUR-APPS.md'),'TestFlight'),'The kit explains how to build the apps and hand them to staff');
$k->close();
check(setting('appkit_app_id')==='org.synthetic.cms' && setting('appkit_apple_team')==='ABCDE12345' && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='appkit.download'")->fetchColumn()===1,'The values are kept for the next kit, and the download is audited');
brand_icon_remove();

// One-button updates from GitHub, against a throwaway site in the test folder (never this checkout).
require_once $root.'/public/inc/updater.php';
$site=$tmp.'/upd_site'; @mkdir("$site/docroot/inc",0777,true); @mkdir("$site/docroot/assets",0777,true); @mkdir("$site/private/cron",0777,true);
file_put_contents("$site/docroot/index.php",'old index'); file_put_contents("$site/docroot/inc/config_path.php",'KEEP'); file_put_contents("$site/docroot/.htaccess",'site own');
file_put_contents("$site/docroot/assets/icon-192.png",'MINE'); file_put_contents("$site/private/cron/cron_config.php",'KEEP CRON'); file_put_contents("$site/private/cron/daily_alerts.php",'old job');
$savedPrivate=$GLOBALS['CMS_PRIVATE_DIR'] ?? null; $GLOBALS['CMS_PRIVATE_DIR']="$site/private";
putenv("CMS_TEST_UPDATE_ROOT=$site/docroot");
$k=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]); putenv('CMS_TEST_UPDATE_KEY='.openssl_pkey_get_details($k)['key']);
$schemaBefore=setting('schema_version');
$pack=function(string $name,array $files,string $ver,array $extra=[])use($tmp,$k):string{
    $p="$tmp/$name.zip"; @unlink($p); $z=new ZipArchive(); $z->open($p,ZipArchive::CREATE); $m=$extra+['version'=>$ver,'files'=>[]];
    foreach ($files as $f=>$d) { $z->addFromString($f,$d); $m['files'][$f]=hash('sha256',$d); }
    $z->addFromString('manifest.json',json_encode($m)); $z->close();
    openssl_sign((string)file_get_contents($p),$sig,$k,OPENSSL_ALGO_SHA256); file_put_contents("$p.sig",base64_encode($sig)); return $p;
};
$files=['docroot/index.php'=>'new index','docroot/inc/config_path.php'=>'BAD','docroot/.htaccess'=>'new rules','docroot/assets/icon-192.png'=>'DEFAULT','docroot/added.php'=>'added',
    'docroot/inc/migrations/mig_99.0.0.sql'=>"CREATE TABLE IF NOT EXISTS update_probe (id int);\n",'cron/daily_alerts.php'=>'new job','cron/cron_config.php'=>'BAD CRON'];
file_put_contents("$site/docroot/old_page.php",'removed upstream'); file_put_contents("$site/docroot/same.php",'unchanged');
$files['docroot/same.php']='unchanged'; $files['cron/backup.sh']='#!/bin/sh';
$p=$pack('upd99',$files,'99.0.0',['notes'=>'- Signed synthetic notes','removed'=>['docroot/old_page.php','docroot/inc/config_path.php'],'exec'=>['cron/backup.sh']]);
file_put_contents("$tmp/release.json",json_encode(['tag_name'=>'v99.0.0','body'=>'Synthetic release notes','assets'=>[
    ['name'=>'cms-update-99.0.0.zip','browser_download_url'=>'https://github.com/x/r/cms-update-99.0.0.zip'],['name'=>'cms-update-99.0.0.zip.sig','browser_download_url'=>'https://github.com/x/r/cms-update-99.0.0.zip.sig']]]));
putenv('CMS_TEST_UPDATES='.json_encode(['https://api.github.com/repos/'.UPDATE_REPO.'/releases/latest'=>"$tmp/release.json",'https://github.com/x/r/cms-update-99.0.0.zip'=>$p,'https://github.com/x/r/cms-update-99.0.0.zip.sig'=>"$p.sig"]));
$rel=updater_latest();
check($rel['version']==='99.0.0' && $rel['notes']==='Synthetic release notes' && str_ends_with((string)$rel['sig'],'.sig'),'The newest GitHub release and its package are found');
$dl=updater_download($rel);
check(is_file($dl) && updater_signature_ok($dl,"$dl.sig"),'The package is downloaded into the private updates folder and its signature checked');
// A changed package, or one signed by anyone else, is refused and deleted.
copy($p,"$tmp/tampered.zip"); file_put_contents("$tmp/tampered.zip",'x',FILE_APPEND); copy("$p.sig","$tmp/tampered.zip.sig");
$other=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]); openssl_sign((string)file_get_contents($p),$osig,$other,OPENSSL_ALGO_SHA256); file_put_contents("$tmp/othersig",base64_encode($osig));
check(!updater_signature_ok("$tmp/tampered.zip","$tmp/tampered.zip.sig") && !updater_signature_ok($p,"$tmp/othersig"),'A changed package, or one signed with another key, does not pass');
putenv('CMS_TEST_UPDATE_KEY');
check(!updater_signature_ok($p,"$p.sig"),'Without the test key, only the publisher\'s built-in key is trusted');
putenv('CMS_TEST_UPDATE_KEY='.openssl_pkey_get_details($k)['key']);
$plan=updater_inspect($dl);
check($plan['problems']===[] && isset($plan['docroot']['index.php'],$plan['cron']['daily_alerts.php']) && !isset($plan['docroot']['inc/config_path.php']) && !isset($plan['docroot']['assets/icon-192.png']) && !isset($plan['cron']['cron_config.php']) && $plan['kept']===['.htaccess'],'The plan leaves the site\'s own configuration, icons, cron settings and changed .htaccess alone');
check(!isset($plan['docroot']['same.php']) && $plan['remove']===['docroot/old_page.php'] && $plan['notes']==='- Signed synthetic notes','Unchanged files are skipped, files the publisher removed are listed (never the site\'s own), and the notes come from the signed package');
$report=updater_apply($dl,$plan,1);
check(file_get_contents("$site/docroot/index.php")==='new index' && file_get_contents("$site/docroot/added.php")==='added' && file_get_contents("$site/private/cron/daily_alerts.php")==='new job','The update replaces the files and the scheduled jobs');
check(!is_file("$site/docroot/old_page.php") && (fileperms("$site/private/cron/backup.sh") & 0111)!==0 && glob("$site/docroot/*.cms-update-tmp")===[] && !is_file(updater_marker()),'A removed page is deleted, scripts stay runnable, and nothing temporary is left behind');
check(file_get_contents("$site/docroot/inc/config_path.php")==='KEEP' && file_get_contents("$site/docroot/.htaccess")==='site own' && file_get_contents("$site/docroot/assets/icon-192.png")==='MINE' && file_get_contents("$site/private/cron/cron_config.php")==='KEEP CRON','The site\'s own files are untouched');
check($db->query("SHOW TABLES LIKE 'update_probe'")->fetchColumn()!==false && setting('schema_version')==='99.0.0' && str_contains(implode(' ',$report),'.htaccess has your own changes'),'The new version\'s database changes run, and the kept .htaccess is reported');
check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='update.apply'")->fetchColumn()===1 && count(glob("$site/private/updates/backup-*.zip"))===1,'The update is audited and the old files are kept in a backup');
// A file that cannot be written stops the update before anything changes.
file_put_contents("$site/docroot/blocker",'a file where a folder is needed');
$p2=$pack('upd100',['docroot/index.php'=>'newer index','docroot/zz_new.php'=>'x','docroot/blocker/inside.php'=>'cannot be written'],'100.0.0');
$plan2=updater_inspect($p2);
$err2=null; try { updater_apply($p2,$plan2,1); } catch (RuntimeException $e) { $err2=$e->getMessage(); }
check($err2!==null && str_contains($err2,'Nothing on the site was changed') && file_get_contents("$site/docroot/index.php")==='new index' && !is_file("$site/docroot/zz_new.php") && glob("$site/docroot/*.cms-update-tmp")===[],'An update that cannot write a file changes nothing and leaves nothing behind');
unlink("$site/docroot/blocker");
// An install the server cut off part way: the marker stops a new install, and the backup puts the old files back.
$bk=glob("$site/private/updates/backup-*.zip")[0];
file_put_contents(updater_marker(),json_encode(['backup'=>$bk,'from'=>'4.17.0','to'=>'99.0.0']));
file_put_contents("$site/docroot/index.php",'half written');
$err4=null; try { updater_apply($p2,updater_inspect($p2),1); } catch (RuntimeException $e) { $err4=$e->getMessage(); }
check($err4!==null && str_contains($err4,'did not finish'),'While an earlier install is unfinished, no new one starts');
check(updater_restore($bk) && file_get_contents("$site/docroot/index.php")==='old index' && !is_file("$site/docroot/added.php") && file_get_contents("$site/docroot/old_page.php")==='removed upstream','Putting the previous files back restores replaced and removed files and removes added ones');
unlink(updater_marker());
// A stock .htaccess (one the publisher shipped) is updated; a site's own is not.
file_put_contents("$site/docroot/.htaccess",'stock rules v1');
$p5=$pack('upd101',['docroot/.htaccess'=>'stock rules v2'],'101.0.0',['known'=>['docroot/.htaccess'=>[hash('sha256','stock rules v1')]]]);
$plan5=updater_inspect($p5);
check(isset($plan5['docroot']['.htaccess']) && $plan5['kept']===[],'A .htaccess the publisher shipped is updated with the release');
$p6=$pack('upd102',['docroot/inc/./config_path.php'=>'x'],'102.0.0');
check(str_contains(implode(' ',updater_inspect($p6)['problems']),'unsafe path'),'A path with a "." part is refused');
check(str_contains((string)file_get_contents($root.'/public/inc/updater.php'),"if (PHP_SAPI !== 'cli') return null;"),'The test hooks (another signing key, fake downloads, another folder) never work on a web server');
// Packages that are not newer, or name unsafe paths, are not installed.
$p3=$pack('upd_old',['docroot/index.php'=>'x','docroot/../escape.php'=>'x'],'1.0.0');
$plan3=updater_inspect($p3);
check(count($plan3['problems'])===2 && str_contains(implode(' ',$plan3['problems']),'unsafe path') && str_contains(implode(' ',$plan3['problems']),'not newer'),'An older package, or one naming a path outside the site, is refused');
$err3=null; try { updater_apply($p3,$plan3,1); } catch (RuntimeException $e) { $err3=$e->getMessage(); }
check($err3!==null && str_contains($err3,'not started'),'A package with problems is never applied');
// The page: off until chosen, then offers the download.
[$code,$html,$err]=$renderPage('update',[]);
check(str_contains($html,'One-button updates are off'),'Software update says how to turn it on'.($err?": $err":''));
$db->exec("INSERT INTO settings(name,value) VALUES('cfg.app.update_url','github') ON DUPLICATE KEY UPDATE value='github'");
[$code,$html,$err]=$renderPage('update',[]);
check(str_contains($html,'Download and check the newest version'),'With github chosen, Software update offers the newest release'.($err?": $err":''));
check(config_override_save('app.update_url','github',1)===null && config_override_save('app.update_url','http://plain.example',1)!==null,'Settings accept github as the update source, and still refuse plain http');
$db->exec("DELETE FROM settings WHERE name IN ('cfg.app.update_url','update_downloaded')");
$db->exec('DROP TABLE IF EXISTS update_probe');
set_setting('schema_version',(string)$schemaBefore);
putenv('CMS_TEST_UPDATE_ROOT'); putenv('CMS_TEST_UPDATE_KEY'); putenv('CMS_TEST_UPDATES');
if ($savedPrivate===null) unset($GLOBALS['CMS_PRIVATE_DIR']); else $GLOBALS['CMS_PRIVATE_DIR']=$savedPrivate;
