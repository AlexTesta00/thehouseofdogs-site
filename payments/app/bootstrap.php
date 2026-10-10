<?php
/* House of Dogs Payments - bootstrap condiviso.
   Viene incluso da public/app-path.php. Imposta i percorsi privati, la gestione
   degli errori e carica le librerie. Nessun output qui. */

declare(strict_types=1);

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('Serve PHP 8.2 o successivo.');
}

if (!defined('HODPAY_APP_DIR')) {
    define('HODPAY_APP_DIR', __DIR__);
}
if (!defined('HODPAY_PRIVATE_DIR')) {
    define('HODPAY_PRIVATE_DIR', dirname(__DIR__));
}
if (!defined('HODPAY_CONFIG_DIR')) {
    define('HODPAY_CONFIG_DIR', HODPAY_PRIVATE_DIR . '/config');
}
if (!defined('HODPAY_DATA_DIR')) {
    define('HODPAY_DATA_DIR', HODPAY_PRIVATE_DIR . '/data');
}
define('HODPAY_VERSION', '1.0.0');

/* Errori: mai a schermo, sempre nel log privato. */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', HODPAY_DATA_DIR . '/php-errors.log');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Rome');

foreach (['config', 'db', 'security', 'http', 'auth', 'money', 'validation', 'mollie', 'requests', 'checkout', 'view'] as $lib) {
    require_once HODPAY_APP_DIR . '/lib/' . $lib . '.php';
}

/* Eccezioni non gestite: risposta generica, dettagli solo nel log. */
set_exception_handler(static function (Throwable $e): void {
    error_log('[hodpay] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Errore: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (hodpay_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'Errore interno. Riprova tra poco.']], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="it"><meta charset="utf-8"><title>Errore</title><body style="font-family:system-ui;padding:2rem"><h1>Si è verificato un errore</h1><p>Riprova tra qualche minuto.</p></body></html>';
    }
    exit;
});
