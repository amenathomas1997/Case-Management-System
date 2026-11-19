# Release 4.34.0 — November 19, 2026

Web 4.34.0, iPhone app 4.34.0 (build 65), Android app 4.34.0 (version code 18). No database change; the database stays at 4.33.0.

**Not deployed with deploy.sh.** It is published as the GitHub release v4.34.0, and an administrator installs it with Administration, Software update. The phone apps are built from this tag.

**Why.** On 2026-11-19 the maintainer asked that the iPhone and Android apps match the website and behave the same way.
- **The audit:** PARITY-AUDIT-2026-11-19.md compared every website page with both apps and the app API. It found 19 High, about 64 Medium and about 68 Low findings.
- **The maintainer's choice:** "High first, then rest". This release fixes all High findings, every Medium one where an app or the API behaves differently from the website, and Low fixes in files already being changed. Features the apps simply lack follow in later releases (see the end of this note).

**Maintainer decisions taken with defaults (2026-11-19)**
- **Duplicate clients:** new app versions are refused until "This is a different person" is chosen. Phones still on an older version keep the old save-then-warn behaviour, so nobody gets stuck.
- **Android app lock:** included. It works as the iPhone's does: fingerprint, face or the phone's PIN when the app opens and after 2 minutes away, with nothing of the client data visible behind it.
- **Left as they are:** assessment dates (the API allows tomorrow for time-zone slack), and emails to administrators when a client signs in person.

**What changed (43 items; a sharing note follows the list)**
- P1: Android: edit a case note, add an addendum, keep the text when a note locks
- P2: Section text lost when the format is set back to Free text
- P3: iPhone client form: date of birth, school, IPE dates; Android IPE dates; On Hold reason marked required; DOB and client type shown
- P4: New-client duplicate check refuses until "This is a different person"
- P5: The note's session warning and homework count are shown
- P6: Consent request cannot replace a colleague's just-sent request; field errors tied
- P7: Search finds what the website finds
- P8: Completing a session credits the instructor with the website's description
- P9: iPhone can complete a session when hours are already logged that day; Move sheet error form
- P10: Closing a client request withdraws its offered times
- P11: Track edit: Who pays, dollar amount, RSA default, amendment link, money format
- P12: Booking refuses a session already on the schedule
- P13: Waitlist clients cannot be booked
- P14: Booking details as the website
- P15: Schedule actions: confirm Cancel/No-show, hide before start, Move on ended sessions, check-in window, no hours without a track, list cut said
- P16: Log hours: travel-only, note-only, two decimals, homework reminder
- P17: Abandoned-device task never goes to a deactivated account
- P18: Device follow-up: Unknown answer, required answer, confirmations, the "No" sentence
- P19: Android marks only the alerts it listed
- P20: Tasks: the website's title wording; done tasks are not "overdue"
- P21: Android opens a conversation on the channel with the unread messages, and always offers the counselor
- P22: Incident types are the agency's list; no future date; reporter shown
- P23: Compliance lists guardian consents that ended at 18; Android gets the consents section
- P24: After signing the last agreement the hold lifts at once; name errors tied
- P25: iPhone tells the truth about emailed codes
- P26: Android asks before replacing the calendar address; authenticator setup order; role shown; password errors tied
- P27: Sign-in code step: a code that is not 6 digits does not use a try; the website's wording
- P28: Ask your caseload: last-note dates and incomplete notices
- P29: Translate gets the language name; Android says when a translation is incomplete
- P30: Knowledge-base assistant finds the articles the website finds
- P31: Curriculum generator drafts the website's lesson plan
- P32: Outcome risk flags use the period and say when cut off
- P33: AI tools say whether client identifiers are stripped
- P34: AI progress review sends the website's context
- P35: Progress report matches the website, with AI drafts
- P36: Reopening a device request returns it to its furthest step
- P37: Loan return date follows the administrator's loan period; condition going out "Not recorded"; return condition list
- P38: Reservations: no past "needed until", queue order, iPhone "needed until", promotion said
- P39: Equipment and assessment inputs refused as the website refuses them
- P40: Utilization value leaves out no-charge work
- P41: Marking an invoice submitted asks for the date sent; due shown only when Submitted; errors cleared
- P42: iPhone dashboard says the administrator's "authorization ending soon" days
- P43: Client list and small client-side fixes in files already changed

**Shared code, so the website and the apps can't drift apart again.** Pieces of page code moved into shared helpers that the website page and the API both call:
- the session-log description and review messages (inc/session_changes.php)
- new-track defaults (inc/pricing.php)
- knowledge-base search (inc/kb_search.php)
- the curriculum lesson plan (inc/curriculum_ai.php)
- the risk prompt (inc/risk_ai.php)
- the progress review (inc/progress_ai.php)
- the report AI cover note (inc/report_ai.php)
- reopening a device request (inc/portal_devices.php)

**Older apps keep working.** Every new field is optional for them:
- a 4.32.0 app's progress report still covers the whole track
- its loans without a date get the usual loan period
- its Translate language codes are still understood

**Repository.** tests/brand_guard.php now fails if a former organisation's name, address or app identity appears in any tracked file, archives included. A note in DEPLOYMENT-4.33.0.md had brought one back; it is fixed.

**Tests**
- New: tests/v434_parity_cases.php (server and website), new checks in tests/ios_api_regression.py (146 in all), android V434Test.kt (47).
- Checked before commit:
  - full_schema (2975), audit_regression, security, staging_scrub and run_all pass
  - the browser accessibility check passes on 360 pages
  - iPhone: builds with no warnings
  - Android: build, 136 unit tests and lint (0 errors) pass

**Review.** Three independent reviews (server, iPhone, Android) checked each side against the others. Everything they found was fixed before commit, then all tests were run again:
- **Android, High:** turning the phone while the unlock prompt was up could leave the app locked until it was restarted.
- **Android, other fixes:**
  - the lock screen now offers Sign out
  - dialogs are hidden behind the lock
  - no prompt loop on Android 8 and 9
  - no false failure after signing an agreement
  - a note-edit conflict can be reloaded
- **Server, Medium:** an AI report draft did not check the reports permission.
- **Server, other fixes:**
  - older apps keep their whole-track report
  - the one-day time-zone slack is back for equipment service and incident dates
  - the "caseload cut" notice was wrong at exactly 80 clients
  - more tests, and the tests now put back their settings
- **iPhone, Medium:**
  - a homework date error could point at the wrong item
  - "hours already logged" was refused when miles were entered
  - choices made after a refusal could use old values
  - only one of several field errors was said
- **Both apps:**
  - the choice is now worded as on the website: "Completed — log hours as well"
  - field errors now follow the server's error keys instead of its wording

**Later releases.** These come next, most needed first:
- client list filters
- My availability and offering times to a client request
- the evaluation report and letters
- setting the mobile number and how alerts reach you
- signing out or wiping a lost phone
- the dashboard's Today and Your week
- incoming authorization emails
- surveys and follow-up checks after closure
- equipment status changes

## Published (not deployed)

2026-11-19, after CI passed all three jobs on 84f1aa7. The GitHub release v4.34.0 (latest, not a pre-release) carries:
- the install bundle, built from a clean checkout of the tag, including the four new shared files
- the signed update package: 342 files, verified

**Rehearsed:** installed with the 4.33.0 updater onto a copy of a 4.33.0 site.
- The signature verified, the plan showed no problems, and the backup was made first.
- 23 files were replaced or added, with no database change.
- The sign-in page then served 4.34.0.

The live site stays as it is until an administrator installs it with Software update. The 4.34.0 phone apps are built from this tag.
