# Putting the Android app on Google Play

Everything on the app side is ready: the store bundle builds and signs, the app targets Android 16 (API 36, which Google Play has required since August 31, 2026), and the listing text, icon, banner and screenshots are in `android/play-store/`. Only the steps below need you, because they need your Google account and your signing key.

## 1. Make your upload key (once, on this Mac)

The upload key proves an update comes from you. Keep it and its password somewhere safe, such as your password manager. If it is ever lost, Google can reset it, because Play holds the real app signing key.

In Terminal, from the repository folder:

```bash
"/Applications/Android Studio.app/Contents/jbr/Contents/Home/bin/keytool" -genkeypair -keystore ~/cms-upload.jks -alias upload -keyalg RSA -keysize 2048 -validity 10000
```

It asks for a password twice, then your name and organisation. Then create `android/keystore.properties`. This file is never committed (.gitignore lists it):

```
storeFile=/Users/maintainer/cms-upload.jks
storePassword=the password you chose
keyAlias=upload
keyPassword=the password you chose
```

## 2. Build the store bundle

```bash
cd android && JAVA_HOME="/Applications/Android Studio.app/Contents/jbr/Contents/Home" ./gradlew :app:bundleRelease
```

The file to upload is `android/app/build/outputs/bundle/release/app-release.aab`. Raise `cms.versionCode` in `android/gradle.properties` by one for every upload; Play refuses a number it has seen.

## 3. Google Play Console

1. Make a developer account at play.google.com/console. It is a one-time US$25 fee. Choose an organisation account; that needs your D-U-N-S number.
2. Create app: name "Case Management System", default language English (United States), App, Free.
3. **Testing, Internal testing**: create a release, upload `app-release.aab`, and accept Play App Signing when asked. Under Testers, add your staff's Google account emails (up to 100), save, and send them the opt-in link. Internal testing is available within minutes and needs no full review. Staff can start using the app from here.
4. Before a wider (closed or production) release, Play asks for the items below, under **Policy and programs, App content** and **Grow, Store presence**.

### Store listing (Grow, Store presence, Main store listing)

- **App name:** Case Management System
- **Short description (80 characters at most):** Case management for assistive technology staff: clients, sessions and notes.
- **Full description:**

  The phone app for staff of Example Agency. It uses the same records as the website: anything saved in one shows in the other.

  - Clients: contact details, counselor, accessibility preferences, training tracks and case notes
  - Schedule: book, check in, complete, move or cancel sessions, and join online sessions
  - Hours, mileage, signatures and documents from the field
  - Messages with clients and their counselors, also by text message
  - Tasks, alerts, referrals, equipment loans and device follow-ups
  - Works without a signal: entries are kept encrypted and sent when you reconnect
  - Built for screen readers: every screen is tested with TalkBack

  For staff of organisations using the Case Management System. You need an account from your organisation to sign in.

- **App icon:** `play-store/icon-512.png`
- **Feature graphic:** `play-store/feature-graphic.jpg`
- **Phone screenshots:** the five files in `play-store/screenshots/`. They come from the test server and show only made-up people.
- **Category:** Business. **Contact email:** your support address. **Privacy policy:** https://cms.example.org/privacy.php

### App content answers

- **App access:** all functions need a sign-in. Give Google a reviewer account on the live site that can see only made-up clients. Make a separate staff user and a test client with no real data, and turn on an authenticator-app code you can share. Without one, a closed or production release is rejected. Internal testing doesn't need it.
- **Ads:** no ads.
- **Content rating:** answer the questionnaire as a business or productivity app with no violence, sexual content, gambling or user-to-user public sharing. The result is "Everyone".
- **Target audience:** 18 and over.
- **News app:** no. **Government app:** no. **Financial features:** none.
- **Health apps declaration (every app must complete it):** choose that the app has no health features in Google's list; it is business software for staff, not a medical device. Its client records hold health-related case notes, declared under Data safety.
- **Data safety:**
  - Data is encrypted in transit: yes.
  - Users can ask for their data to be deleted: yes, through their organisation.
  - Collected, used for app functionality only, never shared or sold:
    - Personal info: name, email address, phone number, address
    - Health info: disability and accessibility notes in client records
    - Messages: in-app messages
    - Files and docs: documents uploaded from the phone
    - Audio: only if you count the voice assistant. Speech is turned into text by the phone, and only the text is sent.
    - App info and performance: none.
  - Nothing is shared with third parties for their own use. The optional AI features send text to the AI provider as a service provider, which Play does not count as sharing.

## What was checked

- The signed release bundle and APK build with shrinking on. The encryption library needed two "don't warn" rules, now in `app/proguard-rules.pro`.
- The release APK starts on the emulator and shows the sign-in screen for cms.example.org. Signing in to the live site from it was not tried.
- The debug build targeting Android 16 signs in to the local test server and works as before.
