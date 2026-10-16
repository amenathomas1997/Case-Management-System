<?php
// Included only for signed-in staff by help.php. No client data is included.
// help.php also reads staff_guide_topics() (with $staffGuideTopicsOnly set) to list
// the guide's main topics in its own table of contents.

if (!function_exists('staff_guide_topics')) {
    /** The guide's outline: h3 id => [title, [h4 id => title]]. Headings and both tables of contents are built from it. */
    function staff_guide_topics(): array {
        return [
            'guide-quickstart' => ['Quick start: your first 10 minutes', []],
            'guide-start' => ['How do I get around?', [
                'guide-menu' => 'What is in each menu group?',
                'guide-breadcrumbs' => 'How do I use the breadcrumbs?',
                'guide-collapsed' => 'How do I open a form that is collapsed?',
                'guide-shortcuts' => 'Which keyboard and JAWS shortcuts work here?',
                'guide-dropdowns' => 'How do I use dropdowns with JAWS?',
                'guide-search' => 'How do I search?',
            ]],
            'guide-day' => ['How do I plan my day?', []],
            'guide-intake' => ['How do I handle a referral?', [
                'guide-referral-close' => 'How do I close a referral that will not become a client?',
                'guide-referral-attach' => 'What if the person is already a client?',
                'guide-case-close' => 'How do I close a case?',
                'guide-waitlist' => 'How do I use the referral waitlist?',
                'guide-counselor-access' => 'How do I give a VR counselor access?',
            ]],
            'guide-notes' => ['How do I write a case note?', [
                'guide-note-lock' => 'How do I correct a note after it locks?',
            ]],
            'guide-training' => ['How do I set up assessments, tracks, goals and homework?', [
                'guide-homework-draft' => 'How do I draft homework from a session note?',
            ]],
            'guide-sessions' => ['How do I schedule and log a session?', [
                'guide-log-session' => 'How do I record a session (hours and note)?',
                'guide-complete-session' => 'How do I complete a session?',
                'guide-groups' => 'How do I run a group session?',
            ]],
            'guide-equipment' => ['How do I loan and track equipment?', [
                'guide-loan' => 'How do I loan an item out?',
                'guide-return' => 'How do I record a return or a service?',
                'guide-reserve' => 'How do I reserve an item?',
                'guide-followup' => 'How do I record a device follow-up?',
                'guide-purchase' => 'How do I buy a device?',
                'guide-at-services' => 'How do I record other AT services?',
            ]],
            'guide-contact' => ['How do I message clients and use the portal?', [
                'guide-messages' => 'How do I send a message?',
                'guide-portal-staff' => 'How do I give a client portal access?',
                'guide-portal-requests' => 'How do I handle a portal request?',
                'guide-documents' => 'How do I upload a document?',
                'guide-consent' => 'How do I request guardian consent?',
                'guide-release' => 'How do I get a release of information?',
            ]],
            'guide-billing' => ['How do I bill and record payments?', [
                'guide-bill-month' => 'How do I bill all unbilled work?',
                'guide-void' => 'How do I fix a mistake on a draft invoice?',
                'guide-submit' => 'How do I mark an invoice submitted?',
                'guide-payment' => 'How do I record a payment or a write-off?',
                'guide-denied' => 'What if an invoice is denied or sent in error?',
                'guide-private-pay' => 'How do I bill a client who pays, or train for free?',
                'guide-contracts' => 'How do I track grants and contracts?',
            ]],
            'guide-reports' => ['How do I run reports?', [
                'guide-report-outcomes' => 'Outcome report',
                'guide-report-funder' => 'Funder outcome report',
                'guide-report-agency' => 'Agency billing summary',
                'guide-report-rsa' => 'RSA-911 case-service export',
                'guide-report-utilization' => 'Staff billable utilization',
                'guide-report-capacity' => 'Capacity planning',
                'guide-report-scheduled' => 'Scheduled report emails',
                'guide-report-risk' => 'Outcome risk flags',
                'guide-client-reports' => 'How do I send a report about one client?',
            ]],
            'guide-ai' => ['How do I use AI and the voice assistant?', [
                'guide-ai-review' => 'How do I check AI work?',
                'guide-voice' => 'How do I use the voice assistant?',
            ]],
            'guide-iphone' => ['How do I use the iPhone app?', [
                'iphone-signin' => 'How do I sign in?',
                'iphone-rotor' => 'How do I reach the buttons in a list?',
                'iphone-steppers' => 'How do I change a number?',
                'iphone-log-hours' => 'How do I log hours on the iPhone?',
                'iphone-account' => 'How do I manage my account on the iPhone?',
                'iphone-offline' => 'What happens when I am offline?',
                'iphone-limits' => 'What do I still do on the website?',
            ]],
            'guide-account' => ['How do I change my account and display settings?', []],
            'guide-admin' => ['Administration', [
                'guide-audit' => 'How do I read the audit log?',
                'guide-templates' => 'How do I manage templates?',
                'guide-trash' => 'How do I restore something from the trash?',
                'guide-users' => 'How do I manage users?',
                'guide-monitoring' => 'How do I monitor the system?',
            ]],
            'guide-privacy' => ['Privacy and confidentiality', []],
            'guide-problems' => ['What if something goes wrong?', [
                'guide-unsaved' => 'What does “Unsaved changes” mean?',
                'guide-session-expired' => 'What if I am signed out?',
                'guide-get-help' => 'How do I get help?',
            ]],
        ];
    }
}
if (!empty($staffGuideTopicsOnly) || !isset($viewer) || !$viewer) return;

$sgTopics = staff_guide_topics();
$sgTitles = [];
foreach ($sgTopics as $sgId => [$sgTitle, $sgSubs]) $sgTitles += [$sgId => $sgTitle] + $sgSubs;
/** A heading from the outline, so its text always matches the table of contents. */
$sgH = fn(int $level, string $id): string => '<h' . $level . ' id="' . h($id) . '">' . h($sgTitles[$id]) . '</h' . $level . '>';
$sgTimeout = max(1, (int)cfg('session_timeout_minutes'));
?>
<section aria-labelledby="staff-guide">
<h2 id="staff-guide">CMS user guide for staff</h2>
<p>This guide shows how to do common tasks. It uses the same names as the website menu. Each topic starts with a question, then short steps.</p>
<p>You may not see every link. Your permission group and caseload decide what you can open. If a link is missing, ask your administrator to check your access.</p>
<nav aria-label="Staff guide topics" id="staff-guide-topics"><ul>
<?php foreach ($sgTopics as $sgId => [$sgTitle, $sgSubs]): ?>
  <li><a href="#<?= h($sgId) ?>"><?= h($sgTitle) ?></a><?php if ($sgSubs): ?>
    <ul>
    <?php foreach ($sgSubs as $sgSubId => $sgSubTitle): ?>
      <li><a href="#<?= h($sgSubId) ?>"><?= h($sgSubTitle) ?></a></li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?></li>
<?php endforeach; ?>
</ul></nav>

