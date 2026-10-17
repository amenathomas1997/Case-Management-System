# Release 4.20.0 — October 17, 2026

Web 4.20.0. The apps are unchanged. Apply dist/migrations/mig_4.20.0.sql, or use Database upgrade; Software update applies it on its own. It adds:
- the tables calendar_links, calendar_busy and staff_availability
- the users columns calendar_sync, calendar_synced_at and calendar_error
- meeting_accounts.granted_scope
- scheduled_sessions.booked_by_client
- schema_version 4.20.0

The owner asked to build the improvements from the comparison with off-the-shelf software. This release covers the first two:
- two-way Outlook calendar sync
- client self-booking

**1. Outlook calendar sync** (inc/calendar_sync.php; My Account, Outlook calendar)
- It uses each person's own Microsoft connection (the one used for Teams links). The connection now asks for Calendars.ReadWrite (delegated): the organisation adds that permission to its Entra app.
- **Keeping Teams links working:** what each person actually granted is kept, and renewals ask for that only. Without this, Microsoft would refuse the renewal and Teams links would stop working for everyone who connected before. Anyone without the calendar is asked to connect again.
- **Sessions to Outlook:** sessions go into the person's calendar from yesterday to four months ahead. They are added, moved and removed as they change here: one session at once after its booking notice, a series by the ten-minute sync (watchdog, last in its run, one run at a time, within 4 minutes).
- **Events:** private (sensitivity), named with the client's first name only, or "Client session" by setting (it defaults to yes, and the Settings label now says so). The body holds only a link.
- **Busy times from Outlook:** the person's other Outlook appointments are kept as busy, all-day events covering the office's local day. Free, working-elsewhere and the system's own invitation emails don't count.
- **Where busy times are checked:**
  - Offered times and self-booking never use a busy time.
  - Staff booking (single, series and group) and the phone apps ask for approval, as an authorization question does.
  - Voice booking refuses.
  - Moving a session warns.
- **Changes made in Outlook:** a session's event moved or deleted there sends the person one alert to change the session here. Outlook alone never changes a session. A changed session whose event was deleted gets a new one.
- **When sync fails:** one failed event does not stop the others. The person is alerted once when sync starts failing, and My Account shows the problem.
- **Turning it off** removes the events it added.

**2. Client self-booking** (inc/self_booking.php; My Availability; the portal's "Book a session")
- Staff set the hours they take sessions: day, from, to, online or in person and where.
- An administrator turns self-booking on there, and sets:
  - the length
  - the notice in hours
  - the days ahead
  - how many self-booked sessions a client can hold
- **Which times are offered:** those with the client's own instructor (an active account) where:
  - the instructor and the client have no session then
  - Outlook is free
  - the time is far enough ahead
  - an active track with an authorization number covers its dates and remaining hours
- A client with no such track books through their instructor; self-booking never makes a session nobody can bill.
- **Booking:** checked again under a lock on the instructor's row, which every booking path now takes first, so no two paths can double-book. The session is booked on that track. An online session gets a Teams or Zoom link when the instructor connected one. The client gets the usual notice, the instructor is told, and the booking is audited.
- **The portal:** a "Choose a time" list, then a confirmation, in English and Spanish.

**Tests:** tests/v420_cases.php, 44 checks, with fake Microsoft replies.

**Checks before commit:**
- full_schema 1904, audit_regression, security, staging_scrub and run_all pass.
- The migration rehearsal matched.

**Review:** every finding was fixed, except those accepted below:
- **The scope change** would have broken existing Teams connections.
- **Self-booking with no authorization**, and with a switched-off instructor.
- **The system's own invitations** counted as busy.
- **A deleted event patched in Deleted Items.**
- **Silent failures**, and one failure stopping the whole sync.
- **Double booking** across different booking paths.
- **Missing Outlook checks** in group, app and voice booking.
- **The Settings label** said no when it meant yes; events are now private.
- **Booking requests waited on Microsoft.**
- **Smaller:**
  - the portal's query count (each track is read once)
  - a calendar too big to read whole is no longer misjudged
  - all-day events
  - the watchdog's order, lock and time limit
  - immutable event ids
  - transaction ids that stop duplicate events
  - the client's own other sessions
  - errors placed on their own fields
  - a "Choose a time" placeholder and confirmation

**Accepted:**
- A group session puts one event per member in the instructor's calendar.
- English place names inside the Spanish time list are not marked as English (browsers drop markup inside options).

## Deployed

2026-10-17 15:41 CT, after CI passed all three jobs on 13251c8. Every replaced file matched 4.19.1 first. The backup is at /home/cmsuser/casemanagement_private/deploy-4.20.0-20261017-154147. All 9 migration statements ran and the new tables and columns are present; every installed file matches the release, and health.php reports 4.20.0.

Published as the GitHub release v4.20.0 (latest), with the install bundle and the verified signed package.

Nothing changes on the live site until the owner adds Calendars.ReadWrite to the Microsoft app (if Teams links are used) and staff turn on Outlook sync, or until self-booking is turned on.
