# Release 3.0.32 — October 15, 2026

Web + iPhone 3.0.32 (35); no migration. Production baseline: 3.0.31 (da9e6e8). Follows the owner's "do all suggestions" after the 3.0.31 deploy.

- **Session completion everywhere.** api.php complete_session takes hours (0 completes without hours), mileage and session_note, and replies with note_saved. The iPhone gets a staff-only Complete session step (hours prefilled, miles, note) before the client's signature or none; its confirmation is built from the server's note_saved. On the website, group completion has one Hours field for each client who attended, prefilled from the first member on a track. Groups get no shared miles or note, because those would bill or disclose across clients.
- **Consent race.** A guardian consent request on the website (client.php) or through the app (inc/api_parity.php) never replaces a request sent since the page or app last showed it (consent_seen). The app path now locks the client row and sends in one transaction, with a savepoint, as the website does.
- **Double-submit guards** (web_idem_field + web_once) on 17 create forms: incidents, device requests and quotes, contract spend, homework, message replies, AT records, equipment items and services, new clients, intake, KB articles, four template kinds, curriculum, competency ratings.
- **Input hygiene.** Admin forms (admin, users, programs, templates, federation) treat array input as blank instead of raising a TypeError.
- **AI evaluation set.** tests/ai_eval/ holds 8 synthetic cases, a scorer and offline fixture checks (in run_all). `--live` runs them on a test install before any prompt or model change.
- **Tests.** The lock-race tests allow 30 s for worker processes to queue. New: consent_race_cases.php, hygiene_static.php, and more in session_record_cases.php.
- **Review fixes before release:**
  - session_note limits are checked in bytes.
  - The group hours default is taken from a member on a track.
  - The consent ordering is deterministic.
  - The iPhone note message comes from the server, and the Complete session sheet can't be swiped away with typed text.
- **Checked, nothing to change:**
  - F119 (verify without login_challenge) is already refused.
  - "Headings before h1": every page prints its h1 first, apart from the intended low-vision and menu headings.
- **Cron check (2026-10-15, read-only):** all 8 jobs are in the crontab, the server files match the repo, and each job's last output shows it running on schedule. at_reminders writes to /dev/null, so its runs are not logged. daily_alerts records its first heartbeat at the next 7:00 run (the heartbeat code arrived after today's run).

Files to deploy: the public/ files in `git diff --name-only v3.0.31 HEAD -- public`. No migration. iPhone: archive 3.0.32 (35).

Verified before commit: full_schema 920; audit_regression 61; security 67; staging_scrub 34; run_all (incl. hygiene_static and ai_eval); php -l on all public and inc PHP; iPhone build succeeded.

## Deployment verified

Published October 15, 2026, 10:26 p.m. Central, from 80058d4 (main at dca6b3e after the CI merge; the website files are the same) with .audit_work/deploy-3.0.32/deploy.sh. Production 3.0.31 matched the baseline (23 files). Backups (website, cron, database 179,622 bytes, verified) are at /home/cmsuser/casemanagement_private/deploy-3.0.32-20261015-222640/. 23 files installed; the server syntax check passed, hashes match, .htaccess unchanged. Live: login, community_referral, help and privacy 200; admin 302; api 401; health 200; updates.json and the sign-in page report 3.0.32. No migration.

Crontab: the at_reminders line now appends to cron.log instead of /dev/null (the only line changed; the previous crontab is saved as crontab-before.txt in the backup folder). Its first logged run is 8:00 a.m. on October 16.

CI: .github/workflows/ci.yml merged (dca6b3e) and runs on every push. iPhone 3.0.32 (35) not archived yet.
