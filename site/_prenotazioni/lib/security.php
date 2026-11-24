<?php
/* Token del link, segreto applicativo, protezione CSRF senza sessione, origine, IP e limiti di frequenza.
   Niente cookie e niente sessioni PHP: l'informativa del sito dichiara che non vengono installati cookie. */

declare(strict_types=1);

const HODBOOK_FORM_MIN_AGE = 2;      /* secondi: sotto questa soglia il modulo è stato inviato troppo in fretta */
const HODBOOK_FORM_MAX_AGE = 7200;   /* secondi: oltre, la pagina è rimasta aperta troppo a lungo */

/** Token del link di prenotazione: 256 bit casuali, 64 caratteri esadecimali. */
function hodbook_random_token(): string
{
    return bin2hex(random_bytes(32));
}

function hodbook_valid_token_format(string $t): bool
{
    return (bool)preg_match('/^[a-f0-9]{64}\z/', $t);
}

/** Nel database si conserva solo l'hash: una copia del DB non espone i link delle prenotazioni. */
function hodbook_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Segreto applicativo (32 byte casuali) generato al primo uso in data/app-secret.php e mai nel repository.
 * Firma i token del modulo e fa da sale per l'hash degli IP. Il file è PHP: se per errore fosse
 * raggiungibile dal web verrebbe eseguito senza stampare nulla. Si legge come testo, non con include,
 * così un file scritto a metà da una richiesta concorrente non produce errori di sintassi.
 */
function hodbook_app_secret(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    $file = HODBOOK_DATA_DIR . '/app-secret.php';
    for ($i = 0; $i < 40; $i++) {
        $found = hodbook_read_app_secret($file);
        if ($found !== null) {
            return $secret = $found;
        }
        if (!is_file($file)) {
            if (!is_dir(HODBOOK_DATA_DIR)) {
                @mkdir(HODBOOK_DATA_DIR, 0750, true);
            }
            $new = bin2hex(random_bytes(32));
            $fh = @fopen($file, 'x');   /* creazione esclusiva: tra due richieste concorrenti ne vince una sola */
            if ($fh !== false) {
                fwrite($fh, "<?php\n/* Segreto dell'app prenotazioni, generato automaticamente. Non condividerlo e non metterlo nel repository. */\nreturn '" . $new . "';\n");
                fflush($fh);
                fclose($fh);
                @chmod($file, 0600);
                return $secret = $new;
            }
        } elseif (time() - (int)@filemtime($file) > 10) {
            /* File presente ma illeggibile da più di 10 secondi: è danneggiato, lo si mette da parte e si rigenera. */
            @rename($file, HODBOOK_DATA_DIR . '/app-secret-danneggiato-' . date('YmdHis') . '.php');
            continue;
        }
        usleep(25000);
    }
    throw new RuntimeException('Segreto applicativo non disponibile.');
}

function hodbook_read_app_secret(string $file): ?string
{
    if (!is_file($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    if (is_string($raw) && preg_match("/return '([a-f0-9]{64})';/", $raw, $m)) {
        return $m[1];
    }
    return null;
}

/* ---------- CSRF senza stato: "timestamp.firma" ---------- */

/** Token nascosto del modulo: timestamp di emissione + HMAC-SHA256 di "prenota|timestamp" con il segreto applicativo. */
function hodbook_form_token(int $now, ?string $secret = null): string
{
    $secret ??= hodbook_app_secret();
    return $now . '.' . hash_hmac('sha256', 'prenota|' . $now, $secret);
}

/**
 * Verifica il token del modulo. Restituisce:
 *   'ok'        firma valida, età tra 2 secondi e 2 ore
 *   'too_fresh' firma valida ma inviato dopo meno di 2 secondi (rallenta i bot)
 *   'expired'   firma valida ma più vecchio di 2 ore
 *   'invalid'   formato o firma non validi
 */
function hodbook_form_token_check(mixed $token, int $now, ?string $secret = null): string
{
    if (!is_string($token) || !preg_match('/^(\d{9,12})\.([a-f0-9]{64})\z/', $token, $m)) {
        return 'invalid';
    }
    $secret ??= hodbook_app_secret();
    if (!hash_equals(hash_hmac('sha256', 'prenota|' . $m[1], $secret), $m[2])) {
        return 'invalid';
    }
    $age = $now - (int)$m[1];
    if ($age < HODBOOK_FORM_MIN_AGE) {
        return 'too_fresh';
    }
    if ($age > HODBOOK_FORM_MAX_AGE) {
        return 'expired';
    }
    return 'ok';
}

/* ---------- Richiesta: IP, HTTPS, origine ---------- */

function hodbook_client_ip(array $cfg): string
{
    if (!empty($cfg['trust_proxy_headers'])) {
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function hodbook_is_https(array $cfg): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    return !empty($cfg['trust_proxy_headers'])
        && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Difesa anti-CSRF aggiuntiva: rifiuta i POST dichiaratamente cross-site (Sec-Fetch-Site o Origin di un altro host). */
function hodbook_same_origin_check(): bool
{
    $site = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($site !== '' && !in_array($site, ['same-origin', 'same-site', 'none'], true)) {
        return false;
    }
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && $origin !== 'null') {
        $host = strtolower((string)parse_url($origin, PHP_URL_HOST));
        $self = strtolower((string)explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]);
        if ($host === '' || $self === '' || $host !== $self) {
            return false;
        }
    }
    return true;
}

/**
 * Chiave del limite di frequenza per un IP: HMAC-SHA256 con il segreto applicativo, l'IP in chiaro non viene salvato.
 * Gli IPv6 si raggruppano per /64 (una sola connessione domestica ne ha un intero blocco).
 */
function hodbook_ip_bucket(string $scope, string $ip, ?string $secret = null): string
{
    $key = $ip;
    if (str_contains($ip, ':')) {
        $bin = @inet_pton($ip);
        if (is_string($bin) && strlen($bin) === 16) {
            $key = bin2hex(substr($bin, 0, 8)) . '::/64';
        }
    }
    return hash_hmac('sha256', $scope . '|' . $key, $secret ?? hodbook_app_secret());
}

/**
 * Limite a finestra scorrevole su SQLite: true se la richiesta è consentita (e viene registrata),
 * false se nella finestra ci sono già $max richieste.
 */
function hodbook_rate_limit(PDO $db, string $bucket, int $max, int $windowSeconds, int $now): bool
{
    return hodbook_db_tx($db, static function (PDO $db) use ($bucket, $max, $windowSeconds, $now): bool {
        $db->prepare('DELETE FROM rate_limits WHERE bucket = :b AND ts <= :cut')
           ->execute([':b' => $bucket, ':cut' => $now - $windowSeconds]);
        $st = $db->prepare('SELECT COUNT(*) FROM rate_limits WHERE bucket = :b');
        $st->execute([':b' => $bucket]);
        if ((int)$st->fetchColumn() >= $max) {
            return false;
        }
        $db->prepare('INSERT INTO rate_limits (bucket, ts) VALUES (:b, :ts)')->execute([':b' => $bucket, ':ts' => $now]);
        return true;
    });
}
