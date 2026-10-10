<?php
/* Genera l'hash della password amministratore da incollare in config/config.php.
   Uso:  php tools/make-password-hash.php            (chiede la password)
         php tools/make-password-hash.php 'LaMiaPassword'
   Non salva nulla: stampa solo l'hash. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo da riga di comando.');
}

$password = $argv[1] ?? null;
if ($password === null) {
    fwrite(STDOUT, "Password amministratore (minimo 12 caratteri): ");
    $password = rtrim((string)fgets(STDIN), "\r\n");
}
if (strlen($password) < 12) {
    fwrite(STDERR, "Password troppo corta: usa almeno 12 caratteri.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
fwrite(STDOUT, "\nIncolla questa riga in config/config.php:\n\n");
fwrite(STDOUT, "    'admin_password_hash' => '" . $hash . "',\n\n");