<?= $sgH(3, 'guide-quickstart') ?>
<ol>
  <li>Sign in. You start on Dashboard.</li>
  <li>If you need larger text or other colors, find the heading Low-vision controls near the top. Choose the button Show display options under it.</li>
  <li>Choose Go to main menu near the top of the page. The menu has six groups. Press H to move from group to group.</li>
  <li>Open Daily work &amp; review. It lists what needs your attention, grouped by when it is due.</li>
  <li>Open Client records and choose a client. Press H to move through the sections of their record.</li>
  <li>Open one of the client's training tracks. Use the On this page links to jump to Record a session.</li>
  <li>Come back to this guide when you need it. Each topic below starts with a “How do I” question.</li>
</ol>

<?= $sgH(3, 'guide-start') ?>
<p>Every staff page has the same parts, in the same order:</p>
<ol>
  <li>The Skip to main content link.</li>
  <li>Page navigation, with three buttons: Go back, Dashboard and Go to main menu.</li>
  <li>The Low-vision controls heading, with the Show display options button.</li>
  <li>The site header, with the main menu.</li>
  <li>Main content. On most pages it starts with the breadcrumbs.</li>
  <li>The footer, called Site information. It has the same three buttons, the referral form, this help page, the support email and the version number.</li>
</ol>
<p>Go back returns to the page you came from in this site. If there is none, it goes to Dashboard. Going back does not undo a save.</p>
<p>After the site is updated, the Dashboard starts with a short “New in” notice and a Got it button that hides it. The full list of changes, newest first, is under <a href="#whats-new">What's new in each release</a> on this page.</p>
<p>A link with a client's name opens that person's record. A track title opens one training plan inside the record. Buttons such as Edit, Review or Open act on the item in the same row. After you follow a link, read the page heading to check where you are.</p>
<p>Filters and Apply buttons only change what the page shows. They do not change records. Buttons such as Save, Assign, Send and Log do change records. Each form on a page is saved on its own. Read the message that appears after you use one.</p>

<?= $sgH(4, 'guide-menu') ?>
<p>The main menu has six groups. Each group has a heading, so H moves from group to group. Each link has a short hint. A screen reader reads the hint after the link name when you pause. On screen, the hint appears when you hover over the link or Tab to it. Press Escape to hide a hint.</p>
<ul>
  <li>Today
    <ul>
      <li>Dashboard: your starting page. Its Today section lists what is due now, then caseload totals, expiring authorizations and recent activity.</li>
      <li>Task list: to-do items with an owner and a due date.</li>
      <li>Daily work &amp; review: what needs attention today, plus documentation review, AI briefings, homework support and the referral waitlist.</li>
    </ul></li>
  <li>Clients
    <ul>
      <li>Client records: find a person and open their notes, assessments, training tracks and documents. The Caseload filter starts on My clients when your account sees only your own caseload, and remembers your last choice.</li>
      <li>Incoming referrals: new requests for services. Review, assign or turn them into client records.</li>
      <li>Intake progress: where each new client is in intake, so stalled work is easy to spot.</li>
      <li>Client portal requests: appointment and help requests that clients sent from their portal.</li>
      <li>Client &amp; counselor messages: conversations with clients and their VR counselors.</li>
      <li>Accommodation assessments: worksite and job accommodation assessments and recommendations.</li>
    </ul></li>
  <li>Services
    <ul>
      <li>Session schedule: upcoming sessions. Completing a session logs its hours.</li>
      <li>Group programs: group cohorts, membership and attendance.</li>
      <li>Assistive technology services: device comparisons, setup records, outcomes, repairs and subscriptions.</li>
      <li>Goal &amp; assessment templates: reusable wording for goals and assessments.</li>
    </ul></li>
  <li>Equipment
    <ul>
      <li>Equipment &amp; loans: the device inventory. Who has what, loans, returns and service.</li>
      <li>Device purchases: device requests, from justification to order, delivery and training.</li>
      <li>Device follow-ups: check-ins after a loan. Is the device still used? Is more help needed?</li>
    </ul></li>
  <li>Billing, compliance &amp; reports
    <ul>
      <li>Invoices: bill unbilled work, track submitted invoices and record payments.</li>
      <li>Grants &amp; contracts: funding agreements, budgets, deliverables and spending.</li>
      <li>Authorizations &amp; compliance: authorizations at risk, reports due, consents to renew, device follow-ups due and overdue equipment loans.</li>
      <li>Incident reports: record an incident and follow it to closure.</li>
      <li>Business analytics: charts and every report, such as outcomes, funders, billing, RSA-911 and utilization.</li>
    </ul></li>
  <li>Help &amp; account
    <ul>
      <li>Staff knowledge base: your agency's how-to articles. You can search them or ask questions.</li>
      <li>AI tools: AI drafting helpers. Always check their work before you save it.</li>
      <li>User guide: this page.</li>
      <li>My Account: your password, sign-in security, display settings and calendar feed.</li>
      <li>Administration (administrators only): team, permissions, sign-in, forms, email, data retention and AI settings.</li>
    </ul></li>
</ul>
<p>After the six groups come three more items: Notifications (with the number unread), the Site search box and the Sign out button. Save your work before you sign out.</p>

<?= $sgH(4, 'guide-breadcrumbs') ?>
<p>The breadcrumbs are a short list at the start of Main content. Their landmark is called Breadcrumb. They show where the page sits, starting from Dashboard.</p>
<p>For example, a training track shows: Dashboard, Client records, the client's name, then the track. The last item is the page you are on. It is not a link.</p>
<ol>
  <li>Press R or Semicolon in JAWS to move to the Breadcrumb landmark. You can also press L to move to its list.</li>
  <li>Choose a parent page to go up one or more levels.</li>
</ol>
<p>Breadcrumbs follow how records are organized. Go back follows the pages you visited. If you have unsaved changes, you still get a warning before you leave.</p>

<?= $sgH(4, 'guide-collapsed') ?>
<p>Some forms start closed, so the lists on the page come first. Examples are Add a task, Schedule a session, Add a note, Add a SMART goal, Loan an item out and Add equipment. Billing and statements on a track and More actions on a device purchase also start closed.</p>
<ol>
  <li>Press H to find the form's heading. Most collapsed forms have a heading with the same name.</li>
  <li>Move to the next item. It is a button with the form's name. JAWS says “collapsed”.</li>
  <li>Press Enter or Space to open it. JAWS now says “expanded”.</li>
  <li>Fill in the form and use its own button, such as Add task.</li>
</ol>
<p>A form opens by itself when it has an error to fix. It also opens when you follow a link that points to it.</p>

