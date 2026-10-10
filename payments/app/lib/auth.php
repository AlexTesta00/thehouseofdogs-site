<?php
/* Accesso amministratore: una sola password, hash in config, sessione PHP. */

declare(strict_types=1);

function hodpay_admin_configured(): bool
{
    $hash = hodpay_config()['admin_password_hash'];
    return $hash !== '' && password_get_info($hash)['algo'] !== null;
}

function hodpay_is_admin(): bool
{
    return session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['admin']) && $_SESSION['admin'] === true;
}

function hodpay_login(string $password): bool
{
    if (!hodpay_admin_configured() || $password === '' || strlen($password) > 1024) {
        return false;
    }
    if (!password_verify($password, hodpay_config()['admin_password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['login_at'] = time();
    unset($_SESSION['csrf']);
    hodpay_csrf_token();
    return true;
}

function hodpay_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
    }
    session_destroy();
}

/** Per gli endpoint JSON: verifica sessione admin, origine e CSRF. */
function hodpay_require_admin_api(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        hodpay_json_error(405, 'method_not_allowed', 'Metodo non consentito.');
    }
    if (!hodpay_is_admin()) {
        hodpay_json_error(401, 'unauthorized', 'Sessione scaduta. Ricarica la pagina ed effettua di nuovo l\'accesso.');
    }
    if (!hodpay_same_origin_check()) {
        hodpay_json_error(403, 'bad_origin', 'Richiesta rifiutata.');
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!hodpay_csrf_check(is_string($token) ? $token : null)) {
        hodpay_json_error(403, 'csrf', 'Token di sicurezza non valido. Ricarica la pagina.');
    }
}
