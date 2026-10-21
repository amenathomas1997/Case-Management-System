# Release 3.0.45 — October 16, 2026

Web and iPhone 3.0.45 (44); migration **mig_3.0.45.sql** (clients gain vr_case_number, ipe_goal, ipe_date and ipe_amended_on).

- **The plan on the client record.** Edited in a new Plan for employment (IPE) group on client_edit.php. IPE dates must be today or earlier.
  - save_client takes all four (fields the app does not send keep their values), and the client action returns them. The iPhone edit sheet edits the case number and the goal.
  - The client page shows them under VR counselor.
  - Printed on the progress report (inc/report_builder.php; the AI cover-note copy masks the case number) and on invoices and statements (HTML and PDF, not for private pay).
  - The evaluation report's default justification names the IPE goal.
  - Not added to rsa_report.php, which keeps the RSA-911 layout.
- **Replace a counselor** (counselors.php, administrators). Replace with a new counselor, on each counselor's row, moves every non-archived client with that email to the new counselor in one transaction.
  - The agency is kept unless a new one is given, and the new counselor counts as confirmed (staff typed it).
  - Each client gets a case note, and draft invoices are re-addressed.
  - The old counselor's portal link is deleted, and the change is audited as vrcportal.replace.
- **Audit labels.** Also added: moved, amendment requested, amendment reply recorded, and counselor replaced.
- **Tests.** tests/plan_handover_cases.php is new, and mig_3.0.45 is added to the chain test.

Verified before commit:
- full_schema 1104 (run with the test process's mailer failing), audit_regression 61, security 67, staging_scrub 34, and run_all.
- The iPhone simulator build succeeded, and the migration rehearsal matched.
- Not checked in a browser or with a screen reader.

## Deployed 2026-10-16 12:46 CT
From 3.0.44 with .audit_work/deploy-3.0.45. 1 migration statement ran. The syntax check passed, every checksum matched, .htaccess was unchanged and every smoke check passed (health reports 3.0.45). Backup: <private dir>/deploy-3.0.45-20261016-124624. iOS 3.0.45 (44) is not archived.
