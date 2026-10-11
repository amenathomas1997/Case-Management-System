<?php
// Self-referral now lives in the unified referral form.
header('Location: community_referral.php?role=self', true, 301);
exit;
