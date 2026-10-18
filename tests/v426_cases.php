<?php
// Included by full_schema.php after v425_cases.php, against its synthetic database only:
// 4.26.0: signing with no signal, and the Android app's contract with the server.

// ---- Signing with no signal ----
$off=$call('start_signature',['client_id'=>$ac,'kind'=>'document','signer_role'=>'Client','signer_name'=>'App Synthetic Client','title'=>'Synthetic offline consent','body'=>'Signed in a client\'s home.']);
$call('sign_in_person',['client_id'=>$ac,'id'=>$off['id'],'typed_name'=>'App Synthetic Client','signed_at'=>date('c',time()+2*86400)],[],false,'not believable');
$db->exec('UPDATE client_signatures SET sent_at=DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id='.(int)$off['id']); // started in the morning
$at=date('Y-m-d H:i:s',time()-1800);
$call('sign_in_person',['client_id'=>$ac,'id'=>$off['id'],'typed_name'=>'App Synthetic Client','signed_at'=>date('c',strtotime($at))]);
$row=$db->query('SELECT signed_at FROM client_signatures WHERE id='.(int)$off['id'])->fetch();
$note=(string)$db->query("SELECT body FROM client_notes WHERE client_id=$ac ORDER BY id DESC LIMIT 1")->fetchColumn();
check($row['signed_at']===$at && str_contains($note,'with no connection'),'A signature kept on the phone with no signal is recorded at the time it was signed');
$again=$call('sign_in_person',['client_id'=>$ac,'id'=>$off['id'],'typed_name'=>'App Synthetic Client','signed_at'=>date('c',strtotime($at))]);
check(!empty($again['repeat']),'The same signature sent twice from the queue is not an error');
$old=$call('start_signature',['client_id'=>$ac,'kind'=>'document','signer_role'=>'Client','signer_name'=>'App Synthetic Client','title'=>'Synthetic stale consent','body'=>'Old.']);
$db->exec('UPDATE client_signatures SET sent_at=DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE id='.(int)$old['id']);
$call('sign_in_person',['client_id'=>$ac,'id'=>$old['id'],'typed_name'=>'App Synthetic Client'],[],false,'more than two days');
// A document started weeks ago cannot be signed now by claiming it was signed just after it was started.
$db->exec('UPDATE client_signatures SET sent_at=DATE_SUB(NOW(), INTERVAL 21 DAY) WHERE id='.(int)$old['id']);
$call('sign_in_person',['client_id'=>$ac,'id'=>$old['id'],'typed_name'=>'App Synthetic Client','signed_at'=>date('c',time()-21*86400+60)],[],false,'more than a week');
check($db->query('SELECT signed_at FROM client_signatures WHERE id='.(int)$old['id'])->fetchColumn()===null,'A signature cannot be backdated to slip inside the window');
// A phone clock a few minutes fast is held to now, not refused.
$fast=$call('start_signature',['client_id'=>$ac,'kind'=>'document','signer_role'=>'Client','signer_name'=>'App Synthetic Client','title'=>'Synthetic fast clock','body'=>'Fast.']);
check(isset($fast['sent_at'],$fast['sign_by']),'The apps are told when the document was started and when it must be signed by');
$call('sign_in_person',['client_id'=>$ac,'id'=>$fast['id'],'typed_name'=>'App Synthetic Client','signed_at'=>date('c',time()+600)]);
check(strtotime((string)$db->query('SELECT signed_at FROM client_signatures WHERE id='.(int)$fast['id'])->fetchColumn())<=time(),'A phone clock a little fast is held to the time it arrived');

