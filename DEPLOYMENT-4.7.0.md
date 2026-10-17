# Release 4.7.0 — October 16, 2026

Web 4.7.0 (no iPhone app change). Apply dist/migrations/mig_4.7.0.sql (saved_reports; schema_version 4.7.0), or use Database upgrade.

**Owner decisions (2026-10-16):**
- Youth is under 18, the Pricing rules default.
- Caseload-scoped staff see only their own caseload in reports. This was already true everywhere when checked:
  - every analytics and report page uses report_table() or the per-client check
  - the app's report actions and voice answers are scoped too
  - utilization counts only work on the person's own clients, and says so
  - Grants & contracts is organisation-level and holds no client data
- The owner approved the remaining buildable list. Two of those items already existed: authorizations are created and edited in the app (save_track and amendment, since 3.0.x), and whole group sessions can be cancelled from the iPhone.

**Custom reports** (custom_reports.php, the analytics permission; inc/custom_reports.php):
- Subjects: clients, training tracks and authorizations, hours and mileage logged, invoices, scheduled sessions, referrals.
- Each subject has a fixed list of fields; only those can be chosen. Unknown columns and sorts are dropped, and filter values are bound parameters.
- Filters: date range on the subject's date, status (the values in use), staff, funding agency.
- Group by one text or date field gives one row per value, with how many and the total of each number or money column chosen.
- The table shows up to 500 rows; the download (CSV, with a scope line for caseload staff) up to 10,000. Downloads are audited.
- Saved reports have a name and an owner, and can be shared with everyone who can see reports. The owner or an administrator can delete them.
- Caseload: clients, tracks, invoices and sessions use report_table(), and hours go through the scoped tracks. Referrals are scoped as on the referrals page (yours or unassigned). The staff filter offers only yourself.
- Linked from Business analytics; the staff guide explains it.
- **Tests:** tests/v47_cases.php is new. It runs every column of every subject, checks dropped input, grouping, saving, sharing, running and deleting, and the caseload limit.

Review: no SQL injection. Every finding was fixed:
- referrals are scoped exactly as on the referrals page (a converted referral only for its client's current instructor), so no converted referral leaks across caseloads
- invoices need the billing permission and referrals the referrals permission
- saving opens the saved report (the id is taken before the audit row)
- viewing and downloading are both audited, with the full definition and row count
- zero shows as 0
- the spreadsheet has plain numbers, so they add up
- times sort and read as times
- a grouped report says when it is capped
- an empty first cell reads "(blank)"
- "Show the choices" returns to Build a report
- "Reverse order" replaces "Largest or latest first"
- tests cover referral scoping, permissions, grouped hours and deleting

Verified before commit:
- full_schema 1500, audit_regression, security, staging_scrub, and run_all.
- The migration rehearsal matched.
- Not checked in a browser or with a screen reader.

Also (owner, 2026-10-16): the referral form link must stay in every footer and on the sign-in page. It already was. A test now checks that every page rendered in the suite (95), and the public pages signed out, link to community_referral.php (62d22d8).

## Deployed 2026-10-16 21:09 CT
From 4.6.0 with .audit_work/deploy-4.7.0 (9 files), at 0902efa (the public files are the same at 62d22d8). mig_4.7.0.sql ran (2 statements); saved_reports present. Production files matched 4.6.0 before install and match 4.7.0 after; .htaccess unchanged. Smoke checks passed; health reports 4.7.0. The live sign-in page links to the referral form. Backups: /home/cmsuser/casemanagement_private/deploy-4.7.0-20261016-210928.
