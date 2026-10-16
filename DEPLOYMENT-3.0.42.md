# Release 3.0.42 — October 16, 2026

Web and cron 3.0.42; migration **mig_3.0.42.sql** (equipment_followups.client_reported). The iPhone app is unchanged (3.0.40, build 42).

- **My devices in the client portal** (inc/portal_devices.php).
  - The portal lists open loans with their due dates (overdue ones in bold), how to return an item, and open AT repairs with their status.
  - Device check-ins due within a week, or overdue, can be answered there: still in use, how happy, and what gets in the way.
  - Answers are saved once, as client-reported (completed_by empty). The instructor is alerted. A device no longer in use adds a case note and an 'abandon-' task, as when staff record it.
  - Device follow-ups marks these "reported by the client". Everything has Spanish wording.
- **Loan reminders to clients** (loan_reminders(), run by cron/daily_alerts.php). One email three days before a loan is due, and one the day after it is overdue.
  - They go only to current clients with a confirmed email who don't prefer phone or post, and include the portal link when the client has one.
  - New editable templates loan_due_client and loan_overdue_client, in English and Spanish.
- **Tests.** tests/portal_devices_cases.php is new. .audit_work/rehearse_migration.php is the local migration rehearsal used before each deploy.

Verified before commit:
- full_schema 1082 (run with the test process's mailer failing, as on CI), audit_regression 61, security 67, staging_scrub 34, and run_all.
- The migration was rehearsed: a 3.0.41 schema plus mig_3.0.42 matches a fresh 3.0.42 schema, and re-running it is safe.
- Not checked in a browser or with a screen reader.
