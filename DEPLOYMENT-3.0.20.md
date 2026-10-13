# CMS 3.0.20 deployment

Deployed October 12, 2026 to https://cms.example.org/.

Following a report that Safari and iOS showed footer links but not the version on the signed-in dashboard, the shared footer now places the version in a separate, clearly labeled paragraph with primary text contrast. Bottom padding includes the device safe-area inset. The version remains server-rendered from APP_VERSION. No database migration was needed.

PHP syntax, existing accessibility checks, and a synthetic authenticated layout render passed. Live English and Spanish version paragraphs and the updated stylesheet were verified. Private application, configuration, cron, and database backups were verified before deployment; .htaccess was preserved. Backup directory: `/home/cmsuser/casemanagement_private/deploy-3.0.20-20261012`.

The reported signed-in Safari rendering issue could not be directly reproduced: macOS computer-use permissions remained pending. These changes improve version visibility; confirmation on the affected devices is still needed. No client records or AI settings were changed.
