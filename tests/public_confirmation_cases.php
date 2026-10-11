<?php
// Exercise real public POST routes with synthetic tokens and forced storage errors.
// Each is posted by an anonymous visitor, as a client or counselor holding the link
// is: the session holds only the form token, never a staff sign-in.
putenv('CMS_TEST_ANON=1'); putenv('CMS_TEST_SESSION');
$cases=[
 ['ack','report_acks','acked_at',['confirm'=>'1'],"INSERT INTO report_acks(client_id,track_id,token,sent_to) VALUES(1,1,?,'test@example.invalid')"],
 ['consent','consent_forms','signed_at',['signature'=>'Synthetic Guardian'],"INSERT INTO consent_forms(client_id,guardian_name,guardian_email,token) VALUES(1,'Synthetic Guardian','test@example.invalid',?)"],
 ['survey','surveys','completed_at',['satisfaction'=>'8','goals_met'=>'9','comments'=>'Synthetic feedback'],"INSERT INTO surveys(client_id,token) VALUES(1,?)"],
];
foreach($cases as [$route,$table,$column,$post,$insert]){
    $token=bin2hex(random_bytes(24));$db->prepare($insert)->execute([$token]);$id=(int)$db->lastInsertId();
    $source=file_get_contents($root.'/public/'.$route.'.php');
    $source=swap_required("require __DIR__ . '/inc/bootstrap.php';",'require '.var_export($tmp.'/bootstrap.php',true).';',$source,"public/$route.php");
    $source=str_replace('__DIR__',var_export($root.'/public',true),$source);file_put_contents($tmp.'/'.$route.'.php',$source);
    $submit=function()use($root,$tmp,$route,$token,$post):array{
        $p=proc_open([PHP_BINARY,'-d','display_errors=stderr','-d','log_errors=0',$root.'/tests/full_schema.php','page_post',$tmp.'/'.$route.'.php',json_encode(['t'=>$token]),json_encode($post)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];
    };
    $db->exec("CREATE TRIGGER confirmation_failure BEFORE INSERT ON client_notes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic confirmation failure'");
    [$code]=$submit();
    check($code!==0&&$db->query("SELECT $column FROM $table WHERE id=$id")->fetchColumn()===null,"$route completion rolls back if the case note cannot save");
    $db->exec('DROP TRIGGER confirmation_failure');
    $before=(int)$db->query('SELECT COUNT(*) FROM client_notes')->fetchColumn();
    [$code,$out,$err]=$submit();
    check($code===0&&$err===''&&$db->query("SELECT $column FROM $table WHERE id=$id")->fetchColumn()!==null,"$route confirmation retries successfully after storage recovers");
    $submit();
    check((int)$db->query('SELECT COUNT(*) FROM client_notes')->fetchColumn()===$before+1,"$route repeated confirmation creates exactly one case note");
}
putenv('CMS_TEST_ANON');
