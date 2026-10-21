# Release 3.0.21 — October 14, 2026

Production baseline verified as 3.0.20. Apply the additive mig_3.0.21.sql before publishing files. Preserve hosting .htaccess, private configuration, installer lock and custom branding assets. Back up website, private directory and database before migrating. Deploy the committed website and cron files; no iOS distribution is included.

Includes round-five audit fixes and accessibility follow-up: navigation, landmarks, user guide, clearer links, unsaved-form warnings, collapsible display controls, per-account toolbar visibility, optional native expanded lists and alternate date/time text entry.

Validation: 823 isolated database assertions; non-database suites; 144 synthetic browser pages and 576 reflow checks; 350 labelled controls; real Chrome date-entry tests. See AUDIT-FOLLOWUP-2026-10-14.md for details. WCAG AAA and the JAWS unselected announcement remain unverified; this release makes no certification claim.

## Deployment verified

Published on October 14, 2026 from commit 17ce95e. Production 3.0.20 matched all 154 expected baseline hashes before publication. Backups are server-private at <private dir>/deploy-3.0.21-20261014/: database-before.sql.gz, website-before.tar.gz and private-before.tar.gz. Archive integrity checks passed. Release tar SHA-256: a95eb45f0c246efe7edcff1d469c35a82ad21c29a152f3241ddae4aa058f3fe9.

Server PHP 8.1 syntax checks passed. Seven additive migration statements completed; all new columns and replay indexes verified. All 158 published website/cron hashes match. Hosting .htaccess checksum is unchanged. Branding and configuration files were excluded from the release overlay.

Live HTTPS checks passed for login, referral and help; all display Version 3.0.21 and load the new accessibility assets. Dashboard and account require sign-in. updates.json reports 3.0.21. The application reports a healthy database connection; no recently modified application error_log was found in the checked document-root depth. Authenticated production workflows were not exercised with client data. Manual JAWS/VoiceOver testing remains outstanding.

The original website and cron files can be restored from the private backups if needed; the additive migration is backward-compatible with 3.0.20. Do not restore the database backup over newer client activity without separately reconciling those records. No iOS App Store/TestFlight release and no GitHub push were performed.
