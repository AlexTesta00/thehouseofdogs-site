<?php
/* Configurazione privata (config/config.php) unita ai valori predefiniti e normalizzata, più il log privato. */

declare(strict_types=1);

function hodbook_config_defaults(): array
{
    return [
        'bank_account_holder'         => '',
        'bank_iban'                   => '',
        'bank_bic'                    => '',
        'bank_name'                   => 'Revolut',
        'revolut_webhook_secrets'     => [],
        'revolut_account_ids'         => [],
        'products'                    => [
            'barboncino'          => ['name' => 'Caparra Barboncino Toy', 'amount_cents' => 55000],
            'maltipoo'            => ['name' => 'Caparra Maltipoo Toy', 'amount_cents' => 45000],
            'maltese'             => ['name' => 'Caparra Maltese Toy', 'amount_cents' => 40000],
            'bassotto-arlecchino' => ['name' => 'Caparra Bassotto Kaninchen Arlecchino', 'amount_cents' => 55000],
            'bassotto-cioccolato' => ['name' => 'Caparra Bassotto Kaninchen Cioccolato', 'amount_cents' => 45000],
        ],
        'currency'                    => 'EUR',
        'reference_prefix'            => 'HOD',
        'whatsapp_number'             => '393770876017',
        'merchant_name'               => 'The House Of Dog',
        'timestamp_tolerance_seconds' => 300,
        'trust_proxy_headers'         => false,
        'merchant_email'              => 'houseofdogdigiuseppe@outlook.it',
        'mail_from_address'           => 'noreply@thehouseofdog.it',
        'mail_from_name'              => 'House of Dogs',
        'mail_mode'                   => 'mail',
        'site_url'                    => 'https://www.thehouseofdog.it',
        'contact_phone_display'       => '',
    ];
}

/** Configurazione della richiesta corrente (letta una volta sola). */
function hodbook_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    /* bank.php (nel repository) porta le coordinate bancarie; config.php (privato, generato dal deploy o
       scritto a mano) si sovrappone e ha la precedenza. */
    $user = [];
    foreach (['bank.php', 'config.php'] as $name) {
        $file = HODBOOK_CONFIG_DIR . '/' . $name;
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                $user = array_replace($user, $loaded);
            }
        }
    }
    return $cfg = hodbook_config_normalize($user);
}

/**
 * Unisce la configurazione utente ai default e la normalizza. Funzione pura (usata anche dai test).
 * Nota: "products", se presente, sostituisce per intero l'elenco predefinito.
 */
