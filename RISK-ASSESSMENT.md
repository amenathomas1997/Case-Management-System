# Security risk assessment: Case Management System

A starting point for an organisation's own HIPAA-style security risk assessment of this software (45 CFR 164.308(a)(1)), written for version 4.19.0. It describes what the software does to protect health information. Each organisation still assesses its own hosting, staff, devices and practices. It must also sign business associate agreements with its providers, review this yearly, and keep the dated copy.

This is not legal advice. Have your privacy officer or a qualified adviser review it.

## What the system holds

- **Clients:** names, contact details and dates of birth. Disability, vision and accessibility information. Case notes and assessments. Documents and signatures. Authorizations and invoices. Messages.
- **Staff:** accounts, agreements, training and credentials.
- **Where:** a MariaDB database and an uploads folder on the organisation's own server; nightly backups on the same server; an encrypted copy emailed off-site nightly (check the cron line runs it every night); encrypted entries waiting to send on staff phones.

## Safeguards built into the software

| Area | What the software does |
|---|---|
| Access control | A personal account for every staff member. Permission groups, caseload limits that confine a user to their own clients, and holding client records until required agreements and training are done. |
| Authentication | Passwords plus a required second step (an emailed code or an authenticator app). Optional Microsoft sign-in. Sessions time out. Switching an account off ends every session at once, and the phone apps can be signed out separately. |
| Audit | Every view of a client record and every change is in the audit log, with who did it and from which app. |
| Integrity | CSRF checks on every form. Prepared SQL statements throughout. Signed releases: an update installs only if it carries the publisher's signature. |
| Transmission | HTTPS only. The apps refuse plain http. Messages from Twilio are signature-checked, and update packages from GitHub install only with the publisher's signature. |
| Minimum necessary | Texts and alert emails carry no case details. AI features mask names before text leaves the server, and are off until an administrator enables them. |
| Devices | The apps keep data only on phones with a screen lock, encrypted with a hardware-held key. Nothing goes into cloud backups. The Android app blocks screenshots and the recent-apps preview. |
| Contingency | Nightly backups, checked automatically. A daily AES-256 encrypted off-site copy. A tested restore script (rehearsed by every full test run with a test database). One-button updates that put the old files back on failure. A recovery runbook (RUNBOOK.md). |
| Monitoring | A watchdog every 10 minutes: the site, the database, backup freshness, every scheduled job, and page errors. It alerts by email. System health shows it all. |
| Retention and rights | Retention archiving and purging. A client's records can be exported, and records of disclosure (releases) are kept. |
| Breach response | Privacy incidents with their deadlines. |

## Risks the organisation must address

Each gives the likelihood, the impact and the action.

1. **No business associate agreement (BAA) with a provider that holds or sees health information.** Likelihood: high until checked. Impact: high (a compliance violation).
   - **Action:** sign BAAs with the web host, Microsoft (365 or Graph email), and the AI provider if AI is enabled.
   - **Twilio:** texts carry no health information by design, so Twilio needs a BAA only if staff type health details into texted messages. Train staff not to, or use Twilio's HIPAA-eligible plan.
2. **The server is down with no warning.** Likelihood: medium. Impact: medium.
   - **Action:** add an outside uptime monitor on `/health.php`.
3. **A lost or stolen staff device.** Likelihood: medium. Impact: medium.
   - **Action:** require a screen lock on phones used for work, and switch the account off at once, then sign it out of the mobile app (RUNBOOK.md).
4. **Phishing or a reused password.** Likelihood: medium. Impact: high.
   - **Action:** keep two-step sign-in on for everyone, train staff yearly (Staff training can hold the training and record who passed), and prefer Microsoft sign-in with your organisation's own protections.
5. **The backup password or signing key exists only on the server or one computer.** Likelihood: low. Impact: high.
   - **Action:** keep both in a password manager that more than one trusted person can reach.
6. **An outdated installation.** Likelihood: medium. Impact: medium-high.
   - **Action:** set Settings, Updates to github and install releases promptly. Updates are signed and put old files back on failure.
7. **Shared hosting:** other customers on the same server. Likelihood: low. Impact: high.
   - **Action:** choose hosting that signs a BAA. The private folder stays outside the web folder, as the installer sets up.
8. **Staff texting health details.** Likelihood: low-medium. Impact: medium.
   - **Action:** staff guidance; the texting page says texts must carry no case details.
9. **No independent security test.** Likelihood: n/a. Impact: unknown.
   - **Action:** consider an outside penetration test before taking on large agency contracts.

## Review

| Date | Reviewer | Changes |
|---|---|---|
| | | First assessment |
