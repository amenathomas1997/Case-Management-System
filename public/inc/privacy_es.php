<?php
// The privacy notice in Spanish (3.0.39), included by privacy.php when the page is in Spanish.
// The same facts as the English page, from the same variables; keep the two in step.
// Uses: $org, $contact, $mail, $aiOn, $aiProvider, $redact, $retYears, $operator, $address,
// $law, $hosting, PRIVACY_UPDATED.
$mailEs = $contact !== '' ? $mail : 'nosotros';
?>
<h1>Aviso de privacidad</h1>
<p>Este aviso explica qué información guarda <?= h($org) ?> sobre usted, por qué, con quién se comparte y qué opciones tiene. Cubre este sitio web (incluidos el formulario de referidos, el portal del cliente y el portal del consejero) y la aplicación de iPhone del personal.</p>
<p>Última actualización: <?= en(fmt_date(PRIVACY_UPDATED)) ?>.</p>
<?php if ($operator !== '' || $address !== '' || $law !== ''): ?>
<p><?php if ($operator !== '' || $address !== ''): ?><?= h($org) ?><?= $operator !== '' ? ' está a cargo de ' . h($operator) : '' ?><?= $address !== '' ? ', ' . h($address) : '' ?>.<?php endif; ?><?php if ($law !== ''): ?> Cumplimos <?= brand('privacy_law_es') !== '' ? h(brand('privacy_law_es')) : en($law) ?>.<?php endif; ?></p>
<?php endif; ?>

<nav aria-labelledby="privacy-toc-h"><h2 id="privacy-toc-h">En esta página</h2>
<ul>
  <li><a href="#collect">Qué información recopilamos</a></li>
  <li><a href="#use">Cómo la usamos</a></li>
  <li><a href="#share">Con quién la compartimos</a></li>
  <li><a href="#ai">Funciones de inteligencia artificial y de voz</a></li>
  <li><a href="#cookies">Cookies y su navegador</a></li>
  <li><a href="#security">Cómo la protegemos</a></li>
  <li><a href="#keep">Cuánto tiempo la guardamos</a></li>
  <li><a href="#choices">Sus opciones</a></li>
<?php if ($hipaa): ?>  <li><a href="#hipaa">Sus derechos bajo HIPAA</a></li><?php endif; ?>
  <li><a href="#iphone">La aplicación de iPhone del personal</a></li>
<?php if ($extraHeading !== ''): ?>  <li><a href="#extra"><?= wording_html('privacy_extra_heading') ?></a></li>
<?php endif; ?>  <li><a href="#contact">Preguntas y solicitudes</a></li>
</ul></nav>

<section aria-labelledby="collect"><h2 id="collect">Qué información recopilamos</h2>
<h3>Sobre clientes y estudiantes</h3>
<ul>
  <li>Datos de contacto: nombre, fecha de nacimiento, teléfono, correo electrónico y dirección.</li>
  <li>Información que nos ayuda a atenderle: su discapacidad y sus necesidades de acceso, la tecnología que usa, y sus formatos e idioma preferidos.</li>
  <li>Para estudiantes: datos de la escuela y los datos de contacto de un padre, madre o tutor.</li>
  <li>Su consejero o consejera de rehabilitación vocacional (VR), las autorizaciones de financiamiento y los registros de facturación.</li>
  <li>Registros de nuestros servicios: notas del caso, evaluaciones, metas, horas de capacitación, préstamos de equipos, informes de incidentes y documentos que usted o nosotros subimos, incluidas las firmas.</li>
  <li>Lo que nos envía por el portal del cliente: mensajes, solicitudes, registros de práctica, documentos y cambios en sus datos de contacto.</li>
  <li>Los formularios de consentimiento que firma un tutor y las autorizaciones para compartir información que usted firma (nombre escrito, fecha y la dirección de internet desde donde se firmó), y las respuestas a encuestas que decida dar.</li>
  <li>Los correos que envía a la dirección de nuestra oficina pueden archivarse en su expediente.</li>
