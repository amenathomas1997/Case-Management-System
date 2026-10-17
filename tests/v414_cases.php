<?php
// Included by full_schema.php after v412_cases.php, against its synthetic database only:
// 4.14.0: a booking from the apps accepts the organisation's own booking types.
set_setting('list_booking_types', json_encode(['Interpreter', 'Sign language interpreter', 'Other']));
$call('add_booking', ['client_id'=>1, 'service_type'=>'Sign language interpreter', 'needed_at'=>date('Y-m-d', strtotime('+3 days')).' 10:00', 'duration_min'=>60]);
check($db->query("SELECT COUNT(*) FROM access_bookings WHERE service_type='Sign language interpreter'")->fetchColumn()==1, 'A booking type an organisation added is accepted from the apps');
$call('add_booking', ['client_id'=>1, 'service_type'=>'Made-up type'], [], false, 'not a valid choice');
check(true, 'A booking type not on the list is still refused');
set_setting('list_booking_types', '');
