# Release 3.0.28 — October 15, 2026

Everything from the "what else should we do" list except push notifications. Production baseline: 3.0.27 (3998e45).

Database migration: dist/migrations/mig_3.0.28.sql adds scheduled_sessions.checked_in_at (additive, safe to re-run). Apply before publishing files.

- Portal: "Your progress" per active track (goals met, hours used of authorized, next session, homework to do), token-scoped, Spanish included.
- Staff: inc/release_notes.php; Dashboard "New in {version}" notice with a per-user Got it; help.php#whats-new.
- capacity.php: authorized vs logged vs remaining vs scheduled hours per instructor for the chosen period, against weekly hours; caseload-scoped.
- Scheduled report emails: inc/scheduled_reports.php (outcome and funder queries extracted from outcomes.php / funder_report.php without output change); admin.php "Scheduled reports" settings; cron/monthly_reports.php sends on the configured day, once per period, audited.
- Health: public/health.php JSON (200/503, light rate limit); heartbeats from daily_alerts and watchdog; daily_alerts emails administrators when the watchdog or nightly backup is stale. Guide: poll <base_url>/health.php every 5 minutes with an outside monitor.
- iPhone 3.0.28 (33): App Intents (Log hours, Add a note, Open voice assistant, Today's sessions) with Siri phrases; CaseManagementWidget target (App Group group.org.example.cms; today's sessions, no names; falls back to "Open the app" when no snapshot); Check in on today's sessions (API check_in_session; offline queued; 409 treated as done).
- Server: check_in_session (perm schedule, −12h..+30min window, audited); schedule items carry checked_in_at; schedule.php shows "Arrived HH:MM".
- Installer: optional support phone and privacy operator/address/law/hosting; tests/installer_e2e.sh (18 checks, runs in run_all.sh when CMS_TEST_DSN is set); dist/make_staging.sh builds a scrubbed staging copy (credentials cleared, mail disabled via $CONFIG['mail_disabled'] honoured by send_app_mail, staging admin printed once).
- Operations done outside the code today: restore drill passed (backup restored locally, counts match, copy deleted); production database password rotated (config.php + db.cnf, verified).
- Device builds: the widget's App Group must exist on the App ID in the Apple developer portal; Xcode's automatic signing usually adds it when archiving.

## Deployment verified

Published October 15, 2026 from 05df806 with .audit_work/deploy-3.0.28/deploy.sh, then the health.php follow-up (never-recorded heartbeat is not a failure) as a one-file hotfix. Production 3.0.27 matched the baseline; backups at /home/cmsuser/casemanagement_private/deploy-3.0.28-20261015-121734/ (website, cron and database, verified); mig_3.0.28.sql applied and scheduled_sessions.checked_in_at confirmed; 19 files installed, server syntax check passed, hashes match, .htaccess unchanged; live pages 200, admin 302, api 401, updates.json and sign-in page report 3.0.28. Verified before publishing: run_all incl. installer_e2e (18 checks); full_schema 898; audit_regression 61; security 63; 134 rendered pages scanned clean; iPhone and widget clean build. iOS 3.0.28 (33) archived.
