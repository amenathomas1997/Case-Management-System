# Release 4.27.0 — October 18, 2026

Web 4.27.0, iPhone app 4.27.0 (build 58), Android app 4.27.0 (version code 11). Apply dist/migrations/mig_4.27.0.sql, or use Database upgrade; Software update applies it on its own. It adds:
- users.session_epoch
- incoming_authorizations.sender_email
- scheduled_sessions.client_reminded_at and staff_reminded_at
- schema_version 4.27.0

mig_3.0.36 was also made safe to run again: it no longer turns the note type back into a fixed list, which would have broken custom note types.

**Not deployed with deploy.sh.** It is published as the GitHub release v4.27.0, and the owner installs it with Administration, Software update. The phone apps are built from this tag.

**Why.** On 2026-10-18 the owner asked for a top-to-bottom code audit (AUDIT-2026-10-18.md: 6 High, 22 Medium, about 45 Low), then "Fix all findings". Every finding is fixed here.

**Before installing: two things change for sign-in (owner decisions)**
- **Authenticator app (M1).** A staff member who has turned on an authenticator app must use it (or a passkey) to sign in, on the website and in the apps. The emailed code no longer works for them, and a password reset alone never gets anyone in. If someone has lost their authenticator, an administrator resets their two-step verification.
- **Microsoft sign-in (M2).** A first Microsoft sign-in is matched to a staff account by email only for a member of the organization's own Microsoft tenant. **In Microsoft Entra, the app registration must emit the optional ID-token claim `acct`** (App registrations, the CMS app, Token configuration, Add optional claim, ID, acct). Without it, first-time Microsoft sign-ins are refused with "Sign in with your password once, then connect Microsoft from My Account." Accounts already connected are not affected. The Microsoft sign-in page lists this as a setup step.

**High**
- **H1, signing in person on the website:** while a device is handed over, only the signing page, its thank-you page and the sign or hand-back form work. Back, other pages, Help, the keep-alive and passkeys sign the staff member out. Public pages show no staff menu on a handed-over device.
- **H2, QuickBooks:**
  - A payment recorded here and sent to QuickBooks is no longer imported back a second time.
  - While an invoice is being synced, its QuickBooks payments are voided in QuickBooks; they are then removed here at the next sync.
  - A payment counts as deleted in QuickBooks only when QuickBooks says it is not found.
  - Disconnecting or changing company keeps every payment.
  - A zero balance in QuickBooks that is not covered here asks staff to record it.
- **H3, a phone reported lost:**
  - It is told to wipe itself on every connection until the app confirms (new action wipe_done), even after a password reset, sign out everywhere or deactivation.
  - A phone whose session has already ended can still be reported lost.
  - Both apps check for the wipe on every request, including offline uploads and downloads.
  - The wipe removes every account's data on the phone.
- **H4:** merging clients keeps a legal hold.
- **H5:** cancelling a whole group session is refused for a staff member limited to their caseload when the group includes another person's client (website and apps).
- **H6:** on Android, changing the server address no longer deletes unsent work before signing in. Both apps now warn with a count, and clear only after signing in to a genuinely different server.

**Medium**
- **M3:** staging copies lose every stored secret, and with mail off they send nothing, attachments included.
- **M4:** backups leave out every secret, including the Twilio token; the restore steps list each one to enter again.
- **M5:** the client export follows what the person exporting can see (incidents, tasks, texting log).
- **M6:** the portal's "Email me a new link" re-sends the working link instead of replacing it, with per-client and per-address limits.
- **M7:** a phone number changed on the portal turns texts off.
- **M8:** a guardian's consent signed while the client was a minor stops covering at 18. Consent from a guardian of an adult still counts.
- **M9:** purging a client blanks their incoming authorization emails.
- **M10:** automatic monthly reports skip a client with no release covering the counselor, and tell administrators.
- **M11:** changing a custom field's type is refused when stored answers would not fit.
- **M12:** two people saving the same client: the second save is refused. The message names the fields the other person changed, the form keeps only this person's own edits and shows the saved values for the rest, and closing is decided from the locked record.
- **M13:** merging fills the VR case number, plan, vision, time zone, language and privacy notice.
- **M14:** a closed referral cannot be converted (website and apps).
- **M15:** booking from the apps approves only the authorization warnings that were shown.
- **M16:** session reminders are recorded per recipient: a failure for one never re-sends to the other, and moving or reassigning a session reminds both again.
- **M17 to M19 (iPhone):**
  - the session signature sheet always locks the app
  - email links are encoded
  - the lost-phone wipe clears every account
