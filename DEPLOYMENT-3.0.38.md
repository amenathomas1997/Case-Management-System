# Release 3.0.38 — October 16, 2026

Web, cron and iPhone 3.0.38 (40); migration **mig_3.0.38.sql** (new tables privacy_incidents, privacy_incident_clients and privacy_incident_events; portal_requests gains due_on and outcome). Builds on 3.0.34 to 3.0.37, which are held undeployed. Apply mig_3.0.34 to mig_3.0.38 in order.

- **Privacy incident log** (privacy_incidents.php, linked from Incident reports and Administration, Data).
  - Any staff member with client access can report one: kind, date discovered, date it happened, one client affected, what happened and the information involved. Administrators are notified.
  - The notice deadline is 60 days from discovery (PRIVACY_NOTICE_DAYS).
  - Administrators:
    - record the four-factor risk assessment and its outcome (Under assessment, Low probability of compromise, Breach: notice required);
    - add affected clients;
    - log each notice (to whom, how, when, which affected person, who is then marked notified) and dated follow-ups;
    - close or reopen the incident. It cannot be closed while still Under assessment.
  - Each affected client links to their access history (audit.php?client=N).
  - Staff who are not administrators see only the incidents they reported.
  - An affected person's name is kept (client_label) when their record is purged. A merge moves the incident links to the kept record.
  - cron/daily_alerts.php opens one task ('privinc-<id>') for an administrator when an open incident is within 14 days of its deadline. Closing the incident completes the task.
- **Requests for a copy of records or a correction.**
  - New portal request types 'records' and 'correction', with Spanish labels. Each is due in 30 days (due_on), and its task is due 5 days earlier. The portal tells the client "We answer within 30 days" and shows the outcome.
  - Client requests shows the due date. A records or correction request needs the outcome the client sees before it can be set to Done or Declined, on the web and in the app (portal_request_update).
  - Publish a copy to the portal (records requests) saves an accessible HTML copy of the record as a Resource document, audited as client.export. The copy holds the profile, training and goals, sessions, case notes, assessments, loans, consents and releases. It leaves out billing, rates, invoices and internal reviews.
  - On the iPhone, Client requests shows the due date and outcome, and asks for the outcome when closing one.
- **Tests.** tests/privacy_incident_cases.php is new. The privacy incidents page is added to the page smoke list, and mig_3.0.38 to the chain test. The staging scrub keeps the incident kind, risk level and status, and the event type and recipient.

Verified before commit:
- full_schema 1041, audit_regression 61, security 67, staging_scrub 34, and run_all.
- The iPhone simulator build succeeded.
- Not checked in a browser or with a screen reader. Not pushed and not deployed.
