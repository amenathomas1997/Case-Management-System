# Release 3.0.52 — October 16, 2026

Web 3.0.52. The iPhone app is unchanged (3.0.46, build 45). Apply dist/migrations/mig_3.0.52.sql before updating files. It adds the portal_request_offers table.

This finishes the roadmap item "Clients choose from offered times in the portal".
- **Staff side** (portal_requests.php, Offer times). On an open appointment or reschedule request, staff enter:
  - up to three times
  - the length (a reschedule keeps the session's length)
  - who will run the session
  - the track (for a new appointment)
  - remote, or the location
  Before a time is offered, it is checked:
  - it must be at least an hour away
  - it must not clash with the instructor's calendar
  - it must be within the authorization (workflow_authorization for a new appointment; dates and track status for a move)
  A time that fails a check is refused, with the reason. A client with no working portal link cannot be offered times. Staff are told when the client was not emailed because they prefer phone or post.
- **Client side** (portal.php).
  - The client is emailed (times_offered, with a Spanish version), sees a "Choose a time" radio group, and confirms one.
  - Confirming runs in one transaction, with the request locked. It re-checks the time, then books the session or moves it, withdraws the other times and closes the request.
  - After that commits, the request's task is closed, the client gets the calendar invitation, and the instructor gets the moved-time calendar update and a notice.
  - A time that is no longer free, or now too soon, is expired and the instructor is told.
- **Tests:** tests/request_offers_cases.php is new.

Review: one reviewer found no security issue. Every finding was fixed:
- the instructor's calendar is updated on a move
- a move keeps its length, so the hours check holds
- only one time per request can be booked
- confirming is one transaction
- staff are told when the client was not emailed, and there is a check for a portal link
- a session that was cancelled meanwhile is handled
- the "too soon" wording is right
- there is Spanish for the new messages
- refills go only to the right row
- the confirm button names its request

Not changed: the "With" list shows every active staff member. The export of the client file does not include offers; the request itself is exported.

Verified before commit:
- full_schema 1265, audit_regression, security, staging_scrub, and run_all.
- The migration rehearsal matched.
- Not checked in a browser or with a screen reader.

## Deployed 2026-10-16 15:44 CT
From 3.0.51 with .audit_work/deploy-3.0.52. mig_3.0.52.sql ran, and the new table is present.

CI is green. The syntax check passed, every checksum matched, .htaccess was unchanged and every smoke check passed (health reports 3.0.52). Backup: /home/cmsuser/casemanagement_private/deploy-3.0.52-20261016-154422.
