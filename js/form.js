// Anfrageformular -> send-mail.php (serverseitiger Versand)
(() => {
  const form = document.getElementById('anfrage-form');
  if (!form) return;
  const status = document.getElementById('form-status');
  const submitBtn = form.querySelector('.form-submit');
  const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  const mailFallback = 'Bitte versuch es erneut oder schreib direkt an info@bwstudios.de.';

  // Zeitstempel fürs einfache Bot-Timing-Check im Backend
  const stamp = () => { document.getElementById('form-ts').value = Date.now(); };
  stamp();

  const setError = (el, id, msg) => {
    el.setAttribute('aria-invalid', String(!!msg));
    document.getElementById(`${id}-error`).textContent = msg;
    return msg ? el : null;
  };
  const show = (text, cls) => { status.textContent = text; status.className = `form-status ${cls}`.trim(); };

  const checks = () => [
    setError(form.elements.name, 'name', form.elements.name.value.trim() ? '' : 'Bitte gib deinen Namen an.'),
    setError(form.elements.email, 'email', emailRe.test(form.elements.email.value.trim()) ? '' : 'Bitte gib eine gültige E-Mail-Adresse an.'),
  ].filter(Boolean);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const bad = checks();
    if (bad.length) {
      show('Bitte prüfe die markierten Felder.', 'error');
      bad[0].focus();
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Wird gesendet';
    show('Wird gesendet …', '');

    try {
      const response = await fetch('/send-mail.php', { method: 'POST', body: new FormData(form) });
      const result = await response.json();
      if (result.ok) {
        show('Danke, deine Anfrage wurde gesendet. Ich melde mich zeitnah.', 'success');
        form.reset();
        stamp();
      } else if (response.status === 429) {
        show('Zu viele Anfragen in kurzer Zeit. Bitte versuch es in ein paar Minuten erneut.', 'error');
      } else {
        show(`Anfrage konnte nicht gesendet werden. ${mailFallback}`, 'error');
      }
    } catch (err) {
      show(`Anfrage konnte nicht gesendet werden. ${mailFallback}`, 'error');
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Anfrage senden';
      status.focus();
    }
  });
})();
