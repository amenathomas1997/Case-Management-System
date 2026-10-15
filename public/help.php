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
    'Privacy notice (separate page)' => 'Aviso de privacidad (página aparte)',
    'Getting help' => 'Cómo obtener ayuda',
    'Common terms' => 'Términos comunes',
    'Your portal is a private page reached through the link we emailed you. Keep the link to yourself: anyone who has it can see your page. There is no password.'
        => 'Su portal es una página privada a la que se llega con el enlace que le enviamos por correo electrónico. No comparta el enlace: cualquier persona que lo tenga puede ver su página. No hay contraseña.',
    'The link works for 180 days. Only the newest link works: when we send you a new one, any older link stops working. If your link stops working, contact us for a new one.'
        => 'El enlace funciona durante 180 días. Solo funciona el enlace más reciente: cuando le enviamos uno nuevo, cualquier enlace anterior deja de funcionar. Si su enlace deja de funcionar, comuníquese con nosotros para recibir uno nuevo.',
    'On the portal you can see your upcoming sessions and your progress, mark practice and homework as done with the button “I\'ve done this”, log practice you did on your own, ask for an appointment or other help, share a document with your instructor, send your instructor a message, and update your contact information.'
        => 'En el portal puede ver sus próximas sesiones y su progreso, marcar la práctica y la tarea como hechas con el botón “Ya lo hice”, registrar la práctica que hizo por su cuenta, pedir una cita u otra ayuda, compartir un documento con su instructor, enviar un mensaje a su instructor y actualizar su información de contacto.',
    'Each form on the portal is sent on its own. After you send one, a message at the top of the page says whether it went through. If the page was open for a long time, it may ask you to send the form again; what you typed is kept.'
        => 'Cada formulario del portal se envía por separado. Después de enviar uno, un mensaje en la parte superior de la página indica si se envió. Si la página estuvo abierta mucho tiempo, es posible que le pida enviar el formulario de nuevo; lo que escribió se conserva.',
    'A consent form lets a parent or guardian agree to services for a student. Read the agreement on the page. To sign, type the guardian\'s full name exactly as it is shown above the signature box, then choose “Sign and submit”. Typing the name is your electronic signature; nothing needs to be drawn or printed.'
        => 'Un formulario de consentimiento permite que un padre, madre o tutor acepte los servicios para un estudiante. Lea el acuerdo en la página. Para firmar, escriba el nombre completo del tutor exactamente como aparece encima del cuadro de firma y luego elija “Firmar y enviar”. Escribir el nombre es su firma electrónica; no hace falta dibujar ni imprimir nada.',
    'After you sign, the page confirms it and a record is placed in the student\'s file. You can withdraw consent at any time by contacting us. A consent link stops working 60 days after it is sent; ask us for a new one if yours has expired.'
        => 'Después de firmar, la página lo confirma y se guarda un registro en el expediente del estudiante. Puede retirar su consentimiento en cualquier momento comunicándose con nosotros. Un enlace de consentimiento deja de funcionar 60 días después de enviarse; pídanos uno nuevo si el suyo venció.',
    'Anyone can use the referral form to request assistive technology services: a school, a medical provider, an agency, an employer, a family member, or the person themselves. Required fields are marked with an asterisk (*). If something is missing, a list of the problems appears at the top of the form, with a link to each field.'
        => 'Cualquier persona puede usar el formulario de referencia para solicitar servicios de tecnología de asistencia: una escuela, un proveedor médico, una agencia, un empleador, un familiar o la propia persona. Los campos obligatorios están marcados con un asterisco (*). Si falta algo, aparece una lista de los problemas al principio del formulario, con un enlace a cada campo.',
    'After you send it, the page confirms that it was received. It shows your referral number and the date we will contact you by, which is within 3 business days. Write down the number. If you have not heard from us by that date, email us and mention your referral number.'
        => 'Después de enviarlo, la página confirma que se recibió. Muestra su número de referencia y la fecha en que nos comunicaremos con usted, dentro de 3 días hábiles. Anote el número. Si no tiene noticias nuestras para esa fecha, escríbanos por correo electrónico e indique su número de referencia.',
    'After training, we may email you a short survey with three questions: how satisfied you are, how well the training met your goals, and any comments. The first two are required; comments are optional. You can answer it once, and your answers go to our team to improve our services.'
        => 'Después de la capacitación, es posible que le enviemos por correo electrónico una breve encuesta de tres preguntas: qué tan satisfecho está, qué tan bien la capacitación cumplió sus metas y cualquier comentario. Las dos primeras son obligatorias; los comentarios son opcionales. Puede responderla una vez, y sus respuestas llegan a nuestro equipo para mejorar nuestros servicios.',
    'Fields marked with an asterisk (*) or with the word (required) are required. Read the label and any instructions beside a field before entering information. Review the entries before choosing Save, Send, or Submit. Changing a list selection does not save it: use the button beside the list.'
        => 'Los campos marcados con un asterisco (*) o con la palabra (obligatorio) son obligatorios. Lea la etiqueta y las instrucciones junto a cada campo antes de escribir. Revise lo que escribió antes de elegir Guardar o Enviar. Cambiar una opción de una lista no la guarda: use el botón que está junto a la lista.',
    'If an error appears, read the message, correct the named field, and try again. A file field lets you choose a file from your device. After signing in again or reloading, you may need to choose the file again.'
        => 'Si aparece un error, lea el mensaje, corrija el campo indicado e inténtelo de nuevo. Un campo de archivo le permite elegir un archivo de su dispositivo. Después de volver a iniciar sesión o de recargar la página, es posible que tenga que elegir el archivo otra vez.',
    'For dates, use the format shown by your browser or its date picker. Search filters narrow the list; clear the filters to see more results. If you are unsure what a form is asking, contact the office using the email link at the bottom of the page before submitting.'
        => 'Para las fechas, use el formato que muestra su navegador o su selector de fechas. Los filtros de búsqueda acotan la lista; quite los filtros para ver más resultados. Si no está seguro de lo que pide un formulario, comuníquese con la oficina mediante el enlace de correo electrónico al final de la página antes de enviarlo.',
    'Near the top of each page is the heading “Low-vision controls”. Choose the button “Show display options” under it to choose colors and contrast. Open “Text size and reading options” for text up to 200%, extra spacing, a narrower reading width, highlighted links, and reduced motion. “Reset display” restores the defaults. Preferences are saved in this browser when storage is available. You can also use your browser’s zoom or your device’s high-contrast settings.'
        => 'Cerca de la parte superior de cada página está el encabezado “Controles para baja visión”. Elija el botón “Mostrar opciones de visualización” que está debajo para elegir colores y contraste. Abra “Tamaño del texto y opciones de lectura” para agrandar el texto hasta un 200 %, añadir espacio, estrechar el ancho de lectura, resaltar los enlaces y reducir el movimiento. “Restablecer la vista” vuelve a los valores predeterminados. Las preferencias se guardan en este navegador cuando es posible. También puede usar el zoom de su navegador o la configuración de alto contraste de su dispositivo.',
    'Press Tab to move forward through links and controls, and Shift+Tab to move back. The “Skip to main content” link moves past the repeated navigation. Scrollable tables can receive keyboard focus so you can use arrow keys to read columns that are off screen.'
        => 'Presione Tab para avanzar por los enlaces y controles, y Mayús+Tab para retroceder. El enlace “Saltar al contenido principal” pasa por alto la navegación repetida. Las tablas desplazables pueden recibir el foco del teclado para que use las flechas y lea las columnas que no se ven en la pantalla.',
    'If you have a question, or something on this website does not work, email us. The address is at the bottom of every page, after “Questions? Email”. If you use the client portal, you can also send your instructor a message there.'
        => 'Si tiene una pregunta, o algo de este sitio web no funciona, escríbanos por correo electrónico. La dirección está al final de cada página, después de “¿Preguntas? Escriba a”. Si usa el portal de cliente, también puede enviar un mensaje a su instructor desde allí.',
    'Tell us the name of the page and what went wrong. If you sent a referral, include your referral number. Please do not email private details, such as medical information or your portal link.'
        => 'Indíquenos el nombre de la página y qué salió mal. Si envió una referencia, incluya su número de referencia. Por favor, no envíe por correo electrónico datos privados, como información médica o el enlace de su portal.',
    'Our privacy notice explains what information we keep, why, who we share it with, and your choices.'
        => 'Nuestro aviso de privacidad explica qué información guardamos, por qué, con quién la compartimos y qué opciones tiene.',
    'Read the privacy notice'
        => 'Leer el aviso de privacidad',
]);
// Signed-in staff keep their full navigation.
page_header('Help and glossary', $viewer, 'help');
// The staff guide's main topics are listed in this page's contents for signed-in staff.
if ($viewer) { $staffGuideTopicsOnly = true; require __DIR__ . '/inc/staff_guide.php'; $staffGuideTopicsOnly = false; }
if (!$viewer) echo lang_toggle();
?>
<h1><?= t('Help and glossary') ?></h1>
<p><?= t('Use this guide to find your way around, fill in forms, and understand common terms.') ?></p>
<nav aria-label="<?= h(__('Help topics')) ?>"><ul>
  <?php if ($viewer): ?><li><a href="#staff-guide">CMS user guide for staff</a>
    <ul>
    <?php foreach (staff_guide_topics() as $sgId => [$sgTitle]): ?>
      <li><a href="#<?= h($sgId) ?>"><?= h($sgTitle) ?></a></li>
    <?php endforeach; ?>
    </ul></li><?php endif; ?>
  <li><a href="#portal"><?= t('Your client portal') ?></a></li>
  <li><a href="#consent"><?= t('Signing a consent form') ?></a></li>
  <li><a href="#referral"><?= t('The referral form') ?></a></li>
  <li><a href="#survey"><?= t('The survey') ?></a></li>
  <li><a href="#forms"><?= t('Filling in forms') ?></a></li>
  <li><a href="#display"><?= t('Reading and navigation') ?></a></li>
  <li><a href="#terms"><?= t('Common terms') ?></a></li>
  <li><a href="#getting-help"><?= t('Getting help') ?></a></li>
  <li><a href="privacy.php"><?= t('Privacy notice (separate page)') ?></a></li>
