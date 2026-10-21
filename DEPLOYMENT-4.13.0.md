# Release 4.13.0 — October 17, 2026

Web 4.13.0 (release notes, the staff guide's Android section) and Android app 4.13.0 (versionCode 2). No database change.

The maintainer asked to test the Android app and to build the rest of its screens.

**Tested on an Android emulator for the first time:**
- **Setup:** the emulator was Android 15, Pixel 7. It ran against a local copy of the website (`php -S`, database `cms_android_test`) with made-up records only, and a local test account whose details are in the git-ignored `private/` folder. The live site was not used.
- **How it was checked:** the screens were driven by their accessibility tree, the labels and stops TalkBack uses.
- **First release, all working:**
  - sign-in with an authenticator code, and the dashboard
  - clients, a client, adding a case note, logging hours
  - checking in, and completing a session (including choosing hours already logged, with no duplicate)
  - completing a task, alerts, marking a referral reviewed, sending a message
  - offline: a phone with no screen lock refuses to keep the note and keeps the form; with a PIN it keeps it, and it was sent once after reconnecting
  - an expired session: sign-in appears above a half-typed form, and the form is kept after signing back in
- **Found and fixed:**
  - nothing could be read without a signal (the iPhone app keeps copies)
  - the code instruction was read twice
  - signing back in did not offer the email
  - message senders showed as "client"

**The new Android screens:**
- add and edit a client, including closing a case with its reason
- a training track: authorization use and run-out, goals with progress, expenses, Log hours, edit the track, add a track
- documents (downloaded and opened in another app) and homework (complete, assign)
- schedule a session, with authorization warnings to approve; move a session
- referrals: assign, close, reopen, make a client, add to an existing client
- equipment (lend, return) and device follow-ups (record, skip, ask again in 14 days)
- search, pipeline, waitlist, compliance, invoices (mark a draft submitted), incidents (report, change status)
- My account: change password, authenticator app on or off, calendar address, sign out everywhere

**Offline additions:**
- The last copy of the clients, each client opened, the schedule and follow-ups is kept and shown with when it was saved.
- Goal progress (with what it said when written, so it never undoes newer progress), expenses, device follow-ups and completed homework are kept to send.
- All of this only on a phone with a screen lock, and removed at sign-out.

**Every new screen was walked through on the emulator, and each save was checked in the database:**
- client edit and add
- goal progress and an expense
- completing homework, and opening a document
- scheduling a session (at the organisation's time) and moving it
- lending and returning equipment
- follow-ups, pipeline, compliance, invoices (submitting one), incidents, waitlist, search, and the account screen

**Review:** every finding was fixed:
- **A crash** when a list failed to load inside a scrolling screen (search, invoices, incidents, account, clients offline). This was checked offline on the emulator: no crash.
- **The Account screen** reported a load failure twice.
- **A mistyped authenticator code** lost the setup. The key is now kept, spelled out character by character for TalkBack, and can be copied.
- **Goal progress:** the "written at" details are now only on entries kept offline, as on the iPhone. Reopening an achieved goal at 100% is confirmed only when typed.
- **New tracks** take the agency's mileage rate. The RSA category is dropped when the service type changes.
- **Documents** are only kept on a locked phone, are deleted if the account changed during the download, and are named by their number so two with one name don't overwrite each other.
- **A changed email** offers the confirmation again.
- **Saved copies** are only removed when access is refused (403 or 404).
- **Smaller fixes:** invoices say when only the newest page is shown; Report an incident works even if the list failed; Log hours shows only on active tracks.

**Tests:**
- Web: full_schema, audit_regression, security, staging_scrub and run_all pass.
- Android: unit tests, including the debug-only local address, pass, and lint reports no errors.
- A debug build may use plain http to the computer it runs on (10.0.2.2 or localhost) for testing. Release builds stay https-only.

**Not verified:**
- Not run with TalkBack itself: the emulator image has no TalkBack.
- Not on a real phone, and not against the live site.
- Still only on the website and the iPhone app: the AI tools and voice assistant, scored assessments, AT services, programs, contracts, bookings, procurement, accommodations, competencies, the knowledge base, signatures and uploading documents.

## Deployed 2026-10-16 23:54 CT
The web part went from 4.12.0 with .audit_work/deploy-4.13.0: 4 files at a8a16e4, no migration. Production files matched 4.12.0 before install and match 4.13.0 after, and smoke checks passed. CI passed all three jobs: PHP, Android and iOS. The Android app is not on Google Play yet. Backups: <private dir>/deploy-4.13.0-20261016-235434.
