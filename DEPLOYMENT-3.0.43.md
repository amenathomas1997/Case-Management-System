# Release 3.0.43 — October 16, 2026

Web and iPhone 3.0.43 (43); migration **mig_3.0.43.sql** (scheduled_sessions: series_uid, ics_sequence, and an index).

- **Move a session** (inc/session_changes.php session_move).
  - Where: Schedule, Move or cancel more on each scheduled session's row; the app action move_session; iPhone Move session on the Actions rotor, with a sheet.
  - It warns first when the instructor already has an overlapping session, or when the new time is outside the track's authorization dates or the track is not active. Nothing changes until the person confirms (Move anyway).
  - A move keeps the session row and resets its reminder, and is audited as session.move from the old time to the new.
  - It raises ics_sequence. The client gets the editable email session_moved_client (English and Spanish) with a calendar update that has the same ID and a higher sequence. The instructor is told, with an updated invite, when someone else made the change.
- **Cancel the rest of a series or a whole group.**
  - A weekly series booked on the web or in the app now shares a series_uid.
  - Cancel this and later sessions in the series, and Cancel the whole group, are on the Schedule page, in the app (cancel_series, cancel_group) and on the iPhone.
  - Each client with a confirmed email gets one calendar cancellation; instructors are told.
- **Not covered yet.**
  - Voice cannot move sessions.
  - A session booked before this release, as part of a series or a group, has an instructor invite whose ID doesn't match its own. The instructor's email says to delete the old entry if it is still shown.
- **Tests.** tests/session_changes_cases.php is new, and mig_3.0.43 is added to the chain test.

Verified before commit:
- full_schema 1090 (run with the test process's mailer failing, as on CI), audit_regression 61, security 67, staging_scrub 34, and run_all.
- The iPhone simulator build succeeded, and the migration rehearsal matched.
- Not checked in a browser or with a screen reader.

## Deployed 2026-10-16 12:15 CT
From 3.0.42 with .audit_work/deploy-3.0.43. 1 migration statement ran. The syntax check passed, every checksum matched, .htaccess was unchanged and every smoke check passed (health reports 3.0.43). Backup: <private dir>/deploy-3.0.43-20261016-121539. iOS 3.0.43 (43) is not archived.
