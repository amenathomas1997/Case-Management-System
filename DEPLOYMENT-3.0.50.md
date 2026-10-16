# Release 3.0.50 — October 16, 2026

Web 3.0.50. The iPhone app is unchanged (3.0.46, build 45). Apply dist/migrations/mig_3.0.50.sql. It is a data-only correction: it recalculates old scored-assessment percentages on the instrument's scale, and only where the stored top score still matches the instrument. There is no schema change.

This is the second half of the roadmap's "Management reports and data quality" section.
- **Spreadsheet downloads** (exports.php, inc/list_exports.php).
  - The lists: clients, referrals, tasks, sessions (one year back), authorizations, equipment, loans, device follow-ups, incidents, portal requests and contracts.
  - Each list uses its own page's permission and visibility rule:
    - tasks: task_visibility_sql
    - referrals: the referrals page's converted rule
    - incidents: administrator, or the reporter or instructor
    - sessions: the client's instructor
    - loans: borrowers on other caseloads are masked
  - Every download is audited.
- **Client list filters.**
  - New filters: named instructor (people who hold a caseload), client type, funding agency and school.
  - "Download these clients" includes every match, past the 500 shown.
- **Client timeline** (client_timeline.php, inc/client_timeline.php).
  - Case events newest first, under month headings. Links go only to the event's own place.
  - Incidents follow the incidents page's visibility.
  - Days in each status are counted in calendar days and add up to the length of stay.
  - Opening the timeline is recorded as a file view.
- **Assessments.** The trend badge respects lower-is-better instruments ("better" or "worse").
- **Scheduled email.** It can include the Business summary, with comparisons and the open caseload.
- **Not done from the roadmap item:** an iPhone swipe to delete a group session. The API has no delete-cohort-session action yet, and this release is web only.
- **Tests:** tests/exports_timeline_cases.php is new.

Review: one reviewer. Every finding was fixed:
- the tasks, incidents and sessions downloads now follow their pages' visibility
- the referrals download matches its page
- timeline incidents are hidden from people the incidents page hides them from
- the percentage rounding matches scored_pct()
- calendar-day status counts
- distinct column names in the email
- timeline links go only to the event itself
- the instructor filter lists only people with a caseload

Verified before commit:
- full_schema 1238, audit_regression 61, security 67, staging_scrub 34, and run_all.
- The migration rehearsal matched.
- Not checked in a browser or with a screen reader.

## Deployed 2026-10-16 15:09 CT
From 3.0.49 with .audit_work/deploy-3.0.50. mig_3.0.50.sql ran 1 data statement.

CI is green. The syntax check passed, every checksum matched, .htaccess was unchanged and every smoke check passed (health reports 3.0.50). Backup: /home/cmsuser/casemanagement_private/deploy-3.0.50-20261016-150952.
