# Accessibility audit — October 10, 2026

Baseline: `b6a3aee` (3.0.13). The repository advanced during the audit; the final rendering and verification used this newer baseline. Changes are local and uncommitted. No deployment was performed.

The web review used [W3C's WCAG 2.2 guidance](https://www.w3.org/WAI/WCAG22/understanding/), focusing on labels and relationships, keyboard operation, reflow, contrast, focus and status messages. This is an engineering audit, not a conformance certification.

## Findings fixed

1. **P2 — Table screen-reader text caused whole-page horizontal scrolling.** Absolutely positioned hidden captions and action labels inside horizontally scrolling tables were positioned against an ancestor outside the scroll region. At 320 CSS pixels, 12 rendered pages developed horizontal page overflow. Making `.table-scroll` a positioning context retains the accessible text while containing it within the table's scroll region. Wide tables remain individually scrollable and keyboard reachable.
2. **P2 — Analytics tables lacked scroll regions.** The monthly-value table overflowed with enlarged text. All four visible analytics tables now use named, keyboard-focusable scroll containers. The shared footer removes the extra tab stop when a table fits.
3. **P2 — Equipment labels exceeded narrow screens with enlarged text.** The fixed minimum grid width and nonwrapping QR/text row overflowed at 200% text size. The grid now respects its container width, rows wrap, and QR images scale without changing aspect ratio. Ordinary themes, custom contrast palettes and black-on-white printing retain readable label text.
4. **P2 — Equipment labels offered a QR-only shortcut.** The equipment name now links to the same record as the QR code, providing a keyboard and screen-reader equivalent without using a camera.

## Contact address

Set `app.support_email` in the local, ignored `private/config.php` to **maintainer@example.org**. Existing shared footer, referral-help and counselor-portal contact paths already consume this setting. The sender mailbox and backup address are unchanged. The local configuration must be included in the next authorized deployment; this audit did not change the live site.

## Verification

- 70 synthetic HTML pages rendered from the installation schema. No production records or credentials were used to render them.
- Browser checks: programmatic control names, unique IDs and existing ARIA reference targets; 280 reflow scenarios (320 and 1280 CSS-pixel viewports, with 100% and 200% in-app text size).
- Real Chrome keyboard checks: skip link focuses main content, an overflowing referral table scrolls with arrow keys, and the mobile menu reports its open/closed state.
- Equipment labels: link contrast across six explicit palettes, enlarged layout, and print colors.
- 79 PHP accessibility checks, including theme contrast and public-contact selection.
- All seven JavaScript regression test files passed, covering voice-assistant focus, timeout dialogs, form recovery, AI draft controls and low-vision preferences.
- 655 integration checks passed, including the newly rendered pages and migration parity. The final static print-style adjustment was then copied from the PHP source into the synthetic label snapshot and checked in the browser.
- Changed PHP syntax, browser-test JavaScript syntax and whitespace checks passed.

`tests/accessibility_browser.js` provides repeatable browser checks. First run `tests/full_schema.php` against the dedicated, empty `cms_audit_test` database with `CMS_TEST_HTML_DIR` pointing to a temporary snapshot directory. Then run the browser script with the same directory, Playwright available through Node's module path, and Chrome installed. The browser intercepts requests and serves only synthetic snapshots and repository assets; all other requests are blocked.

## Review boundaries

The source review also covered the shared web navigation, timeout modal, voice assistant, AI draft controls, referral conditional fields, and selected iOS form, error, signature, document/homework and referral views. Existing iOS accessibility labels, signature alternatives, announcements and Dynamic Type patterns were inspected; no iOS code was changed in this pass.

The synthetic pages cover selected states, not every combination of records, permissions, form errors or translated content. The browser label scan is a basic semantic check, not a full accessibility-engine audit. Viewport/text enlargement tests do not replace native browser-zoom testing. Hands-on NVDA/JAWS/VoiceOver, braille, Switch Control, iOS accessibility-size layout testing, generated-document accessibility and live provider workflows remain unverified. No claim of complete WCAG AA or AAA conformance is made.

## Subsequent deployment

After this audit, the user authorized deployment. The fixes and contact setting were deployed as web release 3.0.14 on October 10, 2026. See [deployment record](DEPLOYMENT-3.0.14.md) for backups and live verification. The earlier local-only statements describe the state when the audit finished.
