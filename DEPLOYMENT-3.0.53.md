# Release 3.0.53 — October 16, 2026

Web 3.0.53. Apply dist/migrations/mig_3.0.53.sql (it adds mail_log), and change the off-site backup cron line from `45 2 * * 1` to `45 2 * * *`.

From the roadmap section "Running the system safely":
- **Daily off-site backups** (cron/weekly_offsite.php).
  - Every morning the database and documents go out as an AES-256 ZIP to the backup address.
  - Full days (Mondays, the first run, or when a week has passed without a full copy) carry every document. Other days carry the documents changed since the last full copy, judged by the later of the modified and changed times.
  - If the full set cannot go, it falls back to the recent documents, then the database alone. The full copy is recorded only when every document went, so a failure is retried the next day.
  - Large sends are recorded in the mail log.
- **Backup check** (backup_check in inc/ops.php). It runs after each full run and from "Check the backup now" on System health. It checks that:
  - the newest dump decompresses to the end
  - the dump holds a CREATE TABLE for every live table
  - an encrypted copy opens again with the backup password
  With restore_test_dsn configured, the Monday job also imports the dump into that scratch database, compares the counts, and then empties it. Problems are emailed to the administrators.
- **Restore.** dist/restore.php is a command-line tool. It asks for the passwords, streams the dump in, refuses a database that is not empty unless --force is given, puts the documents back and writes config.php in the installer's shape (permissions 0600). Help has an administrator-only "Restoring from a backup" guide.
- **Watchdog.** It alerts when the uploaded-documents archive is more than 9 days old.
- **Mail log** (mail_log). It records when each email went, the kind of recipient (staff, client, counselor or backup) and the route. No address, subject or content is kept, and rows are pruned after 180 days. When email falls back from Graph to the server's own mail, the administrators get one in-app notice per day.
- **System health** gains:
  - a backup check result and button
  - "Send a test email to me"
  - the Graph secret expiry date, with warnings 30, 14 and 7 days before
  - the recent emails table
- **Tests:** tests/ops_cases.php is new.

Review: one reviewer found nothing serious. Every finding was fixed:
- the full copy is recorded consistently
- the restore no longer loads the whole dump into memory
- the backup check finds a table split across pieces
- count tolerance both ways
- no import from the web button
- the scratch database is emptied after the check
- inode change times are used for documents
- large sends are logged, and backup mail is labelled as backup
- umask is set in restore
- pruning is separated from the expiry alert

Verified before commit:
- full_schema 1273, audit_regression, security, staging_scrub, and run_all.
- The migration rehearsal matched.
- A manual restore into a scratch database succeeded, and a wrong password was refused.
- Not checked in a browser or with a screen reader.