<?= $sgH(4, 'guide-shortcuts') ?>
<p>Quick keys take you straight to a page or a form from anywhere on the site, in Forms Mode or not. Hold the browser's access-key modifier and press the key: in Chrome and Edge on Windows, Alt; in Firefox, Alt+Shift; on a Mac, Control+Option. JAWS reads the shortcut with each link or button that has one.</p>
<ul>
  <li>Today: 1 Dashboard · 2 Task list · 3 Daily work &amp; review (the action center)</li>
  <li>Clients: 4 Client records · R Incoming referrals · I Intake progress · Q Client portal requests · 6 Client &amp; counselor messages · C Accommodation assessments</li>
  <li>Services: 5 Session schedule · G Group programs · H Assistive technology services · O Goal &amp; assessment templates</li>
  <li>Equipment: 7 Equipment &amp; loans · B Device purchases · V Device follow-ups</li>
  <li>Billing, compliance &amp; reports: 8 Invoices · K Grants &amp; contracts · P Authorizations &amp; compliance · J Incident reports · Y Business analytics</li>
  <li>Help &amp; account: W Staff knowledge base · X AI tools · U User guide · A My Account · Z Administration · 9 Notifications</li>
  <li>Getting around: 0 Go to main menu · M Skip to main content · S Site search</li>
  <li>N opens the page's add form: Add a task, Schedule a session, Add a note, Loan an item out, Report an incident, New contract, New device request, or the Add form in a template library.</li>
  <li>T, on a training track, moves to the Record a session form; N there moves to its session note.</li>
</ul>
<p>These shortcuts work on every page of this site. Letter keys work in the JAWS virtual cursor, not in Forms Mode.</p>
<ul>
  <li>Skip to main content: the first link on every page. Press Enter on it to jump past the menu.</li>
  <li>R or Semicolon: move to the next landmark. Insert+Ctrl+R lists the regions. They include Page navigation, Low-vision controls, Site header, Main (the menu), Site search, Main content, Breadcrumb and Site information. In NVDA, press D.</li>
  <li>H: move to the next heading. In the menu, H moves from one group to the next. Insert+F6 lists all headings.</li>
  <li>L: move to the next list. In the menu, each group's links are one list.</li>
  <li>Tab and Shift+Tab: move forward and back through links and controls.</li>
  <li>Escape: hide a menu hint that is showing. In the voice assistant, Escape closes it.</li>
  <li>Alt+L: in the voice assistant, start or continue listening with the microphone.</li>
  <li>Type date instead: a button next to each date field. Choose it to type or paste the date as YYYY-MM-DD, for example 2026-10-14. Time fields have Type time instead and use 24-hour time, for example 14:30. Choose the picker button to switch back. Switching does not save the form.</li>
  <li>Enter or Space on a collapsed form's button: open or close the form.</li>
</ul>

<?= $sgH(4, 'guide-dropdowns') ?>
<p>You can show dropdown choices as an open list instead of a dropdown:</p>
<ol>
  <li>Under the Low-vision controls heading, choose Show display options.</li>
  <li>Choose Text size and reading options.</li>
  <li>Turn on Show dropdown choices as lists.</li>
</ol>
<p>This setting works on every page. It is remembered in this browser when the browser allows it. Turn it off, or choose Reset display, to bring back normal dropdowns.</p>
<p>To use a normal dropdown (combo box) with JAWS:</p>
<ol>
  <li>Move to the combo box. Press Enter to turn on Forms Mode, if JAWS has not turned it on for you.</li>
  <li>Press Up Arrow or Down Arrow to change the choice. Or press Alt+Down Arrow to open the list, then Alt+Up Arrow to close it.</li>
  <li>Press Numpad Plus or Escape to leave Forms Mode.</li>
  <li>Choose the form's button, such as Save or Apply. Picking a choice does not save it.</li>
</ol>
<p>A choice such as “Choose a client” means nothing is picked yet. If JAWS still says “unselected” after you pick a choice, tell us the page heading, the field label, your browser and your JAWS version.</p>
<p><a href="https://support.freedomscientific.com/SurfsUp/9-Forms.htm">Freedom Scientific: using forms and combo boxes with JAWS</a></p>

<?= $sgH(4, 'guide-search') ?>
<ol>
  <li>Go to the main menu. The Site search box comes after the menu groups. You can also press R to reach the Site search landmark.</li>
  <li>Type at least 2 characters, such as part of a name.</li>
  <li>Press Enter.</li>
  <li>Results are grouped by type, and each type has a heading. Press H to move between them.</li>
  <li>Check the type and name before you open a result.</li>
</ol>
<p>Site search needs access to Client records. Some pages have their own search or filters, such as the equipment inventory and the task list. A filter only hides rows. Clear it to see everything again.</p>

<?= $sgH(3, 'guide-day') ?>
<p>Dashboard is the one page to start the day on. Its first section is called Today. Press H to reach it. Each item is a link with a count: overdue tasks, tasks due today, today's sessions (each session is its own link to its row on the schedule), unread messages, device follow-ups due and new client portal requests. You only see the items your permissions cover. If nothing is due, the section says Nothing due today. Caseload at a glance and the other sections follow.</p>
<ol>
  <li>Open Dashboard. Work through the Today section, then check your tasks and any authorizations about to expire.</li>
  <li>Open Daily work &amp; review. The Daily action center opens first.</li>
  <li>Read the summary line. It gives the number of items in each group.</li>
  <li>Press H to move through the groups: Overdue, Due today, This week, Later and No date.</li>
  <li>Follow an item's link to do the work. Items include tasks, referral follow-ups, session outcomes, documentation flags, expiring authorizations, homework and AT service deadlines.</li>
  <li>Open Session schedule. Check Ended sessions awaiting an outcome first.</li>
  <li>Check Notifications, Client &amp; counselor messages and Client portal requests.</li>
</ol>
<p>Reading an alert does not finish the work. Update the record, then mark the task done.</p>
<p>The other tools in Daily work &amp; review are links near the top of that page:</p>
<ul>
  <li>Documentation review: notes with missing sections or placeholders. See <a href="#guide-notes">How do I write a case note?</a></li>
  <li>AI case briefing: choose a client and choose Generate. You get a short draft summary of their recent notes, tracks, goals and homework. Check it against the records. It changes nothing.</li>
  <li>Homework support: choose a client to see their practice updates and homework. See <a href="#guide-training">How do I set up assessments, tracks, goals and homework?</a></li>
  <li>Referral waitlist: see <a href="#guide-waitlist">How do I use the referral waitlist?</a></li>
  <li>AI quality: give feedback on recent AI requests.</li>
</ul>
<p>To add a task:</p>
<ol>
  <li>Open Task list.</li>
  <li>Open the collapsed Add a task form.</li>
  <li>Enter the task, choose its owner and set a due date.</li>
  <li>Choose Add task.</li>
  <li>When the work is finished, choose Done on that task. Choose Reopen if you need it back.</li>
</ol>

<?= $sgH(3, 'guide-intake') ?>
<p>Referrals come from the public referral form. The sender sees a referral number and the date we will contact them by. That date is 3 business days later. The system also makes a task to contact the person by then.</p>
<ol>
  <li>Open Incoming referrals and choose the referral.</li>
  <li>Read the person's details and the services they asked for.</li>
  <li>Assign the referral to a staff member.</li>
  <li>Contact the person by the date they were given. They may quote their referral number.</li>
  <li>Search Client records to check whether the person already has a record.</li>
  <li>Convert the referral to a new client record, or open the existing record.</li>
  <li>On the Overview tab, check access needs, demographics and consent.</li>
