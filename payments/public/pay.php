<?php
/* Pagina cliente: si apre con il token, raccoglie i dati di fatturazione richiesti da Klarna
   e porta al link di pagamento Mollie. Importo, descrizione e metodi arrivano dal server. */

declare(strict_types=1);

require __DIR__ . '/app-path.php';

hodpay_session_start();
$cfg = hodpay_config();
hodpay_send_security_headers(hodpay_nonce());

function hodpay_pay_message(string $title, string $kind, string $text, int $status = 200): never
{
    http_response_code($status);
    hodpay_render_page($title, '<div class="rounded-2xl bg-white p-6 sm:p-8 shadow-sm ring-1 ring-black/5">'
        . '<h1 class="text-xl font-semibold tracking-tight">' . hodpay_e($title) . '</h1><div class="h-4"></div>'
        . hodpay_alert($kind, hodpay_e($text)) . '</div>');
}

$tokenRaw = $_GET['t'] ?? ($_POST['t'] ?? '');
$token = is_string($tokenRaw) ? strtolower(trim($tokenRaw)) : '';
if (!hodpay_valid_token_format($token)) {
    hodpay_pay_message('Link non valido', 'error', 'Questo indirizzo non è un link di pagamento valido. Controlla di averlo copiato per intero.', 404);
}
if (!hodpay_rate_limit('pay-view:' . hodpay_client_ip(), 120, 3600)) {
    hodpay_pay_message('Troppe richieste', 'warn', 'Hai aperto questa pagina troppe volte. Riprova tra qualche minuto.', 429);
}

$req = hodpay_request_by_token($token);
if ($req === null) {
    hodpay_pay_message('Link non trovato', 'error', 'Questo link di pagamento non esiste o è stato rimosso. Chiedi al venditore un nuovo link.', 404);
}
if ($req['status'] === 'created' && !empty($req['mollie_link_url'])) {
    hodpay_redirect((string)$req['mollie_link_url']);
}
if (hodpay_request_is_expired($req)) {
    hodpay_pay_message('Link scaduto', 'warn', 'Questo link è scaduto. Chiedi al venditore un nuovo link di pagamento.', 410);
}
if ($req['status'] === 'demo') {
    hodpay_pay_message('Modalità demo', 'info', 'Dati ricevuti in modalità demo: nessun pagamento è stato creato perché il venditore non ha ancora collegato Mollie.');
}

$errors = [];
$formError = null;
$values = [
    'given_name'  => '',
    'family_name' => '',
    'email'       => '',
    'street'      => '',
    'postal_code' => '',
    'city'        => '',
    'country'     => $cfg['default_country'],
];
/* Precompila nome e cognome dal nome scritto dal venditore. */
$parts = preg_split('/\s+/', trim((string)$req['customer_name'])) ?: [];
if (count($parts) >= 2) {
    $values['given_name'] = array_shift($parts);
    $values['family_name'] = implode(' ', $parts);
} else {
    $values['given_name'] = $parts[0] ?? '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($values as $k => $_) {
        $values[$k] = isset($_POST[$k]) && is_string($_POST[$k]) ? hodpay_clean_text($_POST[$k], 254) : '';
    }
    if (!hodpay_same_origin_check() || !hodpay_csrf_check(isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : null)) {
        $formError = 'La sessione è scaduta. Ricarica la pagina e riprova.';
    } elseif (!hodpay_rate_limit('checkout:ip:' . hodpay_client_ip(), 20, 3600)
           || !hodpay_rate_limit('checkout:tok:' . $req['token_hash'], 8, 3600)) {
        $formError = 'Troppi tentativi. Attendi qualche minuto e riprova.';
    } else {
        [$billing, $errors] = hodpay_validate_billing($values, $cfg);
        if ($errors === []) {
            $result = hodpay_checkout($req, $billing, $cfg, hodpay_mollie_api($cfg));
            if ($result['status'] === 'redirect') {
                hodpay_redirect($result['url']);
            }
            if ($result['status'] === 'demo') {
                hodpay_pay_message('Modalità demo', 'info', 'Dati ricevuti in modalità demo: nessun pagamento è stato creato perché il venditore non ha ancora collegato Mollie.');
            }
            $formError = $result['message'] ?? 'Errore inatteso. Riprova.';
        }
    }
}

$csrf = hodpay_e(hodpay_csrf_token());
$tokenEsc = hodpay_e($token);
$amount = hodpay_e(hodpay_format_amount((int)$req['amount_cents'], (string)$req['currency']));
$desc = hodpay_e((string)$req['description']);
$expires = hodpay_e(date('d/m/Y \a\l\l\e H:i', (int)$req['expires_at']));
$merchant = hodpay_e($cfg['merchant_name']);
$alert = $formError ? hodpay_alert('error', hodpay_e($formError)) . '<div class="h-4"></div>' : '';
$modeNote = hodpay_mode($cfg) === 'test' ? hodpay_alert('warn', 'Ambiente di prova: nessun addebito reale.') . '<div class="h-4"></div>' : '';

