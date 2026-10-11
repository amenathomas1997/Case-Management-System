<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
// Open to everyone: clients, guardians and referrers reach it from the footer of
// the public pages (which may be in Spanish), so the shared sections are
// translated. The copy is used only on this page, so its Spanish lives here.
// Signed-in staff see it in English with their full navigation: the staff
// header is English only, and would otherwise be read inside lang="es".
$viewer = optional_login();
if (!$viewer) enable_public_translation();
register_translations([
    'Help and glossary' => 'Ayuda y glosario',
    'Use this guide to find your way around, fill in forms, and understand common terms.' => 'Use esta guía para orientarse, llenar formularios y entender términos comunes.',
    'Help topics' => 'Temas de ayuda',
    'Your client portal' => 'Su portal de cliente',
    'Signing a consent form' => 'Firmar un formulario de consentimiento',
    'The referral form' => 'El formulario de referencia',
    'The survey' => 'La encuesta',
    'Filling in forms' => 'Cómo llenar formularios',
    'Reading and navigation' => 'Lectura y navegación',
    'For staff: keeping your work and the voice assistant' => 'Para el personal: conservar su trabajo y el asistente de voz',
    'Common terms' => 'Términos comunes',
    'Your portal is a private page reached through the link we emailed you. Keep the link to yourself: anyone who has it can see your page. There is no password. If the link stops working, contact us for a new one.'
        => 'Su portal es una página privada a la que se llega con el enlace que le enviamos por correo electrónico. No comparta el enlace: cualquier persona que lo tenga puede ver su página. No hay contraseña. Si el enlace deja de funcionar, comuníquese con nosotros para recibir uno nuevo.',
    'On the portal you can see your upcoming sessions and your progress, mark practice and homework as done with the button “I\'ve done this”, log practice you did on your own, ask for an appointment or other help, share a document with your instructor, send your instructor a message, and update your contact information.'
        => 'En el portal puede ver sus próximas sesiones y su progreso, marcar la práctica y la tarea como hechas con el botón “Ya lo hice”, registrar la práctica que hizo por su cuenta, pedir una cita u otra ayuda, compartir un documento con su instructor, enviar un mensaje a su instructor y actualizar su información de contacto.',
    'Each form on the portal is sent on its own. After you send one, a message at the top of the page says whether it went through. If the page was open for a long time, it may ask you to send the form again; what you typed is kept.'
        => 'Cada formulario del portal se envía por separado. Después de enviar uno, un mensaje en la parte superior de la página indica si se envió. Si la página estuvo abierta mucho tiempo, es posible que le pida enviar el formulario de nuevo; lo que escribió se conserva.',
    'A consent form lets a parent or guardian agree to services for a student. Read the agreement on the page. To sign, type the guardian\'s full name exactly as it is shown above the signature box, then choose “Sign and submit”. Typing the name is your electronic signature; nothing needs to be drawn or printed.'
        => 'Un formulario de consentimiento permite que un padre, madre o tutor acepte los servicios para un estudiante. Lea el acuerdo en la página. Para firmar, escriba el nombre completo del tutor exactamente como aparece encima del cuadro de firma y luego elija “Firmar y enviar”. Escribir el nombre es su firma electrónica; no hace falta dibujar ni imprimir nada.',
    'After you sign, the page confirms it and a record is placed in the student\'s file. You can withdraw consent at any time by contacting us. A consent link stops working 60 days after it is sent; ask us for a new one if yours has expired.'
        => 'Después de firmar, la página lo confirma y se guarda un registro en el expediente del estudiante. Puede retirar su consentimiento en cualquier momento comunicándose con nosotros. Un enlace de consentimiento deja de funcionar 60 días después de enviarse; pídanos uno nuevo si el suyo venció.',
    'Anyone can use the referral form to ask us to help a person with vision loss: a school, a medical provider, an agency, an employer, a family member, or the person themselves. Required fields are marked with an asterisk (*). If something is missing, a list of the problems appears at the top of the form, with a link to each field.'
        => 'Cualquier persona puede usar el formulario de referencia para pedirnos que ayudemos a alguien con pérdida de visión: una escuela, un proveedor médico, una agencia, un empleador, un familiar o la propia persona. Los campos obligatorios están marcados con un asterisco (*). Si falta algo, aparece una lista de los problemas al principio del formulario, con un enlace a cada campo.',
    'After you send it, the page confirms that it was received, and we will contact you within 3 business days.'
        => 'Después de enviarlo, la página confirma que se recibió y nos comunicaremos con usted dentro de 3 días hábiles.',
    'After training, we may email you a short survey with three questions: how satisfied you are, how well the training met your goals, and any comments. The first two are required; comments are optional. You can answer it once, and your answers go to our team to improve our services.'
        => 'Después de la capacitación, es posible que le enviemos por correo electrónico una breve encuesta de tres preguntas: qué tan satisfecho está, qué tan bien la capacitación cumplió sus metas y cualquier comentario. Las dos primeras son obligatorias; los comentarios son opcionales. Puede responderla una vez, y sus respuestas llegan a nuestro equipo para mejorar nuestros servicios.',
    'Fields marked with an asterisk (*) or with the word (required) are required. Read the label and any instructions beside a field before entering information. Review the entries before choosing Save, Send, or Submit. Changing a list selection does not save it: use the button beside the list.'
        => 'Los campos marcados con un asterisco (*) o con la palabra (obligatorio) son obligatorios. Lea la etiqueta y las instrucciones junto a cada campo antes de escribir. Revise lo que escribió antes de elegir Guardar o Enviar. Cambiar una opción de una lista no la guarda: use el botón que está junto a la lista.',
    'If an error appears, read the message, correct the named field, and try again. A file field lets you choose a file from your device. After signing in again or reloading, you may need to choose the file again.'
        => 'Si aparece un error, lea el mensaje, corrija el campo indicado e inténtelo de nuevo. Un campo de archivo le permite elegir un archivo de su dispositivo. Después de volver a iniciar sesión o de recargar la página, es posible que tenga que elegir el archivo otra vez.',
    'For dates, use the format shown by your browser or its date picker. Search filters narrow the list; clear the filters to see more results. If you are unsure what a form is asking, contact the office using the email link at the bottom of the page before submitting.'
        => 'Para las fechas, use el formato que muestra su navegador o su selector de fechas. Los filtros de búsqueda acotan la lista; quite los filtros para ver más resultados. Si no está seguro de lo que pide un formulario, comuníquese con la oficina mediante el enlace de correo electrónico al final de la página antes de enviarlo.',
    'Use the Low-vision controls at the top of each page to choose colors and contrast. Open “Text size and reading options” for text up to 200%, extra spacing, a narrower reading width, highlighted links, and reduced motion. “Reset display” restores the defaults. Preferences are saved in this browser when storage is available. You can also use your browser’s zoom or your device’s high-contrast settings.'
        => 'Use los Controles para baja visión en la parte superior de cada página para elegir colores y contraste. Abra “Tamaño del texto y opciones de lectura” para agrandar el texto hasta un 200 %, añadir espacio, estrechar el ancho de lectura, resaltar los enlaces y reducir el movimiento. “Restablecer la vista” vuelve a los valores predeterminados. Las preferencias se guardan en este navegador cuando es posible. También puede usar el zoom de su navegador o la configuración de alto contraste de su dispositivo.',
    'Press Tab to move forward through links and controls, and Shift+Tab to move back. The “Skip to main content” link moves past the repeated navigation. Scrollable tables can receive keyboard focus so you can use arrow keys to read columns that are off screen.'
        => 'Presione Tab para avanzar por los enlaces y controles, y Mayús+Tab para retroceder. El enlace “Saltar al contenido principal” pasa por alto la navegación repetida. Las tablas desplazables pueden recibir el foco del teclado para que use las flechas y lea las columnas que no se ven en la pantalla.',
]);
// Signed-in staff keep their full navigation.
page_header('Help and glossary', $viewer);
if (!$viewer) echo lang_toggle();
?>
<h1><?= t('Help and glossary') ?></h1>
<p><?= t('Use this guide to find your way around, fill in forms, and understand common terms.') ?></p>
<nav aria-label="<?= h(__('Help topics')) ?>"><ul>
  <li><a href="#portal"><?= t('Your client portal') ?></a></li>
  <li><a href="#consent"><?= t('Signing a consent form') ?></a></li>
  <li><a href="#referral"><?= t('The referral form') ?></a></li>
  <li><a href="#survey"><?= t('The survey') ?></a></li>
  <li><a href="#forms"><?= t('Filling in forms') ?></a></li>
  <li><a href="#display"><?= t('Reading and navigation') ?></a></li>
  <li><a href="#session"><?= t('For staff: keeping your work and the voice assistant') ?></a></li>
  <li><a href="#terms"><?= t('Common terms') ?></a></li>
