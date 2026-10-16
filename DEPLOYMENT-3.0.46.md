# Release 3.0.46 — October 16, 2026

Web and iPhone 3.0.46 (45). There is no migration.

- **What happened at a session.** Completing a single session on the Schedule page now also takes the goal worked on (this track's open goals). Where the track offers several services, it takes the service delivered too. Both are written to the work log.
- **Correcting work afterwards** (inc/work_logs.php work_log_correct).
  - On the track page, Correct on each unbilled entry changes its hours and miles, with a required reason. In the app it is correct_log, and on the iPhone it is Correct hours or miles on the entry's Actions rotor.
  - Invoiced entries cannot change.
  - More hours or dollars are checked against the authorization like new work (work_authorization_block, under the track's lock).
  - The old values, the new values and the reason are audited as hours.correct.
- **Mileage-only entries.** 0 hours with miles is accepted on the track's Record a session form.
- **The app.** New actions: work_logs (a track's entries), delete_log (unbilled entries, to the trash) and my_week. The iPhone track screen lists Hours logged, with Correct and Delete.
- **Your week.** The Dashboard shows hours logged this week (Monday to Sunday), hours still booked, and the person's weekly target (users.weekly_hours). It comes from staff_week().
- **Tests.** tests/work_log_cases.php is new.

Verified before commit:
- full_schema 1115 (run with the test process's mailer failing), audit_regression 61, security 67, staging_scrub 34, and run_all.
- The iPhone simulator build succeeded.
- Not checked in a browser or with a screen reader.