function hodbook_config_normalize(array $user): array
{
    $c = array_replace(hodbook_config_defaults(), $user);
    $out = [];

    $out['bank_account_holder'] = hodbook_clean_text($c['bank_account_holder'], 140);
    $out['bank_iban'] = hodbook_iban_normalize(is_string($c['bank_iban']) ? $c['bank_iban'] : '');
    $bic = strtoupper(preg_replace('/\s+/', '', is_string($c['bank_bic']) ? $c['bank_bic'] : '') ?? '');
    $out['bank_bic'] = hodbook_bic_valid($bic) ? $bic : '';
    $out['bank_name'] = hodbook_clean_text($c['bank_name'], 80) ?: 'Revolut';

    /* Segreti di firma: uno o più (rotazione). Si accetta anche una stringa singola o separata da virgole. */
    $raw = $c['revolut_webhook_secrets'];
    if (is_string($raw)) {
        $raw = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $secrets = [];
    foreach (is_array($raw) ? $raw : [] as $s) {
        if (is_string($s) && hodbook_webhook_secret_valid(trim($s))) {
            $secrets[] = trim($s);
        }
    }
    $out['revolut_webhook_secrets'] = array_values(array_unique($secrets));

    /* Conti Revolut da considerare (vuoto = tutti). */
    $ids = $c['revolut_account_ids'];
    if (is_string($ids)) {
        $ids = preg_split('/[\s,]+/', $ids, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $accounts = [];
    foreach (is_array($ids) ? $ids : [] as $id) {
        if (is_string($id) && preg_match('/^[A-Za-z0-9\-]{1,64}\z/', trim($id))) {
            $accounts[] = strtolower(trim($id));
        }
    }
    $out['revolut_account_ids'] = array_values(array_unique($accounts));

    /* Prodotti: chiave => nome e caparra in centesimi interi. Le voci non valide vengono scartate. */
    $products = [];
    foreach (is_array($c['products']) ? $c['products'] : [] as $key => $p) {
        if (!is_string($key) || !preg_match('/^[a-z0-9][a-z0-9\-]{0,39}\z/', $key) || !is_array($p)) {
            continue;
        }
        $name = hodbook_clean_text($p['name'] ?? '', 120);
        $amount = $p['amount_cents'] ?? null;
        if (is_string($amount) && preg_match('/^\d{1,9}\z/', $amount)) {
            $amount = (int)$amount;
        }
        if ($name === '' || !is_int($amount) || $amount <= 0 || $amount > 100000000) {
            continue;
        }
        $products[$key] = ['name' => $name, 'amount_cents' => $amount];
    }
    $out['products'] = $products;

    $currency = strtoupper(trim(is_string($c['currency']) ? $c['currency'] : ''));
    $out['currency'] = preg_match('/^[A-Z]{3}\z/', $currency) ? $currency : 'EUR';

    $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper(is_string($c['reference_prefix']) ? $c['reference_prefix'] : '')) ?? '';
    $out['reference_prefix'] = substr($prefix, 0, 6) ?: 'HOD';

    $wa = preg_replace('/\D+/', '', is_string($c['whatsapp_number']) || is_int($c['whatsapp_number']) ? (string)$c['whatsapp_number'] : '') ?? '';
    $out['whatsapp_number'] = strlen($wa) >= 6 && strlen($wa) <= 15 ? $wa : '393770876017';

    $out['merchant_name'] = hodbook_clean_text($c['merchant_name'], 80) ?: 'The House Of Dog';
    $out['timestamp_tolerance_seconds'] = min(3600, max(30, (int)$c['timestamp_tolerance_seconds']));
    $out['trust_proxy_headers'] = (bool)$c['trust_proxy_headers'];

    /* Email: un indirizzo non valido torna al valore predefinito (mai un'intestazione costruita con dati sporchi). */
    $d = hodbook_config_defaults();
    foreach (['merchant_email', 'mail_from_address'] as $key) {
        $email = is_string($c[$key]) ? trim($c[$key]) : '';
        $out[$key] = hodbook_email_header_safe($email) ? $email : $d[$key];
    }
    $out['mail_from_name'] = hodbook_clean_text($c['mail_from_name'], 80) ?: $d['mail_from_name'];
    $mode = is_string($c['mail_mode']) ? strtolower(trim($c['mail_mode'])) : '';
    $out['mail_mode'] = in_array($mode, ['mail', 'file', 'off'], true) ? $mode : 'mail';
    /* URL pubblico del sito per i link nelle email: mai ricavato dall'intestazione Host della richiesta. */
    $url = is_string($c['site_url']) ? rtrim(trim($c['site_url']), '/') : '';
    $out['site_url'] = preg_match('#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?(/[A-Za-z0-9._~\-/]*)?\z#', $url) ? rtrim($url, '/') : $d['site_url'];
    $out['contact_phone_display'] = hodbook_clean_text($c['contact_phone_display'], 40);

    return $out;
}

/** Numero di telefono da mostrare (email al cliente): contact_phone_display o, se vuoto, whatsapp_number formattato. */
function hodbook_phone_display(array $cfg): string
{
    if ($cfg['contact_phone_display'] !== '') {
        return $cfg['contact_phone_display'];
    }
    $n = $cfg['whatsapp_number'];
    if (preg_match('/^39(\d{3})(\d{3})(\d{3,4})\z/', $n, $m)) {
        return '+39 ' . $m[1] . ' ' . $m[2] . ' ' . $m[3];   /* 393770876017 -> +39 377 087 6017 */
    }
    return '+' . $n;
}

/** Banca configurata = intestatario presente e IBAN sintatticamente valido (mod-97). */
function hodbook_bank_configured(array $cfg): bool
{
    return $cfg['bank_account_holder'] !== '' && hodbook_iban_valid($cfg['bank_iban']);
}

/** Webhook configurato = almeno un segreto di firma. */
function hodbook_webhook_configured(array $cfg): bool
{
    return $cfg['revolut_webhook_secrets'] !== [];
}

/**
 * Log privato dell'app (data/prenotazioni.log). Solo eventi tecnici: mai nomi, email, segreti o payload interi.
 * Oltre 2 MB il file viene ruotato in prenotazioni-old.log.
 */
function hodbook_log(string $message): void
{
    if (!defined('HODBOOK_DATA_DIR') || !is_dir(HODBOOK_DATA_DIR)) {
        return;
    }
    $file = HODBOOK_DATA_DIR . '/prenotazioni.log';
    if (is_file($file) && (int)@filesize($file) > 2 * 1024 * 1024) {
        @rename($file, HODBOOK_DATA_DIR . '/prenotazioni-old.log');
    }
    $line = date('Y-m-d H:i:s') . ' ' . str_replace(["\r", "\n"], ' ', $message) . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
