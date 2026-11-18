# Release 4.33.0 — November 18, 2026

Web 4.33.0. The phone apps stay at 4.32.0: the iPhone app's source carries one fix (below) that reaches phones with the next app build. Apply dist/migrations/mig_4.33.0.sql, or use Database upgrade; Software update applies it on its own. It adds:
- the table calendar_accounts (a staff member's CalDAV or Google calendar; the password or key is encrypted with meeting_tokens.key)
- users.calendar_kind (microsoft, caldav or google; set to microsoft for everyone whose sync was already on)
- calendar_links.kind (which kind of calendar each event was made in)
- schema_version 4.33.0

**Not deployed with deploy.sh.** It is to be published as the GitHub release v4.33.0, and an administrator installs it with Administration, Software update. Nothing has reached a live server.

**Why.** On 2026-11-18 the maintainer asked for the calendar sync to match the Full Circle CMS website kit: three kinds of calendar, each tested end to end against a stand-in service, and personal Microsoft accounts that work.

**What changed**
- **Three kinds of calendar** (My Account, My calendar; inc/calendar_kinds.php):
  - **Microsoft**, through the person's Microsoft connection, now a work account or a personal Outlook.com account.
  - **CalDAV**: iCloud, Nextcloud, cPanel's Calendars and Contacts server (port 2080), Fastmail. The person enters the server address, user name and an app password. The calendars are found through current-user-principal and calendar-home-set; only calendars whose supported-calendar-component-set includes VEVENT are offered (a tasks-only calendar and the scheduling inbox are not). With one calendar it is chosen at once; with several, the person picks. Busy times come from a calendar-query REPORT with a time range, with repeats (RRULE) expanded. Events are written with PUT and If-None-Match: *, removed with DELETE, and a 404 on delete counts as already gone. A wrong password is said in plain words.
  - **Google Calendar**, through a service account the person shares their calendar with: a JWT (RS256) signed with the key file, freeBusy for busy times, events made and deleted on the shared calendar. A calendar that is not shared (notFound) is explained as such, at connect time and in the sync.
  - The password or key is kept encrypted with meeting_tokens.key, as the meeting tokens are. System health's key checks and "Forget stored connections and keys" cover it.
- **Personal Microsoft accounts** (Settings, Online meetings, "Microsoft accounts staff may connect": work, personal or any):
  - `personal` signs in at the `consumers` address, `any` at `common`; `work` keeps the tenant ID (or organizations).
  - For personal or any, the sign-in asks for `openid email offline_access Calendars.ReadWrite` only: Microsoft refuses Teams or User.Read on a personal account. Teams links are not made for them.
  - The account's address comes from the ID token (email, else preferred_username). Graph /me is asked only for a work account that granted User.Read: a personal account's calendar token gets 403 with no message there.
  - The refresh token is kept per staff member; the access token is cached and renewed. A token refused before it expired (401) is renewed once and the call repeated; a refresh token Microsoft will not renew ends the connection, said in words.
  - MICROSOFT-SETUP.md: supported account types "any organizational directory and personal Microsoft accounts", `requestedAccessTokenVersion` 2 in the manifest (the Authentication page refuses the change otherwise), the exact redirect URI, delegated Calendars.ReadWrite.
- **The sync routes by kind** (inc/calendar_sync.php): sessions go into whichever calendar the person connected, with the same event content and the same privacy setting for the title. Events made in a calendar of another kind are forgotten when the person switches, after the old calendar's events are removed.
- **Test the connection** on My Account names the account or calendar and the number of busy times in the next 7 days.
- **One HTTP helper** (meeting_http) serves Zoom, Microsoft, Google and CalDAV, now returning the raw body and response headers too, with a test hook.
- **Wording:** notices say "your calendar" rather than "Outlook" for the other kinds; the help page's calendar section covers the three kinds.
- **iPhone app, Generate structured note** (commit 6a05807, found by the maintainer on 2026-11-18): the button in the Add note sheet was dimmed until the separate "Paste raw notes here" box had text, so a rough note typed or dictated into the Note box could never be generated: no request, no spinner, no error. The app now generates from the raw-notes box when it has text, otherwise from the note as typed, as the website does; the button is dimmed only while generating; with nothing to work from it says so. Server side, ai_generate_case_note() refuses a provider reply that held no section text instead of answering with four empty sections. The app source kit (inc/appkit/app-source.zip) is rebuilt to match. The iPhone app's version is not bumped here: building and shipping the app is the owner's step.

**Tests**
- tests/v433_calendar_cases.php (run by full_schema), each kind against a stand-in service with every request recorded:
  - Microsoft: the consumers address and calendar-only scope for personal accounts, the account's name from the ID token with no Graph /me, the renewal's fields (refresh token, the app in the body, the granted scope), calendarView across pages with cancelled and free events skipped, create with transactionId, delete, a 401 renewed once, an expired refresh token in words, Test the connection on My Account.
  - Google: the JWT verified with a generated RSA key, freeBusy, our events listed by their private mark, create (private, no guests), the cached token reused, a 401 renewed once, notFound explained, delete with 404 as gone, connecting on My Account with the key kept encrypted.
  - CalDAV: discovery through principal and home leaving VTODO and the inbox out, the REPORT time range, a weekly repeat expanded, PUT with If-None-Match, a rewrite on move, DELETE with 404 as gone, a 401 in words, connecting on My Account with the password kept encrypted and never shown.
- tests/ios_api_regression.py (three static checks on the Add note sheet), tests/workflow_cases.php (the ai_generate_note action through the AI stand-in: shape, labels, BIRP for a free-format note, redaction, the three refusals) and tests/ios_models.py (decoded by the app's GenerateNoteResponse), for the iPhone fix.
- tests/v432_meetings_cases.php: the work-account scope now includes openid and email. tests/v420_cases.php: its check of the renewal's scope line follows the new code.
- tests/v430_cases_cases.php: the note it inserts is now dated three days ago instead of 2026-10-03. A note locks 30 days after its date, so from early November that fixed date made the "Edit link names the note's date" check fail on every run. This was found while running the suite for this release and is not a calendar change.
- Checked before commit, on an empty MariaDB database with PHP 8.5 locally (the servers run PHP 8.1; the code uses no PHP 8.2 or later feature, and CI runs the same suites on 8.1):
  - full_schema (2879, with the new checks) passes
  - security, audit_regression and staging_scrub pass
  - php -l over public/, public/inc/, cron/, dist/ and tests/ passes
  - hygiene_static passes
  - not run here: run_all.sh (the Node, Python and installer suites) and the browser accessibility check; CI runs them on push

**How a staff member connects each kind**
1. Microsoft: an administrator sets "Microsoft accounts staff may connect" on Settings, Online meetings. On My Account, connect Microsoft under Online meetings, then choose Use my Microsoft calendar under My calendar, then Put my sessions in my calendar.
2. CalDAV: make an app password in the calendar account (iCloud: appleid.apple.com, Sign-In and Security, App-Specific Passwords; Fastmail: Settings, Privacy and Security, Integrations; Nextcloud: Personal settings, Security; cPanel: the email account's password works). On My Account, enter the server address, user name and app password, choose Connect the CalDAV calendar, pick the calendar if asked, then Put my sessions in my calendar.
3. Google: in Google Cloud, make a project, turn on the Google Calendar API, add a service account and download a JSON key. In Google Calendar, share the calendar with the service account's email address with "Make changes to events". On My Account, enter the calendar's address (the Gmail address) and paste the key file, choose Connect the Google calendar, then Put my sessions in my calendar.

The iPhone fix's own checks (the iOS suite, 100 checks, and the Xcode simulator build) passed in the session that made it; the full database suite passed again with both changes in place.

## Published (not deployed)

2026-11-18, after CI passed all three jobs on d9bfad0 (PHP 8.1 and MariaDB suites, the iOS simulator build, the Android build). The GitHub release v4.33.0 (latest, not a pre-release) carries:
- the install bundle, built from a clean checkout of the tag (423 files)
- the signed update package: 338 files, two of them new (inc/calendar_kinds.php and the 4.33.0 migration), nothing removed; verified with the signing key, verified again with the public key built into the updater sites already run, and verified a third time after downloading it back from the release.

**Not rehearsed:** unlike 4.32.0 and 4.32.1, the update was not installed with the previous updater onto a copy of a 4.32.1 site before publishing. The migration itself was exercised by full_schema (a 3.0.7 database brought to 4.33.0, and every migration run again on an up-to-date one).

The live site stays as it is until an administrator installs it with Software update.
