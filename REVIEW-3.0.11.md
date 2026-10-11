# Post-Claude audit — October 9, 2026

Reviewed baseline: `7b348103f75e67f4c0b9966f267914bb2396979b` (3.0.11). The working tree was clean when this audit began. Changes since the previously reviewed structured-referral baseline `dd9eacf` span 127 files. This pass inspected the higher-risk referral, task/group access, API, email filing, billing/reporting, portal, voice, packaging and mobile changes and ran the available regression suites. It is not a claim that every line of all 127 files or every live workflow was independently verified.

## Confirmed findings fixed locally

1. **P1 — Ambiguous email subjects could write a note to the wrong client.** `cron/ingest_email.php` stopped at the first matching name. Duplicate names and subjects naming multiple clients could therefore silently file against whichever row came first. Filing now requires one eligible match; ambiguous verified messages go to the existing review folder. A verified contact associated with only one same-name client can still file to their own record.
2. **P2 — Staff email could modify archived client records automatically.** The staff association override bypassed the archived-client exclusion. These messages now go to review without inserting a case note.
3. **P2 — Historical structured counselor Pre-ETS referrals converted to VR.** The new classification rule used the school source alone. Conversion now also preserves an explicit stored Pre-ETS service or original Pre-ETS note type, including plan amendments submitted through the earlier form.
4. **P1 — Unassigned tasks could expose converted referrals through a stale assignee.** The task predicate still accepted the referral's old assignee after conversion even though the referral pages use the client's current owner. Unassigned converted-referral tasks now follow the current client caseload. **Explicitly assigned tasks still cross caseloads**, as the user explicitly confirmed during this audit.
5. **P2 — Mobile group-session durations were silently changed or accepted as zero.** The API clamped out-of-range values and truncated fractions; the iOS picker offered zero. The API now rejects missing, zero, negative, fractional, nonnumeric and over-1440-minute durations before writing. The iOS picker offers 15–1440 minutes. A valid API duration is saved unchanged.


6. **P2 — Converted referrals could be reassigned independently of their clients.** Both assignment endpoints now lock the referral and refuse changes after conversion. Web and iOS direct staff to the client record. Open referrals and their follow-up tasks change assignment in one transaction; unchanged assignments do not send duplicate notifications.
7. **P2 — Long historical referrals could fail web conversion.** The generated intake subject and metadata could exceed the note columns. Web and API now share byte-safe note formatting, explicitly mark shortened bodies, and preserve the complete original referral. Regression cases exercise maximum-length UTF-8 bodies through both conversion paths.
8. **P1 — Web conversion used a scope decision made before acquiring its row lock.** A concurrent reassignment could invalidate that decision. Conversion now checks scope again against the locked row before creating a client. Assignment also checks lifecycle and scope under the same row lock. The concurrent interleaving itself was reviewed in code, not exercised by a concurrency test.

Also corrected the group-access comment that incorrectly claimed scoped coordinators bypass mixed-caseload restrictions; the access rule itself was already correct.

## Validation

- Initial integration baseline: 491 checks passed.
- Integration after the continued audit: 504 checks passed, including historical Pre-ETS conversion, invalid mobile group durations, maximum-length referral conversion through web/API, converted-referral assignment refusal, open-referral/task assignment consistency and fresh/upgrade migration parity.
- Permission/audit regression: 57 checks passed, including stale referral assignments and the approved cross-caseload task delegation behavior.
- Security regression: 60 checks passed.
- Cron regression: 16 checks passed using local database/mail/Graph doubles; no real messages were sent.
- JavaScript: all seven test files passed.
- iOS API/offline queue: 17 checks passed using isolated storage and network adapters.
- Installer gate: three checks passed.
- iOS simulator build succeeded with code signing disabled. The first sandboxed build could not access simulator/Swift macro services; the permitted retry succeeded.
- PHP syntax checks and whitespace validation passed.

## Boundaries and deployment

No production records, live credentials, live AI/Graph/email requests, website uploads or database migrations were used in this audit. Fixes are local and uncommitted; app version and release feed remain 3.0.11. No schema change is required for these fixes. Distribution archives were not rebuilt. Physical-device accessibility, real provider behavior and production browser workflows remain outside this pass. Existing incorrect historical conversions or misfiled emails were not searched for or altered in production.
