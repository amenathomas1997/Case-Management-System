# Release 4.11.0 — October 16, 2026

Web 4.11.0, iPhone app 4.11.0 (build 50). Apply dist/migrations/mig_4.11.0.sql (clients.status, training_tracks.report_cadence and referrals.referral_source become varchar(40); schema_version 4.11.0), or use Database upgrade.

The last of the maintainer-approved configuration items. Lists and time limits now also sets:

**Client statuses**
- The six built-in statuses stay, because the system relies on them.
- An added status counts as an open case, unless it starts exactly "Closed - ", which counts as closed. A name that starts "closed" any other way is refused.
- Pipeline (website and app) shows every open status, including one later removed from the list that a client still has.
- Scheduling offers clients in any open status except Waitlist.
- The voice assistant knows the added statuses.
- The iPhone app reads the list from the server (form_options).

**Progress report schedules** ("Name | days", 7 to 365)
- none, weekly, biweekly and monthly keep their meaning (monthly still falls on the 1st).
- An added schedule sets its own due dates on Compliance, the app and the daily report job.
- A schedule used by an active track cannot be removed.

**Referral sources**
- Each added source appears on the public referral form as one more choice. It is asked what "Employer / other" is asked, and the referral is saved under its own name.
- They are offered only while "Employer / other" is offered (Referral form settings).
- On the Spanish page the name is marked as English.

Names differing only in capital letters are refused in every list (the database treats them as the same).

**Tests:** tests/v411_cases.php is new. It checks:
- locked and added values, the case and "Closed - " rules, schedule days and due dates
- a schedule in use cannot be removed
- the client form and the app setting an added status
- the pipeline, including a status since removed
- scheduling a client in an added status
- the apps' lists
- a referral from an added source, and a crafted role

Review: every finding was fixed:
- **Case:** names are compared without regard to capital letters, and the closed rule is enforced.
- **Scheduling and the voice assistant** hardcoded the statuses. Both now read the list.
- **Pipeline:** a removed status still shows, and section ids are unique.
- **Removing a schedule in use** is refused.
- **Added sources** follow the "Employer / other" setting and are marked as English on the Spanish page.
- **A crafted array role** no longer breaks the referral form. This was a fault from before this release.

Lists and time limits now leaves alone a list the form did not send. An emptied list of referral sources goes back to none.

Verified before commit:
- full_schema 1657, audit_regression, security, staging_scrub, and run_all.
- The migration rehearsal matched.
- The iPhone app builds for the simulator.
- Not checked in a browser or with a screen reader.

## Deployed 2026-10-16 22:52 CT
From 4.10.0 with .audit_work/deploy-4.11.0 (20 files, including cron/monthly_reports.php), at e7a7627. mig_4.11.0.sql ran; the three columns are text. Production files matched 4.10.0 before install and match 4.11.0 after; .htaccess unchanged. Smoke checks passed; health reports 4.11.0. The iPhone app 4.11.0 (build 50) reaches staff once it is on TestFlight; until then 4.8.0 shows the built-in statuses. Backups: <private dir>/deploy-4.11.0-20261016-225217.
