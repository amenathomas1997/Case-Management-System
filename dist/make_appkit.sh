#!/bin/bash
# Pack the phone apps' source into public/inc/appkit/app-source.zip (4.18.0): the website's
# Phone apps page (phone_apps.php) fills in an organisation's own name, server, app ID and icon
# and hands it out as their app kit. Run before each release that changes android/ or ios/;
# tests/full_schema.php checks the zip matches the committed sources.
# Left out: build output, the maintainer's store guides and upload script, and the Xcode project file (made
# from ios/project.yml by xcodegen, so it always carries the organisation's own values).
set -euo pipefail
cd "$(dirname "$0")/.."
# Only files git knows: a stray file on this machine never reaches another organisation.
UNTRACKED=$(git ls-files --others --exclude-standard -- android ios | grep -Ev '(^|/)\.DS_Store$' || true)
if [ -n "$UNTRACKED" ]; then echo "Refusing to build: these files are not tracked by git. Add or remove them first:" >&2; echo "$UNTRACKED" >&2; exit 1; fi
OUT=public/inc/appkit/app-source.zip
mkdir -p public/inc/appkit
rm -f "$OUT"
git ls-files -- android ios | grep -Ev '^android/PLAY_STORE\.md$|^ios/(TESTFLIGHT\.md|testflight\.sh|ExportOptions\.plist)$|^ios/[^/]+\.xcodeproj/' > /tmp/appkit_files.$$
git ls-files LICENSE >> /tmp/appkit_files.$$
# Fixed timestamps, so the same sources always give the same zip.
STAGE=$(mktemp -d)
tar -cf - -T /tmp/appkit_files.$$ | tar -xf - -C "$STAGE"
find "$STAGE" -exec touch -t 202601010000 {} +
(cd "$STAGE" && LC_ALL=C sort /tmp/appkit_files.$$ | zip -X -q -9 -@ "$OLDPWD/$OUT")
rm -rf "$STAGE" /tmp/appkit_files.$$
echo "Built $OUT ($(wc -c < "$OUT" | tr -d ' ') bytes, $(unzip -l "$OUT" | tail -1 | awk '{print $2}') files)"
