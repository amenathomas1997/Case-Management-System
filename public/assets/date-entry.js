// Keep native pickers by default; offer explicit text entry without losing values.
(function () {
  'use strict';
  var records = [], serial = 0;
  // Words in the page language (layout.php puts them on <body data-cms-text>); English otherwise.
  var T = {lang: 'en', typeDate: 'Type date instead', typeTime: 'Type time instead', typeDateTime: 'Type date and time instead',
    useDate: 'Use date picker instead', useTime: 'Use time picker instead', useDateTime: 'Use date and time picker instead',
    fmtDate: 'YYYY-MM-DD, for example 2026-10-14', fmtTime: 'HH:MM in 24-hour time, for example 14:30',
    fmtDateTime: 'YYYY-MM-DDTHH:MM, for example 2026-10-14T14:30', nounDate: 'date', nounTime: 'time', nounDateTime: 'date and time',
    hint: 'Type {format}. You can paste a complete value.', errInvalid: 'Enter a valid {noun} using {format}.',
    errRequired: 'Enter the required {noun} using {format}.', errRange: 'Enter a {noun} within the allowed range and interval.',
    earliest: 'Earliest: {value}.', latest: 'Latest: {value}.', use: 'Use {format}.'};
  try {
    var given = JSON.parse((document.body && document.body.getAttribute('data-cms-text')) || 'null');
    if (given && typeof given === 'object') Object.keys(T).forEach(function (k) { if (typeof given[k] === 'string' && given[k] !== '') T[k] = given[k]; });
  } catch (e) {}
  function say(key, fill) { return T[key].replace(/\{(\w+)\}/g, function (m, k) { return fill && k in fill ? fill[k] : m; }); }
  // The field's own name from its label, without what is hidden from screen readers (the
  // visual "*"), hints, errors or hidden extras, so the button never repeats them.
  function fieldName(label) {
    var copy = label.cloneNode(true);
    copy.querySelectorAll('[aria-hidden="true"], .hint, .field-error, .visually-hidden, [data-date-entry]').forEach(function (e) { e.remove(); });
    var name = copy.textContent.replace(/\s+/g, ' ').replace(/[\s:*]+$/, '').trim();
    return name || label.textContent.replace(/\s+/g, ' ').replace(/[\s:*]+$/, '').trim();
  }
  document.querySelectorAll('input[type="date"], input[type="datetime-local"], input[type="time"]').forEach(function (field) {
    if (field.disabled || field.readOnly) return;
    var type = field.type, date = type === 'date', time = type === 'time';
    var which = date ? 'Date' : time ? 'Time' : 'DateTime';
    var format = T['fmt' + which], noun = T['noun' + which];
    var id;
    do { id = 'date-entry-' + (++serial); } while (document.getElementById(id));
    var label = (field.labels && field.labels[0]) || null;
    var name = label ? fieldName(label) : '';
    // One short button: "Type date instead", with the field's name as hidden text only.
    var button = document.createElement('button'), buttonText = document.createElement('span'), buttonName = null;
    button.type = 'button'; button.className = 'btn btn-secondary btn-sm'; button.lang = T.lang;
    button.appendChild(buttonText);
    if (name) {
      buttonName = document.createElement('span'); buttonName.className = 'visually-hidden'; buttonName.textContent = ': ' + name;
      button.appendChild(buttonName);
    }
    buttonText.textContent = say('type' + which);
    // The format and any error, shown under the field and said as part of its label (owner
    // rules 1 and 3: not a description JAWS may skip, not an extra line in browse mode).
    var note = document.createElement('p');
    note.id = id; note.className = 'hint'; note.lang = T.lang; note.hidden = true;
    var spoken = null;
    if (label) {
      note.setAttribute('aria-hidden', 'true');
      spoken = document.createElement('span');
      spoken.className = 'visually-hidden'; spoken.lang = T.lang; spoken.setAttribute('data-date-entry', '');
    }
    field.insertAdjacentElement('afterend', note); note.insertAdjacentElement('afterend', button);
    var oldDescription = field.getAttribute('aria-describedby'), oldInvalid = field.getAttribute('aria-invalid');
    var textMode = false;
    function error() {
      var probe = document.createElement('input'); probe.type = type;
      ['min','max','step','required'].forEach(function (a) { if (field.hasAttribute(a)) probe.setAttribute(a, field.getAttribute(a)); });
      probe.value = field.value;
      if (field.value && (probe.value === '' || probe.value !== field.value)) return say('errInvalid', {noun: noun, format: format});
      if (!probe.validity.valid) {
        if (probe.validity.valueMissing) return say('errRequired', {noun: noun, format: format});
        return say('errRange', {noun: noun}) + ' ' + (field.min ? say('earliest', {value: field.min}) + ' ' : '') + (field.max ? say('latest', {value: field.max}) + ' ' : '') + say('use', {format: format});
      }
      return '';
    }
    function validate() {
      if (!textMode) return true;
      var message = error();
      note.textContent = message || say('hint', {format: format});
      note.className = message ? 'field-error' : 'hint';
      if (spoken) spoken.textContent = ' ' + note.textContent;
      if (message) field.setAttribute('aria-invalid','true');
      else if (oldInvalid !== null) field.setAttribute('aria-invalid',oldInvalid);
      else field.removeAttribute('aria-invalid');
      return !message;
    }
    button.addEventListener('click', function () {
      if (textMode && !validate()) { field.focus(); return; }
      // Partial native input has no serializable value: never erase it on a mode switch.
      if (!textMode && field.validity.badInput) { field.reportValidity(); field.focus(); return; }
      var value = field.value;
      textMode = !textMode;
      field.type = textMode ? 'text' : type; field.value = value;
      note.hidden = !textMode;
      if (textMode) {
        if (spoken) label.appendChild(spoken);
        else field.setAttribute('aria-describedby', (oldDescription ? oldDescription + ' ' : '') + id);
        validate();
      } else {
        if (spoken && spoken.parentNode) spoken.parentNode.removeChild(spoken);
        if (oldDescription !== null) field.setAttribute('aria-describedby',oldDescription); else field.removeAttribute('aria-describedby');
        if (oldInvalid !== null) field.setAttribute('aria-invalid',oldInvalid); else field.removeAttribute('aria-invalid');
      }
      buttonText.textContent = say((textMode ? 'use' : 'type') + which);
      field.focus();
    });
    field.addEventListener('blur',validate);
    records.push({field:field, validate:validate});
  });
  document.addEventListener('submit',function (event) {
    var first = null;
    records.forEach(function (r) {
      if (r.field.form === event.target && !r.field.disabled && !r.validate() && !first) first = r.field;
    });
    if (first) { event.preventDefault(); first.focus(); }
  },true);
})();