</ol>
<p>An email address from the referral form is marked Unverified. Nothing is sent to it until you confirm it with the client. To confirm it, open Edit profile / status on the client record and tick Confirmed with the client.</p>
<p>Intake progress shows each new client's stage, so you can spot stalled intake work.</p>

<?= $sgH(4, 'guide-referral-close') ?>
<p>Open the referral, then Close without creating a client. Choose the reason (Declined services, Not eligible, Withdrawn, Unable to reach, Duplicate, Referred elsewhere or Other) and, for Other, a short note; then choose Close referral. The referral leaves the open list (Status: Closed without a client lists it), its contact task is done, and the person who sent it is emailed the outcome automatically: the reference number and a general reason, never the client's details or your note. A referral closed by mistake can be reopened from the same page.</p>

<?= $sgH(4, 'guide-referral-attach') ?>
<p>When the referral page shows Possible duplicate with an existing client, check it is the same person, then choose Attach to this client. No second record is made: the referral's intake note goes on their record, the contact task is done and a follow-up task is opened. A closed or archived record is reopened to Pending Assessment, with a history note naming the referral.</p>

<?= $sgH(4, 'guide-case-close') ?>
<p>On the client's edit page, choose a Closed status. Closing the case then asks why (Employed, Goals met, Declined further services, Unable to contact, Moved away, Health or medical, Not eligible, Transferred to another provider, or Other with a note) and, if you know it, the outcome: employed or not, the employer, job title, start date, hours a week and wage, or an independence outcome. It lists anything still open on the record, such as active tracks, unbilled work, loaned items, future sessions and unpaid invoices, each with a link. The reasons and employment at closure appear on the Outcome report. A case closed in the app asks the reason and employment; closed by voice, its reason is recorded as Not recorded and can be added later.</p>

<?= $sgH(4, 'guide-waitlist') ?>
<ol>
  <li>Open Daily work &amp; review, then Referral waitlist.</li>
  <li>Choose a name. Open referrals are listed oldest first. Closed entries come last.</li>
  <li>Fill in skills needed, language, service areas, client availability and delivery mode.</li>
  <li>Choose a status: Waiting, Contacted, Ready or Closed. Add a follow-up date.</li>
  <li>Choose Save waitlist entry.</li>
</ol>
<p>Suggested instructors appear under the form. A suggestion is not an assignment. Saving does not book a session or assign the client. If someone else saved the entry first, you see their version and your own text. Save again only if yours should replace theirs.</p>

<?= $sgH(4, 'guide-counselor-access') ?>
<p>A VR counselor can have a private, read-only view of their own clients. It shows authorizations, hours, goal progress and report history.</p>
<ol>
  <li>Open Incoming referrals. Choose the link Manage counselor portal access.</li>
  <li>Find the counselor in the table.</li>
  <li>Choose Send portal link. If they already have one, the button is Resend / reset link or Send a new link.</li>
</ol>
<p>The counselor needs the link and a code that is emailed to them each time. A link lasts 180 days. Sending a new link turns off the old one. If a counselor leaves or shares the link, choose Turn off link.</p>

<?= $sgH(3, 'guide-notes') ?>
<ol>
  <li>Open Client records and choose the person.</li>
  <li>Open the Client Notes tab.</li>
  <li>Open the collapsed Add a note form.</li>
  <li>Choose the note format and the date. The glossary explains BIRP, SOAP and PAIP.</li>
  <li>Type the note.</li>
  <li>If you like, use Generate structured note, Format with AI or Clean up with AI. Read and fix the result. Your own text is kept under a heading such as “Your text before the AI clean-up”.</li>
  <li>Choose Save note. When you edit a note, the button is Save changes.</li>
  <li>Read the message, and check the note is in the list.</li>
</ol>
<p>For a note about one training track, open the track and choose Record a session in the On this page links. Write the note there, with the hours or without them. After you complete a session, the confirmation has a link, Write the session note. It opens that form for you.</p>
<p>The notes list shows the newest 20 notes. Choose Show all notes to see the rest. Client Notes also lists track notes. The General only filter hides them.</p>

<?= $sgH(4, 'guide-note-lock') ?>
<p>A note can be edited by its author or an administrator for 30 days after it was written. After that it is locked, and its Edit button becomes Add addendum. An addendum is a new note, of type Addendum, linked to the original: the original says Addenda with a link to it, and the addendum says which note it corrects. When a note is edited, the text it replaces is kept: open Earlier versions under the note to read each one, with who wrote it and when it was replaced. An administrator can change the number of days under Administration, Data, Case note lock. In the app, a locked note has Add addendum on the Actions rotor.</p>
<p>Documentation review (in Daily work &amp; review) checks recent notes for missing sections and placeholders. For each flagged note, do one of these:</p>
<ul>
  <li>Choose Open the flagged note, and fix the note.</li>
  <li>Open Review note text. Type why the flag is acceptable, and choose Record review.</li>
</ul>

<?= $sgH(3, 'guide-training') ?>
<ol>
  <li>In a client record, open the Tech Assessments tab. Use a scored assessment or Record a tech assessment. A template can fill in fields. Check them before you save.</li>
  <li>Choose Add training track on the client record, or open a track from the Training Tracks tab.</li>
  <li>Check the track's authorization, dates, hourly rate and allowed hours. You cannot log hours until the track has an hourly rate.</li>
  <li>On the track, open the collapsed Add a SMART goal form. To use a template, search the templates and choose Prefill form. Change the wording to fit this client.</li>
  <li>Choose Add goal.</li>
  <li>As the client makes progress, use the goal's progress controls and choose Record. Open History to see earlier entries.</li>
  <li>Under Homework, fill in Assign homework and choose Assign.</li>
</ol>
<p>A track page starts with On this page links: Service authorization, Log a session, Case notes, SMART goals, Homework and Billable expenses. Billing and statements is collapsed near the top. It holds the billing exports and statements.</p>
<p>Homework support (in Daily work &amp; review) shows a client's practice updates. If AI is turned on, Draft homework adaptations for review suggests changes. Nothing changes until you save the assignment yourself. A practice update is evidence to review. It does not complete a goal.</p>

<?= $sgH(4, 'guide-homework-draft') ?>
<p>If AI is turned on, a case note on a track can be turned into a homework draft: a title, numbered practice steps for the learner, and a suggested due date, based on what the note says was taught and practised.</p>
<ol>
  <li>On the track page, under Case notes, find the note and choose Draft homework from this note. After you log a session with a session note, the same button, Draft homework from this session note, appears under Log a session. In Homework support, choose a client and use Draft homework from the latest session note beside a track.</li>
  <li>The page reloads with the Assign homework form filled in and a message that starts “Draft from the note”. Read the title, instructions and due date, and change anything that is wrong or missing.</li>
  <li>Choose Assign. Nothing is saved until you do.</li>
</ol>

