# Release 3.0.48 — October 16, 2026

Web 3.0.48. The iPhone app is unchanged (3.0.46, build 45). Apply dist/migrations/mig_3.0.48.sql before updating files. It adds the incoming_authorizations table.

- **Incoming authorizations** (authorizations.php, Clients menu; inc/authorization_email.php).
  - Staff paste an approved authorization email. The parser reads the "Label: value" lines in the agency's format: Authorization Number, Client Name as "Last, First M", Service Category and Authorization Amount. From the original message's From/Sent/To block it takes the approval date and the counselor.
  - The funding agency is the one most used for counselors at that email domain, with Iowa Department for the Blind as the fallback for blind.state.ia.us.
  - Nothing is sent to AI.
  - The page warns when the number is already on a track (and when the amount differs) and when the email does not say approved.
  - The client is matched on first and last name. With several matches, nothing is chosen for the user.
  - The counselor can be recorded on the client, but an existing, different counselor is never replaced.
- **Track form prefill.** Start the track opens track_edit.php filled in:
  - title and RSA category from the service category
  - authorization number, agency and amount
  - start date from the approval date
  - hours worked out from the amount at the client's last VR rate, to the quarter hour
  The heading says to check every field. Saving marks the email "Track created", but only for its own client. The prefill is used once.
- **Mailbox** (cron/ingest_email.php).
  - Taken from verified staff, or from confirmed counselors: an email that says "has been approved", whose number is not already on a track. It is kept, gets a task (incauth-<id>), notifies the instructor or the administrators, and is moved to Filed to CMS.
  - Anything else, such as a reply quoting a progress report, is filed as before.
- **Scoping.** A caseload-scoped user sees the emails matched to their own clients, and the unmatched ones they pasted themselves.
- **Covered** by merge, export and scrub. **Tests:** tests/authorization_email_cases.php is new (made-up names).

Review: one reviewer covered security, correctness and accessibility. Every finding was fixed:
- a quoted report line is no longer read as an approval
- "another client" works
- counselors are not overwritten
- the handled update is limited to the email's own client
- the prefill is used once
- scoped users can place their own unmatched emails, and "Already on a track" is limited to their caseload
- with several addressees, the counselor is the first one who is not staff
- "Last, First" names are turned round
- impossible dates are not rolled over
- the authorization amount is preferred over other totals

Verified before commit:
- full_schema 1169, audit_regression 61, security 67, staging_scrub 34, and run_all.
- The migration rehearsal matched.
- Not checked in a browser or with a screen reader.
