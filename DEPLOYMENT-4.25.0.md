# Release 4.25.0 — October 18, 2026

Web 4.25.0, iPhone app 4.25.0 (build 56), Android app 4.25.0 (version code 9). Apply dist/migrations/mig_4.25.0.sql, or use Database upgrade; Software update applies it on its own. It adds:
- client_signatures.method ("Email" or "In person")
- client_signatures.signature_path (a drawn signature from the apps)
- schema_version 4.25.0

**Not deployed with deploy.sh.** It is published as the GitHub release v4.25.0, and an administrator installs it with Administration, Software update. The phone apps are built from this tag and handed out through TestFlight and Google Play by the maintainer.

**Why.** On 2026-10-18 the maintainer asked to build all the suggestions, "paying special attention to the phone apps".

**1. The phone apps catch up** (the API in public/inc/api_425.php and public/api.php)
- **Both apps:**
  - offices
  - custom fields, on new and existing clients
  - the full closure details with the technology outcomes
  - files with messages: one of the client's documents, or a new file, photo or camera picture
  - sharing documents with the client or counselor
  - documents to sign by email, and **signing in person**
  - release of information requests
  - loan purpose and borrower
  - a referral deadlines figure on the dashboard
- **Android only**, catching up with the iPhone app:
  - the client's state, time zone, guardian, school, VR case number, IPE goal and releases
  - "Email a new portal link"
  - guardian consent requests
  - status history
  - the guardian, school, plan, screen reader and notes fields on the edit form
  - the closure employer and job details
  - starting a new message conversation
- **In-person signing:** the staff member hands over the phone. The client reads the document, with the "we/you" introduction and its own words in their language, and types their name. Drawing a signature is optional, so a blind signer can sign by typed name alone. A wrong name is refused with a spoken message and the typed name is kept.
- **The signer can never reach other records.**
  - **iPhone:** however the signing view closes (signed, cancelled or stopped), the app locks.
  - **Android:** leaving the signing view (Back, hand back, done, even a forced restart) needs the phone's own lock (fingerprint, face, PIN or pattern). A phone with no lock offers only "Sign out of the app".
  - **Both:** a document not signed is withdrawn.
- **Older servers:** these features appear only when the server is 4.25.0 or later, so a 4.24 server never silently drops a file.

**2. Signing in person on the website** (signatures.php)
- Sign here now opens a page to hand over: the document and a typed-name signature, with no staff menu, links or shortcuts. No email is needed.
- While the device is handed over, any other page signs the staff member out, so they sign in again to carry on.
- "Hand back without signing" withdraws the request.
- Only a document started for signing in person, that day, can be signed this way: an emailed one is signed from its own link.
- The case note and the audit log name the staff member whose device was used.
- Starting the same document again reuses it.

**3. Two reports** (Reports)
- **Referral deadlines** (inc/referral_deadlines.php):
  - how the referrals received in a period met their agencies' deadlines, measured by when the "Contact new referral" and "Schedule initial assessment" tasks were done against their due dates
  - shown by agency, with each late or overdue referral listed
  - a spreadsheet download
  - the apps get the totals only, without names
- **Agency scorecard:** one agency for a period:
  - referrals and deadlines met
  - clients served and hours
  - authorizations in use, ending, and ended with hours unused
  - invoiced, paid and average days to pay
  - what is open and past terms, and what waits in or was rejected by its portal
  - outcomes at closure
  
  It is limited to the user's caseload when they have one, and has a spreadsheet download.

**4. Virus scanning** (inc/virus_scan.php; Settings, Security)
- **Setup:** ClamAV through clamd (found at its usual sockets, or a socket path or host:port) or the clamscan or clamdscan program.
- **Off by default.** Once on, every upload is scanned before it is kept: staff, assessment attachments, the client portal, the counselor portal and the apps.
- **What is refused:** a flagged file, and also a file that cannot be checked, so nothing unscanned gets in.
- **Health:** System health shows whether the scanner answers.

**5. Accessibility checked in a real browser on every change** (CI). tests/accessibility_browser.js now runs on 277 rendered pages in Chrome.
- **What it found and was fixed:**
  - the "Who pays" error on a track was never shown at its field
  - the texts list and the staff compliance tables overflowed narrow screens at 200% text
- **The conformance report** now says the browser checks run on every change.

**Tests**
- tests/v425_cases.php:
  - the apps' new actions
  - in-person signing on the website and in the apps
  - both reports
  - virus scanning, answered by CMS_TEST_VIRUS
  - the iPhone app's models decoding the real replies, checked value by value
- Android unit tests (ClientExtrasTest).
- Checked before commit:
  - full_schema (2077), audit_regression, security, staging_scrub and run_all pass, and the migration rehearsal matched
  - the Android build, unit tests and lint pass (0 errors)
  - the iPhone build succeeded with no warnings, and its regression test passes
  - the browser accessibility checks pass

**Tried on the Android emulator, against the local test server** (made-up records only):
- The dashboard deadline figure showed.
- A custom field was set and saved.
- A consent to services was signed in person: a wrong name was refused with a spoken error, and the right name signed it.
- A file-only message was sent and shared on the client's portal.
- With a screen lock on the emulator, leaving the signing view led to "Staff: unlock to continue", and a restart reopened the signing view, not the app.
- Two fixes came from this:
  - state and time zone are now separate rows
  - the screen reader no longer hears doubled full stops
- The iPhone app was not driven on screen: the simulator's app lock asks for the device passcode, which was not entered. Its new screens are verified by the build and by the model checks against real server replies.

**Review:** no data leaks between clients or caseloads were found. Every finding was fixed:
- **Handing a device over:**
  - The Android app could be left from the signing view into the staff app.
  - The iPhone app did not lock if the signer only read the document.
  - The website's signing page showed the staff menu.
  - All three are closed off as described above.
- **Signing in person:**
  - it could complete an emailed document
  - it did not record the staff member
  - it piled up unsigned rows
- **The office default:** a client created in the apps with custom answers lost the creator's office.
- **Virus scanning:**
  - clamdscan was handed a path the ClamAV service could not read (it now streams the file)
  - a hung scanner had no time limit (now two minutes)
  - scanning ran before the cheaper limits
- **Drawn signatures** were not removed when a client's records are purged.
- **Retries:** a retry from the apps could email a signing or release request twice.
- **Reports:**
  - closed referrals counted as contacted on time
  - the scorecard missed agency names typed with different capitals
- **The "Sign it now" link** was offered on a withdrawn document.
- **The website's signing page** now speaks Spanish around a Spanish document.

## Published (not deployed)

2026-10-18, after CI passed all three jobs on 108453d, including the first run of the browser accessibility checks on GitHub's runner. The GitHub release v4.25.0 (latest, not a pre-release) carries:
- the install bundle, built from a clean checkout of the tag
- the signed update package: 327 files, verified with the key built into the code

**Rehearsed before publishing.** The package was installed with the 4.24.0 updater onto a copy of a 4.24.0 site:
- the real signature verified, and the plan showed no problems
- the backup was made first
- the database changes ran (3 statements; database version 4.25.0, client_signatures.method present)
- 28 files were replaced, and the new files were added
- afterwards the sign-in and signing pages served at 4.25.0, and the new reports asked for sign-in

The live site stays as it is until an administrator installs it with Software update. The 4.25.0 phone apps are built from this tag for TestFlight and Google Play.
