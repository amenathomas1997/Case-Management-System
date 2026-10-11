# Web deployment 3.0.14 — October 10, 2026

Deployed to https://cms.example.org/ following the user's deployment request.

The live site was already on 3.0.13. Checksums matched the reviewed local application files except for the three accessibility changes and the site's hosting-specific `.htaccess`, which was preserved exactly. Uploaded `analytics.php`, `assets/styles.css`, `equipment_labels.php`, `inc/core.php` (version/cache key), and `updates.json`. Set the live private configuration's public support address to `maintainer@example.org` while retaining its other configuration values. No local credentials were uploaded.

The installed database already contained the structured-referral columns, Client Upload category and 3.0.12 counselor-verification column/default. Version 3.0.14 requires no additional migration, so no migration was applied.

Before activation, created and verified private application, database and configuration backups at `/home/cmsuser/casemanagement_private/deploy-3.0.14-20261010/`. Backups have owner-only permissions. The deployment used a brief maintenance response while replacing files and configuration, with automatic rollback on failure. No rollback was needed; original hosting rules were restored and compared byte for byte.

Verification passed:

- Server PHP syntax, all five deployed-file SHA-256 checksums, application version and database connection.
- Live login, referral form and help returned HTTP 200.
- Login and referral pages show the requested contact email and versioned 3.0.14 stylesheet.
- Live stylesheet checksum matches the reviewed local file; release feed reports 3.0.14.
- Protected analytics and equipment-label pages redirect anonymous visitors to login.
- Installer and full-schema URLs remain blocked with HTTP 403.

Rebuilt `dist/cms_dist.tar.gz` from the updated local source. Companion iOS remains 3.0.13; this was a website deployment, not an App Store submission. Repository changes remain uncommitted. No production form submissions, outbound test emails or client-record mutations were performed.
