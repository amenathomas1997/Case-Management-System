#!/bin/bash
# Archive the iPhone app and upload it to App Store Connect for TestFlight (see ios/TESTFLIGHT.md).
# Signs with the Apple account signed in to Xcode (Xcode, Settings, Accounts) for the team in
# ios/project.yml. Raise CURRENT_PROJECT_VERSION in project.yml before each upload.
set -euo pipefail
cd "$(dirname "$0")/.."
ARCHIVE="ios/build/CaseManagement.xcarchive"
rm -rf ios/build
xcodegen generate --spec ios/project.yml --project ios
xcodebuild -project ios/CaseManagement.xcodeproj -scheme CaseManagement -configuration Release \
  -destination 'generic/platform=iOS' -archivePath "$ARCHIVE" -allowProvisioningUpdates archive
xcodebuild -exportArchive -archivePath "$ARCHIVE" -exportOptionsPlist ios/ExportOptions.plist \
  -exportPath ios/build/export -allowProvisioningUpdates
echo "Uploaded. In a few minutes it appears in App Store Connect, TestFlight, once Apple has processed it."
