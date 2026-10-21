<?php
// Included by full_schema.php after v42_cases.php, against its synthetic database only:
// 4.3.0's branding, privacy notice details, public wording, web-app manifest, app icon and
// calendar IDs, all set on screen so another AT business can run the system as its own.

/* Neutral built-in branding */
check(brand('name')==='Case Management System' && brand('org')==='Assistive Technology Services' && brand('short')==='Case Management','The built-in branding names no business');
check(str_starts_with(ics_uid('session',5),'cms-session-5@'),'A new site\'s calendar IDs use a neutral prefix');

/* Privacy notice details on Settings */
[$code,,$err]=$renderPage('settings',[],['save_group'=>'legal','brand_privacy_operator'=>'Synthetic Operator','brand_privacy_address'=>'1 Synthetic Road, Springfield',
    'brand_privacy_law'=>'Synthetic State privacy law','brand_privacy_hosting'=>'Synthetic Host','brand_hipaa'=>'no']);
check($code===0 && $err==='' && setting('cfg.brand.privacy_operator')==='Synthetic Operator' && setting('cfg.brand.hipaa')==='no','Administrators set the privacy notice details on Settings' . ($err ? ": $err" : ''));
[$code,,$err]=$renderPage('settings',[],['save_group'=>'legal','brand_privacy_operator'=>'Synthetic Operator','brand_privacy_address'=>'1 Synthetic Road, Springfield','brand_privacy_law'=>'Synthetic State privacy law','brand_privacy_hosting'=>'Synthetic Host','brand_hipaa'=>'maybe']);
check(setting('cfg.brand.hipaa')==='no','Only yes or no is accepted for the HIPAA section');
[$code,,$err]=$renderPage('settings',[],['save_group'=>'updates','app_update_url'=>'http://insecure.example.invalid/u.json']);
check((string)(setting('cfg.app.update_url') ?? '')==='','An update address must use https');
[$code,,$err]=$renderPage('settings',[],['save_group'=>'updates','app_update_url'=>'off']);
check(setting('cfg.app.update_url')==='off','The update check can be turned off on Settings');
set_setting('cfg.app.update_url','');
[$code,$out,$err]=$renderPage('privacy',[]);
check($code===0 && str_contains($out,'is run by Synthetic Operator, 1 Synthetic Road, Springfield') && str_contains($out,'Synthetic State privacy law') && !str_contains($out,'id="hipaa"') && !str_contains($out,'href="#hipaa"'),'The privacy notice names the operator and law from Settings, and leaves out HIPAA when it does not apply' . ($err ? ": $err" : ''));
set_setting('cfg.brand.hipaa','yes');
[$code,$out,$err]=$renderPage('privacy',[]);
check(str_contains($out,'id="hipaa"') && str_contains($out,'href="#hipaa"'),'With HIPAA on, its rights section and contents link are shown');

/* Public wording */
[$code,,$err]=$renderPage('wording',[],['save_wording'=>'1','consent_about'=>'trains people on synthetic tools.','consent_points'=>"We may call your synthetic school.\nWe may email you.",
    'privacy_extra_heading'=>'Synthetic state rights','privacy_extra_body'=>"First synthetic paragraph.\n\nSecond synthetic paragraph.",'privacy_extra_heading_es'=>'Derechos sintéticos']);
check($code===0 && $err==='' && wording_stored('consent_about','en')==='trains people on synthetic tools.' && wording_stored('privacy_extra_heading','es')==='Derechos sintéticos','Administrators save their own public wording, in English and Spanish' . ($err ? ": $err" : ''));
check(wording_html('consent_about')==='trains people on synthetic tools.' && wording_lines_html('consent_points')===['We may call your synthetic school.','We may email you.'],'The consent form uses the business\'s own words');
[$code,$out,$err]=$renderPage('privacy',[]);
check(str_contains($out,'<h2 id="extra">Synthetic state rights</h2>') && str_contains($out,'<p>First synthetic paragraph.</p><p>Second synthetic paragraph.</p>') && str_contains($out,'href="#extra"'),'The privacy notice shows the business\'s own extra section');
[$code,,$err]=$renderPage('wording',[],['save_wording'=>'1','consent_about'=>'trains people on newer synthetic tools.']);
$hist=json_decode((string)setting('wording_consent_about_history'),true);
check(is_array($hist) && $hist[0]['text']==='trains people on synthetic tools.','A wording change keeps the words it replaced, dated');
foreach (array_keys(PUBLIC_WORDING) as $wk) { set_setting('wording_'.$wk,''); set_setting('wording_'.$wk.'_es',''); }
check(str_contains(wording_html('consent_about'),'vision loss') && count(wording_lines_html('consent_points'))===4,'With the boxes empty, the built-in wording is used');
[$code,$out,$err]=$renderPage('wording',[]);
check($code===0 && $err==='' && str_contains($out,'Public wording') && str_contains($out,'lang="es"'),'The wording page offers English and Spanish boxes' . ($err ? ": $err" : ''));

/* The web-app manifest and the icon */
// Both load core.php only (no session, so a cached reply has no cookie): run as the calendar feed is.
$plainPage=function(string $name, array $get)use($root,$tmp):array{
    $src=swap_required("require __DIR__ . '/inc/core.php';",'require '.var_export($tmp.'/core.php',true).';',file_get_contents($root.'/public/'.$name.'.php'),'public/'.$name.'.php');
    $src=str_replace("__DIR__ . '/assets/'",var_export($root.'/public/assets/',true),$src);
    file_put_contents($tmp.'/'.$name.'.php',$src);
    $p=proc_open([PHP_BINARY,'-d','display_errors=stderr','-d','log_errors=0',$root.'/tests/full_schema.php','page',$tmp.'/'.$name.'.php',json_encode($get)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($p),$out,$err];
};
[$code,$out,$err]=$plainPage('manifest',[]);
$mf=json_decode($out,true);
check(is_array($mf) && $mf['name']===brand('name') && $mf['icons'][0]['src']==='icon.php?s=192','The web-app manifest follows the branding on Settings' . ($err ? ": $err" : ''));
[$code,$out,$err]=$plainPage('icon',['s'=>'192']);
check(str_starts_with($out,"\x89PNG"),'The built-in icon is served until one is uploaded');
if (function_exists('imagecreatetruecolor')) {
    $pic=$tmp.'/synthetic-icon.png'; $im=imagecreatetruecolor(600,600); imagefill($im,0,0,imagecolorallocate($im,10,60,120)); imagepng($im,$pic);
    putenv('CMS_TEST_FILES='.json_encode(['icon'=>$pic]));
    [$code,,$err]=$renderPage('settings',[],['save_icon'=>'1']);
    putenv('CMS_TEST_FILES');
    check(brand_icon_uploaded() && getimagesize((string)brand_icon_path(192))[0]===192,'An uploaded square picture becomes the app icon, at each size' . ($err ? ": $err" : ''));
    $small=$tmp.'/synthetic-small.png'; $im=imagecreatetruecolor(100,100); imagepng($im,$small);
    putenv('CMS_TEST_FILES='.json_encode(['icon'=>$small]));
    [[$code,,$err],$sess]=$pageSession(fn()=>$renderPage('settings',[],['save_icon'=>'1']));
    putenv('CMS_TEST_FILES');
    check(str_contains($sess,'at least 512'),'A picture that is too small is refused, saying why');
    [$code,,$err]=$renderPage('settings',[],['save_icon'=>'1','remove_icon'=>'1']);
    check(!brand_icon_uploaded(),'The built-in icons can be put back' . ($err ? ": $err" : ''));
}
