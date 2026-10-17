# Putting the iPhone app on TestFlight

The app side is ready:
- the release archive builds
- both the app and its home-screen widget carry Apple's privacy manifest
- the encryption question is answered in the app (standard HTTPS only, so no export paperwork)
- every permission the app asks for is explained in plain words

Only the steps below need you, because they need your Apple account.

## 1. Once: the App Store Connect record

1. Your Apple Developer Program membership must be active for team  (the team in `ios/project.yml`). It costs US$99 a year.
2. In Xcode, open Settings, Accounts, and sign in with an Apple ID that is an Admin or App Manager on that team.
3. At appstoreconnect.apple.com, go to Apps, the add button, then New App:
   - Platform iOS
   - Name "Case Management System" (or "CMS" if the name is taken)
   - Primary language English (U.S.)
   - Bundle ID `org.example.cms`. If it is not in the list yet, run step 2 once: the first upload registers it.
   - SKU `cms-cms`
   - User access: Full access

## 2. Each upload

```bash
ios/testflight.sh
```

The script:
- archives the app
- signs it with your Xcode account, making the certificates and profiles it needs the first time
- uploads it

Before each upload after the first, raise `CURRENT_PROJECT_VERSION` in `ios/project.yml` by one, in both places. Apple refuses a build number it has seen. The current build is 54.

## 3. TestFlight

- **Internal testing (your staff):**
  - In App Store Connect, open the app, TestFlight, Internal Testing. Add a group and add staff. Each must be a user on your App Store Connect team; up to 100 people.
  - They install the TestFlight app on their iPhone, accept the email invitation, and install. No Apple review is needed.
  - Builds expire after 90 days, so upload a fresh one at least that often.
- **External testing (people outside your team, up to 10,000):**
  - Needs Beta App Review the first time, usually within a day.
  - Apple needs a sign-in to review with. Make a staff user on the live site that sees only made-up clients, with an authenticator-app code you can share. Put it under Test Information, Sign-in required.

## App Store privacy answers (App Store Connect, App Privacy)

These match `Sources/PrivacyInfo.xcprivacy`.

- **Tracking:** no.
- **Data collected:** each item is linked to the user, used for app functionality only, and never for tracking:
  - Contact info: name, email address, phone number, physical address
  - Health and fitness: health (disability and accessibility notes in client records)
  - User content: emails or text messages (in-app and texted messages), photos (document scans), other user content (case notes)
- **Privacy policy URL:** https://cms.example.org/privacy.php

## What was checked

- The Release archive builds (unsigned here, so your Apple account was not touched).
- The app and the widget each include their privacy manifest.
- The app has not been run on a real iPhone from this build; TestFlight is the first time.
