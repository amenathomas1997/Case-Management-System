# Release 4.19.1 — October 17, 2026

Web 4.19.1. The apps are unchanged. No database change. Writes the setting update_attempted.

The owner reported that Check for updates showed the wrong time and did not update.

**Cause, found on the live server:**
- The live config.php predates the update setting, so checking was off.
- Every check therefore failed without saying so.
- Administration kept showing the last successful check: October 16, 5:23 pm, from 3.0.55, with "latest 3.0.55". That looked like a wrong time and a stuck update.
- Run from the live server's PHP, the GitHub check itself works (4.19.0, 3:00 pm CDT), and the web folder is writable.
- The updater did not recognise the live jobs folder (private/cron), which has no cron_config.php.

**Fixes:**
- **update_source():** a site that never chose checks GitHub, and only an explicit `off` turns checking off. Used by the check, the updater and Administration.
- **Administration:**
  - A check older than the installed version shows as "Not checked since this version was installed".
  - A failed attempt is said, with its time in the site's zone.
  - "Off" is said plainly.
  - System health ignores a stale latest version the same way.
- **The updater** recognises a jobs folder by its jobs (watchdog.php), not only by cron_config.php.

**Note:** while diagnosing, a command of mine wrote one line ("Fatal error at Command line code:1") into the live app_errors.log. It was removed before the watchdog ran, so no alert was sent.

**Tests:** 5 more checks in tests/v419_cases.php:
- the default source
- explicit off
- a stale check not shown as current
- a failed attempt said
- an older site's jobs folder found
