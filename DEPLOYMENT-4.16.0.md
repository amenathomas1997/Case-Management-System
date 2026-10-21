# Release 4.16.0 — October 17, 2026

Web 4.16.0, Android app 4.16.0 (versionCode 5), iPhone app 4.16.0 (build 52). Apply dist/migrations/mig_4.16.0.sql, or use Database upgrade. It adds:
- the tables training_modules, training_completions and staff_credentials
- the staff_agreements columns kind, activated_at and version_at, and users.exit_requested_at
- a starting privacy training and a starting exit acknowledgement, both off until edited
- the setting access_hold = new
- schema_version 4.16.0

The maintainer asked for all five follow-ups to staff agreements (2026-10-17): privacy training, signing before access, credentials, an exit checklist and an agreements report.

**1. Staff training** (Administration, Staff training; staff use My training)
- An administrator writes the reading and a quiz: ? starts a question, * marks the right answer and - a wrong one. They also set the pass mark and whether it is required.
- The starting text can't be turned on unedited.
- Staff read it and answer on the website or in either app. The right answers never leave the server.
- Every attempt is kept. A failed attempt says which questions were wrong, and the training can be taken again.
- Passing the privacy training records its date.
- A changed training is a new version, which everyone takes again. A small correction to the reading only (same quiz and pass mark, at most 40 characters) keeps the version. A training that is off can be edited without touching anyone's recorded date.
- Taken again yearly.

**2. Before client records open** (Staff training, the same page)
- Off; new staff only (the built-in choice, written by the migration); or everyone after 1–90 days to catch up.
- While someone has a required agreement or training outstanding, the client areas are taken from them: clients, referrals, schedule, programs, equipment, procurement, billing, reports and analytics. Administration and templates stay.
- This applies to the website, the API and the calendar feed.
- Home and the apps say what to finish; refused pages and app calls say why.
- Saving "everyone" lists who would be held right now and needs a tick to confirm.
- A new version holds people from when it changed; otherwise from when their year ran out.
- The exit acknowledgement never holds anyone.

**3. Credentials** (My credentials; Administration, Staff credentials)
- Each person keeps their certifications and checks, with optional number, issuer, issue date, expiry and notes. Types come from an editable list (Lists and time limits).
- From 60 days before expiry, it shows on the person's Home page and in Compliance, and the daily job opens one task for an administrator. A task closes when the credential is renewed, corrected or removed.
- Staff can change only their own; administrators can change anyone's.
- The apps keep fields they don't show (notes).

**4. Exit acknowledgement** (Staff agreements; the user page's "If they are leaving")
- "Ask them to sign the exit acknowledgement" puts it on their Home page and sends an alert. Its signed copy appears on their user page, and "Stop asking" withdraws the request.
- Only someone asked can sign it.
- Switching a rehired person back on clears an old request.

**5. Staff compliance report** (Administration)
- Every agreement, training, credential and exit acknowledgement for each person, with dates and versions.
- Optionally includes switched-off accounts.
- The download is a spreadsheet with a BOM and formula-safe cells, and it is audited.

**Tests:** tests/v416_cases.php, 31 checks, covering:
- quiz parsing and its refusals
- the starting text
- editing a training that is off keeps everyone's dates
- the questions never show the answers (web and app)
- a failed attempt is kept; passing records the date
- a training changed while open is refused
- the hold:
  - a new staff member is held and an existing one is not
  - client permissions are removed
  - held app users are told why
  - "everyone" needs a confirmation and at least a day
- credentials:
  - expiring soon is flagged in Compliance
  - the apps show your own, and keep notes they don't show
  - nobody can remove someone else's
- the exit acknowledgement: not asked of others, and refused for someone not leaving
- the report on screen and as a download

**Checks before commit:**
- Web: full_schema 1714, audit_regression, security, staging_scrub and run_all pass, and the migration rehearsal matched.
- Android on the emulator: the privacy training was passed 3 of 3 and recorded as the Android app; a credential was added and flagged as expiring soon.
- The iPhone app builds.

**Review:** every finding was fixed, except one accepted:
- **Recorded dates:** editing the starting privacy training would have erased everyone's recorded training dates.
- **The hold's timing:** a changed item didn't hold anyone until their old renewal date.
- **The calendar feed** ignored the hold.
- **One save could hold every non-admin**, without warning.
- **Credentials:**
  - app saves erased notes
  - reminder tasks piled up
  - the reminder wording was wrong
  - a type removed from the list made a credential impossible to edit
  - a delete without an id read an undefined value
- **The report's CSV:** cell protection, accented names, and people with the same name.
- **Exit acknowledgement:**
  - an old signature hid "Stop asking"
  - a rehire was still asked
  - anyone could sign it through the API
- **Messages:** refusals didn't say a hold was the reason.
- **iPhone quiz:** questions were read twice, and there was a "Choose an answer" stop.

**Accepted:** the quiz says which questions were wrong and allows another try at once, so it can be passed by retrying. It is a training check, not an exam, and every attempt is kept for the administrator to see.

**Not covered:** while held, a person still sees their own tasks and alerts, which can name a client, and authorization alert emails still reach them. Both are rare for someone new.

## Deployed 2026-10-17 12:19 CT
From 4.15.0 with .audit_work/deploy-4.16.0: 26 files, including cron/daily_alerts.php, at 1536601.
- mig_4.16.0.sql ran (12 statements), and the 5 new tables and columns are present.
- Production files matched 4.15.0 before install and match 4.16.0 after; .htaccess unchanged.
- Smoke checks passed, and health reports 4.16.0. CI passed all three jobs: PHP, Android and iOS.

On the live site the starting privacy training and exit acknowledgement are off until edited, and the hold is set to "new staff". Nothing is turned on yet, so nobody is held.

The apps need their 4.16.0 builds on TestFlight and Google Play; the website works now.

Backups: <private dir>/deploy-4.16.0-20261017-121928.
