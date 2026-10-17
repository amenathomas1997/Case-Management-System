# Release 4.10.0 — October 16, 2026

Web 4.10.0 (no iPhone app change). Apply dist/migrations/mig_4.10.0.sql (meeting_accounts; schema_version 4.10.0), or use Database upgrade.

**Zoom and Microsoft Teams meeting links** (owner, 2026-10-16: "each person signs in"):
- An administrator registers the system once with Zoom (a user-managed General App) and Microsoft (an Entra app registration with delegated OnlineMeetings.ReadWrite, User.Read and offline_access). The IDs and secrets go in Settings, Online meetings. Help, Administrator guide, "Setting up Zoom and Microsoft Teams meeting links" has the steps and this site's return addresses. Until then nothing new appears.
- Each staff member connects their own account on My Account, Online meetings (Connect or Disconnect for each service; it shows the account it signed in as). Connecting and disconnecting are audited. Disconnecting Zoom also withdraws it at Zoom.
- On Schedule a session, "Make the meeting link" (No, In Zoom, In Microsoft Teams) makes the meeting in the instructor's account once the sessions are saved. A series shares one link (Zoom: a recurring meeting with no fixed time; Teams: one meeting covering the series). The link goes into every session, the instructor's invite and the client's invite. If Zoom or Microsoft refuses, the sessions are still booked, without a link, and the message says so.
- A meeting can be made only in your own account, or by an administrator in anyone's.
- Tokens are encrypted (AES-256-GCM) with a key file, meeting_tokens.key, in the private directory beside config.php (made on first use, readable only by its owner). The database and its backups never hold them in a form that can be used. After a restore to another server, copy that file too, or everyone connects again.
- Group sessions keep the typed link for now.

**Tests:** tests/v410_cases.php is new. Zoom and Microsoft are replaced by synthetic replies (CMS_TEST_MEETINGS). It checks:
- the state check on the return
- encryption, including a tampered token
- the key file's permissions
- renewal of an expired connection
- making Zoom and Teams meetings
- My Account
- booking a series with one link
- refusals: not remote, not connected
- a Zoom refusal still books
- disconnect

The test harness now gives pages a private directory, and session additions apply to pages opened without a form.

Review: no high-severity findings. Every finding was fixed:
- **Token requests** prove the app one way only: Basic auth for Zoom, the body for Microsoft.
- **Key file:** created by linking a finished temporary file, so two first uses at once cannot make two keys. A key readable by others is refused.
- **Renewal** happens under a row lock, so two bookings at once cannot both spend Zoom's single-use refresh token.
- **Saving the link** cannot turn a completed booking into an error page. Links over 500 characters are refused.
- **Whose account:** only your own, or an administrator's choice. The message names the account.
- **Disconnect** says so when the database upgrade has not run. It ignores malformed input and withdraws the token at Zoom.
- **Online meetings section** shows for every staff member, not only those who schedule.
- **The test** of "a plain link starts nothing" now reads the page's own session.

Verified before commit:
- full_schema 1634, audit_regression, security, staging_scrub, and run_all.
- The migration rehearsal matched.
- Not tried against real Zoom or Microsoft accounts (no app is registered yet).
- Not checked in a browser or with a screen reader.

## Deployed 2026-10-16 22:07 CT
From 4.9.0 with .audit_work/deploy-4.10.0 (12 files), at 20921cb. mig_4.10.0.sql ran (2 statements); meeting_accounts present. Production files matched 4.9.0 before install and match 4.10.0 after; .htaccess unchanged. Smoke checks passed; health reports 4.10.0; meeting_connect.php sends a signed-out visitor to sign in. Nothing new shows until Zoom or Microsoft is set up on Settings, Online meetings. Backups: /home/cmsuser/casemanagement_private/deploy-4.10.0-20261016-220739.
