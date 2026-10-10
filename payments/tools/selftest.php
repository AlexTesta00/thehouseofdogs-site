<?php
/* Test automatici senza credenziali Mollie.
   Uso:  php tools/selftest.php          test unitari e di integrazione su DB temporaneo
         php tools/selftest.php --http   in più avvia il server PHP integrato e prova il percorso HTTP
   Esce con codice 1 se un test fallisce. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Solo da riga di comando.');
}

$withHttp = in_array('--http', $argv, true);

/* Ambiente privato temporaneo: config + data isolati dal progetto. */
$tmp = sys_get_temp_dir() . '/hodpay-selftest-' . bin2hex(random_bytes(4));
mkdir($tmp . '/config', 0700, true);
mkdir($tmp . '/data', 0700, true);
$adminPassword = 'prova-selftest-' . bin2hex(random_bytes(4));
$hash = password_hash($adminPassword, PASSWORD_DEFAULT);
file_put_contents($tmp . '/config/config.php', "<?php\nreturn " . var_export([
    'mollie_api_key'      => '',
    'admin_password_hash' => $hash,
    'purchase_description' => 'Caparra cucciolo - Test',
    'base_url'            => 'https://example.test/payments',
], true) . ";\n");

define('HODPAY_PRIVATE_DIR', $tmp);
define('HODPAY_APP_DIR', dirname(__DIR__) . '/app');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/payments/index.php';
require HODPAY_APP_DIR . '/bootstrap.php';

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

/* ---------- Importi ---------- */
section('Importi in centesimi');
$cases = [
    '120' => 12000, '120,50' => 12050, '120.5' => 12050, '€ 350,00' => 35000, '1 200' => 120000,
    '0,99' => 99, '4000' => 400000, '1.200' => null, '1,200' => null, 'abc' => null, '' => null,
    '-5' => null, '12,345' => null, '1e3' => null,
];
foreach ($cases as $in => $want) {
    $got = hodpay_parse_amount_cents((string)$in);
    check("parse '$in' -> " . var_export($want, true), $got === $want, 'ottenuto ' . var_export($got, true));
}
check('mollie value 12050 -> 120.50', hodpay_mollie_value(12050) === '120.50');
check('mollie value 5 -> 0.05', hodpay_mollie_value(5) === '0.05');
check('format 125000 -> 1.250,00 €', hodpay_format_amount(125000) === '1.250,00 €');

$cfg = hodpay_config();
check('config: default min 1 €', $cfg['min_amount_cents'] === 100);
check('config: default max 4.000 €', $cfg['max_amount_cents'] === 400000);
[$c, $e] = hodpay_validate_amount('0,50', $cfg);
check('importo sotto il minimo rifiutato', $c === null && $e !== null);
[$c, $e] = hodpay_validate_amount('4000,01', $cfg);
check('importo sopra il massimo rifiutato', $c === null && $e !== null);
[$c, $e] = hodpay_validate_amount('4000', $cfg);
check('importo al massimo accettato', $c === 400000 && $e === null);

/* ---------- Modalità ---------- */
section('Modalità chiave Mollie');
check('vuota -> demo', hodpay_mode(['mollie_api_key' => '']) === 'demo');
check('test_ -> test', hodpay_mode(['mollie_api_key' => 'test_' . str_repeat('a', 30)]) === 'test');
check('live_ -> live', hodpay_mode(['mollie_api_key' => 'live_' . str_repeat('b', 30)]) === 'live');
check('formato strano -> invalid', hodpay_mode(['mollie_api_key' => 'sk_abc']) === 'invalid');

