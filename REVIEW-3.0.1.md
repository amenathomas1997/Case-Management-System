# Review of the 3.0.1 baseline

Reviewed baseline: `7722582`. These changes are local and have not been deployed.

## Confirmed findings and fixes

- **High — offline data loss:** iOS discarded server-rejected queue entries and entries without a verified owner. Only confirmed successful uploads are now removed, with removal constrained to the uploading account. Rejected and legacy entries remain stored with an explanation. The existing rejection regression failed on the baseline and passes after the fix; an unknown-owner regression was added.
- **High — trash integrity:** snapshot/deletion and restore/trash cleanup were separate writes. Database errors could leave stale snapshots, and cleanup could delete the file of a restored document. Transactions and row locks now serialize consumption of each trash entry. Cleanup checks live documents and legacy duplicate snapshots before deleting a file. Both manual and scheduled cleanup use the shared helper. Forced-failure tests verify rollback.
- **High — billed log deletion race:** the unbilled check happened before deletion without coordinating with invoice generation. Deletion now takes the track lock in invoice order and rechecks billing status under the work-log lock. The page only announces deletion when the helper succeeds.
- **Medium — misleading mobile success:** the goal-status picker ignored failed requests and announced success. It now reports failure and prevents duplicate submissions for that goal. Track-note refresh failures are also visible while retaining previously loaded content.
- **Medium — archived learning resources:** archival made existing assignments impossible to update or close. Existing assignments retain their authorized, immutable parent in web and native edit metadata; new assignments still require a published resource. Portal publication rules remain unchanged.
- **Low — malformed AT input:** array/object field values are rejected before titles or identifiers are cast. Homework edits similarly reject compound values.

## Verification

- 252 passing fresh-schema integration checks using only a temporary MariaDB database and synthetic records, including new trash rollback, duplicate cleanup, billed-log protection, archived-learning lifecycle, and malformed-input cases.
- 40 audit regressions and 22 security regressions passed.
- Nine Swift offline-queue checks passed, using production queue logic with isolated storage and mocked HTTP.
- Five Node test files and four cron checks passed.
- PHP syntax checks passed across 147 application, cron, and test files; changed helpers were checked again after the final edits. Form-message and automated accessibility checks passed.
- The complete iOS arm64 simulator Debug build succeeded with signing disabled after the queue and goal changes.
- `git diff --check` passed.

## Coverage and outstanding work

This pass inspected shared core/authentication/session/permission helpers, inventory transactions, AT service validation and lifecycle behavior, AI context/governance code, homework editing, portal write handling, invoices/downloads, trash and scheduled cleanup, and relevant native API/track-detail paths. The integration suite exercises additional web/API routes and real Swift response models; runtime coverage is not equivalent to reading every line.

A complete independent line-by-line review of every remaining PHP route, Swift screen, JavaScript asset, migration, and third-party dependency has not been completed. Live identity providers, email delivery, AI providers, production hosting, and physical-device interaction remain unverified. Offline entries without a known owner are preserved, but a user-facing recovery/export workflow is still needed. Existing review reports contain other limitations that remain applicable.

No production configuration or records were used, and no real AI, email, or external messaging calls were made. No schema migration is needed for these fixes. Distribution packaging has not been regenerated in this pass.
