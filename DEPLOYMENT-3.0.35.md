# Release 3.0.35 — October 16, 2026

Web, cron and iPhone 3.0.35 (37); migration **mig_3.0.35.sql** (referrals: Closed status, closed_reason, closed_note, closed_at, closed_by, outcome_emailed_at; new table client_closures). Builds on 3.0.34, which is held undeployed. Deploy both together: mig_3.0.34 first, then mig_3.0.35. Maintainer decisions of 2026-10-16:
- The counselor outcome email is automatic.
- The closure reasons are the proposed list.
- Monthly reports wait for a staff narrative.
- A re-referred former client's record is reopened. This is the recommended default; the maintainer did not answer that question.

- **Close a referral** (inc/case_outcomes.php referral_close; referrals.php, api.php close_referral, iPhone).
  - The reasons are Declined services, Not eligible, Withdrawn, Unable to reach, Duplicate, Referred elsewhere and Other; Other needs a note.
  - The ref-contact task is completed, and any waitlist entry is closed.
  - The referrer is emailed automatically using the new editable template **referral_closed**, which gives the reference number (REF-00012), the date received and a general reason, never client details or the note.
  - Closed referrals leave the open lists: the referrals queue, the action centre and the waitlist (web and API). The referrals page gains a Closed filter and a Reopen action, and assigning a closed referral is refused.
- **Attach to an existing client** (referral_attach; referrals.php duplicate box, api.php attach_referral with duplicate_clients ids, iPhone "Already on file").
  - The referral becomes Converted on that record, and its intake note is added.
  - A closed or archived client is reopened to Pending Assessment, with a history note citing the referral.
  - The contact task is completed and a follow-up task ('reref-') is opened.
- **Case closure.** Moving to a Closed status asks for the reason from CLOSURE_REASONS (Other needs a note) and an optional outcome: employed yes or no, employer, job title, start date, hours a week, wage, or an independence outcome.
  - On the web it shows a checklist of open items: active tracks, unbilled work, loans, future sessions, unpaid invoices.
  - Each closure is saved in client_closures.
  - The app's save_client takes closure_* fields; older builds record "Not recorded". Voice records "Not recorded".
  - The Outcome report (page and CSV) adds closures by reason and employed at closure, scoped like the rest of the report.
  - client_closures is merged with duplicate clients, included in the client export, and kept in the staging scrub as status and reason only.
- **Monthly progress reports wait for a narrative** (cron/monthly_reports.php).
  - A due report opens one task per period ('report-due-<track>-<since>') for the client's instructor. Nothing is emailed.
  - Administration > Scheduled reports > "Counselor progress reports" turns automatic sending back on (setting reports_auto_send).
- **Tests.** tests/case_outcomes_cases.php is new. The monthly-report tests cover both modes. mig_3.0.35 is added to the chain test.

Verified before commit:
- full_schema 965, audit_regression, security, staging_scrub and run_all.
- php -l on all PHP files.
- The iPhone build succeeded.
