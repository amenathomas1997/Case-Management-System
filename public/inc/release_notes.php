<?php
// What's new for staff: one entry per release, newest first, as short plain-language
// bullets about what changed for a caseworker (not how). help.php lists every entry
// under "What's new"; index.php shows the current APP_VERSION's first bullet once,
// until the signed-in user chooses Got it (setting seen_release_{user id}).
// Keep the key equal to APP_VERSION when a release ships, or the Dashboard says nothing.
// Plain text only: the pages escape each bullet.
return [
    '3.0.36' => [
        'Case notes lock 30 days after they are written. A later correction is added as an addendum beside the original, and every earlier version of an edited note is kept and shown under the note.',
        'Adult clients can sign their own release of information, by email or on paper, naming who receives what and why. It is valid for one year. The client page, the Compliance page and the report screens show when no current release covers a recipient. Email subjects no longer carry client names, and Administration has a Vendor agreements page.',
    ],
    '3.0.35' => [
        'A referral that will not become a client is closed with a reason, and the person who sent it is emailed the outcome. A referral for someone already on file is attached to their record (a closed record is reopened), so no second record is made.',
        'Closing a case asks why it closed and, if you know it, the employment or independence outcome, and lists anything still open on the record. A due progress report now opens a task for you to add a short narrative and send it.',
    ],
    '3.0.34' => [
        'Invoices take part payments with the date the money came in, write-offs with a reason, and can be closed as Denied or Void: their hours go back to unbilled so you can fix them and invoice again. Your vendor number, remit-to address and due date print on every invoice.',
        'Hours, mileage and expenses outside an authorization (its dates, its hours or its dollars) are refused until the authorization is amended on the track, with a message saying how far over it would go.',
        'A track can be VR or agency, private pay (invoiced to the client) or no charge (hours logged at $0, never invoiced).',
    ],
    '3.0.33' => [
        'Administrators: System health (open it from Administration) shows on one page whether every scheduled job, the backups, email and storage are working, with anything that needs attention listed first.',
    ],
    '3.0.32' => [
        'On the iPhone, completing a session asks for the hours (the scheduled length unless you change it), miles and the session note before the client signs. Group sessions on the website take one Hours field for everyone who attended.',
        'Forms that create something (incidents, device requests, homework, message replies, equipment, new clients and others) save once even if Enter is pressed twice. A guardian consent request is not sent if another one was sent since you opened the page.',
    ],
    '3.0.31' => [
        'One form for a session: on a training track, Record a session saves the hours, the session note, or both, and the note can be free text, BIRP, PAIP or SOAP, with Generate structured note. On the schedule, Mark completed lets you change the hours, add miles and write the note in the same row.',
        'Microsoft sign-in links your account by its email the first time, with no extra step. A client email from a referral form gets reminders and portal links straight away.',
    ],
    '3.0.30' => [
        'Quieter combo boxes: Area choices on the goal-template pickers are plain names; the track page waits until you settle on an area before searching; matching templates are grouped by area so each option is the goal alone.',
    ],
    '3.0.29' => [
        'The Service bundle choice on a training track is quieter: plain bundle names as you arrow through, with the chosen bundle\'s categories listed after the field.',
        'The privacy notice now has a "Your rights under HIPAA" section.',
    ],
    '3.0.28' => [
        'Clients see “Your progress” in their portal: goals met, hours used, the next session and homework still to do, in plain sentences.',
        'A “New in this version” notice on the Dashboard and a “What\'s new” section in Help list what changed, in caseworker terms.',
        'Capacity planning shows, per instructor and period, hours authorized, logged, remaining and still scheduled against their weekly hours, and says who is over capacity.',
        'Scheduled report emails: the Administration page can send the monthly outcome and funder summaries to chosen addresses on a chosen day; a re-run never sends twice.',
        'System health: health.php reports database, last backup and job heartbeats for an outside monitor, and administrators get an email if the nightly backup or watchdog stops.',
        'iPhone: Siri and the Shortcuts app can open Log hours, Add a note, the voice assistant, and read today\'s session count; a home-screen widget shows today\'s sessions with no client names; a Check in action on today\'s sessions records your arrival, and works offline.',
        'Installer: optional support phone and privacy-notice details; an end-to-end installer test; a script that builds a staging copy with client data scrubbed and email switched off.',
        'Client portal: a "Your progress" summary near the top tells each client, for every active training plan, how many goals are met, hours used of hours authorized, their next session and how much homework is still to do (in Spanish for Spanish-speaking clients).',
        'This What\'s new list: in the user guide, and as a short notice on the Dashboard the first time you sign in after an update. Choose Got it to hide the notice.',
    ],
    '3.0.27' => [
        'The Dashboard starts with Today: overdue and due-today tasks, today\'s sessions, unread messages, device follow-ups due and new portal requests, each a link to where it is done.',
        'Log a session can save the session note in the same step, and Mark completed on the schedule takes an optional note.',
        'Clients can prefer Spanish: their portal link, consent, survey and reminder emails, and the portal itself, open in Spanish. The referral form asks the language.',
        'Client records remembers your caseload filter and starts on My clients when your access is caseload-scoped.',
        'A new Administration page (People & permissions, Sign-in & security, Forms & email, Data, AI) takes the admin sections out of My Account. A support phone number set there appears on client pages.',
        'Invoices: filter by agency, month or text; mark every shown draft submitted at once; optional billing cut-off date.',
        'One remembered report-period picker on the outcomes, funder, agency, RSA-911, utilization, capacity and risk reports.',
        'Draft homework from a session note with AI (nothing is assigned until you review it). Lesson plans list objectives, numbered steps and how the learner knows it is done.',
        'Ask the knowledge base explains when nothing matched and can offer a marked general-knowledge answer.',
        'iPhone: optional local reminders (no client names), and a read-only offline copy of your client list, schedule and follow-ups.',
    ],
    '3.0.26' => [
        'A quick key on every main menu link; the full list is under "Which keyboard and JAWS shortcuts work here?" in the user guide.',
        'Low-vision controls say what changed ("Dark. Display updated."), and a menu hint closes with Escape however it was opened.',
        'Sign-in and security tightened: password-reset requests are limited per account, the billing permission no longer opens every assistive technology module, and completing or reopening a task is recorded in the audit log.',
        'Daily work & review now says, after a session ends, to mark it completed on the schedule (which logs the hours) and then write the session note.',
        'Many small screen-reader fixes on staff and client pages: unique link names for same-named clients, field-level error messages on more forms, required markers on the consent signature and portal practice fields.',
    ],
    '3.0.25' => [
        'Quick keys from any page: 1 Dashboard, 2 Task list, 3 Daily work & review, 4 Client records, 5 Session schedule, 6 Messages, 7 Equipment & loans, 8 Invoices, 9 Notifications, 0 Go to main menu, M Skip to main content, S Site search, U User guide, A My Account. Hold the browser\'s access-key modifier (Alt in Chrome and Edge on Windows).',
        'N opens the page\'s add form (Add a task, Schedule a session, Add a note and others); T moves to Log a session on a training track.',
        'JAWS reads each shortcut with its link or button, never as a separate line.',
    ],
    '3.0.24' => [
        'Menu and shortcut hints are read together with the link, so they no longer cost an extra keystroke in JAWS.',
    ],
    '3.0.23' => [
        'Final code audit of the recent work: no high-severity findings. Breadcrumbs and the Go back, Dashboard and Go to main menu shortcuts appear only on staff pages, not on client pages.',
        '"Low-vision controls" is a heading again, with a Show display options button under it; the "Go to main menu" link no longer sounds like the Main navigation landmark.',
        'Voice assistant: after an unclear answer to a confirmation, only a plain yes or no decides it; anything else cancels the change.',
        'The privacy notice names the operator, address, the governing law and the hosting provider.',
        'Includes everything from 3.0.22, which was not deployed on its own.',
    ],
    '3.0.22' => [
        'The main menu is grouped under headings (Today, Clients, Services, Equipment, Billing, compliance & reports, Help & account), and every link has a one-sentence hint.',
        'Breadcrumbs on the client, client edit, track and track edit pages.',
        'Collapsed forms (Add a task, Schedule a session, Add a note, Add equipment) have a heading, and open themselves when there is an error to fix or a link points to them.',
        'Training track: "On this page" links, Log a session moved up after the totals, and "No authorization entered" instead of 0 hours and 0%.',
        'Lists come before add forms on Tasks, Schedule and client Notes; Daily work & review is grouped by Overdue, Due today, This week, Later and No date.',
        'Administration: templates split into four libraries, equipment search and status filters, lists first on Incidents, Contracts and Device purchases, and audit log filters by user, action and date.',
        'Client pages: the referral confirmation shows the referral number and the date to expect contact; clearer expired-link messages; "Your training plan" headings on the portal.',
        'Rewritten user guide (every topic starts with a question), and the privacy notice page.',
        'iPhone: log hours from a client or a track; each list row is one VoiceOver stop with its buttons in the Actions rotor; account settings on the phone.',
    ],
];
