<?php
/* Webhook Revolut Business (Webhooks v2): verifica della firma, controllo del timestamp, elaborazione degli eventi
   TransactionCreated e TransactionStateChanged. Nessuna chiamata all'API Revolut, nessuna notifica a nessuno.

   Fatti dalla documentazione Revolut:
   - intestazioni Revolut-Request-Timestamp (millisecondi) e Revolut-Signature ("v1=<hex>", più firme separate
     da virgole quando sono attivi più segreti);
   - payload firmato = "v1." + timestamp + "." + corpo grezzo; firma = "v1=" + hex(HMAC-SHA256(segreto, payload));
   - gli eventi possono arrivare più volte e fuori ordine; su errore HTTP Revolut ritenta 3 volte ogni 10 minuti. */

declare(strict_types=1);

const HODBOOK_WEBHOOK_MAX_BODY = 65536;

/**
 * Verifica la firma: la firma calcolata con uno qualsiasi dei segreti configurati deve coincidere con una
 * delle firme dell'intestazione. Confronto a tempo costante. Non controlla l'età del timestamp (vedi sotto).
 */
function hodbook_revolut_signature_valid(array $secrets, string $timestamp, string $rawBody, string $signatureHeader): bool
{
    if (!preg_match('/^\d{1,20}\z/', $timestamp) || $signatureHeader === '' || strlen($signatureHeader) > 4096) {
        return false;
    }
    $given = [];
    foreach (explode(',', $signatureHeader) as $part) {
        $part = strtolower(trim($part));
        if ($part !== '') {
            $given[] = $part;
        }
    }
    $payload = 'v1.' . $timestamp . '.' . $rawBody;
    $valid = false;
    foreach ($secrets as $secret) {
        if (!is_string($secret) || $secret === '') {
            continue;
        }
        $expected = 'v1=' . hash_hmac('sha256', $payload, $secret);
        foreach ($given as $sig) {
            if (hash_equals($expected, $sig)) {
                $valid = true;
            }
        }
    }
    return $valid;
}

/** Il timestamp (millisecondi) non deve distare da adesso più della tolleranza, in avanti o all'indietro. */
function hodbook_revolut_timestamp_fresh(string $timestamp, int $nowMs, int $toleranceSeconds): bool
{
    if (!preg_match('/^\d{1,16}\z/', $timestamp)) {
        return false;
    }
    return abs($nowMs - (int)$timestamp) <= $toleranceSeconds * 1000;
}

/**
 * Timestamp ISO 8601 dell'evento ("2023-05-09T16:36:38.028960Z", "2023-04-06T12:21:49.865Z") in microsecondi
 * dall'epoch, per confrontare eventi con precisioni diverse. null se non interpretabile.
 */
function hodbook_revolut_parse_time(mixed $iso): ?int
{
    if (!is_string($iso) || !preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(?:[.,](\d{1,9}))?(Z|[+\-]\d{2}:?\d{2})\z/i', trim($iso), $m)) {
        return null;
    }
    $zone = strtoupper($m[4]) === 'Z' ? '+00:00' : substr($m[4], 0, 3) . ':' . substr($m[4], -2);
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s P', $m[1] . ' ' . $m[2] . ' ' . $zone);
    if ($dt === false || $dt->format('Y-m-d H:i:s') !== $m[1] . ' ' . $m[2]) {
        return null;
    }
    $micros = isset($m[3]) && $m[3] !== '' ? (int)substr(str_pad($m[3], 6, '0'), 0, 6) : 0;
    return $dt->getTimestamp() * 1000000 + $micros;
}

/** Importo della gamba (es. 550, 550.5, -10) in centesimi interi; null se non è un numero ragionevole. */
function hodbook_revolut_amount_cents(mixed $amount): ?int
{
    if (is_string($amount) && is_numeric(trim($amount))) {
        $amount = (float)trim($amount);
    }
    if (!is_int($amount) && !is_float($amount)) {
        return null;
    }
    $f = (float)$amount;
    if (!is_finite($f) || abs($f) > 1.0e9) {
        return null;
    }
    return (int)round($f * 100);
}

function hodbook_revolut_clean_id(mixed $v): ?string
{
    return is_string($v) && preg_match('/^[A-Za-z0-9_\-]{1,100}\z/', $v) ? $v : null;
}

function hodbook_revolut_clean_state(mixed $v): ?string
{
    if (!is_string($v)) {
        return null;
    }
    $s = strtolower(trim($v));
    return preg_match('/^[a-z_]{1,32}\z/', $s) ? $s : null;
}

