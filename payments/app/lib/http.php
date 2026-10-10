<?php
/* Risposte HTTP, intestazioni di sicurezza, URL. */

declare(strict_types=1);

function hodpay_e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function hodpay_wants_json(): bool
{
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    $uri = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    return str_contains($accept, 'application/json') || str_contains($uri, '/api/');
}

function hodpay_send_security_headers(string $nonce): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cache-Control: no-store, max-age=0');
    header('Content-Security-Policy: ' . implode('; ', [
        "default-src 'self'",
        "script-src 'self' https://cdn.tailwindcss.com 'nonce-{$nonce}'",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data:",
        "connect-src 'self'",
        "font-src 'self'",
        "base-uri 'self'",
        "frame-ancestors 'none'",
        "form-action 'self' https:",
        "object-src 'none'",
    ]));
    if (hodpay_is_https()) {
        header('Strict-Transport-Security: max-age=15552000');
    }
}

function hodpay_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function hodpay_json_error(int $status, string $code, string $message, array $extra = []): never
{
    hodpay_json($status, ['ok' => false, 'error' => array_merge(['code' => $code, 'message' => $message], $extra)]);
}

function hodpay_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '' || strlen($raw) > 65536) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function hodpay_redirect(string $url, int $status = 303): never
{
    header('Location: ' . $url, true, $status);
    exit;
}

/** URL pubblico della cartella payments, senza slash finale. */
function hodpay_base_url(): string
{
    $cfg = hodpay_config();
    if (is_string($cfg['base_url']) && $cfg['base_url'] !== '') {
        return rtrim($cfg['base_url'], '/');
    }
    $scheme = hodpay_is_https() ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
        $host = 'localhost';
    }
    $path = rtrim(hodpay_cookie_path(), '/');
    return $scheme . '://' . $host . $path;
}

function hodpay_self_url(): string
{
    return hodpay_base_url() . '/' . basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
}
