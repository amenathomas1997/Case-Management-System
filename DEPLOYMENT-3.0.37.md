# Release 3.0.37 — October 16, 2026

Web, cron and iPhone 3.0.37 (39); migration **mig_3.0.37.sql** (clients: privacy_notice_at, privacy_notice_by, privacy_notice_how, hold_at, hold_by, hold_reason; audit_log index idx_entity). Builds on 3.0.34 to 3.0.36, which are held undeployed. Apply mig_3.0.34, 3.0.35, 3.0.36, then 3.0.37.

- **Who opened a client's file.** record_client_view() (inc/records_privacy.php) writes 'client.view' at most once per person per client in 30 minutes. It is called from client.php and track.php (GET only), from the app's client and track_notes actions ("iPhone app"), from the client portal ("client portal") and from the counselor portal (each client listed, naming the counselor's email). Audit log, filtered by client and by Client: viewed, shows them.
- **Every bulk export is audited.** funder_report.php (CSV and PDF), outcomes.php (CSV and PDF) and utilization.php (CSV) now write report.export, as export.php, rsa_report.php, agency_report.php, invoices.php and invoice_pdf.php already did. New audit labels: Release of information, Vendor agreement, Case note lock, and the verbs addendum, hold, privacy_notice, purge_auto, paper, withdraw and set.
- **Guardian details.** client_edit.php has a Parent or guardian group (name, phone, email; the email is validated). save_client takes guardian_* (an unsent field keeps its value), and the app's client action returns them. The client page and the iPhone client screen show the guardian with a call link. The iPhone edit sheet edits them.
- **Minors without consent.** Compliance lists open clients under 18 or Pre-ETS with no guardian consent ever requested. They are listed once: such a client is not also listed under no release. cron/daily_alerts.php opens one task per client ('turns18-<id>') when a client with a guardian turns 18 within 14 days, or did in the last 30.
- **Privacy notice given.** The client page has a Record keeping card. Choose how the notice was given and choose Record notice given (audited as client.privacy_notice). Compliance lists open clients with no notice recorded, in a collapsed list. The notice page itself (privacy.php) is unchanged and still in English only.
- **Holds and safer deletion.**
  - An administrator places a hold with a reason, or lifts it. Both are audited.
  - A held record is skipped by retention auto-archive, and client purge refuses it. Purge is also refused while the client has an open incident or a Submitted (unpaid) invoice (client_purge_blockers).
  - Authorization, IPE and Release documents are deleted only by an administrator; others do not see Delete on them.
  - The nightly trash purge skips key documents and the items of held clients (trash_auto_purge_allowed), and audits each purge it makes as trash.purge_auto.
- **Tests.** tests/privacy_followup_cases.php is new. mig_3.0.37 is added to the chain test. The staging scrub keeps privacy_notice_how. The existing purge test now closes its incident first, because an open incident blocks a purge.

Verified before commit: full_schema 1023, audit_regression 61, security 67, staging_scrub 34, run_all, and the iPhone simulator build. Not checked in a browser or with a screen reader. Not deployed.

Not done here, still on the roadmap: a Spanish privacy notice, the privacy incident log, lost-phone wipe, record copy and correction requests.
