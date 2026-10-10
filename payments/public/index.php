<?php
/* Pannello del commerciante: accesso con password e generatore di link cliente. */

declare(strict_types=1);

require __DIR__ . '/app-path.php';

hodpay_session_start();
$cfg = hodpay_config();
$mode = hodpay_mode($cfg);
hodpay_send_security_headers(hodpay_nonce());
hodpay_housekeeping();

if (hodpay_login_required() && !hodpay_admin_configured()) {
    hodpay_render_page('Configurazione richiesta', hodpay_alert('warn',
        '<strong>Password amministratore non impostata.</strong><br>Genera l\'hash con <code>php tools/make-password-hash.php</code> e inseriscilo in <code>config/config.php</code> alla voce <code>admin_password_hash</code>. Vedi INSTALLAZIONE.md.'
    ));
}

$loginError = null;
if (hodpay_login_required() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hodpay_same_origin_check() || !hodpay_csrf_check(isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : null)) {
        $loginError = 'Sessione non valida. Ricarica la pagina e riprova.';
    } elseif (!hodpay_rate_limit('login:' . hodpay_client_ip(), 8, 900)) {
        $loginError = 'Troppi tentativi. Attendi 15 minuti prima di riprovare.';
    } elseif (!hodpay_login(isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '')) {
        usleep(random_int(200000, 600000));
        $loginError = 'Password non corretta.';
    } else {
        hodpay_redirect(hodpay_self_url());
    }
}

$csrf = hodpay_e(hodpay_csrf_token());

if (!hodpay_is_admin()) {
    $err = $loginError ? hodpay_alert('error', hodpay_e($loginError)) . '<div class="h-4"></div>' : '';
    hodpay_render_page('Accesso', <<<HTML
<div class="rounded-2xl bg-white p-6 sm:p-8 shadow-sm ring-1 ring-black/5">
  <h1 class="text-2xl font-semibold tracking-tight">Link di pagamento</h1>
  <p class="mt-1 text-sm text-slate-600">Area riservata al venditore.</p>
  <div class="h-6"></div>
  {$err}
  <form method="post" action="" autocomplete="off" novalidate>
    <input type="hidden" name="csrf" value="{$csrf}">
    <label for="password" class="block text-sm font-medium">Password</label>
    <input id="password" name="password" type="password" class="field mt-1.5" required autocomplete="current-password" autofocus>
    <div class="h-5"></div>
    <button type="submit" class="btn-coral">Entra</button>
  </form>
</div>
HTML);
}

/* Area generatore */
$minFmt = hodpay_e(hodpay_format_amount((int)$cfg['min_amount_cents'], $cfg['currency']));
$maxFmt = hodpay_e(hodpay_format_amount((int)$cfg['max_amount_cents'], $cfg['currency']));
$desc = hodpay_e($cfg['purchase_description']);
$hours = (int)$cfg['customer_link_hours'];
$dashboard = hodpay_e($cfg['mollie_dashboard_url']);
$logoutForm = hodpay_login_required() ? <<<HTML
    <form method="post" action="logout.php" class="shrink-0">
      <input type="hidden" name="csrf" value="{$csrf}">
      <button type="submit" class="text-sm text-slate-500 hover:text-ink underline-offset-2 hover:underline">Esci</button>
    </form>
HTML : '';
$modeNote = match ($mode) {
    'demo'    => hodpay_alert('warn', '<strong>Modalità demo.</strong> Nessuna chiave Mollie configurata: puoi provare il percorso, ma non verrà creato alcun pagamento reale né simulato.'),
    'test'    => hodpay_alert('info', '<strong>Modalità test.</strong> I link creano pagamenti di prova su Mollie, senza addebiti reali.'),
    'invalid' => hodpay_alert('error', '<strong>Chiave Mollie non valida.</strong> Deve iniziare con <code>test_</code> o <code>live_</code>. Controlla <code>config/config.php</code>.'),
    default   => '',
};

