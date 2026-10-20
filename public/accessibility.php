<?php
// Accessibility statement (4.23.0): open to everyone. The full conformance report (VPAT format, WCAG 2.2)
// is ACCESSIBILITY-CONFORMANCE-REPORT.md in the software's repository.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$viewer = optional_login();
// In English and Spanish (4.30.0), as the privacy notice is: the Spanish privacy notice links here. A
// signed-in staff member gets it in English, so a client's portal language never switches the staff page.
if (!$viewer) enable_public_translation();
$contact = (string)(cfg('support_email') ?: cfg('mail_from'));
page_header('Accessibility', $viewer ?: null);
if (!$viewer) echo lang_toggle();
if (page_lang() === 'es'):
?>
<h1>Accesibilidad</h1>
<p>Este sistema está hecho para que lo pueda usar cualquier persona, incluidas las personas que usan lectores de pantalla, ampliación, alto contraste o solo el teclado. El programa sigue las Pautas de Accesibilidad para el Contenido Web (<span lang="en">WCAG</span>) 2.2 en el nivel AA, y el contraste del texto cumple el nivel AAA, que es más estricto.</p>
<h2>Cómo se revisa el programa</h2>
<ul>
  <li>Se desarrolla con personas ciegas que lo usan todos los días con lectores de pantalla (<span lang="en">JAWS</span> y <span lang="en">VoiceOver</span>).</li>
  <li>Cada versión se prueba automáticamente, incluidos el contraste del texto y el uso con el teclado y el foco.</li>
  <li>Cada cambio también se revisa en un navegador real: los nombres de cada control, la estructura de la página y el diseño en pantallas pequeñas y con letra grande. Tuvo una revisión de accesibilidad según las <span lang="en">WCAG</span> 2.2.</li>
</ul>
<h2>Lo que sabemos que aún no está completo</h2>
<ul>
  <li>Ningún auditor independiente lo ha probado todavía.</li>
  <li>Los documentos que se descargan, como las facturas en PDF, no se han revisado por completo.</li>
  <li>Las páginas de otros servicios a los que lleva, como el inicio de sesión de <span lang="en">Microsoft</span> y <span lang="en">Teams</span>, son de esos servicios.</li>
</ul>
<p>El informe completo de conformidad de accesibilidad del programa (en el formato <span lang="en">VPAT</span>, según las <span lang="en">WCAG</span> 2.2) está <a href="https://github.com/amenathomas1997/Case-Management-System/blob/main/ACCESSIBILITY-CONFORMANCE-REPORT.md">publicado con el programa</a> (en inglés).</p>
<h2>Avísenos de un problema</h2>
<p>Si algo es difícil de usar, avísenos. <?= support_contact_html() !== '' ? support_contact_html() . '.' : ($contact !== '' ? 'Escriba a <a href="mailto:' . h($contact) . '">' . h($contact) . '</a>.' : '') ?> Diga en qué página estaba y qué pasó. Tratamos los problemas de accesibilidad como fallas que hay que corregir.</p>
<?php page_footer(); exit; endif; ?>
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
