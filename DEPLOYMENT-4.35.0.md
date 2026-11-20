# Release 4.35.0 — November 19, 2026

Web 4.35.0, iPhone app 4.35.0 (build 66), Android app 4.35.0 (version code 19). No database change; the database stays at 4.33.0.

**Not deployed with deploy.sh.** It is published as the GitHub release v4.35.0, and an administrator installs it with Administration, Software update. The phone apps are built from this tag.

**Why.** The second release of the app parity work (PARITY-AUDIT-2026-11-19.md). The maintainer chose "High first, then rest": 4.34.0 fixed every difference in behaviour, and this release adds the field-casework features the apps lacked. The plan is in the audit document under Progress, 4.35.0.

**Maintainer decisions taken with defaults (2026-11-19)**
- **Assessment dates:** the API keeps its one-day time-zone slack; the website allows none.
- **The remembered caseload filter** on the client list is shared between the website and the apps.

**What changed, in both apps unless marked**
- **Group sessions:**
  - **Booking:** clients, each client's track, instructor, date, start time (10:00 by default), length, location, notes, remote and meeting link, with the website's warning approval. The website's labels are used.
  - **Recording attendance:** who attended (everyone ticked to start), hours for each client who attended, and the website's two-client rule.
  - **Notes:** after completion, the app walks through every attendee's note in turn ("Next: <name>, N of M", Skip for now).
- **Completing a session:** choose the goal worked on and the service delivered.
- **Visible buttons:** Move session, Cancel the series and Cancel the whole group, each confirmed with the website's sentence.
  - On the iPhone, every Schedule row action and link can now be reached by swiping with VoiceOver, not only through the rotor.
- **Client requests:** offer up to three times, with the times listed in the confirmation. Past times are refused on the phone. Android also shows the linked session and due date.
- **After closure:**
  - email the outcome survey again
  - enter survey answers taken by phone
  - record the follow-up checks (Yes / No / Not asked, as on the website)
- **Client form:** preferred language, vision status and the age vision loss began, and the On Hold return date. A passed return date never blocks other edits.
- **Client record:** the On Hold line, VR case number, IPE goal and date, counselor agency, guardian email, vision, attendance and client since.
- **Client list filters:** case status, caseload, client type, funding agency, school and office. The caseload choice follows the website's remembered choice. The cut-off line uses the website's sentence.
- **Android only:**
  - tech assessments, with the website's labels
  - Load older notes
  - the full note editor: track, Generate structured note in every format, and homework title, instructions and due date
  - homework editing
  - Log hours in the iPhone's order, with service, category, goal and session note

**Shared code.** The page logic the new app actions use is in shared helpers that the website pages call too:
- inc/session_group.php
- inc/tech_assessments.php
- inc/client_fields.php
- inc/client_list.php
- additions to session_changes, work_logs, request_offers and outcome_followups

The new API actions are in inc/api_435.php:
- group_choices, schedule_group, group_session, complete_group
- offer_times
- send_survey, phone_survey, closure_followup
- clients_filter

**Older apps.** 4.34.0 apps behave exactly as before. Every new field is optional, and the client list without `with_filters` is unchanged.

**Tests**
- New: tests/v435_cases.php (91 checks), new iPhone static checks (187 in all), android V435Test.kt.
  - The iPhone checks now also read the server files, to confirm every reply key the app reads is sent.
- Checked before commit:
  - full_schema (3064), audit_regression, security, staging_scrub and run_all pass
  - the browser accessibility check passes on 370 pages
  - iPhone: builds with no warnings
  - Android: build, 166 unit tests and lint (0 errors) pass

**Review.** Three independent reviews (server, iPhone, Android) found no High problems. Everything they found was fixed before commit:
- **Server:**
  - **Group rule:** group completion now follows the website's two-client rule.
  - **Input types:**
    - booleans and other wrong types are refused, not read as 1
    - the track list must be keyed by client
  - **Conflicts:** a save conflict names the new client fields.
  - **Repeats:** a repeated group booking, completion or offer rebuilds its full reply.
  - **Tests:** more and stricter.
- **iPhone (Medium):**
  - offer-time errors land on the right field
  - the note after a group is linked to the member's track
  - Reload after a conflict keeps the other person's change to the new fields
  - VoiceOver users reach every row action by swiping
- **Android (Medium):**
  - generated homework survives turning the phone
  - the new client fields show only with a 4.35 server
  - the right reason is given when a group can't be recorded
  - a passed On Hold date no longer blocks saving
- **Both apps:** the wording, the order of answers and the confirmations now match each other and the website.
