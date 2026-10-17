# Release 4.7.1 — October 16, 2026

Web 4.7.1. No database change.

- **Main menu** (owner, 2026-10-16): "Referral form" (community_referral.php) under the Clients heading, after Incoming referrals. It is shown to every signed-in user and has no access key.
- **The referral form link** stays in every page's footer and on the sign-in page. The tests check every rendered page (95), the public pages signed out, and the menu link under Clients.

Verified before commit:
- full_schema 1596, audit_regression, security, staging_scrub, and run_all.
- Not checked in a browser or with a screen reader.