- **M20 and M21 (Android):**
  - signing in person survives rotation, and a signed document is never withdrawn
  - client records kept on the phone are limited (30, up to 14 days old), in separate encrypted files written off the main thread
- **M22:** importing authorizations ignores case and spaces in the authorization number.

**Low (selected)**
- **Sign-in:**
  - lockouts per account and address, so outsiders cannot lock out an office
  - resent codes are capped
  - "forgot password" answers the same for every address
  - removing a passkey, an administrator's two-step reset or email change signs out other sessions
  - health check limits are per address
  - addresses behind a trusted proxy
- **The apps:**
  - retries never start, send or request the same thing twice (idem_key)
  - offline check-ins carry the time they were made
  - an email whose outcome is unknown says so
  - offline entries expire after 30 days, after one last try
  - redirects to another server are refused
  - reminders are cleared when the account changes
  - Siri's "Today's sessions" needs the phone unlocked
  - widget contrast
- **Other fixes:**
  - a signed document cannot be withdrawn
  - trash and purge respect legal holds
  - a counselor address read from an email is confirmed only when it is the sender
  - the watchdog alerts the right address while the database is down
  - the agency scorecard matches other spellings of an agency's name
  - the counselor portal shows times with the time zone
  - the fake "referral sent" page is closed
  - search treats % and _ literally
  - the clients filter is remembered only when chosen
  - accessibility: errors tied to their fields on Software update, tasks, offices, reservations, custom fields, the import, follow-ups, the portal check-in and moving a session

**Tests**
- tests/v427_auth_cases.php, v427_api_cases.php, v427_billing_cases.php, v427_records_cases.php, v427_staff_cases.php and v427_portal_cases.php.
- New iPhone regression checks: wipe, queue expiry, server change and redirects.
- New Android unit tests: address comparison, cache limits, retry keys and sign-in wording.
- Checked before commit:
  - full_schema (2195), audit_regression, security, staging_scrub and run_all pass, and the migration rehearsal matched
  - iPhone: builds with no warnings, ios_api_regression passes (60)
  - Android: build, unit tests and lint (0 errors) pass

**Review:** two independent reviews (server, apps) found these, all fixed before commit:
- QuickBooks payments that could never be removed
- a moved session missing one side's reminder
- guardian consent wrongly refused for adults
- referral conversion still open in the API
- a conflicting edit that could undo a colleague's change on the second save
- Android sending the phone's clock time on online check-ins
- Android unable to receive a wipe after its session expired
- Android's storage move could hide unsent work if a write failed
- iPhone retries without idem_key
- iPhone dropping 30-day-old entries just before they could be sent

## Published (not deployed)

2026-10-18, after CI passed all three jobs on ed14c1e. The GitHub release v4.27.0 (latest, not a pre-release) carries:
- the install bundle, built from a clean checkout of the tag
- the signed update package: 328 files, verified

**Rehearsed:** installed with the 4.26.0 updater onto a copy of a 4.26.0 site.
- The signature verified, the plan showed no problems, and the backup was made first.
- The database changes ran (5 statements; database version 4.27.0, users.session_epoch and the reminder columns present).
- 79 files were replaced, including the scheduled jobs.
- The sign-in and signing pages then served at 4.27.0.

The live site stays as it is until the owner installs it with Software update. Before or right after installing, add the optional `acct` claim in Microsoft Entra (see above). The 4.27.0 phone apps are built from this tag.
