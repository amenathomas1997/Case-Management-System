# Release 3.0.33 — October 15, 2026

Web + cron; no migration. Production baseline: 3.0.32 (80058d4). The maintainer asked for a full health dashboard.

- **System health** (public/system_health.php, administrators only; linked from Administration, which also says on one line whether every scheduled job is running). Its sections:
  - A summary of problems, each linking to its section.
  - Every scheduled job, with its schedule, status, last good run and details.
  - Backups: the newest dump and its size, how many are kept, the uploads archive, the last off-site email.
  - Email: Graph or server mail, the last email sent, the last that failed.
  - Storage: free disk, uploaded documents, the cron log.
  - Database and versions.
  - Sign-in and security: failed sign-ins in 24 hours, staff with an authenticator, iPhone app sessions.
  - AI assistant: requests and failures over 7 days.
  - Recent problem lines in cron.log and the watchdog's open alerts.
  - Work waiting: ended sessions with no outcome, old new referrals, open portal requests, old unsigned consents.
  - The page is read-only.
- **Heartbeats for every job.**
  - record_heartbeat() is new in core.php. at_reminders, ingest_email, monthly_reports, session_reminders and weekly_offsite now record one; watchdog and daily_alerts already did.
  - The nightly backup is judged by its newest dump file.
- **Shared checks.**
  - cms_cron_jobs() and health_job_rows() in inc/health_checks.php are used by health.php (whose JSON fields are unchanged), System health, Administration and the watchdog.
  - Limits: watchdog 2 hours; email filing 1 hour; session reminders 15 hours; daily alerts, progress reports and AT reminders 36 hours; backup 48 hours; off-site backup 8 days.
  - A job with no run yet gets its limit as grace from when it was first seen missing.
- **The watchdog emails the backup address** (at most every 6 hours) when any other job falls behind ("CMS health alert: scheduled job").
- **Mail outcomes.** send_app_mail() and send_app_mail_attach() record mail_last_sent and mail_last_failed (times only).
- **Tests.**
  - tests/health_checks.php now covers every job, the grace rule, the backup directory and the shared use.
  - full_schema renders system_health.
  - cron_regression stubs record_heartbeat.
- **Staff guide.** "How do I monitor the system?" is rewritten for System health.

After deploy: the new jobs show "Waiting for its first run" until their next scheduled run (email filing within 15 minutes; the morning jobs at 7:00 to 8:00; the off-site backup next Monday).

Files: public/health.php, public/system_health.php (new), public/admin.php, public/inc/core.php, public/inc/health_checks.php, public/inc/staff_guide.php, public/inc/release_notes.php, public/updates.json, and cron/at_reminders.php, ingest_email.php, monthly_reports.php, session_reminders.php, watchdog.php, weekly_offsite.php.

Verified before commit:
- full_schema 923; audit_regression; security; staging_scrub; run_all (including the new health checks and cron_regression).
- php -l on all PHP files.
- The rendered page was read as text.

## Deployment verified

Published October 15, 2026, 11:14 p.m. Central, with .audit_work/deploy-3.0.33/deploy.sh.

- Production 3.0.32 matched the baseline (13 files; system_health.php is new).
- Backups (website, cron, and the database at 179,632 bytes, verified) are at <private dir>/deploy-3.0.33-20261015-231402/.
- 14 files were installed (8 public, 6 cron). The server syntax check passed, the hashes match, and .htaccess is unchanged.
- Live checks: login, community_referral, help and privacy answer 200; admin and system_health redirect (302) to sign-in; api answers 401; updates.json and the sign-in page report 3.0.33.
- health.php answers 200 with all nine checks OK. The watchdog and backup are fresh. The other jobs are waiting for their first heartbeat; daily alerts keeps its earlier deadline of October 17, 9:31 a.m.
