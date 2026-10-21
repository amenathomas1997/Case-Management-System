# Case Management System

A case-management system for assistive-technology businesses. It handles clients referred by vocational rehabilitation (VR) agencies, people paying for themselves, and free sliding-fee work. It covers:

- referrals and intake, clients, and training tracks with their authorizations
- hours, invoices and payments
- scheduling with reminders by email and text, a client portal and a counselor portal
- equipment loans, AT services, programs and reports
- staff agreements, training and credentials

There are Android and iPhone apps. Everything is built to be used fully with a screen reader (JAWS, NVDA, VoiceOver and TalkBack).

Pricing, funding agencies, branding, email wording, the referral form, lists and other business rules are set by an administrator on screen. Nothing needs code changes.

## Run your own

1. **Requirements:**
   - web hosting with PHP 8.1 or newer, with the pdo_mysql, mbstring, iconv, curl, zip and openssl extensions (gd is optional, for icons in your colours)
   - MariaDB 10.4 or newer (MySQL is not supported)
   - HTTPS on your address
   - cron jobs (most hosting control panels have them)
2. **Download** `cms_dist-<version>.tar.gz` from the newest [release](https://github.com/amenathomas1997/Case-Management-System/releases). Use the release, not the source code. Its README.txt explains every step in detail.
3. **Install:**
   - Create an empty database and user.
   - Upload `docroot/` to your web folder.
   - Create an empty `install.php.enabled` file beside `install.php`, then open `https://your-address/install.php`.
   - The installer takes your organisation's name, colours, database and first administrator. It writes the private configuration outside the web folder and locks itself.
4. **Scheduled jobs:** copy the bundle's `cron/` files into the folder the installer names, and add the cron lines it shows. They cover the nightly backup, the encrypted off-site copy, reminders and the watchdog.
5. **Make it yours** (Administration):
   - Settings: branding and icon, email, texting, updates
   - Microsoft 365 email, "Sign in with Microsoft", Teams meetings and Outlook calendar: follow [MICROSOFT-SETUP.md](MICROSOFT-SETUP.md)
   - Zoom meeting links: follow [ZOOM-SETUP.md](ZOOM-SETUP.md)
   - Lists and time limits, Public wording, Pricing rules, Funding agencies, client statuses
   - Templates, the referral form, staff agreements and training
6. **Your own phone apps:** Administration, Phone apps, downloads the Android and iPhone apps' source already set to your name, icon and server, with the steps to build them and give them to your staff.

## Building your own apps

The apps in `android/` and `ios/` build as a neutral example (app ID `org.example.cms`, the name "CMS", a plain clipboard icon, and a sign-in screen that asks for the server). To build them as your organisation's apps without changing tracked files, add these git-ignored files:

- **Android:** `android/private.properties` with any of the `cms.*` keys from `android/gradle.properties` (for example `cms.appId=org.yourorg.cms` and `cms.defaultServer=cms.yourorg.org`); its values win. Your icon goes in `android/private-res/mipmap-<density>/ic_launcher.png` (mdpi 48, hdpi 72, xhdpi 96, xxhdpi 144, xxxhdpi 192 pixels).
- **iPhone:** `ios/Private.xcconfig` with any of the `CMS_*` settings from `ios/project.yml`, written `CMS_BUNDLE_ID = org.yourorg.cms`; `ios/Overrides.xcconfig` reads it, so its values win. For your own icon, add the set `ios/Sources/Assets.xcassets/PrivateAppIcon.appiconset` (a 1024-pixel PNG and its Contents.json) and the line `CMS_APP_ICON = PrivateAppIcon`.

Neither the source nor the app kit that Phone apps hands out ever includes these files.

## Updates

New installations check this repository's releases. When a new version is out, Administration says so.

To install it, open Software update and choose Download and check. The update is installed only if it carries the publisher's signature. Make a database backup, then choose Install.

Before anything changes, the update copies the files it replaces. It then applies the database changes and swaps in the new files. If a step fails, it puts the old files back. Your configuration, uploads, icons and your own `.htaccess` changes are never replaced.

For this to work, the web server must be able to write its own files. Most hosting does this by default; if not, the page says which files it cannot write. To check somewhere else, or not at all, change Settings, Updates.

Installations from before 4.18.0 update once by hand from the release's install bundle, following its README.txt. After that, the button does it.

## Publishing releases (maintainers)

1. Tag the release.
2. Run `php dist/make_update.php <version>` to build and sign the update package. It needs the private signing key, which is never committed.
3. Run `dist/make_dist.sh` for the install bundle.
4. Attach all three files to a GitHub release named `v<version>`. Don't mark it as a pre-release, or installations won't see it.

`dist/make_appkit.sh` refreshes the phone apps' source that Phone apps hands out.

A fork that publishes its own releases sets `UPDATE_REPO` and `UPDATE_PUBLIC_KEY` in `public/inc/updater.php` to its own.

## Licence

Copyright (C) 2026 The Case Management System authors

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, version 3. The full text is in [LICENSE](LICENSE).

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

Security problems: see [SECURITY.md](SECURITY.md). When something goes wrong: [RUNBOOK.md](RUNBOOK.md). For your own security risk assessment: [RISK-ASSESSMENT.md](RISK-ASSESSMENT.md).

### Third-party code

- `public/inc/fpdf/`: FPDF 1.86 by Olivier Plathey, released as freeware with no usage restrictions. It is compatible with the GPL.
- `public/assets/qrcode.js` was written for this project, under the same licence.