// ---- The Android app's contract: the replies it reads, kept as samples in its unit tests ----
// Each sample in android/app/src/test/resources/api/ is a real reply from this synthetic database. Here the server's
// replies today must still carry every field the samples have (new fields are fine), so a renamed or dropped field
// fails before it breaks the app. CMS_WRITE_ANDROID_FIXTURES=1 writes the samples afresh.
$trackId=(int)$db->query("SELECT id FROM training_tracks WHERE client_id=1 ORDER BY id LIMIT 1")->fetchColumn();
$equipId=(int)$db->query('SELECT id FROM equipment ORDER BY id LIMIT 1')->fetchColumn();
$reads=['me'=>[], 'dashboard'=>[], 'clients'=>[], 'client'=>['id'=>$ac], 'client_choices'=>[], 'form_options'=>[], 'schedule'=>[], 'tracks'=>[],
    'message_threads'=>[], 'message_thread'=>['client_id'=>$ac,'channel'=>'client'], 'documents'=>['client_id'=>$ac], 'homework'=>['client_id'=>1],
    'signatures'=>['client_id'=>$ac], 'signature'=>['client_id'=>$ac,'id'=>$off['id']], 'release_choices'=>[], 'referral_deadlines'=>[], 'referrals'=>[],
    'tasks'=>[], 'notifications'=>[], 'client_history'=>['client_id'=>$ac], 'equipment'=>[], 'invoices'=>[], 'incidents'=>[], 'followups'=>[],
    'compliance'=>[], 'counselors'=>[], 'utilization'=>[], 'portal_requests'=>[], 'staff'=>[], 'agreements'=>[], 'training'=>[], 'credentials'=>[],
    'work_logs'=>['track_id'=>$trackId], 'goals'=>['track_id'=>$trackId], 'goal_templates'=>[], 'equipment_detail'=>['id'=>$equipId], 'reservations'=>[],
    'workflow_briefing'=>['client_id'=>1]];
// Every key path in a reply (".client.name", ".messages[].id"), and the paths of lists that are empty in it.
$keyPaths=function($v,string $p='',array &$empty=[]) use (&$keyPaths): array {
    if (!is_array($v)) return [];
    if (array_is_list($v)) { if (!$v) { $empty[]=$p; return []; } return $keyPaths($v[0],$p.'[]',$empty); }
    $out=[];
    foreach ($v as $k=>$x) { $out[]=$p.'.'.$k; foreach ($keyPaths($x,$p.'.'.$k,$empty) as $q) $out[]=$q; }
    return $out;
};
$dir=$root.'/android/app/src/test/resources/api';
$write=getenv('CMS_WRITE_ANDROID_FIXTURES')==='1';
if ($write && !is_dir($dir)) mkdir($dir,0755,true);
$checked=0; $missing=[];
foreach ($reads as $action=>$q) {
    $file="$dir/$action.json";
    try { $live=$call($action,[],$q); } catch (Throwable $e) {
        // An action with a sample must still answer; one without (this database cannot answer it) is skipped.
        if (!$write && is_file($file)) $missing[]="$action (no longer answers: ".substr($e->getMessage(),0,80).')';
        continue;
    }
    if ($write) { file_put_contents($file,json_encode($live,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"); continue; }
    if (!is_file($file)) continue;
    $sample=json_decode((string)file_get_contents($file),true);
    $emptyLists=[];
    $have=array_flip($keyPaths($live,'',$emptyLists));
    // The kind of each value (text, number, list, object) must also be the same where both have one.
    $kinds=function($v,string $p='',array &$out=[]) use (&$kinds): array {
        $kind=is_array($v) ? (!$v ? 'empty' : (array_is_list($v) ? 'list' : 'object')) : (is_bool($v) ? 'bool' : (is_int($v)||is_float($v) ? 'number' : (is_string($v) ? 'text' : 'null')));
        $out[$p]=$kind;
        if (is_array($v)) { if (array_is_list($v)) { if ($v) $kinds($v[0],$p.'[]',$out); } else foreach ($v as $k=>$x) $kinds($x,$p.'.'.$k,$out); }
        return $out;
    };
    $liveKinds=$kinds($live); $sampleKinds=$kinds($sample);
    foreach ($sampleKinds as $path=>$k) {
        $lk=$liveKinds[$path] ?? null;
        if ($lk===null || $k==='null' || $lk==='null' || $k===$lk) continue;
        // An empty JSON object and an empty list look alike once decoded (either may stand for the other), and
        // amounts come as numbers or text.
        $same=fn(string $a,string $b)=>($a==='empty' && in_array($b,['list','object'],true)) || ($b==='empty' && in_array($a,['list','object'],true)) || (in_array($a,['number','text'],true) && in_array($b,['number','text'],true));
        if (!$same($k,$lk)) $missing[]="$action$path (was $k, now $lk)";
    }
    $noSample=[];
    foreach ($keyPaths($sample,'',$noSample) as $path) {
        if (isset($have[$path])) continue;
        // Under a list that is empty in today's reply there is nothing to compare.
        foreach ($emptyLists as $e) if (str_starts_with($path,$e.'[]')) continue 2;
        $missing[]=$action.$path;
    }
    $checked++;
}
$samples=count(glob("$dir/*.json"));
if (!$write) check($checked===$samples && !$missing,'The server still sends every field the Android app\'s samples have ('.$checked.' replies)'.($missing?': missing '.implode(', ',array_slice($missing,0,8)):''));
