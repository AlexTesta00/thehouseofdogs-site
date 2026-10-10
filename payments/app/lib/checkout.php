<?php
/* Percorso cliente: dalla richiesta salvata al vero link Mollie.
   Il cliente non può cambiare importo, descrizione o metodi: arrivano tutti dal server. */

declare(strict_types=1);

function hodpay_mollie_api(array $cfg): ?HodpayMollieApi
{
    $mode = hodpay_mode($cfg);
    if ($mode !== 'test' && $mode !== 'live') {
        return null;
    }
    return new HodpayMollieClient($cfg['mollie_api_key']);
}

/** Costruisce il payload per POST /v2/payment-links. Puro: facile da testare. */
function hodpay_build_payment_link_payload(array $req, array $billing, array $allowedMethods, array $cfg, int $now): array
{
    $cents = (int)$req['amount_cents'];
    $currency = (string)$req['currency'];
    $value = hodpay_mollie_value($cents);
    $description = hodpay_cut($req['description'] . ' - ' . $req['customer_name'], 255);

    $line = [
        'type'        => 'physical',
        'description' => hodpay_cut((string)$req['description'], 255),
        'quantity'    => 1,
        'unitPrice'   => ['currency' => $currency, 'value' => $value],
        'totalAmount' => ['currency' => $currency, 'value' => $value],
    ];
    if (is_string($cfg['vat_rate']) && preg_match('/^\d{1,2}(\.\d{1,2})?$/', $cfg['vat_rate'])) {
        /* IVA inclusa nel prezzo: imposta = totale - totale / (1 + aliquota). Calcolo in centesimi interi. */
        $rateBp = (int)round(((float)$cfg['vat_rate']) * 100); /* punti base: 22.00 -> 2200 */
        $net = (int)round($cents * 10000 / (10000 + $rateBp));
        $vat = $cents - $net;
        $line['vatRate'] = number_format((float)$cfg['vat_rate'], 2, '.', '');
        $line['vatAmount'] = ['currency' => $currency, 'value' => hodpay_mollie_value($vat)];
    }

    $expiresAt = max((int)$req['expires_at'], $now + 3600);
    $payload = [
        'description'    => $description,
        'amount'         => ['currency' => $currency, 'value' => $value],
        'lines'          => [$line],
        'billingAddress' => [
            'givenName'       => $billing['given_name'],
            'familyName'      => $billing['family_name'],
            'email'           => $billing['email'],
            'streetAndNumber' => $billing['street'],
            'postalCode'      => $billing['postal_code'],
            'city'            => $billing['city'],
            'country'         => $billing['country'],
        ],
        'reusable'       => false,
        'expiresAt'      => gmdate('Y-m-d\TH:i:s+00:00', $expiresAt),
    ];
    if ($allowedMethods !== []) {
        $payload['allowedMethods'] = array_values($allowedMethods);
    }
    $redirect = hodpay_redirect_url($cfg);
    if ($redirect !== null) {
        $payload['redirectUrl'] = $redirect;
    }
    if (is_string($cfg['webhook_url']) && str_starts_with($cfg['webhook_url'], 'https://')) {
        $payload['webhookUrl'] = $cfg['webhook_url'];
    }
    return $payload;
}

function hodpay_redirect_url(array $cfg): ?string
{
    $r = $cfg['redirect_url'];
    if ($r === 'auto') {
        return hodpay_base_url() . '/grazie.php';
    }
    if (is_string($r) && preg_match('#^https?://#', $r)) {
        return $r;
    }
    return null;
}

/** Dalla lista metodi Mollie estrae gli id attivi; Klarna è presente solo se Mollie lo conferma. */
function hodpay_pick_allowed_methods(array $methods): array
{
    $ids = [];
    foreach ($methods as $m) {
        if (!is_array($m) || !isset($m['id']) || !is_string($m['id'])) {
            continue;
        }
        $status = $m['status'] ?? 'activated';
        if ($status !== 'activated' && $status !== null) {
            continue;
        }
        if (!preg_match('/^[a-z0-9]{2,40}$/', $m['id'])) {
            continue;
        }
        $ids[] = $m['id'];
    }
    return array_values(array_unique($ids));
}

/**
 * Esegue il checkout. Restituisce un array con 'status':
 *  - redirect: ['url' => link Mollie, 'klarna' => bool]
 *  - demo:     nessuna chiave configurata, nessun pagamento creato
 *  - error:    ['code' => ..., 'message' => ...]
 */
