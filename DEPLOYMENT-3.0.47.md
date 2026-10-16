# Release 3.0.47 — October 16, 2026

Web 3.0.47. The iPhone app is unchanged (3.0.46, build 45). Apply dist/migrations/mig_3.0.47.sql before updating files. It adds:
- report_acks.report_text
- surveys.channel, recorded_by and reminded_at
- clients.vision_status and vision_onset_age
- the closure_followups table

Changes:
- **Progress and final reports** (inc/report_builder.php, report.php).
  - Reports can cover only the period since the last report.
  - Optional Barriers and Recommendations sections.
  - Final report mode adds:
    - skill levels and scored assessments, first and latest
    - devices
    - this episode's closure
  - The report as emailed is kept in report_acks.report_text, and the counselor portal (vrc.php) shows that copy.
- **AI drafting on every report** (inc/report_ai.php).
  - Barriers and recommendations on the report page.
  - Narrative summaries on the Outcome and funder reports, which send figures only.
  - Worksite recommendations on accommodations.php.
  - Everything goes into an editable box; nothing is sent. The client, counselor, authorization, case number, employer and closure note are masked before sending.
- **Closure follow-ups** (inc/outcome_followups.php).
  - Every closure schedules checks at 30, 60 and 90 days. A correction between Closed statuses does not add more checks.
  - A due check opens a task (daily_alerts.php).
  - Staff record the answers under After closure on the client page.
  - "Needs more service" opens a re-referral task.
  - Reopening a case cancels its pending checks and closes their tasks.
- **Surveys.**
  - Every closure sends the survey, once per 30 days.
  - A client who prefers phone or post is not emailed. Staff enter phone answers on the client page.
  - One reminder goes out after 7 days. It is skipped for a phone or post preference, and once a later answer exists.
  - survey_responses.php lists every answer, with a CSV.
- **Outcome measures.**
  - The Outcome report gains Results after training:
    - devices still in use
    - skill gains
    - goals achieved
    - closure follow-up results
  - It also gains breakdowns by instructor, age band and vision status. Closures there use the same rule as the headline totals.
  - The funder report adds follow-up employment and technology retention.
- **Vision status and age of onset** are on the client edit page. Staging scrubs both.
- **Tests:** tests/outcome_followup_cases.php is new.

Audit: three reviewers checked security and privacy, correctness and accessibility. Every finding was fixed:
- the employer masked for the AI
- the reminder respects phone or post and later answers
- vision onset scrubbed
- a phone or post preference is not reported as a failed send
- no duplicate checks on a closure correction
- reopened cases cancel their checks
- breakdowns match the totals
- the narrative keeps the chosen period
- phone survey errors are tied to their fields
- clearer legends
- the counselor portal says Final report when it is one

Verified before commit:
- full_schema 1143, audit_regression 61, security 67, staging_scrub 34, run_all.
- The migration rehearsal matched.
- Not checked in a browser or with a screen reader.

## Deployed 2026-10-16 13:37 CT
From 3.0.46 with .audit_work/deploy-3.0.47. mig_3.0.47.sql ran 4 statements, and all 8 checked columns and tables are present.

CI is green on b564531. The syntax check passed, every checksum matched, .htaccess was unchanged and every smoke check passed (health reports 3.0.47). Backup: /home/cmsuser/casemanagement_private/deploy-3.0.47-20261016-133752.

At 7:00 tomorrow, the first daily alerts run will send one reminder for each emailed outcome survey still unanswered after 7 to 60 days. It skips clients who prefer phone or post.
