<?php
/* House of Dogs - ricevitore dei webhook Revolut Business (Webhooks v2).
   URL da registrare in Revolut: https://www.thehouseofdog.it/webhook-revolut.php
   Eventi: TransactionCreated e TransactionStateChanged (gli altri ricevono 200 e vengono ignorati).
   Quando arriva il bonifico con il codice in causale, la prenotazione passa da sola a "Caparra ricevuta".

   Risposte: 405 se non è POST, 503 se manca il segreto di firma, 413 oltre 64 KB, 401 firma o timestamp
   non validi, 400 JSON non valido, 200 {"ok":true} se elaborato o ignorato, 500 su errore interno (Revolut ritenta). */

declare(strict_types=1);

require_once __DIR__ . '/_prenotazioni/bootstrap.php';

hodbook_json_mode(true);
hodbook_send_headers();
ignore_user_abort(true);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$raw = '';
if ($method === 'POST') {
    /* Si legge al massimo un byte oltre il limite: basta per riconoscere un corpo troppo grande. */
    $raw = (string)file_get_contents('php://input', false, null, 0, HODBOOK_WEBHOOK_MAX_BODY + 1);
}
$headers = [
    'Revolut-Request-Timestamp' => (string)($_SERVER['HTTP_REVOLUT_REQUEST_TIMESTAMP'] ?? ''),
    'Revolut-Signature'         => (string)($_SERVER['HTTP_REVOLUT_SIGNATURE'] ?? ''),
];

$cfg = hodbook_config();
$response = hodbook_webhook_handle($method, $headers, $raw, $cfg, (int)floor(microtime(true) * 1000));

if ($response['status'] === 405) {
    header('Allow: POST');
}
if ($response['status'] === 200) {
    try {
        hodbook_housekeeping(hodbook_db(), time());
    } catch (Throwable $e) {
        error_log('[hodbook] pulizia: ' . get_class($e) . ': ' . $e->getMessage());
    }
}
hodbook_json_response($response['status'], $response['body']);
