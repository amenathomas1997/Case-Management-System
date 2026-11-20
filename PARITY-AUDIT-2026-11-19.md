# App parity audit — November 19, 2026

The maintainer asked that the iPhone and Android apps match the website and behave the same way. Five read-only comparisons covered every website page against the app actions (public/api.php, public/inc/api_parity.php), the iPhone app (ios/Sources) and the Android app (android/app/src/main/java).

| Area | High | Medium | Low |
|---|---|---|---|
| 1. Clients and intake | 4 | 11 | 9 |
| 2. Schedule, sessions and training | 4 | about 10 | about 7 |
| 3. Dashboard, tasks, messages, My Account, help and AI | 5 | 25 | 26 |
| 4. Services, equipment and staff-written reports | 4 | 12 | 14 |
| 5. Billing, funding and administration | 2 | 6 | 12 |
| **Total** | **19** | **about 64** | **about 68** |

High means a staff member gets a different result from an app than from the website, or data is wrong or lost. Medium means a feature someone in the field needs is missing, or a notable behaviour difference. Low means wording or something minor. Administration settings, imports, large reports and similar pages are marked website-only (reasonable) in each part.

## The High findings

**Wrong or lost data**
1. Completing a session from either app credits the hours to the person recording, not the session's instructor, and a group session is not invoiced as a group (2).
2. The iPhone cannot complete a session when hours are already logged that day; Android can (2).
3. Section text (BIRP/PAIP/SOAP) is lost when a note's format goes back to Free text, while the app says "Note saved" (1).
4. Android cannot edit a case note or add an addendum; the iPhone can (1).
5. The iPhone client form has no date of birth, school or IPE dates; Android has no IPE dates (1).
6. A new client that may be a duplicate is saved silently on the iPhone, and warned about only after saving on Android; the website refuses until "This is a different person" is ticked (1).
7. Closing a client's request from an app leaves its offered times open, so the client can still book (2).
8. Editing a track in an app can't set Who pays or the authorized amount, never links an amendment to its approved request, and the iPhone fills in an RSA category on its own (2).
9. A device follow-up task from an app can go to a deactivated account (3).
10. Incidents from an app use a fixed list of types, not the agency's own list (3).
11. iPhone Compliance leaves out guardian consents that ended at 18; Android has no consents section (3).
12. The AI assistant in the apps can't answer the website's own example questions (no last-note dates), and Android Translate sends language codes, so some languages come back wrong (3).
13. A progress report sent from an app covers the whole track instead of the period since the last report, with no final report, barriers or recommendations (4).
14. The app progress report has no AI draft, which breaks the rule that every staff-written report has one (4).
15. Reopening a device request from an app sets it back to Requested instead of its furthest step (4).
16. A loan's return date ignores the administrator's usual loan period: none at all on Android when left blank, a fixed 60 days on the iPhone (4, 5).
17. The utilization figure in the apps still counts no-charge work that was never invoiced (5).

## The full findings

Each part below has a summary table and numbered findings, with file and line evidence on each platform and the fix.


---

## Parity audit 1: clients and intake

