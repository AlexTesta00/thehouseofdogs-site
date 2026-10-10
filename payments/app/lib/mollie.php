<?php
/* Client Mollie minimale (cURL). La chiave API esiste solo qui, in memoria, durante la richiesta.
   Mai nei log, mai nelle eccezioni, mai nelle risposte. */

declare(strict_types=1);

interface HodpayMollieApi
{
    /** Metodi disponibili per importo, valuta e paese. Restituisce l'array _embedded.methods. */
    public function listMethods(int $amountCents, string $currency, string $country): array;

    /** POST /v2/payment-links con Idempotency-Key. Restituisce l'oggetto payment link. */
    public function createPaymentLink(array $payload, string $idempotencyKey): array;
}

final class HodpayMollieException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $field = null,
        public readonly ?string $detail = null,
        public readonly bool $network = false,
    ) {
        parent::__construct($message, $status);
    }
}

final class HodpayMollieClient implements HodpayMollieApi
{
    private const BASE = 'https://api.mollie.com/v2';

    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 25,
    ) {
        if ($apiKey === '') {
            throw new InvalidArgumentException('Chiave Mollie mancante.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Estensione PHP cURL non disponibile.');
        }
    }

    public function listMethods(int $amountCents, string $currency, string $country): array
    {
        $query = http_build_query([
            'amount'         => ['value' => hodpay_mollie_value($amountCents), 'currency' => $currency],
            'billingCountry' => $country,
            'locale'         => 'it_IT',
        ], '', '&', PHP_QUERY_RFC3986);
        $res = $this->request('GET', '/methods?' . $query, null, []);
        $methods = $res['body']['_embedded']['methods'] ?? [];
        return is_array($methods) ? $methods : [];
    }

    public function createPaymentLink(array $payload, string $idempotencyKey): array
    {
        $res = $this->request('POST', '/payment-links', $payload, ['Idempotency-Key: ' . $idempotencyKey]);
        return $res['body'];
    }

    /** @return array{status:int, body:array, replayed:bool} */
    private function request(string $method, string $path, ?array $body, array $extraHeaders): array
    {
        $headers = array_merge([
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: HouseOfDogsPayments/' . HODPAY_VERSION . ' PHP/' . PHP_VERSION,
        ], $extraHeaders);
        $json = null;
        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }

        $replayed = false;
        $ch = curl_init(self::BASE . $path);
        if ($ch === false) {
            throw new HodpayMollieException('Impossibile inizializzare la connessione a Mollie.', 0, null, null, true);
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$replayed): int {
                if (stripos($line, 'Idempotent-Replayed:') === 0 && stripos($line, 'true') !== false) {
                    $replayed = true;
                }
                return strlen($line);
            },
        ]);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            error_log("[hodpay] Mollie {$method} {$path}: errore di rete cURL #{$errno}");
            throw new HodpayMollieException('Impossibile contattare Mollie. Riprova tra qualche istante.', 0, null, null, true);
        }
        $decoded = json_decode((string)$raw, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status >= 200 && $status < 300) {
            return ['status' => $status, 'body' => $decoded, 'replayed' => $replayed];
        }

        $detail = isset($decoded['detail']) && is_string($decoded['detail']) ? $decoded['detail'] : null;
        $field = isset($decoded['field']) && is_string($decoded['field']) ? $decoded['field'] : null;
        error_log("[hodpay] Mollie {$method} {$path}: HTTP {$status}" . ($field ? " field={$field}" : '') . ($detail ? " detail={$detail}" : ''));

        $message = match (true) {
            $status === 401 => 'Chiave Mollie non accettata. Il venditore deve verificare la configurazione.',
            $status === 409 => 'Richiesta già in elaborazione. Attendi qualche secondo e riprova.',
            $status === 422 => 'Mollie ha rifiutato i dati inseriti' . ($detail ? ': ' . $detail : '.'),
            $status === 429 => 'Troppe richieste verso Mollie. Riprova tra un minuto.',
            $status >= 500  => 'Mollie non è raggiungibile al momento. Riprova tra poco.',
            default         => 'Mollie ha risposto con un errore' . ($detail ? ': ' . $detail : '.'),
        };
        throw new HodpayMollieException($message, $status, $field, $detail, false);
    }
}
