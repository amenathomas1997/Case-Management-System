# Release 3.0.40 — October 16, 2026

Web, cron and iPhone 3.0.40 (42); migration **mig_3.0.40.sql** (portal_tokens and vrc_tokens gain expiry_warned_at).

- **Portal links that renew.** Opening a client portal link, or a counselor portal link after its code, keeps it open for another 180 days (portal_link_touch, PORTAL_LINK_DAYS).
  - cron/daily_alerts.php warns 14 days before an unused link lapses, once per link. The holder gets the editable email portal_expiring (English and Spanish), and for a client the instructor gets an alert.
  - The portal_link email says the link works while it is opened at least every six months.
- **Email me a new link.** The client portal's expired-link page has a form for this (portal_link_self_service). It sends only to a confirmed client address that had a portal, and only when it matches exactly one record. It allows 3 tries an hour per internet address, and it answers the same whatever is typed.
- **One send path.** portal_link_send() is shared by client.php, the self-service form and the new app action send_portal_link. The iPhone client screen has Client portal, Email a new portal link.
- **Notices.**
  - A staff message (web or app) sends the client or counselor the email message_notice: the portal link, no message text, at most once a day per conversation.
  - A portal request set to Done or Declined sends the client request_closed.
  - New templates: message_notice, request_closed and portal_expiring, each in English and Spanish (except the counselor ones, which are English).
- **Contact preference.** A client whose preferred contact is Phone or Postal mail is not emailed session reminders, and the instructor's reminder says to call them, with their phone number. The Send portal link question mentions the preference.
- **Tests.** tests/client_notices_cases.php is new, and mig_3.0.40 is added to the chain test.

Verified before commit:
- full_schema 1059, audit_regression 61, security 67, staging_scrub 34, and run_all.
- The iPhone simulator build succeeded.
- Not checked in a browser or with a screen reader.
