# Release 3.0.36 — October 16, 2026

Web, cron and iPhone 3.0.36 (38); migration **mig_3.0.36.sql** (client_notes: Addendum type and addendum_to; new tables client_note_revisions, client_releases, vendor_agreements with four vendors listed as Not signed). Builds on 3.0.34 and 3.0.35, which are held undeployed. Deploy all three together: mig_3.0.34, then mig_3.0.35, then mig_3.0.36. Owner decisions of 2026-10-16:
- An adult release of information is valid for 1 year.
- Notes lock after 30 days. Later changes are addenda, and every version is kept.
- Plain email reports are fine. Client detail is trimmed from email subjects.
- No business associate agreements are signed. AI stays as it is; the vendor page only records the agreements.

- **Note versions and the 30-day lock** (inc/records_privacy.php; client.php, track.php, api.php add_note and update_note, iPhone).
  - Every edit saves the replaced text in client_note_revisions, in the same transaction. The client page shows "Earlier versions (N)" under the note.
  - A note older than note_lock_days (default 30; Administration, Data, Case note lock) cannot be edited by anyone. The website offers Add addendum instead, and the API answers 409 with locked true.
  - An addendum is a new note of type Addendum with addendum_to set. The original lists its addenda, and the addendum links back. The app's note lists carry locked, addendum_to and versions. The iPhone offers Add addendum on a locked note (button and Actions rotor).
  - Revisions are merged, exported and purged with the client.
- **Releases of information** (client_releases; client.php Releases of information card, release.php, compliance.php).
  - Staff email a release to sign, or record one signed on paper (dated; refused when already over a year old). Each release names the signer (client or guardian), the recipient type and name, what it covers and why.
  - release.php is the public signing page, in English and Spanish, with the consent form's typed-name rules (typed_name_key, now shared with consent.php). Signing sets expires_on to 365 days later (setting release_validity_days) and adds a case note.
  - Releases can be recorded as withdrawn. The new email template **release_request** (and _es) can be edited.
  - Warnings when no current release covers the recipient: report.php and eval_report.php (VR counselor), and accommodations.php (employer report). The API send_report returns release_warning. A current guardian consent counts for the VR counselor and the school.
  - Compliance lists releases that are expiring within 30 days, expired or withdrawn, and open clients with no current release or guardian consent.
  - The iPhone client screen lists releases, read-only.
- **Email subjects without client names.** These subjects no longer name the client: the progress report (now "Training Progress Report — Authorization N"), the evaluation report (now dated), session scheduled (web, API and voice), sessions moved, the staff session reminder, the two session-ownership admin notices, and the default consent request subject. A subject an administrator customised is not changed.
- **Vendor agreements** (vendors.php, linked from Administration, Data). Microsoft 365, the web host, the AI provider and the backup mailbox are listed as Not signed. The status, signed date, renewal date and notes can be edited, and vendors can be added. Nothing is turned off by it.
- **Tests.** tests/records_privacy_cases.php is new. The release page is added to the public confirmation retry cases, release and vendors to the page smoke list, and mig_3.0.36 to the chain test. The staging scrub keeps the vendor list and randomises release tokens.

Verified before commit:
- full_schema 1004, audit_regression 61, security 67, staging_scrub 34, and run_all (including the iOS model and API regression).
- php -l on the changed files.
- The iPhone simulator build succeeded.
- Not checked in a browser or with a screen reader. Not deployed.
