# Release 3.0.22 — October 15, 2026

Status: committed, NOT deployed. Production is 3.0.21 (deployed October 14, 2026 from 17ce95e).

No database migration. Deploy the changed website files only; preserve hosting .htaccess, private configuration, installer lock and custom branding assets. Run the usual read-only checksum comparison against production (expect 3.0.21 = 17ce95e) and back up the docroot before publishing. No iOS distribution: ios/project.yml stays at 3.0.21, and the two iOS fixes below ship only with a future archive.

## Contents

Production audit of the ChatGPT/Codex work since c4d0956 (three reviewers; no high-severity findings), plus link and screen-reader clean-up:

- Breadcrumb navigation on staff pages (new public/inc/navigation.php; client, client edit, track and track edit pages name their parents).
- The Go back / Dashboard / main-menu shortcuts and breadcrumbs appear only for signed-in staff, not on the client portal, referral, consent, survey or sign-in pages. No empty breadcrumb landmark on the Dashboard.
- "Low-vision controls" is a real heading again (moved out of the disclosure button); the disclosure button reads "Show display options".
- The "Main navigation" shortcut link is now "Go to main menu", so it no longer sounds like the Main navigation landmark.
- Voice assistant (web): after an unclear answer to a confirmation, only a bare yes or no answers it; any other input cancels the waiting change instead of saving it. Silence message names Start conversation.
- Accessible HTML documents that are emailed or zipped no longer contain CMS links; staff downloads keep them.
- Unsaved-change protection keeps the browser warning when the session check refuses a submission; Go back ignores a #fragment when comparing pages.
- Required dropdowns stay dropdowns when "Show dropdown choices as lists" is on.
- Device follow-up "Still in use?" uses a native fieldset and legend.
- Referral and waitlist links include the date received, so look-alike referrals have distinct names.
- RSA-911 CSV no longer appends a trailer row after the data; invoice list heading no longer double-escapes; audit.php docblock order; release notes keep the migration chain.
- iOS (not distributed): VoiceOver confirmations say how to answer; goals read "Goal 2 of 5" instead of database ids.

## Verification

- PHP syntax check of every production file passed.
- tests/run_all.sh passed (Node, PHP, Python and Swift API checks, including the new breadcrumb test).
- Local cms_audit_test: full_schema 823, audit_regression 61 and security 63 assertions passed.
- iOS simulator build succeeded.
- Not done: manual JAWS (Chrome/Edge), NVDA and VoiceOver walkthroughs of the breadcrumbs, the low-vision heading, the unsaved-change dialog and the voice confirmation.

## Deploy file list (relative to 3.0.21)

`git diff --name-only 17ce95e HEAD -- public cron`
