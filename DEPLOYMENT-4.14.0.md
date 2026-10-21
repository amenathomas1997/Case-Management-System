# Release 4.14.0 — October 17, 2026

Web 4.14.0, and Android app 4.14.0 (versionCode 3). No database change.

The maintainer asked to "continue building": the Android app now has every screen the iPhone app has.

**New in the Android app:**
- **AI** (shown only when the organisation has each feature on):
  - the AI tools: plain language, translation, curriculum draft, ask about the caseload, ask the knowledge base, review of clients at risk
  - for a client: device recommendations, progress summary, intake assessment draft (staff review each field, then save), case summary (edited, then saved as a note)
  - in case notes: BIRP, PAIP and SOAP formats, cleaning up a written note (with Restore my original text), and drafting sections from rough notes (with Restore my sections), including homework the notes mention
  - nothing the AI writes is saved without staff choosing to save it
- **The voice assistant**, using the iPhone app's rules:
  - it saves only after a yes; no is checked first, and a number with the yes is unclear
  - logging hours and completing a session carry a lowercase key; a lost reply offers a retry with the same key, and the same request asked again reuses it
  - any other lost reply says to check first
  - opening a screen offers a button
  - speech uses the phone's recognizer; replies are read by TalkBack, or spoken aloud when TalkBack is off
- **Assessments:** scored assessments (every item rated, confirmed before saving) and curriculum competencies (only changed levels saved).
- **AT services:** every kind, with forms built from the fields the server describes; lending and returning for repairs and reuse; sharing the trial report; drafting a field with AI.
- **Other records:**
  - Pre-ETS programs: students, and logging a group session with attendance
  - grants and contracts: deliverables and spending
  - interpreter and transport bookings
  - device procurement: quotes, authorization, order, delivery, training, deny, cancel, reopen
  - worksite accommodations
  - the knowledge base: read, write, delete
  - the counselor portal: send or turn off links
  - client portal requests: status, with the outcome the client sees
  - staff utilization and the documentation review
- **Signatures:** the client signs on the phone when a session is completed or hours are logged, as a PNG within the server's limits. A typed name also counts.
- **Uploading documents** from the file picker, up to 25 MB, streamed from disk so a large file never fills the phone's memory.
- **The More menu** is grouped under headings: Everyday, Services, Reports and records, Account and administration.

**Web change:** interpreter and transport bookings from the apps now accept the organisation's own booking types (Lists and time limits). The server used to check a fixed list. tests/v414_cases.php covers it.

**Tested on the Android emulator** against the local copy of the website, with made-up records, and each save was checked in the database:
- every new screen opens without a crash
- a cohort student added and a group session logged
- contract spending
- a booking
- a device quote added and chosen (status moved to Awaiting Authorization)
- a worksite assessment saved
- a knowledge-base article read, with headings and list items
- a portal request moved to In Progress
- an AT trial record saved through the described form
- a scored assessment (10 items, 30 of 40)
- a competency level
- hours logged with a drawn signature (the PNG was stored)
- a document uploaded, with the stored file identical byte for byte

**Not tested:** the AI tools and voice assistant, because the local copy has no AI service. They follow the iPhone app's contracts, and the review checked them against the server.

**Review:** every finding was fixed:
- **Memory:** a large upload could run the phone out of memory. Uploads are now checked for size first, copied to disk at no more than 25 MB, and streamed.
- **Voice assistant:**
  - a repeated request after a lost reply could log hours twice; its key is now reused
  - "yes, 3" counted as yes
  - there were no buttons during a retry offer
  - several screens could not be opened, and searches were refused
- **Case notes:** ticked AI homework with no track was dropped silently; drafting replaced typed sections.
- **TalkBack and the signature pad:** the pad's label now says to pause TalkBack while the client signs, or use the typed name.
- **Complete dialog:** a tap outside it closed it and lost the signature.
- **AI results** were lost when the phone turned.
- **Smaller fixes:** a possible crash adding a cohort student, an empty client name breaking the voice link, and extra TalkBack stops on AI results.

**Tests:**
- Web: full_schema, audit_regression, security, staging_scrub and run_all pass.
- Android: unit tests pass, including the voice yes/no rules and reading times, and lint reports no errors.

## Deployed 2026-10-17 10:36 CT
The web part went from 4.13.0 with .audit_work/deploy-4.14.0: 5 files at 2271614, no migration. Production files matched 4.13.0 before install and match 4.14.0 after, and smoke checks passed. CI passed all three jobs: PHP, Android and iOS. The Android app is not on Google Play yet. Backups: <private dir>/deploy-4.14.0-20261017-103611.
