<?php
// Real feed output with synthetic accounts; no requests to calendar providers.
$feedSource=swap_required("require __DIR__ . '/inc/core.php';",'require '.var_export($tmp.'/core.php',true).';',file_get_contents($root.'/public/calfeed.php'),'public/calfeed.php');
file_put_contents($tmp.'/calfeed.php',$feedSource);
$feed=function()use($root,$tmp):string{
    $p=proc_open([PHP_BINARY,'-d','display_errors=stderr','-d','log_errors=0',$root.'/tests/full_schema.php','page',$tmp.'/calfeed.php',json_encode(['u'=>str_repeat('c',48)])],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $output=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($p)!==0||$err!=='')throw new RuntimeException('Calendar feed failed: '.$err);
    return $output;
};
$db->exec("UPDATE users SET cal_token='".str_repeat('c',48)."',group_id=999 WHERE id=1");
$db->exec("UPDATE groups SET perms='schedule',own_caseload_only=1 WHERE id=999");
$db->exec("UPDATE clients SET assigned_to=1 WHERE id=1; UPDATE clients SET assigned_to=2 WHERE id=3");
$db->exec("INSERT INTO scheduled_sessions(client_id,user_id,start_at,end_at,location) VALUES(1,1,NOW(),DATE_ADD(NOW(),INTERVAL 1 HOUR),'VISIBLE-CASE'),(3,1,NOW(),DATE_ADD(NOW(),INTERVAL 1 HOUR),'HIDDEN-CASE')");
$out=$feed();check(str_contains($out,'VISIBLE-CASE')&&!str_contains($out,'HIDDEN-CASE'),'Calendar feeds apply current caseload restrictions');
$db->exec("UPDATE groups SET own_caseload_only=0 WHERE id=999");
check(str_contains($feed(),'HIDDEN-CASE'),'Unrestricted calendar owners retain their scheduled sessions');
$db->exec("UPDATE groups SET perms='clients' WHERE id=999");
check($feed()==='Feed not found.','Calendar tokens stop disclosing sessions after schedule permission is revoked');
$db->exec("UPDATE users SET group_id=NULL,is_active=0 WHERE id=1");
check($feed()==='Feed not found.','Inactive accounts cannot use calendar feeds');
$db->exec("UPDATE users SET is_active=1 WHERE id=1");