</ul>
<h3>Sobre quienes refieren a alguien</h3>
<p>Su nombre, correo, teléfono y organización, cómo conoce a la persona y con qué necesita ayuda. Para quien se refiere a sí mismo, un rango de ingresos mensuales, que solo decide si la capacitación es gratuita. Registramos la dirección de internet desde donde se envió un referido, para evitar abusos.</p>
<h3>Sobre consejeros de VR</h3>
<p>Su nombre, correo y agencia, los clientes vinculados a usted y los mensajes que envía por el portal del consejero.</p>
<h3>Sobre el personal</h3>
<p>Su nombre, correo, configuración de seguridad para iniciar sesión, horario de trabajo y un registro de lo que hace en el sistema (el registro de auditoría), incluida la dirección de internet desde donde inicia sesión.</p>
<p>No recopilamos su ubicación.</p>
</section>

<section aria-labelledby="use"><h2 id="use">Cómo la usamos</h2>
<ul>
  <li>Para prestar servicios de evaluación, capacitación y equipos de tecnología de asistencia.</li>
  <li>Para programar sesiones, enviar recordatorios y mantenernos en contacto con usted.</li>
  <li>Para informar el progreso a su consejero de VR o a la agencia que financia, y para facturar los servicios que autorizan.</li>
  <li>Para cumplir nuestras obligaciones legales, contractuales y de registro, y para mantener seguro el sistema.</li>
</ul>
<p>No vendemos su información ni la usamos para publicidad.</p>
</section>

<section aria-labelledby="share"><h2 id="share">Con quién la compartimos</h2>
<ul>
  <li><strong>Su consejero de VR o la agencia que financia</strong>, cuando le refirieron o pagan sus servicios: informes de progreso, informes de evaluación, solicitudes de autorización y facturas.</li>
  <li><strong>Microsoft 365</strong>, que envía y recibe nuestro correo (incluidos los códigos para iniciar sesión y los recordatorios), guarda nuestras copias de respaldo cifradas fuera del servidor y permite que el personal inicie sesión con una cuenta de Microsoft.</li>
  <?php if ($aiOn): ?>
  <li><strong><?= en($aiProvider) ?></strong>, el servicio de inteligencia artificial que usamos como ayuda para redactar. Vea <a href="#ai">Funciones de inteligencia artificial y de voz</a>.</li>
  <?php endif; ?>
  <?php if ($hosting !== ''): ?>
  <li><strong><?= en($hosting) ?></strong>, que proporciona el servidor donde funciona este sistema. Nosotros configuramos y administramos el servidor; <?= en($hosting) ?> proporciona la computadora y su conexión, no acceso a nuestros expedientes.</li>
  <?php else: ?>
  <li><strong>La empresa que aloja nuestro servidor</strong>, que guarda el sistema por nosotros.</li>
  <?php endif; ?>
  <li>Cualquier otra persona, como un empleador o un médico, solo cuando usted firma una autorización que la nombra (dura un año y puede retirarla), o cuando la ley lo exige.</li>
</ul>
</section>

