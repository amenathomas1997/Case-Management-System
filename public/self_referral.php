<?php
// Self-referral now lives in the unified referral form. A language chosen in the
// old link (?lang=es) is carried across, so the form opens in that language.
$q = ['role' => 'self'];
if (in_array($_GET['lang'] ?? '', ['en', 'es'], true)) $q['lang'] = $_GET['lang'];
header('Location: community_referral.php?' . http_build_query($q), true, 301);
exit;
