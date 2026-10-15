# Release 3.0.23 — October 15, 2026

3.0.22 was never deployed; 3.0.23 ships everything committed as 3.0.22 plus the final code audit fixes below.

Status: committed, NOT deployed. Production is 3.0.21 (deployed October 14, 2026 from 17ce95e).

No database migration. Deploy the changed website files only; preserve hosting .htaccess, private configuration, installer lock and custom branding assets. Run the usual read-only checksum comparison against production (expect 3.0.21 = 17ce95e) and back up the docroot before publishing. Includes cron/session_reminders.php (deploy it with the website files). iOS: ios/project.yml is 3.0.23 build 30; distribute through an Xcode Archive to TestFlight.

## Contents

Production audit of the ChatGPT/Codex work since c4d0956 (three reviewers; no high-severity findings), plus link and screen-reader clean-up:

- Breadcrumb navigation on staff pages (new public/inc/navigation.php; client, client edit, track and track edit pages name their parents).
- The Go back / Dashboard / main-menu shortcuts and breadcrumbs appear only for signed-in staff, not on the client portal, referral, consent, survey or sign-in pages. No empty breadcrumb landmark on the Dashboard.
- "Low-vision controls" is a real heading again (moved out of the disclosure button); the disclosure button reads "Show display options".
- The "Main navigation" shortcut link is now "Go to main menu", so it no longer sounds like the Main navigation landmark.
- Voice assistant (web): after an unclear answer to a confirmation, only a bare yes or no answers it; any other input cancels the waiting change instead of saving it. Silence message names Start conversation.
- Accessible HTML documents that are emailed or zipped no longer contain CMS links; staff downloads keep them.
- Unsaved-change protection keeps the browser warning when the session check refuses a submission; Go back ignores a #fragment when comparing pages.
- Required dropdowns stay dropdowns when "Show dropdown choices as lists" is on.
- Device follow-up "Still in use?" uses a native fieldset and legend.
- Referral and waitlist links include the date received, so look-alike referrals have distinct names.
- RSA-911 CSV no longer appends a trailer row after the data; invoice list heading no longer double-escapes; audit.php docblock order; release notes keep the migration chain.
- iOS (not distributed): VoiceOver confirmations say how to answer; goals read "Goal 2 of 5" instead of database ids.

## Usability and screen-reader update (second commit)

Full user-experience audit (staff daily work, client-facing pages, admin, iPhone app), then safe quick wins — ordering, wording, defaults and collapsing; no schema, permission or save-logic changes.

Navigation and structure:
- Main menu grouped under headings: Today, Clients, Services, Equipment, Billing, compliance & reports, Help & account (cms_navigation_groups()). Each menu link and the top shortcuts have a one-sentence hint as the link's description (read by screen readers after the name; shown visually after a pause on hover or keyboard focus; Escape hides it).
- Forms that start collapsed (Add a task, Schedule a session, Add a note, Add equipment, etc.) have a heading before them so H still finds them; they open themselves on errors, restored drafts or a matching #link.
- Staff guide documents the menu groups, hints and collapsed forms.

Staff pages:
- Training track: "On this page" links; Log a session moved up after the totals; billing exports and the SMART-goal template picker collapsed; "No authorization entered" instead of 0 hours / 0%.
- Lists before add-forms on Tasks, Schedule and client Notes; Notes show the newest 20 with Show all.
- After completing a session, a link to write its note on the track.
- Daily action center grouped by Overdue / Due today / This week / Later / No date, readable dates, duplicate referral items merged; documentation review links straight to the flagged note; Homework support has its own accurate note.

Admin pages:
- Templates split into four libraries (?lib=); the goal library lists rows only after a search or area choice.
- Equipment: search (name, serial, borrower) and status filters; loan/add forms collapsed; stat cards link to filtered views.
- Lists first on Incidents, Contracts and Device purchases; purchase detail shows only the next lifecycle step (others under More actions); detail headings name the record type with breadcrumbs.
- Business analytics lists every report the viewer can open; audit log filters by user, action and dates (default last 7 days).
- Page headings match menu names (Equipment & loans, Incident reports, Device purchases, Business analytics, Goal & assessment templates).

