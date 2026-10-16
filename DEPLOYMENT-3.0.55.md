# Release 3.0.55 — October 16, 2026

Web 3.0.55. Apply dist/migrations/mig_3.0.55.sql (users.invited_at, confidentiality_signed_on, privacy_training_on; schema_version 3.0.55), or use Database upgrade.

This completes the roadmap section "Running the system safely".
- **Invitations** (inc/staff_accounts.php). Leave the password empty when adding someone: the account gets a random password and is emailed a 7-day link to choose their own (reset.php). User management has "Send invitation again".
- **Agreements and training.**
  - Confidentiality agreement and privacy training dates are recorded on the user page and renewed yearly.
  - Compliance (administrators only) lists what is due or lapsed, invitations never used, and accounts idle for 60 days.
  - The daily alerts open a renewal task for an administrator.
- **Leaving.**
  - The user page lists what the person still holds.
  - The transfer also moves software licences, equipment reservations, group programs and access bookings. Cost rates and skills profiles stay with the person.
  - Only a missing table is skipped; any other error rolls the whole transfer back.
- **Microsoft 365 departures** (optional, off by default; Administration, People). Each night, a staff account disabled in Microsoft 365 is switched off: its app sessions, reset links and sign-in codes are voided. Administrators are reported, not switched off. This needs User.Read.All.
- **Administrator guide** in Help: the scheduled jobs, renewing the Graph secret, joining, leaving, misfiled email, permission groups, homework AI and privacy incidents.
- **Import** (client_import.php, administrators). Clients or open authorizations come in from CSV, with a template and "Validate only". Each row is checked:
  - duplicates are skipped
  - only open statuses (not On Hold) are accepted
  - the instructor must have client access
  - imported emails are unconfirmed
  - authorizations need exactly one client and a new number, with strict hours, rate and date checks
  Everything goes in one transaction.
- **Tests:** tests/staff_import_cases.php is new. The harness can now post files and session values.

Review: no security holes. Every finding was fixed:
- only licences are transferred
- renewal tasks go to an administrator
- the transfer is all-or-nothing
- the import requires client access for the instructor
- invitation failures are announced as errors
- date errors are shown on their fields
- notes appear only for active accounts and are not bold
- Microsoft 365 switch-off voids links and codes
- client_dob is validated
- the transfer and leaving wording is accurate

Verified before commit:
- full_schema 1349, audit_regression, security, staging_scrub, and run_all.
- The migration rehearsal matched.
- Not checked in a browser or with a screen reader.

## Deployed 2026-10-16 17:04 CT
From 3.0.54 with .audit_work/deploy-3.0.55. mig_3.0.55.sql ran (2 statements). schema_version is 3.0.55.

CI is green. Every checksum matched and every smoke check passed (health reports 3.0.55). Backup: /home/cmsuser/casemanagement_private/deploy-3.0.55-20261016-170412.

The roadmap section "Running the system safely" is complete.
