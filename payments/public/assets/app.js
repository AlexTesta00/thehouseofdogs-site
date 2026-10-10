/* Pannello commerciante: creazione link, copia negli appunti, reset. JavaScript puro. */
(function () {
  'use strict';

  var app = document.getElementById('app');
  if (!app) return;

  var endpoint = app.getAttribute('data-endpoint');
  var csrf = app.getAttribute('data-csrf');
  var form = document.getElementById('create-form');
  var submitBtn = document.getElementById('submit-btn');
  var formError = document.getElementById('form-error');
  var result = document.getElementById('result');
  var nameInput = document.getElementById('customer_name');
  var amountInput = document.getElementById('amount');
  var copyBtn = document.getElementById('copy-btn');
  var againBtn = document.getElementById('again-btn');
  var copyFeedback = document.getElementById('copy-feedback');
  var linkInput = document.getElementById('r-link');
  var submitLabel = submitBtn.textContent;

  function setFieldError(name, message) {
    var el = form.querySelector('[data-error-for="' + name + '"]');
    var input = form.elements[name];
    if (!el) return;
    if (message) {
      el.textContent = message;
      el.classList.remove('hidden');
      if (input) input.setAttribute('aria-invalid', 'true');
    } else {
      el.textContent = '';
      el.classList.add('hidden');
      if (input) input.removeAttribute('aria-invalid');
    }
  }

  function showFormError(message) {
    if (!message) {
      formError.classList.add('hidden');
      formError.innerHTML = '';
      return;
    }
    formError.className = 'rounded-xl px-4 py-3 text-sm ring-1 bg-rose-50 text-rose-800 ring-rose-200';
    formError.textContent = message;
    formError.setAttribute('role', 'alert');
  }

  function setLoading(on) {
    submitBtn.disabled = on;
    submitBtn.innerHTML = on ? '<span class="spinner" aria-hidden="true"></span>Creazione in corso…' : submitLabel;
    nameInput.disabled = on;
    amountInput.disabled = on;
  }

  function validateLocal() {
    var ok = true;
    var name = nameInput.value.trim();
    var amount = amountInput.value.trim();
    setFieldError('customer_name', null);
    setFieldError('amount', null);
    if (name.length < 2) {
      setFieldError('customer_name', 'Inserisci il nome del cliente.');
      ok = false;
    }
    if (!/^\s*(€\s*)?\d{1,7}([.,]\d{1,2})?\s*(€)?\s*$/.test(amount)) {
      setFieldError('amount', 'Importo non valido. Usa il formato 120 oppure 120,50.');
      ok = false;
    }
    return ok;
  }

  function showResult(data) {
    document.getElementById('r-name').textContent = data.customer_name;
    document.getElementById('r-amount').textContent = data.amount_formatted;
    linkInput.value = data.link;
    document.getElementById('r-expiry').textContent = 'Valido fino al ' + data.expires_human + '.';
    form.classList.add('hidden');
    result.classList.remove('hidden');
    copyFeedback.classList.add('hidden');
    linkInput.focus();
    linkInput.select();
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    showFormError(null);
    if (!validateLocal()) return;
    setLoading(true);

    var payload = JSON.stringify({ customer_name: nameInput.value.trim(), amount: amountInput.value.trim() });
    fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
      body: payload
    }).then(function (res) {
      return res.json().then(function (json) { return { status: res.status, json: json }; });
    }).then(function (r) {
      setLoading(false);
      if (r.json && r.json.ok) {
        showResult(r.json.data);
        return;
      }
      var err = (r.json && r.json.error) || {};
      if (err.fields) {
        Object.keys(err.fields).forEach(function (k) { setFieldError(k, err.fields[k]); });
      }
      if (r.status === 401) {
        showFormError('Sessione scaduta. Ricarica la pagina ed effettua di nuovo l\'accesso.');
      } else {
        showFormError(err.message || 'Impossibile creare il link. Riprova.');
      }
    }).catch(function () {
      setLoading(false);
      showFormError('Connessione non riuscita. Controlla la rete e riprova.');
    });
  });

  function flashCopied(ok) {
    copyFeedback.textContent = ok ? 'Link copiato negli appunti.' : 'Copia automatica non disponibile: seleziona il link e copialo manualmente.';
    copyFeedback.className = 'mt-2 text-center text-sm ' + (ok ? 'text-emerald-700' : 'text-amber-700');
    copyFeedback.classList.remove('hidden');
    if (ok) {
      copyBtn.textContent = 'Copiato ✓';
      setTimeout(function () { copyBtn.textContent = 'Copia link'; }, 1800);
    }
  }

  copyBtn.addEventListener('click', function () {
    var text = linkInput.value;
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { flashCopied(true); }, function () { fallbackCopy(text); });
    } else {
      fallbackCopy(text);
    }
  });

  function fallbackCopy(text) {
    try {
      linkInput.focus();
      linkInput.select();
      linkInput.setSelectionRange(0, text.length);
      var ok = document.execCommand && document.execCommand('copy');
      flashCopied(!!ok);
    } catch (e) {
      flashCopied(false);
    }
  }

  linkInput.addEventListener('click', function () { linkInput.select(); });

  againBtn.addEventListener('click', function () {
    form.reset();
    setFieldError('customer_name', null);
    setFieldError('amount', null);
    showFormError(null);
    result.classList.add('hidden');
    form.classList.remove('hidden');
    nameInput.focus();
  });
})();
