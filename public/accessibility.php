<?php
// Accessibility statement (4.23.0): open to everyone. The full conformance report (VPAT format, WCAG 2.2)
// is ACCESSIBILITY-CONFORMANCE-REPORT.md in the software's repository.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$viewer = optional_login();
$contact = (string)(cfg('support_email') ?: cfg('mail_from'));
page_header('Accessibility', $viewer ?: null);
?>
<h1>Accessibility</h1>
<p>This system is meant to be usable by everyone, including people who use screen readers, magnification, high contrast or only a keyboard. The software is built to the Web Content Accessibility Guidelines (WCAG) 2.2 at level AA, with text contrast held to the stricter AAA level.</p>
<h2>How the software is checked</h2>
<ul>
  <li>It is developed with blind people who use it every day with screen readers (JAWS and VoiceOver).</li>
  <li>Every release is tested automatically, including text contrast and keyboard and focus behaviour.</li>
  <li>Every change is also checked in a real browser: names on every control, page structure, and layout at small screen widths and large text. It has had an accessibility review against WCAG 2.2.</li>
</ul>
<h2>What we know is not yet complete</h2>
<ul>
  <li>No independent auditor has tested it yet.</li>
  <li>Downloaded documents, such as PDF invoices, have not been fully checked.</li>
  <li>Pages of other services it links to, such as Microsoft sign-in and Teams, are those services' own.</li>
</ul>
<p>The software's full accessibility conformance report (in the VPAT format, against WCAG 2.2) is <a href="https://github.com/amenathomas1997/Case-Management-System/blob/main/ACCESSIBILITY-CONFORMANCE-REPORT.md">published with the software</a>.</p>
<h2>Tell us about a problem</h2>
<p>If something is hard to use, please tell us. <?= support_contact_html() !== '' ? support_contact_html() . '.' : ($contact !== '' ? 'Email <a href="mailto:' . h($contact) . '">' . h($contact) . '</a>.' : '') ?> Say which page and what happened. We treat accessibility problems as defects to fix.</p>
<?php page_footer();