Read-only comparison of the website (public/*.php), the app API (public/api.php, inc/api_parity.php, inc/api_425.php), the iPhone app (ios/Sources) and the Android app (android/app/src/main/java/org/casemanagement/app). Line numbers are from the tree at commit de7d9b8.

Abbreviations: W = website, A = API, I = iPhone, D = Android.

### Summary table

| Feature | Website | iPhone | Android | Same behaviour? | Severity |
|---|---|---|---|---|---|
| Client list: search | clients.php | Present | Present | Mostly (API adds phone digits; I has no "first N of total") | Low |
| Client list: status / caseload ("My clients") / type / agency / school / office filters, CSV | clients.php:46-112 | Missing | Missing | No (API `clients` takes only `q`) | Medium |
| Archived clients list, restore, purge | clients.php:9-44, client.php:21 | Missing | Missing | n/a | Website-only (admin) |
| Client record: overview fields | client.php:1456-1580 | Partial | Partial | No (DOB, client type, on-hold reason, texting status, client since, vision not shown) | Low |
| Notes: list, newest 50 + older | client.php notes tab | Present (Load older notes) | Partial (50 only, heading says total) | No | Medium |
| Notes: add free / BIRP / PAIP / SOAP | client.php:430-650 | Present | Present | No: section text silently dropped when format is Free text | High |
| Notes: edit, locked-note addendum, versions | client.php:483-570 | Present | Missing | No | High |
| Notes: locked during edit -> text moved to addendum | client.php:503-510 | Partial (error only) | Missing | No | Medium |
| Notes: track picker | client.php:478-489 | Present | Partial (only when opened from a track) | No | Low |
| Notes: session link warning, homework count | client.php:568-617 | Missing (warning dropped) | Missing (warning dropped) | No | Medium |
| Notes: homework from generator, due-date validation | client.php:470-477 | Partial (no due edit) | Partial (title only) | No (API nulls a bad due date silently) | Low |
| AI: Clean up / Generate structured note | client.php:1124-1260 | Present | Partial (Generate only via separate box, structured mode only) | Mostly | Low |
| AI: Format with AI | client.php:1260-1290 | Missing | Missing | n/a | Low |
| AI: case summary | client.php:~1290-1314 | Present | Present | No (API context omits assessments, 8 notes vs 10) | Low |
| AI: goal suggestions from an assessment | client.php:~1315-1335 | Missing | Missing | n/a | Low |
| Tech assessments: list + record | client.php:654-755 | Present | Missing (only via AI intake) | No | Medium |
| AI guided intake (intake.php) | intake.php | Present | Present | D always dates the assessment today | Low |
| Client edit / add (client_edit.php) | client_edit.php | Partial (no DOB, school, IPE dates, vision, language, on-hold return date) | Partial (no IPE dates, vision, language, on-hold return date) | No | High (I) / Medium (D) |
| New-client duplicate check | client_edit.php:203-206 | Missing (warning ignored) | Partial (warned after saving) | No (W refuses until "different person" ticked) | High |
| Status change, closure, survey | client_edit.php | Present | Present | Mostly | Low |
| Guardian consent: request | client.php:882-985 | Present | Present | No (`consent_seen` never sent) | Medium |
| Guardian consent: record withdrawal | client.php:986-1005 | Missing | Missing | n/a | Low |
| Releases: email to sign | client.php:1007-1036 | Present | Present | Yes | - |
| Releases: record paper release, withdraw | client.php:1007-1065 | Missing | Missing | n/a | Medium |
| Portal link: send | client.php:856-867 | Present | Present | Wording and confirmation differ | Low |
| Portal link: turn off | client.php:869-880 | Missing | Missing | n/a | Low |
| Privacy notice, legal hold | client.php:1038-1055 | Missing | Missing | n/a | Website-only (hold, admin); notice Low |
| After closure: email survey, phone survey answers, follow-up checks | client.php:1066-1122 | Missing | Missing | n/a | Medium |
| Documents: list, download, share, upload | client.php:756-855, download.php | Present | Present | Mostly (no share-on-upload) | Low |
| Documents: delete (to trash) | client.php:838-855 | Missing | Missing | n/a | Low |
| Assessment attachment download | download.php?assessment= | Missing | Missing | n/a | Low |
| Status history | client.php history tab | Present | Present | Yes | - |
| Client timeline | client_timeline.php | Missing | Missing | n/a | Low |
| Search | search.php | Present | Present | No (API searches less) | Medium |
| Pipeline | pipeline.php | Present | Partial (no "Closed in last 90 days") | Mostly | Low |
| Referrals list | referrals.php | Partial (no status filter) | Present (status filter) | Mostly | Low |
| Referral: view, duplicates, assign, review, convert, close, reopen, attach | referrals.php:31-240 | Present | Present | Mostly | Low |
| Worksite assessments (accommodations.php) | accommodations.php | Present | Present | No AI recommendations or employer report in apps | Low |
| Amendment request: letter | amendment_request.php | Present | Present | Yes | - |
| Amendment request: record as sent / record reply | amendment_request.php:26-47 | Missing | Missing | n/a | Medium |
| Letters | letters.php | Missing | Missing | n/a | Low |
| Custom fields on the client (show + edit) | client.php, client_edit.php | Present | Present | Yes | - |
| Custom fields builder | custom_fields.php | - | - | - | Website-only (admin) |
| Client export ZIP | client_export.php | - | - | - | Website-only (records requests) |
| Client import, merge, trash | client_import/merge/trash.php | - | - | - | Website-only (admin) |
| Public forms: community_referral, referral, self_referral, consent, release | public pages | - | - | - | Website-only (public, not staff) |

Counts: 24 numbered findings: High 4 (1-4), Medium 11 (5-15), Low 9 (16-24, several grouping small items).

### Findings

#### 1. Android cannot edit a case note or add an addendum (High)
- W: client.php:483-570 edits a note (author or admin, inside the edit window), keeps an earlier version, and for a locked note offers an addendum (client.php:603, `addendum_to`).
- A: `update_note` api.php:1191-1275 and `add_note` with `addendum_to` api.php:1150-1153 exist; `client` sends `can_edit`, `locked`, `note_version` per note (api.php:1013-1021).
- I: Edit and Add addendum on every note (ClientsView.swift:425-446, 1232-1253).
- D: no `update_note`, no `addendum_to`, no use of `can_edit`/`locked` anywhere (grep: zero hits). `NoteRow` only expands the text (ClientsScreen.kt:221-227).
- Effect: an Android user who mistypes a note has to go to the website; a locked note cannot be corrected from the phone.
- Fix: add an edit screen for notes with `can_edit` (send `id`, fields, `track_id`, `note_version`; handle 409 `conflict` and 409 `locked`), and an "Add addendum" action for `locked` notes that opens AddNoteScreen with `addendum_to` and type Addendum (subject "Addendum: ..."), as iOS does.

#### 2. Text typed in BIRP/PAIP/SOAP sections is silently lost when the format is set back to Free text (High, both apps)
- W: client.php:453-460 refuses the save ("Free text is chosen as the note format, but the structured sections still hold text...").
- A: `add_note` builds the body from `assemble_note` (api.php:1058-1064); with `note_format` free it keeps only `body`.
- I: `save()` sends `sec1..sec4` only when `structured` (ClientsView.swift:1218-1229); the sections stay in state but are not sent.
- D: same, sections sent only when `structured` (ClientsScreen.kt:352-359).
- Effect: a staff member who drafts sections, then switches to Free text, saves a note without that text and is told "Note saved."
- Fix: in both apps, when format is free and any section has text, refuse with the website's message (or move the section text into the note with the labels). Better still, have `add_note`/`update_note` refuse `free` with non-empty `sec1..4` (422) so every client is protected.

#### 3. iPhone client form has no date of birth, school, IPE dates (High)
- W: client_edit.php has DOB (validated 1900-today), school for Pre-ETS, IPE date and amendment date (client_edit.php:76-79, 152, 117-122).
- A: `save_client` accepts `dob`, `school`, `ipe_date`, `ipe_amended_on` (api.php save_client lines 52, 78-87, 155).
- I: ClientEditSheet.swift has no DOB, school or IPE date fields and its body never sends them (ClientEditSheet.swift:550-560). ClientInfo decodes `dob` (API.swift:149) but the detail screen never shows it.
- D: DOB and school present (ClientEditScreen.kt:290-291, 348, 355); IPE dates missing.
- Effect: a client added on the iPhone has no date of birth (RSA-911 age, duplicate matching by DOB) and a Pre-ETS student has no school; IPE dates can't be entered on either app.
- Fix: add Date of birth (optional, not in the future), School (Pre-ETS only), IPE date and IPE amendment date (today or earlier) to the iPhone form; add the IPE dates to Android. Show DOB and client type on both detail screens.

#### 4. New-client duplicate check: website refuses, apps save (iPhone says nothing) (High)
- W: client_edit.php:203-206 refuses a new client who may already have a record until "This is a different person" is ticked (client_edit.php:505).
- A: `save_client` saves first and returns `duplicate_warning` (api.php save_client lines 366-371).
- I: `SaveResult` decodes only ok, clientId, unarchived, surveySent (ClientEditSheet.swift:513-524); `duplicate_warning` is never shown. A duplicate record is created silently.
- D: shows `duplicate_warning` after saving (ClientEditScreen.kt:391), so the duplicate already exists.
- Fix: make `save_client` behave as the website for new clients: when `possible_duplicate_clients` finds matches and `not_duplicate` is not sent, answer 409 `{duplicate: true, error: <warning text>}` without saving; both apps then show the warning on the Full name field with a "This is a different person" toggle and save again with `not_duplicate: 1`. At minimum, iOS must show `duplicate_warning`.

#### 5. The case note's session warning and homework count are thrown away (Medium, both apps)
- W: session mode says what happened, moves to the next group member, and lists who is still to write (client.php:600-617); a note saved with homework says "N homework items added to the client portal."
- A: `add_note` returns `warning` when the session is not Completed (note saved but not linked) and `homework_created` (api.php:1094-1100, 1187).
- I: `SaveAck` decodes only ok and deduplicated (ClientsView.swift:1423-1426); message is always "Note saved."
- D: `sendOrKeep` uses `message` or the fixed "Case note saved." (Saving.kt:93); `warning` ignored.
- Effect: the session still sits in Notes to write while staff are told the note is done.
- Fix: decode `warning` and `homework_created` and append them to the confirmation (iOS AddNoteSheet.save; Android pass a builder to saveOrQueue or read `reply`). Optionally offer the next group member as the website does.

#### 6. Editing a note that has just locked: website moves the text into an addendum, iPhone only shows an error (Medium)
- W: client.php:503-510 keeps the typed text and opens the addendum form with it.
- A: 409 `{locked: true}` (api.php:1222).
- I: the error banner shows `note_locked_message()`; to add an addendum the person must Cancel (discarding the text) and choose Add addendum (ClientsView.swift:1235-1245).
- Fix: on `locked`, switch the sheet into addendum mode (addendumTo = existing, existing = nil, keep subject/body/sections, type Addendum) and say so. Android needs this once finding 1 is done.

#### 7. On Hold, language, vision: not saved from the apps (Medium)
- W: client_edit.php saves `preferred_language` (line 166), `vision_status`/`vision_onset_age` (117-124, 259), and an optional On Hold return date (on_hold_until, lines 108-111, 617).
- A: `save_client` reads `on_hold_until` (save_client line 90) but stores no `preferred_language`, `vision_status` or `vision_onset_age`, and `client` does not send them, nor `on_hold_reason`/`on_hold_until`.
- I: no On Hold return date; "Reason for status change" is not marked required for On Hold (ClientEditSheet.swift:331-333), so the server's refusal is the first the person hears of it.
- D: hint "required for On Hold" (ClientEditScreen.kt:269); no return date.
- Fix: add `preferred_language`, `vision_status`, `vision_onset_age` to `save_client` (validated as the website) and to `client`'s reply with `on_hold_reason`/`on_hold_until`; add the fields to both forms, the reason labelled "(required for On Hold)" on iOS, and show "On Hold: reason. Expected back date" on the detail screens.

#### 8. Consent request can replace a colleague's just-sent request (Medium, both apps)
- W: the form carries `consent_seen` and refuses when a newer unsigned request exists (client.php:928-940).
- A: supports `consent_seen` (api_parity.php:837-848).
- I/D: neither app sends `consent_seen` (grep: no hits). The guardian's earlier link stops working without warning.
- Fix: the GET `request_consent` reply should include the newest unsigned request id (add `pending_id` to `parity_consent_state`); both apps send it back as `consent_seen` and show the 409 message.

#### 9. Client list filters missing (Medium)
- W: clients.php:46-112 filters by status, caseload (Everyone / My clients / Unassigned / a named instructor, remembered per user; caseload-scoped default My clients), client type, funding agency, school, office; CSV of every match.
- A: `clients` takes only `q` (api.php:909-937).
- I: search only (ClientsView.swift:62-104); D: search only (ClientsScreen.kt:31-56).
- Fix: add `status`, `who`, `type`, `agency`, `school`, `office` to the `clients` action (same SQL as clients.php, and read/write the same `clients_filter_<uid>` setting), plus a filter sheet/choices on both apps with quiet pickers. CSV is reasonably website-only.

#### 10. Search finds less on the apps (Medium)
- W: search.php:28-66 searches clients by counselor name too, referral body, SMART goals, documents, invoices (billing), 25 per group with "more" said.
- A: api.php:2371-2400: no counselor-name match on clients, no referral body, no goals/documents/invoices groups, 15 per group, no "more" flag.
- Fix: bring `search` to the website's queries and groups, return `capped` per group; apps say "first 15 (more on the website)" or raise to 25. Map `kind` for goal (track), document (client documents) and invoice.

#### 11. Android has no tech assessment list or form (Medium)
- W: Assessments tab lists them and records one (client.php:654-755, 2071-2140).
- A: `assessments`, `add_assessment` (api.php:2230-2257).
- I: AssessmentsSection with a record sheet (ClientEditSheet.swift:750-916).
- D: never calls `assessments`; `add_assessment` only from AI guided intake, always dated `today()` (AiScreens.kt:217-221).
- Fix: add an Assessments list and "Record a tech assessment" form (date, primary disability, primary technology, hardware, software, summary) to the Android client screen; give the AI intake form a date field.

#### 12. Android shows only the newest 50 notes (Medium)
- A: `client` returns 50 with `notes_total` and `notes_offset` paging (api.php:1001-1010).
- I: "Load older notes" (ClientsView.swift:451-466, 700-740).
- D: heading "Case notes (total)" but only the first 50 are listed, no way to reach older ones (ClientsScreen.kt:145-146).
- Fix: add "Load older notes (N more)" calling `client` with `notes_offset`, as iOS; say "latest 50 of N" in the heading.

#### 13. After-closure work is website-only (Medium)
- W: email the outcome survey again, enter survey answers taken by phone, and record the 90/180-day follow-up checks (client.php:1066-1122, 1684-1735). A follow-up that needs service creates a re-referral task.
- A/I/D: no actions or screens.
- Note: these are phone calls staff make in the field.
- Fix: add `client_followups` (list due checks and survey state), `phone_survey`, `closure_followup`, `send_survey` actions that call `survey_record_phone`, `closure_followup_record`, `send_outcome_survey` exactly as client.php does, and an "After closure" section on both client screens for closed clients.

#### 14. Paper release and withdrawing a release or consent are website-only (Medium)
- W: record a paper release (client.php:1007-1036, method Paper), withdraw a release (1057-1064), record that the guardian withdrew consent (986-1005).
- A: `request_release` handles only Email (api_425.php:140-163); no withdraw actions.
- Fix: accept `method: "Paper"` (with signed_on, signer) in `request_release`, add `withdraw_release` and `withdraw_consent` (with the consent id the screen showed, as the website checks), and offer them on both apps' release list / consent section with a confirmation.

#### 15. Amendment requests cannot be recorded from the apps (Medium)
- W: "Record as requested" saves the letter as a track note and creates a 10-business-day follow-up task; "Record reply" records approved/denied (amendment_request.php:26-47, inc/amendments.php).
- A: `amendment` (api.php:4634-4770) only drafts the letter and counselor address.
- I/D: letter and "email counselor" only (TrackDetailView.swift:929-936; TrackScreen.kt:69).
- Fix: add `record_amendment` and `amendment_reply` actions calling `amendment_record`/`amendment_reply`; after the person emails the letter, offer "Record that I sent it" and a reply form on the track.

#### 16. Client detail misses fields the website shows (Low)
- W overview: client type (and school), DOB, attendance, vision, text-message agreement, client since, unverified-email warning, On Hold reason and return date (client.php:1344-1347, 1456-1500).
- I: no DOB, client type, VR case number, IPE goal, counselor agency, guardian email (ClientsView.swift:208-290). D: no DOB, client type (ClientsScreen.kt:76-101).
- A: does not send created_at, on_hold_reason/until, vision, attendance.
- Fix: add those keys to `client` and show them; on iOS add VR case number, IPE goal, agency and guardian email.

#### 17. Android note editor gaps (Low)
- No track picker for a general note (track only when opened from a track, ClientsScreen.kt:348); website and iOS offer one.
- Generate structured note needs the separate rough-notes box and a structured format first (ClientsScreen.kt:298-319); iOS 4.33.0 and the website also use what was typed in the note.
- Homework rows show only a tick and the title (ClientsScreen.kt:329); instructions and due date can't be checked or edited.
- Note rows omit format, track and "edited by" (ClientsScreen.kt:221-227).
- Fix: as iOS.

#### 18. Homework due dates: website refuses a bad date, API drops it (Low)
- W: client.php:470-477 error on the row. A: invalid `due` becomes no due date silently (api.php:1172-1174). Neither app lets the due date be edited.
- Fix: return 422 naming the item (as for titles), and add a due-date field to the homework rows.

#### 19. AI help differences (Low)
- Case summary: API context omits tech assessments and takes 8 notes, website 10 plus 5 assessments (api.php:3052-3064 vs client.php ~1290-1314). Results differ for the same client.
- Goal suggestions from an assessment (client.php ~1315-1335) and "Format with AI" are not in either app; API `ai_draft goals` exists but omits hardware/software.
- Fix: share one function for the case-summary context between client.php and api.php; offer "Suggest goals" on an assessment row.

#### 20. Portal link: confirmation and wording (Low)
- W confirmation warns that a closed client's portal is read-only and names a phone/post contact preference; success says it stays open while used every six months (client.php:1376-1379, 863-864).
- A message: "Portal link emailed to X. Any earlier link no longer works." (api.php:835). Apps' confirmations (ClientsView.swift:296-299; ClientsScreen.kt:148) lack both warnings.
- "Turn off portal link" (client.php:869-880) is missing from both.
- Fix: return the website's full message from the API; add the two conditions to the app confirmations; add a `revoke_portal_link` action.

#### 21. Documents: delete, share on upload, assessment attachments (Low)
- Delete (to the 30-day trash; Authorization/IPE/Release admin-only) is website-only (client.php:838-855). Upload cannot share with portal/counselor in one step (A upload_document has no flags). Assessment attachments (download.php?assessment=) are not listed or downloadable in the apps; API add_assessment takes no attachment.
- API accepts `.heic` uploads the website form refuses (api.php:2268).
- Fix: `delete_document` action with the same category rule; optional `share_portal`/`share_counselor` on `upload_document`.

#### 22. Referrals list on iPhone has no status filter (Low)
- W default Open, with Converted/Closed/All and "Assigned to me" (referrals.php:293-301). A defaults to all, open first, 100 cap.
- D: status choice (TasksScreen.kt:176-180). I: no filter (ReferralsView.swift:275-280), so closed and converted ones past the first 100 cannot be reached; it says "the rest are on the website".
- Neither app shows the contact deadline (`contact_due`) the website list shows.
- Fix: add the status picker (and "assigned to me") on iOS; send `contact_due` from `referrals`.

#### 23. Pipeline on Android lacks "Closed in last 90 days" (Low)
- A sends `closed` (api_parity.php:964); iOS shows it (PipelineView.swift:74); Android does not (ReportsScreens.kt:66-80).

#### 24. Small API vs website differences (Low)
- `review_referral` answers ok when the referral is no longer New; website says "Nothing was changed: ... now Converted" (api.php:2899-2911 vs referrals.php:108-121).
- `add_assessment` allows tomorrow; website refuses any future date (api.php:2242 vs client.php:663).
- Website adds a Pre-ETS age advisory (14-21) on save (client_edit.php:190-198); API does not.
- Website writes a separate `client.email_confirm` audit entry; API folds it into `client.update` text (save_client lines 301-310).
- `request_consent` returns `errors` per field; neither app ties them to the Guardian name/email fields (ClientsScreen.kt:176-201).
- iOS client list does not decode `total` and assumes 200 means more (ClientsView.swift:41-44; API.swift:143).
- Worksite assessments: AI "Draft recommendations" and the employer recommendation report (accommodations.php:117-135, 200-220) are not in the apps.
- Client timeline (client_timeline.php) and Letters (letters.php) have no API; staff in the field could use the timeline read-only.

#### Website-only (reasonable)
- custom_fields.php, client_import.php, client_merge.php, trash.php, archived list / restore / purge, legal holds: administrator tasks.
- client_export.php: a ZIP for records requests and audits, done at a desk.
- community_referral.php, referral.php, self_referral.php, consent.php, release.php: public forms for referrers, guardians and clients, not staff.
- Letter template editing in letters.php (admin). Writing a letter for one client could reasonably come to the apps later.
- Privacy notice "given" (client.php:1038-1045): small, but intake is often in person, so worth adding with finding 14.

---

## Parity audit 2: scheduling, sessions and training work

This was a read-only comparison. No repository file was changed.

**Where things are**
- **Website:** public/schedule.php, availability.php, attendance.php, portal_requests.php, track.php, track_edit.php, training.php, homework_edit.php, curriculum.php, sign.php, signatures.php, account.php (calendar), inc/session_notes.php, inc/voice.php.
- **API:** public/api.php, inc/api_parity.php, inc/api_425.php, inc/api_more.php, inc/at_api.php.
- **iPhone:** ios/Sources/ScheduleView.swift, LogHoursView.swift, TrackDetailView.swift, PortalRequestsView.swift, SessionNotes.swift, DocumentsAndHomework.swift, CompetencyView.swift, TrainingView.swift, DocumentsToSign.swift, VoiceAssistant.swift.
- **Android:** ui/ScheduleScreen.kt, NewSessionScreen.kt, ClientsScreen.kt (LogHoursScreen), TrackScreen.kt, MoreTools.kt (PortalRequestsScreen), SignaturesScreen.kt, TrainingScreens.kt, AssessmentScreens.kt, VoiceScreen.kt, SessionNote.kt.

### Summary table

| Feature | Website | iPhone | Android | Same behaviour? | Severity |
|---|---|---|---|---|---|
| Schedule one session / weekly series | schedule.php add_session | Present | Present | Partly: no duplicate check in the API; Waitlist and other non-open clients offered; remote clears location; audit text differs | Medium |
| Several days a week, list of dates, whole authorization (session_plan) | Yes | Missing | Missing | — | Medium |
| Make the meeting link (Zoom/Teams, meeting_make) | Yes | Missing | Missing | API has no field | Medium |
| Typed meeting link and Remote | Yes | Present | Present | Yes (the API also clears the location) | Low |
| Approving authorization, Outlook and overlap warnings | Yes (hash) | Present | Present | Mostly; wording and prefix differ | Low |
| Schedule a group session | Yes | Missing | Missing | API has no action | Medium |
| Group attendance and completion | Yes | Missing | Missing | Apps complete each sitting one by one, which logs the wrong description | High (see 1) |
| Complete a session (hours, miles) | Yes | Present | Present | **No**: the hours go to the person who records them, not the instructor; the description differs | High |
| Completed: "hours already logged" or "log hours as well" | Yes | **Missing** (dead end) | Present | iPhone sends no `outcome` | High |
| Goal worked on and service delivered at completion | Yes | Missing | Missing | API ignores them | Medium |
| Client signature at completion | No | Present | Present | App-only extra | Low |
| Straight into the case note after completing (4.31.0) | Yes | Present | Present | Yes; group note chain n/a | — |
| Notes to write list | Yes | Present | Present | Yes | — |
| Cancel or No Show one session | Yes (confirm) | Present (confirm) | Present (**no confirm**) | Server is the same; Android does not ask first | Medium |
| Several sessions recorded as no-show or cancelled at once | Yes | Missing | Missing | — | Low |
| Move a session (with warnings) | Yes | Present (VoiceOver Actions only) | Present (not for ended sessions) | Server is the same | Low/Medium |
| Cancel the rest of a series / the whole group | Yes | Present (VoiceOver Actions only) | **Missing** | — | Medium |
| Check-in | Shows "Arrived" only | Present | Present | App-only action | Low |
| Upcoming filter by client or office; more than 100 | Yes | "Mine only" toggle; says there are more | No filter; never says the list is cut at 100 | — | Low |
| My availability (self-booking hours) | availability.php | Missing | Missing | — | Low/Medium |
| Self-booking rules (admin) | Yes | — | — | website-only (reasonable) | — |
| Attendance report | attendance.php | Missing | Missing | website-only (reasonable) | — |
| Client requests: list and status | portal_requests.php | Present | Present | **No**: the API does not withdraw offered times when it closes a request | High |
| Client requests: offer times | Yes | Missing | Missing | — | Medium |
| Client requests: publish a records copy, close several at once | Yes | Missing | Missing | — | Low |
| Client requests: session and due date shown | Yes | Present | Missing | — | Low |
| Log hours (track.php add_log) | Yes | Present | Partial (no service type, goal or note) | API refuses a mileage-only entry or a note on its own | Medium |
| Work log: correct and delete | Yes | Present | Present | Same | — |
| Track create/edit (track_edit.php) | Yes | Partial | Partial | Who pays and dollar amount missing; amendment request not linked | High |
| Homework assign / done / edit | Yes | Present | Assign and done only (no edit) | — | Low/Medium |
| Competency (curriculum.php ratings) | Yes | Present | Present | Same (both send only changed levels) | — |
| Curriculum management | Yes (templates) | — | — | website-only (reasonable) | — |
| My training (training.php) | Yes | Present | Present | Same backend (training_submit) | — |
| Documents to sign / in person / withdraw | signatures.php, sign.php | Present | Present | The API emails administrators on an in-person signature; the website does not | Low |
| Calendar sync (Outlook/CalDAV/Google, test) | account.php | Feed address only | Feed address only | website-only (reasonable); see 20 | Low |
| Voice: schedule, complete, cancel, log hours | ai_command.php | Present | Present | Same backend; voice booking has no duplicate check | Low |

### Findings

#### 1. Completing a session in the apps logs the hours to the wrong person, with the wrong description (High)
- **Website** (schedule.php:823-824, 413-414): `auto_log_session(..., schedule_log_description($sess), (int)$sess['user_id'], ...)`. The hours belong to the instructor who taught the session, "whoever records it". A sitting of a group is written "Group session: …", which the comment calls "the only place a group service shows" on the invoice. Location is included.
- **Voice** (inc/voice.php:841-842) follows the website: it uses `$sess['user_id']` and the "Group session" wording.
- **API** `complete_session` (public/api.php:1459-1460) uses `'Training session' . notes . ' (logged from mobile)'` and `$userId`, the person recording it.
- **Effect:**
  - When a supervisor or front desk completes someone else's session from a phone, the hours count as the recorder's (utilization, staff hours, my_week).
  - Each client's sitting of a group, completed from a phone, invoices as "Training session".
  - The invoice line carries "(logged from mobile)".
- **Fix:** in api.php complete_session, call `auto_log_session(..., schedule_log_description($sess), (int)$sess['user_id'], $authIssue, $miles)`. Move `schedule_log_description()` from schedule.php into a shared include (inc/session_changes.php). Keep "mobile" in the audit only.

#### 2. The iPhone cannot complete a session when hours are already logged that day (High)
- **API** (api.php:1386-1398): with hours already logged and no `outcome` key, it answers "Complete this session on the website's Schedule page…" with a `logged_hours` list.
- **Android** sends `outcome` and offers "These are this session's hours" / "This was a separate visit" (ScheduleScreen.kt:199, 230-235).
- **iPhone** never sends `outcome` or `work_log_id` (ScheduleView.swift:822-834), and CompleteSessionSheet has no choice for it (1200-1291). The staff member is sent to the website.
- **Fix:** in iPhone `complete()`, send `"outcome": "completed"`. On a 422 that carries `logged_hours`, show the two website choices in CompleteSessionSheet: "Completed, hours already logged" (one button per entry, with `work_log_id`) and "Completed, log new hours" (`completed_separate`). Do not lose the drawn signature.

#### 3. Closing a client request from the apps leaves its offered times open (High)
- **Website** portal_requests.php:43-44 (and the bulk path at 92): on Done or Declined it runs `UPDATE portal_request_offers SET status='Withdrawn' ... AND status='Offered'`.
- **API** `portal_request_update` (inc/api_parity.php:480-524) says it repeats the page "step for step", but leaves this out.
- **Effect:** a request closed from a phone still lets the client confirm an offered time in the portal, which books a session for a request staff have closed.
- **Fix:** in the closed branch of api_parity.php (after `complete_task_by_key('preq-'...)`), add the same `UPDATE portal_request_offers ... 'Withdrawn'`. Better, move the website's status-change body into one shared function that both call.

#### 4. Track edit in the apps: who pays, dollar amount and amendment request missing (High)
Details from the save_track comparison:
- **API** `save_track` (inc/api_parity.php:527-792) ignores `amend_request`. On the website (track_edit.php:224-228) an amendment saved against an approved request links the two; from an app it never does.
- **API** ignores `incoming_auth` (track_edit.php:328-329). A track made from an authorization email in the app leaves that email New.
- **Both apps** offer neither Who pays (`billing_mode`) nor the Authorized dollar amount. Staff cannot create a Private pay or No charge track, or set or clear a dollar limit, from the phone. Edits keep the stored values.
- `parity_track_choices` (api_parity.php:194-214) does not send:
  - the BILLING_MODES list
  - the private rate
  - the referral's quoted-fee defaults (track_edit.php:347-355)

  So an app-made track is always VR.
- **iPhone** (TrackDetailView.swift:1789, 1849) fills in the first RSA category for a track that has none, so saving silently writes it. The website uses the service type's default (track_edit.php:432).
- **Android** (TrackScreen.kt:488-534):
  - no bundle or RSA category picker
  - a new track sends an empty mileage rate, so the server default applies; the iPhone and the website send 0
  - only Title is marked required
- **Low:**
  - the API refuses "$4,500" for the dollar amount; the website strips the "$" and ","
  - iPhone field errors are matched by message prefix, so "Hourly rate must…" is not tied to "Hourly billing rate"
- **Fix:**
  - read `amend_request` and `incoming_auth` in save_track as track_edit.php does
  - add billing_mode, the private rate and defaults to the choices
  - add Who pays and Authorized dollar amount to both forms
  - default the iPhone RSA category to `track_rsa_default`
  - Android: add the bundle and RSA pickers, send mileage 0 as the iPhone does, mark required fields

#### 5. API booking has no "already on the schedule" check (Medium)
- **Website** (schedule.php:585-604, 279-298): under the lock, the same client, instructor and start already Scheduled is refused ("already on the schedule…, so it was not added again"). A partial overlap of a series is refused with the dates.
- **API** `schedule_session` (api.php:2480-2623) relies only on `idem_key`. When a booking is sent again with a new key, it books and invites twice. This happens when the iPhone says "isn't known whether this was scheduled" (ScheduleView.swift:1178-1183) and the user opens a new form.
- **Voice** `schedule_session` (inc/voice.php:525-606) has no such check either.
- **Fix:** add the website's `$dupQ` loop inside the API's transaction after `session_lock_instructor`, and the same in voice. Ideally move it into a shared `session_book()`.

#### 6. Apps can book clients the website will not (Medium)
- **Website** books only open statuses except Waitlist (schedule.php:895-897, `client_open_statuses()` minus Waitlist).
- **Both apps** drop only statuses starting with "Closed": API.swift:2136 and NewSessionScreen.kt:54. So Waitlist, archived-to-be and custom non-open statuses are offered.
- The API does not check status at all.
- **Fix:** have `client_choices` return a `bookable` flag (or accept `?for=schedule`). Refuse in `schedule_session` with "That client is on the waitlist or closed; …".

#### 7. Booking differences in the API (Low/Medium)
- **Location:**
  - The API empties the location for a remote session (api.php `if ($isRemote) $location = ''`). The website keeps both.
  - The API invite puts the meeting URL in LOCATION; the website uses the typed location.
- **Weeks limit:** the website's "Repeat weekly" input allows a maximum of 52 (schedule.php:1168), but session_plan refuses more than 26 (session_plan.php:27). The apps use 26. Fix the website max to 26.
- **Audit:**
  - The website records the warning text approved ("— authorization exception approved: …", schedule.php:651-652).
  - The API records only "authorization warnings explicitly acknowledged" (api.php:2622). Record the text, for the audit trail.
  - Voice audits `session.add` (voice.php:580) instead of `session.schedule`.
- **Prefix:**
  - The API always says "Authorization review:". The website says "Check before booking:" when only an overlap or Outlook was raised (schedule.php:118-121).
  - The API's Outlook line is generic ("The instructor's"). The website names the instructor and the days.
  - Both still say "Outlook" although the calendar can be CalDAV or Google since 4.33.0.
- **Email:** the API's invite email omits the time zone ("T") and says "Remote session" instead of "Format: Remote / telepractice".

#### 8. Several sessions at once, and making a meeting link, are website-only (Medium)
- Plan patterns ("several days each week", "list of dates", "whole authorization", schedule.php:485-548) and "Make the meeting link" in Zoom or Teams (schedule.php:496-506, 627-644) are not in `schedule_session`. Neither app has them.
- A staff member booking from the field must type a link or go to the website.
- **Fix:** accept `plan_pattern`, `plan_days`, `plan_until`, `plan_dates` and `plan_ok` (the preview hash) in the API, plus `meeting_make` with the same owner and connection checks. Add both to the forms (collapsed, no extra stops).

#### 9. No group sessions in the apps (Medium; the data part is High via 1)
- Scheduling a group (schedule.php:188-342) and Record attendance and complete (345-471) have no API actions.
- The apps show a sitting as "group of N", and Cancel the whole group exists (iPhone only).
- Completing each member one by one in an app skips the attendance step and the website's group wording. No Show for absent members must be recorded separately.
- **Fix:** add `complete_group` (same `attendance_form` semantics: listed, attended, g_hours) and `schedule_group` API actions, sharing code with schedule.php. Until then, at least fix finding 1.

#### 10. Goal worked on and service delivered are not recorded at completion (Medium)
- **Website** (schedule.php:827-839, 1040-1053) saves `session_goal` and `session_category` on the work log.
- **API** complete_session ignores them, and neither app offers them, so app-completed sessions always get the track's first RSA category. This affects RSA/911 reporting when a track has more than one category.
- **Fix:** accept `goal_id` and `rsa_category` in complete_session with the website's checks. Add the two pickers to the completion sheet and dialog, shown only when the track has goals or more than one category.

#### 11. Android: Cancel and No Show without confirmation; offered when the server will refuse (Medium)
- ScheduleScreen.kt:115-120 sends `set_session_status` at once. The website (schedule.php:1024) asks "This cannot be changed back"; the iPhone asks (ScheduleView.swift:176-187).
- Android offers "Record a no-show" and "Complete the session" for sessions that have not started (the server refuses). The website hides both until the start; the iPhone hides Complete.
- **Fix:** add a Confirm step for both. Hide No Show and Complete before `start_at`.

#### 12. Android has no "Cancel the rest of the series / whole group"; the iPhone hides them and Move in the VoiceOver Actions rotor (Medium)
- API `cancel_series` and `cancel_group` exist (api.php:777-800). Android never calls them.
- On iPhone, Move, Cancel series and Cancel group exist only in `.accessibilityActions` (ScheduleView.swift:619-621). A sighted user or Voice Control user without VoiceOver cannot see them.
- Android hides Move for ended sessions (ScheduleScreen.kt:114); the website offers Move on ended rows.
- **Fix:**
  - Android: add the two buttons when `in_series` or `group_uid` is set, with a confirm.
  - iPhone: add visible buttons, or a "More" menu, for Move and Cancel more.
  - Android: allow Move on ended sessions.

#### 13. Log hours: mileage-only and note-only entries refused; Android misses fields (Medium)
- **Website** add_log (track.php:181-215) accepts:
  - hours 0 with miles (travel only)
  - a note without hours
  - a structured note format and homework rows
- **API** `log_hours` (api.php:1531-1536) refuses hours below 0.01 and requires a description.
- **Android** LogHoursScreen (ClientsScreen.kt:367-433) has no service type, goal or session-note fields. The iPhone has them (LogHoursView.swift:403-416).
- **iPhone** forces quarter hours. The website and API take two decimals.
- The website's "no homework on this track" reminder is not given in the apps.
- **Fix:**
  - API: allow `hours = 0` when `mileage > 0`.
  - Android: add service type, goal and note.
  - iPhone: allow two decimals.

#### 14. Client requests: offering times, records copy and bulk close are website-only (Medium)
- `offer_times` (portal_requests.php:95-138) is the main answer to an appointment request, and no API action exists for it.
- `publish_copy` and `bulk_close` are also missing.
- Android does not show the linked session or the due date (MoreTools.kt:211). The iPhone shows both (PortalRequestsView.swift:142-144).
- **Fix:** add an `offer_times` action over `offer_times_save()` with the same field errors. Show `session_start` and `due_on` on Android.

#### 15. Homework edit missing on Android (Low/Medium)
The iPhone edits through `at_homework_update` (DocumentsAndHomework.swift:622), as homework_edit.php does. Android only assigns and marks done (DocumentsScreen.kt:102, 191). **Fix:** add an edit form on Android using `at_homework_update` with the `edit_token` conflict handling.

#### 16. My availability is not in the apps (Low/Medium)
availability.php add and remove hours (any signed-in staff). A staff member changing their hours on the road needs the website. The self-booking rules are an administrator setting: website-only (reasonable). **Fix (optional):** `availability`, `add_availability` and `remove_availability` actions over `availability_add()`.

#### 17. Signing in person: the API emails administrators, the website does not (Low)
- inc/api_425.php:126 calls `notify_admin_users('Signed in person: …')`. signatures.php:36-56 does not. The same signing behaves differently by channel.
- Otherwise the paths match: `signature_input`, `signature_start_in_person`, `signature_sign_in_person`, withdraw on stop, Spanish refusal.
- **Fix:** do the notification in `signature_sign_in_person()` for both channels, or remove it from the API.

#### 18. Smaller schedule-list differences (Low)
- Android never uses `upcoming_total`, so a list cut at 100 is not said. The iPhone says it (ScheduleView.swift:152-155).
- Android's "Write it now" for Completed rows (ScheduleScreen.kt:111) can never show: `schedule` lists only Scheduled sessions. The Notes to write list covers it.
- Android shows Check in for any upcoming session (the server refuses more than 30 minutes ahead). The iPhone limits it to the window.
- The completion dialog shows the Hours field and signature pad for a session with no track. The server ignores the hours and refuses a signature. Hide them when `track_id` is null, as the iPhone does.
- The iPhone MoveSessionSheet's error banner is a plain Form, not ErrorFocusForm, and has no field tie (ScheduleView.swift:1328-1330).
- The bulk "record several as no-show or cancelled" is website-only.

#### 19. Check-in exists only in the apps (Low)
`check_in_session` (inc/api_parity.php:1099-1140) has no website form; schedule.php only shows "Arrived". Fine for a phone feature. Add a website Check in button if front desk staff check clients in at a desk.

#### 20. Calendar sync settings (4.33.0) are website-only (Low, reasonable)
account.php:37-120 connects Microsoft, CalDAV or Google, turns sync on or off and tests it. This is one-time setup needing app passwords or JSON keys, so it is reasonable on the website. The apps' Account shows only the calendar feed address (AccountView.swift:351-397, MoreScreen.kt:288). **Optional:** show "Calendar sync: on (iCloud: Work)" read-only in the apps, so a staff member knows their busy times are checked.

#### 21. Voice (Low)
- The iPhone and Android both use `voice_command`, so behaviour is shared.
- Voice booking:
  - has no duplicate check (finding 5)
  - is in-person only (no remote or link)
  - audits `session.add`
  - audits the completion against 'client' rather than 'session'
- Otherwise it follows the website rules: instructor's hours, group wording, no double logging, note pending.

#### Website-only (reasonable)
- attendance.php (a report with CSV)
- self-booking rules
- curriculum and skill management (templates)
- calendar account connection
- bulk outcome and bulk request close (convenience)

None of these is needed day to day in the field. Exceptions: offering times (14) and group attendance (9), which a staff member handling a request or running a workshop would need.

### Counts
- **High:** 4 (findings 1, 2, 3, 4)
- **Medium:** 10 (5, 6, 8, 9, 10, 11, 12, 13, 14, plus 15/16 borderline)
- **Low:** the rest (7, 17, 18, 19, 20, 21)

---

## Area 3: the daily workspace and the person's own account (parity audit)

Read-only comparison of the website, the app API, the iPhone app and the Android app. Nothing in the repository was changed.

The area is reported in four parts, each with its own summary table and numbered findings:
- Part A (A1 to A21): dashboard, tasks, alerts and alert preferences, messages, device follow-ups. High 1, Medium 9, Low 11.
- Part B (B1 to B13): incidents, privacy incidents, compliance, agreements, training, credentials. High 2, Medium 4, Low 7.
- Part C (C1 to C11, plus C2b): My Account, sign-in flows, session timeout, sign-out. High 0, Medium 7, Low 5.
- Part D (D1 to D10): help, knowledge base, ask, translate, AI assistant and tools, voice. High 2, Medium 5, Low 3.

Totals: High 5, Medium 25, Low 26.

### Most important gaps
1. A1 (High): the API's save_followup (public/api.php:4394-4397) can give the "abandoned device" task to a deactivated account; followups.php:70-74 falls back to the person recording it. Both apps use the API.
2. B1 (High): incident types in the apps and API are a fixed list, not the agency's own list the website uses.
3. B3 (High): iPhone Compliance leaves out guardian consents that ended at the client's 18th birthday; Android has no consents section.
4. D1 (High): ai_ask leaves out last-note dates, so "no note in the last month" questions fail from the apps; no notice of truncation (over 80 clients).
5. D2 (High): Android Translate sends language codes ("so", "ne", "tl", "ht") instead of names, so the AI can answer in the wrong language.
6. C1/C2/C2b (Medium): iPhone wrongly says emailed codes still work once an authenticator is on; neither app can sign out or wipe one lost phone; Android has no app lock.
7. A2/A3, A13, C3 (Medium): app dashboards lack Today, My tasks and Your week (my_week exists but is never called); no app can change how alerts reach you or the mobile number (no API action).


---

## Part A: dashboard, tasks, alerts, messages, device follow-ups

Read-only comparison of the website (public/index.php, tasks.php, notifications.php, messages.php, followups.php), the app API (public/api.php, public/inc/api_parity.php), the iPhone app (ios/Sources) and the Android app (android/app/src/main/java/org/casemanagement/app, written `A/` below).

### Summary table

| Feature | Website | iPhone | Android | Same behaviour? | Severity |
|---|---|---|---|---|---|
| Dashboard: counts (active, pending, new referrals, unbilled) | index.php:225-251 | Present | Present | Android shows client counts without the clients permission (A9) | Low |
| Dashboard: "Today" panel (tasks overdue/today, today's sessions, unread messages, follow-ups due, new portal requests) | index.php:177-195 | Missing | Missing | No (A2) | Medium |
| Dashboard: My tasks panel (first 6, total, overdue) | index.php:253-268 | Missing (Tasks link only) | Missing (Tasks tab) | No (A2) | Medium |
| Dashboard: Your week (hours logged against the weekly target) | index.php:222 (`my_week` action exists) | Missing | Missing | No (A3) | Medium |
| Dashboard: Notes to write | index.php:206-217 | Present | Present | Yes | — |
| Dashboard: access hold, agreements, training, credentials banners | index.php:136-163 | Access hold only | Access hold, agreements, training | No (A8) | Low |
| Dashboard: authorizations expiring | index.php:270-297 | Present (10 rows plus total) | Present (rows cannot be opened) | Mostly | Low |
| Dashboard: recent referrals and latest notes | index.php:299-336 | Present | Present | Yes | — |
| Dashboard: What's new / Got it | index.php:165-173 | Missing | Missing | Website-only (reasonable): the apps have their own update notice | — |
| Tasks: list open / all | tasks.php | Present | Present | Yes | — |
| Tasks: search titles and details | tasks.php:200 | Present | Missing | No (A4) | Medium |
| Tasks: due-date filter (overdue/today/7 days/none) | tasks.php:203-207 | Partial (grouped by due) | Missing | No (A4) | Low |
| Tasks: add (title*, due, client, assign to, details) | tasks.php:16-70 | Present | Partial: title, due and details only | No (A5) | Medium |
| Tasks: complete / reopen | tasks.php:89-97 | Present (also offline) | Present (also offline) | Yes (same audit through set_task_done) | — |
| Tasks: edit an open task | none (app-only) | Present | Missing | Apps differ (A5) | Low |
| Tasks: delete (admin) | tasks.php:98-112 | Missing | Missing | No (A6) | Low |
| Tasks: complete several at once | tasks.php:83-88, 281-291 | Missing | Missing | No (A6) | Low |
| Tasks: recurring | not on the website | — | — | N/A: no recurring tasks exist anywhere | — |
| Tasks: open the linked client / referral | tasks.php:242-244 | Present (both) | Client only | Partial (A5) | Low |
| Alerts: list (unread first, 100) | notifications.php | Present | Present | Yes | — |
| Alerts: open the alert's link | notifications.php:84 | Present (app screen or in-app web) | Missing (link dropped when the list is read) | No (A11) | Medium |
| Alerts: mark the listed ones read | notifications.php:9-30 | Present (listed ids, plus one at a time) | Marks **every** unread alert | No (A10) | Medium |
| Alerts: undo mark-read (15 min) | notifications.php:32-50 | Missing | Missing | No (A12) | Low |
| Alert preferences (email now/daily/never; urgent text) | account.php:405-425 | Missing (no API action) | Missing (no API action) | No (A13) | Medium |
| Messages: conversation list | messages.php:200-268 | Present | Present | API list differs from the page (A16) | Low |
| Messages: open any client's conversation | messages.php:239-250 | Present | Present | Yes | — |
| Messages: thread + client/counselor channel | messages.php:91-180 | Present (opens on the channel with the unread) | Present (always opens on Client; counselor channel hidden without a counselor name) | No (A14, A15) | Medium |
| Messages: reply, text, attach file/document | messages.php:25-89 | Present | Present | Mostly (A17) | Low |
| Device follow-ups: due / upcoming / recent, stats | followups.php:105-140 | Present | Due and upcoming only, no stats, no recent list | No (A20) | Low |
| Device follow-ups: record (in use* Yes/No/Unknown, satisfaction, barriers, notes) | followups.php:30-86 | Present | No "Unknown" choice | No (A18) | Medium |
| Device follow-ups: abandoned task owner | followups.php:68-75 | API drift | API drift | No (A1) | High |
| Device follow-ups: snooze / skip | followups.php:89-108 | Present (skip asks first) | Present (skip does not ask first) | No (A19) | Low |

### Findings

#### A1. Abandoned-device task can go to a deactivated account when recorded from an app
Severity: **High** (the follow-up task is created but nobody sees it)
- Website: followups.php:68-75. Since 4.27.0, when the client's instructor is deactivated, the task goes to the person who recorded the follow-up.
- API: api.php:4397 still uses `(int)$fu['assigned_to'] ?: $userId`, with no `is_active` check. Both apps use this action (iOS FollowupsView.swift:370; Android A/ui/EquipmentScreen.kt:476).
- Fix: in `save_followup`, copy the website's owner check: look up `users.is_active` for `assigned_to`, and if the account is inactive, use `$userId`.

#### A2. Dashboard has no "Today" panel and no My tasks panel in either app
Severity: **Medium**
- Website: index.php:177-195 builds Today: tasks overdue and due today (each a link to the filtered Tasks list), today's sessions still waiting for an outcome, unread client/counselor messages, device follow-ups due, and new client portal requests. index.php:253-268 lists the first 6 open tasks with the total and the overdue count, and an administrator also sees the team's tasks.
- API: `dashboard` (api.php:847-930) returns none of these.
- iPhone: DashboardView.swift:38-170 shows the counts, notes to write, expiring authorizations, referrals and notes. Today and tasks appear only as links to other screens.
- Android: A/ui/DashboardScreen.kt:28-75 is the same, plus an unread-alerts row.
- Fix: add `today` (the same five counts, with the same SQL as index.php) and `tasks` (`my_tasks`, `task_count`, `overdue_tasks`) to the `dashboard` action. Show them at the top of both dashboards, each row opening the matching screen (Tasks with its due filter, Schedule, Messages, Device follow-ups, Client portal requests).

#### A3. "Your week" is missing from both apps, though the API has it
Severity: **Medium**
- Website: index.php:222 `staff_week_text(staff_week(...))`.
- API: `my_week` exists (listed in api.php's switch and open to every signed-in user), but neither app calls it. A search for `my_week`/`myWeek` in ios/ and android/ finds nothing.
- Fix: call `my_week` from both dashboards and show its text line under Notes to write.

#### A4. Android Tasks has no search; neither app has the due-date filter
Severity: **Medium** (search, Android); Low (filter)
- Website: tasks.php:196-210 offers a search over titles and details, and a due filter (Any, Overdue, Due today, Next 7 days, No due date). The dashboard's Today links depend on that filter.
- iPhone: TasksView.swift:156 has search. Open tasks are grouped Overdue/Today/Upcoming/No due date, which covers most of the filter, but "Next 7 days" and "No due date" with done tasks are missing.
- Android: A/ui/TasksScreen.kt:61 has only the Open/All choice. With 300 rows there is no way to find a task.
- Fix: Android: add a search field that sends `q`, and a Due choice that sends `due`. The API's `tasks` action already accepts both (api_parity.php `task_filters($_GET)`). iPhone: add the due choice as a menu, which also lets an A2 "Today" row open on a filter.

#### A5. Android's Add task lacks client and assignee, and Android cannot edit or open a referral
Severity: **Medium**
- Website: tasks.php:150-183 has "What needs doing?" (required), Due date, Client (optional, with the clients permission), Assign to (admin only, preselects the signed-in person, "Anyone" = unassigned) and Details.
- iPhone: TasksView.swift:300-330 matches, including the admin preselect (`assignee = api.userId`, line ~358) and the client picker. It also adds Edit, an app-only feature that `save_task` audits as `task.edit`.
- Android: A/ui/TasksScreen.kt:110-145 has "Task", "Due date" and "Details" only, so an administrator cannot assign a task and nobody can link a client. The task dialog (lines 74-101) offers Done/Reopen and Open client, but no Open referral, no Edit and no "automatic" marker.
- Wording: the web error is "The task needs a title: say what needs doing."; iPhone says "What needs doing: the task needs a title." (TasksView.swift:398); Android says "Name the task." (TasksScreen.kt:133) and labels the field "Task".
- Fix: Android: add a client picker (shown when `can_link_client`) and Assign to (when `can_assign`, preselect self, list `staff`), an Edit action for open non-automatic tasks, and Open referral when `can_open_referral`. Use the website's label "What needs doing?" and its error text on both apps.

#### A6. Admin delete and "Complete several tasks at once" are missing from both apps
Severity: **Low**
- Website: tasks.php:83-88 (bulk_done, up to 200), 98-112 (delete_task, admin, confirmed, audited `task.delete`).
- API: there is no delete or bulk action in api_parity.php.
- Fix: optional. Add a `delete_task` parity action (admin only, audited the same way) with a confirmation in the apps. Bulk complete is reasonably website-only.

#### A7. Android shows done tasks as "overdue"
Severity: **Low** (wrong wording)
- Android: A/ui/TasksScreen.kt:67 calls `dueWords(t.daysLeft)` for every task. Under "All tasks", a done task that was due last month reads "Done, overdue by 30 days".
- Website (tasks.php:249) and iPhone (TasksView.swift `dueText`) show a done task's due date, not overdue.
- Fix: for status Done, show "due <date>" or "no due date".

#### A8. Agreements, training and credentials reminders differ between the apps
Severity: **Low**
- Website: index.php:136-163 shows banners for the access hold, credentials to renew, training to take and agreements to sign.
- iPhone: DashboardView.swift:46-51 shows only the access hold.
- Android: DashboardScreen.kt:30-34 shows the access hold, agreements to sign and training to take, but not credentials.
- Fix: have `dashboard` return `agreements_needed`, `training_needed` and `credentials_due` (using staff_agreements_needed, training_needed, credentials_due_for), and show all four on both apps. This also saves Android two extra calls.

#### A9. Android shows client counts to accounts without the clients permission
Severity: **Low**
- The website hides the tiles (index.php:226). iPhone checks `api.can("clients")` (DashboardView.swift:59).
- Android: DashboardScreen.kt:47-48 always shows "Active clients: 0" and "Waiting for assessment: 0", which reads as a fact. The expiring authorizations (line 60) also cannot be opened, while the web links to the client and the track.
- Fix: wrap both rows in `if (api.can("clients"))`. Make expiring rows open `client/{id}`, which needs `client_id` added to the API's expiring rows.

#### A10. Android "Mark all as read" marks alerts the person never saw
Severity: **Medium**
- Website: notifications.php:9-30 marks only the ids that were on the page, deliberately, so that alerts beyond the 100 shown, and ones that arrived since, stay unread.
- iPhone: NotificationsView.swift:88 sends the listed ids, and can mark one alert at a time.
- Android: A/ui/TasksScreen.kt:161 sends `all: true` from a "Mark all N as read" button, where N is the server's total, not the listed count.
- Fix: send `ids` = the unread ids shown. Label the button "Mark these N as read" when unread is greater than the listed unread, and show the web's "Showing the newest N of M unread alerts" line.

#### A11. Android alerts cannot be opened
Severity: **Medium**
- Website: notifications.php:84 makes each alert a link to its page.
- iPhone: NotificationsView.swift:27-60 maps the link to a screen or opens the in-app web page, and marks the alert read.
- Android: Parsing.kt:299-302 drops `link`, and AlertsScreen (TasksScreen.kt:148-170) shows plain rows with no action.
- Fix: parse `link` and map client.php, referrals.php, tasks.php, portal_requests.php, incidents.php and messages.php to the existing routes, falling back to `openSitePage`. Mark the alert read with `ids` when it is opened.

#### A12. No Undo for "mark as read" in either app
Severity: **Low**
- Website: notifications.php:32-50 (15 minutes).
- Fix: optional. A `mark_notifications_unread` action taking the same ids would let the apps offer Undo in the confirmation.

#### A13. Alert preferences cannot be changed from the phone
Severity: **Medium** (a field worker who gets too many emails, or wants urgent texts, must use the website)
- Website: account.php:405-425 has Mobile number, "Text me when an urgent alert comes in", and "Email me about alerts" (Urgent ones at once, and daily / Once a day / Never).
- API: API_ACCOUNT_ACTIONS (api.php:626) has no action to read or save `mobile`, `alert_sms` or `alert_email`. The `account` action (api_account.php:76-89) does not return them.
- Both apps: not present (a search for alert_email/alert_sms in ios and android finds nothing).
- Fix: return the three values from `account`, add a `save_alert_prefs` action that repeats account.php's validation, and add "How alerts reach you" to both Account screens. Part C of this audit covers the Account screen.

#### A14. Android always opens a conversation on the Client channel
Severity: **Medium** (counselor messages stay unread and unseen)
- The API sends `unread_client` and `unread_counselor` because opening a thread marks only the shown channel read (api.php:4404-4420).
- iPhone: MessagesView.swift:20 opens on the counselor channel when every unread message is there, and reads the counts per channel.
- Android: Parsing.kt:164-166 drops both counts. MessagesScreen (TasksScreen.kt:208) navigates to `thread/{id}`, and ThreadScreen (line 224) starts on "client". A row says "2 unread", but opening it shows nothing new and the counselor's messages stay unread.
- Fix: parse the per-channel counts, pass the starting channel in the route, and say "N unread from the counselor" on the row.

#### A15. Android hides the Counselor channel when the client has no counselor name
Severity: **Low**
- Website: messages.php:113-117 always offers Client and Counselor.
- iPhone: always offers both, and says "No counselor on file for this client." when empty.
- Android: TasksScreen.kt:249 adds the counselor choice only when `counselor_name` is set. A client with a counselor email but no name, which happens with public referrals, cannot be messaged on that channel.
- Fix: always offer "The counselor", named when known.

#### A16. The API's conversation list is not the website's list
Severity: **Low**
- Website: messages.php:200-212 has one row per client and channel, unread first then newest, a last-message preview ("You: …" or a colleague's name), "Showing N of M", and the total unread.
- API: api.php:4404-4414 has one row per client (channels merged), ordered by last_at only, with no preview and no total.
- Fix: add `last_body` and `last_sender_label` to `message_threads` and order by unread first. Apps can then show the preview and keep unread conversations at the top, as the website does.

#### A17. Message send: smaller wording gaps
Severity: **Low**
- Website (messages.php:78-89) adds "They were emailed that a message is waiting." when `message_notice()` sent the email. The API calls `message_notice` (api.php:4498) but discards the result, so the apps never say it.
- Website (messages.php:143-146) warns before typing that the counselor will not see the reply while the counselor email is unconfirmed. `message_thread` does not return `counselor_verified`, so neither app can warn before sending; both only show the warning after sending.
- Fix: return `noticed` from `post_message` and `counselor_unconfirmed` from `message_thread`. Show the warning above the reply field in both apps.

#### A18. Android cannot record "Unknown" for a device follow-up; the API no longer requires an answer
Severity: **Medium**
- Website: followups.php:31-35 requires Still in use? Yes / No / Unknown (4.27.0: a missing answer is refused, never saved as unknown).
- iPhone: FollowupsView.swift:298 offers Yes/No/Unknown and requires a choice.
- Android: A/ui/EquipmentScreen.kt:493 offers only "Not answered" / Yes / No. A worker who could not reach the client must guess, which skews the retention figure, or skip the check.
- API: api.php:4353 `api_choice(..., ['yes','no'], ..., null)` treats a missing or empty `in_use` as Unknown, so it no longer enforces the website's rule. Only the apps' own checks stop a blank answer.
- Fix: Android: add "Unknown" (sending "unknown" or ""). API: accept `yes|no|unknown`, and refuse a missing value with the website's text "Still in use: choose Yes, No or Unknown." (iPhone sends "" for Unknown today, so accept "" as Unknown during the transition.)

#### A19. Android skips a follow-up without asking first
Severity: **Low**
- Website: followups.php:195 confirms "Skip the N-day follow-up for …?". iPhone confirms (FollowupsView.swift:84-87).
- Android: EquipmentScreen.kt:501 skips at once, and a skip cannot be undone.
- Fix: add a confirmation dialog.

#### A20. Android follow-ups lack the stats and the recent list; neither app says what "No" created
Severity: **Low**
- Website: followups.php:149-152 shows the tiles (Due now, Upcoming, Still in use %, Flagged abandoned) and a Recent list (lines 221-240).
- iPhone has the stats and Recent. Android (EquipmentScreen.kt:442) shows only Due and Coming up.
- After a "No", the website says "Recorded. Because the device is no longer in use, a case note and a follow-up task were created." Both apps say only "Follow-up recorded."
- Fix: show `retention`/`abandoned`/`completed` and `recent` on Android. In both apps, when the reply's `abandoned` is true and there is no warning, use the website's sentence.

#### A21. Alerts API wording differs from the page
Severity: **Low**
- mark_notifications_read (api_parity.php) says "N unread alerts remain." The page (notifications.php:27) says "N more unread alerts are listed now."
- Fix: use the page's sentence.

---

## Part B: incidents, privacy incidents, compliance, agreements, training, credentials

Paths are relative to the repo root.

### Summary table

| Feature | Website | iPhone | Android | Same behaviour? | Severity |
|---|---|---|---|---|---|
| Incident log: list, show closed | incidents.php | Present (IncidentsView) | Present (ReportsScreens IncidentsScreen) | Mostly. Neither app shows "reported by" | Low |
| Report an incident: type list | Lists and time limits (`cms_list_items('incident_types')`) | Hard-coded list from API | Hard-coded list from API (plus its own fallback) | **No**: the API ignores the agency's list | High |
| Report an incident: client | Optional client picker | Present | **Missing** | No | Medium |
| Report an incident: date rule | Today or earlier | Picker up to today | Free text. The API accepts tomorrow | Slightly different | Low |
| Incident status change | Reporter or admin | Present (asks before Closed) | Present | Yes | — |
| Privacy incidents: report one | privacy_incidents.php (any staff) | **Missing** | **Missing** | — | Medium |
| Privacy incidents: assess, notices, close | Admin | Missing | Missing | Website-only (reasonable): admin casework | — |
| Compliance: authorizations, reports due, equipment service | compliance.php | Present | Present (no funds %) | Mostly | Low |
| Compliance: consents to renew | Present (includes "Adult": a guardian consent signed before the 18th birthday) | Present via consents_due, **without "Adult"** | **Missing** | No | High |
| Compliance: releases, no release, minors with no consent, no privacy notice, device follow-ups due, overdue loans, contracts at risk | Present | Missing (the API does not send them) | Missing | No | Medium |
| My agreements: list, read, sign, signed copy | agreements.php | Present | Present | Mostly (see B7, B8) | Low |
| Hold on client records until agreements and training are done (4.16.0) | Checked on every request | Banner. **Stays after signing until the app restarts** | Same | No | Medium |
| Staff agreements administration | staff_agreements.php | — | — | Website-only (reasonable): an admin writes agreements | — |
| My training: list, take quiz, retake | training.php | Present | Present | Count wording differs | Low |
| Staff training administration and hold setting | staff_training.php | — | — | Website-only (reasonable): admin | — |
| My credentials: add, edit, remove | credentials.php | Present, no Notes, no idem key | Present, no Notes | Mostly | Low |
| Everyone's credentials (admin) | credentials.php?all=1 | — | — | Website-only (reasonable) | — |

### Findings

#### B1. Incident types: the apps and the API ignore the agency's own list (High)
- Website: types come from Lists and time limits. `public/incidents.php:46` and `:181` use `cms_list_items('incident_types')`.
- API: `incidents` sends a hard-coded `types` list (`public/api.php:4543`). `add_incident` validates against the same hard-coded list (`public/api.php:4547`, `:4556`).
- iPhone: `ios/Sources/IncidentsView.swift:146` uses `data?.types` (the hard-coded list).
- Android: `android/app/src/main/java/org/casemanagement/app/ui/ReportsScreens.kt:253` uses `types`, with its own copy of the same list as a fallback.
- Result: when an administrator renames, adds or removes incident types, the apps still offer the old list. The API saves types the website no longer allows, and it refuses new ones. The same agency then has incidents filed under different categories depending on where they were reported.
- Fix: in `incidents` and `add_incident`, use `cms_list_items('incident_types')`, as `form_options` already does (`public/inc/api_more.php:20`). Drop Android's built-in fallback list.

#### B2. Android cannot tie an incident to a client (Medium)
- Website: the client picker is optional (`public/incidents.php:183-186`). The API takes `client_id` and checks the caseload (`public/api.php:4549-4550`).
- iPhone: `NewRecordClientPicker` (`ios/Sources/IncidentsView.swift:265`).
- Android: `IncidentDialog` has no client field and sends no `client_id` (`ReportsScreens.kt:270-291`).
- Result: an incident reported on Android is never linked to the client. A caseload worker whose incidents are found through the client, or who is not the reporter, never sees it (`public/api.php:4531-4533`).
- Fix: add the shared client picker (optional, "None" first) to `IncidentDialog` and send `client_id`.

#### B3. Compliance "consents to renew" leaves out guardian consents that ended at the 18th birthday (High)
- Website: since 4.29.0, a guardian consent signed before the client turned 18 is listed as kind "Adult" and sorted with the consents no longer in force (`public/compliance.php:163-178`).
- API: `consents_due` says it is the "same query, rules and order" but has no Adult rule (`public/inc/api_parity.php:883-921`).
- iPhone: `ComplianceView` shows `consents_due` (`ios/Sources/ComplianceView.swift:94-123`). For these clients it says "No consents need attention." Its kind→word map has no "Adult" either.
- Android: no consents section at all (`ReportsScreens.kt:84-102`).
- Fix: copy the `$adultSince` / Adult branch, and the `$noConsent` order that includes Adult, into `consents_due` (select `c.dob`). Add an "Adult" entry (for example "turned 18") on iOS. Add the consents section to Android's ComplianceScreen.

#### B4. The compliance API sends only 3 of the website's sections (Medium)
- Website: `public/compliance.php` also lists device follow-ups due (`:86-95`), overdue loans (`:100`), contracts at risk (`:114`), releases expiring, expired or withdrawn and clients with no release (`:185-219`), minors or Pre-ETS clients with no consent ever requested (`:223-229`), and open clients with no privacy notice. It also has 8 summary counts.
- API: `compliance` returns only `auth_risk`, `reports_due` and `equipment_due` (`public/api.php:3949`).
- iPhone and Android: they show only those three (plus consents on iOS).
- Result: a field worker's compliance screen says "All caught up" while the website lists missing releases and consents for the same caseload.
- Fix: return the remaining sections from `compliance`, with the same scoping and the same can('equipment') / can('billing') gates. Render them on both apps. At least do releases, no release, minors with no consent, and device follow-ups, which are caseload work.

#### B5. Android compliance hides funds used. Neither app opens the item (Low)
- Android `ReportsScreens.kt:91` shows only "% of hours used". The API sends `funds_pct` and `funds_remaining`, which iOS shows (`ComplianceView.swift:73-76`). A track can run out of money before its hours run out.
- Neither app links an authorization or report-due row to the track. The website links each row to the client and track.
- Fix: show the funds line on Android. Make the rows open the track or client on both apps (`nav` is already passed to Android's ComplianceScreen but is unused).

#### B6. Privacy incidents cannot be reported from either app (Medium)
- Website: any staff member can report one (kind, discovered date, occurred date, what happened, information involved, optional client). The administrators are notified and the 60-day notice deadline is set (`public/privacy_incidents.php:20-63`).
- API: no action exists. iPhone and Android: nothing (grep finds nothing).
- Result: the most likely privacy incident, a lost phone or a misdirected message, happens in the field, and the deadline runs from discovery.
- Fix: add a `report_privacy_incident` API action that repeats lines 20-63 (validation, transaction, audit `privacy_incident.add`, `notify_admin_users`, idem key). Add a "Report a privacy incident" form on both apps (from Incidents, and from the lost-phone flow). Assessment, notices and closing stay website-only.

#### B7. After the last agreement is signed, the apps keep holding back client records (Medium)
- Website: the hold is worked out again on each request (`public/api.php:564-567`, `public/index.php:138-142`), so client records open at once.
- iPhone: after signing, `AgreementDetailView.sign` only reloads the list (`ios/Sources/AgreementsView.swift:124-130`). It does not call `api.refreshMe()`, so `api.accessHold` and the held-back permissions stay. The Clients areas stay hidden and the banner stays until the app is restarted or unlocked. Passing training does refresh (`TrainingView.swift:116`).
- Android: same. The `sign_agreement` success path does not call `api.refreshMe()` (`AgreementsScreen.kt:61-64`). Training does (`TrainingScreens.kt:100`).
- Fix: call `await api.refreshMe()` (iOS) or `api.refreshMe()` (Android) after a successful `sign_agreement`.

#### B8. The hold banner links to the wrong place, or to nothing (Low)
- Website: the banner links to both My agreements and My training (`public/index.php:141`).
- iPhone: the banner opens only AgreementsView, even when the hold is for training (`ios/Sources/DashboardView.swift:46-50`).
- Android: the banner is plain text (`DashboardScreen.kt:32`). Separate "to sign" and "to take" rows follow it.
- Fix (iPhone): open My training when only training is held, or show two links. Android is acceptable.

#### B9. Signed copies are partly unreachable, and the corrections note is missing (Low)
- Website: the list links "Signed copy" whenever a signature exists, including the "due" (renew soon) state (`public/agreements.php:99`). A copy also says whether small corrections were made after signing (`:43-45`).
- iPhone and Android: the copy is reachable only from the detail page when the state is exactly "signed" (`AgreementsView.swift:74-77`, `AgreementsScreen.kt:48-49`). An agreement in the "due" state has a copy but no way to reach it. Neither app's copy shows the corrections note, and `agreement_copy` does not send it (`public/inc/api_more.php:51-57`).
- Fix: show "Read your signed copy" whenever `signature_id` is set. Add the `staff_agreement_corrections_after` text to `agreement_copy` and display it.

#### B10. The server's name-mismatch error is not tied to the name field (Low)
- The server answers "The name typed does not match the name on your account, X." (`public/inc/staff_agreements.php:107`). The website puts it on `signed_name` (`agreements.php:23`).
- iPhone: the error is shown in the banner. It is not matched to the "Name" field, because it does not start with "Name:" (`AgreementsView.swift:133`).
- Android: it goes only to `ErrorText`, and the `Field` has no `error` (`AgreementsScreen.kt:53-54`).
- Fix: map a 422 from `sign_agreement` (not a 409) to the name field on both apps.

#### B11. The training count differs from the website (Low)
- Website: "N of M to take" counts every module not "done", which includes "due" (renew within 30 days) (`public/training.php:71,78`).
- Both apps count only `state == "needed"` (`TrainingView.swift:15`, `TrainingScreens.kt:43`). With only renewals due, they say "Every training is passed and current." The website says "1 of 2 to take."
- Fix: count `state != "done"` in both apps, as the website does.

#### B12. Credentials: no Notes field, silent truncation, and no idem key on iOS (Low)
- Website: has an optional Notes field (`public/credentials.php:92-93`). It refuses an over-long number, issuer or notes with a message on the field (`:50`), and puts date errors on their fields (`:46-49`).
- API: silently cuts number, issuer and notes to length (`public/inc/api_more.php:95`). It returns one combined date message (`:93`).
- iPhone: no Notes field. Dates are free text with no format check. It sends no `idem_key` for a new credential, although `save_credential` is in `API_IDEM_ACTIONS`, so a retry after a lost reply adds it twice (`ios/Sources/TrainingView.swift:231`).
- Android: no Notes field. It checks the date format and sends an idem key for a new credential (`TrainingScreens.kt:157-167`).
- Fix: add Notes on both apps. Make the API refuse over-long values with field-named messages, like the website. Send an `IdemKey` from iOS for a new credential. On iOS, use date pickers or a format check.

#### B13. Incident date allows tomorrow through the API, and the reporter is not shown (Low)
- Website: refuses a future date (`public/incidents.php:44`). The API allows up to tomorrow (`public/api.php:4552-4554`). Android takes free text (`ReportsScreens.kt:272`), so tomorrow is saved from Android.
- The website shows "reported by" on each row (`incidents.php:150`). The API sends `reporter`, but neither app shows it (`IncidentsView.swift:45`, `ReportsScreens.kt:223`).
- Fix: use a date picker capped at today on Android. Show "reported by" on both apps.

### Website-only (reasonable)
- staff_agreements.php and staff_training.php: an administrator writes agreements, quizzes and the hold setting. This is not field work.
- privacy_incidents.php assessment, people, notices and close: administrator casework. Only reporting is needed in the field (B6).
- credentials.php?all=1 and ?user=: an administrator keeps everyone's credentials.

### Counts
High 2 (B1, B3). Medium 4 (B2, B4, B6, B7). Low 7 (B5, B8–B13).

---

## Part C: My Account, sign-in, session timeout and sign-out

Scope: public/account.php, login.php, verify.php, forgot.php, reset.php, sso.php, passkey.php, logout.php; API sign-in (public/api.php lines 325-523) and public/inc/api_account.php; iPhone ios/Sources/AccountView.swift, LoginView.swift, API.swift, CaseManagementApp.swift; Android ui/LoginScreen.kt, ui/MoreScreen.kt (AccountScreen, AccountSecurity), Api.kt, SecureStore.kt.

Shared logic: the app's password change, authenticator on/off, calendar-feed address and passkey removal all go through public/inc/account_actions.php, the same functions account.php uses. So the database writes, audit entries and emails are the website's. The API sign-in still matches login.php/verify.php line for line on rate limits (8/20/30, 5 codes in 15 minutes), the authenticator-only rule (4.27.0) and the unreadable-seed refusal. The differences are in what the API does not offer and in what the apps say.

### Summary table

| Feature | Website | iPhone | Android | Same behaviour? | Severity |
|---|---|---|---|---|---|
| Sign in: email + password | login.php | Present | Present | Yes (API matches limits and messages; small wording differences) | Low |
| Second step: emailed or authenticator code | verify.php | Present | Present | Mostly. The API gives no "tries left" and counts a code that is not 6 digits as a try (C9) | Low |
| Send a new code | verify.php resend | Present (re-runs login) | Present (re-runs login) | Yes; the server's 5-codes cap applies | — |
| Forgot / reset password | forgot.php, reset.php | Link to website | **Missing** (no link) | Android only: no way to it from the app (C5) | Medium |
| Sign in with a passkey | login.php + passkey.php | Missing | Missing | Website-only (reasonable): WebAuthn is tied to the website's domain | Low |
| Sign in with Microsoft (SSO) | sso.php | Missing | Missing | An SSO-only staff member cannot sign in to either app (C4) | Medium |
| Profile (name, email, role, group) | account.php header | Present | Present (no role shown) | Yes | Low |
| Change password | account.php | Present (sheet) | Present | Yes (shared action); Android does not tie server field errors to their fields | Low |
| Authenticator app: set up / confirm / turn off | account.php | Present, **wrong wording** | Present | iPhone says email codes keep working (C1); Android has the field order reversed and gets stuck when the setup has ended (C8) | Medium |
| Passkeys: list / remove | account.php | Present | **Missing** (points to website) | Differs between apps (C7) | Low |
| Passkeys: add | account.php (WebAuthn) | Missing | Missing | Website-only (reasonable) | — |
| Calendar subscription address (show / create / replace) | account.php | Present (password to show; confirms before replacing) | Present (password to show; **no confirmation before replacing**) | Android differs (C6) | Medium |
| Mobile number + urgent alerts by text | account.php save_mobile | Missing | Missing | No API action (C3) | Medium |
| How alerts reach you (email now/daily/never) | account.php save_alert_email | Missing | Missing | No API action (C3) | Medium |
| Low-vision controls (show/hide), text size, colours | account.php + layout | Not needed | Not needed | Website-only (reasonable): the phones use their own text size and contrast settings | — |
| Language | none on My Account | — | — | Nothing to compare (the website has no per-staff language setting) | — |
| Online meetings: connect/disconnect Zoom or Teams | account.php + meeting_connect.php | Missing | Missing | Website-only (reasonable: OAuth in a browser); the apps give no status or link (C10) | Low |
| Calendar sync (Microsoft/CalDAV/Google, test, on/off, disconnect) | account.php | Missing | Missing | Website-only (reasonable as a one-time setup); no status or link in the apps (C10) | Low |
| Phones signed in: list, sign one out, report lost and wipe | account.php | Count only + sign out everywhere | Count only + sign out everywhere | Partial: no per-phone sign-out or "report lost" (C2) | Medium |
| Sign out everywhere (app sessions) | — (per-phone on the website) | Present (logout_all) | Present (logout_all) | App-only extra; same API | — |
| Link Microsoft sign-in | account.php | Missing | Missing | Website-only (reasonable) | — |
| Sign out | logout.php | Present (confirms; API logout) | Present (confirms; API logout) | Yes | — |
| Remote wipe when reported lost | server | Present (wipe + wipe_done) | Present (wipe + wipe_done) | Yes | — |
| Inactivity timeout / lock | session_timeout_minutes + warning dialog | App lock (Face ID/passcode) on open, after 2 min away | **No app lock** | Android has no inactivity protection (C2b) | Medium |

### Findings

#### C1. iPhone says email codes keep working after an authenticator app is turned on: they do not
Severity: Medium (a staff member is told the opposite of what happens and can be locked out after losing the app).
- Website: account.php:419 says "codes are no longer emailed". The server sends no code once the authenticator is on (login.php totp_seed_present; api.php:386-397 totpOnly). account_actions.php:41 says "signing in asks for a code from the app (or a passkey) instead of an emailed code".
- iPhone: ios/Sources/AccountView.swift:244 "At sign-in you can enter a code from your authenticator app or the emailed code. Both work." and :280 "Add an authenticator app as a second way to get sign-in codes. Email codes keep working either way."
- Android: ui/MoreScreen.kt:265-284 makes no such claim (correct by omission).
- Fix: change :244 to "At sign-in, enter the code your authenticator app shows. No code is emailed while it is on. If you lose the app, an administrator can reset it." Change :280 to "Add an authenticator app for your sign-in codes. Once it is on, sign-in codes are no longer emailed." (the website's wording at account.php:451).

#### C2. No per-phone sign-out or "Report lost and wipe" in either app
Severity: Medium (if a phone is lost in the field, the other device to hand is often the second phone).
- Website: account.php:178-185 (device_signout / device_lost via app_device_action) and :604 app_devices_html (records_privacy.php:559-569, which confirms before "Report lost and wipe").
- API: inc/api_account.php gives only `app_sessions` (a count) and `logout_all`. There is no list of devices and no lost/wipe action.
- iPhone: AccountView.swift:477-492 has a count and "Sign out everywhere" only. Android: ui/MoreScreen.kt:103-105 has a count, plus "Passkeys and lost phones are managed under My Account on the website."
- "Sign out everywhere" ends the lost phone's token but does **not** wipe it: offline drafts, queued entries and cached lists stay on the lost phone. Only device_lost sets wipe_requested_at.
- Fix: add API actions `app_devices` (returns app_devices($userId)) and `device_action` {device_id, lost:bool} calling app_device_action() with the website's messages. In both apps' Account screen, list the phones with "Sign out" and "Report lost and wipe", each confirmed with the website's text.

#### C2b. Android has no app lock, unlike iPhone and the website's inactivity timeout
Severity: Medium.
- Website: inc/bootstrap.php:699-715 signs out after session_timeout_minutes of inactivity, with a warning (layout.php:243-253).
- iPhone: CaseManagementApp.swift:181-200 (AppLock) locks with Face ID, Touch ID or passcode on open and after 2 minutes away (described at AccountView.swift:411).
- Android: BiometricPrompt is used only when a client hands the phone back after signing (ui/SignaturesScreen.kt:422-432). MainActivity.kt sets FLAG_SECURE but has no lock on resume. A 30-day rolling token (api.php:559) means an unlocked phone opens straight into client records.
- Fix: in MainActivity, track when the app went to the background. On resume after 2 minutes (and on cold start), show a lock screen that asks for BiometricPrompt with DEVICE_CREDENTIAL. Say "App lock" in AccountScreen as the iPhone does.

#### C3. Mobile number, urgent alerts by text and alert email cannot be set from either app
Severity: Medium (how alerts reach a staff member in the field is a day-to-day setting, and it is simple).
- Website: account.php:9-24 (save_alert_email: now/daily/off; save_mobile with sms_number() validation, "Enter a mobile number such as 515-555-0100, or leave it empty.", alert_sms only when SMS is enabled, audit 'user.mobile').
- API: no action reads or writes users.mobile, alert_sms or alert_email (`grep alert_email public/api.php public/inc/api_*.php` finds nothing). Neither does the `account` reply.
- iPhone and Android: missing.
- Fix: add `mobile`, `alert_sms`, `sms_enabled` and `alert_email` to the `account` reply. Add `save_alert_settings` to API_ACCOUNT_ACTIONS, sharing the account.php code (move it to account_actions.php: same validation message, audit and flash texts). Add a "How alerts reach you" section to both apps, with a quiet picker and the mobile field's error tied to the field.

#### C4. A staff member who signs in only with Microsoft cannot use either app, and the apps do not say why
Severity: Medium.
- Website: login.php offers "Sign in with Microsoft" (sso.php). account.php:507 itself notes that an SSO-only user may have no password here.
- API: password + code only (api.php:325-523). Neither app offers SSO.
- iPhone: LoginView.swift:164 links "Forgot your password? Reset it on the website", which is the only way such a user gets a password. Android: no link at all (C5).
- Fix (short of SSO in the app): on both sign-in screens, add "Signed in with Microsoft on the website? Set a password with Forgot your password first." Longer term: an ASWebAuthenticationSession / Custom Tab SSO flow that ends with an API token.

#### C5. Android sign-in has no "Forgot your password?" or privacy link
Severity: Medium.
- Website: login.php "Forgot your password?" goes to forgot.php.
- iPhone: LoginView.swift:164-177 (forgot.php and privacy.php links).
- Android: ui/LoginScreen.kt:130-154 has neither. (The privacy policy is reachable only after sign-in, at MoreScreen.kt:79.)
- Fix: add to LoginScreen, under Continue, `SecondaryButton("Forgot your password? Reset it on the website") { openSitePage(api, context, "forgot.php") }` and a Privacy policy row, as on iPhone. For a build with no server, use the typed address.

#### C6. Android replaces the calendar address without asking first
Severity: Medium (it cannot be undone: every calendar subscribed to the old address stops updating).
- Website: account.php:519 `confirm('Make a new calendar address? The old one stops working everywhere it was added.')`.
- iPhone: AccountView.swift:189-193 confirmationDialog "Replace the calendar address?".
- Android: ui/MoreScreen.kt:289 "Make a new calendar address" calls cal_feed_regen at once. The button is also offered when no address exists, with the same name.
- Fix: when has_cal_feed is true, show Confirm("Replace the calendar address?", "The old address stops working everywhere it was added. Subscribe your calendar to the new one.", "Replace the address"). When there is none, name the button "Create my calendar address" (the website's wording).

#### C7. Android cannot see or remove passkeys; iPhone can
Severity: Low.
- Website: account.php:608-628 (list, with remove confirmed).
- API: `account` returns passkeys; remove_passkey exists.
- iPhone: AccountView.swift:318-347 (list, remove with confirmation).
- Android: ui/MoreScreen.kt:105 points to the website only.
- Fix: list `a.passkeys` in AccountScreen (name, added, last used) with "Remove", confirmed with the iPhone's text, calling remove_passkey {id}.

#### C8. Android authenticator setup: field order reversed, and it gets stuck when the setup has ended
Severity: Low.
- Website: account.php:439-450 puts the password first and the timed code last, "so the code is typed just before submitting, inside its 30-second window". It shows how long the key works.
- iPhone: AccountView.swift:264-270 has the password then the code, and says "This setup key works for N minutes". :564-567 clears the setup when the server says it had ended.
- Android: ui/MoreScreen.kt:280-283 has the code field first, then the password. `expires_minutes` is not shown. On the "The authenticator setup had ended…" error, `setup` stays set, so every retry fails until the screen is left.
- Fix: swap the two Fields. Add "This setup key works for {expires_minutes} minutes." In act's catch, set `setup = null` when the message starts with "The authenticator setup had ended".

#### C9. App code step: the API counts a code that is not 6 digits as a try and gives no "tries left"
Severity: Low.
- Website: verify.php refuses a code that is not 6 digits without using a try ("Enter the 6-digit code…"). A wrong code says "N tries left with this code" and, for an authenticator account, "Sign in again with your password to try more codes."
- API: api.php:455-511 takes a try for any code and answers only "That code is not correct or has expired. Sign in again if it keeps failing."
- iPhone: LoginView.swift:202 enables the button only at exactly 6 digits (safe). Android: LoginScreen.kt:158,161 accepts up to 8 digits and enables at 6 or more, so 7-8 digits spend one of the 5 tries.
- Fix: in API verify, `if (strlen($code) !== 6) { delete the guess row; fail('Enter the 6-digit code…', 422); }` before taking a try. Return `tries_left` in the 401 and say it as verify.php does. On Android, `.take(6)` and `code.length == 6`.

#### C10. Online meetings and calendar sync: no status in the apps
Severity: Low (website-only setup is reasonable).
- Website: account.php:531-601 (Zoom/Teams connect and disconnect; calendar connect, test, on/off, with sync errors shown: "Problem: …").
- API/apps: nothing. A calendar sync failure (calendar_error) shows only on the website, so a staff member working from the phone never learns their sessions stopped reaching their calendar.
- Fix: add `calendar_kind`, `calendar_sync`, `calendar_synced_at`, `calendar_error` and the connected meeting providers to the `account` reply. Show them read-only, with "Change on the website" opening account.php#calendar-h / #meetings-h.

#### C11. Small wording and display differences
Severity: Low.
- API login rate limit: "Too many attempts. Wait 15 minutes." (api.php:356). Website: "Too many sign-in attempts. Please wait 15 minutes and try again."
- Code-step buttons: website "Verify and sign in" / "Send a new code"; iPhone matches; Android "Sign in" and "Start again" (LoginScreen.kt:161,163) where iPhone says "Start over".
- Android AccountScreen (MoreScreen.kt:94-104) does not show the role. iPhone shows "Administrator/Staff, permission group X".
- Android change password: the server's field errors (current_password, new_password…) go only to the summary. Local checks use the keys current/new/again, so a wrong current password is not tied to its field (MoreScreen.kt:226,246-262). Map current_password→current, new_password→new, confirm_password→again and ack_passkeys→ack.
- Calendar address: the website shows the address on the page without a password. Both apps ask for the password to show it (cal_feed_show). This is stricter and intended. Note it only so it is not "fixed" by accident.

---

## Part D: help, knowledge base, AI tools and voice assistant

Scope: help.php, kb.php, kb_ask.php, ask.php, translate.php, ai.php, ai_speak.php, ai_tools.php (plus the hub's remediate.php, curriculum_ai.php and risk.php, and the voice assistant ai_command.php / voice_command, because the apps reach them through the same screens).
Read-only comparison of the website, the API (public/api.php) and both apps.

### Summary table

| Feature | Website | iPhone | Android | Same behaviour? | Severity |
|---|---|---|---|---|---|
| Help and glossary (help.php) | Page | Link opens website | Link opens website | Yes (link) | none |
| KB list and search (kb.php) | Present | Present | Present | Mostly. Apps leave out Updated date and "Staff only"; Android's empty-list wording differs | Low (D9) |
| KB article view | Present | Present | Present | Mostly. Apps do not show author or updated date; Android omits "Shared with clients" and does not read numbered lists | Low (D9) |
| KB create/edit/delete (templates) | Present | Present | Present | Yes. API does the same saves, audit and idem. Editor hint wording differs on Android | Low (D9) |
| Ask the knowledge base (kb_ask.php / ai_kb) | Present | Present | Present | **No.** Retrieval differs, there is no general-knowledge option and no "no article covers this" guidance | Medium (D3, D4) |
| Ask your caseload (ask.php / ai_ask) | Present | Present | Present | **No.** API snapshot has no last-note dates, and nothing says when the answer is incomplete or the caseload was cut | High (D1) |
| Translate (translate.php / ai_translate) | Present, with Other language | Present, no Other | Present, **sends language codes**, no Other, no incomplete notice | **No** | High (D2), Medium (D5) |
| Remediation (remediate.php / ai_remediate) | Present, with optional client format | Present | Present | Mostly. The client's preferred format is not used; error wording differs | Low (D8) |
| Curriculum generator (curriculum_ai.php / ai_curriculum) | Lesson plan, save as curriculum, download | Skills list only | Skills list only | **No.** Different prompt and output; no save | Medium (D6) |
| Outcome risk flags (risk.php / ai_risk) | Present, with period, signals table and cut-off notice | Present | Present | Partly. No period signal, no table, no cut-off notice | Medium (D7) |
| AI tools hub (ai_tools.php) | Present | Present | Present | Yes. Same feature and permission gates; apps leave out the redaction-status line | Low (D10) |
| Client AI tools (recommend, intake, progress) | On client pages | Present | Present | Yes. Same API and permissions (out of scope here, checked only for gating) | none |
| AI settings (ai.php) | Admin page | Not in app | Not in app | Website-only (reasonable): provider key and endpoint setup by an administrator | none |
| Provider voice read-aloud (ai_speak.php) | Present | Not in app | Not in app | Website-only (reasonable): apps use VoiceOver, TalkBack and system TTS | none |
| Voice assistant (ai_command.php / voice_command) | Present | Present | Present | Yes. Same server code (inc/voice.php voice_handle), confirmation step, and idem on log_hours and complete_session | none |

Permissions match everywhere. The API's $API_ACTION_PERMS (public/api.php:570-622) has ai_ask=clients (ask.php:12), ai_curriculum=templates (curriculum_ai.php:10), ai_risk=analytics (risk.php:13), save_kb/delete_kb=templates (kb.php:12,55), and ai_kb, ai_translate and ai_remediate need only a sign-in, as on the website. Both apps hide each tool behind the same aiHas(feature) plus can(perm) (ios/Sources/AIToolsView.swift:245-250; android/.../ui/AiScreens.kt:62-69). The voice assistant is gated by aiHas("voice") and can("clients") on both apps (ios/Sources/CaseManagementApp.swift:107; android/.../ui/MoreScreen.kt:42), as ai_command.php:23 does.

### Findings

#### D1. Ask your caseload on the apps gets less data, and never says when an answer is incomplete or the caseload was cut
Severity: **High.** The same question gets a different answer on the phone. The website's own example question, "goals under 25% with no note in the last month", cannot be answered from the app.
- Website: ask.php:36-40 selects `last_note`, and ask.php:72 adds ", last note YYYY-MM-DD" to each client line. ask.php:44 and :76 set `$truncated` at 80 clients or 12,000 characters, and :119-123 show "only part of the caseload fit". ask.php:95-97 sets `$cutOff` from `$res['truncated']` and shows "Incomplete". The prompt at ask.php:85-87 asks for "one client per line".
- API: api.php:4135-4137 (case 'ai_ask') has no last_note subquery, and the line at ~4166 has no last-note date. It returns only `text` (~4177), with no `truncated` (caseload cut) or cut-off flag. Its prompt (~4173) has no "one client per line".
- iPhone: ios/Sources/AIToolsView.swift:465 shows `r.text` only.
- Android: android/.../ui/AiScreens.kt:115 and :129 show the text with the footer "Read-only; check before acting.". There is no incomplete notice.
- Fix: in api.php ai_ask, add the `last_note` subquery and the ", last note " fragment exactly as ask.php:39 and :72 do. Return `'caseload_cut' => count($clients) >= 80 || ctx overflow` and `'truncated' => !empty($res['truncated'])`, and use ask.php's prompt text. In both apps, show "Incomplete: the AI stopped before finishing…" above the result when truncated, and the "only part of the caseload fit" hint when caseload_cut.

#### D2. Android Translate sends language codes ("es", "so", "ne", "tl") as the target language
Severity: **High.** The AI is told to translate "into so" or "into ne", so a Somali, Nepali, Tagalog or Haitian Creole translation can come back in the wrong language or be refused.
- Android: android/.../ui/AiScreens.kt:92 has the choice values `"es"`, `"ar"`, `"zh-Hans"`, `"ht"`, `"so"`, `"ne"`, `"tl"`, … and :114 sends `.put("lang", lang)`, which is that code.
- API: api.php:4053-4075 (ai_translate) accepts any letters-only string (regex at ~4067), so "so" passes. The prompt becomes "Translate the content into so." `translate_lang_code("so")` (inc/ai.php:280) finds no match, so `lang` comes back "".
- Website: translate.php:18 and :39 send the language name ("Somali"). iPhone sends the name too (ios/Sources/AIToolsView.swift:348-351, :390).
- Fix: Android should send the display name (`"Spanish"`, `"Somali"`, …) as `lang`, and use the server's returned `lang`/`dir` (or the local code) only to mark the result's language, as iOS does at AIToolsView.swift:391-393. Server-side hardening: in ai_translate, map a known BCP-47 code to its listed name, or refuse a 2-3 letter code.

#### D3. The knowledge-base assistant finds articles differently in the app
Severity: **Medium.** The same question can match different articles, or none, so the app's answer differs from the website's.
- Website: kb_ask.php:18-24 has a long stop-word list, and :38-41 drops a plural "s" ("profiles" finds "profile"). kb_ask.php:57-62 counts a title match twice and orders by score then updated_at. The article's category is included in the context (:87).
- API: api.php:4105-4124 (ai_kb) has a shorter stop list and no plural stripping. Every field scores 1, so a title match is not weighted. The context has no category (~4126).
- Fix: move the website's term extraction and scoring into one shared function in inc/ (for example `kb_search_terms()` and `kb_rank_articles()`), and call it from both kb_ask.php and api.php ai_kb.

#### D4. Ask the knowledge base: no general-knowledge option, and a bare error when nothing matches
Severity: **Medium.**
- Website: kb_ask.php:28 and :111 have the checkbox "If no article covers it, answer from general knowledge (marked as not agency procedure)". kb_ask.php:72-80 gives the general answer under the heading "Answer (general knowledge, not from your articles)". When nothing matches, :67-68 and :114-125 show the card "No article covers this yet", listing the searched words and three next steps (fewer words, add an article, tick general knowledge). The "no words" error is "That question has no words to search the articles for. Include the key words…" (:51).
- API: api.php:4124 fails with "No knowledge-base articles matched that question." and :4115 with 'Ask about a specific topic, for example "JAWS braille display".' There is no `general` input.
- iPhone: ios/Sources/AIToolsView.swift:471-498 (KBAskView) has a question field only.
- Android: android/.../ui/AiScreens.kt:96 and :116 have a question field only.
- Fix: in ai_kb, accept `general` (bool) and run the website's general-knowledge prompt (kb_ask.php:73-77) when no article matches. Return `general: true`, and on no match return the website's sentence with the terms. Add a "If no article covers it, answer from general knowledge" toggle to both apps. Put "(general knowledge, not from your articles)" in the result caption.

#### D5. Translate on both apps has no "Other language", and Android never says a translation is incomplete
Severity: **Medium.** A staff member cannot translate on the phone for a client who speaks Portuguese, Hmong, Amharic and so on. On Android a cut-off translation looks complete.
- Website: translate.php:16 lists 'Other (type below)', and :38-42 and :82-83 have a typed language with its own validation and error.
- iPhone: ios/Sources/AIToolsView.swift:348-351 offers only the 11 listed languages. It does show truncated (:392).
- Android: android/.../ui/AiScreens.kt:92 offers the 11 codes only. The footer `when` at :124-131 has a truncated note for "remediate" but none for "translate", so `r.truncated` is ignored for translations. The 6,000 check at :107 uses `input.length` (UTF-16 units), while the server counts code points (mb_strlen), so emoji or other astral-plane text is refused early with a different count.
- Fix: add "Other (type below)" plus a field named "Other language" to both apps, with the website's error text, and let the server's 422 message attach to that field. On Android, show the incomplete notice for translate as iOS does, and count with `input.codePointCount(0, input.length)`.

#### D6. The curriculum generator on the apps is an older, different tool
Severity: **Medium.** It produces different output and cannot be saved. Saving a curriculum is reasonably a website task, but the draft itself should match.
- Website: curriculum_ai.php:117-140 caps technology plus focus at 20,000 characters and asks for a lesson plan (Lesson / Objectives / Steps to complete the task / How you know it's done) with 4,000 tokens. It then parses lessons and can save them as a curriculum (:57, audit `curriculum.ai_create`) or download them (:105-112).
- API: api.php:4085-4097 (ai_curriculum) asks for "an ordered list of concrete, teachable skills (one per line)" with 1,200 tokens. Its limits are technology 200 and focus 2,000.
- iPhone: ios/Sources/AIToolsView.swift:402-437 (button "Draft skills", footer "Create the full curriculum on the website's Templates page."). Android: android/.../ui/AiScreens.kt:97-101, :117, :127 (same footer).
- Fix: give ai_curriculum the website's prompt and token budget, and return the parsed lessons (reuse `curriculum_parse_plan`). Optionally add `save` (templates permission, audit `curriculum.ai_create`). Correct the footer to name the website page that has it ("AI tools › Curriculum generator").

#### D7. Outcome risk flags: the app review lacks the period signal and the cut-off notice
Severity: **Medium.**
- Website: risk.php:17-18 has a period picker (this quarter by default). risk.php:42 and :74 add "N sessions in the period" for each client, and the period is sent in the prompt (:84). The prompt asks for a numbered list with one client per number (:77-83). risk.php:88-90 flags `$cutOff` ("the clients after the last item were not assessed") and shows a Signals table.
- API: api.php:4183-4219 (ai_risk) has no period_sessions, no period and a shorter prompt. It returns `assessed` and `total_active` but not `truncated`.
- iPhone: ios/Sources/AIToolsView.swift:520-536 shows assessed/total. Android: android/.../ui/AiScreens.kt:128 does the same. Neither can show a cut-off.
- Fix: in ai_risk, accept `from`/`to` (default this quarter), add the period_sessions subquery and the risk.php prompt, and return `truncated`. In both apps, show the incomplete notice, and optionally the period.

#### D8. Remediation: the client's preferred format is not used, and the wording differs
Severity: **Low.**
- Website: remediate.php:30-43 opened with `?client=` (clients permission and scope check) adds "The reader prefers this format: …" (:61). Its errors are "Paste some content first." and "Shorten the content to 20,000 characters or fewer (it has N)…" (:51-53). Its prompts say "do not add or remove information" and, for large print, "no tables" (:56-60).
- API: api.php:4029-4032 returns "Provide content." and "The content is too long (maximum 20000 characters)." Its prompts are the shorter versions (~4033-4038), and there is no client_id.
- Apps: ios/Sources/AIToolsView.swift:285-331 and android/.../ui/AiScreens.kt:87-90 and :113 have no client option.
- Fix: copy remediate.php's prompts and error sentences into ai_remediate. Accept an optional `client_id` (checked with api_require_client and the clients permission) to add the preferred format.

#### D9. Knowledge base screens: small differences in what is shown and said
Severity: **Low.**
- Website article view: kb.php:113-114 shows category, "updated <date>", author and "Shared with clients". List: kb.php:160-165 has columns for Updated and Portal ("Public" / "Staff only"). The editor hint (kb.php:92) is "## " for a heading, "- " for a bullet, "1. " for a numbered step, up to 60,000 characters. Delete is confirmed with "Delete this article permanently? It does not go to the trash…" (kb.php:97). Empty list: "No articles match your search." / "No articles yet." (kb.php:156).
- iPhone: KnowledgeView.swift:143-149 shows no author or updated date. The editor (:237-239) has no formatting or limit hint. The rest matches.
- Android: MoreTools.kt:61-62 shows the category but not "Shared with clients", author or updated. The list's empty text is "No articles found." (:46). The editor hint is "# starts a heading, - a list item" (:109). MarkdownBlocks (:82-96) reads every line as its own paragraph and does not treat "1. " as a numbered item, unlike kb.php's plain_text_html and iOS kbBlocks.
- API: when the article was deleted meanwhile, save_kb answers "Article not found." (api.php:3878), while the website keeps the text and offers to save it as new. Both apps keep the typed text, so nothing is lost.
- Fix: on both apps, show "Updated <date> · <author>" and (Android) "Shared with clients". Use the website's empty-list sentences and editor hint. Have Android use the same block parser as iOS (numbered items, joined paragraph lines). Optionally add an "Ask the knowledge base" button to the KB list when aiHas("kb"), as kb.php:150 does.

#### D10. AI tools hub: wording and links
Severity: **Low.**
- Website: ai_tools.php:15 says "Staff review every AI draft before it is used" plus whether client identifiers are stripped (redaction on or off). The KB-assistant answer links each source article (kb_ask.php:129).
- iPhone: AIToolsView.swift:267-270 says "Staff review every AI draft." and does not give the redaction status. KB sources are plain titles (:487).
- Android: AiScreens.kt:126 and :134 do the same.
- Fix: add `ai_redaction` to the `me` reply (api.php ~2470) and show the website's sentence. Make each "Grounded in" source open the article (kb/{id}; KBDetailView).

### Not gaps (checked)
- KB save and delete through the API do the same SQL, limits (255 / 80 / 60,000), audit codes (kb.create / kb.update / kb.delete, with "(mobile)") and once-only create (idem) as kb.php (api.php:3868-3894 vs kb.php:11-65).
- AI rate limiting: ai_run, and the explicit ai_rate_limit calls in ai_remediate, ai_translate and ai_curriculum, use the website's shared 60-per-10-minutes bucket. ai.ask and ai.risk are audited as on the website.
- ai.php (AI provider settings) and ai_speak.php (provider TTS) are website-only, which is reasonable.
- help.php: both apps open it in the browser (ios/Sources/AccountView.swift:497, DashboardView.swift:220; android/.../ui/ActionCenter.kt:167-168, MoreScreen.kt:77).

---

## Parity audit 4: services, equipment and staff-written reports

Read-only comparison of the website (public/*.php), the app API (public/api.php, public/inc/at_api.php) and the two apps (ios/Sources, android/.../app). Line numbers are from the tree at commit de7d9b8.

Paths are shortened: `A/` = `android/app/src/main/java/org/casemanagement/app/`, `I/` = `ios/Sources/`.

### Summary table

| Feature | Website | iPhone | Android | Same behaviour? | Severity |
|---|---|---|---|---|---|
| Progress report to the counselor (report.php / send_report) | Present | Partial | Partial | No: different report content, no AI, no options | High |
| AI draft on the progress report (owner rule) | Present | Missing | Missing | No | High (owner rule) |
| Device request reopen (procurement.php / device_request_update op=status) | Present | Present | Present | No: wrong status after reopen | High |
| Equipment loan: return date | Present (prefilled from loan_due_days) | Partial (fixed 60 days, cannot be blank) | Partial (blank means no return date) | No | High |
| Equipment list, search, loan, return, service log, interval, add item | Present | Present | Present | Mostly; see findings 7, 12, 13, 14 | Medium/Low |
| Equipment status (Repair / Retire / Available), retire confirmation | Present | Missing | Missing | n/a | Medium |
| Correct last service date (admins) | Present | Missing | Missing | n/a | Low |
| Reservations and waitlist | Present | Partial (no "needed until") | Present | No: list order, past "needed until" accepted | Medium |
| QR scan page (equipment.php?scan=) | Present | Missing | Missing | The phone camera opens the website page, which works | Low |
| Equipment CSV import, QR labels | Present | Missing | Missing | Website-only (reasonable) | n/a |
| Device requests: create, quotes, authorize, order, deliver, train, deny/cancel | Present | Present | Present | Mostly; see findings 3, 15 | Medium |
| Delivered device added to loaner inventory | Present | Missing | Missing | n/a | Medium |
| Funding justification letter | Present | Missing | Missing | n/a | Low |
| Vendors (business associate agreements) | Present (admin) | Missing | Missing | Website-only (reasonable) | n/a |
| AT services records, AI field drafts, issue/return, device comparison report | Present | Present | Present | Yes (shared inc/at_services.php) | n/a |
| AT instructor matching, profitability, homework update | Present | Present | Missing | Apps differ | Low |
| Pre-ETS cohorts: list, open, add, add/remove student, log session | Present | Present | Present | Mostly; add cohort is reduced | Low |
| Pre-ETS: delete a logged session, archive/reactivate, CSV export | Present | Missing | Missing | Delete session needed in the field | Medium |
| Scored assessments (score_assessment.php) | Present | Present | Present | Nearly; see finding 21 | Low |
| Tech assessments (client.php Assessments tab) | Present | Present | Present (AI tools screen only) | Partial: no attachment, no template prefill | Low |
| Suggest goals with AI from an assessment | Present | Missing | Missing | API exists (ai_draft type goals), unused | Medium |
| Formal AT evaluation report (eval_report.php), AI draft, signed email | Present | Missing | Missing | n/a | Medium |
| Worksite assessments (accommodations.php) | Present | Present | Present | Partial: no AI recommendations, no report | Medium |
| AT recommendations (recommend.php / ai_recommend) | Present | Present | Present | Different AI context; no "create device request" | Low |
| AI progress review (progress_review.php / ai_progress) | Present | Partial | Partial | Different AI context; no per-goal apply | Medium |
| Curriculum AI (curriculum_ai.php / ai_curriculum) | Present | Partial (draft only) | Partial (draft only) | Create on website: reasonable | Low |
| Amendment request letter with AI draft | Present | Present | Missing | Apps differ | Medium |
| Letters (letters.php) | Present | Missing | Missing | n/a | Medium |
| Outcome survey: email it, enter phone answers (client.php) | Present | Missing | Missing | n/a | Medium |
| Survey responses report, outcomes report, RSA-911 export, AT Act report | Present | Missing | Missing | Website-only (reasonable) | n/a |
| Templates library (templates.php) | Present | Goal templates used when adding goals | Same | Library management website-only (reasonable) | n/a |
| Public survey page (survey.php) | Present | n/a | n/a | Client-facing page | n/a |

Counts: High 4, Medium 12, Low 14.

### Findings

#### 1. The app's progress report is a different report from the website's
Severity: High

The website sends, by default, the report for the period since the last report, can send a Final Report, includes staff-written Barriers and Recommendations, lets staff change the recipient, copy themselves, and put a copy in the client's portal. The API builds the report with no options, so the counselor gets the whole history of the track, never a final report, never barriers or recommendations, and the note subject is always "Training report emailed to VRC".

- Website: public/report.php:21-26 (period since `last_report_at`, final mode, barriers, recommendations), :136-137 (recipient, cc_self), :190-200 (share_portal), :178-180 (note subject "Final report…" in final mode).
- API: public/api.php:2312 `build_track_report($tid, $userName, $intro)` (no `$opts`, so `since` is null and `final` false); :2316 recipient only as a hidden fallback; :2354 subject fixed.
- iPhone: I/TrackDetailView.swift:1480-1530 (SendReportSheet: personal message and signature only), :1551 body.
- Android: A/ui/TrackScreen.kt:286-307 (SendReportDialog: message and signature only).

Fix: give send_report the same inputs as report.php (`report_kind` final/progress, `period` on by default when `last_report_at` exists, `barriers`, `recommendations`, `recipient`, `cc_self`, `share_portal`) and pass them to `build_track_report(..., 3, [...])`; write the same note subject and copy wording. Add the fields to both sheets (recipient prefilled with the counselor email, "Report since the last one" switch on by default, "Final report" choice, two multi-line fields, two switches). Better still, add a `report_preview` action returning `$report['text']` so staff can read the report before signing, as on the website.

#### 2. No AI draft on the app's progress report (owner rule)
Severity: High (owner rule: AI draft buttons on every staff-written report)

The website has "Draft with AI" for the cover note and "Draft barriers and recommendations with AI", with placeholders restored and the draft added below anything typed. Neither app offers either, and the API has no action for them (ai_draft accepts only note, case, goals).

- Website: public/report.php:46-80 (cover note, placeholders restored, audited 'VRC progress cover note'), :84-103 (report_ai_sections).
- API: public/api.php:3034-3089 (types note/case/goals only).
- iPhone: I/TrackDetailView.swift:1480-1530 (no AI button). Android: A/ui/TrackScreen.kt:286-307 (no AI button).

Fix: add `ai_draft` types `report_intro` and `report_sections` (track_id, final flag, the typed text) that repeat report.php's prompts, the `[CLIENT]`/`[COUNSELOR]`/`[AUTH]`/`[EMPLOYER]` restore and the audit details; return the text and `truncated`. Add "Draft with AI" buttons to both sheets that append below typed text, never replace it, and say when a placeholder remains (send_report already refuses one).

#### 3. Reopening a device request from the app puts it back at "Requested"
Severity: High

The website reopens a denied or cancelled request at the furthest step it had reached (Delivered, Ordered, Authorized, Awaiting Authorization, Quoting or Requested), so it is counted and filtered where it belongs. The API sets the status to the literal "Requested", so a reopened request that already has an order or delivery shows as a new request, and the forward-only step rule then never moves it back to where it really is until each step is saved again.

- Website: public/procurement.php:268-279.
- API: public/api.php:3624-3630 (`UPDATE device_requests SET status=?` with `$to = 'Requested'`).
- iPhone: I/ProcurementView.swift:179. Android: A/ui/ProcurementScreens.kt:128.

Fix: in device_request_update op=status with `to = Requested`, compute the status exactly as procurement.php:271-275 (needs `selected_quote_id` and a quote count in the SELECT at api.php:3538) and audit "reopened as X". Return the new status so the apps can say "Request reopened as Ordered."

#### 4. Loan return date: three different behaviours
Severity: High

The website prefills "Return due" with today plus the administrator's `loan_due_days` setting (Lists and time limits, default 60) and allows it to be cleared for no return date. Android sends no date when the field is left blank, which the server stores as no return date, while its hint says "empty for the usual period", so Android loans are never overdue unless a date is typed. iPhone always sends today plus a fixed 60 days, ignores the setting, and cannot record a loan with no return date.

- Website: public/equipment.php:609 (scan page default), main form same rule; public/inc/lists.php:63.
- API: public/api.php:2659 (blank stays null; no default applied).
- iPhone: I/EquipmentView.swift:452 `defaultDue` (60 days fixed), :481 DatePicker with no "no return date" choice.
- Android: A/ui/EquipmentScreen.kt:398 hint "empty for the usual period", :413 date sent only when typed.

Fix: add `loan_due_days` to `form_options` (public/inc/api_more.php:19) as a number. iPhone: default the picker to today plus that number and add a "No return date" switch. Android: prefill the field with that date and change the hint to "year-month-day; clear it for no return date". Optionally let the API apply the default when `due_date` is absent (not when sent blank on purpose).

#### 5. Formal AT evaluation report missing from both apps
Severity: Medium

eval_report.php builds the evaluation report from a tech assessment, drafts the four narrative sections with AI (never over text the evaluator wrote), and emails it signed to the VR counselor or downloads a PDF. Nothing in the API or either app offers it; an evaluator who has just recorded an assessment in the field cannot send it.

- Website: public/eval_report.php:1-80 and the AI block from :71.
- API, iPhone, Android: no action or screen (grep for eval_report finds only a comment in api.php:215).

Fix: add `eval_report` (GET: the four prefilled sections), `ai_eval_draft` (sections still empty or default only, as eval_report.php), and `send_eval_report` (signature check, placeholder check, counselor-verified check, note, audit, as the website). Add an "Evaluation report" button on each tech assessment in both apps, with an AI draft button.

#### 6. Worksite assessments: no AI recommendations and no report in the apps
Severity: Medium (owner rule for the AI button)

The website's worksite form has "Draft recommendations with AI" (worksite_ai_recommendations) and a printable recommendation report. The apps edit the same fields but have neither, and the API has no AI action for it.

- Website: public/accommodations.php:118-135 (AI), :200-220 (report, audited worksite.report), :272 (button).
- API: public/api.php:3411-3500 (no AI, no report).
- iPhone: I/AccommodationsView.swift:214-300. Android: A/ui/ProcurementScreens.kt:205-300.

Fix: add `ai_worksite` calling `worksite_ai_recommendations()` with the typed job title, tasks and barriers; audit as the website. Add a "Draft recommendations with AI" button under Recommendations in both apps that fills an empty field or appends below typed text. Add a `worksite_report` text action for sharing.

#### 7. Equipment status changes are website-only
Severity: Medium

Staff on the website set an item to Repair, Retired or back to Available, with a confirmation page for retiring that says how many reservations will be cancelled; the change is refused while on loan or in an AT workflow, retiring notifies holders and closes the service task, and Available promotes the waitlist. The apps cannot do any of this: a device found broken in the field cannot be put into repair from the phone. There is no API action.

- Website: public/equipment.php:298-376, retire confirmation :499-530.
- API: none. iPhone: I/EquipmentView.swift (no status action). Android: A/ui/EquipmentScreen.kt (none).

Fix: add `set_equipment_status` repeating equipment.php:298-376 line for line (including `confirm_retire`, `cancel_retired_holds`, `notify_retired_holders`, the task close and the promotion message). In the apps add "Mark in repair", "Back in service" and "Retire" on the item's detail screen, with a confirmation for Retire that states the number of reservations affected (return that count from equipment_detail).

#### 8. Reservations: iPhone has no "needed until"; the API accepts a past one
Severity: Medium

The website and Android ask for "Needed from" and "Needed until". The iPhone sheet has only "Needed from", so iPhone holds never lapse. The website refuses a "needed until" in the past (the hold would never count); the API does not, so Android can save a hold that is already lapsed. The website lists the waitlist in queue order (who gets the item next); the API orders by needed_from, so both apps show the queue out of order.

- Website: public/equipment.php:170-172 (past date refused), :703-716 (order by status, created_at).
- API: public/api.php:3181-3187 (no past check), :3173-3179 (order by needed_from).
- iPhone: I/EquipmentView.swift:925-926 (from only). Android: A/ui/EquipmentScreen.kt:349-350.

Fix: API: refuse `needed_until < today` with the website's wording; order by `er.status, er.created_at, er.id` and return `live` and `by_name`. iPhone: add an optional "Needed until" date with its own switch, as "Needed from".

#### 9. AI progress review differs, and its results cannot be applied in the apps
Severity: Medium

The website's review sends each goal's measurable metric and the last 15 logged sessions as well as the notes, shows when the review stopped short, and lets staff record progress per goal from the review. The API sends no sessions and no metric (a different, weaker review), does not return `truncated`, and the apps show text only.

- Website: public/progress_review.php:154-195 (context with metric and work_logs, truncated), :55-120 (record per goal).
- API: public/api.php:4005-4026.
- iPhone: I/AIToolsView.swift:592. Android: A/ui/AiScreens.kt:192.

Fix: make ai_progress build the same context as progress_review.php:157-171, use the same prompt, return `truncated`. In both apps list the client's active goals under the review with "Record progress" (goal_progress already exists) so a reviewed goal can be updated without leaving the screen.

#### 10. "Suggest goals with AI" from an assessment is unused
Severity: Medium

The website offers "Suggest goals with AI" on each tech assessment. The API supports it (ai_draft type goals) but neither app calls it.

- Website: public/client.php:2135-2140 (button), handler from :1322.
- API: public/api.php:3071-3082.
- iPhone and Android: no call (grep for `"type": "goals"` finds none).

Fix: add a "Suggest goals with AI" button on each assessment in both apps' assessment lists; show the draft and offer "Add as goal" per line, prefilling the add-goal form.

#### 11. Android has no amendment request letter (iPhone has it, with AI)
Severity: Medium

The amendment letter (more hours or funds for a track) is on the website and on the iPhone with "Draft with AI". Android only says "The amendment letter is on the website."

- Website: public/amendment_request.php. API: public/api.php:4634.
- iPhone: I/TrackDetailView.swift:813-940 (AmendmentView with AI draft).
- Android: A/ui/TrackScreen.kt:112.

Fix: port AmendmentView to Android (load, edit, Draft with AI, send), using the same `amendment` action.

#### 12. Letters missing from both apps
Severity: Medium

letters.php writes the welcome, appointment confirmation and missed-appointment letters for one client from merge fields, lets staff edit them, and saves them to documents or the portal, as a note, or downloads them. After a missed session in the field, staff cannot send the missed-appointment letter from the phone. Note the website has no AI draft here either (see finding 30).

- Website: public/letters.php:40-90; templates public/inc/portal_history.php:90-107.
- API, iPhone, Android: none.

Fix: add `letters` (GET: the list and the merged text for a client) and `save_letter` (to documents, portal, or note, audited as letter.save / letter.note). Add "Write a letter" to the client screen in both apps.

#### 13. Outcome survey: cannot email it again or enter phone answers in the apps
Severity: Medium

On the client page the website can email the outcome survey after closure and enter the answers given by phone. The apps only show that a survey was emailed automatically when a client is closed as rehabilitated.

- Website: public/client.php:1065-1100.
- API: only the automatic send in save_client (public/api.php:1996-2010).
- iPhone: I/ClientEditSheet.swift:636. Android: A/ui/ClientEditScreen.kt:390.

Fix: add `send_survey` and `phone_survey` actions repeating client.php:1066-1100, and a "Outcome survey" section on closed clients in both apps.

#### 14. Items added from the apps lack purchase date, useful life and AT type
Severity: Medium

The website records purchase date, useful life (months) and the AT Act device type. The API and both apps do not, so app-added items have no book value and are missing from the AT Act report's device-type categories until someone edits them on the website. The API also clamps a service interval outside 0 to 3650 instead of refusing it as the website does.

- Website: public/equipment.php:37-90.
- API: public/api.php:3157-3172 (no purchase_date, useful_life_months, at_type; interval clamped).
- iPhone: I/EquipmentView.swift:995-1063. Android: A/ui/EquipmentScreen.kt:228-272.

Fix: accept and validate those three fields in add_equipment with the website's messages; refuse an out-of-range interval (also in set_equipment_interval, api.php:3148-3156). Add the three optional fields to both forms (AT type as a quiet picker with "Not recorded").

#### 15. Device requests: delivery cannot add the device to inventory; no justification letter; no reason on deny/cancel
Severity: Medium

The website's delivery step has "Add to the loaner inventory" (once per request), offers a printable funding justification letter, and keeps a reason note when a request is denied or cancelled. The apps have none of these; the API's deliver step has no inventory option.

- Website: public/procurement.php:229-249 (inventory), :293-318 (letter), :277 (notes).
- API: public/api.php:3606-3612 (deliver), :3624-3630 (notes only when sent).
- iPhone: I/ProcurementView.swift:247-276, 367. Android: A/ui/ProcurementScreens.kt:125-128, 145.

Fix: add `add_to_inventory` to op=deliver (only with the equipment permission, same marker and messages); add a "Reason" field to the deny/cancel confirmations; add a `device_letter` text action for sharing.

#### 16. Pre-ETS: a wrongly logged session cannot be deleted from the apps
Severity: Medium

The website tells staff "If anything is wrong, delete it under Recent sessions and log it again" and offers delete (audited with the attendance removed), archive and reactivate. The apps can log a session but not take one back, so a mistake made on the phone inflates the hours report until someone uses the website. Adding a cohort in the apps also drops start and end dates, coordinator and notes, and the API always makes the creator the coordinator.

- Website: public/programs.php:163-207 (delete, archive), :31-68 (add with coordinator and notes).
- API: public/api.php:3663-3674 (`coordinator_id = $userId`, no notes).
- iPhone: I/ProgramsView.swift:296, 309-345. Android: A/ui/ProgramsScreens.kt:81-102, 193.

Fix: add `delete_cohort_session` (repeat programs.php:163-191) and a "Delete this session" action with confirmation on each recent session in both apps. Accept dates, coordinator and notes in add_cohort and add the fields to both forms.

#### 17. AT services: Android lacks instructor matching, profitability and homework update
Severity: Low

iPhone calls at_matches, at_profitability and at_homework_update; Android calls none of them.

- iPhone: I/ATServicesView.swift:707, 764. Android: A/ui/AtServicesScreen.kt (no calls).

Fix: add the matching search and the AT homework update to Android; profitability can stay iPhone and website only if the owner prefers.

#### 18. Equipment return: condition choices and a hard-coded "Needs Repair"
Severity: Low

The website offers every configured condition ("Unchanged" or any item of the list) on return. Both apps offer only "same condition" or "Needs Repair", hard-coded; if an administrator renamed that condition, `loan_handoff_in` ignores it and nothing tells the user. The API also calls `return_equipment($lid)` without the actor.

- Website: public/equipment.php:575, :287.
- API: public/api.php:2684 (`return_equipment($lid)`).
- iPhone: I/EquipmentView.swift:162-170. Android: A/ui/EquipmentScreen.kt:118-119.

Fix: offer the `equipment_conditions` list from form_options in both apps (a quiet picker, "Unchanged" first); pass `$userId` to return_equipment.

#### 19. Android records the item's condition as "condition going out" by default
Severity: Low

The website and iPhone default to "Not recorded". Android preselects the item's current condition, so every Android loan records a condition_out nobody checked.

- Website: public/equipment.php:779. iPhone: I/EquipmentView.swift:484-487. Android: A/ui/EquipmentScreen.kt:379.

Fix: default Android to "" ("Not recorded").

#### 20. Service log from the API: blank date becomes today, the audit detail is lost
Severity: Low

The website requires the service date and refuses a future one; its audit entry is "Maintenance on 2026-11-19". The API takes a blank date as today, allows tomorrow, and audits only "(mobile)". Both apps send a date, so only the audit detail differs in practice.

- Website: public/equipment.php:391-394, :433. API: public/api.php:3121-3141.

Fix: audit `$serviceType . ' on ' . $date . ' (mobile)'`; refuse a blank date.

#### 21. Scored assessment: retired instrument and blank date
Severity: Low

The website still saves ratings already entered when the instrument was retired meanwhile, and requires a date. The API refuses ("Instrument not found") and defaults a blank date to today. Its audit detail omits the instrument name.

- Website: public/score_assessment.php:57-62, :78-81, :118. API: public/api.php:3307-3349.

Fix: drop `AND active = 1` from save_scored's instrument lookup; refuse a blank date; audit `name: total/max (mobile)`.

#### 22. Tech assessment from the apps: no attachment, no template prefill
Severity: Low

The website can attach the assessment report file and prefill the form from an assessment template. The apps cannot.

- Website: public/client.php:654-740, :2070-2090. API: public/api.php:2238-2257.

Fix: add an optional attachment (reuse upload_document's checks) and a template picker (a `assessment_templates` action) to the assessment form.

#### 23. AT recommendations: different AI context; cannot start a device request
Severity: Low

The API context omits "uses a screen reader", the latest worksite job title and tasks, and "technology already tried", and its prompt is shorter than the website's, so the two give different suggestions. The website then offers "Create a device request"; the apps do not.

- Website: public/recommend.php:27-50, :59-67, :93-110. API: public/api.php:3953-3973.

Fix: call one shared `recommend_context()` and prompt from both; add "Start a device request" under the result in both apps (procurement permission only).

#### 24. iPhone does not say when no release of information covers the counselor
Severity: Low

send_report returns `release_warning`; Android adds it to the confirmation, the iPhone ignores it.

- API: public/api.php:2368. Android: A/ui/TrackScreen.kt:300. iPhone: I/TrackDetailView.swift:1540-1556.

Fix: decode `releaseWarning` and append it to the iPhone confirmation.

#### 25. Cancelling a reservation: the app is not told the item passed to the next client
Severity: Low

The website says "The item is now held for the client who has waited longest". The API does not return whether a promotion happened.

- Website: public/equipment.php:264. API: public/api.php:3249-3256.

Fix: return `promoted: true/false` and say it in both apps.

#### 26. Money typed with a dollar sign or commas is refused in the apps
Severity: Low

The website accepts "$2,995.00"; api_money refuses it with "Enter the amount as digits", which does not name the field.

- Website: public/equipment.php:26-33, public/procurement.php:142. API: public/api.php:87-96.

Fix: strip "$", spaces and thousands commas in api_money and name the field in its message ("Value: enter…").

#### 27. Equipment list in the apps leaves out retired items
Severity: Low

The website lists retired items under a "Retired" filter; the API leaves them out, so a retired device's history cannot be found on the phone.

- Website: public/equipment.php:618-625. API: public/api.php:2634.

Fix: return retired items with a flag and show them under "Not available" in both apps.

#### 28. Curriculum AI: drafts only in the apps
Severity: Low (reasonable)

The apps draft the skills list and say to create the curriculum on the website; the website creates it. Building curricula is library work, reasonably website-only.

- Website: public/curriculum_ai.php:45-60. iPhone: I/AIToolsView.swift:423. Android: A/ui/AiScreens.kt:117.

#### 29. AT Act report: missing loan details cannot be filled in from the phone
Severity: Low (website-only is reasonable)

at_act_report.php lets staff with equipment access fill in a loan's missing purpose and borrower type. The report is reasonably website-only, but the loan forms in both apps already collect these, so only older loans are affected.

- Website: public/at_act_report.php:13-40.

#### 30. Staff-written texts with no AI draft on the website either (owner rule)
Severity: Low

Two staff-written texts have no AI draft anywhere: the device request "Justification of need", which becomes the funding justification letter (procurement.php:79-110, :293-318), and the letters (letters.php). The owner's rule asks for AI drafts on all staff-written reports.

Fix: add "Draft with AI" for the justification (from the latest assessment, goals and the chosen device) on the website and both apps, and for the letter body on the website, keeping "staff edit before sending".

### Website-only (reasonable)

- equipment_import.php: bulk CSV import with a dry run, desk work.
- equipment_labels.php: printing QR labels. Scanning a label with the phone camera opens the website's scan page in the browser, which works on a phone, so no app screen is needed.
- vendors.php: administrator record of business associate agreements.
- rsa_report.php, outcomes.php (including its AI narrative), survey_responses.php, at_act_report.php: period reports and exports for agencies and contract renewal.
- templates.php: managing the goal, assessment and curriculum libraries. The apps use goal templates when adding goals; assessment templates are finding 22.
- survey.php: the public page clients answer on.

### Owner rule check: AI draft buttons on staff-written reports

| Staff-written text | Website | iPhone | Android |
|---|---|---|---|
| Progress report cover note, barriers and recommendations | Yes | No (finding 2) | No (finding 2) |
| Evaluation report | Yes | Screen missing (finding 5) | Screen missing (finding 5) |
| Amendment letter | Yes | Yes | Screen missing (finding 11) |
| Worksite recommendations | Yes | No (finding 6) | No (finding 6) |
| AT services record fields (at_ai_draft) | Yes | Yes | Yes |
| Case notes, case summary | Yes | Yes | Yes |
| Goals from an assessment | Yes | No (finding 10) | No (finding 10) |
| Progress review | Yes | Yes (weaker context, finding 9) | Yes (weaker context, finding 9) |
| AT recommendations | Yes | Yes | Yes |
| Device request justification | No | No | No (finding 30) |
| Letters | No | Screen missing | Screen missing (findings 12, 30) |

---

## Parity audit 5: billing, funding, oversight and administration

Read-only comparison of the website (public/*.php), the app API (public/api.php, inc/api_parity.php, inc/api_more.php, inc/api_425.php), the iPhone app (ios/Sources) and the Android app (android/app/src/main/java/org/casemanagement/app). Nothing in the repository was changed.

### Summary table

| Feature | Website | iPhone | Android | Same behaviour? | Severity |
|---|---|---|---|---|---|
| Invoice list (status filter, balances, paging) | invoices.php | Present (paging, unbilled figure) | Partial (first 300 only, no unbilled figure, no track or authorization) | No: the apps differ from each other, and both lack the agency, month and text filters | Medium |
| Invoice: mark a draft submitted | invoices.php `set_status` (date chosen) | Present (always today) | Present (always today) | No: the date can't be chosen, so the due date is set from the wrong day | Medium |
| Invoices: several submitted at once / "submit all shown" | invoices.php `bulk_submit`, `submit_shown` | Missing | Missing | n/a | Low |
| Invoice: payment, write-off, remove payment, deny or void, memo, void draft | invoices.php | Missing | Missing | Website-only (office bookkeeping); see finding 7 | Low |
| Invoice PDF / accessible HTML | invoice_pdf.php | Missing | Missing | n/a | Low |
| Generate invoices (batch), QuickBooks CSV | invoices.php | Missing | Missing | Website-only (reasonable) | n/a |
| Outstanding by agency (past due, part-paid) | invoices.php | Partial (no past due) | Partial (no past due) | No | Low |
| Incoming authorization emails (paste, start track, dismiss) | authorizations.php (clients) | Missing | Missing | n/a | Medium |
| Track hours and funds remaining | track.php | Present | Present | Yes (track_rollup) | n/a |
| Unused authorizations report | unused_authorizations.php | Missing | Missing | Website-only (analytics report) | n/a |
| VR portal tracker | vr_portal.php (billing) | Missing | Missing | Website-only (done in the agencies' portals at a desk) | n/a |
| Counselor portal: list, send/reset link, turn off | counselors.php (admin) | Present | Partial (no expiry or last-used dates) | Mostly; email text and failure handling differ | Low |
| Counselor portal: replace a counselor on all clients | counselors.php | Missing | Missing | Website-only (reasonable, admin) | n/a |
| Counselor details on a client | client.php | Present | Present | Yes | n/a |
| Grants and contracts: list, detail, add, deliverable status, add deliverable, add spend | contracts.php (billing) | Present | Present | Mostly; notes, limits, audit wording differ | Low |
| Contracts: change budget, close/reopen, delete deliverable or spend | contracts.php | Missing | Missing | n/a | Low |
| Access bookings: list, add, status | access_bookings.php (schedule) | Present | Present | Mostly; cancelled ones can't be seen or restored | Low |
| Staff utilization | utilization.php (analytics) | Present | Present | **No: value figure includes no-charge work** | High |
| Equipment loan due date (Lists: loan_due_days) | equipment.php | Ignores setting (60 days fixed) | **Empty saves no due date** | No | High |
| Dashboard: authorizations ending soon (Lists: auth_soon_days) | dashboard.php | Wording fixed at "30 days" | Neutral wording | No (iPhone) | Medium |
| Outcome risk flags (AI) | risk.php (analytics) | Present | Present | No: the website now sends sessions in the period and the period | Medium |
| Custom lists (Lists and time limits) | lists.php | Present via form_options | Present via form_options | Yes for the lists the apps use | Low (see 18) |
| Pricing rules (VR conflict warn/block) | pricing.php | Honoured (save_track) | Honoured (save_track) | Yes | n/a |
| Public wording on in-person signing | wording.php | Honoured (shared inc/signatures.php) | Honoured | Yes | n/a |
| Time zone (Settings) | settings.php | Honoured (me.timezone) | Honoured (me.timezone) | Yes | n/a |
| Offices (client's office) | offices.php | Present on client form | Present on client form | Yes; office filter on the client list is not in the apps | Low |
| Branding (organisation name) | settings.php | Not sent to the apps | Not sent | n/a | Low |
| Workflows: action items, briefing, note review, waitlist | workflows.php | Present | Present | Yes | n/a |
| Data quality, documentation auditor | data_quality.php, doc_audit.php (clients) | Missing | Missing | n/a | Low |
| Capacity planning | capacity.php (schedule) | Missing | Missing | Website-only (planning) | n/a |
| Referral deadlines summary | referral_deadlines.php | Present (totals) | Present (totals) | Yes | n/a |
| Remediate / translate (AI) | remediate.php | Present | Present | Not compared in depth | n/a |
| Agency report, agency scorecard, funder report, business summary, staff report, analytics, custom reports, audit, exports/export, QuickBooks, SMS settings, offices, users, admin, settings, lists, wording, email templates, referral settings, phone apps, system health, update/upgrade, federation, funding agencies, pricing | various (admin/analytics/billing) | Missing | Missing | Website-only (reasonable) | n/a |
| Client portal | portal.php | n/a | n/a | Client-facing; staff send the link from the apps (present) | n/a |

### Findings

#### 1. Staff utilization: the apps' "value" includes no-charge work the website leaves out
**Severity: High** (a manager gets a different dollar figure on the phone than on the website).
- Website: public/utilization.php:30-36. Since 4.29.0, value is `CASE WHEN w.invoice_id IS NULL AND t.billing_mode = 'none' THEN 0 ELSE ...`, so uninvoiced no-charge work has no value. The page says so (utilization.php line ~136: "no-charge work has no value").
- API: public/api.php:4597-4602 (`case 'utilization'`). It still sums `ROUND(w.hours * rate) + ROUND(w.mileage * rate)` for every log. The comment above it ("as utilization.php counts it") is out of date.
- iPhone (ios/Sources/UtilizationView.swift) and Android (ui/MoreTools.kt `UtilizationScreen`) show `value` / `total_value` as sent.
- Fix: copy the website's CASE expression into the API query, and keep the comment in step. Also consider sending `util: null` for no target (the website sorts no-target staff last; the API sorts them by 0, mixed in with real 0%).

#### 2. Equipment loan due date ignores "A new equipment loan is due back after this many days"; on Android an empty field saves no due date at all
**Severity: High** (Android: the loan is stored with no due date, so it never shows as overdue and no overdue reminder runs).
- Website: public/equipment.php:598 and 771 fill the due date with `+cms_number('loan_due_days')` days (Lists and time limits; inc/lists.php:63, default 60).
- API: public/api.php:2661-2668 passes `$loanDue` (null when not sent) straight to `loan_equipment()`. inc/access.php:63-82 stores `due_date` NULL. There is no default.
- Android: ui/EquipmentScreen.kt:398 hint "year-month-day; empty for the usual period". Line 413 leaves `due_date` out when it is empty, so the loan saves with no due date.
- iPhone: ios/Sources/EquipmentView.swift:452 `defaultDue` is a fixed 60 days. That's correct only when the administrator has kept the default.
- Fix: in the API, `$loanDue ??= date('Y-m-d', strtotime('+' . cms_number('loan_due_days') . ' days'))`. Also send `loan_due_days` (and the other numbers in inc/lists.php that the apps show) in `form_options` so iPhone can start its date picker there.

#### 3. Mark submitted always uses today; the website asks for the date it was sent
**Severity: Medium** (the due date and days outstanding are worked out from the wrong day when the invoice was sent earlier).
- Website: public/invoices.php:284-298. `submitted_on` is optional, from the creation date to today, and the confirmation names the due date.
- API: inc/api_more.php:114-127 already accepts `submitted_on` with the same rule (billing_date_in).
- iPhone: ios/Sources/InvoicesView.swift:225-235 sends only `id`, and the confirmation is "Mark invoice N submitted today?"
- Android: ui/ReportsScreens.kt:193-198 sends only `id`.
- Fix: add an optional "Date sent" date field (default today) to both confirmations and send `submitted_on`. iPhone should also say the due date the server returns, as Android and the website do.

#### 4. The Android invoice list stops at 300, and it shows less than iPhone
**Severity: Medium** (the two apps differ; on Android older invoices can't be reached at all).
- API: inc/api_parity.php:984-1030 returns `page`/`pages` and takes `?page=`.
- iPhone: InvoicesView.swift:113-122 has Newer/Older paging, the "Unbilled work" figure, and the track and authorization number on each row.
- Android: ReportsScreens.kt:165-191 never sends `page` ("Showing the newest N ... see them all on the website"). It doesn't show `unbilled`, `track_title` or `auth_number`. It shows "due ..." for every invoice with a due date, including Paid, Denied and Void ones; iPhone shows it only for Submitted.
- Fix: add paging, the unbilled figure and the track/authorization to Android. Show the due date only on Submitted invoices.

#### 5. Invoice filters and finding one invoice
**Severity: Medium**
- Website: invoices.php:24-41 and 336-352 filter by status, funding agency (every spelling, agency_match_sql), month and text (invoice number or client name), and `?id=N` lists a single invoice. search.php:63-66 finds invoices by number or payment reference.
- API `invoices`: status only (api_parity.php:988). The API `search` action doesn't return invoices.
- Apps: a status picker only. A billing staff member asked about invoice INV-0123 on the phone can only scroll.
- Fix: accept `agency`, `month`, `q` and `id` in the API `invoices` action, reusing `invoice_list_filters()` / `invoice_filter_sql()` (move them from invoices.php into inc/billing.php). Add a search field to both apps, and include invoices in the API search as search.php does.

#### 6. Incoming authorization emails are not in the apps
**Severity: Medium**
- Website: public/authorizations.php (permission `clients`, caseload-scoped). Paste an approved-authorization email, review the matched client, then start the track or dismiss it. Emails forwarded to the CMS mailbox wait here too.
- API / apps: no action, so staff in the field who get an authorization can't confirm it and start the track from the phone.
- Fix: add `incoming_authorizations` (list and detail), `start_authorization_track` and `dismiss_authorization` actions over the inc/authorization_email.php helpers, with the same `$load` scope rule. Add a screen in both apps. Lower priority if the owner treats this as desk work.

#### 7. Recording payments, write-offs, denial or void, memos, void draft
**Severity: Low** (website-only is reasonable: these are bookkeeping steps done with the remittance at a desk).
- Website: invoices.php:216-281. Apps: no API actions. The iPhone list says so (InvoicesView.swift:128-129); Android doesn't say where to do them.
- Fix: no change needed. Optionally add the same one-line note to Android.

#### 8. The iPhone dashboard says "30 days" whatever the administrator set
**Severity: Medium** (wrong words to a VoiceOver user when the setting has been changed).
- API: api.php:865-867 uses `cms_number('auth_soon_days')`.
- iPhone: ios/Sources/DashboardView.swift:100 and 105 hard-code "Expired or expiring within 30 days" / "...in the next 30 days."
- Android: ui/DashboardScreen.kt:60 "Authorizations ending soon" (neutral, correct).
- Fix: send `auth_soon_days` with `dashboard` and use it in the wording, or use Android's neutral heading.

#### 9. Outcome risk flags: the API no longer sends what risk.php sends
**Severity: Medium** (the AI ranks clients from different signals on the phone and on the website).
- Website: public/risk.php:17-18 and 41-47. The period (report_period, this quarter by default), `period_sessions` per client ("N sessions in the period"), the period in the prompt, and a "numbered list, one client per number" instruction.
- API: api.php:4179-4215 (`ai_risk`). No period, no sessions in the period, shorter prompt.
- Apps: AIToolsView.swift / AiScreens.kt call `ai_risk` with no period.
- Fix: repeat the period signal in the API (default this quarter, or accept `from`/`to`), and move the prompt into a shared helper so the two can't drift again.

#### 10. Outstanding summary is missing "past due" and part-paid
**Severity: Low**
- Website: invoices.php:384-391 and 434-450 show Amount owed, **Past due**, part-paid count and oldest days. Each row also shows days overdue.
- API: api_parity.php:1020-1028 sends count, amount and oldest only. Rows have no `days_overdue`.
- Fix: add `overdue` and `part_paid` to the API's aging query and `days_overdue` to each row. Show "past due $X" in both apps.

#### 11. Invoice PDF / accessible HTML can't be opened from the apps
**Severity: Low**
- Website: invoices.php rows link to invoice_pdf.php?invoice=N (PDF and `format=html`).
- Apps: no link. A billing person checking an invoice before marking it submitted can't read it on the phone.
- Fix: add an "Open invoice" row that opens `invoice_pdf.php?invoice=N&format=html` in the browser (both apps already open site pages this way, e.g. MoreScreen.kt openSitePage), or an API action that returns the HTML.

#### 12. Counselor portal differences
**Severity: Low**
- Email text: the website (counselors.php:44-47) mentions "authorizations, hours used, and goal status", "to protect client information" and how to get a new link. The API (api.php:2750-2752) sends a shorter body. Use one shared function.
- Failure after the email was sent: the website catches a failed link store, audits `vrcportal.send_failed` and says the emailed link won't open (counselors.php:55-68). The API has no try/catch (api.php:2761-2763), so the app gets a generic 500, the throttle row stays, and nothing is audited.
- Android (ui/MoreTools.kt:133-185) doesn't show when the link expires or when it was last opened. The API sends both; iPhone shows them.
- Android doesn't clear `error` after a later success, so a stale error stays above the new notice.
- "Replace with a new counselor" (counselors.php:73-121) is admin housekeeping: website-only is reasonable.

#### 13. Grants and contracts differences
**Severity: Low**
- Notes: the website's add form has Notes (contracts.php:96-101). The API's `add_contract` (api.php:3753-3765) has no notes and neither app offers it.
- Amounts: the API's `api_money` (api.php:87-96) takes at most 7 digits with no commas or $ ($9,999,999.99 at most). The website's `contract_money_in` (contracts.php:41-50) takes 10 digits, "$50,000" and "50,000.00". A budget of $10M or more can't be entered from the apps. Android `moneyText` (ProgramsScreens.kt:38) turns a comma into a decimal point, so "12,50" is saved as 12.50, which the website refuses. It also refuses "$1,250", which the website reads as 1250.
- Android percent spent (ProgramsScreens.kt ContractScreen: `Math.floor(spent * 100 / amount)`) uses float division. The website (contracts.php:31-35) and iPhone (ContractsView.swift:90-93) work in cents because a float can floor an exact 29% to 28%.
- Audit: the website's details are the contract name and the amount. The API writes "(mobile)" only for `contract.add` (api.php:3763). `set_deliverable` audits even when the status didn't change (the website skips it).
- Missing from the apps: change budget, close/reopen, delete a deliverable or spend entry (contracts.php:155-220). Low; a desk task.

#### 14. Access bookings differences
**Severity: Low**
- Cancelled bookings: the website lists them under "Show cancelled", where they can be set back (access_bookings.php:111, 122-128). The API excludes them (api.php:3812), so a booking cancelled by mistake in an app can't be found or restored there. iPhone (BookingsView.swift:59) and Android (ProgramsScreens.kt:376) both say "Cancelled bookings are removed from this list."
- Audit detail: website "Requested → Confirmed" (access_bookings.php:108). API "Confirmed (mobile)" (api.php:3846).
- Cost: website up to 99,999,999.99 (8 digits). API `api_money` 7 digits.
- "CART captioning" shows as "CART (real-time captioning)" on the website (BOOKING_TYPE_LABELS). The apps show the stored name.
- Fix: add `?cancelled=1` to `bookings` and a "Show cancelled" toggle. Log old → new in the API audit.

#### 15. Utilization presentation differs from the website
**Severity: Low**
- The apps add their own bands ("on target" 85%+, "below target" 60-84%, "well below target"): UtilizationView.swift:146-158 and MoreTools.kt `band`. The website has no bands; it marks 100% and over "at or above target" (utilization.php ~127).
- Android's heading is "Organisation:" for the figure the website calls "Team utilization".
- The website uses the remembered report period (report_period). The apps default to 28 days each time.
- Fix: use the website's wording. Accept the remembered period when no dates are sent.

#### 16. iPhone menu calls Invoices "Read only"
**Severity: Low**
- ios/Sources/DashboardView.swift:196 subtitle "Read only: status, amounts and balances", but drafts can be marked submitted there (4.0.0).
- Fix: "Status, balances, and marking drafts submitted".

#### 17. Invoice screen error handling
**Severity: Low**
- Android ReportsScreens.kt:167-198: `error` is never cleared when a later submit succeeds or the status filter changes, so a stale error stays on screen.
- iPhone InvoicesView.swift:236-245: a 409 ("no longer a draft") doesn't reload the list, so the row still offers Mark submitted. `submit_invoice` isn't in API_IDEM_ACTIONS (api.php:695-700), so a retry after a lost reply returns the same 409 even though the first attempt worked.
- Fix: clear the error on success and reload after a 409 in both apps. Treat an already-Submitted invoice with the same date as success, or add `submit_invoice` to the retry guard.

#### 18. Custom lists the apps don't use
**Severity: Low**
- `form_options` (inc/api_more.php:18-29) sends `vision_statuses` and `referral_sources`, but neither app reads them. FormOptions.list / formList are used for the other 14 lists. Vision status isn't on either app's client form (cross-reference for the clients audit).
- Incident types and referral close reasons come with their own actions and are honoured. Release lists come with `release_choices`.
- The iPhone built-in fallback for note types (ClientsView.swift:788) adds "Phone Call", which the website's built-in list doesn't have (inc/lists.php:41). It is used only with an older server.
- Fix: add vision status to the client forms from `vision_statuses` (clients area).

#### 19. Data quality and the documentation auditor are not in the apps
**Severity: Low**
- data_quality.php and doc_audit.php need only `clients` and are caseload-scoped. A staff member could use "what to tidy on my caseload" on the phone. The apps have Compliance and the note review (workflows review), not these.
- Fix: optional read-only list actions later. Not urgent.

#### 20. Branding and offices
**Severity: Low**
- `me` (api.php:2467-2478) doesn't send the organisation's name (Settings → branding), so the apps can't show it.
- Offices: the client's office is offered and saved the same way (api.php:1668-1687; form_options offices). The client list's office filter (clients.php:95-97) isn't in the API or apps (clients area).

### Settings honoured the same way (checked, no gap)
- Pricing rules: api_parity.php:598-600 `save_track` calls `pricing_mode_conflict()` and refuses on block, as track_edit.php:103-104 does.
- Public wording: in-person signing uses inc/signatures.php `signature_wording()` for both the website and the app.
- Time zone: `me.timezone`, used by iPhone (API.swift:1442) and Android (Api.kt:672).
- Counselor link days, consent and release validity, auth_soon_days on the dashboard query, and referral deadlines are all computed on the server.
- Permissions: invoices and contracts need billing, counselors admin, utilization analytics, bookings schedule. The website and the API ($API_ACTION_PERMS, api.php:570-626) agree, and both apps hide the menu items the same way (DashboardView.swift:196-214, MoreScreen.kt:63-68). The invoice list and submit are caseload-scoped as on the website.

### Website-only (reasonable), one line each
- funding_agencies, pricing, lists, wording, email_templates, referral_settings, settings, offices, users, admin, federation, phone_apps, system_health, update/upgrade, qbo, sms: one-time administrator configuration.
- agency_report, agency_scorecard, funder_report, business_summary, staff_report, analytics, custom_reports, unused_authorizations, capacity: period reports and tables built for a large screen and CSV.
- audit, exports/export, invoice batch generation, QuickBooks CSV: bulk or compliance downloads.
- vr_portal: done alongside the agencies' own vendor portals at a desk.
- portal.php: client-facing. Staff send the portal link from the apps already.

---

## Progress

- **4.34.0 (2026-11-19):** every High finding and every Medium behaviour difference (43 items, see DEPLOYMENT-4.34.0.md), plus the Android app lock (C2b).
- **4.35.0 (2026-11-19):** the 4.35.0 field casework section below (14 items, see DEPLOYMENT-4.35.0.md).

### Parity findings left for releases after 4.34.0

Source: PARITY-AUDIT-2026-11-19.md. Every Medium and Low finding not in plan-4340.md. Where 4.34.0 fixes part of a finding, only the part that is left is listed. Releases are suggested, ordered by how much field staff need them. Ids: `1.n` = audit 1, `2.n` = audit 2, `A1…D10` = area 3, `4.n` = audit 4, `5.n` = audit 5. M = Medium, L = Low.

#### 4.35.0: field casework (notes, clients, sessions)
- 2.9 (M) Group sessions: schedule a group and record attendance and complete it from the apps (`schedule_group`, `complete_group`).
- 2.10 (M) Choose the goal worked on and the service delivered when completing a session (the default already matches the website).
- 2.13 (M, rest) Android Log hours: service type, goal and session-note fields.
- 2.12 (M, rest) Android "Cancel the rest of the series" / "Cancel the whole group"; iPhone visible buttons (not only the VoiceOver Actions rotor) for Move, Cancel series, Cancel group.
- 2.14 (M) Offer times for a client request (`offer_times`); Android shows the linked session and due date.
- 1.11 (M) Android tech assessment list and "Record a tech assessment" form; the AI intake gets a date field (1.11 L part).
- 1.12 (M) Android "Load older notes (N more)".
- 1.13 (M) After closure: email the survey again, phone survey answers, 90/180-day follow-up checks (with 4.13).
- 4.13 (M) Outcome survey section on closed clients (same actions as 1.13).
- 1.7 (M, rest) Preferred language, vision status and onset age, On Hold return date on both client forms and in the `client` reply.
- 1.16 (L, rest) Client detail: VR case number, IPE goal, agency, guardian email on iPhone; client since, On Hold line, vision, attendance on both.
- 1.17 (L) Android note editor: track picker for a general note, Generate structured note from the typed note, homework instructions and due date, format/track/edited-by on rows.
- 1.18 (L, rest) Android homework due-date field.
- 1.9 (M) Client list filters (status, caseload, type, agency, school, office).
- 2.15 (L/M) Android homework edit (`at_homework_update`).
- 2.18 (L, rest) Bulk "record several as no-show or cancelled" (website-only is acceptable).

#### 4.36.0: daily workspace and safety
- C2 (M) Per-phone sign-out and "Report lost and wipe" in both apps (`app_devices`, `device_action`).
- B6 (M) Report a privacy incident from the apps (`report_privacy_incident`).
- A2 (M) Dashboard "Today" panel and My tasks panel.
- A3 (M) "Your week" line from `my_week`.
- A4 (M search, L filter) Android task search and due filter; iPhone due-filter menu.
- A5 (M, rest) Android Add task: client, Assign to, Edit, Open referral.
- A11 (M) Android alerts open their link.
- A13 / C3 (M) How alerts reach you: mobile number, urgent texts, alert email (`save_alert_settings`).
- B2 (M) Android incident client picker.
- B4 (M) Compliance: releases, no release, minors with no consent, device follow-ups due, overdue loans, contracts at risk, privacy notice.
- C5 (M) Android sign-in "Forgot your password?" and privacy policy links.
- C4 (M) Sign-in screens say how a Microsoft-only user gets a password; later an in-app SSO flow.
- C9 (L, rest) Android code field `.take(6)` and enabled only at exactly 6 digits.
- C11 (L, rest) Android code-step buttons "Verify and sign in" / "Start over".
- A6 (L) Admin delete task; complete several at once.
- A8 (L) Agreements, training and credentials banners from `dashboard` on both apps.
- A9 (L) Android hides client counts without the clients permission; expiring rows open the client.
- A12 (L) Undo "mark as read".
- A16 (L) Conversation list preview, unread first.
- A17 (L) "They were emailed that a message is waiting."; counselor-unconfirmed warning before typing.
- A20 (L, rest) Android follow-up stats and Recent list.
- B5 (L) Android funds-used line; compliance rows open the track.
- B8 (L) iPhone hold banner opens My training when only training is held.
- B9 (L) Signed copy reachable in the "due" state; corrections note in `agreement_copy`.
- B11 (L) Training count "N of M to take" counts every module not done (both apps).
- B12 (L) Credentials Notes field, API refuses over-long values, iPhone idem key and date check.
- C7 (L) Android passkey list and remove.
- C10 (L) Calendar sync and meeting connection status shown read-only.
- 2.20 (L) Calendar sync status in the apps (with C10).
- 2.16 (L/M) My availability in the apps.
- 2.17 (L) Signing in person: notify administrators on both channels or neither (owner decision which).
- 2.19 (L) Website Check in button for front desk.

#### 4.37.0: services, equipment and reports
- 4.7 (M) Equipment status: Mark in repair, Back in service, Retire with confirmation (`set_equipment_status`).
- 4.16 (M) Pre-ETS: delete a logged session; add cohort with dates, coordinator and notes.
- 4.15 (M) Device delivery adds to the loaner inventory; reason on deny/cancel; justification letter.
- 4.14 (M, rest) Purchase date, useful life, AT type on items added from the apps.
- 4.5 (M) Formal AT evaluation report with AI draft.
- 4.6 (M) Worksite assessment AI recommendations and report (owner AI rule).
- 4.10 (M) "Suggest goals with AI" on each assessment (owner AI rule).
- 4.11 (M) Android amendment request letter with AI draft.
- 1.15 (M) Record an amendment request as sent and record the reply (then wire `amend_request`, already accepted by save_track in 4.34.0).
- 4.9 (M, rest) Record progress per goal from the AI review.
- 4.12 (M) Letters (welcome, appointment, missed appointment) from the apps.
- 1.14 (M) Paper release, withdraw a release, record a guardian's consent withdrawal; privacy notice given.
- 4.17 (L) Android AT instructor matching and homework update.
- 4.22 (L) Tech assessment attachment and template prefill.
- 4.23 (L) Shared AT recommendation context; "Start a device request" from the result.
- 4.27 (L) Retired items listed under "Not available".
- 4.30 (L) AI draft for device justification and letters (website and apps).
- 1.19 (L) Shared case-summary context (10 notes plus assessments); "Suggest goals" and "Format with AI".
- 1.21 (L) Delete a document to the trash; share on upload; assessment attachments; `.heic` rule.
- 1.24 (L, rest) `review_referral` says "Nothing was changed…"; worksite AI and report; client timeline read-only.
- 1.20 (L) Portal link: the website's warnings and wording; "Turn off portal link".
- 1.22 (L) iPhone referral status filter and "assigned to me"; `contact_due` in `referrals`.
- 1.23 (L) Android pipeline "Closed in last 90 days".
- 4.28 (L) Curriculum: save from the apps (website-only is reasonable).
- 4.29 (L) AT Act missing loan details (website-only is reasonable).
- D4 (M, rest) Knowledge base "answer from general knowledge" option.
- D5 (M, rest) Translate "Other language" on both apps.
- D8 (L) Remediation: client's preferred format and the website's prompts and errors.
- D9 (L) Knowledge base: updated date, author, "Shared with clients", empty-list text, Android numbered lists.
- 2.21 (L, rest) Voice booking: remote and meeting link.

#### 4.38.0: billing and administration
- 5.4 (M, rest) Android invoice paging, unbilled figure, track and authorization on rows.
- 5.5 (M) Invoice filters (agency, month, text, id); invoices in search on the apps.
- 5.6 (M) Incoming authorization emails: list, start the track (`incoming_auth`, already accepted by save_track), dismiss.
- 2.8 (M) Several sessions at once (plan patterns) and "Make the meeting link" in the booking form.
- 5.10 (L) Outstanding: past due and part-paid; days overdue per row.
- 5.11 (L) Open the invoice (accessible HTML) from the apps.
- 5.12 (L) Counselor portal: shared email text, failure handling after the email, Android expiry and last-used dates, Android error clearing.
- 5.13 (L, rest) Contracts: Notes, 10-digit budgets in the forms, audit wording, change budget, close/reopen, delete deliverable/spend.
- 5.14 (L) Access bookings: show and restore cancelled; old → new in the audit; CART label; 8-digit cost.
- 5.15 (L) Utilization wording ("Team utilization", no bands, "at or above target"); remembered period.
- 5.7 (L) One-line note on Android that payments are recorded on the website.
- 5.18 (L) Vision status from `vision_statuses` (with 1.7); iPhone fallback note types.
- 5.19 (L) Data quality and documentation auditor lists (read-only).
- 5.20 (L) Organisation name in `me`; office filter (with 1.9).
- 2.7 (L, none left) all bullets are in 4.34.0 (P14).
