# Putting the Android app on Google Play

This is how an organisation publishes its own build of the Android app. Google requires each organisation to publish under its own developer account, so every step below uses your account, your app ID and your signing key. Nothing about another organisation's listing is kept in this repository.

## 0. Your app's identity

Set your app ID, names and server in `android/private.properties` (git-ignored) or in `android/gradle.properties`, and put your icon in `android/private-res/` (see "Building your own apps" in README.md). Your app ID (for example `org.yourorg.cms`) can never change after the first upload.

## 1. Make your upload key (once)

The upload key proves an update comes from you. Keep it and its password somewhere safe, such as a password manager. If it is ever lost, Google can reset it, because Play holds the real app signing key.

```bash
keytool -genkeypair -keystore ~/cms-upload.jks -alias upload -keyalg RSA -keysize 2048 -validity 10000
```

(`keytool` comes with Java; Android Studio's copy is in its `jbr/bin` folder.) It asks for a password twice, then your name and organisation. Then create `android/keystore.properties`. This file is never committed (.gitignore lists it):

```
storeFile=/path/to/cms-upload.jks
storePassword=the password you chose
keyAlias=upload
keyPassword=the password you chose
```

## 2. Build the store bundle

```bash
cd android && ./gradlew :app:bundleRelease
```

Gradle runs on Java 21 (Android Studio's own runtime works: set `JAVA_HOME` to it). The file to upload is `android/app/build/outputs/bundle/release/app-release.aab`. Raise `cms.versionCode` in `android/gradle.properties` by one for every upload; Play refuses a number it has seen.

## 3. Google Play Console

1. Make a developer account at play.google.com/console (a one-time US$25 fee). An organisation account needs your D-U-N-S number.
2. Create app: your app's name, its default language, App, Free.
3. **Testing, Internal testing**: create a release, upload `app-release.aab`, and accept Play App Signing when asked. Under Testers, add your staff's Google account emails (up to 100), save, and send them the opt-in link. Internal testing is available within minutes and needs no full review.
4. Before a wider (closed or production) release, Play asks for the items below, under **Policy and programs, App content** and **Grow, Store presence**.

### Store listing (Grow, Store presence, Main store listing)

Prepare these yourself, from your own build:

- **App name** and a **short description** (80 characters at most), for example "Case management for assistive technology staff: clients, sessions and notes."
- **Full description:** what your staff can do with the app. A starting point:
  - Clients: contact details, counselor, accessibility preferences, training tracks and case notes
  - Schedule: book, check in, complete, move or cancel sessions, and join online sessions
  - Hours, mileage, signatures and documents from the field
  - Messages with clients and their counselors, also by text message
  - Tasks, alerts, referrals, equipment loans and device follow-ups
  - Works without a signal: entries are kept encrypted and sent when you reconnect
  - Built for screen readers: every screen is tested with TalkBack
- **App icon** (512 by 512 pixels) and **feature graphic** (1024 by 500 pixels) in your own branding.
- **Phone screenshots:** take them from a test server with made-up people only, never real client records.
- **Category:** Business. **Contact email:** your support address. **Privacy policy:** your site's `privacy.php` page (for example https://cms.example.org/privacy.php).

### App content answers

- **App access:** all functions need a sign-in. Give Google a reviewer account on your site that can see only made-up clients: a separate staff user, a test client with no real data, and an authenticator-app code you can share. Without one, a closed or production release is rejected. Internal testing doesn't need it.
- **Ads:** no ads.
- **Content rating:** answer the questionnaire as a business or productivity app with no violence, sexual content, gambling or user-to-user public sharing. The result is "Everyone".
- **Target audience:** 18 and over.
- **News app:** no. **Government app:** no. **Financial features:** none.
- **Health apps declaration (every app must complete it):** the app has no health features in Google's list; it is business software for staff, not a medical device. Its client records hold health-related case notes, declared under Data safety.
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

## Notes

- The release bundle is shrunk with R8. The encryption library needs two "don't warn" rules, already in `app/proguard-rules.pro`.
- Try the release APK on an emulator or phone before uploading: it should start on the sign-in screen for your server (or ask for the server, when the build names none).
