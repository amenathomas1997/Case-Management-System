# Case Management System

A case-management system for assistive-technology businesses. It handles clients referred by vocational rehabilitation (VR) agencies, people paying for themselves, and free sliding-fee work. It covers:

- referrals and intake, clients, and training tracks with their authorizations
- hours, invoices and payments
- scheduling, a client portal and a counselor portal
- equipment loans and reports

There is also an iPhone app. Both are built to be used fully with a screen reader (JAWS, NVDA and VoiceOver).

Pricing, funding agencies, branding, email wording, the referral form and other business rules are set by an administrator on screen.

- Requirements: PHP 8.1+ and MariaDB 10.4+ (MySQL 8 should work).
- Install: copy `public/` to the web root and open `install.php` (see `dist/`).
- Upgrade: copy the new files, then open Administration, Database upgrade.

## The iPhone app

The app in `ios/` is built with Xcode and XcodeGen. Before building it for your organisation, change the block of `CMS_` settings at the top of `ios/project.yml`:

- your app ID and Apple developer team
- the name under the icon and how VoiceOver says it
- the sign-in line
- your server's address

Leave the server empty to have the app ask for it at sign-in. Replace the app icon (ios/Sources/Assets.xcassets/AppIcon.appiconset) and, if you like, the brand colours (the FC colour sets there, which keep 7:1 contrast). Then run `xcodegen generate --spec ios/project.yml --project ios` and archive in Xcode.

## Licence

Copyright (C) 2026 Example Agency

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, version 3. The full text is in [LICENSE](LICENSE).

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

### Third-party code

- `public/inc/fpdf/`: FPDF 1.86 by Olivier Plathey, released as freeware with no usage restrictions. It is compatible with the GPL.
- `public/assets/qrcode.js` was written for this project, under the same licence.