<section aria-labelledby="ai"><h2 id="ai">Funciones de inteligencia artificial y de voz</h2>
<?php if ($aiOn): ?>
<p>El personal puede usar inteligencia artificial como ayuda para redactar notas, informes, traducciones y planes. Un miembro del personal siempre revisa las sugerencias antes de guardarlas. Las solicitudes se envían a <?= en($aiProvider) ?>.</p>
<?php if ($redact): ?>
<p>Antes de enviar una solicitud, el sistema quita los nombres, los correos electrónicos, los números de teléfono, las direcciones y las fechas de nacimiento. La descripción de las necesidades y los servicios sí se envía, porque el borrador depende de ella. Dos tipos de solicitud no se anonimizan por completo: las traducciones y las solicitudes habladas al asistente de voz.</p>
<?php else: ?>
<p>Las solicitudes pueden incluir datos que identifican, como nombres, porque la eliminación de identificadores está desactivada en este momento.</p>
<?php endif; ?>
<p>Registramos qué función de inteligencia artificial se usó y qué tan larga fue la solicitud, no su contenido.</p>
<?php else: ?>
<p>Las funciones de redacción con inteligencia artificial están desactivadas en este momento. Si las activamos, primero actualizaremos este aviso.</p>
<?php endif; ?>
<p>En el sitio web, el asistente de voz usa el reconocimiento de voz de su navegador. Algunos navegadores, como Google Chrome, envían el audio al fabricante del navegador para convertirlo en texto. En la aplicación de iPhone, la voz se convierte en texto en el teléfono cuando el teléfono lo permite; si no, lo hace Apple. No guardamos grabaciones de voz.</p>
</section>

<section aria-labelledby="cookies"><h2 id="cookies">Cookies y su navegador</h2>
<p>Usamos una sola cookie, que mantiene su sesión iniciada y se borra cuando cierra el navegador. Las opciones de visualización que elige, como el tamaño del texto y los colores, se guardan en su propio navegador. No usamos cookies de análisis, de seguimiento ni de publicidad, y el sitio no carga nada de otras empresas.</p>
</section>

<section aria-labelledby="security"><h2 id="security">Cómo la protegemos</h2>
<ul>
  <li>Todas las conexiones con este sitio están cifradas (HTTPS).</li>
  <li>El personal inicia sesión con verificación en dos pasos o con una llave de acceso, y la sesión se cierra después de un tiempo sin actividad.</li>
  <li>Cada miembro del personal ve solo las áreas, y donde está configurado solo los clientes, que su función necesita.</li>
  <li>Los cambios, y quién abrió el expediente de cada cliente, quedan en un registro de auditoría.</li>
  <li>Las notas del caso no se pueden cambiar 30 días después de escritas. Una corrección se agrega junto a la nota, y se guardan las versiones anteriores.</li>
  <li>Los archivos subidos se guardan fuera de la parte pública del sitio. Las copias de respaldo fuera del servidor están cifradas.</li>
</ul>
</section>

<section aria-labelledby="keep"><h2 id="keep">Cuánto tiempo la guardamos</h2>
<ul>
  <li>Los registros de servicios se guardan durante el tiempo que exigen quienes financian, los contratos y la ley.<?php if ($retYears > 0): ?> Los expedientes de clientes cerrados se archivan automáticamente después de <?= $retYears ?> año<?= $retYears === 1 ? '' : 's' ?>.<?php endif; ?></li>
  <li>Lo que el personal elimina va a una papelera y se borra para siempre después de 30 días, excepto las autorizaciones, los planes y las autorizaciones para compartir información, y los expedientes que se guardan por una retención.</li>
  <li>Las copias de respaldo diarias se guardan durante 30 días.</li>
  <li>Los registros de inicio de sesión y el registro de auditoría se guardan para proteger el sistema.</li>
</ul>
</section>

<section aria-labelledby="choices"><h2 id="choices">Sus opciones</h2>
<ul>
  <li><strong>Ver su información.</strong> Pida una copia de su expediente en su portal del cliente, o pídanosla. Respondemos en un plazo de 30 días.</li>
  <li><strong>Corregirla.</strong> Actualice sus datos de contacto en su portal del cliente, o pida allí o pídanos que corrijamos cualquier otra cosa.</li>
  <li><strong>Retirar su consentimiento.</strong> Usted, o un padre, madre o tutor, puede retirar un consentimiento o una autorización en cualquier momento comunicándose con nosotros. Esto no deshace lo que ya se compartió.</li>
  <li><strong>Las encuestas son opcionales.</strong> Puede saltar cualquier pregunta.</li>
</ul>
</section>

