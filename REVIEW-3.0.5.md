# Audit of 3.0.5 — October 2, 2026

Baseline: `1aa2bf0`, including review of the 3.0.4 and 3.0.5 changes since the previous audit. Fixes remain local. The preexisting Xcode user-interface state change was preserved.

## Confirmed findings fixed

- **High — incomplete AI name redaction:** Both web and mobile “Ask your caseload” sent names embedded in the question. Goal/disability snippets were also shortened before redaction, allowing truncated names to survive matching. Questions now pass through the same tokenization, and narrative text is redacted before shortening. Tests inspect captured mock-provider payloads on both routes. Matching covers known full names in the included snapshot; this is not a general guarantee that arbitrary free text contains no identifiers.
- **High — report caseload boundary:** Analytics, outcome/funder reports, utilization and capacity aggregates included other clients for restricted staff. Following the owner's explicit decision, these now use scoped report sources. Funder lists and mobile utilization are also scoped. CSV/HTML and web/API regressions include a distinct outside-caseload client. Capacity targets remain full staff targets; restricted utilization views explain this.
- **Medium — historical financial figures changed with current rates:** Analytics used current track rates instead of work-log snapshots. It now uses recorded hourly/mileage rates with the existing legacy fallback. Outstanding invoices exclude drafts and subtract recorded payments, including a short payment on an invoice marked Paid.
- **Medium — fresh installer unreachable:** Apache denied the installer that the distribution instructed administrators to open. Installation remains disabled by default. A hosting administrator must create an empty `install.php.enabled` file; both PHP and Apache check this explicit opt-in. The marker is denied as downloadable content, excluded from bundles, and removed on successful installation. The installation lock still takes precedence. Bundle instructions were updated.
- **Medium — search errors presented as empty results:** Native search displayed “No results” on request failure and retained old results when shortening/clearing the query. It now shows an error, clears stale results, and checks a request identifier as well as query text before accepting responses.
- **Low — CSV warning output and escaping:** Expanded export tests exposed PHP CSV deprecation warnings. All public CSV writers now use an explicit shared escape configuration. A round-trip test covers quotes, backslashes, commas and embedded newlines; existing formula-neutralization checks remain in place.

## Verification

- 286 fresh-schema integration checks passed with synthetic data.
- 40 audit and 23 security checks passed.
- 11 Swift offline-queue checks passed with isolated storage/mock HTTP.
- Five Node test files, four cron checks, form-message checks and automated accessibility checks passed.
- Three installer-gate checks passed without loading production configuration or connecting to a database.
- 152 PHP files passed syntax checks, including the installer.
- Complete iOS arm64 simulator Debug build succeeded with signing disabled.
- `git diff --check` passed.

## Scope and remaining verification

See `AUDIT-COVERAGE-3.0.5.md` for the per-file inventory, source hashes and coverage classification. This audit combines targeted source inspection across the stack with broad regression execution. It does not establish that every unchanged line or workflow has been independently verified; the ledger explicitly records that distinction.

No live AI, email, SSO or calendar-provider call was made. Apache deployment behavior and a complete fresh installation still require hosting validation; the installer tests cover the PHP gate, not a production Apache instance. Physical-device VoiceOver and search interactions need hands-on verification. Existing audit limitations, including unknown-owner offline recovery and broader concurrency coverage, remain applicable. SSO JWT signature validation was inspected but not expanded in this pass; no live SSO verification was performed.

No deployment, version bump, migration or distribution-archive regeneration was performed. The changes require no database migration.
