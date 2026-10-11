<?php
// Included by the synthetic, fresh-schema integration suite.
$makeDoc=function()use($db,$tmp):int{
    file_put_contents($tmp.'/trash-test.txt','Synthetic document');
    $db->exec("INSERT INTO client_documents(client_id,category,title,orig_name,path) VALUES(1,'Other','Trash test','trash-test.txt','trash-test.txt')");
    return (int)$db->lastInsertId();
};
$docId=$makeDoc();
$db->exec("CREATE TRIGGER test_delete_failure BEFORE DELETE ON client_documents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic delete failure'");
try{trash_row('client_documents',$docId,1,'test');throw new RuntimeException('Deletion should fail');}catch(PDOException $e){}
check((int)$db->query("SELECT COUNT(*) FROM trash WHERE entity_id=$docId AND entity='client_documents'")->fetchColumn()===0,'Failed deletion rolls back its trash snapshot');
$db->exec('DROP TRIGGER test_delete_failure');
check(trash_row('client_documents',$docId,1,'test')&&!trash_row('client_documents',$docId,1,'test'),'Repeated deletion creates only one trash snapshot');
$trashId=(int)$db->query("SELECT id FROM trash WHERE entity_id=$docId AND entity='client_documents'")->fetchColumn();
$db->exec("CREATE TRIGGER test_restore_failure BEFORE DELETE ON trash FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic cleanup failure'");
try{restore_trash($trashId);throw new RuntimeException('Restore should fail');}catch(PDOException $e){}
check((int)$db->query("SELECT COUNT(*) FROM client_documents WHERE id=$docId")->fetchColumn()===0,'Failed restore cleanup rolls back the restored row');
$db->exec('DROP TRIGGER test_restore_failure');
check(restore_trash($trashId)!==null&&purge_trash($trashId)===null&&is_file($tmp.'/trash-test.txt'),'Restored documents survive stale cleanup requests');
trash_row('client_documents',$docId,1,'test');
$trashId=(int)$db->query("SELECT id FROM trash WHERE entity_id=$docId AND entity='client_documents'")->fetchColumn();
$db->exec("INSERT INTO trash(entity,entity_id,label,payload,deleted_by) SELECT entity,entity_id,label,payload,deleted_by FROM trash WHERE id=$trashId");
$duplicate=(int)$db->lastInsertId();
restore_trash($trashId);purge_trash($duplicate);
check(is_file($tmp.'/trash-test.txt'),'Purging a legacy duplicate never deletes a restored document file');
trash_row('client_documents',$docId,1,'test');
$trashId=(int)$db->query("SELECT id FROM trash WHERE entity_id=$docId AND entity='client_documents'")->fetchColumn();
check(purge_trash($trashId,true)===null,'Automatic cleanup preserves unexpired trash');
purge_trash($trashId);
check(!is_file($tmp.'/trash-test.txt'),'Permanent cleanup removes an unreferenced document file');
$logId=(int)$db->query('SELECT id FROM work_logs WHERE is_billed=1 LIMIT 1')->fetchColumn();
check($logId>0&&!trash_row('work_logs',$logId,1,'Billed work'),'Trash rechecks billed status under the invoice lock');
// Expenses share the trash with logged work: an unbilled one can be deleted and
// restored; one already on an invoice stays put.
$db->exec("INSERT INTO track_expenses(track_id,expense_date,description,amount) VALUES(50,'2026-01-06','Trash test expense',4.50)");$expenseId=(int)$db->lastInsertId();
$billedExpense=(int)$db->query('SELECT id FROM track_expenses WHERE is_billed=1 LIMIT 1')->fetchColumn();
check(trash_row('track_expenses',$expenseId,1,'Trash test expense')&&(int)$db->query("SELECT COUNT(*) FROM track_expenses WHERE id=$expenseId")->fetchColumn()===0,'An unbilled expense can be deleted to the trash');
check($billedExpense>0&&!trash_row('track_expenses',$billedExpense,1,'Billed expense')&&(int)$db->query("SELECT COUNT(*) FROM track_expenses WHERE id=$billedExpense")->fetchColumn()===1,'An expense already on an invoice cannot be deleted');
$trashId=(int)$db->query("SELECT id FROM trash WHERE entity_id=$expenseId AND entity='track_expenses'")->fetchColumn();
check($trashId>0&&restore_trash($trashId)!==null&&(float)$db->query("SELECT amount FROM track_expenses WHERE id=$expenseId")->fetchColumn()===4.5,'A deleted expense is restored from the trash');
$db->exec("DELETE FROM track_expenses WHERE id=$expenseId");
// Purging a deleted log removes its client signature image, unless a live log still uses the same file.
$signedLog=function(string $file)use($db):int{
    $db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description,signature_path) VALUES(50,'2026-01-07',1,'Signed synthetic work',?)")->execute([$file]);
    return (int)$db->lastInsertId();
};
file_put_contents($tmp.'/sig_trash_alone.png','synthetic');file_put_contents($tmp.'/sig_trash_shared.png','synthetic');
$alone=$signedLog('sig_trash_alone.png');$shared=$signedLog('sig_trash_shared.png');$stillSigned=$signedLog('sig_trash_shared.png');
foreach([$alone,$shared] as $logId){
    trash_row('work_logs',$logId,1,'Signed synthetic work');
    purge_trash((int)$db->query("SELECT id FROM trash WHERE entity_id=$logId AND entity='work_logs'")->fetchColumn());
}
check(!is_file($tmp.'/sig_trash_alone.png')&&is_file($tmp.'/sig_trash_shared.png'),'Purging a log removes its unused signature image and keeps one a live log still uses');
$db->exec("DELETE FROM work_logs WHERE id=$stillSigned");@unlink($tmp.'/sig_trash_shared.png');