function hodpay_checkout(array $req, array $billing, array $cfg, ?HodpayMollieApi $api, ?int $now = null): array
{
    $now ??= time();
    $id = (int)$req['id'];

    /* Tentativi ripetuti: il link Mollie esiste già, non se ne crea un altro. */
    if ($req['status'] === 'created' && !empty($req['mollie_link_url'])) {
        return ['status' => 'redirect', 'url' => (string)$req['mollie_link_url'], 'klarna' => (bool)$req['klarna_available'], 'reused' => true];
    }
    if (hodpay_request_is_expired($req, $now)) {
        return ['status' => 'error', 'code' => 'expired', 'message' => 'Questo link è scaduto. Chiedi al venditore un nuovo link di pagamento.'];
    }

    $billingJson = json_encode($billing, JSON_UNESCAPED_UNICODE);
    $mode = hodpay_mode($cfg);

    if ($mode === 'demo') {
        hodpay_request_update($id, ['status' => 'demo', 'billing_json' => $billingJson, 'completed_at' => $now]);
        return ['status' => 'demo'];
    }
    if ($mode === 'invalid' || $api === null) {
        return ['status' => 'error', 'code' => 'config', 'message' => 'Il sistema di pagamento non è configurato correttamente. Contatta il venditore.'];
    }

    /* 1. Disponibilità dei metodi (incluso Klarna) per importo e paese. */
    try {
        $methods = $api->listMethods((int)$req['amount_cents'], (string)$req['currency'], $billing['country']);
    } catch (HodpayMollieException $e) {
        hodpay_request_update($id, ['attempts' => (int)$req['attempts'] + 1, 'last_error' => 'methods: ' . $e->getMessage()]);
        return ['status' => 'error', 'code' => 'mollie', 'message' => $e->getMessage()];
    }
    $allowed = hodpay_pick_allowed_methods($methods);
    $klarna = in_array('klarna', $allowed, true);
    if ($allowed === []) {
        hodpay_request_update($id, ['attempts' => (int)$req['attempts'] + 1, 'last_error' => 'nessun metodo disponibile']);
        return ['status' => 'error', 'code' => 'no_methods', 'message' => 'Nessun metodo di pagamento è disponibile per questo importo e paese. Contatta il venditore.'];
    }

    /* 2. Creazione del link con chiave di idempotenza: un retry non crea duplicati. */
    $payload = hodpay_build_payment_link_payload($req, $billing, $allowed, $cfg, $now);
    try {
        $link = $api->createPaymentLink($payload, (string)$req['idempotency_key']);
    } catch (HodpayMollieException $e) {
        $update = ['attempts' => (int)$req['attempts'] + 1, 'last_error' => 'create: ' . $e->getMessage(), 'billing_json' => $billingJson];
        /* Errore definitivo lato dati (es. 422): la chiave è "bruciata" per quel payload.
           Se il cliente corregge i dati, il payload cambia e Mollie risponderebbe 400.
           Si ruota quindi la chiave. Su 409/429/5xx/rete la chiave resta: il retry è sicuro. */
        if ($e->status >= 400 && $e->status < 500 && !in_array($e->status, [409, 429], true)) {
            $update['idempotency_key'] = hodpay_uuid4();
        }
        hodpay_request_update($id, $update);
        return ['status' => 'error', 'code' => $e->status === 409 ? 'busy' : 'mollie', 'message' => $e->getMessage()];
    }

    $url = $link['_links']['paymentLink']['href'] ?? null;
    if (!is_string($url) || !str_starts_with($url, 'https://')) {
        hodpay_request_update($id, ['attempts' => (int)$req['attempts'] + 1, 'last_error' => 'risposta senza paymentLink.href']);
        return ['status' => 'error', 'code' => 'mollie', 'message' => 'Risposta inattesa da Mollie. Riprova tra poco.'];
    }

    hodpay_request_update($id, [
        'status'           => 'created',
        'billing_json'     => $billingJson,
        'klarna_available' => $klarna ? 1 : 0,
        'allowed_methods'  => implode(',', $allowed),
        'mollie_link_id'   => isset($link['id']) && is_string($link['id']) ? $link['id'] : null,
        'mollie_link_url'  => $url,
        'attempts'         => (int)$req['attempts'] + 1,
        'last_error'       => null,
        'completed_at'     => $now,
    ]);
    return ['status' => 'redirect', 'url' => $url, 'klarna' => $klarna, 'reused' => false];
}
