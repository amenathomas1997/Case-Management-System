# Release 4.37.0 — November 19, 2026

Web 4.37.0, iPhone app 4.37.0 (build 68), Android app 4.37.0 (version code 21). No database change; the database stays at 4.33.0.

**Not deployed with deploy.sh.** It is published as the GitHub release v4.37.0, and an administrator installs it with Administration, Software update. The phone apps are built from this tag.

**Why.** This is the fourth release of the app parity work in PARITY-AUDIT-2026-11-19.md: services, equipment and staff-written reports. The plan is in that document under Progress, 4.37.0.

**Maintainer decisions taken with defaults (2026-11-19)**
- **Cohort notes:** a Notes field on the website's New cohort form and in both apps, up to 2,000 characters. Longer notes are refused, not cut.
- **Service profitability on Android:** left for 4.38.0.
- **HEIC photos:** refused as the website does. Both apps convert photos to JPEG before uploading. An upload a 4.36.0 app queued offline is refused with "Save the photo as a JPEG or PDF, then upload it again." and stays under Offline entries until discarded.
- **Voice booking:** the meeting link is made in the instructor's own connected Zoom or Teams account; a spoken link is never taken.
- **Letters:** all seven are offered.
- **Evaluation report:** emailed signed (PDF and HTML, as the website) and shared as accessible HTML. The PDF download stays on the website.
- **AI drafts on the website:** "Draft justification with AI" on device requests and "Draft the letter with AI" on letters, under the rule that every staff-written report has AI drafts staff edit before sending.

**What changed, in both apps unless marked**
- **Equipment:**
  - Mark in repair, back in service, or retire, with the website's confirmation.
  - Retired items are listed last.
  - New items take the AT Act type, purchase date and useful life.
  - An item someone else manages says so.
- **Device requests:**
  - deny and cancel with a reason
  - the note shown
  - add the delivered device to the loaner inventory
  - the justification letter
  - "Draft justification with AI"
  - starting a request from AI recommendations
- **Evaluation report:** the four sections with "Draft narrative with AI", emailed signed to the counselor or shared as accessible HTML.
- **Assessments:**
  - worksite recommendations drafted with AI, and the recommendation report
  - goal suggestions with AI
  - "Format with AI" on structured notes
  - recording progress per goal from the progress review
  - templates, and the report attachment
- **Amendments:**
  - the authorization burn-down
  - an AI draft in its own box when the letter was edited, never replacing it
  - record the request as sent, VR's reply, and the approved change opened on the track
- **Letters:**
  - all seven, with "Draft the letter with AI" in its own box
  - saved to the client's documents, kept as a case note, or shared as an accessible file
  - Back with changes asks first
- **Consent and privacy:**
  - paper releases, and recording a release or consent withdrawn
  - the privacy notice given
  - the client portal link, with "Turn off portal link"
- **Documents and the client record:**
  - share uploads on the client or counselor portal
  - move a document to the trash
  - the client timeline
- **Referrals:** status and Show filters, the contact line, and the website's heading.
- **Programs:** cohorts with a coordinator, dates and notes, archive or reactivate them, and delete a session.
- **Other:**
  - instructor matches
  - the knowledge base with general-knowledge answers
  - translation into a typed language
  - accessible documents for a client
  - the website's wording throughout
- **Android only:** the amendment screen, the Programs pipeline's closed list, and the knowledge-base article view.

**Privacy.** Copying a letter or report puts it on the clipboard as private, and clears it after 10 minutes and at sign-out. On the iPhone it is also kept to that device. Shared files are deleted when the screen closes.

**Shared code.** The website pages and the API call the same helpers:
- new files: inc/equipment_status.php, recommend.php, eval_report.php, client_ai.php, programs.php, remediate.php
- additions to many existing helpers

The new API actions are in inc/api_437.php. The API's own copies of the recommendation, goals, case-summary, remediation, translation and amendment AI prompts are gone.

**Older apps.** 4.36.0 apps keep working, and their refused queued entries are kept with the reason. These old-app requests are now refused, each with the website's message:
- an over-long equipment name or notes
- a device-request justification over 4,000 characters
- a .heic upload
- a cohort school over 190 characters

**Tests**
- New: tests/v437_cases.php (173 checks), iPhone checks (263 in all; some now compile and run the app's own code against the server's reply shapes), android V437Test.kt.
- Checked before commit:
  - full_schema (3358), audit_regression, security, staging_scrub and run_all pass
  - the browser accessibility check passes
  - iPhone: builds with no warnings
  - Android: build, 242 unit tests and lint (0 errors) pass

**Review.** Three independent reviews (server, iPhone, Android) checked the work. Everything they found was fixed before commit:
- **Both apps, High:** an edited amendment letter could be replaced by an AI draft without asking.
- **iPhone, High:** the cohort screen could not load on a 4.37.0 server.
- **Server, Medium:** a repeated "record amendment" could show another caseload's history.
- **Medium, also fixed:**
  - AI drafts could cut or overwrite typed text
  - letters were shared as plain text
  - a second press could email or save twice
  - large attachments and photos could run the phone out of memory
  - goal suggestions were asked again when the phone was turned
  - the referral heading showed its count twice
- **Low:** many smaller items, including:
  - AI audit entries written only when the AI was asked
  - unique, cleaned-up shared files
  - the website's wording and order on the new screens

## Published (not deployed)

2026-11-19, after CI passed all three jobs on b3b84e9. The GitHub release v4.37.0 (latest, not a pre-release) carries:
- the install bundle, built from a clean checkout of the tag, including the seven new shared files
- the signed update package: 360 files, verified

**Rehearsed:** installed with the 4.36.0 updater onto a copy of a 4.36.0 site.
- The signature verified, the plan showed no problems, and the backup was made first.
- 45 files were replaced or added (the new shared files are present), with no database change.
- The sign-in page then served 4.37.0.

The live site stays as it is until an administrator installs it with Software update. The 4.37.0 phone apps are built from this tag.