hodpay_render_page('Crea link di pagamento', <<<HTML
<div id="app" class="rounded-2xl bg-white p-6 sm:p-8 shadow-sm ring-1 ring-black/5" data-csrf="{$csrf}" data-endpoint="api/create-link.php">
  <div class="flex items-start justify-between gap-4">
    <div>
      <h1 class="text-2xl font-semibold tracking-tight">Crea link di pagamento</h1>
      <p class="mt-1 text-sm text-slate-600">Inserisci nome e importo. Il cliente completerà i suoi dati dal link.</p>
    </div>
{$logoutForm}  </div>
  <div class="h-5"></div>
  {$modeNote}

  <form id="create-form" class="mt-5 space-y-5" novalidate>
    <div>
      <label for="customer_name" class="block text-sm font-medium">Nome cliente</label>
      <input id="customer_name" name="customer_name" type="text" class="field mt-1.5" maxlength="100" required autocomplete="off" placeholder="Es. Maria Rossi">
      <p class="mt-1 text-xs text-rose-700 hidden" data-error-for="customer_name"></p>
    </div>
    <div>
      <label for="amount" class="block text-sm font-medium">Importo in euro</label>
      <div class="relative mt-1.5">
        <input id="amount" name="amount" type="text" inputmode="decimal" class="field pr-10" maxlength="12" required autocomplete="off" placeholder="Es. 350 oppure 350,00">
        <span class="pointer-events-none absolute inset-y-0 right-4 flex items-center text-slate-400">€</span>
      </div>
      <p class="mt-1 text-xs text-slate-500">Da {$minFmt} a {$maxFmt}.</p>
      <p class="mt-1 text-xs text-rose-700 hidden" data-error-for="amount"></p>
    </div>
    <div id="form-error" class="hidden"></div>
    <button type="submit" id="submit-btn" class="btn-coral">Crea link di pagamento</button>
  </form>

  <section id="result" class="hidden mt-2" aria-live="polite">
    <div class="rounded-xl bg-emerald-50 ring-1 ring-emerald-200 px-4 py-3 text-sm text-emerald-800">Link creato. Copialo e invialo al cliente.</div>
    <dl class="mt-5 grid grid-cols-2 gap-3 text-sm">
      <div class="rounded-xl bg-sand px-4 py-3"><dt class="text-xs uppercase tracking-wide text-slate-500">Cliente</dt><dd id="r-name" class="mt-0.5 font-medium break-words"></dd></div>
      <div class="rounded-xl bg-sand px-4 py-3"><dt class="text-xs uppercase tracking-wide text-slate-500">Importo</dt><dd id="r-amount" class="mt-0.5 font-medium"></dd></div>
    </dl>
    <div class="mt-4">
      <label for="r-link" class="block text-sm font-medium">Link da inviare</label>
      <input id="r-link" type="text" class="field mt-1.5 font-mono text-xs" readonly>
      <p id="r-expiry" class="mt-1 text-xs text-slate-500"></p>
    </div>
    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
      <button type="button" id="copy-btn" class="btn-coral">Copia link</button>
      <button type="button" id="again-btn" class="btn-ghost">Crea un altro link</button>
    </div>
    <p id="copy-feedback" class="mt-2 text-center text-sm text-emerald-700 hidden" role="status">Link copiato negli appunti.</p>
    <p class="mt-4 text-xs text-slate-500">Il cliente compila i propri dati e viene indirizzato alla pagina di pagamento Mollie. Klarna compare tra i metodi solo se Mollie lo conferma per importo e paese al momento del pagamento.</p>
  </section>

  <div class="mt-8 border-t border-slate-100 pt-5 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
    <span>Descrizione acquisto: <span class="font-medium text-ink">{$desc}</span> · link valido {$hours} ore</span>
    <a href="{$dashboard}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-medium text-coral-dark hover:underline">Apri Mollie <span aria-hidden="true">↗</span></a>
  </div>
</div>
HTML, ['scripts' => ['assets/app.js']]);