<?php if ($hipaa): ?>
<section aria-labelledby="hipaa"><h2 id="hipaa">Sus derechos bajo HIPAA</h2>
<p>Tratamos sus registros de servicios y de salud como información de salud protegida bajo las reglas federales de HIPAA. Usted tiene derecho a:</p>
<ul>
  <li><strong>Obtener una copia de sus expedientes.</strong> Pídala por escrito; le daremos una copia, por lo general en un plazo de 30 días, en el formato que pida cuando sea posible.</li>
  <li><strong>Pedirnos que corrijamos sus expedientes.</strong> Si no estamos de acuerdo, le diremos por escrito por qué, y usted puede agregar una declaración propia.</li>
  <li><strong>Saber con quién hemos compartido sus expedientes</strong>, excepto para su atención, el pago y el funcionamiento de nuestros servicios.</li>
  <li><strong>Pedirnos que limitemos</strong> lo que compartimos, y que nos comuniquemos con usted de cierta manera o en cierto lugar. Aceptaremos toda solicitud razonable.</li>
  <li><strong>Obtener una copia en papel de este aviso</strong> en cualquier momento, aunque haya aceptado recibirlo de forma electrónica.</li>
  <li><strong>Elegir a alguien que actúe por usted</strong>, como un tutor legal o una persona con poder notarial para asuntos médicos.</li>
</ul>
<p>Nunca vendemos su información, y le pediremos permiso por escrito antes de usarla para mercadeo o para cualquier fin que no se describa en este aviso. Puede retirar ese permiso en cualquier momento. Si alguna vez ocurre una filtración de su información, se lo diremos.</p>
<p>Si cree que se violaron sus derechos de privacidad, puede presentar una queja ante nosotros (vea <a href="#contact">Preguntas y solicitudes</a>) o ante el Departamento de Salud y Servicios Humanos de los Estados Unidos, Oficina de Derechos Civiles, en <a href="https://www.hhs.gov/ocr/complaints/" hreflang="en">hhs.gov/ocr/complaints</a> o al 1-800-368-1019. No tomaremos represalias contra usted por presentar una queja.</p>
</section>
<?php endif; ?>

<section aria-labelledby="iphone"><h2 id="iphone">La aplicación de iPhone del personal</h2>
<p>La aplicación de iPhone es para el personal de <?= h($org) ?>. Se conecta solo a este sitio web, por una conexión cifrada.</p>
<ul>
  <li>La sesión se guarda en el Llavero del iPhone. Las notas, horas y borradores sin enviar se guardan en un almacenamiento protegido de la aplicación, no se incluyen en las copias de respaldo del iPhone y se borran después de 14 días o al cerrar la sesión. En un iPhone sin código de acceso, la aplicación no guarda ninguno de ellos.</li>
  <li>Si se pierde un teléfono, el personal puede reportarlo como perdido desde el sitio web: la próxima vez que se conecte, la aplicación borra todo lo que guarda y cierra la sesión.</li>
  <li>La aplicación pide usar Face ID (para bloquear la aplicación), la cámara (para escanear documentos), el micrófono y el reconocimiento de voz (para el asistente de voz), y Fotos (solo para guardar la imagen de un documento que usted elija).</li>
  <li>La aplicación no contiene análisis, publicidad ni seguimiento, y no recopila su ubicación.</li>
</ul>
</section>

<?= $extraHtml ?><section aria-labelledby="contact"><h2 id="contact">Preguntas y solicitudes</h2>
<p>Para hacer una pregunta, pedir una copia o una corrección de su información, o presentar una queja, escriba a <?= $mailEs ?><?= $operator !== '' ? ', o escriba por correo postal a ' . h($operator) . ($address !== '' ? ', ' . h($address) : '') : '' ?>.<?= $operator !== '' ? ' ' . h($operator) . ' atiende las solicitudes de privacidad.' : '' ?> Le responderemos lo antes posible. Si cambiamos este aviso, actualizaremos la fecha que aparece al principio.</p>
</section>