/** Stati finali negativi: il denaro non è arrivato o è tornato indietro. */
function hodbook_revolut_state_failed(string $state): bool
{
    return in_array($state, ['declined', 'failed', 'reverted'], true);
}

/** Ordine nel ciclo di vita, usato solo a parità di timestamp. Stati sconosciuti = non definitivi. */
function hodbook_revolut_state_rank(?string $state): int
{
    return match ($state) {
        'created'              => 0,
        'pending'              => 1,
        'completed'            => 3,
        'declined', 'failed'   => 4,
        'reverted'             => 5,
        null                   => -1,
        default                => 2,
    };
}

/** true se lo stato portato dall'evento ($newTs, $newState) deve sostituire quello salvato. */
function hodbook_revolut_state_is_newer(?int $storedTs, ?string $storedState, int $newTs, string $newState): bool
{
    if ($storedState === null || $storedTs === null) {
        return true;
    }
    if ($newTs !== $storedTs) {
        return $newTs > $storedTs;
    }
    return hodbook_revolut_state_rank($newState) > hodbook_revolut_state_rank($storedState);
}

/**
 * Gamba in entrata della transazione: importo positivo, valuta uguale a quella configurata e, se configurati,
 * conto tra revolut_account_ids. Restituisce ['amount_cents' => int, 'currency' => string] o null.
 */
function hodbook_revolut_incoming_leg(array $data, array $cfg): ?array
{
    foreach (is_array($data['legs'] ?? null) ? $data['legs'] : [] as $leg) {
        if (!is_array($leg)) {
            continue;
        }
        $cents = hodbook_revolut_amount_cents($leg['amount'] ?? null);
        if ($cents === null || $cents <= 0) {
            continue;
        }
        $currency = is_string($leg['currency'] ?? null) ? strtoupper(trim($leg['currency'])) : '';
        if ($currency !== $cfg['currency']) {
            continue;
        }
        if ($cfg['revolut_account_ids'] !== []) {
            $account = is_string($leg['account_id'] ?? null) ? strtolower(trim($leg['account_id'])) : '';
            if (!in_array($account, $cfg['revolut_account_ids'], true)) {
                continue;
            }
        }
        return ['amount_cents' => $cents, 'currency' => $currency];
    }
    return null;
}

/** Testi in cui cercare il codice: la causale (reference) e la descrizione di ogni gamba. */
function hodbook_revolut_match_texts(array $data): array
{
    $texts = [];
    if (is_string($data['reference'] ?? null)) {
        $texts[] = $data['reference'];
    }
    foreach (is_array($data['legs'] ?? null) ? $data['legs'] : [] as $leg) {
        if (is_array($leg) && is_string($leg['description'] ?? null)) {
            $texts[] = $leg['description'];
        }
    }
    return $texts;
}

/** Prima prenotazione esistente il cui codice compare nei testi (ogni testo è analizzato da solo). */
function hodbook_revolut_find_booking(PDO $db, array $texts, string $prefix): ?array
{
    $seen = [];
    foreach ($texts as $text) {
        foreach (hodbook_reference_extract($text, $prefix) as $code) {
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $booking = hodbook_booking_by_reference($db, $code);
            if ($booking !== null) {
                return $booking;
            }
        }
    }
    return null;
}