<?= $sgH(3, 'guide-sessions') ?>
<p>Scheduling a session is a plan. Logging hours records the work you did. A case note records what happened. Completed work needs hours and a note, and both forms let you write the note in the same place as the hours: they are saved together.</p>
<ol>
  <li>Open Session schedule.</li>
  <li>Open the collapsed Schedule a session form.</li>
  <li>Choose the client and the training track. Linking a track means completing the session logs its hours.</li>
  <li>Choose the instructor, date, start time and duration. To repeat weekly, enter the number of weeks.</li>
  <li>For a remote session, tick Remote / telepractice session and add the meeting link.</li>
  <li>Choose Schedule &amp; send calendar invite.</li>
  <li>If authorization warnings appear, read them. Tick the approval box only if the booking should go ahead.</li>
</ol>
<p>The page lists Ended sessions awaiting an outcome, Upcoming sessions and Closed sessions. It also links to Capacity planning and to interpreter, CART and transport bookings.</p>

<?= $sgH(4, 'guide-log-session') ?>
<p>Use this for work that was not a scheduled session, or for a session with no track.</p>
<ol>
  <li>Open the training track.</li>
  <li>Choose Record a session in the On this page links. The form is near the top.</li>
  <li>Enter the date and the RSA service delivered.</li>
  <li>If you like, choose the goal this session advanced.</li>
  <li>Enter hours worked. Use quarter hours, such as 1.5. Leave it blank to save only a note.</li>
  <li>Enter mileage, if any, and an activity description. The description is needed with hours.</li>
  <li>Under Session note, choose the note type and format (free text, BIRP, PAIP or SOAP), and type the note. The subject is "Session on" and the date unless you enter one. Generate structured note turns rough notes into the format's sections. Leave the note blank to log the hours only.</li>
  <li>Choose Save session. The confirmation says what was saved.</li>
</ol>
<p>The hours and the note are saved together: if either is refused, nothing is saved and what you typed stays in the form. If the work is outside the authorization (its dates, hours or dollars), you get an error: record the amendment on Edit track / authorization first. Do not log hours here for a session you will complete on the schedule. That would log them twice. Billable expenses use Log an expense, further down the track.</p>

<?= $sgH(4, 'guide-complete-session') ?>
<ol>
  <li>Open Session schedule.</li>
  <li>Press H to find Ended sessions awaiting an outcome.</li>
  <li>In the session's row, choose an outcome: Completed, Cancelled or No Show.</li>
  <li>To change the hours, add miles or write the session note, open Hours, miles and session note in the same row. Hours start as the scheduled length. It is shown only for a session linked to a track, and it is saved only with Completed.</li>
  <li>Choose Save, then confirm. The confirmation says what was logged and that the note was saved.</li>
  <li>If you did not write the note in the row, choose Write the session note in the confirmation, and write it on the track.</li>
</ol>
<p>Completing a session that is linked to a track logs its hours, and saves the note you typed in the same step: if the completion is refused, the note is not saved either, and stays in the row. If hours for that day are already logged, choose “Completed — hours already logged” instead. Choose “Completed — log hours as well” only for a separate visit. If someone else cancels your session or records a No Show, you are told.</p>

<?= $sgH(4, 'guide-groups') ?>
<ol>
  <li>On Session schedule, open the collapsed Schedule a group session form.</li>
  <li>Choose the clients and details, then choose Schedule group session.</li>
  <li>After the session, find it under Group sessions awaiting completion.</li>
  <li>Untick anyone who did not come. They are recorded as No Show, with no hours.</li>
  <li>Choose Record attendance.</li>
</ol>
<p>Group programs holds cohorts and membership. Use Log a group session there for program sessions. Do not log the same attendance in both places.</p>

<?= $sgH(3, 'guide-equipment') ?>
<p id="guide-devices">Equipment &amp; loans is the device inventory. The counts at the top are links: Items tracked, On loan, Overdue returns and Service due. Each opens the inventory filtered to those items.</p>
<ul>
  <li>To find an item, use Search by item name, serial number or borrower, then choose Search.</li>
  <li>To filter, use the links in Filter inventory by status: All, Available, On loan, Overdue returns, Service due, In repair or Retired.</li>
  <li>A line under the filters says how many items are shown.</li>
</ul>

<?= $sgH(4, 'guide-loan') ?>
<ol>
  <li>Open Equipment &amp; loans.</li>
  <li>Open the collapsed Loan an item out form.</li>
  <li>Choose the item and the client, and fill in the loan details.</li>
  <li>Choose Loan out.</li>
</ol>
<p>Some items say “held by a reservation”. If you lend one to someone else, the hold moves to the waitlist. The client's instructor and the person who made the hold are told. To add a new item to the inventory, open Add equipment and choose Add item.</p>

<?= $sgH(4, 'guide-return') ?>
<ol>
  <li>Open the item. Its name in the inventory is a link.</li>
  <li>Choose Mark returned, then confirm.</li>
  <li>To record maintenance, fill in Log a service and choose Log service.</li>
</ol>

<?= $sgH(4, 'guide-reserve') ?>
<ol>
  <li>On Equipment &amp; loans, press H to find Reservations &amp; waitlist.</li>
  <li>Under Reserve or waitlist an item, choose the item and the client.</li>
  <li>Enter the dates it is needed, and any notes.</li>
  <li>Choose Add reservation.</li>
</ol>
<p>An available item is held for the client. An item on loan puts the client on its waitlist. When the item is loaned to that client, the reservation is closed for you. A reservation is not a loan.</p>

<?= $sgH(4, 'guide-followup') ?>
<ol>
  <li>Open Device follow-ups. Due now comes first.</li>
  <li>Each due check is a heading with the client and the device. Press H to move between them.</li>
  <li>Answer Still in use? and the other questions.</li>
  <li>Choose Save follow-up. Or choose Snooze 2 weeks, or skip.</li>
</ol>
<p>Check your answers first. A saved follow-up cannot be changed on that page.</p>

<?= $sgH(4, 'guide-purchase') ?>
<ol>
  <li>Open Device purchases.</li>
  <li>Fill in New device request and choose Create request.</li>
  <li>Open the request. Fill in the justification of need.</li>
  <li>Add quotes with Add quote. Choose Mark recommended on the best one.</li>
  <li>Under Lifecycle, the page shows only the next step. Steps are: Record funding authorization, Record order, Record delivery and Complete training.</li>
  <li>Use that step's save button. The last one is Mark trained &amp; close.</li>
</ol>
<p>Other steps, and Deny or cancel, are under the collapsed More actions.</p>

<?= $sgH(4, 'guide-at-services') ?>
<p>Open Assistive technology services, or choose AT services and learning on a client record. Pick a category, open or create a record, fill it in and choose Save record. Check its status and next-action date.</p>
<ul>
  <li>Device trial comparisons: set shared goals, add trial options and results, then record the preferred option and why.</li>
  <li>Device setup records: versions, accessibility settings, recovery steps and training. Never store passwords or license keys.</li>
  <li>Post-delivery outcomes: open the check linked to the setup. Record real-world use, barriers and next steps.</li>
  <li>Repair and loaner support: the issue, vendor and repair progress. Return any loaner before you close the repair.</li>
  <li>Software licenses and License assignments: seats bought, renewals, and which client has each seat.</li>
  <li>Instructor matching: staff skills and availability. A match does not assign a client or book a session.</li>
  <li>Learning library and Assigned learning: reusable material, linked to a client to track completion.</li>
  <li>Service profitability and Staff labor rates: costs and margins, for administrators and billing staff.</li>
  <li>Device reuse: inspection, cleaning, data wiping and repairs before a donated device is used again.</li>
  <li>Authorization to payment: links funding, the track and the invoice. It does not replace the invoice or payment record.</li>
