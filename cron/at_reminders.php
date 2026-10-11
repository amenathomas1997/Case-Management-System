<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit('CLI only.');
// Application inc/ directory: optional cron_config.php beside this script (returns the path), then production, then the repository layout.
foreach([is_file(__DIR__.'/cron_config.php')?(string)(require __DIR__.'/cron_config.php'):'','/home/cmsuser/public_html/casemanagement/inc',dirname(__DIR__).'/public/inc'] as $inc){$inc=rtrim($inc,'/');if($inc!==''&&is_file($inc.'/core.php')){require $inc.'/core.php';break;}}
if(!function_exists('db')){fwrite(STDERR,"CMS cron: the application's inc/core.php was not found. Create cron_config.php beside this script containing: <?php return '/full/path/to/your/web/docroot/inc';\n");exit(1);}
require_once $inc.'/at_services.php';
if(!at_ready()){echo "AT migration has not been installed.\n";exit(1);}
// In-app reminders only; no email or external messages. One reminder per due date/user.
$rows=db()->query("SELECT r.*,c.assigned_to FROM at_records r LEFT JOIN clients c ON c.id=r.client_id
 WHERE r.kind IN ('outcome','funding','repair','learning','license') AND r.due_date<=DATE_ADD(CURDATE(),INTERVAL 7 DAY)
 AND r.status NOT IN ('Completed','Skipped','Paid','Resolved','Cancelled','Expired')")->fetchAll();
$users=db()->query('SELECT * FROM users WHERE is_active=1')->fetchAll();$sent=0;
$access=[];foreach($users as $u)$access[(int)$u['id']]=resolve_perms($u);
foreach($rows as $r){
    // Who may act on this record: the module (or billing) permission, and the caseload for scoped staff.
    $permission=at_catalog()[$r['kind']]['perm'];$eligible=[];
    foreach($access as $uid=>$a){
        if(!in_array($permission,$a['perms'],true)&&!in_array('billing',$a['perms'],true))continue;
        if($a['scoped']&&$r['client_id']&&(int)$r['assigned_to']!==$uid)continue;
        $eligible[$uid]=$a;
    }
    // The assigned case worker / creator is reminded. Administrators are the fallback
    // only when there is no such person or that person cannot act on it (inactive or without access).
    $target=(int)($r['staff_id']?:($r['assigned_to']?:$r['created_by']));
    $recipients=isset($eligible[$target])?[$target]:array_keys(array_filter($eligible,fn(array $a):bool=>in_array('admin',$a['perms'],true)));
    foreach($recipients as $uid){
        db()->beginTransaction();
        try{
            $q=db()->prepare('INSERT IGNORE INTO at_reminders(record_id,due_date,user_id) VALUES (?,?,?)');$q->execute([$r['id'],$r['due_date'],$uid]);
            if($q->rowCount()) {db()->prepare('INSERT INTO notifications(user_id,message,link) VALUES (?,?,?)')->execute([$uid,mb_substr('AT due '.$r['due_date'].': '.$r['title'],0,190),'at_services.php?id='.(int)$r['id']]);$sent++;}
            db()->commit();
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    }
}
echo "Created $sent AT reminders.\n";