Client-facing pages:
- Referral confirmation shows the referral number and the date to expect contact; Referral purpose asked only of counselors and agencies (others file a new referral); error messages start with their field labels; plainer income hint.
- Client pages show the organization name without "Case Management" or the version.
- Clearer expired-link messages (portal, survey, reset), "Your training plan" headings, a friendlier unavailable-feature message, Update my details collapsed, consent wording and contact, counselor-portal empty state, amendment letter grammar and signature, session reminders point to the portal and contact email.

iPhone app (not distributed):
- Log hours from a client or a track (preselected), as a primary Schedule toolbar button and with a Log button at the top; hours can be typed.
- Every stepper is one adjustable VoiceOver element (swipe up or down) — AccessibilityHelpers.swift.
- List rows across the app are one VoiceOver stop with their buttons in the Actions rotor (schedule, goals, notes, equipment, reservations, follow-ups, bookings, contracts, homework, incidents, counselors, programs, offline entries); destructive actions keep their confirmations. Trade-off: Voice Control can no longer tap buttons inside those rows by name.
- Note field before the AI helper; message and follow-up drafts persist; equipment search and sections; schedule grouped Today / Tomorrow / Later.

## User guide, privacy notice, iPhone account settings and parity (third commit)

- Staff guide rewritten as task-based "How do I" topics (68 topics, table of contents) including iPhone, billing, reports, voice, shortcuts and privacy; public help updated.
- New public privacy notice (privacy.php), linked from every footer and from the iPhone app and its sign-in screen. AI provider, redaction and retention statements are read from live settings. Owner must confirm legal entity, address, jurisdiction, privacy contact, hosting, and HIPAA/FERPA applicability.
- iPhone Account screen: password, two-step sign-in, passkeys (add on the website), calendar address, app lock and voice settings, offline entries, sign out / sign out everywhere, user guide and privacy. Server: inc/account_actions.php (shared with account.php, behaviour unchanged) and inc/api_account.php.
- Parity: inc/api_parity.php and iPhone screens for tasks, alerts, portal requests, intake progress, invoices (read-only), add/edit training tracks, consent requests, consents to renew and status history.

## Final code audit (five reviewers over 17ce95e..HEAD) and fixes

No high-severity web or API findings. Fixed:
- iPhone Edit track and Edit task no longer save the loaded server values as a "draft" (a later open could restore stale values and, for tracks, re-price work); Cancel without changes clears the draft.
- Track progress bar follows a just-saved authorization; odd fractions of hours are read correctly.
- API: save_track keeps mileage rate and dates when a field is left out; save_task keeps an unchanged client link (and its scope); consent requests limited to 10 an hour per staff member; reauth lockout answers 403 so the app does not replay a wrong password; pending authenticator setup cleared on password change and sign out everywhere.
- Rows whose actions are only on the Actions rotor have a safe default double-tap (follow-ups: Record); Tasks Done guarded against double taps; invoices agency rows have unique ids; password change warns that drafts are discarded; voice confirmation hint never interrupts VoiceOver.
- Web: client Notes heading order; a waitlist follow-up date stays in the action center; array-safe search inputs; referral receipt shown once; help and privacy pages count as client-facing; page shortcut buttons keep 44px targets; session reminders keep "your instructor" in custom wording and list {portal_link} and {contact}.

## Verification

- PHP syntax check of every production file passed.
- tests/run_all.sh passed (Node, PHP, Python and Swift API checks, including the new breadcrumb test).
- Local cms_audit_test: full_schema 823, audit_regression 61 and security 63 assertions passed.
- iOS: clean simulator build succeeded with no warnings; ios_api_regression passed.
- Rendered all 128 page snapshots: no duplicate ids, every hint description resolves, no same-named links to different places except identical synthetic test records. Visual check of the grouped menu and a hint in Chrome.
- Test updates were wording/title only (page markers, the portal's unavailable-feature message, the referral role error).
- Not done: manual JAWS (Chrome/Edge), NVDA and VoiceOver walkthroughs — especially the grouped menu and hints, collapsed forms, the unsaved-change dialog, the voice confirmation, and the iPhone Actions rotor and adjustable steppers.

## Deploy file list (relative to 3.0.21, live from 17ce95e)

`git diff --name-only 17ce95e HEAD -- public cron`
