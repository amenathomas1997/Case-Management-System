<?php
// Included by full_schema.php after the v431 files: 4.31.1, the plain product. The hand-over
// marker cookie was renamed, so a marker set under the name used before still holds until it
// expires and is removed by a full sign-in; nothing else that ends in the same words counts
// when it is not a future time.

$savedCookies4311=$_COOKIE;
$_COOKIE=['old_handover'=>(string)(time()+600)];
check(handover_marked(),'A hand-over marker set under the name used before 4.31.1 still holds while it lasts');
handover_clear();
check(!handover_marked() && !isset($_COOKIE['old_handover']),'A full sign-in removes the marker kept under the earlier name too');
$_COOKIE=['old_handover'=>(string)(time()-10),'x_handover'=>'yes'];
check(!handover_marked(),'An expired or malformed marker under another name does not count');
$_COOKIE=[HANDOVER_COOKIE=>(string)(time()+600)];
check(HANDOVER_COOKIE==='cms_handover' && handover_marked(),'The marker now uses the neutral name cms_handover');
$_COOKIE=$savedCookies4311;
