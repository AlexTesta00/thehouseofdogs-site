/* Pagina cliente: piccoli aiuti all'inserimento e stato di invio. Il form funziona anche senza JS. */
(function () {
  'use strict';

  var form = document.getElementById('pay-form');
  if (!form) return;
  var btn = document.getElementById('pay-submit');
  var cap = document.getElementById('postal_code');
  var country = document.getElementById('country');

  function updateCapHint() {
    if (!cap || !country) return;
    var numeric = ['IT', 'DE', 'ES', 'FI', 'FR', 'AT', 'BE'].indexOf(country.value) !== -1;
    cap.setAttribute('inputmode', numeric ? 'numeric' : 'text');
  }
  if (country) {
    country.addEventListener('change', updateCapHint);
    updateCapHint();
  }

  form.addEventListener('submit', function () {
    var required = form.querySelectorAll('[required]');
    for (var i = 0; i < required.length; i++) {
      if (!required[i].value.trim()) {
        required[i].setAttribute('aria-invalid', 'true');
        required[i].focus();
        return;
      }
    }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner" aria-hidden="true"></span>Preparazione del pagamento…';
  });

  Array.prototype.forEach.call(form.querySelectorAll('input'), function (el) {
    el.addEventListener('input', function () { el.removeAttribute('aria-invalid'); });
  });
})();
