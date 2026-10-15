<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/ai.php';
// Open to everyone: clients, guardians, referrers, counselors and staff (the iPhone
// app and its sign-in screen link here). Statements that depend on how this site is
// configured (AI provider, redaction, record retention) are read from its settings,
// so the notice stays true when an administrator changes them.
$viewer = optional_login();
$org = brand('org');
$contact = (string)(cfg('support_email') ?: cfg('mail_from'));
$mail = $contact !== '' ? '<a href="mailto:' . h($contact) . '">' . h($contact) . '</a>' : 'us';
$aiOn = ai_enabled();
$aiProvider = $aiOn ? (ai_providers()[ai_cfg()['provider']]['label'] ?? 'an AI service') : '';
$redact = ai_redaction_on();
$retYears = (int)(setting('retention_years') ?? '0');
const PRIVACY_UPDATED = '2026-10-15';
page_header('Privacy notice', $viewer);
?>
<h1>Privacy notice</h1>
<p>This notice explains what information <?= h($org) ?> keeps about you, why, who it is shared with, and the choices you have. It covers this website (including the referral form, the client portal and the counselor portal) and the staff iPhone app.</p>
<p lang="es">Si necesita esta información en español, escríbanos a <?= $mail ?>.</p>
<p>Last updated <?= h(fmt_date(PRIVACY_UPDATED)) ?>.</p>

<nav aria-labelledby="privacy-toc-h"><h2 id="privacy-toc-h">On this page</h2>
<ul>
  <li><a href="#collect">What we collect</a></li>
  <li><a href="#use">How we use it</a></li>
  <li><a href="#share">Who we share it with</a></li>
  <li><a href="#ai">AI and voice features</a></li>
  <li><a href="#cookies">Cookies and your browser</a></li>
  <li><a href="#security">How we protect it</a></li>
  <li><a href="#keep">How long we keep it</a></li>
  <li><a href="#choices">Your choices</a></li>
  <li><a href="#iphone">The staff iPhone app</a></li>
  <li><a href="#contact">Questions and requests</a></li>
</ul></nav>

<section aria-labelledby="collect"><h2 id="collect">What we collect</h2>
<h3>About clients and students</h3>
<ul>
  <li>Contact details: name, date of birth, phone, email and address.</li>
  <li>Information that helps us serve you: your disability and access needs, the technology you use, your preferred formats and language.</li>
  <li>School details and a parent or guardian's contact details, for students.</li>
  <li>Your vocational rehabilitation (VR) counselor, funding authorizations and billing records.</li>
  <li>Records of our services: case notes, assessments, goals, training hours, equipment loans, incident reports and documents you or we upload, including signatures.</li>
  <li>What you send us through the client portal: messages, requests, practice logs, documents and changes to your contact details.</li>
  <li>Consent forms a guardian signs (typed name, date and the internet address it was signed from) and survey answers you choose to give.</li>
  <li>Emails you send to our office address may be filed in your record.</li>
</ul>
<h3>About people who refer someone</h3>
<p>Your name, email, phone and organization, how you know the person, and what they need help with. For self-referrals, a monthly income range, which only decides whether training is free. We record the internet address a referral was sent from, to stop misuse.</p>
<h3>About VR counselors</h3>
<p>Your name, email and agency, the clients linked to you, and messages you send through the counselor portal.</p>
<h3>About staff</h3>
<p>Your name, email, sign-in security settings, working hours, and a record of what you do in the system (the audit log), including the internet address you sign in from.</p>
<p>We do not collect your location.</p>
</section>

<section aria-labelledby="use"><h2 id="use">How we use it</h2>
<ul>
  <li>To provide assistive technology assessment, training and equipment services.</li>
  <li>To schedule sessions, send reminders and keep in touch with you.</li>
  <li>To report progress to your VR counselor or funding agency and to bill for services they authorize.</li>
  <li>To meet our legal, contract and record-keeping duties, and to keep the system secure.</li>
</ul>
<p>We do not sell your information, and we do not use it for advertising.</p>
</section>

<section aria-labelledby="share"><h2 id="share">Who we share it with</h2>
<ul>
  <li><strong>Your VR counselor or funding agency</strong>, when they referred you or pay for your services: progress reports, evaluation reports, authorization requests and invoices.</li>
  <li><strong>Microsoft 365</strong>, which sends and receives our email (including sign-in codes and reminders), holds our encrypted off-site backup copies, and lets staff sign in with a Microsoft account.</li>
  <?php if ($aiOn): ?>
  <li><strong><?= h($aiProvider) ?></strong>, the AI service we use for drafting help. See <a href="#ai">AI and voice features</a>.</li>
  <?php endif; ?>
  <li><strong>The company that hosts our server</strong>, which stores the system for us.</li>
  <li>Anyone else only when you agree, or when the law requires it.</li>