/* ---------- Token e sicurezza ---------- */
section('Token, CSRF, limiti');
$t1 = hodpay_random_token();
check('token 64 hex', hodpay_valid_token_format($t1));
check('token diversi', $t1 !== hodpay_random_token());
check('token maiuscolo rifiutato', !hodpay_valid_token_format(strtoupper($t1)));
check('uuid4 formato', (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', hodpay_uuid4()));

$_SESSION = [];
$csrf = hodpay_csrf_token();
check('csrf valido', hodpay_csrf_check($csrf));
check('csrf errato', !hodpay_csrf_check('x' . $csrf));
check('csrf vuoto', !hodpay_csrf_check(''));
check('csrf null', !hodpay_csrf_check(null));

$_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
check('cross-site rifiutato', !hodpay_same_origin_check());
$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
check('same-origin accettato', hodpay_same_origin_check());
unset($_SERVER['HTTP_SEC_FETCH_SITE']);
$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['HTTP_ORIGIN'] = 'https://evil.test';
check('origin estraneo rifiutato', !hodpay_same_origin_check());
$_SERVER['HTTP_ORIGIN'] = 'https://example.test';
check('origin proprio accettato', hodpay_same_origin_check());
unset($_SERVER['HTTP_ORIGIN']);

$okCount = 0;
for ($i = 0; $i < 5; $i++) {
    if (hodpay_rate_limit('test:bucket', 3, 60)) {
        $okCount++;
    }
}
check('rate limit 3 su 5', $okCount === 3);

/* ---------- Autenticazione ---------- */
section('Autenticazione');
check('admin configurato', hodpay_admin_configured());
check('login password errata', !hodpay_login('password-sbagliata'));
check('non admin prima del login', !hodpay_is_admin());
/* session_regenerate_id non funziona in CLI: simuliamo solo la verifica */
check('password_verify corretta', password_verify($adminPassword, $cfg['admin_password_hash']));

/* ---------- Validazione dati cliente ---------- */
section('Validazione dati cliente');
$good = ['given_name' => 'Maria', 'family_name' => 'Rossi', 'email' => 'Maria.Rossi@Example.com',
         'street' => 'Via Roma 12', 'postal_code' => '70022', 'city' => 'Altamura', 'country' => 'IT'];
[$b, $errs] = hodpay_validate_billing($good, $cfg);
check('dati validi accettati', $errs === [], json_encode($errs));
check('email normalizzata minuscola', ($b['email'] ?? '') === 'maria.rossi@example.com');
[$b, $errs] = hodpay_validate_billing(array_merge($good, ['given_name' => 'M']), $cfg);
check('nome 1 carattere rifiutato', isset($errs['given_name']));
[$b, $errs] = hodpay_validate_billing(array_merge($good, ['family_name' => '12345']), $cfg);
check('cognome numerico rifiutato', isset($errs['family_name']));
[$b, $errs] = hodpay_validate_billing(array_merge($good, ['email' => 'non-valida']), $cfg);
check('email non valida rifiutata', isset($errs['email']));
[$b, $errs] = hodpay_validate_billing(array_merge($good, ['street' => 'Via Roma']), $cfg);
check('via senza civico rifiutata', isset($errs['street']));
[$b, $errs] = hodpay_validate_billing(array_merge($good, ['postal_code' => '7002']), $cfg);
check('CAP italiano di 4 cifre rifiutato', isset($errs['postal_code']));
[$b, $errs] = hodpay_validate_billing(array_merge($good, ['country' => 'US']), $cfg);
check('paese non ammesso rifiutato', isset($errs['country']));
[$b, $errs] = hodpay_validate_billing(array_merge($good, ['country' => 'NL', 'postal_code' => '1012 AB']), $cfg);
check('CAP olandese accettato', !isset($errs['postal_code']), json_encode($errs));
[$b, $errs] = hodpay_validate_billing(array_merge($good, ['city' => "Alta<script>"]), $cfg);
check('città con caratteri strani rifiutata', isset($errs['city']));
[$n, $e] = hodpay_validate_customer_name("  Maria   Rossi \x01 ");
check('nome cliente normalizzato', $n === 'Maria Rossi', var_export($n, true));
[$n, $e] = hodpay_validate_customer_name('123');
check('nome cliente numerico rifiutato', $n === null);

/* ---------- Richieste e percorso cliente (Mollie finto) ---------- */
section('Richiesta e checkout con Mollie simulato');

final class FakeMollie implements HodpayMollieApi
{
    public array $methods = [];
    public array $calls = [];
    public ?HodpayMollieException $failCreate = null;
    public ?HodpayMollieException $failMethods = null;
    public array $lastPayload = [];
    public array $keys = [];

    public function listMethods(int $amountCents, string $currency, string $country): array
    {
        $this->calls[] = 'methods';
        if ($this->failMethods) {
            throw $this->failMethods;
        }
        return $this->methods;
    }

    public function createPaymentLink(array $payload, string $idempotencyKey): array
    {
        $this->calls[] = 'create';
        $this->keys[] = $idempotencyKey;
        $this->lastPayload = $payload;
        if ($this->failCreate) {
            throw $this->failCreate;
        }
        return ['id' => 'pl_test123', '_links' => ['paymentLink' => ['href' => 'https://paymentlink.mollie.com/payment/pl_test123/']]];
    }
}

$cfgTest = array_replace($cfg, ['mollie_api_key' => 'test_' . str_repeat('x', 30)]);
[$token, $req] = hodpay_request_create('Maria Rossi', 35000, $cfgTest);
check('richiesta creata pending', $req['status'] === 'pending' && (int)$req['amount_cents'] === 35000);
check('token non salvato in chiaro', $req['token_hash'] !== $token && $req['token_hash'] === hash('sha256', $token));
check('richiesta trovata via token', (hodpay_request_by_token($token)['id'] ?? null) === $req['id']);
check('token sconosciuto -> null', hodpay_request_by_token(str_repeat('0', 64)) === null);
check('token malformato -> null', hodpay_request_by_token('abc') === null);
check('link cliente corretto', hodpay_customer_link($token) === 'https://example.test/payments/pay.php?t=' . $token);
check('scadenza a 72 ore', (int)$req['expires_at'] - (int)$req['created_at'] === 72 * 3600);

$billing = ['given_name' => 'Maria', 'family_name' => 'Rossi', 'email' => 'maria@example.com',
            'street' => 'Via Roma 12', 'postal_code' => '70022', 'city' => 'Altamura', 'country' => 'IT'];

/* Klarna disponibile */
$api = new FakeMollie();
$api->methods = [['id' => 'klarna', 'status' => 'activated'], ['id' => 'creditcard', 'status' => 'activated'],
                 ['id' => 'paypal', 'status' => 'pending-boarding'], ['id' => 'ideal', 'status' => 'activated']];
$res = hodpay_checkout($req, $billing, $cfgTest, $api);
check('checkout -> redirect', $res['status'] === 'redirect', json_encode($res));
check('url Mollie restituito', ($res['url'] ?? '') === 'https://paymentlink.mollie.com/payment/pl_test123/');
check('klarna segnalato disponibile', ($res['klarna'] ?? false) === true);
check('ordine chiamate: methods poi create', $api->calls === ['methods', 'create']);
$p = $api->lastPayload;
check('payload: amount', ($p['amount'] ?? null) === ['currency' => 'EUR', 'value' => '350.00']);
check('payload: description con nome', ($p['description'] ?? '') === 'Caparra cucciolo - Test - Maria Rossi');
check('payload: una riga con totale uguale', count($p['lines'] ?? []) === 1 && $p['lines'][0]['totalAmount']['value'] === '350.00' && $p['lines'][0]['quantity'] === 1);
check('payload: billingAddress completo', isset($p['billingAddress']) && $p['billingAddress'] === [
    'givenName' => 'Maria', 'familyName' => 'Rossi', 'email' => 'maria@example.com',
    'streetAndNumber' => 'Via Roma 12', 'postalCode' => '70022', 'city' => 'Altamura', 'country' => 'IT']);
check('payload: allowedMethods solo attivi, con klarna', ($p['allowedMethods'] ?? null) === ['klarna', 'creditcard', 'ideal']);
check('payload: reusable false', ($p['reusable'] ?? null) === false);
check('payload: expiresAt ISO 8601', (bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $p['expiresAt'] ?? ''));
check('payload: redirectUrl grazie.php', ($p['redirectUrl'] ?? '') === 'https://example.test/payments/grazie.php');
check('payload: niente webhook se non configurato', !isset($p['webhookUrl']));
check('payload: idempotency key = quella salvata', $api->keys[0] === $req['idempotency_key']);
$saved = hodpay_request_by_id((int)$req['id']);
check('stato salvato created + url + klarna', $saved['status'] === 'created' && $saved['mollie_link_url'] === $res['url'] && (int)$saved['klarna_available'] === 1);

/* Tentativo ripetuto: nessuna nuova chiamata */
$api2 = new FakeMollie();
$res2 = hodpay_checkout($saved, $billing, $cfgTest, $api2);
check('retry: stesso url senza chiamare Mollie', $res2['status'] === 'redirect' && $res2['url'] === $res['url'] && $api2->calls === [] && $res2['reused'] === true);

/* Klarna NON disponibile: si procede con gli altri metodi */
[$token2, $req2] = hodpay_request_create('Luca Bianchi', 150000, $cfgTest);
$api = new FakeMollie();
$api->methods = [['id' => 'creditcard', 'status' => 'activated'], ['id' => 'paypal', 'status' => 'activated']];
$res = hodpay_checkout($req2, $billing, $cfgTest, $api);
check('senza klarna -> redirect comunque', $res['status'] === 'redirect' && $res['klarna'] === false);
check('senza klarna -> allowedMethods senza klarna', ($api->lastPayload['allowedMethods'] ?? null) === ['creditcard', 'paypal']);

/* Nessun metodo */
[$token3, $req3] = hodpay_request_create('Anna Verdi', 20000, $cfgTest);
$api = new FakeMollie();
$api->methods = [];
$res = hodpay_checkout($req3, $billing, $cfgTest, $api);
check('nessun metodo -> errore chiaro, nessuna create', $res['status'] === 'error' && $res['code'] === 'no_methods' && $api->calls === ['methods']);

/* Errore 422 -> chiave di idempotenza ruotata; 5xx -> chiave conservata */
[$token4, $req4] = hodpay_request_create('Paolo Neri', 50000, $cfgTest);
$api = new FakeMollie();
$api->methods = [['id' => 'klarna', 'status' => 'activated']];
$api->failCreate = new HodpayMollieException('Mollie ha rifiutato i dati inseriti: email', 422, 'billingAddress.email', 'email');
$res = hodpay_checkout($req4, $billing, $cfgTest, $api);
$after = hodpay_request_by_id((int)$req4['id']);
check('422 -> errore mostrato', $res['status'] === 'error' && str_contains($res['message'], 'rifiutato'));
check('422 -> chiave idempotenza ruotata', $after['idempotency_key'] !== $req4['idempotency_key']);
check('422 -> stato ancora pending, tentativi 1', $after['status'] === 'pending' && (int)$after['attempts'] === 1);

$api = new FakeMollie();
$api->methods = [['id' => 'klarna', 'status' => 'activated']];
$api->failCreate = new HodpayMollieException('Mollie non è raggiungibile', 503);
$res = hodpay_checkout($after, $billing, $cfgTest, $api);
$after2 = hodpay_request_by_id((int)$req4['id']);
check('503 -> errore, chiave conservata per il retry', $res['status'] === 'error' && $after2['idempotency_key'] === $after['idempotency_key']);

$api = new FakeMollie();
$api->methods = [['id' => 'klarna', 'status' => 'activated']];
$api->failCreate = new HodpayMollieException('in elaborazione', 409);
$res = hodpay_checkout($after2, $billing, $cfgTest, $api);
check('409 -> codice busy', $res['status'] === 'error' && $res['code'] === 'busy');

$api = new FakeMollie();
$api->failMethods = new HodpayMollieException('Impossibile contattare Mollie', 0, null, null, true);
$res = hodpay_checkout($after2, $billing, $cfgTest, $api);
check('errore rete su methods -> errore, nessuna create', $res['status'] === 'error' && $api->calls === ['methods']);

/* Scadenza */
[$token5, $req5] = hodpay_request_create('Scaduto', 10000, $cfgTest);
$res = hodpay_checkout($req5, $billing, $cfgTest, new FakeMollie(), (int)$req5['expires_at'] + 1);
check('link scaduto -> errore expired', $res['status'] === 'error' && $res['code'] === 'expired');

/* Demo: nessuna chiamata, nessuna simulazione */
[$token6, $req6] = hodpay_request_create('Demo', 10000, $cfg);
$api = new FakeMollie();
$res = hodpay_checkout($req6, $billing, $cfg, null);
check('demo -> stato demo senza chiamate', $res['status'] === 'demo' && $api->calls === []);
check('demo -> salvato come demo', hodpay_request_by_id((int)$req6['id'])['status'] === 'demo');

/* IVA facoltativa */
$cfgVat = array_replace($cfgTest, ['vat_rate' => '22.00']);
$p = hodpay_build_payment_link_payload($req2, $billing, ['klarna'], $cfgVat, time());
check('vat: 1500.00 con 22% -> vatAmount 270.49', ($p['lines'][0]['vatRate'] ?? '') === '22.00' && ($p['lines'][0]['vatAmount']['value'] ?? '') === '270.49');

/* ---------- HTTP (opzionale) ---------- */
if ($withHttp) {
    section('Percorso HTTP con server PHP integrato (modalità demo)');
    if (!function_exists('curl_init')) {
        check('cURL disponibile per il test HTTP', false, 'estensione curl mancante');
    } else {
        $port = random_int(8300, 8900);
        $docroot = dirname(__DIR__) . '/public';
        $cmd = [PHP_BINARY];
        if (php_ini_loaded_file() === false) {
            /* Nessun php.ini: ripassiamo le estensioni caricate in questo processo. */
            $cmd[] = '-n';
            $cmd[] = '-d';
            $cmd[] = 'extension_dir=' . ini_get('extension_dir');
            foreach (['curl', 'openssl', 'pdo_sqlite', 'mbstring'] as $ext) {
                if (extension_loaded($ext)) {
                    $cmd[] = '-d';
                    $cmd[] = 'extension=' . $ext;
                }
            }
        }
        array_push($cmd, '-S', '127.0.0.1:' . $port, '-t', $docroot);
        $env = array_merge(getenv(), ['HODPAY_PRIVATE_DIR' => $tmp, 'HODPAY_APP_DIR' => HODPAY_APP_DIR]);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/server.log', 'a'], 2 => ['file', $tmp . '/server.log', 'a']], $pipes, null, $env);
        usleep(900000);
        $base = 'http://127.0.0.1:' . $port;
        $jar = $tmp . '/cookies.txt';

        $http = static function (string $method, string $url, array $opts = []) use ($jar): array {
            $ch = curl_init($url);
            $headers = $opts['headers'] ?? [];
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_TIMEOUT => 15,
            ]);
            if (isset($opts['body'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
            }
            if ($headers) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
            $raw = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            return ['code' => $code, 'headers' => substr($raw, 0, $hsize), 'body' => substr($raw, $hsize)];
        };
        $csrfFrom = static function (string $html): string {
            return preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : (preg_match('/data-csrf="([a-f0-9]+)"/', $html, $m) ? $m[1] : '');
        };

        $r = $http('GET', $base . '/index.php');
        check('GET index -> 200 login', $r['code'] === 200 && str_contains($r['body'], 'name="password"'), 'HTTP ' . $r['code']);
        check('intestazioni di sicurezza presenti', str_contains($r['headers'], 'Content-Security-Policy') && str_contains($r['headers'], 'X-Frame-Options: DENY'));
        check('cookie di sessione HttpOnly', (bool)preg_match('/Set-Cookie: hodpay=.*HttpOnly/i', $r['headers']));
        $csrfTok = $csrfFrom($r['body']);
        check('csrf nel form di login', $csrfTok !== '');

        $r = $http('POST', $base . '/api/create-link.php', ['body' => '{}', 'headers' => ['Content-Type: application/json']]);
        check('API senza login -> 401', $r['code'] === 401, 'HTTP ' . $r['code']);

        $r = $http('POST', $base . '/index.php', ['body' => http_build_query(['csrf' => $csrfTok, 'password' => 'sbagliata'])]);
        check('login errato -> resta sulla pagina con errore', $r['code'] === 200 && str_contains($r['body'], 'Password non corretta'));
        $r = $http('POST', $base . '/index.php', ['body' => http_build_query(['csrf' => 'x', 'password' => $adminPassword])]);
        check('login con csrf errato rifiutato', $r['code'] === 200 && str_contains($r['body'], 'Sessione non valida'));
        $r = $http('POST', $base . '/index.php', ['body' => http_build_query(['csrf' => $csrfTok, 'password' => $adminPassword])]);
        check('login corretto -> redirect 303', $r['code'] === 303, 'HTTP ' . $r['code']);
        $r = $http('GET', $base . '/index.php');
        check('pannello generatore visibile', $r['code'] === 200 && str_contains($r['body'], 'id="create-form"'));
        check('chiave API mai nell\'HTML', !str_contains($r['body'], 'test_') && !str_contains($r['body'], 'live_'));
        $csrfAdmin = $csrfFrom($r['body']);

        $r = $http('POST', $base . '/api/create-link.php', ['body' => json_encode(['customer_name' => 'Maria Rossi', 'amount' => '350,00']),
            'headers' => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrfAdmin]]);
        $j = json_decode($r['body'], true);
        check('API crea link -> 201', $r['code'] === 201 && ($j['ok'] ?? false) === true, $r['body']);
        $link = $j['data']['link'] ?? '';
        check('link cliente con token 64 hex', (bool)preg_match('#/pay\.php\?t=[a-f0-9]{64}$#', $link), $link);
        check('importo formattato', ($j['data']['amount_formatted'] ?? '') === '350,00 €');

        $r = $http('POST', $base . '/api/create-link.php', ['body' => json_encode(['customer_name' => 'X', 'amount' => '5000']),
            'headers' => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrfAdmin]]);
        $j2 = json_decode($r['body'], true);
        check('API validazione -> 422 con campi', $r['code'] === 422 && isset($j2['error']['fields']['customer_name'], $j2['error']['fields']['amount']));

        $r = $http('POST', $base . '/api/create-link.php', ['body' => json_encode(['customer_name' => 'Maria Rossi', 'amount' => '10']),
            'headers' => ['Content-Type: application/json', 'X-CSRF-Token: sbagliato']]);
        check('API con csrf errato -> 403', $r['code'] === 403);

        $payPath = parse_url($link, PHP_URL_PATH) . '?' . parse_url($link, PHP_URL_QUERY);
        $payUrl = $base . substr($payPath, strlen('/payments'));
        $r = $http('GET', $payUrl);
        check('pagina cliente -> 200 con importo', $r['code'] === 200 && str_contains($r['body'], '350,00 €') && str_contains($r['body'], 'id="pay-form"'), 'HTTP ' . $r['code']);
        check('pagina cliente: paese Italia preselezionato', (bool)preg_match('/<option value="IT" selected>/', $r['body']));
        check('pagina cliente: nome precompilato', str_contains($r['body'], 'value="Maria"') && str_contains($r['body'], 'value="Rossi"'));
        $csrfPay = $csrfFrom($r['body']);
        $tok = substr($link, -64);

        $r = $http('GET', $base . '/pay.php?t=' . str_repeat('0', 64));
        check('token inesistente -> 404', $r['code'] === 404);
        $r = $http('GET', $base . '/pay.php?t=abc');
        check('token malformato -> 404', $r['code'] === 404);

        $bad = ['csrf' => $csrfPay, 't' => $tok, 'given_name' => 'Maria', 'family_name' => 'Rossi', 'email' => 'no', 'street' => 'Via Roma', 'postal_code' => '123', 'city' => 'Altamura', 'country' => 'IT'];
        $r = $http('POST', $payUrl, ['body' => http_build_query($bad)]);
        check('checkout dati errati -> form con errori', $r['code'] === 200 && str_contains($r['body'], 'Email: indirizzo non valido') && str_contains($r['body'], 'numero civico') && str_contains($r['body'], 'CAP: devono essere 5 cifre'));

        $good = array_merge($bad, ['email' => 'maria@example.com', 'street' => 'Via Roma 12', 'postal_code' => '70022']);
        $r = $http('POST', $payUrl, ['body' => http_build_query($good)]);
        check('checkout demo -> pagina demo, nessun redirect', $r['code'] === 200 && str_contains($r['body'], 'Modalità demo'));
        $r = $http('GET', $payUrl);
        check('riapertura dopo demo -> pagina demo', $r['code'] === 200 && str_contains($r['body'], 'modalità demo'));

        $r = $http('GET', $base . '/grazie.php');
        check('pagina grazie -> 200', $r['code'] === 200 && str_contains($r['body'], 'Grazie'));

        $r = $http('POST', $base . '/logout.php', ['body' => http_build_query(['csrf' => $csrfAdmin])]);
        check('logout -> redirect', $r['code'] === 303);
        $r = $http('GET', $base . '/index.php');
        check('dopo logout torna il login', str_contains($r['body'], 'name="password"'));

        $r = $http('GET', $base . '/app-path.php');
        check('app-path.php non espone nulla di utile', $r['code'] === 200 && trim($r['body']) === '');

        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
    }
}

/* Pulizia */
foreach (glob($tmp . '/data/*') ?: [] as $f) { @unlink($f); }
foreach (glob($tmp . '/config/*') ?: [] as $f) { @unlink($f); }
foreach (glob($tmp . '/*') ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
@rmdir($tmp . '/data');
@rmdir($tmp . '/config');
@rmdir($tmp);

echo "\nRisultato: $pass ok, $fail falliti\n";
exit($fail === 0 ? 0 : 1);
