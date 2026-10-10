<?php
/* Token, sessione, CSRF, limiti di frequenza. */

declare(strict_types=1);

/** Token cliente: 256 bit casuali, 64 caratteri esadecimali. */
function hodpay_random_token(): string
{
    return bin2hex(random_bytes(32));
}

function hodpay_valid_token_format(string $t): bool
{
    return (bool)preg_match('/^[a-f0-9]{64}$/', $t);
}

/** Nel database si conserva solo l'hash: un dump del DB non espone i link attivi. */
function hodpay_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function hodpay_uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function hodpay_client_ip(): string
{
    $cfg = hodpay_config();
    if ($cfg['trust_proxy_headers']) {
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function hodpay_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    $cfg = hodpay_config();
    if ($cfg['trust_proxy_headers'] && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return false;
}

function hodpay_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $cfg = hodpay_config();
    $secure = $cfg['cookie_secure'] === 'auto' ? hodpay_is_https() : (bool)$cfg['cookie_secure'];
    session_name((string)$cfg['session_name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => hodpay_cookie_path(),
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();

    $now = time();
    $idle = (int)$cfg['session_idle_minutes'] * 60;
    if (!empty($_SESSION['last_seen']) && ($now - (int)$_SESSION['last_seen']) > $idle) {
        /* Sessione inattiva troppo a lungo: azzera tutto, incluso il login. */
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = $now;
}

/** Il cookie vale solo sotto la cartella pubblica di questa app. */
function hodpay_cookie_path(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
    $dir = str_replace('\\', '/', dirname($script));
    if (str_ends_with($dir, '/api')) {
        $dir = substr($dir, 0, -4);
    }
    $dir = rtrim($dir, '/') . '/';
    return $dir === '//' ? '/' : $dir;
}

function hodpay_csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function hodpay_csrf_check(?string $given): bool
{
    $expected = $_SESSION['csrf'] ?? null;
    return is_string($expected) && is_string($given) && $given !== '' && hash_equals($expected, $given);
}

/** Difesa aggiuntiva anti-CSRF: rifiuta POST dichiaratamente cross-site. */
function hodpay_same_origin_check(): bool
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
 * Limite di frequenza a finestra scorrevole su SQLite.
 * Restituisce true se la richiesta è consentita (e la registra), false se il limite è superato.
 */
function hodpay_rate_limit(string $bucket, int $max, int $windowSeconds): bool
{
    $db = hodpay_db();
    $now = time();
    $bucket = hash('sha256', $bucket);
    $db->prepare('DELETE FROM rate_limits WHERE bucket = :b AND ts < :cut')
       ->execute([':b' => $bucket, ':cut' => $now - $windowSeconds]);
    $st = $db->prepare('SELECT COUNT(*) FROM rate_limits WHERE bucket = :b');
    $st->execute([':b' => $bucket]);
    if ((int)$st->fetchColumn() >= $max) {
        return false;
    }
    $db->prepare('INSERT INTO rate_limits (bucket, ts) VALUES (:b, :ts)')->execute([':b' => $bucket, ':ts' => $now]);
    return true;
}

function hodpay_nonce(): string
{
    static $nonce = null;
    return $nonce ??= base64_encode(random_bytes(16));
}
