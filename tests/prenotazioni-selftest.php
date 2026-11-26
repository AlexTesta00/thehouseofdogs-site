<?php
/* Test automatici delle prenotazioni con bonifico (site/_prenotazioni). Questo file non viene pubblicato.
   Uso:  php tests/prenotazioni-selftest.php          test unitari e di integrazione su una cartella temporanea
         php tests/prenotazioni-selftest.php --http   in più avvia il server PHP integrato su site/ e prova le pagine
   Senza php.ini le estensioni vanno passate a mano:
         php -n -d extension_dir=<cartella php>/ext -d extension=pdo_sqlite -d extension=curl -d extension=mbstring
             -d extension=openssl tests/prenotazioni-selftest.php --http
   La cartella privata vera (site/_prenotazioni/config e data) non viene mai toccata: config e dati dei test
   vivono in una cartella temporanea (costante e variabile d'ambiente HODBOOK_PRIVATE_DIR).
   Esce con codice 1 se un test fallisce. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Solo da riga di comando.');
}

$withHttp = in_array('--http', $argv, true);
$siteDir = dirname(__DIR__) . '/site';

/* ---------- Ambiente privato temporaneo ---------- */
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hodbook-selftest-' . bin2hex(random_bytes(4));
mkdir($tmp . '/config', 0700, true);
mkdir($tmp . '/data', 0700, true);
$servers = [];

/* Pulizia anche se un test si interrompe con un'eccezione: server fermati, database chiuso, cartella rimossa. */
register_shutdown_function(static function () use ($tmp): void {
    foreach ($GLOBALS['servers'] ?? [] as $s) {
        if (is_resource($s['proc'])) {
            proc_terminate($s['proc']);
            proc_close($s['proc']);
        }
    }
    foreach (['DB', 'failingDb', 'db3', 'migrated'] as $handle) {
        $GLOBALS[$handle] = null;
    }
    gc_collect_cycles();
    $rm = static function (string $dir) use (&$rm): void {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) ? $rm($path) : @unlink($path);
        }
        @rmdir($dir);
    };
    /* Su Windows un server appena fermato può tenere aperto il database per qualche istante: si riprova. */
    for ($attempt = 0; $attempt < 15 && is_dir($tmp); $attempt++) {
        if ($attempt > 0) {
            usleep(200000);
        }
        $rm($tmp);
    }
});

const VECTOR_SECRET = 'wsk_r59a4HfWVAKycbCaNO1RvgCJec02gRd8';
const VECTOR_TS = '1683650202360';
const VECTOR_BODY = '{"data":{"id":"645a7696-22f3-aa47-9c74-cbae0449cc46","new_state":"completed","old_state":"pending","request_id":"app_charges-9f5d5eb3-1e06-46c5-b1c0-3914763e0bcb"},"event":"TransactionStateChanged","timestamp":"2023-05-09T16:36:38.028960Z"}';
const VECTOR_SIG = 'v1=bca326fb378d0da7f7c490ad584a8106bab9723d8d9cdd0d50b4c5b3be3837c0';

$secretA = 'wsk_' . bin2hex(random_bytes(16));
$secretB = 'wsk_' . bin2hex(random_bytes(16));
const TEST_IBAN = 'IT60X0542811101000000123456';   /* IBAN di esempio con cifre di controllo valide */
const TEST_SITE_URL = 'https://prenota.example.test';   /* diverso dall'host del server di prova: i link delle email non vengono da Host */
const TEST_MERCHANT = 'esercente@example.test';
/* Codici fiscali calcolati con l'algoritmo ufficiale (verificato su codici pubblicati come validi: RSSMRA80A01H501U, CCCFBA85D03L219P). */
const IBAN_2 = 'IT42A0366901600067012321526';   /* secondo conto (bank.php), valido */
const CF_OK = 'RSSMRA80A01H501U';
const CF_OK_2 = 'BNCLRA92M55A662S';     /* donna (giorno + 40) */

function write_config(string $dir, array $cfg): void
{
    file_put_contents($dir . '/config/config.php', "<?php\nreturn " . var_export($cfg, true) . ";\n");
}

$testConfig = [
    'bank_account_holder'     => 'TITOLARE DI PROVA',
    'bank_iban'               => 'it60 x054 2811 1010 0000 0123 456',
    'bank_bic'                => 'revolt21',
    'revolut_webhook_secrets' => $secretA,
    'mail_mode'               => 'file',
    'site_url'                => TEST_SITE_URL . '/',
    'merchant_email'          => TEST_MERCHANT,
];
write_config($tmp, $testConfig);

define('HODBOOK_PRIVATE_DIR', $tmp);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/prenota.php';
$_SERVER['HTTP_HOST'] = 'example.test';
require $siteDir . '/_prenotazioni/bootstrap.php';

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $info = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   $name\n";
    } else {
        $fail++;
        echo "  FAIL $name" . ($info !== '' ? "  ($info)" : '') . "\n";
    }
}
function section(string $t): void
{
    echo "\n== $t ==\n";
}

$CFG = hodbook_config();
$DB = hodbook_db_open(HODBOOK_DATA_DIR . '/prenotazioni.sqlite');

/* ---------- Helper per i webhook ---------- */
function now_us(): int
{
    return (int)floor(microtime(true) * 1000000);
}
function iso_us(int $micros): string
{
    return gmdate('Y-m-d\TH:i:s', intdiv($micros, 1000000)) . '.' . str_pad((string)($micros % 1000000), 6, '0', STR_PAD_LEFT) . 'Z';
}
function tx_id(): string
{
    $h = bin2hex(random_bytes(16));
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}
/** TransactionCreated con una gamba (importo positivo = entrata), sul modello dell'esempio della documentazione. */
function ev_created(string $txId, string $state, int|float $amount, string $currency, string $reference, int $tsUs, array $leg = [], array $data = []): array
{
    $leg = array_merge([
        'leg_id' => $txId . '-0', 'account_id' => '05018b0d-e67c-4fec-bea6-415e9da9432c',
        'counterparty' => ['id' => '7e18625a-3e6c-4d4f-8429-216c25309a5f', 'account_type' => 'external', 'account_id' => 'ff29e658-f07f-4d81-bc0f-7ad0ff141357'],
        'amount' => $amount, 'currency' => $currency, 'description' => 'Payment from Mario Rossi',
    ], $leg);
    return ['event' => 'TransactionCreated', 'timestamp' => iso_us($tsUs), 'data' => array_merge([
        'id' => $txId, 'type' => 'transfer', 'state' => $state, 'request_id' => 'req-' . $txId,
        'created_at' => iso_us($tsUs), 'updated_at' => iso_us($tsUs), 'reference' => $reference, 'legs' => [$leg],
    ], $data)];
}
function ev_state(string $txId, string $old, string $new, int $tsUs): array
{
    return ['event' => 'TransactionStateChanged', 'timestamp' => iso_us($tsUs),
            'data' => ['id' => $txId, 'request_id' => 'req-' . $txId, 'old_state' => $old, 'new_state' => $new]];
}
/** Intestazioni firmate come le manda Revolut. */
function sign(string $raw, string $secret, int $tsMs): array
{
    return ['Revolut-Request-Timestamp' => (string)$tsMs, 'Revolut-Signature' => 'v1=' . hash_hmac('sha256', 'v1.' . $tsMs . '.' . $raw, $secret)];
}
/** Consegna un evento firmato al gestore del webhook (stesso codice usato da webhook-revolut.php). */
function deliver(array|string $payload, ?array $cfg = null, ?string $secret = null): array
{
    global $CFG, $DB, $secretA;
    $raw = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES);
    $nowMs = (int)floor(microtime(true) * 1000);
    return hodbook_webhook_handle('POST', sign($raw, $secret ?? $secretA, $nowMs), $raw, $cfg ?? $CFG, $nowMs, $DB);
}
/** Dati cliente già validati (come li produce hodbook_validate_booking_form). */
function customer(array $over = []): array
{
    return array_merge(['first_name' => 'Maria', 'last_name' => 'Rossi', 'email' => 'maria@example.com', 'phone' => '+393331234567',
        'residence' => 'Via Roma 12, 70022 Altamura (BA)', 'tax_code' => CF_OK], $over);
}
function new_booking(string $key = 'barboncino'): array
{
    global $CFG, $DB;
    [, $row] = hodbook_booking_create($DB, $CFG, $key, customer(), time());
    return $row;
}

