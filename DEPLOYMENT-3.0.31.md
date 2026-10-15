# Release 3.0.31 — October 15, 2026

Web: two files; no migration. Production baseline: 3.0.30 (c742c5b). Fixes the two code findings of PROJECT-REVIEW-2026-10-15.md (the ChatGPT/Codex readiness review), verified first.

- **Staging scrub is deny-by-default** (review finding 1, confirmed and wider than reported). A scan of dist/schema_full.sql found about 60 free-text columns the old allowlist left untouched: homework.title/instructions, smart_goals, portal_requests.details, client_documents.title/orig_name, client_notes.subject, goal_progress.note, client_status_history.note, tasks.title, scheduled_sessions location/meeting_url/notes, invoices.bill_to/notes/payment_ref/auth_number, referrals reason/subject, equipment follow-up notes and others. The scrub moved to dist/staging_scrub.php: every text column is scrubbed unless STAGING_SCRUB_KEEP names it (codes, statuses, catalogues, staff names and emails), so columns added later are covered too. Also: the private directory is now copied by allowlist (cron/ without logs or dumps, .htaccess, uploads only with --with-uploads); before, it copied everything but a few names, which would have included the deploy-*/ folders holding full production database dumps. New test tests/staging_scrub.php seeds a marker into every column of every table and checks it survives only in kept columns (34 checks; a deliberate leak was caught).
- **Health check: bounded first-run grace** (review finding 2). public/health.php used to report a never-recorded heartbeat as healthy indefinitely. Now the first poll that sees it missing records heartbeat_*_awaited_since in settings, and the check fails once the job's own limit passes (36 h daily alerts, 2 h watchdog). Rule in public/inc/health_checks.php (new), tested by tests/health_checks.php (in run_all).
- **iOS note** (review finding 3): the owner's Xcode archive of 3.0.28 (33) at 2:19 p.m. holds the widget and is signed with the App Group (codesign); DEPLOYMENT-3.0.28.md corrected. TestFlight upload not confirmed. ios/CaseManagement.xcodeproj change is Xcode's PrivacyInfo file type from that session.
- Review findings 4 (CI pipeline) and 5 (AI evaluation set) are follow-up work, not in this release.

Files to deploy: public/health.php, public/inc/health_checks.php (new), public/inc/core.php, public/updates.json. dist/ and tests/ are not deployed.

After deploy: health.php should still return 200 (the watchdog has a heartbeat; daily alerts gets 36 h from the first poll). If daily alerts has not run by then, the monitor alerts — check its cron entry.

Verified before commit: run_all (incl. new health_checks); full_schema 898; audit_regression 61; security 63; staging_scrub 34; php -l on all public, inc, cron and dist PHP.