$countryNames = [
    'IT' => 'Italia', 'AT' => 'Austria', 'BE' => 'Belgio', 'DE' => 'Germania', 'ES' => 'Spagna',
    'FI' => 'Finlandia', 'FR' => 'Francia', 'IE' => 'Irlanda', 'NL' => 'Paesi Bassi', 'PT' => 'Portogallo',
];
$countryOptions = '';
foreach ($cfg['allowed_countries'] as $cc) {
    $sel = $cc === $values['country'] ? ' selected' : '';
    $countryOptions .= '<option value="' . hodpay_e($cc) . '"' . $sel . '>' . hodpay_e($countryNames[$cc] ?? $cc) . '</option>';
}

function hodpay_input(string $name, string $label, array $values, array $errors, array $attrs = []): string
{
    $extra = '';
    foreach ($attrs as $k => $v) {
        $extra .= ' ' . $k . '="' . hodpay_e($v) . '"';
    }
    $invalid = isset($errors[$name]) ? ' aria-invalid="true"' : '';
    return '<div><label for="' . $name . '" class="block text-sm font-medium">' . hodpay_e($label) . '</label>'
        . '<input id="' . $name . '" name="' . $name . '" class="field mt-1.5" value="' . hodpay_e($values[$name]) . '" required' . $invalid . $extra . '>'
        . hodpay_field_error($errors, $name) . '</div>';
}

$fGiven  = hodpay_input('given_name', 'Nome', $values, $errors, ['type' => 'text', 'autocomplete' => 'given-name', 'maxlength' => '100']);
$fFamily = hodpay_input('family_name', 'Cognome', $values, $errors, ['type' => 'text', 'autocomplete' => 'family-name', 'maxlength' => '100']);
$fEmail  = hodpay_input('email', 'Email', $values, $errors, ['type' => 'email', 'autocomplete' => 'email', 'maxlength' => '254', 'inputmode' => 'email']);
$fStreet = hodpay_input('street', 'Via e numero civico', $values, $errors, ['type' => 'text', 'autocomplete' => 'street-address', 'maxlength' => '200', 'placeholder' => 'Es. Via Roma 12']);
$fCap    = hodpay_input('postal_code', 'CAP', $values, $errors, ['type' => 'text', 'autocomplete' => 'postal-code', 'maxlength' => '16', 'inputmode' => 'numeric']);
$fCity   = hodpay_input('city', 'Città', $values, $errors, ['type' => 'text', 'autocomplete' => 'address-level2', 'maxlength' => '100']);
$countryErr = hodpay_field_error($errors, 'country');

hodpay_render_page('Completa il pagamento', <<<HTML
<div class="rounded-2xl bg-white p-6 sm:p-8 shadow-sm ring-1 ring-black/5">
  <p class="text-xs uppercase tracking-wide text-slate-500">Richiesta di pagamento da {$merchant}</p>
  <div class="mt-3 flex items-end justify-between gap-4">
    <div>
      <p class="text-sm text-slate-600">{$desc}</p>
      <p class="mt-1 text-3xl font-semibold tracking-tight">{$amount}</p>
    </div>
  </div>
  <p class="mt-2 text-xs text-slate-500">Link valido fino al {$expires}.</p>
  <div class="h-6"></div>
  {$modeNote}{$alert}
  <h1 class="text-lg font-semibold tracking-tight">I tuoi dati</h1>
  <p class="mt-1 text-sm text-slate-600">Servono per emettere il pagamento e per proporti anche Klarna, quando disponibile.</p>
  <form id="pay-form" method="post" action="pay.php?t={$tokenEsc}" class="mt-5 space-y-4" novalidate>
    <input type="hidden" name="csrf" value="{$csrf}">
    <input type="hidden" name="t" value="{$tokenEsc}">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">{$fGiven}{$fFamily}</div>
    {$fEmail}
    {$fStreet}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div class="sm:col-span-1">{$fCap}</div>
      <div class="sm:col-span-2">{$fCity}</div>
    </div>
    <div>
      <label for="country" class="block text-sm font-medium">Paese</label>
      <select id="country" name="country" class="field mt-1.5">{$countryOptions}</select>
      {$countryErr}
    </div>
    <div class="h-1"></div>
    <button type="submit" id="pay-submit" class="btn-coral">Procedi al pagamento</button>
    <p class="text-center text-xs text-slate-500">Verrai indirizzato alla pagina sicura di Mollie per scegliere il metodo di pagamento. Klarna è disponibile solo se confermato da Mollie per importo e paese; l'approvazione finale spetta a Klarna.</p>
  </form>
</div>
HTML, ['scripts' => ['assets/pay.js'], 'badge' => hodpay_mode($cfg) !== 'live']);
