# Release 4.28.0 — October 18, 2026

Web 4.28.0, iPhone app 4.28.0 (build 59), Android app 4.28.0 (version code 12). Apply dist/migrations/mig_4.28.0.sql, or use Database upgrade; Software update applies it on its own.

**What the migration does:**
- **QuickBooks payment cleanup:** a payment recorded here and imported back from QuickBooks before this release appears as two rows with the same QuickBooks id. The imported copy is removed, keeping the row recorded here, and that invoice's paid amount and status are recalculated.
- **New key:** invoice_payments gets a unique key on (invoice_id, qbo_id).
- **Version:** sets schema_version to 4.28.0.

**mig_3.0.8 change:** it no longer turns the document category back into a fixed list, so running it again keeps custom document categories.

**Not deployed with deploy.sh.** It is published as the GitHub release v4.28.0, and the owner installs it with Administration, Software update. The phone apps are built from this tag.

**Why.** On 2026-10-18 the owner asked for an ultra code audit and fix. Ten auditors covered the whole system, two of them only the 4.27.0 changes. Their report is AUDIT-2026-10-18b.md: no High, 16 Medium, about 70 Low. Every finding is fixed here.

**Before or after installing**
- **Software update now refuses** when the database is behind the site's files. Run Database upgrade first. This normally never happens with Software update.
- **Authenticator app seeds are now stored encrypted** with the private key file `meeting_tokens.key`, the one that already protects meeting and QuickBooks tokens.
  - If the database is ever restored onto a new server, copy that key file too.
  - Without it, people with an authenticator are refused with a clear message.
  - An administrator then resets their two-step verification. If the only administrator is locked out, they run `php reset_2fa.php their@email` in the private cron folder (new; Help explains it).
  - System health warns when the key file is missing or does not match.

**Medium**
- **N1:** Microsoft auto-provisioning no longer creates accounts for guests or personal Microsoft accounts. Only members of the organization's own tenant get one.
- **N2 (iPhone):** offline entries over 30 days old are expired only after the server was actually reached.
- **N3 (iPhone):** reminders, the widget and Siri use only the person's own sessions and follow-ups.
- **N4:** saving or sending again after a lost reply no longer makes a second record or email.
  - Both apps send a retry key on every create and send action.
  - The server answers a repeat with the first reply, and answers "still being processed" while the first is running.
- **N5:** Spanish in-person signing in both apps marks every instruction, field, button, dialog, error and announcement as Spanish for VoiceOver and TalkBack.
- **N6 (Android):** open forms and dialogs survive rotation, a text-size change and the app being stopped in the background. This covers completing a session with its hours, note and drawn signature. A signature is rescaled to the new pad size.
- **N7:** changing a client's counselor or details re-addresses draft invoices the same way invoices are made:
  - VR: the agency billing address, then the counselor, with the email only if confirmed
  - private pay: the client
- **N8:** every spreadsheet download guards every cell against formulas, including Staff compliance.
- **N9 to N10:** a track and a worksite assessment are now protected against two people saving at once. The second save is refused, names what the other person changed, and keeps the person's own typing. For a worksite this works on the website and in both apps.
- **N11:** the Referral deadlines report shows a caseload-limited user only their own clients' referrals.
- **N12:** when a client picks an offered new time:
  - the session moves to the offered instructor, place and remote setting
  - the right instructor's calendar is checked
  - the previous instructor gets a calendar cancellation
  - the session leaves its group
  - an instructor limited to their own caseload can't be offered a client who isn't theirs
- **N13:** QuickBooks can no longer count a payment twice. Payments recorded here are sent before linked ones are imported, a lost reply is recovered, and the unique key stops repeats.
- **N14:** Software update refuses when the database is behind its files.
- **N15:** converting a referral in the apps works when the person agreed to texts.
- **N16:** the apps' client save is protected against stale forms, as the website's has been since 4.27.0. The apps send the version they loaded and the values the form opened with. On a conflict they show what changed and offer Reload, which keeps the person's typing.

