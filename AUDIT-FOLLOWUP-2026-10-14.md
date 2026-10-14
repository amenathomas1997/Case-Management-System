# Audit follow-up — October 14, 2026

Status: deployed as 3.0.21 on October 14, 2026; see DEPLOYMENT-3.0.21.md for verification and backups. Active source is the clean nested Case-Management-System repository, based on c4d0956. Existing Claude changes were preserved.

## Website navigation and unsaved changes

Shared pages now provide Go back, Dashboard and Main navigation links above the header and in the footer. Back uses a same-origin previous PHP page when available, otherwise Dashboard. Main navigation expands the mobile menu and moves keyboard focus to it. Signed-in visitors to shared public/help pages receive their permission-filtered navigation. Standalone error screens and accessible HTML exports have return links.

Post forms are checked for changed editable values, including checkbox, radio, select and file inputs. Leaving by a regular link offers Keep editing or Leave without saving. Known note/assessment saves and an unambiguous button explicitly labelled Save also offer Save changes, using real form validation and submission. Saving follows the form's normal server response; it does not assume success or automatically continue to the originally clicked destination. Other action forms are not submitted as an inferred save. No draft contents are stored by the navigation script outside page memory.

Browser back, refresh and tab close use the browser's standard unsaved-change warning. Browser restrictions govern its display, especially mobile app termination; this is not durable autosave. Downloads, new-tab links and same-page navigation do not discard the current draft. Failed or deferred submissions retain protection. The custom dialog supports Escape, keyboard focus and focus restoration.

## Audit follow-up fixes

Corrected the shared-layout strict_types fatal error, restored logout CSRF validation with an accessible retry screen, preserved voice confirmations through microphone silence, improved repeated action labels, restored intake drafts after session recovery, and corrected validation return targets. Existing accessibility fixes and iOS changes remain in the working tree.

## Verification

- PHP syntax: 144 production files passed.
- tests/run_all.sh: passed (Node, PHP and Python checks, including Swift API regression checks).
- Dedicated local cms_audit_test full-schema integration: 819 assertions passed, including migrations, stale/valid logout and restored intake drafts.
- Dedicated database audit regression suite: 61 assertions passed.
- Dedicated database security suite: 63 assertions passed.
- Final focused navigation tests passed after the additional Save-button handling; shared-layout accessibility checks and git diff --check passed.
- iOS simulator build succeeded during the prior audit follow-up on October 13. No new iOS edits were made for the website feedback.

No production database, external AI service or outgoing email was used for these tests.

## Remaining verification

Safari/macOS and iOS Safari keyboard/VoiceOver walkthroughs have not been performed for the new navigation dialog. Exercise a client note and track note: edit, choose Dashboard, Keep editing, Save changes, invalid Save, Leave without saving, browser Back and session-expiration recovery. Repeat mobile menu shortcuts and standalone errors.

Claude's accessibility inventory was generated against f9801f1, before the round-five changes. Its partial result contains 128 confirmed, 277 unverified and 91 rejected candidates. That historical inventory has not been fully reconciled against the current source; this follow-up does not claim a complete WCAG conformance audit or AAA certification. The code audit's completed 413-findings record is separate from this unfinished accessibility inventory. Preserve the snapshot worktree until that reconciliation is complete.

Before release, include public/assets/navigation.js in the package, review the complete working diff, and follow the repository release process. Version 3.0.21 includes a database migration; no deployment or production migration was performed in this follow-up.

## Controls review and account display settings

Low-vision controls now use a native, initially collapsed details/summary disclosure. My Account has Show/Hide radio choices and an explicit Save display settings action. Visibility is stored under the authenticated user's setting, with CSRF protection, and applies across signed-in browsers. Hiding the toolbar does not reset browser display preferences. Recovery controls remain in My Account. No schema migration is needed for this setting.

Combo boxes remain native selects by default. Expanded-list presentation remains a user-selected alternative, not a replacement for normal combobox keyboard semantics. No global arrow-key overrides, automatic Forms Mode changes, or automatic form submissions were introduced.

Date, local date/time and time inputs now offer explicit text entry as an alternative to the native picker. Text mode provides associated format instructions, preserves values and labels, checks calendar validity and the native required/min/max/step constraints, blocks invalid submission even on novalidate forms, and focuses the first invalid field. Invalid text cannot be silently discarded by switching back to the picker. Browser locale/calendar interaction remains native in picker mode. The new controls are in English with language attributes; full localization remains follow-up work.

Verification completed:
- 350 rendered dropdown/date/time controls across 144 synthetic page snapshots have accessible labels; dropdown options have names and the lists are nonempty in these fixtures.
- Chrome accessibility/layout suite passed on 144 pages, 576 reflow scenarios, ARIA references, unique IDs, keyboard table scrolling, skip links, menu state, palettes and print colors.
- Chrome date-entry tests passed for invalid calendar dates, required fields, date bounds, invalid time, datetime values, error focus, value preservation and picker restoration.
- Full-schema integration passed with four new account preference cases (823 total assertions).
- Non-database suites and focused disclosure/toolbar checks passed.

WCAG 2.2 AAA remains a target, not a verified conformance claim. The browser checks cover only the rendered fixtures and automated assertions. Outstanding: JAWS/Chrome and JAWS/Edge with the user's Forms Mode settings, the reported “unselected” behavior, NVDA, Safari/VoiceOver, mobile screen readers, and manual target-size/focus/keyboard/error review in all operational states. The original accessibility inventory still needs reconciliation.

Standards reviewed: W3C WCAG 2.2 Understanding 3.3.2 (labels/instructions), 3.2.5 (change on request), and the WAI-ARIA APG date picker dialog guidance. Sources: https://www.w3.org/WAI/WCAG22/Understanding/labels-or-instructions ; https://www.w3.org/WAI/WCAG22/Understanding/change-on-request ; https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/examples/datepicker-dialog/ .

Release update: all new website assets and the staff guide were included in the verified 3.0.21 deployment on October 14, 2026. The iOS changes were not distributed.
