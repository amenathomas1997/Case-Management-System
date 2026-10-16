# Release 4.0.0 — October 16, 2026

Web 4.0.0, iPhone app 4.0.0 (build 46). Apply dist/migrations/mig_4.0.0.sql (users.alert_email; equipment_loans condition_out, condition_in, accepted_name, accepted_at; schema_version 4.0.0), or use Database upgrade.

This release covers the roadmap section "Voice, accessibility and the iPhone app". The owner asked for the version to jump to 4.0.
- **Voice without AI** (inc/voice_more.php).
  - Common requests are matched without the AI: help; today and this week; tasks due; unread messages and alerts; new referrals; open requests; unbilled work; unpaid invoices; expiring authorizations; caseload; follow-ups.
  - For a named client: phone, status, next session, hours left, notes, goals and homework. It can also search, open a page or open a client.
  - Cancel and no-show change the client's next session, after confirmation, through session_set_outcome (the same path the app uses).
  - Voice is available when setting ai_feat_voice is on, even with AI off. With AI off, an unmatched request says what still works.
- **Alerts.**
  - My account, "Email me about alerts": Urgent ones at once, and daily; Once a day; or Never. Everyone starts on Never, so nobody gets new email without choosing it.
  - Urgent alerts (a portal request or message, a homework call for help) are emailed at once at most every 15 minutes. The daily email counts unread alerts. Neither names the client.
  - The calendar feed adds a reminder 30 minutes before each session. Access bookings are titled "Support booking". My account has a Subscribe in Calendar link.
- **Call and directions links**: tel_link and directions_link on the client, clients, referrals and schedule pages.
- **Bulk actions**:
  - Session schedule: no-show or cancelled for ended sessions.
  - Tasks: done.
  - Invoices: drafts submitted.
  - Client portal requests: close (closing also withdraws offered times).
- **Equipment hand-off**: the loan records condition out and who accepted it; the return records condition in.
- **API**:
  - submit_invoice (billing).
  - App tokens within 7 days of expiry are renewed to 30 days on use.
  - schedule returns client_id and user_id; client returns school.
- **iPhone app**:
  - My sessions only.
  - Directions and open-the-client from a session.
  - Address, school and counselor phone on the client record.
  - Client records readable offline.
  - Draft invoices marked submitted from the Actions rotor.
  - Equipment condition.
  - Update notice on the Dashboard; calendar subscription link.
- **Tests:** tests/v4_cases.php is new.

Not in this release (needs an owner decision or later work): authorization create and edit in the app, Microsoft or passkey sign-in in the app, documents in braille or audio, fuller offline queueing.

Review: no SQL injection or CSRF gaps. Every finding was fixed except one, kept on purpose:
- voice billing answers (unbilled work, unpaid invoices) count only a caseload-limited person's own clients
- app sign-ins renew in use but never past 90 days from signing in
- No Show is refused before a session starts, in every channel (session_set_outcome)
- only a client's portal message or request, and a homework call for help, are urgent; routine portal activity is not
- voice question patterns are anchored, so a note that mentions "managing" or "expiring authorizations" goes to the AI
- pronouns are never read as a client's name, and no-show needs "mark" or "record"
- the voice confirmation names the instructor when the session is someone else's; group sessions are never changed by voice
- the bulk no-show/cancel refuses group sessions on the server and asks to confirm
- "now" users also get the daily count, so an alert held back by the 15-minute limit is still mentioned
- alert email starts at Never (column default 'off'), so nobody gets new email without choosing it
- voice follows whether AI is on until an administrator sets its own switch, so sites without AI get no new button
- the My account label is shortened to "Email me about alerts"
- iPhone: Directions and Open record are on the session's Actions rotor (the row is one VoiceOver stop), and Open record needs client access
- iPhone: Mark submitted asks first
- iPhone: a cached copy the server refuses is deleted, and copies over 30 days old are not shown
- Kept: the Directions link on each in-person session row on the website, a feature the roadmap asked for (not hint text).

Verified before commit:
- full_schema 1374, audit_regression, security, staging_scrub, and run_all (including the iOS API and model tests).
- The migration rehearsal matched.
- The iPhone app builds for the simulator.
- Not checked in a browser or with a screen reader.

## Deployed 2026-10-16 17:51 CT
From 3.0.55 with .audit_work/deploy-4.0.0 (28 files). mig_4.0.0.sql ran (3 statements); all 5 new columns present. Production files matched 3.0.55 before install and match 4.0.0 after; .htaccess unchanged. Smoke checks passed; health reports 4.0.0. Backups: /home/cmsuser/casemanagement_private/deploy-4.0.0-20261016-175118. The iPhone app 4.0.0 (build 46) still needs archiving to TestFlight by the owner.
