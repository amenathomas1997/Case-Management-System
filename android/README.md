# The Android app

The Android app uses the same server, the same sign-in and the same records as the website and the iPhone app. Anything saved in one shows in the others straight away; the phone keeps nothing of its own except entries written without a signal, until they are sent.

## What it does

Nearly everything the iPhone app does.

- **Sign in** with email, password and the emailed code (or an authenticator app's code). A build without a server asks for the organisation's web address first.
- **Home**: the dashboard's counts, authorizations ending soon, the newest referrals and case notes, unread alerts, and anything waiting to send.
- **Clients**:
  - search, add a client, and open a client: contact details (a double tap calls or emails), counselor, accessibility preferences, training tracks and case notes
  - edit the client's details, including closing a case with its reason
  - add a case note
  - documents (opened in another app) and homework (complete it, assign it)
  - a training track: hours used and when the authorization runs out, goals with progress, expenses, log hours, and the track's details
- **Schedule**: schedule a session (once or weekly, with authorization warnings to approve), then sessions that ended and wait for an outcome, and upcoming ones by day. For each one:
  - check in
  - complete it, with hours, miles and a note (if hours were already logged that day, say whether they are this session's)
  - move it to another time
  - record a no-show, or cancel
  - join the online meeting
- **Tasks**: open tasks, soonest first; mark one done or reopen it; add a task.
- **More**:
  - Search
  - Alerts, with Mark all as read
  - Referrals: each in full, with Mark as reviewed, assign, close, reopen, make a client, or add to an existing client
  - Messages with clients and their counselors
  - Equipment: lend and return
  - Device follow-ups: record, skip, ask again later
  - Pipeline, Waitlist, Compliance, Invoices (mark a draft submitted), Incidents (report one, change its status)
  - Offline entries
  - My account: change your password, the authenticator app, your calendar address, sign out everywhere, and sign out

Still only on the website and the iPhone app: the AI tools and voice assistant, scored assessments, AT services, programs, contracts, bookings, procurement, accommodations, competencies, the knowledge base, signatures and uploading documents.

## Without a signal

- The last copy of the client list, each client opened, the schedule and device follow-ups is kept, and shown with when it was saved.
- Case notes, hours, check-ins, completed tasks, goal progress, expenses, device follow-ups and completed homework are kept to send later.

Both happen only on a phone with a screen lock, and everything kept is removed when someone signs out.

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

## Testing against a local copy of the website

A debug build (never a release build) may use plain http to the computer it runs on, so the app can be tried on the Android emulator against a local copy of the website with made-up records:

1. Make a local database from `dist/schema_full.sql` and a `private/config.php` that points at it, with `base_url` set to `http://10.0.2.2:8099`.
2. Start the website with:

   ```bash
   php -S 0.0.0.0:8099 -t public
   ```

3. Build without the built-in server:

   ```bash
   ./gradlew -Pcms.defaultServer= :app:assembleDebug
   ```

4. Install the app on the emulator, and sign in with the address `http://10.0.2.2:8099`.

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
