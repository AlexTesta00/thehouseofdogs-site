<?php
/* House of Dogs - prenotazioni con bonifico: bootstrap condiviso.
   Incluso da prenota.php, prenotazione.php e webhook-revolut.php. Imposta i percorsi privati, la gestione
   degli errori (mai a schermo, sempre nel log privato) e carica le librerie. Nessun output qui.

   Cartella privata (config/ e data/): per default è questa cartella. I test ne usano una temporanea
   definendo la costante HODBOOK_PRIVATE_DIR oppure la variabile d'ambiente omonima, così configurazione
   e dati veri non vengono mai toccati. La cartella è protetta dal web dal file .htaccess accanto. */

declare(strict_types=1);

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('Serve PHP 8.2 o successivo.');
}

if (!defined('HODBOOK_PRIVATE_DIR')) {
    $hodbookEnvDir = getenv('HODBOOK_PRIVATE_DIR');
    define('HODBOOK_PRIVATE_DIR', is_string($hodbookEnvDir) && $hodbookEnvDir !== '' && is_dir($hodbookEnvDir)
        ? rtrim($hodbookEnvDir, '/\\')
        : __DIR__);
    unset($hodbookEnvDir);
}
if (!defined('HODBOOK_CONFIG_DIR')) {
    define('HODBOOK_CONFIG_DIR', HODBOOK_PRIVATE_DIR . '/config');
}
if (!defined('HODBOOK_DATA_DIR')) {
    define('HODBOOK_DATA_DIR', HODBOOK_PRIVATE_DIR . '/data');
}
if (!defined('HODBOOK_VERSION')) {
    define('HODBOOK_VERSION', '1.0.0');
}

if (!is_dir(HODBOOK_DATA_DIR)) {
    @mkdir(HODBOOK_DATA_DIR, 0750, true);
}

/* Errori: mai a schermo, sempre nel log privato. */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', HODBOOK_DATA_DIR . '/php-errors.log');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Rome');

foreach (['validation', 'config', 'db', 'security', 'bookings', 'revolut', 'view'] as $hodbookLib) {
    require_once __DIR__ . '/lib/' . $hodbookLib . '.php';
}
unset($hodbookLib);

/* Eccezioni non gestite: risposta generica, dettagli solo nel log privato. */
set_exception_handler(static function (Throwable $e): void {
    error_log('[hodbook] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Errore: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Cache-Control: no-store');
        header('Content-Type: ' . (hodbook_json_mode() ? 'application/json' : 'text/html') . '; charset=utf-8');
    }
    if (hodbook_json_mode()) {
        echo '{"ok":false,"error":"server_error"}';
    } else {
        echo '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>Errore | House of Dogs</title></head>'
            . '<body style="font-family:system-ui,sans-serif;padding:2rem;max-width:36rem;margin:0 auto;color:#0f2a44">'
            . '<h1>Si è verificato un errore</h1><p>Riprova tra qualche minuto. Se il problema continua, scrivici su '
            . '<a href="https://wa.me/393770876017" style="color:#1470b3">WhatsApp</a>.</p>'
            . '<p><a href="./" style="color:#1470b3">← Torna al sito</a></p></body></html>';
    }
    exit;
});
