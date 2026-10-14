# Release 3.0.21 — October 14, 2026

Production baseline verified as 3.0.20. Apply the additive mig_3.0.21.sql before publishing files. Preserve hosting .htaccess, private configuration, installer lock and custom branding assets. Back up website, private directory and database before migrating. Deploy the committed website and cron files; no iOS distribution is included.

Includes round-five audit fixes and accessibility follow-up: navigation, landmarks, user guide, clearer links, unsaved-form warnings, collapsible display controls, per-account toolbar visibility, optional native expanded lists and alternate date/time text entry.

Validation: 823 isolated database assertions; non-database suites; 144 synthetic browser pages and 576 reflow checks; 350 labelled controls; real Chrome date-entry tests. See AUDIT-FOLLOWUP-2026-10-14.md for details. WCAG AAA and the JAWS unselected announcement remain unverified; this release makes no certification claim.

Deployment results and backup location are recorded after live verification.
