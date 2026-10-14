<?php
// The VR counselor portal now lives in the unified referral form. A language
// chosen in the old link (?lang=es) is carried across, so the form opens in it.
$q = ['role' => 'counselor'];
if (in_array($_GET['lang'] ?? '', ['en', 'es'], true)) $q['lang'] = $_GET['lang'];
header('Location: community_referral.php?' . http_build_query($q), true, 301);
exit;
