<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/ai.php';
// Open to everyone: clients, guardians, referrers, counselors and staff (the iPhone
// app and its sign-in screen link here). Statements that depend on how this site is
// configured (AI provider, redaction, record retention) are read from its settings,
// so the notice stays true when an administrator changes them.
$viewer = optional_login();
// In English and Spanish (3.0.39): the Spanish text is inc/privacy_es.php, with the same facts.
enable_public_translation();
$org = brand('org');
$contact = (string)(cfg('support_email') ?: cfg('mail_from'));
$mail = $contact !== '' ? '<a href="mailto:' . h($contact) . '">' . h($contact) . '</a>' : 'us';
$aiOn = ai_enabled();
$aiProvider = $aiOn ? (ai_providers()[ai_cfg()['provider']]['label'] ?? 'an AI service') : '';
$redact = ai_redaction_on();
$retYears = (int)(setting('retention_years') ?? '0');
// Who runs the service. Another installation sets these in $CONFIG['brand'] (privacy_operator,
// privacy_address, privacy_law, privacy_hosting); this agency's own details are the default
// only while the agency name is still Example Agency.
$ownInstall = $org === 'Example Agency';
$operator = brand('privacy_operator') ?: ($ownInstall ? 'the maintainer' : '');
$address = brand('privacy_address') ?: ($ownInstall ? '100 Main Street, Springfield' : '');
$law = brand('privacy_law') ?: ($ownInstall ? 'Minnesota privacy law and the federal HIPAA Privacy and Security Rules' : '');
$hosting = brand('privacy_hosting') ?: ($ownInstall ? 'Linode' : '');
const PRIVACY_UPDATED = '2026-10-16';
page_header('Privacy notice', $viewer);
echo lang_toggle();
if (page_lang() === 'es') {
    require __DIR__ . '/inc/privacy_es.php';
    page_footer();
    exit;
}
?>
<h1>Privacy notice</h1>
<p>This notice explains what information <?= h($org) ?> keeps about you, why, who it is shared with, and the choices you have. It covers this website (including the referral form, the client portal and the counselor portal) and the staff iPhone app.</p>
<p>Last updated <?= h(fmt_date(PRIVACY_UPDATED)) ?>.</p>
<?php if ($operator !== '' || $address !== ''): ?>
<p><?= h($org) ?><?= $operator !== '' ? ' is run by ' . h($operator) : '' ?><?= $address !== '' ? ', ' . h($address) : '' ?>.<?php if ($law !== ''): ?> We follow <?= h($law) ?>.<?php endif; ?></p>
<?php endif; ?>

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
  <li><a href="#hipaa">Your rights under HIPAA</a></li>
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
  <li>Consent forms a guardian signs and releases of information you sign (typed name, date and the internet address it was signed from), and survey answers you choose to give.</li>
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
  <?php if ($hosting !== ''): ?>
  <li><strong><?= h($hosting) ?></strong>, which provides the server this system runs on. We set up and manage the server ourselves; <?= h($hosting) ?> provides the computer and its connection, not access to our records.</li>
  <?php else: ?>
  <li><strong>The company that hosts our server</strong>, which stores the system for us.</li>
  <?php endif; ?>
  <li>Anyone else, such as an employer or a doctor, only when you sign a release naming them (it lasts one year and you can withdraw it), or when the law requires it.</li>
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
  <li>Changes, and who opened each client's record, are recorded in an audit log.</li>
  <li>Case notes cannot be changed 30 days after they are written. A correction is added beside the note, and earlier versions are kept.</li>
  <li>Uploaded files are kept outside the public part of the website. Off-site backup copies are encrypted.</li>
</ul>
</section>

