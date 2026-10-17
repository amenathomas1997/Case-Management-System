# Release 4.4.0 — October 16, 2026

Web 4.4.0 (no iPhone app change). Apply dist/migrations/mig_4.4.0.sql (schema_version only; the lists are settings), or use Database upgrade.

The second open-source release: **Lists and time limits** (lists.php, administrators; inc/lists.php).
- **Form lists.** Each has one item per line, starts as the built-in constant, and is stored as JSON (list_<key>) only when it differs. Saving a group checks every box in it, and a value removed from a list stays on records that have it. The lists:
  - vision statuses
  - case closure reasons
  - referral close reasons, with the words the referrer's email uses ("Reason | words")
  - scored assessment level names and curriculum competency level names (exactly five, 0 to 4)
  - device request categories
  - incident types and access booking types
  - release of information recipient types and information items
- **Time limits.** Empty means built-in; saving all boxes empty puts every value back.

  | Limit | Built-in | Used in |
  |---|---|---|
  | Authorization warning percent | 80 | compliance.php, the app's compliance action, daily alerts (hours and funds) |
  | "Expiring soon" days | 30 | compliance, Dashboard, track page, app, action center |
  | Expiry alert days | 7, 14, 30 | daily alerts |
  | Closure follow-up days | 30, 60, 90 | |
  | Device check-in days | 30, 60, 90 | |
  | Loan due days | 60 | |
  | Counselor portal link days | 180 | website and app |
  | Consent and release signing link days | 60 | |
  | Consent and release validity days | 365 | kept in their old settings, consent_validity_days and release_validity_days |
- INCIDENT_TYPES, BOOKING_TYPES and COMPETENCY_LEVELS moved from their pages into inc/lists.php. The modules that use lists load inc/lists.php themselves, and each list's constant is resolved only when that list is asked for, so a module loaded on its own works.
- **Not yet in this release** (4.5.0): lists held in database enum columns (client types, statuses, service types, note types, document categories, equipment categories and conditions, maintenance types, report cadences, referral note types and sources); the iPhone app's copies of the lists. A list changed here that the app also offers (closure reasons, device request categories) is checked by the server, so an old choice sent from the app is refused with a message until 4.5.0.
- **Tests:** tests/v44_cases.php is new.

Review: no security holes. Every finding was fixed:
- the daily expiry alert marks the configured days, so custom days no longer repeat it every day
- a changed expiry date clears every exp* mark
- the usage alert clears 5 points below the configured percent, not at a fixed 75
- funds are compared in whole cents (no floating-point miss at the line)
- a vision status since removed from the list is kept when the client is saved again, and still shown as chosen
- the app's expiring count and the voice answer use the configured days
- the app is told its list may have changed when a choice is refused
- referral close reasons go to the app as text
- stored lists must hold text
- each reset box names its list, and the error summary shows after a refused save
- the staff guide says link periods can be changed
- tests now cover the alerts with custom days and percent, and re-saving a client with a removed value

Verified before commit:
- full_schema 1460, audit_regression, security, staging_scrub, and run_all.
- The migration rehearsal matched.
- Not checked in a browser or with a screen reader.
