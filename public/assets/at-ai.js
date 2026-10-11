(() => {
  'use strict';
  document.querySelectorAll('[data-at-ai]:not([data-ai-ready])').forEach(panel => {
    panel.dataset.aiReady = 'true';
    const form = panel.closest('form');
    if (!form) return;
    const field = panel.querySelector('[data-ai-field]');
    const status = panel.querySelector('[data-ai-status]');
    const draft = panel.querySelector('[data-ai-draft]');
    const generate = panel.querySelector('[data-ai-generate]');
    const apply = panel.querySelector('[data-ai-apply]');
    const undo = panel.querySelector('[data-ai-undo]');
    const map = JSON.parse(panel.dataset.map || '{}');
    let target = null, previous = '', applied = '', generatedFor = '', targetName = '', busy = false;
    // The Draft button usually holds keyboard focus while a draft is requested.
    // Disabling it would drop that focus, so it is marked aria-disabled instead
    // and further activations are ignored until the request ends.
    const setBusy = on => { busy = on; generate.ariaDisabled = on ? 'true' : 'false'; };
    // The token is read on every request: session-forms.js refreshes window.__csrf
    // when it re-checks the session, but this form's own field only on its submit.
    const token = () => (typeof window !== 'undefined' && window.__csrf) || form.elements.namedItem('csrf')?.value || '';
    const SESSION_CHANGED = 'Your session has changed or ended, so no draft was made and your form is unchanged. Copy your work, sign in again in a new tab if asked, then reload this page and try again.';
    const CONNECTION = 'We could not reach the server, so no draft was made and your form is unchanged. Check your connection and try again.';
    generate.addEventListener('click', async () => {
      if (busy) return;
      const values = {...JSON.parse(panel.dataset.context || '{}')};
      new FormData(form).forEach((value, key) => { if (typeof value === 'string' && key !== 'csrf') values[key] = value; });
      Object.entries(map).forEach(([key, name]) => { values[key] = form.elements.namedItem(name)?.value || ''; });
      generatedFor = field.value;
      targetName = field.options?.[field.selectedIndex]?.text || 'the chosen field';
      setBusy(true); apply.disabled = true; status.textContent = 'Drafting… Your existing text is unchanged.';
      try {
        let response, result = null;
        try {
          response = await fetch('at_ai.php', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': token()}, body: JSON.stringify({kind: panel.dataset.kind, field: generatedFor, values, id: values.id || 0, instruction: panel.querySelector('[data-ai-request]').value})});
        } catch (error) { throw new Error(CONNECTION); }
        // A signed-in request kept the session alive on the server; tell the
        // inactivity warning (session-timeout.js) so it does not interrupt.
        if (response.ok) { try { document.dispatchEvent(new Event('cms:activity')); } catch (error) {} }
        // An ended session (401), a refused stale token (403, plain text) or a sign-in
        // page in place of JSON means the session changed, not that the connection failed.
        if (response.status !== 401 && response.status !== 403) {
          try { result = await response.json(); } catch (error) { throw new Error(error && error.name === 'SyntaxError' ? SESSION_CHANGED : CONNECTION); }
        }
        if (!result || typeof result !== 'object') throw new Error(SESSION_CHANGED);
        if (!response.ok || !result.ok) throw new Error(result.error || 'Drafting failed.');
        draft.value = result.text; panel.querySelector('[data-ai-review]').hidden = false;
        // A draft cut off at the length limit is marked in its label and said so
        // in the status, as the other AI pages do, so the end is checked before use.
        const cut = !!result.truncated;
        const label = draft.id ? panel.querySelector('label[for="' + draft.id + '"]') : null;
        let mark = panel.querySelector('[data-ai-incomplete]');
        if (!mark && label) { mark = document.createElement('span'); mark.dataset.aiIncomplete = ''; mark.textContent = ' (incomplete)'; label.appendChild(mark); }
        if (mark) mark.hidden = !cut;
        status.textContent = cut
          ? 'Draft for ' + targetName + ' ready, but it is incomplete: the AI stopped before finishing. Check the end, or ask for a shorter draft.'
          : 'Draft for ' + targetName + ' ready. Review it, then apply it if you want to use it.';
        apply.disabled = false;
        // Take the user to the draft only if they are still waiting on the button;
        // if they moved on, the status message above tells them it is ready.
        const here = document.activeElement;
        if (!here || here === generate || here === document.body) draft.focus();
      } catch (error) { status.textContent = error.message; }
      finally { setBusy(false); }
    });
    apply.addEventListener('click', () => {
      const destination = form.elements.namedItem(map[generatedFor] || generatedFor);
      if (!destination || !('value' in destination)) { status.textContent = 'The destination field is unavailable.'; return; }
      // Restore must give back the user's own text: a repeated Apply (a double-click,
      // or an edited draft) keeps it unless the user changed the field since.
      if (undo.hidden || destination !== target || destination.value !== applied) previous = destination.value;
      target = destination; target.value = draft.value;
      target.dispatchEvent(new Event('input', {bubbles: true})); applied = target.value; undo.hidden = false;
      status.textContent = 'Draft applied to ' + targetName + '. Review and save when ready.'; target.focus();
    });
    undo.addEventListener('click', () => {
      if (target) { target.value = previous; target.dispatchEvent(new Event('input', {bubbles: true})); target.focus(); }
      undo.hidden = true; status.textContent = 'Previous text restored.';
    });
  });
})();