</ul>

<?= $sgH(3, 'guide-contact') ?>
<p>Messages, portal links and consent links go to real people outside the agency. Check who will receive them before you send.</p>

<?= $sgH(4, 'guide-messages') ?>
<ol>
  <li>Open Client &amp; counselor messages. Or choose Messages with client on a client record.</li>
  <li>Choose the client, and choose the client or their VR counselor.</li>
  <li>Read the conversation. Check who will get your reply.</li>
  <li>Type your reply and send it.</li>
</ol>
<p>A message is not a case note. Write a note as well if the conversation matters to the case.</p>

<?= $sgH(4, 'guide-portal-staff') ?>
<ol>
  <li>Open the client record.</li>
  <li>Choose Send portal link, then confirm.</li>
</ol>
<p>The client gets a private link by email. There is no password. The link lasts 180 days. Only the newest link works. Sending a new link turns off every older one.</p>
<p>The link is not sent to an Unverified email address. Confirm the address first on Edit profile / status. If someone else may have the link, choose Turn off portal link. Text next to the button says until when the link is active, or when it expired.</p>
<p>An archived record's portal does not open. A closed case's portal is read-only.</p>

<?= $sgH(4, 'guide-portal-requests') ?>
<ol>
  <li>Open Client portal requests.</li>
  <li>Read the request.</li>
  <li>Do the work, such as booking the session on Session schedule.</li>
  <li>Update the request's status.</li>
</ol>

<?= $sgH(4, 'guide-documents') ?>
<ol>
  <li>Open the client record, then the Documents tab.</li>
  <li>Under Upload a document, type a description and choose the file.</li>
  <li>Choose Upload.</li>
  <li>Check the file is in the Documents list.</li>
</ol>
<p>If the upload fails, choose the file again and resend the form.</p>

<?= $sgH(4, 'guide-release') ?>
<p>A release lets you share an adult client's information with one named recipient, such as their VR counselor, an employer or a doctor, for one year.</p>
<ol>
  <li>Open the client record, on the Overview tab, and find Releases of information.</li>
  <li>Open the collapsed Request or record a release.</li>
  <li>Choose who signs (the client, or a parent or guardian), the recipient type and the recipient's name. Tick what information it covers, and say why it is shared.</li>
  <li>To have it signed online, enter the signer's email and choose Email release to sign. They type their name on the form to sign it, in English or Spanish. For a release signed on paper, enter the date signed and choose Record paper release; then upload the paper copy under Documents, category Release.</li>
</ol>
<p>The list shows each release as Current, Awaiting signature, Expired or Withdrawn. If the client takes a release back, choose Record withdrawn. Compliance lists releases that expire within 30 days and open clients with no release or guardian consent. The progress report, evaluation report and worksite report screens say so when no current release covers the recipient.</p>

<?= $sgH(4, 'guide-consent') ?>
<ol>
  <li>Open the client record, on the Overview tab.</li>
  <li>Open the collapsed Request guardian consent by email.</li>
  <li>Enter the guardian's name and email.</li>
  <li>Choose Email consent form, then confirm.</li>
</ol>
<p>The guardian signs by typing their full name. The link works for 60 days. A new request turns off any earlier unsigned link. While a request is waiting, the form is called Resend the guardian consent request by email. Authorizations &amp; compliance lists consents that need renewing.</p>

<?= $sgH(3, 'guide-billing') ?>
<p>Before you bill, check each track's authorization, hours, expenses and rate. Fix mistakes on the track first. Recording work and recording payment are separate steps. Your vendor number, remit-to address, tax ID and payment terms print on every invoice; an administrator enters them under Administration, Billing details.</p>
<p>Work outside an authorization is refused: hours, mileage or expenses dated outside its dates, or that would go over its authorized hours or dollars, cannot be saved on the website, in the app or by voice. The message says how far over it would go. Record the amendment on Edit track / authorization first, then log the work. The track page shows an overrun as, for example, 2 hours over.</p>

<?= $sgH(4, 'guide-bill-month') ?>
<ol>
  <li>Open Invoices.</li>
  <li>Choose Generate invoices for all unbilled work. The button shows the total amount.</li>
  <li>Confirm. A draft invoice is made for every track with unbilled work. Those hours are now marked billed.</li>
  <li>Use the Show filter to pick Draft, then choose Apply.</li>
  <li>Review each draft invoice.</li>
</ol>
<p>Export for QuickBooks (CSV) downloads the invoices for your accounting system.</p>

<?= $sgH(4, 'guide-void') ?>
<ol>
  <li>Find the draft invoice in the list.</li>
  <li>Choose Void draft, then confirm.</li>
  <li>Fix the hours or expenses on the track.</li>
  <li>Generate invoices again.</li>
</ol>
<p>Voiding makes that work unbilled again. Only a draft can be voided. A submitted or paid invoice has gone to the funder.</p>

<?= $sgH(4, 'guide-submit') ?>
<ol>
  <li>Send the invoice to the funder.</li>
  <li>On that invoice, check Date submitted (today unless you change it), then choose Mark submitted and confirm.</li>
</ol>
<p>The due date is set from the payment terms and printed on the invoice. Downloading an invoice does not mark it submitted.</p>

<?= $sgH(4, 'guide-payment') ?>
<ol>
  <li>Find the submitted invoice and open Record a payment or write-off.</li>
  <li>Choose A payment received. The amount starts as the balance still owed; change it for a part payment.</li>
  <li>Enter the date the money was received and the check or EFT reference.</li>
  <li>Choose Record.</li>
</ol>
<p>An invoice can take any number of payments. When the balance reaches zero it is marked Paid; until then it stays under Outstanding, with what is past due. When the agency will not pay the rest, record A write-off with the reason. A payment or write-off entered by mistake is removed under Payments and write-offs, with the reason; the balance is worked out again.</p>

<?= $sgH(4, 'guide-denied') ?>
<p>Open Denied or sent in error on the submitted invoice, choose Denied by the funder or Void, give the reason, and choose Close the invoice. It stays on record with its number and reason, and its hours and expenses become unbilled again: correct them on the track, then generate a new invoice. An invoice that already has a payment or write-off recorded cannot be closed: remove those entries first.</p>

<?= $sgH(4, 'guide-private-pay') ?>
<p>On Edit track / authorization, set Who pays for this training. Private pay needs no authorization number: its invoices are billed to the client, at the hourly fee. No charge logs hours at $0 and is never invoiced. A new track for a client whose referral was quoted a fee starts with that choice. The fee and the free-below income are set under Administration, Billing details, and the public referral form quotes them.</p>

