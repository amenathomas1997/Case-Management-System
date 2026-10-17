# The Android app

The Android app uses the same server, the same sign-in and the same records as the website and the iPhone app. Anything saved in one shows in the others straight away; the phone keeps nothing of its own except entries written without a signal, until they are sent.

## What it does (first release)

Most of the daily work: the same jobs as the iPhone app's main tabs.

- **Sign in** with email, password and the emailed code (or an authenticator app's code). A build without a server asks for the organisation's web address first.
- **Home**: the dashboard's counts, authorizations ending soon, the newest referrals and case notes, unread alerts, and anything waiting to send.
- **Clients**:
  - search, and open a client: contact details (a double tap calls or emails), counselor, accessibility preferences, training tracks with the hours used, and case notes
  - add a case note
  - log hours and mileage on an active track
- **Schedule**: sessions that ended and wait for an outcome, then upcoming ones by day. For each one:
  - check in
  - complete it, with hours, miles and a note (if hours were already logged that day, say whether they are this session's)
  - record a no-show, or cancel
  - join the online meeting
- **Tasks**: open tasks, soonest first; mark one done or reopen it; add a task.
- **More**:
  - Alerts, with Mark all as read
  - Referrals (open, converted, closed), each referral in full, Mark as reviewed
  - Messages with clients and their counselors
  - Offline entries
  - My account and Sign out

Everything else (equipment, invoices, reports, AI tools, documents, assessments and the rest of the iPhone app's screens) is still on the website. It will be added to the Android app in later releases.

## How it keeps data safe (as the iPhone app does)

- The session and anything waiting to send are encrypted with a key held in the phone's hardware keystore. None of it goes into Google backups or transfers to a new phone.
- Client screens are hidden from the recent-apps preview and from screenshots taken by other apps.
- Only secure (https) servers are used.
- Entries written without a signal are kept only on a phone with a screen lock.
  - They are sent in order when the signal returns, each exactly once (the server recognises a resend).
  - One the server refuses stays under Offline entries, with the reason, until it is sent or discarded.
- A phone reported lost on the website removes everything the app keeps the next time it connects, and signs out.

## TalkBack

- Every screen has a heading.
- Each row in a list is one stop that reads as one sentence; a double tap opens it.
- A field's hint is part of its label, so it adds no extra stop.
- Choices open a plain list of options.
- Errors and confirmations are read out once.
- Buttons are at least 48 points high.

## Building it

You need Android Studio (it includes Java and the Android SDK).

1. Open the `android` folder in Android Studio, or from a terminal in that folder run:

   ```bash
   ./gradlew :app:assembleDebug
   ```

   The app is then at `app/build/outputs/apk/debug/app-debug.apk`.
2. The unit tests run with `./gradlew :app:testDebugUnitTest`, and the CI job builds, tests and lints on every push.

## Another organisation's build

Change only the `cms.*` lines in `gradle.properties`:

- `cms.appId`: your own application ID
- `cms.displayName`: the name under the icon
- `cms.spokenName`: how TalkBack should say the name
- `cms.signinTitle`: the line on the sign-in screen
- `cms.defaultServer`: your server's address. Leave it empty and the sign-in screen asks for it.
- `cms.versionName` and `cms.versionCode`: the version. Raise `versionCode` by one for every upload to Google Play.

Replace the icons in `app/src/main/res/mipmap-*`.

## Publishing to Google Play

1. Make an upload key once, and keep it safe (Google Play needs the same key for every update):

   ```bash
   keytool -genkeypair -v -keystore upload.jks -alias upload -keyalg RSA -keysize 4096 -validity 10000
   ```

2. In Android Studio choose Build, Generate Signed App Bundle, and pick that key. Or set it up in `app/build.gradle.kts` with a `keystore.properties` file that is never committed (it is in `.gitignore`).
3. Upload the `.aab` file in the Google Play Console. Start with Internal testing, as with TestFlight.
4. The Play Console asks for:
   - a privacy policy link: use the site's `/privacy.php`
   - the data safety answers: the app sends what staff type to your own server, and nothing to anyone else

Google Play requires each new app version to target a recent Android release (`targetSdk` in `app/build.gradle.kts`, currently 35). Raise it when Play asks.