**Low (selected)**
- **Accessibility:**
  - Help text on about 150 form fields now sits inside the field's label, so screen readers read it with the field instead of as a separate line.
  - Errors are tied to their fields with a summary on many more forms, on the website and in Android.
  - The Spanish voice is used on more public pages, and a staff member's privacy notice stays in English.
- **Sign-in:**
  - "Add a passkey" shows the server's own message.
  - The two-step reset says that sessions end.
  - Outsiders can no longer use up an app sign-in code.
- **Scheduling:**
  - "Move anyway" approves only the warnings shown, re-checked under the instructor's lock.
  - A moved group member leaves the group.
  - A corrected client email gets the session reminder.
- **Jobs and backups:**
  - mail and texts whose outcome is unknown are no longer sent twice
  - backups leave out pending authenticator set-ups
  - a failed test restore cleans up its copy
  - no false "moved in Outlook" alerts
  - installer errors name their fields
- **Phone apps:**
  - Android no longer follows redirects to another server (the 4.27.0 notes said both apps refused them; only the iPhone did)
  - Android: kept documents expire after 14 days, and one damaged storage file no longer wipes the others
  - iPhone: nothing is announced or uploaded while the phone is handed to a signer
  - iPhone: sign-out names the documents prepared to sign that it removes
  - iPhone: the lock screen scrolls at the largest text sizes
- **Other:**
  - survey links expire after 120 days
  - the counselor portal shows VR tracks only
  - renamed agencies are kept together in reports
  - a Denied or Void invoice no longer says "TOTAL DUE"
  - signed documents have no Withdraw button

**Tests**
- New PHP cases: tests/v428_auth_cases.php, v428_api_cases.php, v428_cases_cases.php, v428_admin_cases.php, v428_public_cases.php and v428_jobs_cases.php.
- New iPhone regression checks: queue expiry and the :443 address.
- New Android unit tests in V428Test.
- Checked before commit:
  - full_schema (2353), audit_regression, security, staging_scrub and run_all pass, and the migration rehearsal matched
  - iPhone: builds with no warnings, ios_api_regression passes (65)
  - Android: build, unit tests and lint (0 errors) pass

**Review:** two independent reviews (server and apps) found these, all fixed before commit:
- **Sign-in:** an unreadable authenticator seed let the app sign-in fall back to emailed codes. It now refuses everywhere, and the key file is never silently replaced.
- **Retries:** a slow first attempt could still be repeated.
- **Moving sessions:** the API's "Move anyway" was not re-checked under the lock.
- **Reschedules:** the group, the old instructor's cancellation, and caseload limits.
- **Draft invoices:** the agency was missing on older drafts.
- **Android:**
  - the worksite stale check was missing
  - Reload re-recorded the texting agreement (iPhone too)
  - a kept signature was clipped after rotation
  - Reload got stuck after a failure
  - a few retry keys were missing (iPhone too)
- **iPhone:** the hand-over flag was cleared by an unlock while signing was still open, and Spanish was lost in dialog titles.

## Published (not deployed)

2026-10-18, after CI passed all three jobs on 8b3d7a1. The GitHub release v4.28.0 (latest, not a pre-release) carries:
- the install bundle, built from a clean checkout of the tag; it includes cron/reset_2fa.php
- the signed update package: 330 files, verified

**Rehearsed:** installed with the 4.27.0 updater onto a copy of a 4.27.0 site.
- The signature verified, the plan showed no problems, and the backup was made first.
- The database changes ran (5 statements; database version 4.28.0, the invoice_qbo_payment key present).
- 102 files were replaced, including the scheduled jobs and the new recovery script in the private cron folder.
- The sign-in, signing and survey pages then served at 4.28.0.

The live site stays as it is until the owner installs it with Software update. The 4.28.0 phone apps are built from this tag.