<?= $sgH(4, 'guide-contracts') ?>
<p>Open Grants &amp; contracts and choose the agreement. Record its budget and deliverables, and review spending. Authorizations &amp; compliance lists authorizations at risk and reports due. Follow each link to fix the record.</p>

<?= $sgH(3, 'guide-reports') ?>
<p>Open Business analytics. The Reports list comes first, then the charts. You see only the reports your permissions allow. Billable value is not the same as cash received.</p>
<?= $sgH(4, 'guide-report-outcomes') ?>
<p>Clients served, hours by RSA category and program, closures and the rehabilitation rate for a period. Agencies often ask for it at contract renewal. Exports to CSV.</p>
<?= $sgH(4, 'guide-report-funder') ?>
<p>One report for each funding agency. It covers clients served, hours, goals, device retention, assessment gains and closures. View it as a page, a letterhead PDF or CSV.</p>
<?= $sgH(4, 'guide-report-agency') ?>
<p>Billing totals for each funding agency over a date range, to reconcile with state VR. Also linked from Invoices.</p>
<?= $sgH(4, 'guide-report-rsa') ?>
<p>One row per client served in a period, with the case-service fields state VR asks for. Preview it on screen or download a CSV.</p>
<?= $sgH(4, 'guide-report-utilization') ?>
<p>Each staff member's billable hours and value for a period, compared with their expected capacity.</p>
<?= $sgH(4, 'guide-report-capacity') ?>
<p>Booked session hours compared with each instructor's available hours. It also shows authorized hours not yet scheduled. The last section, Authorized hours versus hours left, lists for each instructor and the chosen period the hours authorized on their clients' active tracks, the hours logged, the hours remaining, the sessions still ahead and the hours available (weekly hours times the weeks in the period), with one sentence per instructor saying how many hours are free or by how many hours they are over capacity. Also linked from Session schedule.</p>
<?= $sgH(4, 'guide-report-scheduled') ?>
<p>An administrator can have the outcome report and the funder outcome report emailed automatically once a month, as plain text, under Scheduled reports on the Administration page: choose the reports, the recipients, the day of the month (1 to 28) and whether each report covers the previous month or the previous quarter. Each send is recorded in the audit log as report.scheduled. The figures are the same as on the report pages.</p>
<?= $sgH(4, 'guide-report-risk') ?>
<p>Uses AI to list active clients who may not complete, with a reason and a suggested action. It is only a prompt to review the case.</p>
<?= $sgH(4, 'guide-client-reports') ?>
<ol>
  <li>Open the client's training track.</li>
  <li>To send a progress report to the counselor, choose Email report to VRC. Check the content and recipient before you send.</li>
  <li>For a statement, open the collapsed Billing and statements. Choose Download PDF statement or Download accessible HTML statement. The billing CSV exports are there too.</li>
</ol>
<p>When a track's progress report is due (weekly, every two weeks or monthly), a task appears for the client's instructor: Write and send the progress report. Open the track, choose Email report to VRC, add a short narrative, sign and send. Nothing goes to the counselor until a person sends it, unless an administrator turns on automatic sending under Administration, Scheduled reports.</p>

<?= $sgH(3, 'guide-ai') ?>
<p>AI tools in the menu holds the general tools: Accessible content &amp; remediation, Knowledge-base assistant, Ask your caseload, On-demand translation, Curriculum generator and Outcome risk flags. Other AI buttons sit next to notes, assessments, homework and briefings.</p>
<p>The Curriculum generator drafts a lesson plan: for each lesson, its objectives, numbered Steps to complete the task that name the keystrokes or gestures the learner uses, and How you know it's done. The objectives become the curriculum's skills; the steps stay in the plan text, which you can download.</p>
<ol>
  <li>Choose the tool for the job.</li>
  <li>Check the client and source material are right.</li>
  <li>Type a clear request.</li>
  <li>Generate the draft.</li>
  <li>Check it, edit it, then save, assign or send it yourself.</li>
</ol>
<p>If a tool is missing, ask an administrator whether it is turned on for you.</p>

<?= $sgH(4, 'guide-ai-review') ?>
<p>AI writes drafts. It never saves, sends or changes a record by itself. Before you use a draft, check:</p>
<ul>
  <li>Names, dates and numbers match the records.</li>
  <li>Service details and recommendations are correct.</li>
  <li>Nothing important is missing.</li>
  <li>It does not describe anything that did not happen.</li>
</ul>
<p>To report a good or bad result, use AI quality in Daily work &amp; review.</p>

<?= $sgH(4, 'guide-voice') ?>
<ol>
  <li>Choose the Voice assistant button. It is just before the footer.</li>
  <li>Type a request and choose Send. Or choose Use microphone, or press Alt+L, and speak.</li>
  <li>To keep talking back and forth, choose Start conversation. Choose End conversation to stop.</li>
  <li>Before anything is saved, the assistant asks you to confirm. Choose Accept and save, or Decline. You can also type yes or no.</li>
</ol>
<p>Opening the assistant does not turn on the microphone. Stop speaking stops a spoken reply. The reply stays as text in the conversation history. Escape closes the assistant. You can also turn off Sound cues and Spoken replies, and set Loudness.</p>

<?= $sgH(3, 'guide-iphone') ?>
<p>The iPhone app works with VoiceOver. It covers daily work: clients, tracks, notes, hours, the schedule, Tasks, Notifications, Portal requests, Intake progress and Invoices. Invoices are read-only in the app. The app links to this user guide and to the privacy notice.</p>

<?= $sgH(4, 'iphone-signin') ?>
<ol>
  <li>Enter your email address and password. Choose Continue.</li>
  <li>If you use two-step sign-in, enter the 6-digit code. Choose Verify and sign in.</li>
</ol>
<p>If you forget your password, reset it on the website. The app can lock with Face ID or your passcode.</p>

<?= $sgH(4, 'iphone-rotor') ?>
<p>Each row in a list is one VoiceOver stop. Its buttons are in the Actions rotor.</p>
<ol>
  <li>Swipe to the row.</li>
  <li>Swipe up or down to hear its actions.</li>
  <li>Double-tap to use the one you want.</li>
</ol>
<p>If you hear something else when you swipe up or down, turn the rotor to Actions first.</p>

<?= $sgH(4, 'iphone-steppers') ?>
<p>Fields such as hours are steppers. Swipe up to increase the value. Swipe down to decrease it.</p>

<?= $sgH(4, 'iphone-log-hours') ?>
<p>Choose Log hours from a client, a track or the Schedule. The Schedule is grouped into Today, Tomorrow and Later. As on the website, do not log the same session twice.</p>

<?= $sgH(4, 'iphone-account') ?>
<p>The Account screen lets you change your password, set up two-step sign-in, subscribe to your calendar and sign out everywhere. Passkeys are set up on the website, in My Account.</p>

<?= $sgH(4, 'iphone-offline') ?>
<p>With no connection, notes and hours you enter are kept on the phone. They are sent when the connection comes back. Do not enter them again. Check later that they appear on the website.</p>

