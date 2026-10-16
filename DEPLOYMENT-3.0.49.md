# Release 3.0.49 — October 16, 2026

Web 3.0.49. The iPhone app is unchanged (3.0.46, build 45). Apply dist/migrations/mig_3.0.49.sql before updating files. It adds training_tracks.completed_at and backfills ended tracks with their last work date.

This is the first half of the roadmap's "Management reports and data quality" section.
- **Fiscal year.** Administration, Scheduled reports, sets the month the fiscal year starts (October by default). Every period picker gains Fiscal year to date and Last fiscal year. The RSA-911 report keeps the federal year.
- **Business summary** (business_summary.php).
  - The figures: referrals and conversions, new clients, clients served, sessions completed, no-shows, cancellations, the no-show rate, hours, value, closures and the rehabilitation rate.
  - Each figure is compared with the previous period of the same length and with the same dates last year, with the change in words.
  - It also has month-by-month tables, the open caseload by instructor and status, CSV and accessible HTML downloads, and an AI narrative of the figures.
  - A period that runs past today is counted to today.
- **Attendance and no-shows** (attendance.php).
  - Counts by client, instructor, service type, or remote and in person.
  - Lists clients with two or more no-shows in 60 days, with a CSV.
  - The client page shows attendance.
- **Unused authorizations** (unused_authorizations.php).
  - Lists VR authorizations that ended in the period: the track was Completed or Cancelled then, or an Active track's expiration date passed.
  - Shows hours and dollars authorized, delivered, invoiced and unused, by agency, service and instructor, with a CSV.
  - A dollar limit is compared with hours, mileage and expenses together. An hours limit is compared with the hours only.
  - The app and the website set completed_at through track_mark_ended().
- **Data quality** (data_quality.php). It lists:
  - likely duplicates, with a merge link for administrators
  - closed clients with an active track
  - Active Training clients with no track
  - missing date of birth, counselor or disability/vision
  - hours with no category
  - minors with no consent
- **Duplicate warnings.**
  - Adding a client on the web who may already have a record is held until staff tick "This is a different person".
  - The app's save_client returns duplicate_warning.
  - Voice says when the name is already used.
  - A caseload-limited user sees only a count for duplicates on other caseloads.
- **Voice** can open reports, the business summary, attendance, unused authorizations, data quality, survey responses and incoming authorizations.
- **Tests:** tests/reports_cases.php is new.

Review: one reviewer. Every finding was fixed:
- duplicate names from other caseloads are hidden
- periods are counted to today, and not-yet-expired tracks are excluded
- the unused-dollar measures are consistent
- blank groups are labelled Not recorded
- February 29 maps to February 28 in last-year comparisons
- a note appears when only the first 24 months are shown
- converted referrals are scoped by their client's instructor

Verified before commit:
- full_schema 1208, audit_regression 61, security 67, staging_scrub 34, and run_all.
- The migration rehearsal matched.
- Not checked in a browser or with a screen reader.