</ul></nav>
<section aria-labelledby="portal"><h2 id="portal"><?= t('Your client portal') ?></h2>
<p><?= t('Your portal is a private page reached through the link we emailed you. Keep the link to yourself: anyone who has it can see your page. There is no password. If the link stops working, contact us for a new one.') ?></p>
<p><?= t('On the portal you can see your upcoming sessions and your progress, mark practice and homework as done with the button “I\'ve done this”, log practice you did on your own, ask for an appointment or other help, share a document with your instructor, send your instructor a message, and update your contact information.') ?></p>
<p><?= t('Each form on the portal is sent on its own. After you send one, a message at the top of the page says whether it went through. If the page was open for a long time, it may ask you to send the form again; what you typed is kept.') ?></p>
</section>
<section aria-labelledby="consent"><h2 id="consent"><?= t('Signing a consent form') ?></h2>
<p><?= t('A consent form lets a parent or guardian agree to services for a student. Read the agreement on the page. To sign, type the guardian\'s full name exactly as it is shown above the signature box, then choose “Sign and submit”. Typing the name is your electronic signature; nothing needs to be drawn or printed.') ?></p>
<p><?= t('After you sign, the page confirms it and a record is placed in the student\'s file. You can withdraw consent at any time by contacting us. A consent link stops working 60 days after it is sent; ask us for a new one if yours has expired.') ?></p>
</section>
<section aria-labelledby="referral"><h2 id="referral"><?= t('The referral form') ?></h2>
<p><?= t('Anyone can use the referral form to ask us to help a person with vision loss: a school, a medical provider, an agency, an employer, a family member, or the person themselves. Required fields are marked with an asterisk (*). If something is missing, a list of the problems appears at the top of the form, with a link to each field.') ?></p>
<p><?= t('After you send it, the page confirms that it was received, and we will contact you within 3 business days.') ?></p>
</section>
<section aria-labelledby="survey"><h2 id="survey"><?= t('The survey') ?></h2>
<p><?= t('After training, we may email you a short survey with three questions: how satisfied you are, how well the training met your goals, and any comments. The first two are required; comments are optional. You can answer it once, and your answers go to our team to improve our services.') ?></p>
</section>
<section aria-labelledby="forms"><h2 id="forms"><?= t('Filling in forms') ?></h2>
<p><?= t('Fields marked with an asterisk (*) or with the word (required) are required. Read the label and any instructions beside a field before entering information. Review the entries before choosing Save, Send, or Submit. Changing a list selection does not save it: use the button beside the list.') ?></p>
<p><?= t('If an error appears, read the message, correct the named field, and try again. A file field lets you choose a file from your device. After signing in again or reloading, you may need to choose the file again.') ?></p>
<p><?= t('For dates, use the format shown by your browser or its date picker. Search filters narrow the list; clear the filters to see more results. If you are unsure what a form is asking, contact the office using the email link at the bottom of the page before submitting.') ?></p>
</section>
<section aria-labelledby="display"><h2 id="display"><?= t('Reading and navigation') ?></h2>
<p><?= t('Use the Low-vision controls at the top of each page to choose colors and contrast. Open “Text size and reading options” for text up to 200%, extra spacing, a narrower reading width, highlighted links, and reduced motion. “Reset display” restores the defaults. Preferences are saved in this browser when storage is available. You can also use your browser’s zoom or your device’s high-contrast settings.') ?></p>
<p><?= t('Press Tab to move forward through links and controls, and Shift+Tab to move back. The “Skip to main content” link moves past the repeated navigation. Scrollable tables can receive keyboard focus so you can use arrow keys to read columns that are off screen.') ?></p>
</section>
<?php // Staff-only topics stay in English, marked as such on a Spanish page (3.1.2).
$enAttr = page_lang() !== 'en' ? ' lang="en"' : ''; ?>
<section aria-labelledby="session"><h2 id="session"><?= t('For staff: keeping your work and the voice assistant') ?></h2>
<p<?= $enAttr ?>>Staff sessions end after <?= max(1, (int)cfg('session_timeout_minutes')) ?> minutes without activity. A warning lets you extend the session. If it has ended, keep the form open, sign in in another tab, and return to the form. Copy important unsaved text before leaving or reloading. Passwords and files are not automatically restored.</p>
<p<?= $enAttr ?>>The voice assistant also accepts typed requests. Opening it does not start the microphone. Use Stop speaking to stop a spoken reply; the reply remains as text. Escape closes the assistant and stops the conversation.</p>
</section>
<section aria-labelledby="terms"><h2 id="terms"><?= t('Common terms') ?></h2>
<dl class="props"<?= $enAttr ?>>
  <dt>AAC — augmentative and alternative communication</dt><dd>Devices and methods, such as speech-generating devices or symbol boards, that help a person communicate.</dd>
  <dt>AI — artificial intelligence</dt><dd>Software that drafts or suggests text. In this app its output is always a draft for staff to review; it never saves, sends, or changes a record by itself.</dd>
  <dt>AT — assistive technology</dt><dd>Tools or software that help a person perform an activity.</dd>
  <dt>Authorization</dt><dd>Approval for a service, often with an allowed number of hours or a spending limit.</dd>
  <dt>BIRP — behavior, intervention, response, plan</dt><dd>A structured case-note format with one part for each of those four headings.</dd>
  <dt>CART — Communication Access Realtime Translation</dt><dd>Live captioning typed by a trained captioner during a meeting or session.</dd>
  <dt>Caseload</dt><dd>The clients assigned to a staff member.</dd>
  <dt>CCTV — closed-circuit television (video magnifier)</dt><dd>A desktop or portable camera and screen that enlarges print and objects.</dd>
  <dt>Consent</dt><dd>A person's agreement to the activity described in a form.</dd>
  <dt>CSV — comma-separated values</dt><dd>A plain spreadsheet file that Excel and QuickBooks open.</dd>
  <dt>Current value (book value)</dt><dd>What an item cost, reduced by an equal amount each month over its useful life, counted from its purchase date.</dd>
  <dt>Deliverable</dt><dd>A specific item or result required by a contract.</dd>
  <dt>EFT — electronic funds transfer</dt><dd>A direct bank payment. Its reference number is recorded when an invoice is marked paid.</dd>
  <dt>IPE — individualized plan for employment</dt><dd>A plan describing an employment goal and the services intended to help reach it.</dd>
  <dt>KB — knowledge base</dt><dd>The collection of how-to articles and reference notes staff can search.</dd>
  <dt>MB — megabytes</dt><dd>A measure of file size.</dd>
  <dt>NPS — Net Promoter Score</dt><dd>A survey measure based on how likely people say they are to recommend a service.</dd>
  <dt>OTP — one-time password</dt><dd>A temporary sign-in code. You can paste it into the code field.</dd>
  <dt>PAIP — purpose, assessment, intervention, plan</dt><dd>A structured case-note format with one part for each of those four headings.</dd>
  <dt>Passkey</dt><dd>A sign-in method that uses your device to approve access instead of typing an account password.</dd>
  <dt>PDF — Portable Document Format</dt><dd>A common document file type that looks the same on any device.</dd>
  <dt>PO — purchase order</dt><dd>The order number a funder or purchaser issues for a device.</dd>
  <dt>Pre-ETS — pre-employment transition services</dt><dd>Services that help students with disabilities prepare for work and further education.</dd>
  <dt>ROI — release of information</dt><dd>In consent records, permission to share the information described in the release.</dd>
  <dt>RSA — Rehabilitation Services Administration</dt><dd>The agency name used in the app's service categories and reporting.</dd>
  <dt>Serial number</dt><dd>The manufacturer's unique number for one device.</dd>
  <dt>SMART goal</dt><dd>A goal that is specific, measurable, achievable, relevant, and time-bound.</dd>
  <dt>SOAP — subjective, objective, assessment, plan</dt><dd>A structured case-note format with one part for each of those four headings.</dd>
  <dt>Telepractice</dt><dd>A session provided remotely, such as through a video call.</dd>
  <dt>VR — vocational rehabilitation</dt><dd>Services intended to help people with disabilities prepare for, find, or keep work.</dd>
  <dt>VRC — vocational rehabilitation counselor</dt><dd>A counselor who helps plan and coordinate vocational rehabilitation services.</dd>
  <dt>WPM — words per minute</dt><dd>A typing or reading speed, such as a keyboarding goal of 30 WPM.</dd>
</dl></section>
<?php page_footer();
