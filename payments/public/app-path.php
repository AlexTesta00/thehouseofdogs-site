<?php
/* Punto di aggancio tra la cartella pubblica e quella privata (app/, config/, data/).
   La cartella privata viene cercata, nell'ordine, in:
     1. la variabile d'ambiente HODPAY_PRIVATE_DIR (usata dai test);
     2. la cartella madre di public/          -> layout di sviluppo: payments/public + payments/app
     3. ../payments-private accanto a questa  -> layout Hostinger: public_html/payments + public_html/payments-private
     4. ../../payments-private                -> cartella privata fuori dalla document root
   Per un percorso diverso, aggiungilo in cima alla lista $candidati. */

declare(strict_types=1);

$candidati = [
    dirname(__DIR__),
    dirname(__DIR__) . '/payments-private',
    dirname(__DIR__, 2) . '/payments-private',
];

$private = getenv('HODPAY_PRIVATE_DIR') ?: null;
$app = getenv('HODPAY_APP_DIR') ?: null;
if ($private === null) {
    foreach ($candidati as $dir) {
        if (is_file($dir . '/app/bootstrap.php')) {
            $private = $dir;
            break;
        }
    }
}
if ($app === null && $private !== null) {
    $app = $private . '/app';
}

if ($private === null || $app === null || !is_file($app . '/bootstrap.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Cartella privata non trovata. Controlla il percorso in app-path.php.');
}

define('HODPAY_PRIVATE_DIR', $private);
define('HODPAY_APP_DIR', $app);
require $app . '/bootstrap.php';