</ul></nav>
<?php if ($viewer) require __DIR__ . '/inc/staff_guide.php'; ?>
<section aria-labelledby="portal"><h2 id="portal"><?= t('Your client portal') ?></h2>
<p><?= t('Your portal is a private page reached through the link we emailed you. Keep the link to yourself: anyone who has it can see your page. There is no password.') ?></p>
<p><?= t('The link works for 180 days. Only the newest link works: when we send you a new one, any older link stops working. If your link stops working, contact us for a new one.') ?></p>
<p><?= t('On the portal you can see your upcoming sessions and your progress, mark practice and homework as done with the button “I\'ve done this”, log practice you did on your own, ask for an appointment or other help, share a document with your instructor, send your instructor a message, and update your contact information.') ?></p>
<p><?= t('Each form on the portal is sent on its own. After you send one, a message at the top of the page says whether it went through. If the page was open for a long time, it may ask you to send the form again; what you typed is kept.') ?></p>
</section>
<section aria-labelledby="consent"><h2 id="consent"><?= t('Signing a consent form') ?></h2>
<p><?= t('A consent form lets a parent or guardian agree to services for a student. Read the agreement on the page. To sign, type the guardian\'s full name exactly as it is shown above the signature box, then choose “Sign and submit”. Typing the name is your electronic signature; nothing needs to be drawn or printed.') ?></p>
<p><?= t('After you sign, the page confirms it and a record is placed in the student\'s file. You can withdraw consent at any time by contacting us. A consent link stops working 60 days after it is sent; ask us for a new one if yours has expired.') ?></p>
</section>
<section aria-labelledby="referral"><h2 id="referral"><?= t('The referral form') ?></h2>
<p><?= t('Anyone can use the referral form to request assistive technology services: a school, a medical provider, an agency, an employer, a family member, or the person themselves. Required fields are marked with an asterisk (*). If something is missing, a list of the problems appears at the top of the form, with a link to each field.') ?></p>
<p><?= t('After you send it, the page confirms that it was received. It shows your referral number and the date we will contact you by, which is within 3 business days. Write down the number. If you have not heard from us by that date, email us and mention your referral number.') ?></p>
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
<p><?= t('Near the top of each page is the heading “Low-vision controls”. Choose the button “Show display options” under it to choose colors and contrast. Open “Text size and reading options” for text up to 200%, extra spacing, a narrower reading width, highlighted links, and reduced motion. “Reset display” restores the defaults. Preferences are saved in this browser when storage is available. You can also use your browser’s zoom or your device’s high-contrast settings.') ?></p>
<p><?= t('Press Tab to move forward through links and controls, and Shift+Tab to move back. The “Skip to main content” link moves past the repeated navigation. Scrollable tables can receive keyboard focus so you can use arrow keys to read columns that are off screen.') ?></p>
</section>
<?php // Clients and guardians reach this list from the public pages' footer, so it is
// translated like the sections above: [term, Spanish term, meaning, Spanish meaning].
$glossary = [
    ['AAC — augmentative and alternative communication', 'AAC — comunicación aumentativa y alternativa',
        'Devices and methods, such as speech-generating devices or symbol boards, that help a person communicate.',
        'Dispositivos y métodos, como los aparatos que generan voz o los tableros de símbolos, que ayudan a una persona a comunicarse.'],
    ['AI — artificial intelligence', 'AI — inteligencia artificial (IA)',
        'Software that drafts or suggests text. In this app its output is always a draft for staff to review; it never saves, sends, or changes a record by itself.',
        'Programas que redactan o sugieren texto. En esta aplicación, lo que producen es siempre un borrador que el personal revisa; nunca guardan, envían ni cambian un registro por sí mismos.'],
    ['AT — assistive technology', 'AT — tecnología de asistencia',
        'Tools or software that help a person perform an activity.',
        'Herramientas o programas que ayudan a una persona a realizar una actividad.'],
    ['Authorization', 'Autorización',
        'Approval for a service, often with an allowed number of hours or a spending limit.',
        'Aprobación de un servicio, a menudo con un número de horas permitido o un límite de gasto.'],
    ['BIRP — behavior, intervention, response, plan', 'BIRP — conducta, intervención, respuesta, plan',
        'A structured case-note format with one part for each of those four headings.',
        'Un formato estructurado de nota de caso, con una parte para cada uno de esos cuatro encabezados.'],
    ['CART — Communication Access Realtime Translation', 'CART — subtitulado en tiempo real para el acceso a la comunicación',
        'Live captioning typed by a trained captioner during a meeting or session.',
        'Subtítulos en vivo que escribe una persona capacitada durante una reunión o sesión.'],
    ['Caseload', 'Carga de casos',
        'The clients assigned to a staff member.',
        'Los clientes asignados a un miembro del personal.'],
    ['CCTV — closed-circuit television (video magnifier)', 'CCTV — circuito cerrado de televisión (lupa electrónica)',
        'A desktop or portable camera and screen that enlarges print and objects.',
        'Una cámara con pantalla, de escritorio o portátil, que amplía textos impresos y objetos.'],
    ['Consent', 'Consentimiento',
        'A person\'s agreement to the activity described in a form.',
        'El acuerdo de una persona con la actividad descrita en un formulario.'],
    ['CSV — comma-separated values', 'CSV — valores separados por comas',
        'A plain spreadsheet file that Excel and QuickBooks open.',
        'Un archivo sencillo de hoja de cálculo que se abre en Excel y QuickBooks.'],
    ['Current value (book value)', 'Valor actual (valor en libros)',
        'What an item cost, reduced by an equal amount each month over its useful life, counted from its purchase date.',
        'Lo que costó un artículo, reducido en una cantidad igual cada mes durante su vida útil, contando desde la fecha de compra.'],
    ['Deliverable', 'Entregable',
        'A specific item or result required by a contract.',
        'Un artículo o resultado específico que exige un contrato.'],
    ['EFT — electronic funds transfer', 'EFT — transferencia electrónica de fondos',
        'A direct bank payment. Its reference number is recorded when an invoice is marked paid.',
        'Un pago bancario directo. Su número de referencia se registra cuando una factura se marca como pagada.'],
    ['IPE — individualized plan for employment', 'IPE — plan individualizado de empleo',
        'A plan describing an employment goal and the services intended to help reach it.',
        'Un plan que describe una meta de empleo y los servicios destinados a ayudar a alcanzarla.'],
    ['KB — knowledge base', 'KB — base de conocimientos',
        'The collection of how-to articles and reference notes staff can search.',
        'La colección de artículos prácticos y notas de referencia en la que el personal puede buscar.'],
    ['MB — megabytes', 'MB — megabytes',
        'A measure of file size.',
        'Una medida del tamaño de un archivo.'],
    ['NPS — Net Promoter Score', 'NPS — índice neto de promotores',
        'A survey measure based on how likely people say they are to recommend a service.',
        'Una medida de encuesta basada en qué tan probable es, según las personas, que recomienden un servicio.'],
    ['OTP — one-time password', 'OTP — contraseña de un solo uso',
        'A temporary sign-in code. You can paste it into the code field.',
        'Un código temporal para iniciar sesión. Puede pegarlo en el campo del código.'],
    ['PAIP — purpose, assessment, intervention, plan', 'PAIP — propósito, evaluación, intervención, plan',
        'A structured case-note format with one part for each of those four headings.',
        'Un formato estructurado de nota de caso, con una parte para cada uno de esos cuatro encabezados.'],
    ['Passkey', 'Llave de acceso (passkey)',
        'A sign-in method that uses your device to approve access instead of typing an account password.',
        'Un método para iniciar sesión que usa su dispositivo para aprobar el acceso en lugar de escribir una contraseña.'],
    ['PDF — Portable Document Format', 'PDF — formato de documento portátil',
        'A common document file type that looks the same on any device.',
        'Un tipo de archivo de documento común que se ve igual en cualquier dispositivo.'],
    ['PO — purchase order', 'PO — orden de compra',
        'The order number a funder or purchaser issues for a device.',
        'El número de orden que emite un financiador o comprador para un dispositivo.'],
    ['Pre-ETS — pre-employment transition services', 'Pre-ETS — servicios de transición previos al empleo',
        'Services that help students with disabilities prepare for work and further education.',
        'Servicios que ayudan a estudiantes con discapacidades a prepararse para el trabajo y para continuar sus estudios.'],
    ['ROI — release of information', 'ROI — autorización para divulgar información',
        'In consent records, permission to share the information described in the release.',
        'En los registros de consentimiento, el permiso para compartir la información descrita en la autorización.'],
    ['RSA — Rehabilitation Services Administration', 'RSA — Administración de Servicios de Rehabilitación',
        'The agency name used in the app\'s service categories and reporting.',
        'El nombre de la agencia que se usa en las categorías de servicio y en los informes de la aplicación.'],
    ['Serial number', 'Número de serie',
        'The manufacturer\'s unique number for one device.',
        'El número único que el fabricante asigna a un dispositivo.'],
    ['SMART goal', 'Meta SMART',
        'A goal that is specific, measurable, achievable, relevant, and time-bound.',
        'Una meta específica, medible, alcanzable, relevante y con un plazo definido.'],
    ['SOAP — subjective, objective, assessment, plan', 'SOAP — subjetivo, objetivo, evaluación, plan',
        'A structured case-note format with one part for each of those four headings.',
        'Un formato estructurado de nota de caso, con una parte para cada uno de esos cuatro encabezados.'],
    ['Telepractice', 'Telepráctica',
        'A session provided remotely, such as through a video call.',
        'Una sesión que se presta a distancia, por ejemplo, por videollamada.'],
    ['VR — vocational rehabilitation', 'VR — rehabilitación vocacional',
        'Services intended to help people with disabilities prepare for, find, or keep work.',
        'Servicios destinados a ayudar a personas con discapacidades a prepararse para un trabajo, encontrarlo o conservarlo.'],
    ['VRC — vocational rehabilitation counselor', 'VRC — consejero de rehabilitación vocacional',
        'A counselor who helps plan and coordinate vocational rehabilitation services.',
        'Un consejero que ayuda a planificar y coordinar los servicios de rehabilitación vocacional.'],
    ['WPM — words per minute', 'WPM — palabras por minuto',
        'A typing or reading speed, such as a keyboarding goal of 30 WPM.',
        'Una velocidad de escritura o de lectura, como una meta de mecanografía de 30 WPM.'],
];
register_translations(array_merge(...array_map(fn(array $g): array => [$g[0] => $g[1], $g[2] => $g[3]], $glossary))); ?>
<section aria-labelledby="terms"><h2 id="terms"><?= t('Common terms') ?></h2>
<dl class="props">
<?php foreach ($glossary as [$term, , $meaning]): ?>
  <dt><?= t($term) ?></dt><dd><?= t($meaning) ?></dd>
<?php endforeach; ?>
</dl></section>
<section aria-labelledby="getting-help"><h2 id="getting-help"><?= t('Getting help') ?></h2>
<p><?= t('If you have a question, or something on this website does not work, email us. The address is at the bottom of every page, after “Questions? Email”. If you use the client portal, you can also send your instructor a message there.') ?></p>
<p><?= t('Tell us the name of the page and what went wrong. If you sent a referral, include your referral number. Please do not email private details, such as medical information or your portal link.') ?></p>
<p><?= t('Our privacy notice explains what information we keep, why, who we share it with, and your choices.') ?> <a href="privacy.php"><?= t('Read the privacy notice') ?></a></p>
</section>
<?php page_footer();
