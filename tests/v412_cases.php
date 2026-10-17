<?php
// Included by full_schema.php after v411_cases.php, against its synthetic database only:
// 4.12.0: the Android app uses the same API; its client views are logged as the Android app's.
$views=fn(string $where)=>(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='client.view' AND entity_id=1 AND detail=".$db->quote($where))->fetchColumn();
$before=$views('Android app');
putenv('CMS_TEST_APP_VERSION=4.12.0 (1) Android');
$call('client',[],['id'=>'1']);
putenv('CMS_TEST_APP_VERSION');
check($views('Android app')===$before+1,'A client opened in the Android app is logged as the Android app\'s view');
$db->exec("DELETE FROM audit_log WHERE action='client.view' AND entity_id=1 AND detail='iPhone app'");
$call('client',[],['id'=>'1']);
check($views('iPhone app')===1,'Without the Android app\'s version, a view is the iPhone app\'s as before');
