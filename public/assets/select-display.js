// Optional native list presentation. Never intercept keys or submit a form.
(function () {
  'use strict';
  var original = new Map();
  function sync() {
    var expanded = document.documentElement.getAttribute('data-vision-lists') === 'true';
    // Release references to controls removed by a dynamic form.
    original.forEach(function (_, select) { if (!select.isConnected) original.delete(select); });
    document.querySelectorAll('select').forEach(function (select) {
      if (select.multiple || (!original.has(select) && select.size > 1)) return;
      var index = select.selectedIndex;
      if (expanded) {
        if (!original.has(select)) original.set(select, select.getAttribute('size'));
        select.size = Math.max(2, Math.min(6, select.options.length));
      } else if (original.has(select)) {
        var size = original.get(select);
        if (size === null) select.removeAttribute('size');
        else select.setAttribute('size', size);
        original.delete(select);
      }
      // Changing presentation must not pick a value, including on an empty selection.
      if (select.selectedIndex !== index) select.selectedIndex = index;
    });
  }
  sync();
  if (window.MutationObserver) {
    new MutationObserver(sync).observe(document.documentElement, {
      subtree: true, childList: true, attributes: true, attributeFilter: ['data-vision-lists']
    });
  }
})();
