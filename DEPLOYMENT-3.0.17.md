# AT-only referral update — October 12, 2026

Published 3.0.17 to https://cms.example.org/ as the requested follow-up to the website deployment.

The public form now focuses on AT assessment, training, demonstrations, recommendations, and technology-related needs. Removed the school-only Pre-ETS checklist, general braille-literacy option, and general independent-living option; retained braille devices and explicitly technology-based daily-living support. Description prompts now ask about tasks, devices/software, and accessibility barriers. Spanish wording was updated.

VR counselors and schools remain valid referral sources. New school AT referrals no longer automatically convert to Pre-ETS clients. Existing records are not rewritten; explicitly historical Pre-ETS requests retain their classification and details. Retired service selections from stale forms are refused with a review message. Existing configurable form settings are preserved; production uses the default lists.

Validation: 708 isolated integration assertions passed, plus referral language-state tests, PHP syntax checks, and whitespace checks. Live read-only verification covered all seven referral-source variants and the Spanish form. No production referral was submitted and no test notification was sent.

Deployed five files: community_referral.php, inc/referral_request.php, inc/lang_es.php, inc/core.php, and updates.json. Pre-deployment checksums matched the live 3.0.16 baseline. Private application, database, cron, and configuration backups were created and verified in `/home/cmsuser/casemanagement_private/deploy-3.0.17-20261012/`. A brief maintenance response and automatic file rollback protected activation; no rollback was needed. Original hosting rules were restored byte for byte. No database migration was required. Companion iOS distribution and the generic distribution archive were not changed.
