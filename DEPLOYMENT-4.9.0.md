# Release 4.9.0 — October 16, 2026

Web 4.9.0 (no iPhone app change). Apply dist/migrations/mig_4.9.0.sql (schema_version only), or use Database upgrade.

**Multi-session scheduler.** The maintainer chose (2026-10-16) several days a week, a list of dates, and plan to fill an authorization. The schedule already had a weekly repeat and group sessions.
- **The form.** Schedule a session has a collapsed "More than one session" section, so a single booking has no extra stops. It holds How often, Days of the week, Last date, and Dates (one per line, each optionally with its own time).
- **inc/session_plan.php** turns the choice into the exact sessions:
  - weekly, as before (1 to 26 weeks)
  - several days each week, until a last date or for a number of weeks (up to 52)
  - a list of dates: within two years, not in the past, sorted, repeats dropped
  - spread over the authorization: from the first date to the track's expiration, on the chosen days, stopping before its remaining hours (authorized, less logged and already booked) run out
  - at most 100 sessions
- **Preview and confirm.** Any pattern other than weekly is listed first (the dates and total hours). It is booked only when "Book these N sessions" is ticked for that exact list (a fingerprint of the client, instructor and times). Otherwise nothing is saved.
- **Booking** uses the existing path:
  - the authorization review counts the exact sessions (workflow_authorization takes the list)
  - the duplicate check
  - one transaction and one series_uid, so "cancel this and later sessions" works
  - the client's invitation, and the instructor's calendar invite with every session
- The staff guide explains it.
- **Tests:** tests/v49_cases.php is new. It covers each pattern, the authorization cut-off, preview before booking, the confirmed booking as one series, and refusal of a mismatched confirmation.

Review: every finding was fixed:
- **Approval and confirmation together.** A plan that needs both the authorization approval and the list confirmation shows both boxes together; the approval stays ticked while the list waits. Before, the two could never be confirmed together and the booking looped.
- **Track hours.** "Spread over the authorization" reads only the client's own track, so another client's hours are never shown.
- **Fill** starts at the authorization's start date when the typed date is earlier, and says so if it would pass 100 sessions.
- **Weeks.** The weeks box allows 52 for several days a week, and that error is reported on Repeat weekly.
- **The confirmation fingerprint** also covers the track and the pattern.
- **Wording and focus.** The error summary links to the list's heading, and the message names the box as it reads ("Book these 4 sessions"). Labels are shorter.
- **Tests.** The mismatch check runs before booking. A past date this year is tested, as are weekly unchanged and the approval-plus-confirmation case.

Verified before commit:
- full_schema 1615, audit_regression, security, staging_scrub, and run_all.
- The migration rehearsal matched.
- Not checked in a browser or with a screen reader.

## Deployed 2026-10-16 21:57 CT
From 4.8.0 with .audit_work/deploy-4.9.0 (9 files), at c1eae45. mig_4.9.0.sql ran (1 statement). Production files matched 4.8.0 before install and match 4.9.0 after; .htaccess unchanged. Smoke checks passed; health reports 4.9.0. The deploy script was started again by mistake afterwards; its checksum check stopped it before any backup or change (one backup folder, live still 4.9.0). Backups: <private dir>/deploy-4.9.0-20261016-215723.
