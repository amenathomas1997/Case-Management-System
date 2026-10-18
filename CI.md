# Continuous integration

`.github/workflows/ci.yml` runs on every push, every pull request, and on demand
(Actions tab, "CI", "Run workflow"). It only tests. It never deploys, holds no
production secrets, and never connects to the production server or database:
its database is a throwaway MariaDB 10.11 container that is deleted when the run
ends. Production is still deployed by hand with the owner's deploy scripts.

## Reading the results

On GitHub, open the repository's **Actions** tab, or the "Checks" section of a
pull request. Each run has two jobs; each job lists its steps in order, and each
step shows passed or failed. Open a failed step to read its log. The test suites
print one `PASS:` line per check and stop at the first failure, so the last lines
of a failed step name the check that failed.

The GitHub mobile app and email notifications also report a failed run.

### Job 1: PHP 8.1 + MariaDB suites (required)

Ubuntu, PHP 8.1 (the production version), Node 20 and Python 3.12. Steps:

1. **Lint PHP**: `php -l` over `public/`, `public/inc/`, `cron/` and `dist/`.
   A failure names the file.
2. **tests/run_all.sh**: every Node suite (`tests/*.test.cjs`), the PHP checks
   that need no database, the Python suites, and `tests/installer_e2e.sh` (the
   white-label installer end to end in the `cms_installer_e2e` database). The
   step's log ends with `All suites passed.` or with `FAILED:` and the list of
   suites that failed; scroll up to the `== suite` line for each one's output.
   `tests/ios_api_regression.py` is reported as SKIPPED here on purpose: it
   needs Apple's Swift toolchain, so it runs in the iOS job instead.
3. **tests/full_schema.php**, **tests/audit_regression.php**,
   **tests/security.php**, **tests/staging_scrub.php**: the database suites,
   each against a freshly emptied `cms_audit_test` database. They run even when
   an earlier step failed, so one run shows every failing suite.
   `full_schema.php` is the slowest (several minutes).
4. **tests/accessibility_browser.js** (4.25.0): the pages `full_schema.php` rendered, opened in Chrome with
   Playwright: no unlabelled controls, duplicate ids or broken ARIA references, no page wider than the
   screen at 320 and 1280 pixels with 100% and 200% text, and the keyboard checks. A failure lists each
   page (its file name names the page) and the problem.

### Job 2: iOS simulator build (optional)

macOS: generates the Xcode project with XcodeGen, builds scheme CaseManagement for the
simulator without code signing, then runs `tests/ios_api_regression.py`. It is
marked optional (`continue-on-error`), so a failure here shows as a warning and
does not fail the run. It does not archive, sign or upload to TestFlight.
macOS minutes cost more on a private repository; delete the `ios:` job from
`ci.yml` if that matters more than the check.

## Running the same checks locally

See each test file's header. In short, with an empty local `cms_audit_test`:

```bash
CMS_TEST_DSN="mysql:host=localhost;dbname=cms_audit_test" CMS_TEST_USER=$USER CMS_TEST_PASSWORD= bash tests/run_all.sh
```

then each database suite (`php tests/full_schema.php` and so on) with the same
three variables, emptying the database between suites.
