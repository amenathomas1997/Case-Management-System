# When something goes wrong

What to do, in order, for the problems most likely to stop the system. It is written for whoever looks after an installation. Keep a copy somewhere other than the server, for example printed or in your password manager's notes.

**Before you need this, write down:**
- your hosting company's support contact and your account login (in your password manager)
- the backup password (Settings shows only whether one is set)
- the address that receives the watchdog's alerts and the daily off-site backups (config.php, backup_email)
- where the private folder is (config.php, backups and uploads live there)

## The site does not open

1. Open `https://your-address/health.php`.
   - If it answers, the web server and PHP are running: go to step 3.
   - If it does not answer, the server or hosting is down: go to step 2.
2. Check your hosting company's status page, then contact their support. Nothing in the system can fix a server that is down.
   - The watchdog runs on the same server, so it cannot warn you when the whole server is down. An outside monitor (such as UptimeRobot, free) checking `health.php` every 5 minutes can; set one up now.
3. If health.php reports `"db": "error"`, the database is down or its password changed.
   - Check the database in your hosting control panel.
   - If its password changed, put the new one in config.php.
4. Pages show "Something went wrong":
   - System health lists the page and job errors of the last 7 days, and the watchdog emails them within the hour.
   - The server's PHP error log has the detail. Its lines can contain client details (a database error can quote a value), so send them only to someone covered by your business associate agreement.

## An update went wrong

- **Software update says an update did not finish:** choose "Put the previous files back". The database keeps the new version's changes, which the previous version works with.
- **The site broke after an update, with no such message:**
  - The files the update replaced are in the private folder's `updates/backup-<old>-to-<new>-<date>.zip`.
  - Unzip it over the web folder: `docroot/` files go to the web folder, `cron/` files to the cron folder.
- **Updated by hand from an install bundle:** put back the copy of the web folder you made first, as the bundle's README.txt says to.

## The server or the database is lost

You need the newest off-site backup email and the backup password.

1. Get hosting: PHP 8.1+, MariaDB 10.4+, HTTPS.
2. Create an empty database and user.
3. Upload the install bundle's `docroot/` to the web folder (the same version you ran, or newer). Do not run install.php.
4. On the server, run `dist/restore.php` (it is in the install bundle):
   - **Command:** `php restore.php --zip=<backup.zip> --dsn="mysql:host=localhost;dbname=<db>;charset=utf8mb4" --db-user=<user> --private=<private folder> --timezone=<your time zone>`
   - `--timezone` is required: your organisation's time zone as on the old server's Settings, for example `America/Chicago` (restore.php refuses to run without it, so times are never shifted).
   - Copy the key file `meeting_tokens.key` from the old server's private folder into the new private folder (owned by the user the website runs as, and readable only by it: chmod 600). It is not in the backup. Without it, authenticator apps stop working and QuickBooks and the meeting accounts must be connected again.
   - It asks for the backup password and the database password.
   - **What it does:** restores the database and the uploaded documents, and writes config.php.
   - Help, "Restoring from a backup", has the details. Every full test run with a test database practises this whole path on made-up data (tests/restore_drill.php).
5. Write `inc/config_path.php` in the web folder: `<?php return '/path/to/private/folder';`
6. Copy the cron files and add the cron lines, as the install bundle's README.txt says.
7. Enter again what backups never hold: the Microsoft Graph and Entra secrets, the AI key and the Twilio token (Settings).
8. Sign in, check a few clients, and run Check for updates.

**What you lose:** at most one day of records, when the off-site job runs every night, as the installer's cron line sets it (45 2 * * *); an older installation set up to run it only on Mondays can lose up to a week, so check that line. Monday's copy carries every document, other days the documents changed in the last eight days.

## A password, key or device is lost or stolen

- **A staff member's phone or laptop:**
  - On their user page, untick Active and save. That ends every session at once, in browsers and the phone apps.
  - Use "Sign out of mobile app" too, and "Report lost" for a phone that is listed.
  - Have them set a new password and two-step sign-in, then tick Active again.
  - The phone apps keep only encrypted data, and only on phones with a screen lock.
- **An administrator account may be compromised:**
  - Reset its password and two-step sign-in from another administrator's account.
  - Read the audit log (Administration) for what it did.
- **config.php may have leaked** (it holds the database password and secrets):
  - Change the database password, the Microsoft Graph secret, the AI key and the Twilio token.
  - Update config.php and Settings.
- **The update signing key (publisher only):** if it is lost, no update can be published. If it is stolen, publish a release with a new key at once, and tell installations to update by hand once.
- **A privacy breach:** Administration, Privacy incidents records it and its deadlines. Health information breaches have legal notice deadlines; follow your privacy officer's plan.

## Email, texts or reminders stop

- System health shows each scheduled job's last run and the mail log.
- **A job has fallen behind:** check the cron line in the hosting control panel.
- **Mail is failing:** the Microsoft Graph secret may have expired; System health shows its expiry date.
- **Texts are failing:** Administration, Text messages shows each text's delivery status and Twilio's error.
