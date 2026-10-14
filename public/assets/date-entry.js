// Keep native pickers by default; offer explicit text entry without losing values.
(function () {
  'use strict';
  var records = [], serial = 0;
  document.querySelectorAll('input[type="date"], input[type="datetime-local"], input[type="time"]').forEach(function (field) {
    if (field.disabled || field.readOnly) return;
    var type = field.type, date = type === 'date', time = type === 'time';
    var format = date ? 'YYYY-MM-DD, for example 2026-10-14' : time ? 'HH:MM in 24-hour time, for example 14:30' : 'YYYY-MM-DDTHH:MM, for example 2026-10-14T14:30';
    var noun = date ? 'date' : time ? 'time' : 'date and time';
    var id;
    do { id = 'date-entry-' + (++serial); } while (document.getElementById(id));
    var button = document.createElement('button'), note = document.createElement('p');
    button.type = 'button'; button.className = 'btn btn-secondary btn-sm'; button.lang = 'en';
    button.textContent = 'Type ' + noun + ' instead';
    var label = Array.from(field.labels || []).map(function (l) { return l.textContent.trim(); }).join(' ');
    button.setAttribute('aria-label', button.textContent + (label ? ': ' + label : ''));
    note.id = id; note.className = 'hint'; note.lang = 'en'; note.hidden = true;
    field.insertAdjacentElement('afterend', note); note.insertAdjacentElement('afterend', button);
    var oldDescription = field.getAttribute('aria-describedby'), oldInvalid = field.getAttribute('aria-invalid');
    var textMode = false;
    function error() {
      var probe = document.createElement('input'); probe.type = type;
      ['min','max','step','required'].forEach(function (a) { if (field.hasAttribute(a)) probe.setAttribute(a, field.getAttribute(a)); });
      probe.value = field.value;
      if (field.value && (probe.value === '' || probe.value !== field.value)) return 'Enter a valid ' + noun + ' using ' + format + '.';
      if (!probe.validity.valid) {
        if (probe.validity.valueMissing) return 'Enter the required ' + noun + ' using ' + format + '.';
        return 'Enter a ' + noun + ' within the allowed range and interval. ' + (field.min ? 'Earliest: ' + field.min + '. ' : '') + (field.max ? 'Latest: ' + field.max + '. ' : '') + 'Use ' + format + '.';
      }
      return '';
    }
    function validate() {
      if (!textMode) return true;
      var message = error();
      note.textContent = message || 'Type ' + format + '. You can paste a complete value.';
      note.className = message ? 'field-error' : 'hint';
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
      if (textMode) { field.setAttribute('aria-describedby', (oldDescription ? oldDescription + ' ' : '') + id); validate(); }
      else {
        if (oldDescription !== null) field.setAttribute('aria-describedby',oldDescription); else field.removeAttribute('aria-describedby');
        if (oldInvalid !== null) field.setAttribute('aria-invalid',oldInvalid); else field.removeAttribute('aria-invalid');
      }
      button.textContent = textMode ? 'Use ' + noun + ' picker instead' : 'Type ' + noun + ' instead';
      button.setAttribute('aria-label',button.textContent + (label ? ': ' + label : ''));
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
