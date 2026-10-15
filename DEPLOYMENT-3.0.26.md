# Release 3.0.26 — October 15, 2026

Full code audit (four reviewers: sign-in/API, staff pages, back office/cron/public, iPhone) and WCAG 2.2 audit (three reviewers: shell/scripts, client-facing pages, staff pages) over the whole codebase, then fixes. No database migration; website and cron files only. Production baseline: 3.0.25 (346f06d).

## Code audit results
No high-severity findings. The sign-in, token, CSRF, caseload-scope, SQL binding, download, mail and AI-endpoint code was reviewed end to end and found sound (details in the audit reports summarised below).

Fixed:
- MEDIUM — api.php verify: a wrong code for an address with no sign-in pending no longer counts against that address (it counts against the sender's IP only), so nobody can lock a staff member's two-step sign-in without the password. Regression test added.
- Password-step challenge is now required on the app's verify step (every app build in use sends it).
- forgot.php: password-reset requests capped at 3 an hour per account as well as 5 per IP.
- inc/at_services.php: the billing permission no longer stands in for every AT module (it had unlocked admin-only labor rates and other instructors' profiles); Service profitability is now a billing module.
- cron/session_reminders.php takes a run lock like the other mailing crons (no double reminders from overlapping runs).
- ack.php: receipt links expire after 180 days; the recipient address is masked on the page.
- Task completion/reopen is audited (website and app); task assignees and cohort coordinators must be active accounts.
- iPhone: creates whose reply is lost tell the user to check before retrying (no double filing); a Schedule row's double-tap no longer opens the signature pad; cancelling the pad without signing no longer locks the app; the amendment-letter copy is cleared at sign-out; meeting links must be https.

Deferred (hygiene, listed for a later release): idempotency keys on a few more create forms; consent request race (two tabs); array-input TypeErrors on some admin forms (already a clean 400).

## WCAG 2.2 audit results
Conformant across the checked criteria (full list in the reports). Fixed:
- 1.4.13: hover-shown link hints now close with Escape whether they came from focus or the pointer.
- 4.1.2: aria-keyshortcuts uses ARIA key names (Control+Alt on a Mac); the visible hint names the keyboard keys and, on a Mac, the VoiceOver pass-through; one announcement channel per link.
- 4.1.3: a flash shown with a form error summary is read with it instead of being lost; Low-vision controls announce what changed ("Dark. Display updated.").
- Quick keys on every main menu link (29 keys; see the user guide).
- Staff pages: one required-field marker helper; unique link names for same-named clients/referrals; intake prefilled from the client record; duplicate labels scoped on schedule, equipment and tasks; filter buttons named consistently; field-level errors on four forms; NPS table visible; count message not styled as an error; action-centre session items land on the session's Mark completed control.
- Client-facing: a filled bot trap no longer shows a false "Referral sent"; referral purpose hint split into a list; required markers on consent signature, portal practice fields and referral purpose; status message when the referral form switches sections; portal heading level; verify-code expiry wording.
- Action centre wording: "session ended: mark it completed on the schedule (this logs the hours), then write the session note".
