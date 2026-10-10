<?php
/* Caricamento e validazione della configurazione privata (config/config.php). */

declare(strict_types=1);

function hodpay_config_defaults(): array
{
    return [
        'mollie_api_key'       => '',
        'require_admin_login'  => false,
        'admin_password_hash'  => '',
        'merchant_name'        => 'The House Of Dog',
        'purchase_description' => 'Caparra cucciolo - The House Of Dog',
        'currency'             => 'EUR',
        'min_amount_cents'     => 100,
        'max_amount_cents'     => 400000,
        'customer_link_hours'  => 72,
        'default_country'      => 'IT',
        'allowed_countries'    => ['IT', 'AT', 'BE', 'DE', 'ES', 'FI', 'FR', 'IE', 'NL', 'PT'],
        'vat_rate'             => null,
        'base_url'             => null,
        'redirect_url'         => 'auto',
        'webhook_url'          => null,
        'mollie_dashboard_url' => 'https://my.mollie.com/dashboard',
        'session_name'         => 'hodpay',
        'session_idle_minutes' => 30,
        'trust_proxy_headers'  => false,
        'cookie_secure'        => 'auto',
    ];
}

/** Restituisce la configurazione unita ai default. In cache per richiesta. */
function hodpay_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $file = HODPAY_CONFIG_DIR . '/config.php';
    $user = [];
    if (is_file($file)) {
        $loaded = require $file;
        if (is_array($loaded)) {
            $user = $loaded;
        }
    }
    $cfg = array_replace(hodpay_config_defaults(), $user);

    $cfg['mollie_api_key']      = trim((string)$cfg['mollie_api_key']);
    $cfg['admin_password_hash'] = trim((string)$cfg['admin_password_hash']);
    $cfg['currency']            = strtoupper(trim((string)$cfg['currency'])) ?: 'EUR';
    $cfg['min_amount_cents']    = max(1, (int)$cfg['min_amount_cents']);
    $cfg['max_amount_cents']    = max($cfg['min_amount_cents'], (int)$cfg['max_amount_cents']);
    $cfg['customer_link_hours'] = min(24 * 30, max(1, (int)$cfg['customer_link_hours']));
    $cfg['default_country']     = strtoupper(substr(trim((string)$cfg['default_country']), 0, 2)) ?: 'IT';
    $cfg['allowed_countries']   = array_values(array_unique(array_map(
        static fn($c) => strtoupper(substr(trim((string)$c), 0, 2)),
        is_array($cfg['allowed_countries']) ? $cfg['allowed_countries'] : ['IT']
    )));
    if (!in_array($cfg['default_country'], $cfg['allowed_countries'], true)) {
        array_unshift($cfg['allowed_countries'], $cfg['default_country']);
    }
    $cfg['purchase_description'] = hodpay_cut(trim((string)$cfg['purchase_description']), 120) ?: 'Pagamento';
    $cfg['merchant_name']        = hodpay_cut(trim((string)$cfg['merchant_name']), 80) ?: 'Venditore';
    $cfg['session_idle_minutes'] = max(5, (int)$cfg['session_idle_minutes']);
    return $cfg;
}

/** demo = nessuna chiave; test = chiave test_; live = chiave live_; invalid = formato sconosciuto. */
function hodpay_mode(?array $cfg = null): string
{
    $cfg ??= hodpay_config();
    $key = $cfg['mollie_api_key'];
    if ($key === '') {
        return 'demo';
    }
    if (preg_match('/^test_[A-Za-z0-9]{20,}$/', $key)) {
        return 'test';
    }
    if (preg_match('/^live_[A-Za-z0-9]{20,}$/', $key)) {
        return 'live';
    }
    return 'invalid';
}

function hodpay_mode_label(string $mode): string
{
    return match ($mode) {
        'demo'  => 'Demo: chiave Mollie non configurata',
        'test'  => 'Test: nessun addebito reale',
        'live'  => 'Live: pagamenti reali',
        default => 'Chiave Mollie non valida',
    };
}

/** Taglio sicuro anche senza mbstring. */
function hodpay_cut(string $s, int $max): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

function hodpay_len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}
