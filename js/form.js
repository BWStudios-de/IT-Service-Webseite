// Anfrageformular (Hilfe, Termin, Frage) -> send-mail.php (serverseitiger Versand), gleicher Aufbau wie auf bwstudios.de.
// Terminanfragen sind nur Anfragen, die Bestätigung erfolgt manuell.
(() => {
  const form = document.getElementById('anfrage-form');
  const status = document.getElementById('form-status');
  const submitBtn = form.querySelector('.form-submit');
  const dateEl = form.elements.date;
  const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  const tomorrow = new Date(Date.now() + 864e5);
  dateEl.min = `${tomorrow.getFullYear()}-${String(tomorrow.getMonth() + 1).padStart(2, '0')}-${String(tomorrow.getDate()).padStart(2, '0')}`;

  // Zeitstempel fuers einfache Bot-Timing-Check im Backend.
  const stamp = () => { document.getElementById('form-ts').value = Date.now(); };
  stamp();

  // Nicht passende Bereiche ausblenden und deaktivieren: sie werden weder geprueft noch gesendet.
  const sync = () => {
    const typ = form.elements.typ.value;
    form.querySelectorAll('[data-show]').forEach((box) => {
      const on = box.dataset.show === typ;
      box.hidden = !on;
      box.querySelectorAll('input').forEach((i) => { i.disabled = !on; });
    });
  };
  form.querySelectorAll('input[name="typ"]').forEach((r) => r.addEventListener('change', sync));
  sync();

  const setError = (el, id, msg) => {
    el.setAttribute('aria-invalid', String(!!msg));
    document.getElementById(`${id}-error`).textContent = msg;
    return msg ? el : null;
  };
  const show = (text, cls) => { status.textContent = text; status.className = `form-status ${cls}`.trim(); };

  const rules = {
    name: () => setError(form.elements.name, 'name', form.elements.name.value.trim() ? '' : 'Bitte gib deinen Namen an.'),
    email: () => setError(form.elements.email, 'email', emailRe.test(form.elements.email.value.trim()) ? '' : 'Bitte gib eine gültige E-Mail-Adresse an.'),
    date: () => setError(dateEl, 'date', dateEl.value && dateEl.value >= dateEl.min ? '' : 'Bitte wähle ein Datum.'),
    time: () => setError(form.querySelector('input[name="time"]'), 'time', form.elements.time.value ? '' : 'Bitte wähle eine Uhrzeit.'),
  };
  const check = (step) => (rules[step.dataset.rule] || (() => null))();

  // Schrittweise Anzeige: immer nur eine Frage sichtbar (ohne JavaScript bleiben alle sichtbar).
  const steps = [...form.querySelectorAll('.step')];
  const nav = form.querySelector('.form-nav');
  const backBtn = form.querySelector('.form-back');
  const nextBtn = form.querySelector('.form-next');
  const progress = document.createElement('p');
  progress.className = 'step-progress';
  form.insertBefore(progress, form.firstChild);
  nav.hidden = false;
  form.classList.add('is-stepped');
  let current = 0;

  const active = () => steps.filter((st) => !st.dataset.show || st.dataset.show === form.elements.typ.value);
  const render = (focus) => {
    const list = active();
    current = Math.max(0, Math.min(current, list.length - 1));
    steps.forEach((st) => st.classList.toggle('is-current', st === list[current]));
    progress.textContent = `Schritt ${current + 1} von ${list.length}`;
    progress.style.setProperty('--progress', `${((current + 1) / list.length) * 100}%`);
    backBtn.hidden = current === 0;
    nextBtn.hidden = current === list.length - 1;
    submitBtn.hidden = !nextBtn.hidden;
    if (focus) (list[current].querySelector('input:checked') || list[current].querySelector('input, textarea')).focus();
  };
  const go = (i) => { current = i; render(true); };
  const next = () => {
    const bad = check(active()[current]);
    show(bad ? 'Bitte prüfe die markierten Felder.' : '', bad ? 'error' : '');
    if (bad) { bad.focus(); return; }
    go(current + 1);
  };
  nextBtn.addEventListener('click', next);
  backBtn.addEventListener('click', () => go(current - 1));
  form.querySelectorAll('input[name="typ"]').forEach((r) => r.addEventListener('change', () => render(false)));
  render(false);

  steps.forEach((st) => st.addEventListener('focusout', (ev) => {
    if (st.contains(ev.relatedTarget) || !st.dataset.rule) return;
    if (st.hidden) return;
    check(st);
  }));

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (nextBtn.hidden === false) { next(); return; }
    const termin = form.elements.typ.value === 'termin';
    const list = active();
    const checks = list.map(check);
    const bad = checks.findIndex(Boolean);
    if (bad > -1) {
      const msgs = list.map((st) => st.querySelector('.field-error')).filter((e) => e && e.textContent).map((e) => e.textContent);
      show(`Bitte prüfe die markierten Felder: ${msgs.join(' ')}`, 'error');
      current = bad;
      render(false);
      checks[bad].focus();
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Wird gesendet';
    show('Wird gesendet …', '');

    try {
      const response = await fetch('/send-mail.php', { method: 'POST', body: new FormData(form) });
      const result = await response.json();

      if (result.ok) {
        show(termin
          ? 'Deine Anfrage ist eingegangen, der Termin wird noch bestätigt. Du erhältst eine Rückmeldung per E-Mail.'
          : 'Danke, deine Nachricht wurde gesendet. Ich melde mich zeitnah.', 'success');
        status.tabIndex = -1;
        status.focus();
        form.reset();
        sync();
        stamp();
        current = 0;
        render(false);
      } else if (response.status === 429) {
        show('Zu viele Anfragen in kurzer Zeit. Bitte versuch es in ein paar Minuten erneut.', 'error');
      } else {
        show('Anfrage konnte nicht gesendet werden. Bitte versuch es erneut oder schreib direkt an info@bwstudios.de.', 'error');
      }
    } catch (err) {
      show('Anfrage konnte nicht gesendet werden. Bitte versuch es erneut oder schreib direkt an info@bwstudios.de.', 'error');
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Anfrage senden';
      status.focus();
    }
  });
})();