/* ---------- Helper per le email in modalità "file" ---------- */
/** File .eml in outbox, eventualmente solo quelli di un codice di prenotazione (il codice è nel nome del file). */
function outbox(string $privateDir, string $reference = ''): array
{
    $files = glob($privateDir . '/data/outbox/*.eml') ?: [];
    sort($files);
    return array_values(array_filter($files, static fn(string $f): bool => $reference === '' || str_contains(basename($f), '-' . $reference . '-')));
}
/** Intestazioni (ripiegature ricomposte, nomi in minuscolo, valori in lista) e corpo di un messaggio. */
function eml(string $raw): array
{
    [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $unfolded = preg_replace("/\r\n[ \t]+/", ' ', $head) ?? '';
    $headers = [];
    foreach (explode("\r\n", $unfolded) as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $headers[strtolower(trim($name))][] = trim($value);
    }
    return ['head' => $head, 'headers' => $headers, 'body' => $body];
}
/** Decodifica le encoded-word =?UTF-8?B?...?= (lo spazio tra due parole codificate adiacenti non conta). */
function mime_decode(string $value): string
{
    $value = preg_replace('/\?=\s+=\?/', '?==?', $value) ?? '';
    return preg_replace_callback('/=\?UTF-8\?B\?([A-Za-z0-9+\/=]*)\?=/i', static fn(array $m): string => (string)base64_decode($m[1]), $value) ?? '';
}
function hdr(array $msg, string $name): string
{
    return $msg['headers'][strtolower($name)][0] ?? '';
}
function bk(array $booking): ?array
{
    global $DB;
    return hodbook_booking_by_id($DB, (int)$booking['id']);
}
function txrow(string $id): ?array
{
    global $DB;
    return hodbook_revolut_tx_by_id($DB, $id);
}
function count_rows(string $sql, array $params = []): int
{
    global $DB;
    $st = $DB->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/* =================================================================== */
section('Ambiente di test isolato');
check('cartella privata temporanea', HODBOOK_PRIVATE_DIR === $tmp && HODBOOK_DATA_DIR === $tmp . '/data');
check('database temporaneo in WAL', $DB->query('PRAGMA journal_mode')->fetchColumn() === 'wal');
check('schema alla versione corrente', (int)$DB->query('PRAGMA user_version')->fetchColumn() === HODBOOK_SCHEMA_VERSION);
$tables = $DB->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
check('tabelle create', array_values(array_intersect(['bookings', 'rate_limits', 'revolut_events', 'revolut_transactions'], $tables)) === ['bookings', 'rate_limits', 'revolut_events', 'revolut_transactions'], implode(',', $tables));
hodbook_db_migrate($DB);
check('migrazione ripetuta senza errori', (int)$DB->query('PRAGMA user_version')->fetchColumn() === HODBOOK_SCHEMA_VERSION);
$cols = array_column($DB->query('PRAGMA table_info(bookings)')->fetchAll(), 'name');
check('schema nuovo: phone, residence, tax_code, customer_email_sent_at', array_diff(['phone', 'residence', 'tax_code', 'customer_email_sent_at'], $cols) === [], implode(',', $cols));
check('indice per il limite per email', (bool)$DB->query("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = 'idx_bookings_email_created'")->fetchColumn());

/* Database di sviluppo creato con lo schema 1 (con dati): deve aggiornarsi senza errori e senza perdere righe. */
$v1Path = $tmp . '/data/schema-v1.sqlite';
$v1 = new PDO('sqlite:' . $v1Path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$v1->exec("CREATE TABLE bookings (id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT NOT NULL UNIQUE, reference TEXT NOT NULL UNIQUE,
    product_key TEXT NOT NULL, product_name TEXT NOT NULL, amount_cents INTEGER NOT NULL, currency TEXT NOT NULL, first_name TEXT NOT NULL,
    last_name TEXT NOT NULL, email TEXT NOT NULL, phone TEXT NOT NULL, terms_accepted_at INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'pending',
    received_cents INTEGER, paid_tx_id TEXT, paid_at INTEGER, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)");
$v1->exec("CREATE TABLE revolut_events (id INTEGER PRIMARY KEY AUTOINCREMENT, body_hash TEXT NOT NULL UNIQUE, event TEXT NOT NULL, tx_id TEXT, received_at INTEGER NOT NULL)");
$v1->exec('CREATE TABLE revolut_transactions (tx_id TEXT PRIMARY KEY, state TEXT, state_event_ts INTEGER, amount_cents INTEGER, currency TEXT, reference_text TEXT, booking_id INTEGER, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)');
$v1->exec('CREATE TABLE rate_limits (bucket TEXT NOT NULL, ts INTEGER NOT NULL)');
$v1->exec("INSERT INTO bookings (token_hash, reference, product_key, product_name, amount_cents, currency, first_name, last_name, email, phone, terms_accepted_at, created_at, updated_at)
    VALUES ('h', 'HODVECCHIO', 'maltese', 'Caparra Maltese Toy', 40000, 'EUR', 'Anna', 'Bianchi', 'anna@example.com', '+39333', 1, 1, 1)");
$v1->exec('PRAGMA user_version = 1');
$v1 = null;
$migrated = hodbook_db_open($v1Path);
$old = $migrated->query("SELECT * FROM bookings WHERE reference = 'HODVECCHIO'")->fetch();
check('database con schema 1: migrato alla versione corrente', (int)$migrated->query('PRAGMA user_version')->fetchColumn() === HODBOOK_SCHEMA_VERSION);
check('database con schema 1: riga conservata, telefono intatto, nuovi campi vuoti', is_array($old) && $old['phone'] === '+39333'
    && $old['residence'] === '' && $old['tax_code'] === '' && $old['customer_email_sent_at'] === null);
[, $afterMigration] = hodbook_booking_create($migrated, $CFG, 'maltese', customer(), time());
check('database migrato: nuove prenotazioni con residenza e codice fiscale', $afterMigration['residence'] === 'Via Roma 12, 70022 Altamura (BA)' && $afterMigration['tax_code'] === CF_OK);
$old = null;
$afterMigration = null;
$migrated = null;

/* =================================================================== */
section('Configurazione');
$def = hodbook_config_normalize([]);
check('5 prodotti predefiniti', array_keys($def['products']) === ['barboncino', 'maltipoo', 'maltese', 'bassotto-arlecchino', 'bassotto-cioccolato']);
check('caparre predefinite', array_column($def['products'], 'amount_cents') === [55000, 45000, 40000, 55000, 45000]);
check('nome prodotto predefinito', $def['products']['bassotto-cioccolato']['name'] === 'Caparra Bassotto Kaninchen Cioccolato');
check('default EUR, HOD, Revolut, 300 s, WhatsApp', $def['currency'] === 'EUR' && $def['reference_prefix'] === 'HOD' && $def['bank_name'] === 'Revolut'
    && $def['timestamp_tolerance_seconds'] === 300 && $def['whatsapp_number'] === '393770876017' && $def['trust_proxy_headers'] === false);
check('senza dati: banca e webhook non configurati', !hodbook_bank_configured($def) && !hodbook_webhook_configured($def));
check('config di test: banca configurata', hodbook_bank_configured($CFG));
check('IBAN salvato senza spazi e maiuscolo', $CFG['bank_iban'] === TEST_IBAN);
check('BIC normalizzato', $CFG['bank_bic'] === 'REVOLT21');
check('segreto come stringa singola accettato', $CFG['revolut_webhook_secrets'] === [$secretA] && hodbook_webhook_configured($CFG));
$c = hodbook_config_normalize(['revolut_webhook_secrets' => [$secretA, ' ' . $secretB . ' ', 'sbagliato', '', 'wsk_x']]);
check('segreti multipli, quelli non validi scartati', $c['revolut_webhook_secrets'] === [$secretA, $secretB]);
$c = hodbook_config_normalize(['revolut_webhook_secrets' => "$secretA, $secretB"]);
check('segreti separati da virgola', $c['revolut_webhook_secrets'] === [$secretA, $secretB]);
$c = hodbook_config_normalize(['bank_account_holder' => 'X', 'bank_iban' => 'IT00X0000000000000000000000']);
check('IBAN con checksum errato: banca non configurata', !hodbook_bank_configured($c));
$c = hodbook_config_normalize(['bank_account_holder' => '', 'bank_iban' => TEST_IBAN]);
check('senza intestatario: banca non configurata', !hodbook_bank_configured($c));
$c = hodbook_config_normalize(['bank_bic' => 'NON VALIDO!']);
check('BIC non valido ignorato', $c['bank_bic'] === '');
$c = hodbook_config_normalize(['products' => ['solo' => ['name' => 'Caparra Test', 'amount_cents' => '1234'], 'Brutta Chiave' => ['name' => 'X', 'amount_cents' => 1], 'zero' => ['name' => 'Z', 'amount_cents' => 0]]]);
check('products sostituisce l\'elenco e scarta le voci non valide', $c['products'] === ['solo' => ['name' => 'Caparra Test', 'amount_cents' => 1234]]);
$c = hodbook_config_normalize(['reference_prefix' => 'h-o-d', 'currency' => 'eur', 'timestamp_tolerance_seconds' => 5, 'revolut_account_ids' => 'ACC-1, acc-2']);
check('prefisso, valuta, tolleranza minima, conti normalizzati', $c['reference_prefix'] === 'HOD' && $c['currency'] === 'EUR'
    && $c['timestamp_tolerance_seconds'] === 30 && $c['revolut_account_ids'] === ['acc-1', 'acc-2']);
check('email: valori predefiniti', $def['merchant_email'] === 'houseofdogdigiuseppe@outlook.it' && $def['mail_from_address'] === 'noreply@thehouseofdog.it'
    && $def['mail_from_name'] === 'House of Dogs' && $def['mail_mode'] === 'mail' && $def['site_url'] === 'https://www.thehouseofdog.it');
check('config di test: modalità file, site_url senza slash finale', $CFG['mail_mode'] === 'file' && $CFG['site_url'] === TEST_SITE_URL && $CFG['merchant_email'] === TEST_MERCHANT);
$c = hodbook_config_normalize(['merchant_email' => "x@example.com\r\nBcc: evil@example.com", 'mail_from_address' => 'non un indirizzo',
    'mail_mode' => 'smtp', 'site_url' => 'javascript:alert(1)', 'mail_from_name' => "Nome\r\nBcc: evil@example.com"]);
check('email: valori non validi tornano ai predefiniti', $c['merchant_email'] === 'houseofdogdigiuseppe@outlook.it' && $c['mail_from_address'] === 'noreply@thehouseofdog.it'
    && $c['mail_mode'] === 'mail' && $c['site_url'] === 'https://www.thehouseofdog.it');
check('email: a capo tolti dal nome del mittente', !str_contains($c['mail_from_name'], "\n") && !str_contains($c['mail_from_name'], "\r"));
check('email: modalità OFF e File accettate', hodbook_config_normalize(['mail_mode' => 'OFF'])['mail_mode'] === 'off' && hodbook_config_normalize(['mail_mode' => ' File '])['mail_mode'] === 'file');
check('telefono mostrato: +39 377 087 6017 dal numero WhatsApp', hodbook_phone_display($def) === '+39 377 087 6017');
check('telefono mostrato: contact_phone_display ha la precedenza', hodbook_phone_display(hodbook_config_normalize(['contact_phone_display' => '080 123 4567'])) === '080 123 4567');
check('telefono mostrato: numero estero', hodbook_phone_display(hodbook_config_normalize(['whatsapp_number' => '4915112345678'])) === '+4915112345678');

/* Più conti per il bonifico (bank_extra_accounts) */
$extraAcc = ['bank_account_holder' => 'Giuseppe Falcicchio', 'bank_iban' => 'IT42 A036 6901 6000 6701 2321 526', 'bank_bic' => 'revoitm2', 'bank_name' => 'Revolut'];
check('un solo conto: bank_accounts contiene il principale', $CFG['bank_accounts'] === [['holder' => 'TITOLARE DI PROVA', 'iban' => TEST_IBAN, 'bic' => 'REVOLT21', 'name' => 'Revolut']]);
$c = hodbook_config_normalize(['bank_account_holder' => 'TITOLARE DI PROVA', 'bank_iban' => TEST_IBAN, 'bank_extra_accounts' => [$extraAcc]]);
check('conto extra valido: secondo nell\'elenco, normalizzato', $c['bank_accounts'] === [
    ['holder' => 'TITOLARE DI PROVA', 'iban' => TEST_IBAN, 'bic' => '', 'name' => 'Revolut'],
    ['holder' => 'Giuseppe Falcicchio', 'iban' => IBAN_2, 'bic' => 'REVOITM2', 'name' => 'Revolut']]);
check('conto extra: le chiavi piatte restano quelle del conto principale', $c['bank_iban'] === TEST_IBAN && $c['bank_account_holder'] === 'TITOLARE DI PROVA'
    && $c['bank_name'] === 'Revolut' && hodbook_bank_configured($c));
$c = hodbook_config_normalize(['bank_account_holder' => 'A', 'bank_iban' => TEST_IBAN, 'bank_extra_accounts' => [
    array_merge($extraAcc, ['bank_iban' => 'IT42A0366901600067012321527']),
    array_merge($extraAcc, ['bank_account_holder' => '   ']),
    array_merge($extraAcc, ['bank_iban' => 'it60 x054 2811 1010 0000 0123 456']),
    'non un conto',
    null,
]]);
check('extra con IBAN non valido, intestatario vuoto, IBAN uguale al principale o non array: scartati',
    count($c['bank_accounts']) === 1 && $c['bank_accounts'][0]['iban'] === TEST_IBAN);
$c = hodbook_config_normalize(['bank_account_holder' => 'A', 'bank_iban' => TEST_IBAN, 'bank_extra_accounts' => [$extraAcc, array_merge($extraAcc, ['bank_account_holder' => 'Altro nome'])]]);
check('stesso IBAN in due conti extra: resta solo il primo', count($c['bank_accounts']) === 2 && $c['bank_accounts'][1]['holder'] === 'Giuseppe Falcicchio');
$c = hodbook_config_normalize(['bank_account_holder' => 'X', 'bank_iban' => 'IT00X0000000000000000000000', 'bank_extra_accounts' => [$extraAcc]]);
check('principale non valido + extra valido: prenotazione attiva con il solo extra', hodbook_bank_configured($c)
    && count($c['bank_accounts']) === 1 && $c['bank_accounts'][0]['iban'] === IBAN_2 && $c['bank_iban'] === 'IT00X0000000000000000000000');
$many = [];
foreach (['GB82WEST12345698765432', 'LT121000011101001000', 'DE89370400440532013000', 'FR1420041010050500013M02606', 'NL91ABNA0417164300'] as $i => $iban) {
    $many[] = ['bank_account_holder' => 'Conto prova ' . $i, 'bank_iban' => $iban];
}
$c = hodbook_config_normalize(['bank_account_holder' => 'A', 'bank_iban' => TEST_IBAN, 'bank_extra_accounts' => $many]);
check('al massimo 4 conti: il principale e i primi 3 extra validi', HODBOOK_MAX_BANK_ACCOUNTS === 4
    && array_column($c['bank_accounts'], 'iban') === [TEST_IBAN, 'GB82WEST12345698765432', 'LT121000011101001000', 'DE89370400440532013000']);
check('principale non valido: i primi 4 extra', array_column(hodbook_config_normalize(['bank_extra_accounts' => $many])['bank_accounts'], 'iban')
    === ['GB82WEST12345698765432', 'LT121000011101001000', 'DE89370400440532013000', 'FR1420041010050500013M02606']);
$c = hodbook_config_normalize(['bank_account_holder' => 'A', 'bank_iban' => TEST_IBAN, 'bank_extra_accounts' => array_merge($extraAcc, ['bank_bic' => 'NON VALIDO', 'bank_name' => ''])]);
check('un solo extra scritto senza la lista esterna; BIC non valido -> vuoto, banca vuota -> Revolut', count($c['bank_accounts']) === 2
    && $c['bank_accounts'][1]['bic'] === '' && $c['bank_accounts'][1]['name'] === 'Revolut');
check('bank_extra_accounts che non è una lista di conti: ignorato', hodbook_config_normalize(['bank_account_holder' => 'A', 'bank_iban' => TEST_IBAN, 'bank_extra_accounts' => 'IT42'])['bank_accounts']
    === [['holder' => 'A', 'iban' => TEST_IBAN, 'bic' => '', 'name' => 'Revolut']]);
check('nessun conto valido: banca non configurata', !hodbook_bank_configured(hodbook_config_normalize(['bank_extra_accounts' => [array_merge($extraAcc, ['bank_iban' => 'IT42'])]])));
check('configurazione non normalizzata: conti ricalcolati', count(hodbook_bank_accounts(['bank_account_holder' => 'A', 'bank_iban' => TEST_IBAN, 'bank_extra_accounts' => [$extraAcc]])) === 2);
$bankFile = require $siteDir . '/_prenotazioni/config/bank.php';
$realAccounts = is_array($bankFile) ? hodbook_config_normalize($bankFile)['bank_accounts'] : [];
check('bank.php del repository: ogni conto elencato è valido (nessuno scartato in silenzio)', is_array($bankFile) && $realAccounts !== []
    && count($realAccounts) === 1 + count(is_array($bankFile['bank_extra_accounts'] ?? null) ? $bankFile['bank_extra_accounts'] : []), count($realAccounts) . ' conti validi');

/* =================================================================== */
section('IBAN, BIC, importi');
check('IBAN italiano di esempio valido', hodbook_iban_valid(TEST_IBAN));
check('IBAN con spazi e minuscole valido', hodbook_iban_valid('it60 x054 2811 1010 0000 0123 456'));
check('IBAN GB di esempio valido', hodbook_iban_valid('GB82 WEST 1234 5698 7654 32'));
check('IBAN LT (formato Revolut) valido', hodbook_iban_valid('LT121000011101001000'));
check('checksum errato rifiutato', !hodbook_iban_valid('IT60X0542811101000000123457'));
check('cifre di controllo 00 rifiutate', !hodbook_iban_valid('IT00X0000000000000000000000'));
check('lunghezza sbagliata per l\'Italia rifiutata', !hodbook_iban_valid('IT60X054281110100000012345'));
check('testo qualsiasi rifiutato', !hodbook_iban_valid('non è un iban') && !hodbook_iban_valid(''));
check('IBAN a gruppi di 4', hodbook_iban_format(TEST_IBAN) === 'IT60 X054 2811 1010 0000 0123 456');
check('BIC 8 e 11 caratteri', hodbook_bic_valid('REVOLT21') && hodbook_bic_valid('BCITITMMXXX') && !hodbook_bic_valid('REVO21') && !hodbook_bic_valid('revolt21'));
check('importo 550,00 €', hodbook_format_amount(55000) === '550,00 €');
check('importo 1.250,05 €', hodbook_format_amount(125005) === '1.250,05 €');
check('importo da copiare 550,00', hodbook_amount_plain(55000) === '550,00' && hodbook_amount_plain(125005) === '1250,05' && hodbook_amount_plain(5) === '0,05');
check('centesimi dai numeri Revolut', hodbook_revolut_amount_cents(550) === 55000 && hodbook_revolut_amount_cents(550.5) === 55050
    && hodbook_revolut_amount_cents(0.29) === 29 && hodbook_revolut_amount_cents(-10) === -1000 && hodbook_revolut_amount_cents('12.34') === 1234
    && hodbook_revolut_amount_cents('x') === null && hodbook_revolut_amount_cents(null) === null && hodbook_revolut_amount_cents(INF) === null);

/* =================================================================== */
section('Codice di prenotazione (causale)');
$re = '/^HOD[' . HODBOOK_REF_ALPHABET . ']{6}\z/';
$codes = [];
$chars = '';
for ($i = 0; $i < 3000; $i++) {
    $code = hodbook_reference_generate('HOD');
    $codes[$code] = true;
    $chars .= substr($code, 3);
}
$allFormat = true;
foreach (array_keys($codes) as $code) {
    $allFormat = $allFormat && (bool)preg_match($re, $code);
}
check('formato prefisso + 6 caratteri, senza separatori', $allFormat);
check('alfabeto senza 0, O, 1, I', strlen(HODBOOK_REF_ALPHABET) === 32 && strpbrk(HODBOOK_REF_ALPHABET, '0O1I') === false);
check('nessun 0/O/1/I nei codici generati', strpbrk($chars, '0O1I') === false);
check('tutti i 32 caratteri usati', count(array_unique(str_split($chars))) === 32);
check('3000 codici tutti diversi', count($codes) === 3000, (string)count($codes));
check('estrazione: maiuscole e minuscole', hodbook_reference_extract('hod7k3f9q', 'HOD') === ['HOD7K3F9Q']);
check('estrazione: spazi, trattini e parole in più', hodbook_reference_extract('Caparra hod-7k3 f9q - Rossi Maria', 'HOD') === ['HOD7K3F9Q']);
check('estrazione: punti e barre', hodbook_reference_extract('rif. H.O.D/7K3F9Q', 'HOD') === ['HOD7K3F9Q']);
check('estrazione: nessun codice', hodbook_reference_extract('caparra cucciolo maltese', 'HOD') === []);
check('estrazione: codice troppo corto', hodbook_reference_extract('HOD7K3F', 'HOD') === []);
check('estrazione: carattere escluso (0) non valido', hodbook_reference_extract('HOD7K3F0Q', 'HOD') === []);
check('estrazione: due codici in ordine', hodbook_reference_extract('HODABCDEF poi hod 7k3f9q', 'HOD') === ['HODABCDEF', 'HOD7K3F9Q']);
check('estrazione: prefisso dentro una parola seguito da caratteri esclusi', hodbook_reference_extract('METHODS HOD7K3F9Q', 'HOD') === ['HOD7K3F9Q']);
check('estrazione: ogni posizione del prefisso viene provata', hodbook_reference_extract('methods 22222 e HOD7K3F9Q', 'HOD') === ['HODS22222', 'HOD7K3F9Q']);
check('estrazione: doppioni rimossi', hodbook_reference_extract('HOD7K3F9Q HOD7K3F9Q', 'HOD') === ['HOD7K3F9Q']);

/* =================================================================== */
section('Validazione del modulo');
$good = ['nome' => ' Maria ', 'cognome' => "D\u{2019}Angelo-Rossi", 'email' => 'Maria.Rossi@Example.COM', 'telefono' => '+39 333.123-4567',
    'residenza' => "  Via San Michele, 4/B\r\n  70022   Altamura (BA)  ", 'codice_fiscale' => ' rss mra 80a01 h501u ', 'termini' => '1', 'tipo' => 'maltese'];
[$d, $errs] = hodbook_validate_booking_form($good, $CFG['products']);
check('dati validi accettati', $errs === [], json_encode($errs, JSON_UNESCAPED_UNICODE));
check('nome ripulito', $d['first_name'] === 'Maria');
check('apostrofo tipografico normalizzato', $d['last_name'] === "D'Angelo-Rossi");
check('email in minuscolo', $d['email'] === 'maria.rossi@example.com');
check('telefono normalizzato', $d['phone'] === '+393331234567');
check('residenza su una riga, spazi compressi', $d['residence'] === 'Via San Michele, 4/B 70022 Altamura (BA)', (string)$d['residence']);
check('codice fiscale senza spazi e maiuscolo', $d['tax_code'] === CF_OK);
check('prodotto', $d['product_key'] === 'maltese');
$bad = function (array $over) use ($good): array {
    return hodbook_validate_booking_form(array_merge($good, $over), hodbook_config()['products'])[1];
};
check('nome di 1 carattere rifiutato', isset($bad(['nome' => 'M'])['nome']));
check('nome con cifre rifiutato', isset($bad(['nome' => 'Mar1a'])['nome']));
check('nome con simboli rifiutato', isset($bad(['nome' => 'Maria<script>'])['nome']));
check('nome di 101 caratteri rifiutato', isset($bad(['nome' => str_repeat('a', 101)])['nome']));
check('nome di 100 caratteri accettato', !isset($bad(['nome' => str_repeat('a', 100)])['nome']));
check('nome accentato accettato', !isset($bad(['nome' => 'Niccolò Ánne'])['nome']));
check('nome solo trattini rifiutato', isset($bad(['nome' => "-'-"])['nome']));
check('cognome mancante rifiutato', isset($bad(['cognome' => ''])['cognome']));
check('campo non stringa rifiutato', isset($bad(['cognome' => ['x']])['cognome']));
check('email non valida rifiutata', isset($bad(['email' => 'maria@'])['email']));
check('email non ASCII rifiutata', isset($bad(['email' => 'mària@example.com'])['email']));
check('telefono troppo corto rifiutato', isset($bad(['telefono' => '12345'])['telefono']));
check('telefono di 16 cifre rifiutato', isset($bad(['telefono' => '1234567890123456'])['telefono']));
check('telefono con lettere rifiutato', isset($bad(['telefono' => '333 abc 4567'])['telefono']));
check('telefono con doppio + rifiutato', isset($bad(['telefono' => '++393331234567'])['telefono']));
check('telefono 6 cifre accettato', !isset($bad(['telefono' => '123456'])['telefono']));
check('telefono mancante rifiutato', isset($bad(['telefono' => ''])['telefono']));
check('condizioni non accettate rifiutate', isset($bad(['termini' => ''])['termini']));
check('prodotto sconosciuto rifiutato', isset($bad(['tipo' => 'gatto'])['tipo']));
check('UTF-8 non valido rifiutato', isset($bad(['nome' => "Mar\xC3\x28a"])['nome']));
check('email con virgola o virgolette rifiutata (finirebbe in un\'intestazione)', isset($bad(['email' => 'a,b@example.com'])['email']) && isset($bad(['email' => '"a b"@example.com'])['email']));
check('email con apostrofo accettata', !isset($bad(['email' => "o'brien@example.com"])['email']));

/* Residenza */
$res = static fn(string $v): array => hodbook_validate_residence($v);
check('residenza: indirizzo tipico', $res('Via Roma 12, 70022 Altamura (BA)') === ['Via Roma 12, 70022 Altamura (BA)', null]);
check('residenza: a capo e spazi diventano uno spazio', $res("Via Roma 12\n\n70022    Altamura") === ['Via Roma 12 70022 Altamura', null]);
check('residenza: segni ammessi . \' ’ / - ( ) °', $res("P.zza dell'Unità d\u{2019}Italia 3/A - int. 2 (piano 1°), 00100 Roma")[1] === null);
check('residenza: lettere accentate e straniere', $res('Straße München 5, 80331 München')[1] === null && $res('Via Niccolò 9, Forlì')[1] === null);
check('residenza: vuota rifiutata', $res('   ')[0] === null && $res('')[1] === 'Inserisci la residenza.');
check('residenza: meno di 10 caratteri rifiutata', $res('Via 1 Bari')[0] !== null && $res('Via 1 Bar')[0] === null);
check('residenza: 200 caratteri accettati, 201 rifiutati', $res(str_repeat('a', 199) . '1')[1] === null && $res(str_repeat('a', 200) . '1')[0] === null);
check('residenza: senza numeri rifiutata', $res('Via Roma, Altamura')[0] === null);
check('residenza: senza lettere rifiutata', $res('12345, 67890 (00)')[0] === null);
check('residenza: simboli non ammessi rifiutati', $res('Via Roma 12; Altamura')[0] === null && $res('Via <b>Roma</b> 12')[0] === null
    && $res('Via Roma 12 #3 Bari')[0] === null && $res('Via Roma 12, 70022 Altamura & Co')[0] === null);
check('residenza: cifre non latine rifiutate', $res("Via Roma \u{0661}\u{0662}, Altamura")[0] === null);
check('residenza nel modulo: errore per campo', isset($bad(['residenza' => 'Bari'])['residenza']));

/* Codice fiscale */
$cfValid = [
    CF_OK               => 'esempio pubblicato (uomo)',
    'CCCFBA85D03L219P'  => 'esempio pubblicato',
    CF_OK_2             => 'donna, giorno + 40',
    'BNCLRA92M71A662H'  => 'donna, giorno 71 (limite)',
    'CCCFBA85D03L21VE'  => 'omocodia su una cifra',
    'RSSMRA80A01H5LMX'  => 'omocodia su due cifre',
    'RSSMRAULA01H501C'  => 'omocodia sull\'anno',
    'RSSMRAULALMHRLMD'  => 'omocodia su tutte e sette le cifre',
];
foreach ($cfValid as $code => $what) {
    check('codice fiscale valido: ' . $code . ' (' . $what . ')', hodbook_tax_code_valid($code) && hodbook_validate_tax_code($code) === [$code, null]);
}
check('codice fiscale minuscolo e con spazi normalizzato', hodbook_validate_tax_code("  ccc fba 85d03\tl219p ") === ['CCCFBA85D03L219P', null]);
$cfMsg = 'Codice fiscale non valido: controlla i 16 caratteri.';
$cfInvalid = [
    'RSSMRA80A01H501X'  => 'carattere di controllo sbagliato',
    'RSSMRA80A01H501'   => '15 caratteri',
    'RSSMRA80A01H501UU' => '17 caratteri',
    '03933680401'       => 'partita IVA / codice fiscale di società (11 cifre)',
    'RSSMRA80Z01H501U'  => 'mese inesistente',
    'RSSMRA8AA01H501U'  => 'lettera non di omocodia in una posizione numerica',
    'RSSMRA80A32H501C'  => 'giorno 32 (carattere di controllo corretto)',
    'RSSMRA80A00H501?'  => 'carattere non ammesso',
];
foreach ($cfInvalid as $code => $what) {
    check('codice fiscale rifiutato: ' . $what, !hodbook_tax_code_valid($code) && hodbook_validate_tax_code($code) === [null, $cfMsg]);
}
check('codice fiscale vuoto: messaggio dedicato', hodbook_validate_tax_code('') === [null, 'Inserisci il codice fiscale.']);
check('codice fiscale nel modulo: errore per campo', ($bad(['codice_fiscale' => 'RSSMRA80A01H501X'])['codice_fiscale'] ?? '') === $cfMsg);

/* =================================================================== */
section('CSRF senza sessione');
$now = time();
$secret = hodbook_app_secret();
check('segreto applicativo creato in data/', is_file(HODBOOK_DATA_DIR . '/app-secret.php') && strlen($secret) === 64);
check('segreto stabile tra le letture', hodbook_read_app_secret(HODBOOK_DATA_DIR . '/app-secret.php') === $secret);
check('file del segreto non stampa nulla se eseguito', (static function (string $f): string { ob_start(); include $f; return (string)ob_get_clean(); })(HODBOOK_DATA_DIR . '/app-secret.php') === '');
$tok = hodbook_form_token($now - 10);
check('token formato timestamp.firma', (bool)preg_match('/^\d{10}\.[a-f0-9]{64}$/', $tok));
check('token valido (10 s)', hodbook_form_token_check($tok, $now) === 'ok');
check('token valido al limite di 2 s', hodbook_form_token_check(hodbook_form_token($now - 2), $now) === 'ok');
check('token valido al limite di 2 ore', hodbook_form_token_check(hodbook_form_token($now - 7200), $now) === 'ok');
check('token troppo recente (1 s)', hodbook_form_token_check(hodbook_form_token($now - 1), $now) === 'too_fresh');
check('token dal futuro = troppo recente', hodbook_form_token_check(hodbook_form_token($now + 60), $now) === 'too_fresh');
check('token scaduto (2 ore + 1 s)', hodbook_form_token_check(hodbook_form_token($now - 7201), $now) === 'expired');
[$ts, $sig] = explode('.', $tok);
check('firma alterata rifiutata', hodbook_form_token_check($ts . '.' . str_repeat('0', 64), $now) === 'invalid');
check('timestamp alterato rifiutato', hodbook_form_token_check(((int)$ts - 100) . '.' . $sig, $now) === 'invalid');
check('token di un altro segreto rifiutato', hodbook_form_token_check(hodbook_form_token($now - 10, str_repeat('ab', 32)), $now) === 'invalid');
check('token vuoto, malformato o non stringa rifiutato', hodbook_form_token_check('', $now) === 'invalid' && hodbook_form_token_check('abc', $now) === 'invalid'
    && hodbook_form_token_check(null, $now) === 'invalid' && hodbook_form_token_check([$tok], $now) === 'invalid' && hodbook_form_token_check($tok . "\n", $now) === 'invalid');

$_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
check('POST cross-site rifiutato', !hodbook_same_origin_check());
$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
check('POST same-origin accettato', hodbook_same_origin_check());
unset($_SERVER['HTTP_SEC_FETCH_SITE']);
$_SERVER['HTTP_ORIGIN'] = 'https://evil.test';
check('Origin di un altro host rifiutato', !hodbook_same_origin_check());
$_SERVER['HTTP_ORIGIN'] = 'https://example.test';
check('Origin dello stesso host accettato', hodbook_same_origin_check());
unset($_SERVER['HTTP_ORIGIN']);
check('senza intestazioni accettato', hodbook_same_origin_check());

/* =================================================================== */
section('Limite di frequenza');
$bucket = hodbook_ip_bucket('prenota', '203.0.113.7');
check('IP salvato solo come hash', strlen($bucket) === 64 && !str_contains($bucket, '203.0.113.7') && $bucket !== hash('sha256', '203.0.113.7'));
check('hash diverso per IP diversi', $bucket !== hodbook_ip_bucket('prenota', '203.0.113.8'));
check('IPv6 raggruppati per /64', hodbook_ip_bucket('prenota', '2001:db8:1:2::1') === hodbook_ip_bucket('prenota', '2001:db8:1:2:ffff::9')
    && hodbook_ip_bucket('prenota', '2001:db8:1:2::1') !== hodbook_ip_bucket('prenota', '2001:db8:1:3::1'));
$allowed = 0;
for ($i = 0; $i < 10; $i++) {
    if (hodbook_rate_limit($DB, $bucket, 8, 3600, $now)) {
        $allowed++;
    }
}
check('8 invii consentiti su 10 nella stessa ora', $allowed === 8, (string)$allowed);
check('altro IP non bloccato', hodbook_rate_limit($DB, hodbook_ip_bucket('prenota', '198.51.100.1'), 8, 3600, $now));
check('dopo un\'ora di nuovo consentito', hodbook_rate_limit($DB, $bucket, 8, 3600, $now + 3601));

/* =================================================================== */
section('Creazione della prenotazione');
[$token, $b] = hodbook_booking_create($DB, $CFG, 'barboncino', customer(), $now);
check('prenotazione in attesa', $b['status'] === 'pending' && $b['received_cents'] === null && $b['paid_at'] === null && $b['paid_tx_id'] === null);
check('dati del cliente salvati (telefono, residenza, codice fiscale)', $b['phone'] === '+393331234567' && $b['residence'] === 'Via Roma 12, 70022 Altamura (BA)'
    && $b['tax_code'] === CF_OK && $b['customer_email_sent_at'] === null);
check('nome prodotto e caparra copiati', $b['product_key'] === 'barboncino' && $b['product_name'] === 'Caparra Barboncino Toy' && (int)$b['amount_cents'] === 55000 && $b['currency'] === 'EUR');
check('accettazione delle condizioni registrata', (int)$b['terms_accepted_at'] === $now);
check('token 64 esadecimali', hodbook_valid_token_format($token));
check('token mai salvato in chiaro', $b['token_hash'] === hash('sha256', $token) && count_rows('SELECT COUNT(*) FROM bookings WHERE token_hash = :t', [':t' => $token]) === 0);
check('codice nel formato giusto', (bool)preg_match($re, (string)$b['reference']));
check('ricerca per token', (hodbook_booking_by_token($DB, $token)['id'] ?? null) === $b['id']);
check('token sconosciuto o malformato -> null', hodbook_booking_by_token($DB, str_repeat('0', 64)) === null && hodbook_booking_by_token($DB, 'abc') === null
    && hodbook_booking_by_token($DB, strtoupper($token)) === null);
$cfgChanged = $CFG;
$cfgChanged['products']['barboncino'] = ['name' => 'Caparra Barboncino Toy NUOVA', 'amount_cents' => 99900];
new_booking();
check('cambiare la config non altera le prenotazioni esistenti', ($row = bk($b)) && $row['product_name'] === 'Caparra Barboncino Toy' && (int)$row['amount_cents'] === 55000);
[, $b2] = hodbook_booking_create($DB, $cfgChanged, 'barboncino', customer(['first_name' => 'Luca', 'email' => 'l@example.com']), $now);
check('le nuove prenotazioni usano la config nuova', (int)$b2['amount_cents'] === 99900);
$queue = [(string)$b['reference'], (string)$b['reference'], 'HODZZZZZ2'];
[, $b3] = hodbook_booking_create($DB, $CFG, 'maltese', customer(['first_name' => 'Anna', 'email' => 'a@example.com']), $now,
    static function () use (&$queue): string { return array_shift($queue); });
check('collisione del codice: si riprova con un altro', $b3['reference'] === 'HODZZZZZ2' && $queue === []);
$threw = false;
try {
    hodbook_booking_create($DB, $CFG, 'gatto', customer(), $now);
} catch (InvalidArgumentException) {
    $threw = true;
}
check('prodotto sconosciuto: eccezione', $threw);

/* =================================================================== */
section('Limite di prenotazioni per email');
$limitMail = 'limite@example.com';
$made = [];
for ($i = 0; $i < 3; $i++) {
    $made[] = hodbook_booking_create_limited($DB, $CFG, 'maltese', customer(['email' => $limitMail]), $now);
}
check('3 prenotazioni con la stessa email nelle 24 ore: consentite', count(array_filter($made)) === 3);
$before = count_rows('SELECT COUNT(*) FROM bookings');
$fourth = hodbook_booking_create_limited($DB, $CFG, 'maltese', customer(['email' => 'LIMITE@Example.com']), $now);
check('4ª prenotazione (email con maiuscole diverse): rifiutata, nulla creato', $fourth === null && count_rows('SELECT COUNT(*) FROM bookings') === $before);
check('altra email: consentita', hodbook_booking_create_limited($DB, $CFG, 'maltese', customer(['email' => 'altra@example.com']), $now) !== null);
$DB->prepare('UPDATE bookings SET created_at = :t WHERE id = :id')->execute([':t' => $now - 86401, ':id' => $made[0][1]['id']]);
check('dopo 24 ore la prenotazione più vecchia non conta più', hodbook_booking_create_limited($DB, $CFG, 'maltese', customer(['email' => $limitMail]), $now) !== null);
check('...e il limite torna subito pieno', hodbook_booking_create_limited($DB, $CFG, 'maltese', customer(['email' => $limitMail]), $now) === null);

/* =================================================================== */
section('Email: costruzione del messaggio e protezione delle intestazioni');
$built = hodbook_mail_build($CFG, ['to' => 'cliente@example.com', 'reply_to' => TEST_MERCHANT,
    'subject' => 'Abbiamo ricevuto la tua richiesta di prenotazione (HOD7K3F9Q) - caparra già pronta, città: Forlì', 'body' => "Riga 1 àèìòù €\nRiga 2 " . str_repeat('lunga ', 40)], $now);
check('From sul dominio del sito con nome tra virgolette', $built['headers']['From'] === '"House of Dogs" <noreply@thehouseofdog.it>');
check('Reply-To, MIME, testo UTF-8, base64', $built['headers']['Reply-To'] === TEST_MERCHANT && $built['headers']['MIME-Version'] === '1.0'
    && $built['headers']['Content-Type'] === 'text/plain; charset=UTF-8' && $built['headers']['Content-Transfer-Encoding'] === 'base64');
check('Date in formato RFC 2822', (bool)preg_match('/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} [+\-]\d{4}$/', $built['headers']['Date']));
check('Message-ID sul dominio del mittente', (bool)preg_match('/^<[a-f0-9]{24}\.\d+@thehouseofdog\.it>$/', $built['headers']['Message-ID']));
check('mittente di busta -f solo con indirizzo "stretto"', $built['params'] === '-fnoreply@thehouseofdog.it'
    && hodbook_mail_build(array_merge($CFG, ['mail_from_address' => "o'brien@example.com"]), ['to' => 'a@example.com', 'subject' => 'x', 'body' => 'y'], $now)['params'] === '');
$lines = explode("\r\n", $built['body']);
check('corpo base64 a righe di massimo 76 caratteri', max(array_map('strlen', $lines)) <= 76 && count($lines) > 1);
check('corpo decodificato identico, a capo CRLF', base64_decode(implode('', $lines)) === "Riga 1 àèìòù €\r\nRiga 2 " . str_repeat('lunga ', 40));
$words = preg_split('/\r\n /', $built['subject']) ?: [];
check('oggetto in encoded-word UTF-8 di massimo 75 caratteri', count($words) > 1 && max(array_map('strlen', $words)) <= 75
    && count(array_filter($words, static fn(string $w): bool => (bool)preg_match('/^=\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=$/', $w))) === count($words));
check('oggetto decodificato identico', mime_decode($built['subject']) === 'Abbiamo ricevuto la tua richiesta di prenotazione (HOD7K3F9Q) - caparra già pronta, città: Forlì');
$utfName = hodbook_mail_build(array_merge($CFG, ['mail_from_name' => "Casa dei Cuccioli \u{2013} Altamura"]), ['to' => 'a@example.com', 'subject' => 'x', 'body' => 'y'], $now);
check('nome del mittente non ASCII codificato', str_starts_with($utfName['headers']['From'], '=?UTF-8?B?') && str_ends_with($utfName['headers']['From'], ' <noreply@thehouseofdog.it>')
    && mime_decode(substr($utfName['headers']['From'], 0, (int)strrpos($utfName['headers']['From'], ' <'))) === "Casa dei Cuccioli \u{2013} Altamura");
$quoted = hodbook_mail_build(array_merge($CFG, ['mail_from_name' => 'House "of" Dogs']), ['to' => 'a@example.com', 'subject' => 'x', 'body' => 'y'], $now);
check('virgolette nel nome del mittente protette', $quoted['headers']['From'] === '"House \"of\" Dogs" <noreply@thehouseofdog.it>');
$rejects = 0;
foreach (["a@example.com\r\nBcc: evil@example.com", "a@example.com\nBcc: evil@example.com", 'a@example.com, evil@example.com', 'Evil <evil@example.com>', ''] as $badTo) {
    foreach (['to', 'reply_to'] as $field) {
        try {
            hodbook_mail_build($CFG, array_merge(['to' => 'ok@example.com', 'subject' => 'x', 'body' => 'y'], [$field => $badTo === '' && $field === 'reply_to' ? 'x' : $badTo]), $now);
        } catch (InvalidArgumentException) {
            $rejects++;
        }
    }
}
check('destinatario o Reply-To con a capo, virgole o nomi: messaggio rifiutato', $rejects === 10, (string)$rejects);
$captured = [];
$fakeMail = static function (...$args) use (&$captured): bool {
    $captured = $args;
    return true;
};
check('trasporto mail(): argomenti nel formato di mail() con -f', hodbook_mail_transport_mail($built, $fakeMail) && count($captured) === 5
    && $captured[0] === 'cliente@example.com' && $captured[1] === $built['subject'] && $captured[2] === $built['body']
    && is_array($captured[3]) && !isset($captured[3]['To']) && !isset($captured[3]['Subject']) && $captured[4] === '-fnoreply@thehouseofdog.it');
$noF = $built;
$noF['params'] = '';
check('trasporto mail(): senza -f quando il mittente non è "stretto"', hodbook_mail_transport_mail($noF, $fakeMail) && count($captured) === 4);
check('trasporto mail(): mail() che rifiuta = false', !hodbook_mail_transport_mail($built, static fn(...$a): bool => false));

/* =================================================================== */
section('Email della prenotazione (modalità file)');
$customerData = customer(['first_name' => 'Giulia', 'last_name' => "D'Amico", 'email' => 'giulia.damico@example.com', 'phone' => '+393401112233',
    'residence' => 'Corso Vittorio Emanuele 101, 70122 Bari (BA)', 'tax_code' => CF_OK_2]);
[$mailToken, $mb] = hodbook_booking_create($DB, $CFG, 'bassotto-arlecchino', $customerData, $now);
$ref = (string)$mb['reference'];
$sent = hodbook_booking_send_emails($CFG, $mb, $mailToken, $now, $DB);
$files = outbox($tmp, $ref);
check('due email consegnate', $sent === ['merchant' => true, 'customer' => true]);
check('esattamente due file .eml in data/outbox per la prenotazione', count($files) === 2, (string)count($files));
check('invio registrato nella prenotazione', (int)bk($mb)['customer_email_sent_at'] === $now);
$merchantFile = array_values(array_filter($files, static fn(string $f): bool => str_contains($f, '-esercente-')))[0] ?? '';
$customerFile = array_values(array_filter($files, static fn(string $f): bool => str_contains($f, '-cliente-')))[0] ?? '';
$m = eml((string)@file_get_contents($merchantFile));
$cu = eml((string)@file_get_contents($customerFile));
$link = TEST_SITE_URL . '/prenotazione.php?c=' . $mailToken;
check('esercente: To, Reply-To = cliente, From', hdr($m, 'To') === TEST_MERCHANT && hdr($m, 'Reply-To') === 'giulia.damico@example.com'
    && hdr($m, 'From') === '"House of Dogs" <noreply@thehouseofdog.it>');
check('esercente: oggetto con prodotto, nome e codice', mime_decode(hdr($m, 'Subject')) === "Nuova richiesta di prenotazione: Caparra Bassotto Kaninchen Arlecchino - Giulia D'Amico ($ref)");
check('esercente: tutti e sei i dati del cliente', str_contains($m['body'], "Nome: Giulia\r\n") && str_contains($m['body'], "Cognome: D'Amico\r\n")
    && str_contains($m['body'], "Email: giulia.damico@example.com\r\nTelefono: +393401112233\r\n")
    && str_contains($m['body'], "Residenza: Corso Vittorio Emanuele 101, 70122 Bari (BA)\r\n") && str_contains($m['body'], 'Codice fiscale: ' . CF_OK_2 . "\r\n"));
check('esercente: caparra, codice, date, link e nota sui bonifici istantanei', str_contains($m['body'], "Cucciolo: Caparra Bassotto Kaninchen Arlecchino\r\nCaparra: 550,00 €\r\nCodice di prenotazione (causale): $ref\r\n")
    && str_contains($m['body'], 'Condizioni di vendita accettate il: ' . date('d/m/Y H:i', $now)) && str_contains($m['body'], 'Richiesta inviata il: ' . date('d/m/Y H:i', $now))
    && str_contains($m['body'], "Pagina della prenotazione (stato del bonifico):\r\n" . $link . "\r\n")
    && str_contains($m['body'], 'Al cliente abbiamo indicato le coordinate bancarie e che sono accettati solo bonifici istantanei.'));
check('cliente: To = cliente, Reply-To = esercente', hdr($cu, 'To') === 'giulia.damico@example.com' && hdr($cu, 'Reply-To') === TEST_MERCHANT);
check('cliente: oggetto con il codice', mime_decode(hdr($cu, 'Subject')) === "Abbiamo ricevuto la tua richiesta di prenotazione ($ref)");
check('cliente: saluto e riepilogo', str_starts_with($cu['body'], "Ciao Giulia,\r\n\r\nabbiamo ricevuto la tua richiesta di prenotazione. Ecco il riepilogo.\r\n")
    && str_contains($cu['body'], "Codice di prenotazione: $ref\r\n"));
check('cliente: dati per il bonifico con IBAN a gruppi, BIC, importo e causale', str_contains($cu['body'],
    "DATI PER IL BONIFICO\r\nIntestatario: TITOLARE DI PROVA\r\nIBAN: IT60 X054 2811 1010 0000 0123 456\r\nBIC: REVOLT21\r\nBanca: Revolut\r\nImporto: 550,00 €\r\nCausale: $ref\r\n"));
check('cliente: regola dei bonifici istantanei, testuale', str_contains($cu['body'], "IMPORTANTE\r\nNon sono accettati bonifici ordinari: la caparra va versata solo con bonifico istantaneo.\r\nScrivi nella causale solo il codice di prenotazione, senza altro testo.\r\n"));
check('cliente: link assoluto su site_url, condizioni, contatti, firma', str_contains($cu['body'], "da questa pagina:\r\n" . $link . "\r\n")
    && str_contains($cu['body'], "Condizioni di vendita:\r\n" . TEST_SITE_URL . "/termini.php\r\n")
    && str_contains($cu['body'], 'scrivici su WhatsApp al +39 377 087 6017.') && str_ends_with($cu['body'], "The House Of Dog\r\n\r\n"));
check('cliente: niente dati che non lo riguardano', !str_contains($cu['body'], CF_OK_2) && !str_contains($cu['body'], 'Residenza'));
$noBic = $CFG;
$noBic['bank_accounts'][0]['bic'] = '';
[, $noBicCustomer] = hodbook_booking_messages($noBic, $mb, $mailToken);
check('cliente: riga BIC solo se configurato', !str_contains($noBicCustomer['body'], 'BIC:') && str_contains($noBicCustomer['body'], "IBAN: IT60 X054 2811 1010 0000 0123 456\nBanca: Revolut\n"));

/* Più conti: il cliente ne sceglie uno; con un conto solo il testo resta quello di sempre. */
[$oneMerchant, $oneCustomer] = hodbook_booking_messages($CFG, $mb, $mailToken);
check('un conto: nessuna traccia della scelta tra conti nelle email', !str_contains($oneCustomer['body'], 'Conto 1') && !str_contains($oneCustomer['body'], 'a tua scelta')
    && !str_contains($oneMerchant['body'], 'conti:'));
$twoCfg = $CFG;
$twoCfg['bank_accounts'][] = ['holder' => 'Giuseppe Falcicchio', 'iban' => IBAN_2, 'bic' => 'REVOITM2', 'name' => 'Revolut'];
[$twoMerchant, $twoCustomer] = hodbook_booking_messages($twoCfg, $mb, $mailToken);
check('due conti, email al cliente: Conto 1 e Conto 2, poi importo e causale una volta sola', str_contains($twoCustomer['body'],
    "DATI PER IL BONIFICO\nPuoi fare il bonifico su uno di questi conti, a tua scelta: basta un solo bonifico.\n\n"
    . "Conto 1\nIntestatario: TITOLARE DI PROVA\nIBAN: IT60 X054 2811 1010 0000 0123 456\nBIC: REVOLT21\nBanca: Revolut\n\n"
    . "Conto 2\nIntestatario: Giuseppe Falcicchio\nIBAN: IT42 A036 6901 6000 6701 2321 526\nBIC: REVOITM2\nBanca: Revolut\n\n"
    . "Importo: 550,00 €\nCausale: $ref\n\nIMPORTANTE\n")
    && substr_count($twoCustomer['body'], 'Importo: ') === 1 && substr_count($twoCustomer['body'], 'Causale: ') === 1);
$cutBank = static fn(string $body): string => preg_replace('/DATI PER IL BONIFICO\n.*?\nCausale: [^\n]*\n/s', '[DATI]', $body) ?? '';
check('due conti, email al cliente: oggetto e resto del testo identici al caso a un conto', $twoCustomer['subject'] === $oneCustomer['subject']
    && $cutBank($twoCustomer['body']) === $cutBank($oneCustomer['body']) && $cutBank($oneCustomer['body']) !== $oneCustomer['body']);
check('due conti, email all\'esercente: solo una riga in più con il numero dei conti', $twoMerchant['subject'] === $oneMerchant['subject']
    && $twoMerchant['body'] === $oneMerchant['body'] . "Al cliente sono stati indicati 2 conti: può fare il bonifico su uno a sua scelta.\n");
$threeCfg = $twoCfg;
$threeCfg['bank_accounts'][] = ['holder' => 'Terzo Conto', 'iban' => 'GB82WEST12345698765432', 'bic' => '', 'name' => 'Altra Banca'];
[$threeMerchant, $threeCustomer] = hodbook_booking_messages($threeCfg, $mb, $mailToken);
check('tre conti: Conto 3 senza riga BIC, e "3 conti" all\'esercente', str_contains($threeCustomer['body'], "\n\nConto 3\nIntestatario: Terzo Conto\nIBAN: GB82 WEST 1234 5698 7654 32\nBanca: Altra Banca\n\nImporto: ")
    && str_contains($threeMerchant['body'], 'Al cliente sono stati indicati 3 conti'));

/* =================================================================== */
section('Pagina: riquadro "Dati per il bonifico"');
/* HTML del riquadro con un solo conto, catturato dalla versione precedente: deve restare identico byte per byte. */
$goldenSingle = <<<'HTML'
<section class="box mt-6" aria-labelledby="bonifico"><h2 id="bonifico">Dati per il bonifico</h2><p class="rule mt-4" id="regola-istantaneo">Non sono accettati bonifici ordinari: la caparra va versata solo con bonifico istantaneo.</p><p class="mt-3 text-sm">Il bonifico istantaneo arriva in pochi secondi: la conferma compare su questa pagina.</p><p class="mt-1 text-sm muted">Copia questi dati nell'app o nel sito della tua banca.</p><dl class="kv mt-4"><div><dt><span>Intestatario</span><button type="button" class="copy-btn" data-copy="TITOLARE DI PROVA" aria-label="Copia intestatario" hidden>Copia</button></dt><dd><span class="val copyable">TITOLARE DI PROVA</span></dd></div><div><dt><span>IBAN</span><button type="button" class="copy-btn" data-copy="IT60X0542811101000000123456" aria-label="Copia IBAN" hidden>Copia</button></dt><dd><span class="val copyable val-iban">IT60 X054 2811 1010 0000 0123 456</span></dd></div><div><dt><span>BIC</span><button type="button" class="copy-btn" data-copy="REVOLT21" aria-label="Copia BIC" hidden>Copia</button></dt><dd><span class="val copyable">REVOLT21</span></dd></div><div><dt><span>Banca</span><button type="button" class="copy-btn" data-copy="Revolut" aria-label="Copia banca" hidden>Copia</button></dt><dd><span class="val copyable">Revolut</span></dd></div><div><dt><span>Importo</span><button type="button" class="copy-btn" data-copy="550,00" aria-label="Copia importo" hidden>Copia</button></dt><dd><span class="val copyable nowrap">550,00 €</span></dd></div><div><dt><span>Causale</span><button type="button" class="copy-btn" data-copy="HODGLDN22" aria-label="Copia causale" hidden>Copia</button></dt><dd><span class="val copyable val-code">HODGLDN22</span></dd></div></dl><p class="warn mt-5">Scrivi nella causale solo questo codice, senza altro testo: è quello che ci permette di riconoscere il tuo bonifico.</p></section>
HTML;
const AUTO_SENTENCE = 'Il bonifico istantaneo arriva in pochi secondi: la conferma compare su questa pagina.';
check('un conto, con webhook: HTML identico alla versione precedente', hodbook_bank_section($CFG['bank_accounts'], '550,00 €', '550,00', 'HODGLDN22', true) === $goldenSingle);
check('un conto, senza webhook: identico salvo la frase sull\'arrivo (come prima)', hodbook_bank_section($CFG['bank_accounts'], '550,00 €', '550,00', 'HODGLDN22', false)
    === str_replace(AUTO_SENTENCE, 'Il bonifico istantaneo arriva sul nostro conto in pochi secondi.', $goldenSingle));
check('un conto: nessuno stile in più nella pagina', hodbook_bank_section_css($CFG['bank_accounts']) === '');
$twoAccounts = $twoCfg['bank_accounts'];
$html2 = hodbook_bank_section($twoAccounts, '550,00 €', '550,00', 'HODGLDN22', false);
check('due conti: entrambi gli IBAN, a gruppi e da copiare senza spazi', str_contains($html2, '>IT60 X054 2811 1010 0000 0123 456<') && str_contains($html2, '>IT42 A036 6901 6000 6701 2321 526<')
    && str_contains($html2, 'data-copy="' . TEST_IBAN . '"') && str_contains($html2, 'data-copy="' . IBAN_2 . '"'));
check('due conti: titoli h3 "Conto 1" e "Conto 2" in ordine, un solo "oppure" tra i blocchi', str_contains($html2, '<h3 id="conto-1">Conto 1</h3>') && str_contains($html2, '<h3 id="conto-2">Conto 2</h3>')
    && substr_count($html2, '<div class="or">oppure</div>') === 1
    && strpos($html2, 'Conto 1</h3>') < strpos($html2, '>oppure<') && strpos($html2, '>oppure<') < strpos($html2, 'Conto 2</h3>'));
check('due conti: regola in alto, poi la frase sulla scelta, poi i conti', strpos($html2, 'id="regola-istantaneo"') < strpos($html2, 'Puoi fare il bonifico su uno di questi conti, a tua scelta: basta un solo bonifico.')
    && strpos($html2, 'basta un solo bonifico.') < strpos($html2, 'Conto 1</h3>'));
check('due conti: Importo e Causale una volta sola, sotto il loro titolo dopo i conti', substr_count($html2, 'data-copy="550,00"') === 1 && substr_count($html2, 'data-copy="HODGLDN22"') === 1
    && strpos($html2, 'Conto 2</h3>') < strpos($html2, '>Importo e causale (uguali per tutti i conti)</h3>')
    && strpos($html2, '(uguali per tutti i conti)</h3>') < strpos($html2, 'data-copy="550,00"'));
check('due conti: avviso sulla causale invariato in fondo', str_ends_with($html2, '<p class="warn mt-5">Scrivi nella causale solo questo codice, senza altro testo: è quello che ci permette di riconoscere il tuo bonifico.</p></section>'));
preg_match_all('/aria-label="([^"]+)"/', $html2, $labels);
preg_match_all('/ id="([^"]+)"/', $html2, $ids);
check('due conti: 10 bottoni Copia con aria-label tutti diversi, id univoci', count($labels[1]) === 10 && count(array_unique($labels[1])) === 10
    && in_array('Copia IBAN del conto 2', $labels[1], true) && count($ids[1]) === count(array_unique($ids[1])), implode(' | ', $labels[1]));
check('due conti: frase sull\'arrivo adatta a più conti', str_contains($html2, '<p class="mt-3 text-sm">Il bonifico istantaneo arriva sul conto che scegli in pochi secondi.</p>')
    && str_contains(hodbook_bank_section($twoAccounts, '550,00 €', '550,00', 'HODGLDN22', true), AUTO_SENTENCE));
check('due conti: stile in più solo in questo caso', str_contains(hodbook_bank_section_css($twoAccounts), '.accounts .or{') && str_ends_with(hodbook_bank_section_css($twoAccounts), "\n"));
$html3 = hodbook_bank_section($threeCfg['bank_accounts'], '550,00 €', '550,00', 'HODGLDN22', false);
check('tre conti: due "oppure", Conto 3 senza BIC', substr_count($html3, '<div class="or">oppure</div>') === 2 && str_contains($html3, '<h3 id="conto-3">Conto 3</h3>')
    && substr_count($html3, 'aria-label="Copia BIC') === 2);

/* Tentativo di aggiungere intestazioni attraverso il nome (il modulo lo rifiuterebbe: qui si prova il livello email). */
[$injToken, $inj] = hodbook_booking_create($DB, $CFG, 'maltese', customer(['first_name' => "Mario\r\nBcc: evil@example.com", 'last_name' => "Rossi\nX-Spam: 1"]), $now);
hodbook_booking_send_emails($CFG, $inj, $injToken, $now, $DB);
$injFiles = outbox($tmp, (string)$inj['reference']);
$injectionFound = false;
foreach ($injFiles as $f) {
    $msg = eml((string)file_get_contents($f));
    $injectionFound = $injectionFound || isset($msg['headers']['bcc']) || isset($msg['headers']['x-spam'])
        || (bool)preg_match('/^(Bcc|X-Spam):/mi', $msg['head']);
}
$injMerchant = array_values(array_filter($injFiles, static fn(string $f): bool => str_contains($f, '-esercente-')))[0] ?? '';
$injSubject = mime_decode(hdr(eml((string)@file_get_contents($injMerchant)), 'Subject'));
check('a capo nel nome: nessuna intestazione aggiunta (Bcc, X-Spam)', count($injFiles) === 2 && !$injectionFound);
check('a capo nel nome: restano solo spazi nell\'oggetto', $injSubject !== '' && !preg_match('/[\r\n]/', $injSubject) && str_contains($injSubject, 'Mario Bcc: evil@example.com'));

/* Modalità "off": nessun invio, nessun file, nessuna registrazione */
$offCfg = array_merge($CFG, ['mail_mode' => 'off']);
[$offToken, $off] = hodbook_booking_create($DB, $offCfg, 'maltese', customer(), $now);
$beforeFiles = count(outbox($tmp));
check('modalità off: nessuna email', hodbook_booking_send_emails($offCfg, $off, $offToken, $now, $DB) === ['merchant' => false, 'customer' => false]
    && count(outbox($tmp)) === $beforeFiles && bk($off)['customer_email_sent_at'] === null);

/* Trasporto che fallisce: nessuna eccezione, prenotazione intatta, una riga di log senza dati personali */
$logBefore = (string)@file_get_contents(HODBOOK_DATA_DIR . '/prenotazioni.log');
[$failToken, $failBooking] = hodbook_booking_create($DB, $CFG, 'maltese', customer(['first_name' => 'Ugo', 'email' => 'ugo.fallito@example.com']), $now);
$threw = false;
try {
    $res2 = hodbook_booking_send_emails($CFG, $failBooking, $failToken, $now, $DB, static function (array $built): bool {
        throw new RuntimeException('server SMTP irraggiungibile');
    });
} catch (Throwable) {
    $threw = true;
}
$logNew = substr((string)@file_get_contents(HODBOOK_DATA_DIR . '/prenotazioni.log'), strlen($logBefore));
check('trasporto in errore: nessuna eccezione, entrambe non inviate', !$threw && ($res2 ?? null) === ['merchant' => false, 'customer' => false]);
check('trasporto in errore: prenotazione intatta, invio non registrato', ($row = bk($failBooking)) !== null && $row['status'] === 'pending' && $row['customer_email_sent_at'] === null);
check('trasporto in errore: una riga di log con id e quali email, senza dati personali', substr_count($logNew, "\n") === 1
    && str_contains($logNew, 'email NON inviata per la prenotazione #' . $failBooking['id']) && str_contains($logNew, 'esercente') && str_contains($logNew, 'cliente')
    && !str_contains($logNew, 'ugo.fallito') && !str_contains($logNew, 'Ugo') && !str_contains($logNew, 'SMTP'), trim($logNew));
$res3 = hodbook_booking_send_emails($CFG, $failBooking, $failToken, $now, $DB, static fn(array $built): bool => $built['to'] === TEST_MERCHANT);
check('trasporto che rifiuta solo il cliente: esercente inviata, cliente no', $res3 === ['merchant' => true, 'customer' => false] && bk($failBooking)['customer_email_sent_at'] === null);
$blocked = array_merge($CFG, ['merchant_email' => 'non-valida']);
check('indirizzo esercente non valido in config: torna al predefinito', hodbook_config_normalize(['merchant_email' => 'non-valida'])['merchant_email'] === 'houseofdogdigiuseppe@outlook.it');
check('indirizzo non valido arrivato al livello email (To e Reply-To): nessuna eccezione, nessun invio', hodbook_booking_send_emails($blocked, $failBooking, $failToken, $now, null, static fn(array $b): bool => true)
    === ['merchant' => false, 'customer' => false]);

/* =================================================================== */
section('Firma e timestamp dei webhook Revolut');
check('vettore ufficiale Revolut: firma valida', hodbook_revolut_signature_valid([VECTOR_SECRET], VECTOR_TS, VECTOR_BODY, VECTOR_SIG));
check('vettore: firma calcolata identica', 'v1=' . hash_hmac('sha256', 'v1.' . VECTOR_TS . '.' . VECTOR_BODY, VECTOR_SECRET) === VECTOR_SIG);
check('vettore: più firme nell\'intestazione', hodbook_revolut_signature_valid([VECTOR_SECRET], VECTOR_TS, VECTOR_BODY, 'v1=' . str_repeat('a', 64) . ',' . VECTOR_SIG));
check('vettore: più firme con spazi', hodbook_revolut_signature_valid([VECTOR_SECRET], VECTOR_TS, VECTOR_BODY, VECTOR_SIG . ', v1=' . str_repeat('b', 64)));
check('vettore: più segreti configurati', hodbook_revolut_signature_valid([$secretA, VECTOR_SECRET], VECTOR_TS, VECTOR_BODY, VECTOR_SIG));
check('vettore: segreto sbagliato', !hodbook_revolut_signature_valid([$secretA, $secretB], VECTOR_TS, VECTOR_BODY, VECTOR_SIG));
check('vettore: corpo modificato', !hodbook_revolut_signature_valid([VECTOR_SECRET], VECTOR_TS, VECTOR_BODY . ' ', VECTOR_SIG));
check('vettore: corpo ricodificato (spazi diversi)', !hodbook_revolut_signature_valid([VECTOR_SECRET], VECTOR_TS, json_encode(json_decode(VECTOR_BODY), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), VECTOR_SIG));
check('vettore: timestamp modificato', !hodbook_revolut_signature_valid([VECTOR_SECRET], '1683650202361', VECTOR_BODY, VECTOR_SIG));
check('firma senza prefisso v1= rifiutata', !hodbook_revolut_signature_valid([VECTOR_SECRET], VECTOR_TS, VECTOR_BODY, substr(VECTOR_SIG, 3)));
check('intestazione vuota o timestamp non numerico', !hodbook_revolut_signature_valid([VECTOR_SECRET], VECTOR_TS, VECTOR_BODY, '')
    && !hodbook_revolut_signature_valid([VECTOR_SECRET], '', VECTOR_BODY, VECTOR_SIG) && !hodbook_revolut_signature_valid([], VECTOR_TS, VECTOR_BODY, VECTOR_SIG));
$nowMs = (int)floor(microtime(true) * 1000);
check('timestamp attuale accettato', hodbook_revolut_timestamp_fresh((string)$nowMs, $nowMs, 300));
check('timestamp a 299 s accettato (passato e futuro)', hodbook_revolut_timestamp_fresh((string)($nowMs - 299000), $nowMs, 300) && hodbook_revolut_timestamp_fresh((string)($nowMs + 299000), $nowMs, 300));
check('timestamp a 301 s rifiutato (passato e futuro)', !hodbook_revolut_timestamp_fresh((string)($nowMs - 301000), $nowMs, 300) && !hodbook_revolut_timestamp_fresh((string)($nowMs + 301000), $nowMs, 300));
check('timestamp del vettore (2023) vecchio: rifiutato', !hodbook_revolut_timestamp_fresh(VECTOR_TS, $nowMs, 300));
check('timestamp del vettore accettato al suo istante', hodbook_revolut_timestamp_fresh(VECTOR_TS, (int)VECTOR_TS + 1000, 300));
check('timestamp non numerico rifiutato', !hodbook_revolut_timestamp_fresh('abc', $nowMs, 300) && !hodbook_revolut_timestamp_fresh('', $nowMs, 300));
check('orario evento in microsecondi', hodbook_revolut_parse_time('2023-05-09T16:36:38.028960Z') === 1683650198028960);
check('precisioni diverse confrontabili', hodbook_revolut_parse_time('2023-04-06T12:21:49.865Z') === hodbook_revolut_parse_time('2023-04-06T12:21:49.865000Z'));
check('fuso orario esplicito', hodbook_revolut_parse_time('2023-04-06T14:21:49.865+02:00') === hodbook_revolut_parse_time('2023-04-06T12:21:49.865Z'));
check('orario non valido -> null', hodbook_revolut_parse_time('ieri') === null && hodbook_revolut_parse_time('2023-02-30T10:00:00Z') === null && hodbook_revolut_parse_time(null) === null);

/* =================================================================== */
section('Webhook: risposte HTTP del gestore');
$r = hodbook_webhook_handle('GET', [], '', $CFG, $nowMs, $DB);
check('GET -> 405', $r['status'] === 405);
$noSecret = hodbook_config_normalize(['bank_account_holder' => 'X', 'bank_iban' => TEST_IBAN]);
$raw = json_encode(ev_state(tx_id(), 'pending', 'completed', now_us()));
$r = hodbook_webhook_handle('POST', sign($raw, $secretA, $nowMs), $raw, $noSecret, $nowMs, $DB);
check('senza segreto configurato -> 503', $r['status'] === 503);
$big = str_repeat('x', HODBOOK_WEBHOOK_MAX_BODY + 1);
$r = hodbook_webhook_handle('POST', sign($big, $secretA, $nowMs), $big, $CFG, $nowMs, $DB);
check('corpo oltre 64 KB -> 413', $r['status'] === 413);
$r = hodbook_webhook_handle('POST', [], $raw, $CFG, $nowMs, $DB);
check('senza firma -> 401', $r['status'] === 401 && $r['body'] === ['ok' => false, 'error' => 'unauthorized']);
$r = hodbook_webhook_handle('POST', sign($raw, $secretB, $nowMs), $raw, $CFG, $nowMs, $DB);
check('firma con un altro segreto -> 401', $r['status'] === 401);
$r = hodbook_webhook_handle('POST', sign($raw, $secretA, $nowMs - 600000), $raw, $CFG, $nowMs, $DB);
check('timestamp vecchio di 10 minuti -> 401', $r['status'] === 401);
$r = hodbook_webhook_handle('POST', ['revolut-request-timestamp' => VECTOR_TS, 'REVOLUT-SIGNATURE' => VECTOR_SIG], VECTOR_BODY,
    hodbook_config_normalize(['revolut_webhook_secrets' => VECTOR_SECRET]), (int)VECTOR_TS + 2000, $DB);
check('vettore ufficiale attraverso il gestore -> 200 (intestazioni senza distinzione di maiuscole)', $r['status'] === 200 && $r['body'] === ['ok' => true]);
$r = deliver('{"event": "TransactionCreated", "data": ');
check('JSON malformato con firma valida -> 400', $r['status'] === 400);
$r = deliver('"solo una stringa"');
check('JSON che non è un oggetto -> 400', $r['status'] === 400);
$r = deliver(['event' => 'PayoutLinkCreated', 'timestamp' => iso_us(now_us()), 'data' => ['id' => tx_id(), 'state' => 'created']]);
check('PayoutLinkCreated -> 200 ignorato', $r['status'] === 200 && ($r['result']['result'] ?? '') === 'ignored');
$r = deliver(['event' => 'PayoutLinkStateChanged', 'timestamp' => iso_us(now_us()), 'data' => ['id' => tx_id(), 'new_state' => 'active']]);
check('PayoutLinkStateChanged -> 200 ignorato', $r['status'] === 200 && ($r['result']['result'] ?? '') === 'ignored');
$r = deliver(['event' => 'TransactionCreated', 'timestamp' => iso_us(now_us()), 'data' => ['state' => 'completed']]);
check('TransactionCreated senza id -> 200 ignorato', $r['status'] === 200 && ($r['result']['result'] ?? '') === 'ignored');
$failingDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r = hodbook_webhook_handle('POST', sign($raw, $secretA, $nowMs), $raw, $CFG, $nowMs, $failingDb);
check('errore interno (database senza tabelle) -> 500 per far ritentare Revolut', $r['status'] === 500 && $r['body']['ok'] === false);

/* =================================================================== */
section('Webhook: abbinamento dei bonifici');

/* 1. Bonifico istantaneo completato con l'importo esatto */
$b = new_booking('barboncino');
$t = tx_id();
$r = deliver(ev_created($t, 'completed', 550, 'EUR', (string)$b['reference'], now_us()));
$row = bk($b);
check('completato con importo esatto -> paid', $r['status'] === 200 && $row['status'] === 'paid', json_encode($r));
check('paid: paid_tx_id, received_cents, paid_at', $row['paid_tx_id'] === $t && (int)$row['received_cents'] === 55000 && (int)$row['paid_at'] >= $now);
check('transazione salvata e collegata', ($x = txrow($t)) && (int)$x['booking_id'] === (int)$b['id'] && (int)$x['amount_cents'] === 55000 && $x['state'] === 'completed' && $x['reference_text'] === $b['reference']);
check('esito del gestore riporta lo stato', ($r['result']['booking_status'] ?? '') === 'paid' && ($r['result']['event'] ?? '') === 'TransactionCreated');

/* 2. pending, poi StateChanged completed */
$b = new_booking('maltipoo');
$t = tx_id();
$t0 = now_us();
deliver(ev_created($t, 'pending', 450, 'EUR', 'Caparra ' . strtolower((string)$b['reference']), $t0));
check('pending -> incoming', bk($b)['status'] === 'incoming');
deliver(ev_state($t, 'pending', 'completed', $t0 + 5000000));
check('poi StateChanged completed -> paid', bk($b)['status'] === 'paid' && bk($b)['paid_tx_id'] === $t);

/* 3. StateChanged prima del Created (fuori ordine) */
$b = new_booking('maltese');
$t = tx_id();
$t0 = now_us();
$r1 = deliver(ev_state($t, 'pending', 'completed', $t0 + 3000000));
check('StateChanged prima del Created -> 200, riga provvisoria senza importo', $r1['status'] === 200 && ($x = txrow($t)) && $x['amount_cents'] === null && $x['booking_id'] === null && $x['state'] === 'completed');
check('prenotazione ancora in attesa', bk($b)['status'] === 'pending');
deliver(ev_created($t, 'pending', 400, 'EUR', (string)$b['reference'], $t0));
check('Created più vecchio non sovrascrive lo stato più recente', txrow($t)['state'] === 'completed');
check('fuori ordine -> paid', bk($b)['status'] === 'paid' && (int)bk($b)['received_cents'] === 40000);

/* 4. Consegna duplicata */
$b = new_booking('maltese');
$t = tx_id();
$ev = ev_created($t, 'completed', 400, 'EUR', (string)$b['reference'], now_us());
$raw = json_encode($ev, JSON_UNESCAPED_SLASHES);
deliver($raw);
$first = bk($b);
$DB->prepare('UPDATE bookings SET paid_at = paid_at - 100 WHERE id = :id')->execute([':id' => $b['id']]);
$r = deliver($raw);
check('consegna ripetuta -> 200 senza rielaborare', $r['status'] === 200 && ($r['result']['result'] ?? '') === 'duplicate');
check('consegna ripetuta: prenotazione invariata', (int)bk($b)['paid_at'] === (int)$first['paid_at'] - 100 && bk($b)['status'] === 'paid');
check('un solo evento registrato per corpo', count_rows('SELECT COUNT(*) FROM revolut_events WHERE body_hash = :h', [':h' => hash('sha256', $raw)]) === 1);
$r = deliver($ev);
check('stesso evento con firma nuova: sempre duplicato', ($r['result']['result'] ?? '') === 'duplicate');

/* 5. Importo diverso */
$b = new_booking('barboncino');
$t = tx_id();
deliver(ev_created($t, 'completed', 500, 'EUR', (string)$b['reference'], now_us()));
check('importo diverso -> review', bk($b)['status'] === 'review' && (int)bk($b)['received_cents'] === 50000 && bk($b)['paid_at'] === null);

/* 6. Stornato dopo il pagamento */
$b = new_booking('barboncino');
$t = tx_id();
$t0 = now_us();
deliver(ev_created($t, 'completed', 550, 'EUR', (string)$b['reference'], $t0));
check('pagato', bk($b)['status'] === 'paid');
deliver(ev_state($t, 'completed', 'reverted', $t0 + 60000000));
$row = bk($b);
check('reverted dopo paid -> pending, campi del pagamento azzerati', $row['status'] === 'pending' && $row['paid_tx_id'] === null && $row['paid_at'] === null && $row['received_cents'] === null);

/* 7. Uscita (importo negativo) ignorata */
$b = new_booking('barboncino');
$t = tx_id();
$r = deliver(ev_created($t, 'completed', -550, 'EUR', (string)$b['reference'], now_us()));
check('bonifico in uscita -> 200 ignorato, nessuna transazione', $r['status'] === 200 && ($r['result']['result'] ?? '') === 'ignored' && txrow($t) === null && bk($b)['status'] === 'pending');

/* 8. Altra valuta ignorata */
$r = deliver(ev_created($t2 = tx_id(), 'completed', 550, 'GBP', (string)$b['reference'], now_us()));
check('valuta diversa -> ignorato', ($r['result']['result'] ?? '') === 'ignored' && txrow($t2) === null && bk($b)['status'] === 'pending');

/* 9. Codice che non corrisponde a nessuna prenotazione */
$t = tx_id();
$before = count_rows("SELECT COUNT(*) FROM bookings WHERE status <> 'pending'");
$r = deliver(ev_created($t, 'completed', 550, 'EUR', 'caparra HODZZZZZZ rossi', now_us()));
check('nessun abbinamento: transazione salvata senza prenotazione', $r['status'] === 200 && ($x = txrow($t)) && $x['booking_id'] === null && (int)$x['amount_cents'] === 55000);
check('nessun abbinamento: nessuna prenotazione cambiata', count_rows("SELECT COUNT(*) FROM bookings WHERE status <> 'pending'") === $before);

/* 10. Una seconda transazione non declassa una prenotazione pagata */
$b = new_booking('maltipoo');
$tA = tx_id();
$tB = tx_id();
$t0 = now_us();
deliver(ev_created($tA, 'completed', 450, 'EUR', (string)$b['reference'], $t0));
$paid = bk($b);
deliver(ev_created($tB, 'pending', 450, 'EUR', (string)$b['reference'], $t0 + 1000000));
check('seconda transazione pending: resta paid', bk($b)['status'] === 'paid' && bk($b)['paid_tx_id'] === $tA);
deliver(ev_state($tB, 'pending', 'declined', $t0 + 2000000));
check('seconda transazione declined: resta paid', bk($b)['status'] === 'paid' && bk($b)['paid_tx_id'] === $tA);
$tC = tx_id();
deliver(ev_created($tC, 'completed', 100, 'EUR', (string)$b['reference'], $t0 + 3000000));
deliver(ev_state($tC, 'completed', 'reverted', $t0 + 4000000));
check('terza transazione con importo diverso e stornata: resta paid, paid_at invariato', bk($b)['status'] === 'paid' && bk($b)['paid_tx_id'] === $tA && (int)bk($b)['paid_at'] === (int)$paid['paid_at']);

/* 11. Filtro sui conti */
$cfgAcc = $CFG;
$cfgAcc['revolut_account_ids'] = ['05018b0d-e67c-4fec-bea6-415e9da9432c'];
$b = new_booking('maltese');
$t = tx_id();
$r = deliver(ev_created($t, 'completed', 400, 'EUR', (string)$b['reference'], now_us(), ['account_id' => '11111111-2222-3333-4444-555555555555']), $cfgAcc);
check('conto non in elenco -> ignorato', ($r['result']['result'] ?? '') === 'ignored' && txrow($t) === null && bk($b)['status'] === 'pending');
$t = tx_id();
deliver(ev_created($t, 'completed', 400, 'EUR', (string)$b['reference'], now_us(), ['account_id' => '05018B0D-E67C-4FEC-BEA6-415E9DA9432C']), $cfgAcc);
check('conto in elenco (maiuscole indifferenti) -> paid', bk($b)['status'] === 'paid');

/* 12. Altri casi */
$b = new_booking('barboncino');
$t = tx_id();
$t0 = now_us();
deliver(ev_created($t, 'completed', 550, 'EUR', (string)$b['reference'], $t0 + 10000000));
deliver(ev_state($t, 'created', 'pending', $t0));
check('StateChanged più vecchio non sovrascrive', txrow($t)['state'] === 'completed' && bk($b)['status'] === 'paid');

$b = new_booking('barboncino');
deliver(ev_created($t = tx_id(), 'completed', 550, 'EUR', '', now_us(), ['description' => 'From MARIO ROSSI ' . strtolower((string)$b['reference'])]));
check('codice solo nella descrizione della gamba -> paid', bk($b)['status'] === 'paid');

$b = new_booking('barboncino');
$ref = (string)$b['reference'];
deliver(ev_created($t = tx_id(), 'completed', 550, 'EUR', 'caparra ' . substr($ref, 0, 3) . '-' . substr($ref, 3, 3) . ' ' . substr($ref, 6) . ' Rossi', now_us()));
check('causale disordinata ("caparra HOD-XXX XXX Rossi") -> paid', bk($b)['status'] === 'paid');

$b = new_booking('barboncino');
deliver(ev_created($t = tx_id(), 'completed', 550, 'EUR', 'HODZZZZZZ e poi ' . $b['reference'], now_us()));
check('primo codice inesistente, secondo valido -> abbinato al secondo', bk($b)['status'] === 'paid');

$b = new_booking('barboncino');
$t0 = now_us();
deliver(ev_created($t = tx_id(), 'pending', 550, 'EUR', (string)$b['reference'], $t0));
deliver(ev_state($t, 'pending', 'processing', $t0 + 1000000));
check('stato sconosciuto -> non definitivo (incoming)', txrow($t)['state'] === 'processing' && bk($b)['status'] === 'incoming');
deliver(ev_state($t, 'processing', 'declined', $t0 + 2000000));
check('pending poi declined -> di nuovo pending', bk($b)['status'] === 'pending');

$b = new_booking('barboncino');
$t0 = now_us();
deliver(ev_created($t = tx_id(), 'completed', 549.99, 'EUR', (string)$b['reference'], $t0));
check('549,99 € -> review', bk($b)['status'] === 'review' && (int)bk($b)['received_cents'] === 54999);
deliver(ev_state($t, 'completed', 'reverted', $t0 + 1000000));
check('review poi reverted -> pending', bk($b)['status'] === 'pending' && bk($b)['received_cents'] === null);
deliver(ev_created($t2 = tx_id(), 'completed', 550, 'EUR', (string)$b['reference'], $t0 + 2000000));
check('poi bonifico corretto -> paid', bk($b)['status'] === 'paid' && bk($b)['paid_tx_id'] === $t2);

$payload = ev_created($t2 = tx_id(), 'completed', 1, 'EUR', 'nessun codice', now_us());
unset($payload['timestamp']);
$r = deliver($payload);
check('evento senza timestamp: usa quello firmato dell\'intestazione', $r['status'] === 200 && ($x = txrow($t2)) && (int)$x['state_event_ts'] > 0);

/* =================================================================== */
section('Stato calcolato (funzione pura)');
$base = ['amount_cents' => 55000, 'currency' => 'EUR', 'status' => 'pending', 'paid_tx_id' => null, 'paid_at' => null, 'received_cents' => null];
$tx = static fn(string $id, ?string $state, ?int $amount, int $created = 1, string $cur = 'EUR'): array => ['tx_id' => $id, 'state' => $state, 'amount_cents' => $amount, 'currency' => $cur, 'created_at' => $created];
check('nessuna transazione: nessun cambiamento', hodbook_booking_evaluate($base, [], 100) === []);
check('pending -> incoming', hodbook_booking_evaluate($base, [$tx('a', 'pending', 55000)], 100) === ['status' => 'incoming']);
check('created -> incoming', hodbook_booking_evaluate($base, [$tx('a', 'created', 55000)], 100) === ['status' => 'incoming']);
check('completed esatto -> paid', hodbook_booking_evaluate($base, [$tx('a', 'completed', 55000)], 100) === ['status' => 'paid', 'paid_tx_id' => 'a', 'received_cents' => 55000, 'paid_at' => 100]);
check('importo non ancora noto: ignorato', hodbook_booking_evaluate($base, [$tx('a', 'completed', null)], 100) === []);
$paidA = array_merge($base, ['status' => 'paid', 'paid_tx_id' => 'a', 'paid_at' => 50, 'received_cents' => 55000]);
check('pagato da a, b pending: invariato', hodbook_booking_evaluate($paidA, [$tx('a', 'completed', 55000), $tx('b', 'pending', 55000)], 100) === []);
check('pagato da a, b completed esatto: resta a', hodbook_booking_evaluate($paidA, [$tx('b', 'completed', 55000, 0), $tx('a', 'completed', 55000, 5)], 100) === []);
check('pagato da a, a stornato e b esatto: pagato da b', hodbook_booking_evaluate($paidA, [$tx('a', 'reverted', 55000), $tx('b', 'completed', 55000)], 100) === ['paid_tx_id' => 'b', 'paid_at' => 100]);
check('pagato da a, a stornato: pending', hodbook_booking_evaluate($paidA, [$tx('a', 'reverted', 55000)], 100) === ['status' => 'pending', 'paid_tx_id' => null, 'received_cents' => null, 'paid_at' => null]);
$manual = array_merge($base, ['status' => 'paid', 'paid_tx_id' => null, 'paid_at' => 50]);
check('pagato a mano (senza transazione): mai toccato', hodbook_booking_evaluate($manual, [$tx('b', 'reverted', 55000)], 100) === []);
check('due bonifici parziali completati -> review con il totale', hodbook_booking_evaluate($base, [$tx('a', 'completed', 20000), $tx('b', 'completed', 35000)], 100)
    === ['status' => 'review', 'received_cents' => 55000]);
check('importo esatto ma in altra valuta -> review senza importo', hodbook_booking_evaluate($base, [$tx('a', 'completed', 55000, 1, 'GBP')], 100) === ['status' => 'review']);
check('review + pending: review ha la precedenza', hodbook_booking_evaluate($base, [$tx('a', 'completed', 100), $tx('b', 'pending', 55000)], 100) === ['status' => 'review', 'received_cents' => 100]);

/* =================================================================== */
section('Pulizia periodica');
$old = $now - 366 * 86400;
$oldPending = new_booking();
$oldPaid = new_booking();
$oldReview = new_booking();
$recent = new_booking();
$DB->prepare('UPDATE bookings SET created_at = :t WHERE id IN (:a, :b, :c)')->execute([':t' => $old, ':a' => $oldPending['id'], ':b' => $oldPaid['id'], ':c' => $oldReview['id']]);
$DB->prepare("UPDATE bookings SET status = 'paid' WHERE id = :id")->execute([':id' => $oldPaid['id']]);
$DB->prepare("UPDATE bookings SET status = 'review' WHERE id = :id")->execute([':id' => $oldReview['id']]);
$DB->prepare('INSERT INTO revolut_transactions (tx_id, state, booking_id, created_at, updated_at) VALUES (:id, :s, :b, :t, :t)')
   ->execute([':id' => $oldTx = tx_id(), ':s' => 'declined', ':b' => $oldPending['id'], ':t' => $old]);
$DB->prepare('INSERT INTO revolut_transactions (tx_id, state, booking_id, created_at, updated_at) VALUES (:id, :s, NULL, :t, :t)')
   ->execute([':id' => $strayTx = tx_id(), ':s' => 'completed', ':t' => $now - 91 * 86400]);
$DB->prepare('INSERT INTO rate_limits (bucket, ts) VALUES (:b, :t), (:b, :t2)')->execute([':b' => 'vecchio', ':t' => $now - 90000, ':t2' => $now - 60]);
$DB->prepare('INSERT INTO revolut_events (body_hash, event, received_at) VALUES (:h, :e, :t)')->execute([':h' => hash('sha256', 'vecchio'), ':e' => 'x', ':t' => $now - 91 * 86400]);
hodbook_housekeeping($DB, $now, true);
check('non pagata più vecchia di 12 mesi: cancellata', bk($oldPending) === null);
check('...con le sue transazioni', txrow($oldTx) === null);
check('pagata vecchia: conservata', bk($oldPaid) !== null);
check('in verifica vecchia: conservata', bk($oldReview) !== null);
check('recente: conservata', bk($recent) !== null);
check('contatori oltre 24 ore cancellati, recenti conservati', count_rows("SELECT COUNT(*) FROM rate_limits WHERE bucket = 'vecchio'") === 1);
check('impronte webhook oltre 90 giorni cancellate', count_rows('SELECT COUNT(*) FROM revolut_events WHERE body_hash = :h', [':h' => hash('sha256', 'vecchio')]) === 0);
check('transazioni non abbinate oltre 90 giorni cancellate', txrow($strayTx) === null);

/* =================================================================== */
if ($withHttp) {
    section('Percorso HTTP con il server PHP integrato');
    if (!function_exists('curl_init')) {
        check('cURL disponibile per il test HTTP', false, 'estensione curl mancante');
    } else {
        $allHeaders = [];

        $startServer = static function (string $privateDir) use ($siteDir): array {
            $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            $name = $probe ? (string)stream_socket_get_name($probe, false) : '';
            if ($probe) {
                fclose($probe);
            }
            $port = (int)substr($name, (int)strrpos($name, ':') + 1) ?: random_int(20000, 40000);
            $cmd = [PHP_BINARY];
            if (php_ini_loaded_file() === false) {
                /* Nessun php.ini: si ripassano le estensioni caricate in questo processo. */
                array_push($cmd, '-n', '-d', 'extension_dir=' . ini_get('extension_dir'));
                foreach (['curl', 'openssl', 'pdo_sqlite', 'mbstring'] as $ext) {
                    if (extension_loaded($ext)) {
                        array_push($cmd, '-d', 'extension=' . $ext);
                    }
                }
            }
            array_push($cmd, '-S', '127.0.0.1:' . $port, '-t', $siteDir);
            $log = $privateDir . '/server.log';
            $env = array_merge(getenv(), ['HODBOOK_PRIVATE_DIR' => $privateDir]);
            $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $env);
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline) {
                $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($s) {
                    fclose($s);
                    break;
                }
                usleep(100000);
            }
            return ['proc' => $proc, 'pipes' => $pipes, 'base' => 'http://127.0.0.1:' . $port, 'log' => $log];
        };

        $http = static function (string $method, string $url, array $opts = []) use (&$allHeaders): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => array_merge(['Expect:'], $opts['headers'] ?? []),
            ]);
            if (isset($opts['body'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
            }
            $raw = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            unset($ch);
            $headers = substr($raw, 0, $hsize);
            $allHeaders[] = $headers;
            return ['code' => $code, 'headers' => $headers, 'body' => substr($raw, $hsize)];
        };
        $header = static function (array $r, string $name): string {
            return preg_match('/^' . preg_quote($name, '/') . ':\s*(.*?)\r?$/mi', $r['headers'], $m) ? trim($m[1]) : '';
        };
        $tokenFrom = static function (string $html): string {
            return preg_match('/name="token" value="(\d+\.[a-f0-9]{64})"/', $html, $m) ? $m[1] : '';
        };

        $srv = $servers[] = $startServer($tmp);
        $base = $srv['base'];

        /* --- prenota.php --- */
        $r = $http('GET', $base . '/prenota.php');
        check('GET prenota.php senza tipo -> 200 elenco dei 5 cuccioli', $r['code'] === 200 && !str_contains($r['body'], '<form')
            && substr_count($r['body'], 'class="choice" href="prenota.php?tipo=') === 5 && str_contains($r['body'], 'href="prenota.php?tipo=bassotto-cioccolato"'), 'HTTP ' . $r['code']);
        check('intestazioni: nosniff, DENY, same-origin, no-store', $header($r, 'X-Content-Type-Options') === 'nosniff' && $header($r, 'X-Frame-Options') === 'DENY'
            && $header($r, 'Referrer-Policy') === 'same-origin' && str_contains($header($r, 'Cache-Control'), 'no-store'));
        check('meta robots noindex, nofollow', str_contains($r['body'], '<meta name="robots" content="noindex, nofollow">'));
        check('stessa testa delle pagine legali', str_contains($r['body'], 'assets/css/custom.css') && str_contains($r['body'], 'assets/css/fonts.css')
            && str_contains($r['body'], 'https://cdn.tailwindcss.com') && str_contains($r['body'], 'assets/img/favicon-32.png'));
        $r = $http('GET', $base . '/prenota.php?tipo=gatto');
        check('tipo sconosciuto -> elenco', $r['code'] === 200 && !str_contains($r['body'], '<form') && str_contains($r['body'], 'Quale cucciolo vuoi prenotare?'));

        $r = $http('GET', $base . '/prenota.php?tipo=barboncino');
        $formToken = $tokenFrom($r['body']);
        check('GET con tipo -> modulo con nome prodotto e caparra', $r['code'] === 200 && str_contains($r['body'], 'id="prenota-form"')
            && str_contains($r['body'], 'Caparra Barboncino Toy') && str_contains($r['body'], '550,00 €'));
        check('modulo: campi, honeypot, tipo e token', str_contains($r['body'], 'name="nome"') && str_contains($r['body'], 'name="cognome"') && str_contains($r['body'], 'name="email"')
            && str_contains($r['body'], 'name="telefono"') && str_contains($r['body'], 'name="residenza"') && str_contains($r['body'], 'name="codice_fiscale"')
            && str_contains($r['body'], 'name="sito_web"') && str_contains($r['body'], 'name="tipo" value="barboncino"') && $formToken !== '');
        $order = array_map(static fn(string $l): int|false => strpos($r['body'], '<label for="f-' . $l . '"'), ['nome', 'cognome', 'email', 'telefono', 'residenza', 'codice_fiscale']);
        check('modulo: ordine Nome, Cognome, Email, Telefono, Residenza, Codice fiscale', !in_array(false, $order, true) && $order === array_values(array_unique($order)) && $order == (static function (array $o): array { sort($o); return $o; })($order));
        check('modulo: Telefono presente e obbligatorio', (bool)preg_match('#<input id="f-telefono" name="telefono" value="" required[^>]*type="tel"#', $r['body']) && str_contains($r['body'], '>Telefono <span'));
        check('modulo: Residenza in textarea a 2 righe con suggerimento', (bool)preg_match('#<textarea id="f-residenza" name="residenza" required[^>]*rows="2"[^>]*maxlength="200"#', $r['body'])
            && str_contains($r['body'], '>Via e numero civico, CAP, città e provincia</p>'));
        check('modulo: Codice fiscale con autocapitalize, autocomplete off, spellcheck off, maxlength 16',
            (bool)preg_match('#<input id="f-codice_fiscale" name="codice_fiscale" value="" required[^>]*maxlength="16" autocapitalize="characters" autocomplete="off" spellcheck="false"#', $r['body']));
        check('modulo: condizioni in nuova scheda e informativa', (bool)preg_match('#<input[^>]+name="termini"[^>]+required#', $r['body'])
            && str_contains($r['body'], 'Ho letto e accetto le <a href="termini.php" target="_blank" rel="noopener">Condizioni di vendita</a>') && str_contains($r['body'], 'href="privacy.php"'));
        check('modulo: avviso caparra testuale e regola dei bonifici istantanei nello stesso riquadro', str_contains($r['body'],
            '<div class="warn"><p>La caparra non è rimborsabile in caso di rinuncia. Prima del pagamento ti confermiamo sempre per iscritto cucciolo, prezzo totale, documenti e consegna.</p>'
            . '<p class="mt-2">Non sono accettati bonifici ordinari: la caparra va versata solo con bonifico istantaneo.</p></div>'));
        check('modulo: regola prima del bottone', strpos($r['body'], 'Non sono accettati bonifici ordinari') < strpos($r['body'], 'id="prenota-submit"'));
        check('modulo: testo del bottone', str_contains($r['body'], '>Prenota e vedi i dati per il bonifico</button>'));

        $goodPost = ['tipo' => 'barboncino', 'token' => $formToken, 'sito_web' => '', 'nome' => 'Maria', 'cognome' => "D'Angelo", 'email' => 'maria.http@example.com',
            'telefono' => '333 123 4567', 'residenza' => "Via Roma 12\r\n70022 Altamura (BA)", 'codice_fiscale' => 'rssmra80a01h501u', 'termini' => '1'];
        $countBookings = static fn(): int => count_rows('SELECT COUNT(*) FROM bookings');
        $n0 = $countBookings();

        $r = $http('POST', $base . '/prenota.php', ['body' => http_build_query($goodPost)]);
        check('invio dopo meno di 2 secondi -> modulo di nuovo, nessuna prenotazione', $r['code'] === 200 && str_contains($r['body'], 'Un attimo') && $countBookings() === $n0);
        check('...con lo stesso token e i dati conservati', $tokenFrom($r['body']) === $formToken && str_contains($r['body'], 'value="D&apos;Angelo"'));
        usleep(2200000);

        $outboxBefore = count(outbox($tmp));
        $r = $http('POST', $base . '/prenota.php', ['body' => http_build_query(array_merge($goodPost, ['nome' => 'M', 'cognome' => 'Rossi<script>', 'email' => 'no', 'telefono' => '12',
            'residenza' => 'Bari <b>', 'codice_fiscale' => 'RSSMRA80A01H501X', 'termini' => '']))]);
        check('dati non validi -> errori per campo', $r['code'] === 200 && str_contains($r['body'], 'Il nome deve avere almeno 2 lettere.') && str_contains($r['body'], 'Il cognome può contenere solo lettere')
            && str_contains($r['body'], 'L&apos;email non sembra valida') && str_contains($r['body'], 'Il numero di telefono non sembra valido') && str_contains($r['body'], 'Per prenotare devi accettare le Condizioni di vendita.'));
        check('codice fiscale non valido -> messaggio richiesto', str_contains($r['body'], '<p id="f-codice_fiscale-err" class="err">Codice fiscale non valido: controlla i 16 caratteri.</p>'));
        check('residenza non valida -> errore sul campo', str_contains($r['body'], 'id="f-residenza-err"') && str_contains($r['body'], 'Controlla i 7 campi evidenziati.'));
        check('valori conservati ed escapati (anche nella textarea)', str_contains($r['body'], 'value="Rossi&lt;script&gt;"') && !str_contains($r['body'], 'Rossi<script>')
            && str_contains($r['body'], '>Bari &lt;b&gt;</textarea>') && str_contains($r['body'], 'value="RSSMRA80A01H501X"') && str_contains($r['body'], 'aria-invalid="true"'));
        check('nessuna prenotazione creata, nessuna email', $countBookings() === $n0 && count(outbox($tmp)) === $outboxBefore);

        $r = $http('POST', $base . '/prenota.php', ['body' => http_build_query(array_merge($goodPost, ['sito_web' => 'http://spam.example']))]);
        check('honeypot compilato -> messaggio generico, nessuna prenotazione', $r['code'] === 200 && str_contains($r['body'], 'Non è stato possibile completare la prenotazione') && $countBookings() === $n0);

        $r = $http('POST', $base . '/prenota.php', ['body' => http_build_query(array_merge($goodPost, ['token' => substr($formToken, 0, -1) . (str_ends_with($formToken, 'a') ? 'b' : 'a')]))]);
        check('token alterato -> modulo di nuovo, nessuna prenotazione', $r['code'] === 200 && str_contains($r['body'], 'Non siamo riusciti a verificare il modulo') && $countBookings() === $n0);

        $r = $http('POST', $base . '/prenota.php', ['body' => http_build_query($goodPost), 'headers' => ['Sec-Fetch-Site: cross-site']]);
        check('POST cross-site (Sec-Fetch-Site) -> 403', $r['code'] === 403 && $countBookings() === $n0);
        $r = $http('POST', $base . '/prenota.php', ['body' => http_build_query($goodPost), 'headers' => ['Origin: https://evil.example']]);
        check('POST con Origin di un altro host -> 403', $r['code'] === 403 && $countBookings() === $n0);

        $r = $http('POST', $base . '/prenota.php', ['body' => http_build_query($goodPost), 'headers' => ['Origin: ' . $base, 'Sec-Fetch-Site: same-origin']]);
        $location = $header($r, 'Location');
        check('POST valido -> 303 verso prenotazione.php?c=<token>', $r['code'] === 303 && (bool)preg_match('#^prenotazione\.php\?c=[a-f0-9]{64}$#', $location), 'HTTP ' . $r['code'] . ' ' . $location);
        check('una prenotazione creata', $countBookings() === $n0 + 1);
        $bookingToken = substr($location, -64);
        $httpBooking = hodbook_booking_by_token($DB, $bookingToken);
        $httpRef = (string)($httpBooking['reference'] ?? '');
        $httpFiles = outbox($tmp, $httpRef);
        check('POST valido: due file .eml nella cartella temporanea', $httpRef !== '' && count($httpFiles) === 2 && count(outbox($tmp)) === $outboxBefore + 2, (string)count($httpFiles));
        $httpMerchant = eml((string)@file_get_contents(array_values(array_filter($httpFiles, static fn(string $f): bool => str_contains($f, '-esercente-')))[0] ?? ''));
        $httpCustomer = eml((string)@file_get_contents(array_values(array_filter($httpFiles, static fn(string $f): bool => str_contains($f, '-cliente-')))[0] ?? ''));
        check('POST valido: email all\'esercente con i sei dati del cliente', hdr($httpMerchant, 'To') === TEST_MERCHANT && hdr($httpMerchant, 'Reply-To') === 'maria.http@example.com'
            && str_contains($httpMerchant['body'], "Nome: Maria\r\nCognome: D'Angelo\r\nEmail: maria.http@example.com\r\nTelefono: 3331234567\r\nResidenza: Via Roma 12 70022 Altamura (BA)\r\nCodice fiscale: " . CF_OK . "\r\n"));
        check('POST valido: email al cliente con link assoluto su site_url (non sull\'host della richiesta)', hdr($httpCustomer, 'To') === 'maria.http@example.com'
            && str_contains($httpCustomer['body'], TEST_SITE_URL . '/prenotazione.php?c=' . $bookingToken) && !str_contains($httpCustomer['body'], '127.0.0.1')
            && !str_contains($httpMerchant['body'], '127.0.0.1'));
        check('POST valido: invio registrato', $httpBooking !== null && $httpBooking['customer_email_sent_at'] !== null);

        /* --- prenotazione.php --- */
        $page = $base . '/' . $location;
        $r = $http('GET', $page);
        $refMatch = preg_match('/Prenotazione (HOD[' . HODBOOK_REF_ALPHABET . ']{6})</', $r['body'], $m) ? $m[1] : '';
        check('pagina della prenotazione -> 200 con il codice', $r['code'] === 200 && $refMatch !== '', 'HTTP ' . $r['code']);
        check('saluto, prodotto, caparra', str_contains($r['body'], 'Ciao Maria,') && str_contains($r['body'], 'Caparra Barboncino Toy') && str_contains($r['body'], '550,00 €'));
        check('stato: In attesa del bonifico', str_contains($r['body'], 'In attesa del bonifico') && str_contains($r['body'], 'data-status="pending"'));
        check('dati per il bonifico: intestatario, IBAN a gruppi, BIC, banca', str_contains($r['body'], 'Dati per il bonifico') && str_contains($r['body'], 'TITOLARE DI PROVA')
            && str_contains($r['body'], 'IT60 X054 2811 1010 0000 0123 456') && str_contains($r['body'], 'REVOLT21') && str_contains($r['body'], '>Revolut<'));
        check('Copia: IBAN senza spazi, importo 550,00, causale', str_contains($r['body'], 'data-copy="' . TEST_IBAN . '"') && str_contains($r['body'], 'data-copy="550,00"')
            && str_contains($r['body'], 'data-copy="' . $refMatch . '"') && str_contains($r['body'], '>Copia</button>'));
        check('istruzione sulla causale (testuale)', str_contains($r['body'], 'Scrivi nella causale solo questo codice, senza altro testo: è quello che ci permette di riconoscere il tuo bonifico.'));
        $rulePos = strpos($r['body'], '<p class="rule mt-4" id="regola-istantaneo">Non sono accettati bonifici ordinari: la caparra va versata solo con bonifico istantaneo.</p>');
        check('regola dei bonifici istantanei, testuale, in evidenza sopra le coordinate', $rulePos !== false && $rulePos > strpos($r['body'], '>Dati per il bonifico</h2>')
            && $rulePos < strpos($r['body'], 'TITOLARE DI PROVA') && $rulePos < strpos($r['body'], 'IT60 X054 2811 1010 0000 0123 456'));
        check('spiegazione neutra subito dopo la regola, vecchio suggerimento rimosso', str_contains($r['body'], '</p><p class="mt-3 text-sm">Il bonifico istantaneo arriva in pochi secondi: la conferma compare su questa pagina.</p>')
            && !str_contains($r['body'], 'Se la tua banca lo permette'));
        $secStart = strpos($r['body'], '<section class="box mt-6" aria-labelledby="bonifico">');
        $secHtml = $secStart === false ? '' : substr($r['body'], $secStart, (int)strpos($r['body'], '</section>', $secStart) + 10 - $secStart);
        check('un conto: riquadro della pagina identico alla versione precedente, nessuno stile in più', $secHtml === str_replace('HODGLDN22', $refMatch, $goldenSingle)
            && !str_contains($r['body'], '.accounts .or{') && !str_contains($r['body'], 'Conto 1'));
        check('riga sull\'email di riepilogo con indirizzo oscurato', str_contains($r['body'], 'Ti abbiamo inviato un\'email di riepilogo a <strong>m***@example.com</strong>. Se non la trovi controlla la posta indesiderata.'));
        check('link da salvare con Copia link', str_contains($r['body'], 'Salva il link di questa pagina') && str_contains($r['body'], '>Copia link</button>')
            && str_contains($r['body'], $base . '/prenotazione.php?c=' . $bookingToken));
        check('WhatsApp con prodotto e codice nel messaggio', str_contains($r['body'], 'Avvisaci su WhatsApp')
            && str_contains($r['body'], 'https://wa.me/393770876017?text=' . rawurlencode('Ciao! Ho prenotato sul sito: Caparra Barboncino Toy. Codice prenotazione: ' . $refMatch . '.')));
        check('link a termini, privacy e ritorno al sito', str_contains($r['body'], 'href="termini.php"') && str_contains($r['body'], 'href="privacy.php"') && str_contains($r['body'], '← Torna al sito'));
        check('script di supporto caricato', str_contains($r['body'], 'assets/js/prenota.js'));
        check('nessun dato personale oltre al nome e all\'email oscurata', !str_contains($r['body'], 'maria.http@example.com') && !str_contains($r['body'], '3331234567')
            && !str_contains($r['body'], CF_OK) && !str_contains($r['body'], 'Altamura (BA)'));
        check('intestazioni anche qui', $header($r, 'Referrer-Policy') === 'same-origin' && $header($r, 'X-Frame-Options') === 'DENY' && str_contains($header($r, 'Cache-Control'), 'no-store'));
        $stored = hodbook_booking_by_token($DB, $bookingToken);
        check('salvata con dati normalizzati', $stored !== null && $stored['reference'] === $refMatch && $stored['phone'] === '3331234567' && $stored['last_name'] === "D'Angelo"
            && $stored['residence'] === 'Via Roma 12 70022 Altamura (BA)' && $stored['tax_code'] === CF_OK);

        $r = $http('GET', $page . '&format=json');
        check('stato JSON -> {"status":"pending"} senza dati personali', $r['code'] === 200 && trim($r['body']) === '{"status":"pending"}'
            && str_contains($header($r, 'Content-Type'), 'application/json') && str_contains($header($r, 'Cache-Control'), 'no-store'));
        $r = $http('GET', $base . '/prenotazione.php?c=' . str_repeat('0', 64));
        check('token sconosciuto -> 404 con messaggio', $r['code'] === 404 && str_contains($r['body'], 'Prenotazione non trovata') && str_contains($r['body'], 'href="./"'));
        $r = $http('GET', $base . '/prenotazione.php?c=abc');
        check('token malformato -> 404', $r['code'] === 404);
        $r = $http('GET', $base . '/prenotazione.php?c=' . str_repeat('0', 64) . '&format=json');
        check('token sconosciuto in JSON -> 404', $r['code'] === 404 && !str_contains($r['body'], 'status'));

        /* --- webhook-revolut.php --- */
        $r = $http('GET', $base . '/webhook-revolut.php');
        check('GET sul webhook -> 405', $r['code'] === 405 && $header($r, 'Allow') === 'POST');
        $txHttp = tx_id();
        $rawEv = json_encode(ev_created($txHttp, 'completed', 550, 'EUR', 'caparra ' . strtolower($refMatch), now_us()), JSON_UNESCAPED_SLASHES);
        $nowMs = (int)floor(microtime(true) * 1000);
        $r = $http('POST', $base . '/webhook-revolut.php', ['body' => $rawEv, 'headers' => ['Content-Type: application/json',
            'Revolut-Request-Timestamp: ' . $nowMs, 'Revolut-Signature: v1=' . hash_hmac('sha256', 'v1.' . $nowMs . '.' . $rawEv, $secretB)]]);
        check('webhook con firma sbagliata -> 401', $r['code'] === 401 && !str_contains($r['body'], 'wsk_'));
        check('stato invariato dopo il 401', trim($http('GET', $page . '&format=json')['body']) === '{"status":"pending"}');
        $signed = sign($rawEv, $secretA, $nowMs);
        $r = $http('POST', $base . '/webhook-revolut.php', ['body' => $rawEv, 'headers' => ['Content-Type: application/json',
            'Revolut-Request-Timestamp: ' . $signed['Revolut-Request-Timestamp'], 'Revolut-Signature: ' . $signed['Revolut-Signature']]]);
        check('webhook firmato -> 200 {"ok":true}', $r['code'] === 200 && trim($r['body']) === '{"ok":true}', 'HTTP ' . $r['code'] . ' ' . $r['body']);
        $r = $http('GET', $page . '&format=json');
        check('stato JSON -> paid', trim($r['body']) === '{"status":"paid"}', $r['body']);
        $r = $http('GET', $page);
        check('pagina: Caparra ricevuta. Grazie! con data, senza dati per il bonifico', str_contains($r['body'], 'Caparra ricevuta. Grazie!') && str_contains($r['body'], 'Caparra ricevuta il ')
            && !str_contains($r['body'], 'Dati per il bonifico') && !str_contains($r['body'], 'IT60 X054') && str_contains($r['body'], 'data-status="paid"'));
        $r = $http('POST', $base . '/webhook-revolut.php', ['body' => $rawEv, 'headers' => ['Content-Type: application/json',
            'Revolut-Request-Timestamp: ' . $signed['Revolut-Request-Timestamp'], 'Revolut-Signature: ' . $signed['Revolut-Signature']]]);
        check('webhook ripetuto -> 200, nessun effetto', $r['code'] === 200 && trim($http('GET', $page . '&format=json')['body']) === '{"status":"paid"}');
        $r = $http('POST', $base . '/webhook-revolut.php', ['body' => str_repeat('{', 70000), 'headers' => ['Content-Type: application/json']]);
        check('webhook oltre 64 KB -> 413', $r['code'] === 413);
        $log = (string)@file_get_contents($tmp . '/data/prenotazioni.log');
        check('log privato: eventi senza dati personali né segreti', str_contains($log, 'webhook processed evento=TransactionCreated tx=' . $txHttp) && str_contains($log, 'stato=paid')
            && !str_contains($log, 'maria.http@example.com') && !str_contains($log, 'Maria') && !str_contains($log, $secretA) && !str_contains($log, 'D\'Angelo'));

        /* --- limite per email: 3 prenotazioni già fatte (dal test) con lo stesso indirizzo nelle ultime 24 ore --- */
        for ($i = 0; $i < 3; $i++) {
            hodbook_booking_create_limited($DB, $CFG, 'maltese', customer(['email' => 'limite-http@example.com']), time());
        }
        $nLimit = $countBookings();
        $outboxLimit = count(outbox($tmp));
        $r = $http('POST', $base . '/prenota.php', ['body' => http_build_query(array_merge($goodPost, ['email' => 'Limite-HTTP@example.com']))]);
        check('4ª richiesta con la stessa email -> modulo con errore e link WhatsApp', $r['code'] === 200
            && str_contains($r['body'], 'Hai già inviato più richieste con questa email. <a href="https://wa.me/393770876017?text=') && str_contains($r['body'], '">Scrivici su WhatsApp</a>.'));
        check('4ª richiesta: nessuna prenotazione, nessuna email', $countBookings() === $nLimit && count(outbox($tmp)) === $outboxLimit);

        /* --- limite di frequenza: finora 6 POST contati (troppo presto, errori, honeypot, token alterato, valido, limite email) --- */
        $codes = [];
        for ($i = 0; $i < 3; $i++) {
            $codes[] = $http('POST', $base . '/prenota.php', ['body' => http_build_query(array_merge($goodPost, ['email' => 'no']))])['code'];
        }
        check('limite: 8 POST l\'ora, il 9° -> 429', $codes === [200, 200, 429], implode(',', $codes));
        $r = $http('POST', $base . '/prenota.php', ['body' => http_build_query($goodPost)]);
        check('pagina 429 con WhatsApp e nessuna prenotazione', $r['code'] === 429 && str_contains($r['body'], 'Troppi tentativi') && str_contains($r['body'], 'https://wa.me/393770876017') && $countBookings() === $nLimit);
        $r = $http('GET', $base . '/prenota.php?tipo=maltese');
        check('il limite vale solo per gli invii: la pagina si apre', $r['code'] === 200 && str_contains($r['body'], 'id="prenota-form"'));

        $r = $http('GET', $base . '/assets/js/prenota.js');
        check('prenota.js servito e senza localStorage', $r['code'] === 200 && str_contains($r['body'], 'data-copy') && !str_contains($r['body'], 'localStorage') && !str_contains($r['body'], 'document.cookie'));

        /* --- seconda istanza: banca e webhook non configurati --- */
        $tmp2 = $tmp . '/senza-banca';
        mkdir($tmp2 . '/config', 0700, true);
        mkdir($tmp2 . '/data', 0700, true);
        write_config($tmp2, ['bank_account_holder' => 'X', 'bank_iban' => 'IT00X0000000000000000000000']);
        $srv2 = $servers[] = $startServer($tmp2);
        $r = $http('GET', $srv2['base'] . '/prenota.php?tipo=maltese');
        check('banca non configurata: 200, niente modulo, WhatsApp', $r['code'] === 200 && !str_contains($r['body'], '<form') && str_contains($r['body'], 'non è disponibile')
            && str_contains($r['body'], 'https://wa.me/393770876017?text=' . rawurlencode('Ciao! Vorrei prenotare: Caparra Maltese Toy.')));
        $r = $http('GET', $srv2['base'] . '/prenota.php');
        check('banca non configurata, senza tipo: stesso messaggio', $r['code'] === 200 && !str_contains($r['body'], '<form') && str_contains($r['body'], 'Prenota su WhatsApp'));
        $r = $http('POST', $srv2['base'] . '/prenota.php', ['body' => http_build_query($goodPost)]);
        check('banca non configurata: POST non crea nulla', $r['code'] === 200 && !str_contains($r['body'], '<form') && !is_file($tmp2 . '/data/prenotazioni.sqlite'));
        $r = $http('POST', $srv2['base'] . '/webhook-revolut.php', ['body' => $rawEv, 'headers' => ['Content-Type: application/json',
            'Revolut-Request-Timestamp: ' . $signed['Revolut-Request-Timestamp'], 'Revolut-Signature: ' . $signed['Revolut-Signature']]]);
        check('webhook senza segreto -> 503', $r['code'] === 503);

        /* --- terza istanza: trasporto email che fallisce (data/outbox è un file, la cartella non si può creare) --- */
        $tmp3 = $tmp . '/posta-guasta';
        mkdir($tmp3 . '/config', 0700, true);
        mkdir($tmp3 . '/data', 0700, true);
        write_config($tmp3, $testConfig);
        file_put_contents($tmp3 . '/data/outbox', 'non una cartella');
        $srv3 = $servers[] = $startServer($tmp3);
        $r = $http('GET', $srv3['base'] . '/prenota.php?tipo=maltipoo');
        $secret3 = hodbook_read_app_secret($tmp3 . '/data/app-secret.php');
        check('posta guasta: il modulo si apre', $r['code'] === 200 && str_contains($r['body'], 'id="prenota-form"') && $secret3 !== null);
        $r = $http('POST', $srv3['base'] . '/prenota.php', ['body' => http_build_query(array_merge($goodPost, ['tipo' => 'maltipoo',
            'token' => hodbook_form_token(time() - 10, (string)$secret3), 'email' => 'posta.guasta@example.com']))]);
        $location3 = $header($r, 'Location');
        check('posta guasta: prenotazione creata e redirect 303 lo stesso', $r['code'] === 303 && (bool)preg_match('#^prenotazione\.php\?c=[a-f0-9]{64}$#', $location3), 'HTTP ' . $r['code']);
        $db3 = hodbook_db_open($tmp3 . '/data/prenotazioni.sqlite');
        $b3 = hodbook_booking_by_token($db3, substr($location3, -64));
        check('posta guasta: prenotazione salvata, invio non registrato, nessun file', $b3 !== null && $b3['customer_email_sent_at'] === null && !is_dir($tmp3 . '/data/outbox'));
        $log3 = (string)@file_get_contents($tmp3 . '/data/prenotazioni.log');
        check('posta guasta: una riga di log con id, senza dati personali', str_contains($log3, 'email NON inviata per la prenotazione #' . ($b3['id'] ?? '?') . ', modalità file: esercente (RuntimeException), cliente (RuntimeException)')
            && !str_contains($log3, 'posta.guasta') && !str_contains($log3, 'Maria'), trim($log3));
        $r = $http('GET', $srv3['base'] . '/' . $location3);
        check('posta guasta: la pagina si apre senza promettere l\'email', $r['code'] === 200 && str_contains($r['body'], 'In attesa del bonifico') && !str_contains($r['body'], 'Ti abbiamo inviato'));
        $b3 = null;
        $db3 = null;

        /* --- quarta istanza: due conti (bank_extra_accounts), senza webhook: modulo -> pagina -> email --- */
        $tmp4 = $tmp . '/due-conti';
        mkdir($tmp4 . '/config', 0700, true);
        mkdir($tmp4 . '/data', 0700, true);
        write_config($tmp4, array_merge($testConfig, ['revolut_webhook_secrets' => [], 'bank_extra_accounts' => [$extraAcc]]));
        $srv4 = $servers[] = $startServer($tmp4);
        $r = $http('GET', $srv4['base'] . '/prenota.php?tipo=maltese');
        $secret4 = hodbook_read_app_secret($tmp4 . '/data/app-secret.php');
        check('due conti: il modulo si apre', $r['code'] === 200 && str_contains($r['body'], 'id="prenota-form"') && $secret4 !== null);
        $r = $http('POST', $srv4['base'] . '/prenota.php', ['body' => http_build_query(array_merge($goodPost, ['tipo' => 'maltese',
            'token' => hodbook_form_token(time() - 10, (string)$secret4), 'email' => 'due.conti@example.com']))]);
        $location4 = $header($r, 'Location');
        check('due conti: prenotazione creata, redirect 303', $r['code'] === 303 && (bool)preg_match('#^prenotazione\.php\?c=[a-f0-9]{64}$#', $location4), 'HTTP ' . $r['code']);
        $r = $http('GET', $srv4['base'] . '/' . $location4);
        $ref4 = preg_match('/Prenotazione (HOD[' . HODBOOK_REF_ALPHABET . ']{6})</', $r['body'], $m4) ? $m4[1] : '';
        check('due conti, pagina: entrambi gli IBAN e i titoli Conto 1 e Conto 2', $r['code'] === 200 && $ref4 !== ''
            && str_contains($r['body'], '>IT60 X054 2811 1010 0000 0123 456<') && str_contains($r['body'], '>IT42 A036 6901 6000 6701 2321 526<')
            && str_contains($r['body'], '<h3 id="conto-1">Conto 1</h3>') && str_contains($r['body'], '<h3 id="conto-2">Conto 2</h3>'));
        check('due conti, pagina: Importo e Causale una volta sola', substr_count($r['body'], 'data-copy="400,00"') === 1
            && substr_count($r['body'], 'data-copy="' . $ref4 . '"') === 1 && str_contains($r['body'], '>Importo e causale (uguali per tutti i conti)</h3>'));
        check('due conti, pagina: frase sulla scelta, "oppure", stile dedicato, regola in alto', str_contains($r['body'], 'Puoi fare il bonifico su uno di questi conti, a tua scelta: basta un solo bonifico.')
            && str_contains($r['body'], '<div class="or">oppure</div>') && str_contains($r['body'], '.accounts .or{')
            && strpos($r['body'], 'id="regola-istantaneo"') < strpos($r['body'], 'Conto 1</h3>'));
        $files4 = outbox($tmp4, $ref4);
        $cust4 = eml((string)@file_get_contents(array_values(array_filter($files4, static fn(string $f): bool => str_contains($f, '-cliente-')))[0] ?? ''));
        $merch4 = eml((string)@file_get_contents(array_values(array_filter($files4, static fn(string $f): bool => str_contains($f, '-esercente-')))[0] ?? ''));
        check('due conti, email al cliente: i due conti, importo e causale una volta sola', count($files4) === 2
            && str_contains($cust4['body'], "Puoi fare il bonifico su uno di questi conti, a tua scelta: basta un solo bonifico.\r\n\r\nConto 1\r\nIntestatario: TITOLARE DI PROVA\r\n")
            && str_contains($cust4['body'], "\r\nConto 2\r\nIntestatario: Giuseppe Falcicchio\r\nIBAN: IT42 A036 6901 6000 6701 2321 526\r\nBIC: REVOITM2\r\nBanca: Revolut\r\n")
            && substr_count($cust4['body'], 'Importo: 400,00 €') === 1 && substr_count($cust4['body'], 'Causale: ' . $ref4) === 1);
        check('due conti, email all\'esercente: indica i 2 conti', str_contains($merch4['body'], "Al cliente sono stati indicati 2 conti: può fare il bonifico su uno a sua scelta.\r\n"));

        $cookies = array_filter($allHeaders, static fn(string $h): bool => (bool)preg_match('/^Set-Cookie:/mi', $h));
        check('nessun Set-Cookie in ' . count($allHeaders) . ' risposte', $cookies === [] && count($allHeaders) > 30);

        foreach ($servers as $s) {
            if (is_resource($s['proc'])) {
                proc_terminate($s['proc']);
                proc_close($s['proc']);
            }
        }
        $serverLog = (string)@file_get_contents($srv['log']) . (string)@file_get_contents($srv2['log']) . (string)@file_get_contents($srv3['log'])
            . (string)@file_get_contents($srv4['log']);
        check('server: nessun errore PHP', !preg_match('/PHP (Warning|Notice|Deprecated|Fatal|Parse)/', $serverLog), substr($serverLog, 0, 300));
        $errLog23 = (string)@file_get_contents($tmp2 . '/data/php-errors.log') . (string)@file_get_contents($tmp3 . '/data/php-errors.log')
            . (string)@file_get_contents($tmp4 . '/data/php-errors.log');
        check('seconda, terza e quarta istanza: log errori PHP vuoti', trim($errLog23) === '', substr($errLog23, 0, 300));
    }
}

/* =================================================================== */
section('Log degli errori PHP');
$errLog = (string)@file_get_contents(HODBOOK_DATA_DIR . '/php-errors.log');
$unexpected = array_filter(explode("\n", trim($errLog)), static fn(string $l): bool => $l !== '' && !str_contains($l, '[hodbook] webhook: PDOException'));
check('nessun avviso o errore PHP inatteso', $unexpected === [], substr(implode("\n", $unexpected), 0, 500));

echo "\nRisultato: $pass ok, $fail falliti\n";
exit($fail === 0 ? 0 : 1);
