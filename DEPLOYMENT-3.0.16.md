# Web deployment 3.0.16 — October 12, 2026

Deployed to the production site at the user's request.

Production started on 3.0.15. Compared live application checksums against local source: differences were the reviewed workflow changes and the hosting-specific `.htaccess`, which was preserved byte for byte. Deployed 16 web files and the private AT reminder cron script. Updated the release feed to 3.0.16. No local configuration or credentials were uploaded.

Created and verified owner-private backups of the application, cron directory, configuration, and database at `<private dir>/deploy-3.0.16-20261012/`. The database backup includes routines and triggers. Verified staged checksums and server PHP syntax before activation. Used a brief maintenance response, a deployment lock, and automatic file rollback on error; no rollback was needed.

Applied `mig_3.0.16.sql` successfully before replacing application files. Confirmed the four new tables and the AI outcome/latency/truncation columns. The contact email remains the organisation's support address. Client portal AI explanations remain off by default.

Verification passed:

- All deployed web and cron files match the staged source.
- Application version, database connection, workflow schema and contact configuration.
- Read-only live action-center, documentation-review and AI-quality queries.
- Login, public referral form, help, and release feed return HTTP 200. Login and referral pages show the current version and contact email.
- Workflow, AI-quality, and scheduling pages redirect anonymous visitors to login.
- Installer and schema URLs remain HTTP 403.
- Original hosting rules match their backup exactly.
- AT reminders remain scheduled daily at 08:00 in the server cron timezone.

No production client records, forms, outbound test messages, or paid AI calls were created. The companion iOS source/build changes were not distributed through the App Store as part of this website deployment. The generic distribution archive was not rebuilt; this deployment used an explicit, checksummed delta package. Local source changes remain uncommitted.
