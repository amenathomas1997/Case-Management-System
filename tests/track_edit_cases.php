<?php
$source=file_get_contents($root.'/public/track_edit.php');
$source=swap_required("require __DIR__ . '/inc/bootstrap.php';",'require '.var_export($tmp.'/bootstrap.php',true).';',$source,'public/track_edit.php');
$source=str_replace('__DIR__',var_export($root.'/public',true),$source);file_put_contents($tmp.'/track_edit.php',$source);
$editTrack=function(array $changes=[])use($root,$tmp):array{
    $post=array_replace(['title'=>'Synthetic revised track','auth_number'=>'AUTH-TEST','funding_agency'=>'Test funder','authorized_hours'=>'120','hourly_rate'=>'90','start_date'=>'2026-01-01','expiration_date'=>'2027-01-01'],$changes);
    $p=proc_open([PHP_BINARY,'-d','display_errors=stderr','-d','log_errors=0',$root.'/tests/full_schema.php','page_post',$tmp.'/track_edit.php',json_encode(['id'=>1]),json_encode($post)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];
};
$db->exec("INSERT IGNORE INTO sent_alerts(track_id,alert_key) VALUES(1,'pct80')");
$old=$db->query('SELECT * FROM training_tracks WHERE id=1')->fetch();
$count=(int)$db->query('SELECT COUNT(*) FROM auth_amendments WHERE track_id=1')->fetchColumn();
$db->exec("CREATE TRIGGER track_edit_failure BEFORE UPDATE ON training_tracks FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic track failure'");
[$code]=$editTrack();
check($code!==0&&(int)$db->query('SELECT COUNT(*) FROM auth_amendments WHERE track_id=1')->fetchColumn()===$count&&(int)$db->query("SELECT COUNT(*) FROM sent_alerts WHERE track_id=1 AND alert_key='pct80'")->fetchColumn()===1,'Failed authorization update preserves amendment history and sent alerts');
$db->exec('DROP TRIGGER track_edit_failure');
[$code,$out,$err]=$editTrack(['expiration_date'=>'2026-02-31']);
check($code===0&&$err===''&&str_contains($out,'Use a valid expiration date')&&$db->query('SELECT title FROM training_tracks WHERE id=1')->fetchColumn()===$old['title'],'Invalid authorization dates render validation without updating the track');
// Work logged but not yet invoiced is re-priced with a rate correction; invoiced work is not.
$db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description,hourly_rate) VALUES(1,'2026-01-08',1,'Unbilled at the old rate',?)")->execute([$old['hourly_rate']]);$unbilled=(int)$db->lastInsertId();
// Logs on Submitted/Paid invoices keep their rate; logs on Draft invoices are re-priced with the unbilled ones (pass E).
// A Submitted invoice (already sent to the funder) and a Draft invoice, each with one billed log at the old rate.
$db->prepare("INSERT INTO invoices(track_id,invoice_number,status,hourly_rate,hours,hours_amount,total_amount) VALUES(1,'TE-SUBMITTED','Submitted',?,1,?,?)")->execute([$old['hourly_rate'],$old['hourly_rate'],$old['hourly_rate']]);$sentInvoice=(int)$db->lastInsertId();
$db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description,hourly_rate,is_billed,invoice_id) VALUES(1,'2026-01-09',1,'Billed on a submitted invoice',?,1,?)")->execute([$old['hourly_rate'],$sentInvoice]);$sentLog=(int)$db->lastInsertId();
$db->prepare("INSERT INTO invoices(track_id,invoice_number,status,hourly_rate,hours,hours_amount,expenses_amount,total_amount) VALUES(1,'TE-DRAFT','Draft',?,2,?,5,?)")->execute([$old['hourly_rate'],2*$old['hourly_rate'],2*$old['hourly_rate']+5]);$draftInvoice=(int)$db->lastInsertId();
$db->prepare("INSERT INTO work_logs(track_id,work_date,hours,description,hourly_rate,is_billed,invoice_id) VALUES(1,'2026-01-10',2,'Billed on a draft invoice',?,1,?)")->execute([$old['hourly_rate'],$draftInvoice]);$draftLog=(int)$db->lastInsertId();
$sentBefore=$db->query("SELECT hourly_rate,hours_amount,total_amount FROM invoices WHERE id=$sentInvoice")->fetch();
$billedRates=$db->query("SELECT w.id,w.hourly_rate FROM work_logs w JOIN invoices i ON i.id=w.invoice_id WHERE w.track_id=1 AND w.is_billed=1 AND i.status<>'Draft' ORDER BY w.id")->fetchAll(PDO::FETCH_KEY_PAIR);
[$code,$out,$err]=$editTrack();
check($code===0&&$err===''&&(int)$db->query('SELECT COUNT(*) FROM auth_amendments WHERE track_id=1')->fetchColumn()===$count+1&&(int)$db->query('SELECT COUNT(*) FROM sent_alerts WHERE track_id=1')->fetchColumn()===0,'Successful authorization update saves history and resets alerts together');
check((float)$old['hourly_rate']!==90.0&&(float)$db->query("SELECT hourly_rate FROM work_logs WHERE id=$unbilled")->fetchColumn()===90.0
    &&isset($billedRates[$sentLog])&&$db->query("SELECT w.id,w.hourly_rate FROM work_logs w JOIN invoices i ON i.id=w.invoice_id WHERE w.track_id=1 AND w.is_billed=1 AND i.status<>'Draft' ORDER BY w.id")->fetchAll(PDO::FETCH_KEY_PAIR)===$billedRates
    &&$db->query("SELECT hourly_rate,hours_amount,total_amount FROM invoices WHERE id=$sentInvoice")->fetch()===$sentBefore,
    'A rate change re-prices unbilled and draft-invoiced work; submitted or paid work and its invoice keep their rate and amount');
