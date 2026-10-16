# Release 3.0.44 — October 16, 2026

Web and cron 3.0.44; migration **mig_3.0.44.sql** (new table amendment_requests; clients gain on_hold_reason and on_hold_until). The iPhone app is unchanged (3.0.43, build 43).

Owner decisions (2026-10-16):
- No client sign-off per session.
- No separate Awaiting Authorization status: On Hold with a reason is the waiting stage.
- Emailed authorizations arrive as forwarded plain-text emails with a dead link. Reading them needs a sample email first.

- **Amendment requests followed to their answer** (inc/amendments.php, amendment_request.php).
  - **Record the request as sent:** date, recipient, the additional hours or dollars or a new end date (at least one), and the letter as it reads.
  - This adds a track case note with the letter, a follow-up task due in 10 business days ('amend-<id>'), and an audit entry.
  - **Record the reply:** Approved, Partly approved, Denied or Withdrawn, with the date and a note. This completes the task. An approval opens track_edit.php with the request linked and the amendment reason filled in. Saving the change sets amendment_requests.amendment_id to the new auth_amendments row.
  - The track page and the Compliance list say "Amendment requested on <date>, awaiting reply".
  - Requests are merged and exported with the client.
- **On Hold with a reason** (on_hold_input and on_hold_save in inc/case_outcomes.php).
  - Moving a client to On Hold needs a reason, on the web and in the app. A return date is optional and can be updated later.
  - Voice records "Set On Hold by voice (no reason given)". Leaving On Hold clears both fields.
  - The client page shows the reason and the return date.
  - cron/daily_alerts.php opens an 'onhold-' check-in task about every three weeks and when the return date comes. It closes open check-ins for clients no longer On Hold.
  - Setting a client to Active Training with no active authorized track adds a reminder to the saved message. Saving is never refused.
- **Not yet.** The iPhone app does not record amendment requests or take a return date (its status note serves as the On Hold reason).
- **Tests.** tests/amendment_onhold_cases.php is new, and mig_3.0.44 is added to the chain test.

Verified before commit:
- full_schema 1099 (run with the test process's mailer failing), audit_regression 61, security 67, staging_scrub 34, and run_all.
- The migration rehearsal matched.
- Not checked in a browser or with a screen reader.

## Deployed 2026-10-16 12:34 CT
From 3.0.43 with .audit_work/deploy-3.0.44. 2 migration statements ran. The syntax check passed, every checksum matched, .htaccess was unchanged and every smoke check passed (health reports 3.0.44). Backup: /home/cmsuser/casemanagement_private/deploy-3.0.44-20261016-123430. Clients already On Hold have no reason recorded; their first check-in task comes on the next daily run when they have been On Hold three weeks or more.
