# Release 4.29.0 — October 19, 2026

Web 4.29.0, iPhone app 4.29.0 (build 60), Android app 4.29.0 (version code 13). Apply dist/migrations/mig_4.29.0.sql, or use Database upgrade; Software update applies it on its own.

**What the migration does:**
- **One account per Microsoft identity:** users.entra_oid becomes a unique key.
- **Duplicate links:** where a Microsoft identity was linked to more than one account, it stays on the lowest-numbered active account (or, if none is active, the lowest-numbered switched-off one). The other links are removed, and each removal is written to the audit log.
- **Version:** sets schema_version to 4.29.0.

**mig_3.0.31 change:** running it again no longer confirms addresses of clients created after 3.0.55.

**Not deployed with deploy.sh.** It is published as the GitHub release v4.29.0, and an administrator installs it with Administration, Software update. The phone apps are built from this tag.

**Why.** On 2026-10-18 the maintainer asked for one more ultra audit. Ten auditors covered the whole system; the case pages were split among four helpers, and two auditors looked only at the 4.28.0 changes. The report is AUDIT-2026-10-18c.md: no High, 16 Medium, about 70 Low, and no caseload leaks. Every finding is fixed here.

**Before or after installing (maintainer, in Microsoft Entra, App registrations, the CMS app):**
- **Authentication:** add a second Web redirect URI, `<your site>/login.php`. It is used when a device handed to a client for signing is given back: the browser is signed out of Microsoft too.
- **Token configuration:** add the optional ID-token claim **auth_time**, next to acct. After a hand-over, Microsoft sign-in needs a fresh sign-in, and the claim proves it. Without it, Microsoft sign-in on that browser falls back to password and code for 2 hours. Federation and System health say when the claim is missing.

**Restoring a backup now needs two things:**
- **The time zone:** `restore.php` requires `--timezone=<your time zone>`, or reads it from an existing config.php.
- **The key file:** copy `meeting_tokens.key` from the old server into the private folder. It must be owned by the user the website runs as (chmod 600).
  - If it is gone for good, System health, "Forget stored connections and keys" starts over safely.
  - It refuses when the file only needs its owner or permissions fixed.
  - Live portal, counselor and calendar links are no longer in backups, so they are sent again after a restore.

**Medium**
- **P1:** a Microsoft identity linked to a switched-off account is refused for sign-in, linking and auto-provisioning. Changing a switched-off account's email keeps the identity, so the refusal holds.
- **P2:** after a device is handed to a client to sign:
  - the browser is also signed out of Microsoft
  - for 2 hours, Microsoft sign-in needs a fresh sign-in and passkey sign-in is refused
  - a full password and code sign-in clears this
- **P3:** editing a track in the iPhone or Android app is protected against stale forms, as the website has been since 4.28.0. The second save names the fields that changed, Reload keeps typed changes, and iPhone drafts keep only the fields changed.
- **P4:** the retry guard now covers saving tasks and tracks and requesting consent from the apps.
- **P5:** "still being processed" keeps the retry key in both apps, so trying again never saves twice.
- **P6 (Android):** dialogs reopened after a rotation can no longer show another item's typed values. One case could save one client's waitlist notes onto another.
- **P7 (iPhone):** Reload after a client conflict no longer withdraws a colleague's texting agreement. Status and closure fields follow the reloaded record.
- **P8 (Android):** leaving a form while it saves no longer loses the entry. The save finishes in the background, and Back says so first.
- **P9:** booking a session warns when the instructor already has a session at that time, and the warning must be approved. This applies on the website, in the apps and by voice; voice refuses instead.
- **P10:** making a meeting link in another person's Zoom or Teams account needs the administrator permission.
- **P11:** merging clients moves the duplicate's audit entries to the kept record. Purging also clears the details of records merged into it before this release.
- **P12:** offered times from an instructor who was switched off or moved off the client are withdrawn, and the client's current instructor is told.
- **P13:** agency figures count only work the agency pays for. This covers:
  - the agency billing summary, the funder report and its email
  - RSA-911, the agency scorecard and analytics

  Private-pay and no-charge tracks store their payer as the funder. A renamed agency is listed once.