$draft=$db->query("SELECT hourly_rate,hours_amount,total_amount FROM invoices WHERE id=$draftInvoice")->fetch();
check((float)$db->query("SELECT hourly_rate FROM work_logs WHERE id=$draftLog")->fetchColumn()===90.0
    &&(float)$draft['hourly_rate']===90.0&&(float)$draft['hours_amount']===180.0&&(float)$draft['total_amount']===185.0,
    'A rate change re-prices a draft invoice\'s work and re-totals the draft at the new rate, expenses kept');
$db->exec("DELETE FROM work_logs WHERE id IN ($unbilled,$sentLog,$draftLog)");
$db->exec("DELETE FROM invoices WHERE id IN ($sentInvoice,$draftInvoice)");
// A new authorization number reaches the header a Draft invoice captured (with the
// track's funding agency); a Submitted invoice keeps what it was sent with, and a
// Draft made before headers were captured (no auth_number) shows the track's anyway.
$db->exec("INSERT INTO invoices(track_id,invoice_number,status,auth_number,funding_agency) VALUES(1,'TE-HDR-DRAFT','Draft','AUTH-TEST','Earlier funder'),(1,'TE-HDR-OLD','Draft',NULL,NULL),(1,'TE-HDR-SENT','Submitted','AUTH-TEST','Earlier funder')");
$header=fn(string $number):array=>$db->query("SELECT auth_number,funding_agency FROM invoices WHERE invoice_number='$number'")->fetch();
[$code,$out,$err]=$editTrack(['auth_number'=>'AUTH-RENEWED']);
check($code===0&&$err===''&&$header('TE-HDR-DRAFT')===['auth_number'=>'AUTH-RENEWED','funding_agency'=>'Test funder']
    &&$header('TE-HDR-OLD')===['auth_number'=>null,'funding_agency'=>null]&&$header('TE-HDR-SENT')===['auth_number'=>'AUTH-TEST','funding_agency'=>'Earlier funder'],
    'A new authorization number updates the header of a Draft invoice that captured one, and leaves a submitted invoice and an uncaptured draft as they were'.($err?': '.$err:''));
$db->exec("DELETE FROM invoices WHERE invoice_number IN ('TE-HDR-DRAFT','TE-HDR-OLD','TE-HDR-SENT')");
