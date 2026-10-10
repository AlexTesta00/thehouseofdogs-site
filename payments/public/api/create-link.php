<?php
/* API riservata al commerciante: crea la richiesta interna e restituisce il link cliente.
   Non chiama Mollie: il vero payment link nasce quando il cliente completa i suoi dati. */

declare(strict_types=1);

require __DIR__ . '/../app-path.php';

hodpay_session_start();
hodpay_require_admin_api();

if (!hodpay_rate_limit('create:' . hodpay_client_ip(), 60, 3600)) {
    hodpay_json_error(429, 'rate_limited', 'Hai creato molti link in poco tempo. Attendi qualche minuto.');
}

$cfg = hodpay_config();
$mode = hodpay_mode($cfg);
if ($mode === 'invalid') {
    hodpay_json_error(500, 'config', 'Chiave Mollie non valida: controlla config/config.php.');
}

$in = hodpay_read_json_body();
$errors = [];
[$name, $e] = hodpay_validate_customer_name($in['customer_name'] ?? '');
if ($e) { $errors['customer_name'] = $e; }
[$cents, $e] = hodpay_validate_amount($in['amount'] ?? '', $cfg);
if ($e) { $errors['amount'] = $e; }
if ($errors !== []) {
    hodpay_json_error(422, 'validation', 'Controlla i campi evidenziati.', ['fields' => $errors]);
}

[$token, $row] = hodpay_request_create($name, $cents, $cfg);

hodpay_json(201, [
    'ok'   => true,
    'data' => [
        'customer_name'    => $row['customer_name'],
        'amount_cents'     => (int)$row['amount_cents'],
        'amount_formatted' => hodpay_format_amount((int)$row['amount_cents'], $row['currency']),
        'link'             => hodpay_customer_link($token),
        'expires_at'       => date(DATE_ATOM, (int)$row['expires_at']),
        'expires_human'    => date('d/m/Y \a\l\l\e H:i', (int)$row['expires_at']),
        'mode'             => $mode,
    ],
]);
