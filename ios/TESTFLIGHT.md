# Putting the iPhone app on TestFlight

This is how an organisation publishes its own build of the iPhone app. Apple requires each organisation to publish under its own developer account, so every step below uses your account, your bundle ID and your team.

The app side is ready:
- the release archive builds
- both the app and its home-screen widget carry Apple's privacy manifest
- the encryption question is answered in the app (standard HTTPS only, so no export paperwork)
- every permission the app asks for is explained in plain words

## 0. Your app's identity

Set your bundle ID, Apple team, names and server in `ios/Private.xcconfig` (git-ignored) or in `ios/project.yml`, and add your icon as `ios/Sources/Assets.xcassets/PrivateAppIcon.appiconset` (see "Building your own apps" in README.md). Your bundle ID (for example `org.yourorg.cms`) can never change after the first upload.

## 1. Once: the App Store Connect record

1. Your organisation's Apple Developer Program membership must be active (US$99 a year), and `CMS_TEAM` must be its 10-character team ID (Apple Developer, Membership).
2. In Xcode, open Settings, Accounts, and sign in with an Apple ID that is an Admin or App Manager on that team.
3. At appstoreconnect.apple.com, go to Apps, the add button, then New App:
   - Platform iOS
   - Your app's name and primary language
   - Your bundle ID. If it is not in the list yet, run step 2 once: the first upload registers it.
   - A SKU of your choice (any short code, for example `cms`)
   - User access: Full access

## 2. Each upload

```bash
ios/testflight.sh
```

The script:
- generates the Xcode project and archives the app
- signs it with your Xcode account, making the certificates and profiles it needs the first time
- uploads it

Before each upload after the first, raise `CURRENT_PROJECT_VERSION` in `ios/project.yml` by one, in both places (the app and the widget). Apple refuses a build number it has seen.

## 3. TestFlight

- **Internal testing (your staff):**
  - In App Store Connect, open the app, TestFlight, Internal Testing. Add a group and add staff. Each must be a user on your App Store Connect team; up to 100 people.
  - They install the TestFlight app on their iPhone, accept the email invitation, and install. No Apple review is needed.
  - Builds expire after 90 days, so upload a fresh one at least that often.
- **External testing (people outside your team, up to 10,000):**
  - Needs Beta App Review the first time, usually within a day.
  - Apple needs a sign-in to review with. Make a staff user on your site that sees only made-up clients, with an authenticator-app code you can share. Put it under Test Information, Sign-in required.

## App Store privacy answers (App Store Connect, App Privacy)

These match `Sources/PrivacyInfo.xcprivacy`.

- **Tracking:** no.
- **Data collected:** each item is linked to the user, used for app functionality only, and never for tracking:
  - Contact info: name, email address, phone number, physical address
  - Health and fitness: health (disability and accessibility notes in client records)
  - User content: emails or text messages (in-app and texted messages), photos (document scans), other user content (case notes)
- **Privacy policy URL:** your site's `privacy.php` page (for example https://cms.example.org/privacy.php).

Screenshots for the App Store listing should come from a test server with made-up people only.
