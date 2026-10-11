# Post-Claude audit — 3.0.2

The review started from the uncommitted 3.0.2 changes and ended on commit `962b1a6` plus the fixes described below. The commit arrived during the review; its diff was checked against the changes already inspected. This audit made no commits, deployments, production configuration reads, or live email/AI calls.

## Findings fixed

1. **High: calendar subscriptions bypassed current access rules.** A valid calendar token continued to disclose assigned sessions after schedule permission was removed, and ignored own-caseload restrictions after a client was reassigned. The feed now resolves current group permissions and applies client scope on each request. Tests verify scoped, unrestricted, permission-revoked, and inactive accounts.
2. **Medium: legacy offline queue decoding blocked recovery.** Swift's synthesized decoder did not use the `owner = 0` property default when an older JSON entry omitted that key. Explicit decoding now accepts missing ownership as unknown, preserving the entry without uploading it under an arbitrary account. The regression uses an actual JSON fixture with the key absent. Claude's dismissible notice remains intact; tests verify new saves and sign-out reset dismissal.
3. **Medium: public confirmations could lose their case notes permanently.** Consent signatures, survey completion, and report receipts updated their completion flags before inserting their case notes. A database failure could leave the flag committed and prevent a retry from writing the note. Each pair now shares a transaction. Tests force note insertion failure, verify rollback, retry successfully, and verify repeat submission creates only one note. Survey comments are normalized once for both writes, avoiding a second unsafe array-to-string operation.
4. **Medium: authorization amendment history could describe an update that never happened.** Track edits wrote amendment history and cleared alert markers separately from the track update. These writes now share a transaction and a track-row lock; history compares against the locked current record. Invalid start/expiration dates are rejected before database writes. Forced-failure tests verify that history and alert state remain unchanged; successful edits commit all changes together.

## Verification

- 268 fresh-schema integration checks passed, including populated API response decoding, web route rendering, AT workflows, permissions, exports, existing trash tests, and the new cases above.
- 40 audit and 22 security regression checks passed.
- 11 Swift offline-queue checks passed with isolated storage and mock HTTP.
- Five Node test files, four cron checks, form-message checks, and automated accessibility checks passed.
- 150 PHP files passed syntax checks; `git diff --check` passed.
- The full iOS arm64 simulator Debug build succeeded with signing disabled.

Tests used the dedicated empty `cms_audit_test` database and temporary configuration. No real calendar-provider subscription, notification email, or AI request was sent.

## Coverage and limits

Source inspection included all visible 3.0.2 changes and surrounding queue/UI behavior, calendar authorization, public confirmation routes, password-reset/forgot-password flow, passkey enrollment, counselor portal token expiry, API permission mapping, notification/AI routes, and authorization editing. Existing shared helpers and AT behavior were rechecked through the regression suite.

This is a cross-stack source and regression audit, not a claim that every line of every unchanged route, native screen, bundled library, or migration has been independently re-reviewed. Live SSO/passkey devices, email/AI providers, production hosting and physical-device accessibility remain environment-specific checks. Preserved unknown-owner queue entries still need a user-facing recovery/export workflow. Provider-side calendar caches cannot be erased by denying future feed requests. Staff edits still do not universally implement optimistic concurrency checks. Prior reports' other limitations remain applicable.

The fixes require no schema migration. They remain local; distribution archives were not regenerated.