</ul>
</section>

<section aria-labelledby="ai"><h2 id="ai">AI and voice features</h2>
<?php if ($aiOn): ?>
<p>Staff can use AI to help draft notes, reports, translations and plans. A staff member always reviews AI suggestions before they are saved. Requests go to <?= h($aiProvider) ?>.</p>
<?php if ($redact): ?>
<p>Before a request is sent, the system removes names, email addresses, phone numbers, street addresses and dates of birth. The description of needs and services is still sent, because the draft depends on it. Two kinds of request are not fully de-identified: translations, and spoken requests to the voice assistant.</p>
<?php else: ?>
<p>Requests may include identifying details such as names, because removal of identifiers is currently turned off.</p>
<?php endif; ?>
<p>We record which AI feature was used and how long the request was, not its contents.</p>
<?php else: ?>
<p>AI drafting features are currently turned off. If we turn them on, this notice will be updated first.</p>
<?php endif; ?>
<p>On the website, the voice assistant uses your web browser's speech recognition. Some browsers, such as Google Chrome, send the audio to the browser maker to turn it into text. On the iPhone app, speech is turned into text on the phone when the phone supports it, otherwise by Apple. We do not store voice recordings.</p>
</section>

<section aria-labelledby="cookies"><h2 id="cookies">Cookies and your browser</h2>
<p>We use one cookie, which keeps you signed in and is deleted when you close your browser. Display choices you make, such as text size and colors, are saved in your own browser. We do not use analytics, tracking or advertising cookies, and the site loads nothing from other companies.</p>
</section>

<section aria-labelledby="security"><h2 id="security">How we protect it</h2>
<ul>
  <li>Every connection to this site is encrypted (HTTPS).</li>
  <li>Staff sign in with two-step verification or a passkey, and are signed out after a period of inactivity.</li>
  <li>Each staff member sees only the areas, and where set up only the clients, their role needs.</li>
  <li>Changes are recorded in an audit log.</li>
  <li>Uploaded files are kept outside the public part of the website. Off-site backup copies are encrypted.</li>
</ul>
</section>

<section aria-labelledby="keep"><h2 id="keep">How long we keep it</h2>
<ul>
  <li>Service records are kept for as long as our funders, contracts and the law require.<?php if ($retYears > 0): ?> Records of closed clients are archived automatically after <?= $retYears ?> year<?= $retYears === 1 ? '' : 's' ?>.<?php endif; ?></li>
  <li>Items staff delete go to a trash folder and are permanently removed after 30 days.</li>
  <li>Daily backups are kept for 30 days.</li>
  <li>Sign-in records and the audit log are kept to protect the system.</li>
</ul>
</section>

<section aria-labelledby="choices"><h2 id="choices">Your choices</h2>
<ul>
  <li><strong>See your information.</strong> Ask us for a copy of your record.</li>
  <li><strong>Correct it.</strong> Update your contact details in your client portal, or ask us to correct anything else.</li>
  <li><strong>Withdraw consent.</strong> A parent or guardian can withdraw a consent at any time by contacting us. This does not undo what was done before.</li>
  <li><strong>Surveys are optional.</strong> You can skip any question.</li>
</ul>
</section>

<section aria-labelledby="iphone"><h2 id="iphone">The staff iPhone app</h2>
<p>The iPhone app is for <?= h($org) ?> staff. It connects only to this website over an encrypted connection.</p>
<ul>
  <li>Your sign-in is kept in the iPhone's Keychain. Unsent notes, hours and drafts are kept in protected app storage, are not included in iPhone backups, and are removed after 14 days or when you sign out.</li>
  <li>The app asks to use Face ID (to lock the app), the camera (to scan documents), the microphone and speech recognition (for the voice assistant), and Photos (only to save a document image you choose).</li>
  <li>The app contains no analytics, advertising or tracking, and does not collect your location.</li>
</ul>
</section>

<section aria-labelledby="contact"><h2 id="contact">Questions and requests</h2>
<p>To ask a question, request a copy or correction of your information, or make a complaint, email <?= $mail ?>. We will answer as soon as we can. If we change this notice, we will update the date at the top.</p>
</section>
<?php page_footer();