<section aria-labelledby="keep"><h2 id="keep">How long we keep it</h2>
<ul>
  <li>Service records are kept for as long as our funders, contracts and the law require.<?php if ($retYears > 0): ?> Records of closed clients are archived automatically after <?= $retYears ?> year<?= $retYears === 1 ? '' : 's' ?>.<?php endif; ?></li>
  <li>Items staff delete go to a trash folder and are permanently removed after 30 days, except authorizations, plans and releases, and records kept on hold.</li>
  <li>Daily backups are kept for 30 days.</li>
  <li>Sign-in records and the audit log are kept to protect the system.</li>
</ul>
</section>

<section aria-labelledby="choices"><h2 id="choices">Your choices</h2>
<ul>
  <li><strong>See your information.</strong> Ask for a copy of your record in your client portal, or ask us. We answer within 30 days.</li>
  <li><strong>Correct it.</strong> Update your contact details in your client portal, or ask there or ask us to correct anything else.</li>
  <li><strong>Withdraw consent.</strong> You, or a parent or guardian, can withdraw a consent or a release at any time by contacting us. This does not undo what was shared before.</li>
  <li><strong>Surveys are optional.</strong> You can skip any question.</li>
</ul>
</section>

<section aria-labelledby="hipaa"><h2 id="hipaa">Your rights under HIPAA</h2>
<p>We treat your service and health-related records as protected health information under the federal HIPAA rules. You have the right to:</p>
<ul>
  <li><strong>Get a copy of your records.</strong> Ask us in writing; we will provide a copy, usually within 30 days, in the format you ask for where we can.</li>
  <li><strong>Ask us to correct your records.</strong> If we disagree, we will tell you why in writing and you can add a statement of your own.</li>
  <li><strong>Know who we have shared your records with</strong>, other than for your treatment, payment and running our services.</li>
  <li><strong>Ask us to limit</strong> what we share, and to contact you in a particular way or at a particular place. We will say yes to every reasonable request.</li>
  <li><strong>Get a paper copy of this notice</strong> at any time, even if you agreed to receive it electronically.</li>
  <li><strong>Choose someone to act for you</strong>, such as a legal guardian or someone with medical power of attorney.</li>
</ul>
<p>We never sell your information, and we will ask for your written permission before using it for marketing or for any purpose not described in this notice. You can withdraw that permission at any time. If a breach of your information ever happens, we will tell you.</p>
<p>If you believe your privacy rights have been violated, you can complain to us (see <a href="#contact">Questions and requests</a>) or to the U.S. Department of Health and Human Services, Office for Civil Rights, at <a href="https://www.hhs.gov/ocr/complaints/">hhs.gov/ocr/complaints</a> or 1-800-368-1019. We will not retaliate against you for complaining.</p>
</section>

<section aria-labelledby="iphone"><h2 id="iphone">The staff iPhone app</h2>
<p>The iPhone app is for <?= h($org) ?> staff. It connects only to this website over an encrypted connection.</p>
<ul>
  <li>Your sign-in is kept in the iPhone's Keychain. Unsent notes, hours and drafts are kept in protected app storage, are not included in iPhone backups, and are removed after 14 days or when you sign out. On an iPhone without a passcode the app keeps none of them.</li>
  <li>If a phone is lost, staff can report it lost from the website: the next time it connects, the app removes everything it keeps and signs out.</li>
  <li>The app asks to use Face ID (to lock the app), the camera (to scan documents), the microphone and speech recognition (for the voice assistant), and Photos (only to save a document image you choose).</li>
  <li>The app contains no analytics, advertising or tracking, and does not collect your location.</li>
</ul>
</section>

<section aria-labelledby="contact"><h2 id="contact">Questions and requests</h2>
<p>To ask a question, request a copy or correction of your information, or make a complaint, email <?= $mail ?><?= $operator !== '' ? ', or write to ' . h($operator) . ($address !== '' ? ' at ' . h($address) : '') : '' ?>.<?= $operator !== '' ? ' ' . h($operator) . ' handles privacy requests.' : '' ?> We will answer as soon as we can. If we change this notice, we will update the date at the top.</p>
</section>
<?php page_footer();
