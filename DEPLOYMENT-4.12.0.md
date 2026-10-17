# Release 4.12.0 — October 16, 2026

Web 4.12.0, and the first Android app (versionName 4.12.0, versionCode 1). No database change.

The owner asked for "an Android app that is in sync with the website and the iPhone app". It is in `android/` (Kotlin, Jetpack Compose). It uses the same API, sign-in and records as the iPhone app, so everything is in sync by construction: nothing is kept on the phone except entries written without a signal, until they are sent. `android/README.md` covers what it does, how to build it, another organisation's build settings, and publishing to Google Play.

**What the Android app does (first release):** the daily work of the iPhone app's main tabs.
- Sign in with email, password and code. A build without a server asks for the address.
- Home: the dashboard.
- Clients:
  - search, and open a client's details, tracks with hours used, and case notes
  - add a case note
  - log hours and mileage
- Schedule:
  - check in
  - complete a session, choosing which hours already logged are the session's
  - record a no-show, or cancel
  - join the online meeting
- Tasks: complete, reopen, add.
- Alerts, referrals (read, Mark as reviewed), messages with clients and counselors, offline entries, and My account with Sign out.
- Equipment, invoices, reports, AI tools and the iPhone app's other screens are still on the website, for later releases.

**Safety, as in the iPhone app:**
- The session and offline entries are encrypted with a hardware keystore key, and are never backed up or transferred.
- Screens are hidden from recent-apps previews and screenshots.
- https only.
- Offline entries are kept only on a phone with a screen lock. They are sent once each (idem_key, and a check-in repeat counts as done), and a refused one is kept with its reason.
- A phone reported lost is wiped at its next request.
- When a session expires, sign-in appears above the open screens, so a half-written note is kept.

**TalkBack:**
- headings, one stop per list row
- the action named once by TalkBack's own "double tap to …"
- hints inside labels
- choices as plain lists
- errors and confirmations read out once
- 48-point targets

**Web change:** client views from the Android app are logged as "Android app" in the audit log (it says so in X-App-Version), not "iPhone app". The staff guide has a section for the Android app.

**CI:** a new job builds the Android app, runs its unit tests and Android lint on every push.

**Tests:**
- tests/v412_cases.php: the audit label from each app.
- android/app/src/test: reading the organisation's address.
- full_schema 1659, audit_regression, security, staging_scrub and run_all pass.
- The Android app builds, and lint reports no errors.

**Review:** the Android review found no wrong API use. Every finding was fixed:
- **Changing server** drops the last server's offline entries and account.
- **Calls, email and meeting links** with no app to open them say so instead of crashing; meeting links must be https.
- **Expired session:** sign-in appears above the screens, and the form is kept.
- **Offline entries** are owned by the account that started the save, and a lost connection after an account change is reported as such.
- **"NaN"** and infinity are refused as numbers.
- **Dialogs scroll.**
- **An unreadable keystore** starts afresh instead of failing every launch.
- **Saving offline** no longer waits for an upload in progress.
- **"Today"** is the server's today.
- **"Send a new code"** asks for the password again rather than sending a blank one.
- **Signing out** clears the last account's notices and runs beyond the screen.
- **TalkBack:**
  - no repeated "double tap" hints and no separate hint lines
  - a note's expanded state is read as a state
  - no duplicate headings
- **A repeat check-in** counts as done.
- **Tasks** show "Reopened, waiting to send" correctly, worked out from the stored queue.
- **A failed refresh** says the list may be out of date.

**Not verified:**
- The Android app has not been run on a phone or an emulator here: there is no Android system image on this Mac.
- It has not been tried against the live server, and has not been checked with TalkBack.
- Its first run should be an internal test (Google Play Internal testing, or the debug APK on one phone).

## Deployed 2026-10-16 23:17 CT
The web part went from 4.11.0 with .audit_work/deploy-4.12.0: 5 files at e035572, no migration. Production files matched 4.11.0 before install and match 4.12.0 after. Smoke checks passed, and health reports 4.12.0. Backups: /home/cmsuser/casemanagement_private/deploy-4.12.0-20261016-231702.

CI's first run failed in the third-party Android setup step (its licence prompt), not in the app. 4003cc0 uses the runner's own Android SDK, and all three jobs then passed, including the Android build, unit tests and lint.

The Android app is not published anywhere yet. The debug APK can be built with android/README.md. Publishing needs the owner's Google Play developer account and upload key.
