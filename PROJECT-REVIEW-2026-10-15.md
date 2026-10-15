# Project review — October 15, 2026

Baseline: c742c5b, website 3.0.30. This is a readiness review of current source, deployment records, tests and public health, not a new line-by-line audit of all code.

## Manual accessibility result

The owner reports that the workflows they manually tested passed. Browsers, screen readers, devices and the exact workflow list were not supplied in that confirmation. Record this as a successful user acceptance result for the tested workflows; it does not by itself establish full WCAG 2.2 AAA conformance. The older October 14 follow-up predates several subsequent fixes and must not be treated as the current feature/deployment inventory.

## Current state verified

The live website and release feed both report 3.0.30. Public health returned HTTP 200 with a working database, a watchdog run at 4:40 p.m. Central, and a backup from 2:15 a.m. Central on October 15. Daily alerts had no recorded first heartbeat at the time of the check. This may be expected until the next scheduled daily run following today's deployment; it does not prove a broken cron job.

The only pre-existing local change is Xcode's PrivacyInfo.xcprivacy file-type metadata in ios/CaseManagement.xcodeproj/project.pbxproj. It was preserved. The local tracking branch reports origin/main at c742c5b; the remote was not fetched during this review.

## Findings and priorities

1. **High — staging anonymization is incomplete.** dist/make_staging.sh copies the full production database, then scrubs only the listed fields. Its plan does not include homework.title/instructions, smart_goals.specific_goal/measurable_metric/focus_area or portal_requests.details. Those fields accept client-specific free text and can retain identifying data. Several scrubbed tables also retain titles. A staging copy produced by this script cannot yet be described as fully anonymized. Use synthetic data for broadly accessible testing until all sensitive fields, files and copied private artifacts are covered and a seeded-data regression test verifies the result. No production or staging copy was created in this review; no actual disclosure was established.
2. **Medium — missing first cron runs can remain healthy indefinitely.** public/health.php marks a missing daily/watchdog heartbeat healthy whenever the database works, without an installation/deployment grace-period deadline. If daily alerts never starts, that same job cannot send its own warning. Confirm the next scheduled daily run, and replace the unlimited first-run exemption with a bounded grace period and a test. The watchdog currently has a valid heartbeat.
3. **Release follow-through — iOS distribution needs confirmation.** Release 3.0.28 notes say the widget App Group needs one-time registration in Xcode before a signed archive can be produced/uploaded, while another line says it was archived. Verify the actual signed archive and TestFlight delivery rather than relying on the contradictory notes. Preserve the current Xcode metadata change.
4. **Regression automation — no checked-in .github/workflows pipeline was found.** The project has substantial local suites but requires people to invoke them. Add CI for the supported PHP/MariaDB versions, JS checks, installer and schema/migration tests; keep browser and macOS/iOS jobs where available.
5. **AI quality — expand beyond mocked contract tests.** Existing tests check redaction, scope, draft handling and request behavior with synthetic responses. Add a curated synthetic evaluation set for note accuracy, homework usefulness, grounding, unsupported claims and confirmation behavior before changing prompts/models. No paid or external AI calls were made in this review.

## Verification

Non-database tests passed. PHP syntax passed for 155 application/cron/installer files. The isolated database suites passed: full schema/integration 898 assertions, audit regression 61, and security 63. Installer end-to-end is skipped by the default run_all invocation without CMS_TEST_DSN; recent deployment notes record a prior successful run, which is separate from today's check.

No application fixes or production configuration changes were made during this review. The report identifies work to complete next.