<?= $sgH(4, 'iphone-limits') ?>
<p>Some work is only on the website:</p>
<ul>
  <li>Billing all unbilled work, and other invoice changes.</li>
  <li>Editing templates.</li>
  <li>Analytics charts and funder reports.</li>
  <li>User administration.</li>
  <li>Equipment import and QR labels.</li>
  <li>Merging and exporting client records.</li>
</ul>

<?= $sgH(3, 'guide-account') ?>
<p>Open My Account. It holds only your own settings. Administrators also find an Administration link near the top; agency-wide settings are there (see <a href="#guide-admin">Administration</a>). Press H to move between the sections:</p>
<ul>
  <li>Display and accessibility settings: choose Show controls or Hide controls for the low-vision controls, then Save display settings. This follows your account. Your display choices stay in place when the controls are hidden.</li>
  <li>Microsoft sign-in: link your Microsoft account, if your agency uses it.</li>
  <li>Change password.</li>
  <li>Authenticator app: an optional second way to sign in.</li>
  <li>Calendar subscription: a private address for your calendar app. Do not share it.</li>
  <li>Passkeys: sign in with Face ID, Touch ID, Windows Hello or a password manager.</li>
</ul>
<p>To change how pages look, choose Show display options under the Low-vision controls heading. You can pick colors, such as White on black or Yellow on black. Text size and reading options has text size up to 200%, more spacing, narrow width, highlighted links, reduced motion and dropdowns as lists. Reset display brings back the defaults. These choices are saved in this browser.</p>

<?= $sgH(3, 'guide-admin') ?>
<p>These tasks are for administrators. Open Administration from the Help &amp; account menu group (quick key Z) or from the link at the top of My Account. The page starts with a list of links to its six sections. Press H to move between them:</p>
<ul>
  <li>People &amp; permissions: add a team member, change a member's permission group or weekly hours, see the permission groups and create a group. User management is linked here for everything else.</li>
  <li>Sign-in &amp; security: Microsoft Entra ID federation, and Check for updates.</li>
  <li>Forms &amp; email: Referral form settings, Email templates, Counselor portal access, and the Support phone number. The phone number is shown to clients, families and referrers on the public pages as the number to call for help. Leave it blank to show none.</li>
  <li>Data: Trash, Audit log, Merge duplicate clients, Archived clients, the Annual outcome report and Record retention.</li>
  <li>Scheduled reports: email the outcome report and the funder outcome report automatically each month (see <a href="#guide-report-scheduled">Scheduled report emails</a>).</li>
  <li>AI: the AI assistant settings.</li>
</ul>
<p>Check the user or setting carefully before you save. A link in this guide does not give anyone extra access.</p>

<?= $sgH(4, 'guide-audit') ?>
<ol>
  <li>Open Administration. Under Data, choose Audit log.</li>
  <li>The log opens on the last 7 days.</li>
  <li>Filter by team member, action, client record, from date or to date.</li>
  <li>Clear the From date to see every date. Choose Reset filters to the last 7 days to start again.</li>
</ol>
<p>The page has two sections: Activity, and Sign-in attempts (the latest 100).</p>

<?= $sgH(4, 'guide-templates') ?>
<p>Goal &amp; assessment templates has four libraries. Each one is its own page, with links at the top to switch: SMART goal templates, Assessment templates, Service bundles and Scored assessment instruments. Each page has one list and one add form. The goal library is large, so search it to see templates. Templates are starting points. Staff adapt them for each client.</p>
<p>Keep the Staff knowledge base current too. Search for an article before you add a new one.</p>

<?= $sgH(4, 'guide-trash') ?>
<ol>
  <li>Open Administration and find Data.</li>
  <li>Choose Trash (restore deleted items).</li>
  <li>Find the item and restore it.</li>
</ol>

<?= $sgH(4, 'guide-users') ?>
<p>People &amp; permissions, on the Administration page, handles adding a team member and quick changes to groups and hours. For everything else, choose User management there. On that page you can edit details, reset passwords, recover two-step sign-in, move a caseload and deactivate a user.</p>

<?= $sgH(4, 'guide-monitoring') ?>
<p>Open Administration, then Open System health. The page shows, with a summary of anything that needs attention first: every scheduled job and when it last ran, the backups, email, free disk space, the database and versions, failed sign-ins, the AI assistant, recent problems in the cron log, and work that is waiting. Press H to move between the sections; Check again reloads it. The Administration page itself says on one line whether every scheduled job is running.</p>
<p>The same job checks answer at <?= h(rtrim((string)cfg('base_url'), '/')) ?>/health.php, for an external monitor. That address needs no sign-in and holds no client data. It answers HTTP 200 when the database answers and every scheduled job ran within its limit, and HTTP 503 otherwise, saying which check failed. Set up a monitor such as UptimeRobot to poll that exact address every 5 minutes and alert on any response other than 200. The watchdog also emails the backup address, at most every 6 hours, while a job has fallen behind.</p>

<?= $sgH(3, 'guide-privacy') ?>
<p>Open only the records you need for your work. Never share portal, consent or calendar links, and keep client details out of ordinary email. The <a href="privacy.php">privacy notice</a> explains what we keep, who we share it with and the choices clients have.</p>

<?= $sgH(3, 'guide-problems') ?>

<?= $sgH(4, 'guide-unsaved') ?>
<p>If you try to leave a page with unsaved changes, you get a warning with three choices:</p>
<ul>
  <li>Keep editing: go back to the form.</li>
  <li>Save changes: save the form as normal. You may not go on to the link you chose.</li>
  <li>Leave without saving: throw away your changes.</li>
</ul>
<p>Some forms have no Save changes choice. Go back and use the form's own button, such as Assign or Send. The browser's Back button, refresh or closing the tab may show the browser's own warning. Nothing is saved automatically.</p>
<p>If an error appears after you submit, read the list of problems at the top. Each one links to its field. Fix the fields and submit again. A filled-in form is not saved until you see the confirmation.</p>

<?= $sgH(4, 'guide-session-expired') ?>
<p id="session">You are signed out after <?= $sgTimeout ?> minutes without activity. A dialog, Are you still there?, appears first.</p>
<ul>
  <li>Choose Stay signed in to keep going.</li>
  <li>Choose Return to my work to close the dialog.</li>
</ul>
<p>If you were signed out:</p>
<ol>
  <li>Keep the page open. What you typed is still there.</li>
  <li>Copy any important text.</li>
  <li>Choose the link to sign in again in a new tab.</li>
  <li>Come back to this tab and submit the form.</li>
</ol>
<p>Passwords and chosen files are not kept. Choose files again before you submit.</p>

<?= $sgH(4, 'guide-get-help') ?>
<p>If a record is missing, check the filters, the client or track you chose, and your caseload. Ask an administrator to check your access.</p>
<p>If you are still stuck, email the office. The address is in the footer. Tell us:</p>
<ul>
  <li>The page heading.</li>
  <li>The link or button you used.</li>
  <li>What you expected, and the exact error.</li>
  <li>The version number from the footer.</li>
</ul>
<p>Do not put client details or private links in a support email.</p>
<p><a href="#staff-guide-topics">Back to staff guide topics</a></p>
</section>
