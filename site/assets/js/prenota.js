/* House of Dogs - prenotazione con bonifico (prenota.php e prenotazione.php).
   - Bottoni "Copia": copiano il valore esatto (IBAN senza spazi, importo come 550,00) e mostrano "Copiato".
   - Stato: ogni 20 secondi, finché la prenotazione è in attesa o il bonifico è in arrivo, chiede lo stato
     a prenotazione.php?...&format=json e ricarica la pagina quando cambia. Si ferma se la scheda è nascosta.
   - Modulo: porta il cursore sul primo campo da correggere ed evita il doppio invio.
   Tutto è un miglioramento progressivo: senza JavaScript le pagine funzionano e i valori si selezionano a mano.
   Niente cookie e niente dati salvati nel browser (l'informativa privacy lo garantisce). */
(function () {
  'use strict';

  var live = document.getElementById('copy-live');

  function announce(text) {
    if (!live) return;
    live.textContent = '';
    window.setTimeout(function () { live.textContent = text; }, 60);
  }

  function legacyCopy(text) {
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.top = '-1000px';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    area.setSelectionRange(0, text.length);
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(area);
    return ok;
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text).then(
        function () { return true; },
        function () { return legacyCopy(text); }
      );
    }
    return Promise.resolve(legacyCopy(text));
  }

  /* ---------- Bottoni "Copia" ---------- */
  Array.prototype.forEach.call(document.querySelectorAll('button[data-copy]'), function (btn) {
    var label = btn.textContent;
    var timer = null;
    btn.hidden = false;
    btn.addEventListener('click', function () {
      copyText(btn.getAttribute('data-copy') || '').then(function (ok) {
        btn.textContent = ok ? (btn.getAttribute('data-done') || 'Copiato') : 'Copia non riuscita';
        btn.classList.toggle('done', ok);
        announce(ok ? 'Copiato negli appunti' : 'Copia non riuscita: seleziona il testo a mano');
        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
          btn.textContent = label;
          btn.classList.remove('done');
        }, 2500);
      });
    });
  });

  Array.prototype.forEach.call(document.querySelectorAll('[data-js-only]'), function (el) { el.hidden = false; });

  /* ---------- Modulo di prenotazione ---------- */
  var form = document.getElementById('prenota-form');
  if (form) {
    var submit = document.getElementById('prenota-submit');
    var submitLabel = submit ? submit.textContent : '';
    var invalid = form.querySelector('[aria-invalid="true"]');
    if (invalid) invalid.focus();

    Array.prototype.forEach.call(form.querySelectorAll('input, textarea'), function (el) {
      el.addEventListener('input', function () { el.removeAttribute('aria-invalid'); });
      el.addEventListener('change', function () { el.removeAttribute('aria-invalid'); });
    });

    /* Codice fiscale incollato con spazi (es. "RSS MRA 80A01 H501U"): senza questo il limite di 16 caratteri
       lo taglierebbe. Spazi tolti e maiuscole; il server normalizza comunque. */
    var cf = document.getElementById('f-codice_fiscale');
    if (cf) {
      cf.addEventListener('paste', function (ev) {
        var data = ev.clipboardData || window.clipboardData;
        var text = data ? data.getData('text') : '';
        if (!text) return;
        ev.preventDefault();
        var start = typeof cf.selectionStart === 'number' ? cf.selectionStart : cf.value.length;
        var end = typeof cf.selectionEnd === 'number' ? cf.selectionEnd : cf.value.length;
        cf.value = (cf.value.slice(0, start) + text + cf.value.slice(end)).replace(/\s+/g, '').toUpperCase().slice(0, 16);
        cf.removeAttribute('aria-invalid');
      });
    }

    form.addEventListener('submit', function () {
      if (!submit) return;
      window.setTimeout(function () {
        submit.disabled = true;
        submit.textContent = 'Invio in corso…';
      }, 0);
    });

    /* Tornando indietro con il browser il bottone deve essere di nuovo attivo. */
    window.addEventListener('pageshow', function () {
      if (submit && submit.disabled) {
        submit.disabled = false;
        submit.textContent = submitLabel;
      }
    });
  }

  /* ---------- Aggiornamento automatico dello stato ---------- */
  var root = document.querySelector('[data-status-url]');
  if (!root || !window.fetch) return;
  var current = root.getAttribute('data-status');
  if (current !== 'pending' && current !== 'incoming') return;

  var url = root.getAttribute('data-status-url');
  var busy = false;
  var stopped = false;
  var interval = null;

  function stop() {
    stopped = true;
    if (interval) window.clearInterval(interval);
  }

  function check() {
    if (stopped || busy || document.hidden) return;
    busy = true;
    fetch(url, { cache: 'no-store', credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (res) {
        if (res.status === 404) { stop(); return null; }
        return res.ok ? res.json() : null;
      })
      .then(function (data) {
        if (data && typeof data.status === 'string' && data.status !== current) {
          stop();
          window.location.reload();
        }
      })
      .catch(function () { /* rete assente: si riprova al prossimo giro */ })
      .then(function () { busy = false; });
  }

  interval = window.setInterval(check, 20000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) check();
  });
})();
