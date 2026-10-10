<?php
/* Punto di aggancio tra la cartella pubblica e quella privata (app/, config/, data/).
   Se sposti la cartella privata fuori dalla document root, modifica SOLO la riga $private.
   Esempio Hostinger, cartella privata accanto a public_html:
     $private = dirname(__DIR__, 2) . '/payments-private';
   dove public/ è stato caricato come public_html/payments/. */

declare(strict_types=1);

$private = getenv('HODPAY_PRIVATE_DIR') ?: dirname(__DIR__);
$app = getenv('HODPAY_APP_DIR') ?: $private . '/app';

if (!is_file($app . '/bootstrap.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Cartella privata non trovata. Controlla il percorso in app-path.php.');
}

define('HODPAY_PRIVATE_DIR', $private);
define('HODPAY_APP_DIR', $app);
require $app . '/bootstrap.php';