- **P14:** losing the key file can be recovered with "Forget stored connections and keys" on System health. Authenticator seeds are never stored unencrypted.
- **P15:** staging copies scrub unique columns per row. A failed scrub drops the copy, and the copy can never fall back to the production settings.
- **P16:** restore.php never switches the site to Central time; it requires the time zone.

**Low (selected)**
- **Notes:**
  - Note edits on the website and in the iPhone app are protected against stale forms.
  - Notes written offline keep the time they were written.
- **Scheduling and portal:**
  - Reopening a referral restores its contact task and waitlist place.
  - In-person signing requests expire after 2 days.
  - Unreadable numbers and dates are refused instead of erasing a stored value.
  - Text-message requests reach an active instructor and get a task.
- **QuickBooks:**
  - changed payment amounts are followed
  - payments are re-read only when something differs
  - a rate limit waits for the next run
  - the sync lock is per database
- **Backups and updates:**
  - backups no longer hold live link tokens
  - rolling back an update keeps the backup script runnable
  - secret-expiry warnings count calendar days
- **Voice:** dictated phone numbers and emails are kept.
- **Accessibility:**
  - Errors are tied to their fields on many more forms.
  - The staff menu is marked English on Spanish pages.
  - Hints are inside labels in the iPhone app.
  - Android lists say "latest N of M".

**Tests**
- New PHP cases: tests/v429_auth_cases.php, v429_api_cases.php, v429_cases_cases.php, v429_admin_cases.php, v429_public_cases.php and v429_jobs_cases.php.
- New checks in tests/staging_scrub.php.
- New iPhone regression checks and Android V429Test.
- Checked before commit:
  - full_schema (2555), audit_regression, security, staging_scrub and run_all pass, and the migration rehearsal matched
  - iPhone: builds with no warnings, ios_api_regression passes (69)
  - Android: build, unit tests and lint (0 errors) pass

**Review:** two independent reviews found these, all fixed before commit:
- **Key file:** "Forget stored connections and keys" would have treated a good key file with the wrong owner as damaged. It now refuses and says to fix the owner.
- **Hand-over:** the marker blocked Microsoft sign-in for 12 hours on sites without auth_time. It is now 2 hours, cleared by a fresh Microsoft sign-in, reported when the claim is missing, and paired with a Microsoft sign-out.
- **Booking warnings:** voice booking skipped the overlap check, and the API's warnings differed from the website's.
- **Notes:** a retried note edit showed a false conflict.
- **Private pay:** private-pay tracks from the apps kept "State VR".
- **QuickBooks:** reads were near the rate limit.
- **Text messages:** request details were in Spanish on staff pages.
- **Android:** Back was held silently while saving, and save results could reach another account after sign-out.
- **iPhone:** the track draft lost the amendment reason, the hours list was shown as complete, VoiceOver said "Error: Reloaded", and the segmented picker labels.

**After the review:** CI's browser accessibility check found two error summaries with the same id on Client requests, when two of its forms were refused at once. A later summary on a page now gets its own id (inc/bootstrap.php, 94a630a). It was checked locally on 329 rendered pages and in CI.

## Published (not deployed)

2026-10-19, after CI passed all three jobs on 94a630a. The GitHub release v4.29.0 (latest, not a pre-release) carries:
- the install bundle, built from a clean checkout of the tag
- the signed update package: 331 files, verified

**Rehearsed:** installed with the 4.28.0 updater onto a copy of a 4.28.0 site that had one Microsoft identity linked to two accounts.
- The signature verified, the plan showed no problems, and the backup was made first.
- The database changes ran (6 statements; database version 4.29.0):
  - the identity stayed on the active account and was removed from the switched-off one, with an audit entry
  - idx_entra_oid is now unique
- 92 files were replaced, including the scheduled jobs; cron/backup.sh stayed executable.
- The sign-in, signing and survey pages then served at 4.29.0.

The live site stays as it is until an administrator installs it with Software update. The 4.29.0 phone apps are built from this tag.
