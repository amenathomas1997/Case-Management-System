<?php
// What's new for staff: one entry per release, newest first, as short plain-language
// bullets about what changed for a caseworker (not how). help.php lists every entry
// under "What's new"; index.php shows the current APP_VERSION's first bullet once,
// until the signed-in user chooses Got it (setting seen_release_{user id}).
// Keep the key equal to APP_VERSION when a release ships, or the Dashboard says nothing.
// Plain text only: the pages escape each bullet.
return [
    '4.8.0' => [
        'JAWS now reads each main menu link\'s short description as you move through the menu (it is part of the link), with no extra line or stop.',
        'The iPhone app (4.8.0) works without a signal for more things: expenses, goal progress, completing a task and completing homework are kept on the phone and sent when you are back online, each only once, as notes, hours and check-ins already were.',
    ],
    '4.7.1' => [
        'The main menu has a Referral form link under Clients, to make a referral for someone or share the form\'s link. The referral form is also linked from every page\'s footer and the sign-in page, and the tests now make sure it stays there.',
    ],
    '4.7.0' => [
        'Custom reports (Business analytics): build your own report from clients, training tracks and authorizations, hours and mileage, invoices, scheduled sessions or referrals. Choose the columns and filters (dates, status, staff, funding agency), group it to get counts and totals, download it as a spreadsheet, and save it, for yourself or shared, to run again.',
    ],
    '4.6.0' => [
        'Lists and time limits now also sets how many days ahead session and AT service reminders go out, and the windows the documentation review looks at. Pricing rules has a default mileage rate for new tracks (an agency\'s own rate comes first).',
        'The iPhone app (4.6.0) can be built by any organisation: its name, app ID and server are one block of build settings, and a build without a server asks for the organisation\'s web address at sign-in. Your own app is unchanged.',
    ],
    '4.5.0' => [
        'Lists and time limits now also covers client types, case note types, document categories, service types, primary technology, and equipment categories, conditions and maintenance types. You can add your own; the few the system relies on (such as VR and Pre-ETS) always stay.',
        'The iPhone app (4.5.0) offers the same lists as the website, read from your server each time you open it.',
    ],
    '4.4.0' => [
        'Lists and time limits (Administration) lets you change the choices forms offer: vision statuses, why a case or referral is closed, device request categories, incident and booking types, release of information choices, and the assessment and competency level names. Records keep any value you later remove.',
        'It also sets the percent of an authorization that triggers a warning (built-in 80), how many days count as expiring soon, when expiry alerts go out, the closure and device follow-up days, how long loans, counselor links, signing links, consents and releases last.',
    ],
    '4.3.0' => [
        'Everything that names your business is set on screen: the branding and app icon (Settings), who runs the service, your address, the laws you follow and whether the HIPAA section applies (Settings, Privacy notice), and the consent form\'s description of your services and an extra privacy section (Public wording), in English and Spanish.',
        'The system no longer has any one business built in, so other assistive technology businesses can run their own copy. Help has a new Administrator guide section, Making the system your own.',
    ],
    '4.2.0' => [
        'Pricing rules (Administration) puts every pricing rule on one page: the personal hourly rate, free training below a monthly income, a parent\'s income for a youth under an age you set, whether an open VR case is always billed at the VR rate, and who the referral form asks. Any business using the system can set its own.',
        'Each VR agency can have its own hourly and mileage rate (Funding agencies), for example $40 an hour for one state and $80 for another. A new VR track with no rate typed charges its agency\'s rate, and Pricing rules lists every agency\'s rate.',
        'The referral form asks people referring themselves whether they have an open VR case. Someone who does is quoted the VR rate, not free or the personal rate, and a new track for them starts as VR. Setting up free or personal-rate training for them shows a warning.',
    ],
    '4.1.0' => [
        'Referrals from VR agencies in other states. Administration, Funding agencies keeps each agency\'s state, its clients\' usual time zone, the email domains its authorizations come from, the vendor number it gave you, its payment terms and its billing address. Its invoices use them, and its authorization emails are recognised.',
        'Each client has a State and a Time zone on their edit page; the public referral form asks for the state. A client in another time zone gets their reminders, calendar invitations, portal and letters in their own time, and the schedule shows "Client\'s time" beside yours.',
    ],
    '4.0.0' => [
        'The voice assistant now answers common requests without AI: what is on today or this week, tasks due, unread messages and alerts, new referrals, open requests, unbilled work, unpaid invoices, expiring authorizations, a client\'s phone, status, next session, hours left, notes, goals and homework, and opening a client or page. It can also cancel a session or mark a no-show, after you confirm. Administrators can keep voice on with AI off.',
        'On My account, Email me about alerts: urgent ones (a client\'s request, message or call for help) at once and a daily count, a daily count only, or never (the starting choice). The email never names the client. Calendar subscriptions remind you 30 minutes before each session. Phone numbers and addresses are links to call and get directions.',
        'Bulk actions: mark several ended sessions No Show or Cancelled, several tasks done, several draft invoices submitted, or close several portal requests at once. Equipment loans record the item\'s condition going out and coming back, and who accepted it.',
        'iPhone app: My sessions only on the schedule, directions, open the client from a session, mark draft invoices submitted, equipment condition, client records readable offline, and a calendar subscription link.',
    ],
    '3.0.55' => [
        'New staff are emailed an invitation to choose their own password (leave the password empty when adding them). Each person\'s confidentiality agreement and privacy training dates are recorded on their user page and renewed yearly; Compliance and the daily tasks remind you, and flag accounts not used for 60 days. A person\'s user page lists what they still hold if they are leaving, and the transfer now also moves open AT records, equipment reservations, group programs and access bookings. Optionally, staff disabled in Microsoft 365 are switched off each night.',
        'Help has an Administrator guide: the scheduled jobs, renewing the email secret, people joining and leaving, misfiled email, permission groups, homework AI and privacy incidents. Administrators can import clients and open authorizations from a spreadsheet, checked row by row first.',
    ],
    '3.0.54' => [
        'Administrators can change settings on screen instead of in the server\'s config.php (Administration, Settings): the Microsoft Graph email details, the from, help and backup addresses, sign-in timeouts, the time zone and branding. PDFs now use your brand colour.',
        'Database upgrade (Administration) applies the database changes new files need, one step at a time, after you confirm there is a backup from today. The system checks for new versions every day and tells administrators.',
    ],
    '3.0.53' => [
        'Backups now go off-site every day, not once a week: Mondays carry every document, other days the database and the documents changed that week. Each Monday the system checks that the backup can be restored, and System health shows the result, with a Check the backup now button. Administrators find a step-by-step restore guide in Help, and the release has a restore script.',
        'System health can send you a test email, lists the recent emails (who they went to by kind, and whether they went, never addresses), and records when the Microsoft Graph email secret expires: administrators are warned 30, 14 and 7 days before. If email ever falls back from Microsoft Graph to the server\'s own mail, administrators are told.',
    ],
    '3.0.52' => [
        'Offer times on a client\'s appointment or reschedule request (Client portal requests, Offer times): up to three times, each checked against the instructor\'s calendar and the authorization. The client is emailed, picks one in their portal and confirms it: the session is booked (or moved), they get the calendar invitation, and the request closes.',
    ],
    '3.0.51' => [
        'Letters: Write a letter on a client\'s page fills in a welcome, appointment, missed-appointment, equipment loan or return, referral acknowledgement or completion letter from the record. Check it, then save it to their documents (and portal), keep it as a note, or download it to print. Administrators change the wording on the Letters page.',
        'The client portal now shows the client\'s practice updates with your feedback (add it under Daily work, Homework support), their finished homework, and a summary of each finished training plan. When you send a progress or final report you can also put a copy in the client\'s portal.',
    ],
    '3.0.50' => [
        'Spreadsheet downloads (Business analytics) gives the everyday lists as CSV files: clients, referrals, tasks, sessions, authorizations, equipment and loans, device follow-ups, incidents, portal requests and contracts. The client list can be filtered by instructor, client type, funding agency and school, and Download these clients gets every match, past the 500 shown.',
        'Each client has a Timeline (a button on their page): every case event newest first under month headings, and the days spent in each status. On a lower-is-better assessment, a falling score now shows as better. The scheduled report email can include the Business summary.',
    ],
    '3.0.49' => [
        'New reports under Business analytics: a Business summary (referrals, new clients, clients served, sessions, no-shows, hours, closures and the rehabilitation rate, compared with the period before and last year, month by month, with the open caseload by instructor), Attendance and no-shows, and Unused authorizations (hours and dollars left on authorizations that ended). Each downloads as CSV.',
        'Data quality lists likely duplicate clients and records to tidy before a report is due. Adding a client who may already have a record now warns first. Administration sets the month your fiscal year starts; reports offer Fiscal year to date and Last fiscal year. The client page shows attendance.',
    ],
    '3.0.48' => [
        'Incoming authorizations (Clients menu): paste an approved authorization email, or forward it to the CMS mailbox, and the system reads the authorization number, client, service, amount and counselor. Check the client, choose Start the track, and the new track form opens filled in for you to check and save. A forwarded email also adds a task. Nothing is sent to AI.',
    ],
    '3.0.47' => [
        'Progress reports can cover only the period since the last report, and can include barriers and recommendations. Choose Final report when training ends: it adds skill levels and scored assessments (first and latest), devices and the closure. AI can draft the barriers and recommendations for you to edit, and the Outcome report, funder report and worksite recommendations each have an AI draft button too. The counselor\'s link now shows the report exactly as it was emailed.',
        'Closing a case schedules follow-up checks at 30, 60 and 90 days. When one is due, a task appears: call the former client and record, under After closure on the client page, whether they are still employed, still using their technology, and whether they need more service (which opens a re-referral task).',
        'Every closure now sends the outcome survey, with one reminder after a week. A client who prefers phone or post is not emailed: ask by phone and enter the answers on the client page. Survey responses has every answer with a CSV. The Outcome report adds results after training and breakdowns by instructor, age band and vision status (new fields on the client edit page).',
    ],
    '3.0.46' => [
        'Completing a session can record the goal worked on and, where a track offers several, the service delivered. A logged entry not yet invoiced can be corrected afterwards (hours and miles, with a reason kept in the audit log) on the track page or in the iPhone app, which now lists a track\'s hours. An entry of 0 hours with miles records travel only.',
        'The Dashboard shows your week: hours logged, hours still booked, and your weekly target.',
    ],
    '3.0.45' => [
        'Each client now has their VR case or participant number, IPE employment goal, IPE date and latest IPE amendment (edit page, and the iPhone app for the number and goal). They print on progress reports and invoices, and the evaluation report\'s justification names the goal.',
        'On the Counselor portal page, Replace with a new counselor moves every client of a counselor who has left to the new one in one step, with a note on each client, and turns off the old counselor\'s portal link.',
    ],
    '3.0.44' => [
        'Amendment requests are followed to their answer: record a request as sent on the amendment page (what you asked for, and the letter, kept on the track), get a follow-up task, then record the reply. An approved change opens the track ready to enter it, and the two are linked. The track and Compliance say when an amendment is awaiting a reply.',
        'Putting a client On Hold now needs a reason (for example, waiting for the authorization) and can have an expected return date; a check-in task appears about every three weeks and on the return date. Setting a client to Active Training without an authorized track gives a reminder.',
    ],
    '3.0.43' => [
        'A session can be moved to a new time from the Schedule page or the iPhone app (Move or cancel more, or Move session on the Actions rotor), instead of cancelling and booking it again. It warns first when the instructor is already booked then or the time is outside the authorization, and the client\'s and instructor\'s calendar entries update in place.',
        'The rest of a weekly series, or a whole group session, can be cancelled in one step; each client gets a calendar cancellation.',
    ],
    '3.0.42' => [
        'Clients see My devices in their portal: what is loaned to them and when it is due back, and any repair under way. They can answer their 30, 60 and 90-day device check-ins there themselves; Device follow-ups marks these as reported by the client.',
        'Clients are emailed a reminder three days before a loaned item is due, and the day after it is overdue (not those who prefer phone or post).',
    ],
    '3.0.41' => [
        'Counselors are emailed when a client misses a session, and when an authorization reaches 80% of its hours or funds. The email gives the date or the authorization number and a link to their portal, never the client\'s name. Their portal now shows each client\'s attendance for the last 90 days, lets them open a report again and confirm they received it.',
        'Clients are emailed a calendar invitation when a session is booked, and a cancellation that removes it from their calendar when it is cancelled (not those who prefer phone or post). Their portal has Add to calendar on each session and a list of past sessions.',
    ],
    '3.0.40' => [
        'Portal links for clients and counselors now stay open while they are used at least every six months. Two weeks before an unused link stops, its holder is emailed and the instructor is alerted, and a client with an expired link can ask for a new one from the expired-link page.',
        'Clients and counselors get a short email when you send them a message (no message text, at most once a day), and clients are emailed when a portal request is answered. Session reminders are not emailed to clients who prefer phone or post; your reminder tells you to call them. The app can send a portal link.',
    ],
    '3.0.39' => [
        'My Account and each person on Users list the phones signed in to the app. Sign one out on its own, or choose Report lost and wipe: the next time that phone connects, the app removes its drafts, unsent entries and saved lists, and signs out. On an iPhone without a passcode the app no longer keeps anything between uses.',
        'The privacy notice is now in Spanish too (choose Español at the top), and says what changed this week: releases of information, copies of records from the portal, locked case notes and the lost-phone wipe.',
    ],
    '3.0.38' => [
        'A privacy incident log (Administration, Privacy incidents, or from Incident reports): report a misdirected report, a lost device or access by the wrong person; record the risk assessment, the people affected, each notice sent and follow-up, with the 60-day notice deadline and a reminder task two weeks before it.',
        'Clients can ask in their portal for a copy of their records or a correction. Each request is due in 30 days, staff can publish an accessible copy of the record to the client\'s portal, and the request closes with an outcome the client sees.',
    ],
    '3.0.37' => [
        'The audit log now shows who opened each client\'s file (on the website, in the app and through the portals) and every bulk export. A client record can be put on hold, so it is not archived, purged or emptied from the trash, and only an administrator can delete an authorization, IPE or release document.',
        'Guardian name, phone and email can be edited on the client and in the app, with a call link. Compliance lists minors with no guardian consent and clients with no privacy notice recorded, and a task reminds you when a client with a guardian turns 18.',
    ],
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
