#!/bin/bash
# Run every test suite that needs no database: the Node suites (tests/*.test.cjs),
# the PHP checks that load no configuration, and the Python suites that use local
# test doubles. Every suite runs; the script exits non-zero when any one failed.
# The database suites (tests/full_schema.php, tests/audit_regression.php and
# tests/security.php) need an empty, dedicated test database and are run on their
# own, as each file's header says. tests/ios_api_regression.py needs swiftc (the
# Xcode command-line tools) and is reported as skipped where it is missing.
cd "$(dirname "$0")/.." || exit 1
failed=""
run() {
  echo "== $*"
  "$@" || failed="$failed
  $*"
}
for suite in tests/*.test.cjs; do run node "$suite"; done
run php tests/form_messages.php
run php tests/accessibility.php
run php tests/breadcrumbs.php
run python3 tests/cron_regression.py
run python3 tests/installer_gate.py
if command -v swiftc >/dev/null 2>&1; then
  run python3 tests/ios_api_regression.py
else
  echo "== SKIPPED tests/ios_api_regression.py: swiftc is not installed"
fi
if [ -n "$failed" ]; then
  echo "FAILED:$failed" >&2
  exit 1
fi
echo "All suites passed."
