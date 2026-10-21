# CMS 3.0.19 deployment

Deployed October 12, 2026 to the production site.

The shared footer now always displays APP_VERSION, including before sign-in. The version constant and release feed are 3.0.19. No database migration was required.

Validation: PHP syntax checks, existing accessibility checks, anonymous and authenticated footer render checks, and whitespace checks passed. Live login, English and Spanish referral, help, portal, and password-reset pages returned HTTP 200 and displayed v3.0.19 inside their footer. The live release feed reports 3.0.19.

The deployment verified preexisting file checksums, staged file checksums, and private application, cron, configuration, and database backups. Production configuration and the original .htaccess were preserved. Backups are stored in `<private dir>/deploy-3.0.19-20261012`. No client records were changed and no AI calls were made during verification.
