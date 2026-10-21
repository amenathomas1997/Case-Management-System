# Release 3.0.24 — October 15, 2026

Screen-reader fix on top of 3.0.23 (live from e17511c). No database migration; website files only.

- Menu and page-shortcut hints: each link's description is now a hidden copy (read with the link by JAWS and VoiceOver), and the on-screen hint is aria-hidden. Before, the visible hint appeared on keyboard focus and JAWS read it as a separate line after the link, costing an extra keystroke.
- APP_VERSION 3.0.24 so browsers load the updated stylesheet.

Verification: tests/accessibility.php and tests/run_all.sh pass; all 28 hinted links resolve to a hidden description and no visible hint is exposed to screen readers; in Chrome the hint appears on keyboard focus and hover while the accessibility tree shows only the link.

## Deployment verified

Published October 15, 2026 from 298f796 with .audit_work/deploy-3.0.24/deploy.sh. Production 3.0.23 matched the baseline; backup at <private dir>/deploy-3.0.24-20261015-102322/; server PHP syntax check passed; installed hashes match; .htaccess unchanged; live pages 200, api 401, updates.json and sign-in page report 3.0.24; the live stylesheet carries the new hint rule.