function hodbook_revolut_tx_by_id(PDO $db, string $txId): ?array
{
    $st = $db->prepare('SELECT * FROM revolut_transactions WHERE tx_id = :id');
    $st->execute([':id' => $txId]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

/** TransactionCreated: salva la transazione in entrata, la collega alla prenotazione e ricalcola lo stato. */
function hodbook_revolut_on_created(PDO $db, array $cfg, array $data, int $eventTs, int $now): array
{
    $txId = hodbook_revolut_clean_id($data['id'] ?? null);
    if ($txId === null) {
        return ['result' => 'ignored', 'reason' => 'id mancante'];
    }
    $leg = hodbook_revolut_incoming_leg($data, $cfg);
    if ($leg === null) {
        return ['result' => 'ignored', 'reason' => 'nessuna entrata in ' . $cfg['currency'], 'tx_id' => $txId];
    }
    $state = hodbook_revolut_clean_state($data['state'] ?? null) ?? 'created';
    $reference = hodbook_clean_text($data['reference'] ?? '', 140);
    $booking = hodbook_revolut_find_booking($db, hodbook_revolut_match_texts($data), $cfg['reference_prefix']);

    $row = hodbook_revolut_tx_by_id($db, $txId);
    if ($row === null) {
        $db->prepare('INSERT INTO revolut_transactions
            (tx_id, state, state_event_ts, amount_cents, currency, reference_text, booking_id, created_at, updated_at)
            VALUES (:id, :st, :ts, :amt, :cur, :ref, :bk, :now, :now)')
           ->execute([
               ':id' => $txId, ':st' => $state, ':ts' => $eventTs, ':amt' => $leg['amount_cents'], ':cur' => $leg['currency'],
               ':ref' => $reference, ':bk' => $booking['id'] ?? null, ':now' => $now,
           ]);
        $bookingId = $booking !== null ? (int)$booking['id'] : null;
    } else {
        /* Riga già presente: di solito uno "stub" creato da un TransactionStateChanged arrivato prima. */
        $fields = [
            'amount_cents'   => $leg['amount_cents'],
            'currency'       => $leg['currency'],
            'reference_text' => $reference,
            'updated_at'     => $now,
        ];
        $bookingId = $row['booking_id'] !== null ? (int)$row['booking_id'] : null;
        if ($bookingId === null && $booking !== null) {
            $bookingId = (int)$booking['id'];
            $fields['booking_id'] = $bookingId;
        }
        $storedTs = $row['state_event_ts'] !== null ? (int)$row['state_event_ts'] : null;
        $storedState = $row['state'] !== null ? (string)$row['state'] : null;
        if (hodbook_revolut_state_is_newer($storedTs, $storedState, $eventTs, $state)) {
            $fields['state'] = $state;
            $fields['state_event_ts'] = $eventTs;
        }
        hodbook_revolut_tx_update($db, $txId, $fields);
    }

    return hodbook_revolut_result($db, $txId, $bookingId, $now);
}

/** TransactionStateChanged: aggiorna lo stato rispettando l'ordine dei timestamp (riga "stub" se sconosciuta). */
function hodbook_revolut_on_state_changed(PDO $db, array $data, int $eventTs, int $now): array
{
    $txId = hodbook_revolut_clean_id($data['id'] ?? null);
    $state = hodbook_revolut_clean_state($data['new_state'] ?? null);
    if ($txId === null || $state === null) {
        return ['result' => 'ignored', 'reason' => 'id o new_state mancanti'];
    }
    $row = hodbook_revolut_tx_by_id($db, $txId);
    if ($row === null) {
        $db->prepare('INSERT INTO revolut_transactions (tx_id, state, state_event_ts, created_at, updated_at)
            VALUES (:id, :st, :ts, :now, :now)')
           ->execute([':id' => $txId, ':st' => $state, ':ts' => $eventTs, ':now' => $now]);
        return hodbook_revolut_result($db, $txId, null, $now);
    }
    $storedTs = $row['state_event_ts'] !== null ? (int)$row['state_event_ts'] : null;
    $storedState = $row['state'] !== null ? (string)$row['state'] : null;
    if (hodbook_revolut_state_is_newer($storedTs, $storedState, $eventTs, $state)) {
        hodbook_revolut_tx_update($db, $txId, ['state' => $state, 'state_event_ts' => $eventTs, 'updated_at' => $now]);
    }
    return hodbook_revolut_result($db, $txId, $row['booking_id'] !== null ? (int)$row['booking_id'] : null, $now);
}

function hodbook_revolut_tx_update(PDO $db, string $txId, array $fields): void
{
    $allowed = ['state', 'state_event_ts', 'amount_cents', 'currency', 'reference_text', 'booking_id', 'updated_at'];
    $set = [];
    $params = [':id' => $txId];
    foreach ($fields as $col => $value) {
        if (!in_array($col, $allowed, true)) {
            throw new InvalidArgumentException('Colonna non aggiornabile: ' . $col);
        }
        $set[] = $col . ' = :' . $col;
        $params[':' . $col] = $value;
    }
    $db->prepare('UPDATE revolut_transactions SET ' . implode(', ', $set) . ' WHERE tx_id = :id')->execute($params);
}

/** Ricalcola la prenotazione collegata (se c'è) e riassume l'esito per il log e per i test. */
function hodbook_revolut_result(PDO $db, string $txId, ?int $bookingId, int $now): array
{
    $tx = hodbook_revolut_tx_by_id($db, $txId);
    $booking = $bookingId !== null ? hodbook_booking_refresh($db, $bookingId, $now) : null;
    return [
        'result'         => 'processed',
        'tx_id'          => $txId,
        'tx_state'       => $tx['state'] ?? null,
        'booking_id'     => $booking !== null ? (int)$booking['id'] : null,
        'booking_status' => $booking !== null ? (string)$booking['status'] : null,
    ];
}

/**
 * Elabora un webhook già autenticato dentro una transazione del database: o tutto o niente, così un errore
 * (es. database bloccato) produce un 500 e Revolut ritenta. Le consegne ripetute (stesso corpo) non vengono rielaborate.
 * $fallbackMicros: timestamp da usare se l'evento non ha un "timestamp" leggibile (quello dell'intestazione firmata).
 */
function hodbook_webhook_process(PDO $db, array $cfg, string $raw, array $payload, int $fallbackMicros, int $now): array
{
    $event = is_string($payload['event'] ?? null) ? hodbook_clean_text($payload['event'], 64) : '';
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $eventTs = hodbook_revolut_parse_time($payload['timestamp'] ?? null) ?? $fallbackMicros;
    $txId = hodbook_revolut_clean_id($data['id'] ?? null);

    return hodbook_db_tx($db, static function (PDO $db) use ($cfg, $raw, $event, $data, $eventTs, $txId, $now): array {
        $st = $db->prepare('INSERT OR IGNORE INTO revolut_events (body_hash, event, tx_id, received_at) VALUES (:h, :e, :t, :r)');
        $st->execute([':h' => hash('sha256', $raw), ':e' => $event !== '' ? $event : '(nessuno)', ':t' => $txId, ':r' => $now]);
        if ($st->rowCount() === 0) {
            return ['result' => 'duplicate', 'event' => $event, 'tx_id' => $txId];
        }
        $result = match ($event) {
            'TransactionCreated'      => hodbook_revolut_on_created($db, $cfg, $data, $eventTs, $now),
            'TransactionStateChanged' => hodbook_revolut_on_state_changed($db, $data, $eventTs, $now),
            default                   => ['result' => 'ignored', 'reason' => 'evento non gestito'],
        };
        return ['event' => $event] + $result;
    });
}

/**
 * Gestione completa di una richiesta al webhook, indipendente da $_SERVER (testabile).
 * $headers: nome => valore (maiuscole indifferenti). Restituisce ['status' => codice HTTP, 'body' => array JSON].
 */
function hodbook_webhook_handle(string $method, array $headers, string $raw, array $cfg, int $nowMs, ?PDO $db = null): array
{
    if (strtoupper($method) !== 'POST') {
        return ['status' => 405, 'body' => ['ok' => false, 'error' => 'method_not_allowed']];
    }
    if (!hodbook_webhook_configured($cfg)) {
        return ['status' => 503, 'body' => ['ok' => false, 'error' => 'not_configured']];
    }
    if (strlen($raw) > HODBOOK_WEBHOOK_MAX_BODY) {
        return ['status' => 413, 'body' => ['ok' => false, 'error' => 'too_large']];
    }
    $h = array_change_key_case($headers, CASE_LOWER);
    $timestamp = trim(is_string($h['revolut-request-timestamp'] ?? null) ? $h['revolut-request-timestamp'] : '');
    $signature = trim(is_string($h['revolut-signature'] ?? null) ? $h['revolut-signature'] : '');
    if (!hodbook_revolut_signature_valid($cfg['revolut_webhook_secrets'], $timestamp, $raw, $signature)
        || !hodbook_revolut_timestamp_fresh($timestamp, $nowMs, (int)$cfg['timestamp_tolerance_seconds'])) {
        hodbook_log('webhook rifiutato (401): firma o timestamp non validi');
        return ['status' => 401, 'body' => ['ok' => false, 'error' => 'unauthorized']];
    }
    $object = json_decode($raw, false, 64);
    $payload = $object instanceof stdClass ? json_decode($raw, true, 64) : null;
    if (!is_array($payload)) {
        hodbook_log('webhook rifiutato (400): JSON non valido');
        return ['status' => 400, 'body' => ['ok' => false, 'error' => 'bad_json']];
    }
    try {
        $db ??= hodbook_db();
        $result = hodbook_webhook_process($db, $cfg, $raw, $payload, (int)$timestamp * 1000, intdiv($nowMs, 1000));
    } catch (Throwable $e) {
        error_log('[hodbook] webhook: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        hodbook_log('webhook errore interno (500): ' . get_class($e));
        return ['status' => 500, 'body' => ['ok' => false, 'error' => 'server_error']];
    }
    hodbook_log(sprintf(
        'webhook %s evento=%s tx=%s prenotazione=%s stato=%s',
        $result['result'] ?? '?',
        ($result['event'] ?? '') !== '' ? $result['event'] : '-',
        $result['tx_id'] ?? '-',
        isset($result['booking_id']) ? '#' . $result['booking_id'] : '-',
        $result['booking_status'] ?? '-'
    ));
    return ['status' => 200, 'body' => ['ok' => true], 'result' => $result];
}
