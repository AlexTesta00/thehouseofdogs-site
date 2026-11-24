<?php
/* Prenotazioni: codice causale, creazione, ricerca, stato calcolato dalle transazioni Revolut, pulizia periodica. */

declare(strict_types=1);

/* Alfabeto del codice: niente 0/O e 1/I, che al telefono o scritti a mano si confondono. */
const HODBOOK_REF_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
const HODBOOK_REF_LENGTH = 6;

const HODBOOK_STATUSES = ['pending', 'incoming', 'paid', 'review'];

/** Codice causale: prefisso + 6 caratteri casuali dell'alfabeto, senza separatori (es. HOD7K3F9Q). */
function hodbook_reference_generate(string $prefix): string
{
    $max = strlen(HODBOOK_REF_ALPHABET) - 1;
    $code = $prefix;
    for ($i = 0; $i < HODBOOK_REF_LENGTH; $i++) {
        $code .= HODBOOK_REF_ALPHABET[random_int(0, $max)];
    }
    return $code;
}

/** Maiuscolo e solo A-Z/0-9: "caparra hod-7k3 f9q" diventa "CAPARRAHOD7K3F9Q". */
function hodbook_normalize_match_text(string $s): string
{
    return preg_replace('/[^A-Z0-9]+/', '', strtoupper($s)) ?? '';
}

/**
 * Cerca codici di prenotazione dentro un testo libero (causale scritta dal cliente).
 * Il testo viene normalizzato e si prova ogni posizione in cui compare il prefisso: un codice è il prefisso
 * seguito da 6 caratteri dell'alfabeto. Restituisce i candidati nell'ordine in cui compaiono, senza doppioni.
 */
function hodbook_reference_extract(string $text, string $prefix, int $limit = 20): array
{
    $norm = hodbook_normalize_match_text($text);
    $prefix = hodbook_normalize_match_text($prefix);
    if ($norm === '' || $prefix === '') {
        return [];
    }
    $found = [];
    $plen = strlen($prefix);
    $offset = 0;
    while (count($found) < $limit && ($pos = strpos($norm, $prefix, $offset)) !== false) {
        $code = substr($norm, $pos + $plen, HODBOOK_REF_LENGTH);
        if (strlen($code) === HODBOOK_REF_LENGTH && strspn($code, HODBOOK_REF_ALPHABET) === HODBOOK_REF_LENGTH) {
            $found[$prefix . $code] = true;
        }
        $offset = $pos + 1;
    }
    return array_keys($found);
}

/**
 * Crea una prenotazione e restituisce [token in chiaro, riga]. Il token compare solo nel link mostrato al cliente.
 * Nome prodotto e importo vengono copiati dalla configurazione. In caso di collisione sul codice si riprova.
 * $customer: first_name, last_name, email, phone (già validati). $refGen serve ai test.
 */
function hodbook_booking_create(PDO $db, array $cfg, string $productKey, array $customer, int $now, ?callable $refGen = null): array
{
    $product = $cfg['products'][$productKey] ?? null;
    if (!is_array($product)) {
        throw new InvalidArgumentException('Prodotto sconosciuto.');
    }
    $refGen ??= static fn(string $prefix): string => hodbook_reference_generate($prefix);
    $sql = 'INSERT INTO bookings
        (token_hash, reference, product_key, product_name, amount_cents, currency, first_name, last_name, email, phone,
         terms_accepted_at, status, created_at, updated_at)
        VALUES (:th, :ref, :pk, :pn, :amt, :cur, :fn, :ln, :em, :ph, :terms, \'pending\', :now, :now)';
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $token = hodbook_random_token();
        try {
            /* Statement nuovo a ogni tentativo: dopo un vincolo violato pdo_sqlite non lo azzera e un nuovo bind fallirebbe. */
            $db->prepare($sql)->execute([
                ':th'    => hodbook_token_hash($token),
                ':ref'   => $refGen($cfg['reference_prefix']),
                ':pk'    => $productKey,
                ':pn'    => $product['name'],
                ':amt'   => $product['amount_cents'],
                ':cur'   => $cfg['currency'],
                ':fn'    => $customer['first_name'],
                ':ln'    => $customer['last_name'],
                ':em'    => $customer['email'],
                ':ph'    => $customer['phone'],
                ':terms' => $now,
                ':now'   => $now,
            ]);
        } catch (PDOException $e) {
            if (hodbook_db_is_unique_violation($e)) {
                continue;
            }
            throw $e;
        }
        $row = hodbook_booking_by_id($db, (int)$db->lastInsertId());
        if ($row === null) {
            throw new RuntimeException('Prenotazione non trovata dopo la creazione.');
        }
        return [$token, $row];
    }
    throw new RuntimeException('Impossibile generare un codice di prenotazione univoco.');
}

function hodbook_booking_by_id(PDO $db, int $id): ?array
{
    $st = $db->prepare('SELECT * FROM bookings WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

function hodbook_booking_by_token(PDO $db, string $token): ?array
{
    if (!hodbook_valid_token_format($token)) {
        return null;
    }
    $st = $db->prepare('SELECT * FROM bookings WHERE token_hash = :h');
    $st->execute([':h' => hodbook_token_hash($token)]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

function hodbook_booking_by_reference(PDO $db, string $reference): ?array
{
    $st = $db->prepare('SELECT * FROM bookings WHERE reference = :r');
    $st->execute([':r' => $reference]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

/** Stato noto dell'app; un valore inatteso nel database viene mostrato come "in attesa". */
function hodbook_booking_status(array $booking): string
{
    $s = (string)($booking['status'] ?? '');
    return in_array($s, HODBOOK_STATUSES, true) ? $s : 'pending';
}

/**
 * Funzione pura: calcola lo stato della prenotazione dalle transazioni Revolut collegate.
 * Restituisce solo le colonne da cambiare (array vuoto = nessun cambiamento).
 *
 *  - una transazione completed con l'importo esatto        -> paid (paid_at, paid_tx_id, received_cents)
 *  - completed con importo diverso (nessuna esatta)         -> review (received_cents = totale ricevuto)
 *  - solo transazioni created/pending o in stato sconosciuto -> incoming
 *  - nessuna, o solo declined/failed/reverted                -> pending, campi del pagamento azzerati
 *
 * Una prenotazione pagata resta pagata finché la transazione che l'ha pagata resta completed:
 * un'altra transazione non può declassarla. Se lo stato "paid" non è spiegato da nessuna transazione
 * collegata (ad esempio impostato a mano), non viene toccato. Il risultato non dipende dall'ordine degli eventi.
 *
 * $txs: righe di revolut_transactions con booking_id = id della prenotazione.
 */
function hodbook_booking_evaluate(array $booking, array $txs, int $now): array
{
    $amount = (int)$booking['amount_cents'];
    $currency = strtoupper((string)$booking['currency']);
    $paidTx = $booking['paid_tx_id'] !== null ? (string)$booking['paid_tx_id'] : null;
    $status = hodbook_booking_status($booking);

    $completed = [];
    $exact = [];
    $inFlight = false;
    $known = [];
    foreach ($txs as $tx) {
        $known[(string)$tx['tx_id']] = true;
        if ($tx['amount_cents'] === null) {
            continue;   /* importo ancora sconosciuto: non valutabile */
        }
        $state = (string)($tx['state'] ?? '');
        if ($state === 'completed') {
            $completed[] = $tx;
            if ((int)$tx['amount_cents'] === $amount && strtoupper((string)$tx['currency']) === $currency) {
                $exact[] = $tx;
            }
        } elseif (!hodbook_revolut_state_failed($state)) {
            $inFlight = true;   /* created, pending o stato sconosciuto: non definitivo */
        }
    }

    if ($status === 'paid' && ($paidTx === null || !isset($known[$paidTx]))) {
        return [];
    }

    if ($exact !== []) {
        $payer = null;
        foreach ($exact as $tx) {
            if ((string)$tx['tx_id'] === $paidTx) {
                $payer = $tx;
                break;
            }
        }
        if ($payer === null) {
            usort($exact, static fn(array $a, array $b): int => [(int)$a['created_at'], (string)$a['tx_id']] <=> [(int)$b['created_at'], (string)$b['tx_id']]);
            $payer = $exact[0];
        }
        $samePayer = $status === 'paid' && $paidTx === (string)$payer['tx_id'] && $booking['paid_at'] !== null;
        $target = [
            'status'         => 'paid',
            'paid_tx_id'     => (string)$payer['tx_id'],
            'received_cents' => $amount,
            'paid_at'        => $samePayer ? (int)$booking['paid_at'] : $now,
        ];
    } elseif ($completed !== []) {
        $received = null;
        foreach ($completed as $tx) {
            if (strtoupper((string)$tx['currency']) === $currency) {
                $received = ($received ?? 0) + (int)$tx['amount_cents'];
            }
        }
        $target = ['status' => 'review', 'paid_tx_id' => null, 'received_cents' => $received, 'paid_at' => null];
    } elseif ($inFlight) {
        $target = ['status' => 'incoming', 'paid_tx_id' => null, 'received_cents' => null, 'paid_at' => null];
    } else {
        $target = ['status' => 'pending', 'paid_tx_id' => null, 'received_cents' => null, 'paid_at' => null];
    }

    $changes = [];
    foreach ($target as $col => $value) {
        $current = $booking[$col] ?? null;
        if ($current !== null && is_int($value)) {
            $current = (int)$current;
        } elseif ($current !== null && is_string($value)) {
            $current = (string)$current;
        }
        if ($current !== $value) {
            $changes[$col] = $value;
        }
    }
    return $changes;
}

/** Ricalcola e salva lo stato della prenotazione. Restituisce la riga aggiornata, o null se non esiste. */
function hodbook_booking_refresh(PDO $db, int $bookingId, int $now): ?array
{
    $booking = hodbook_booking_by_id($db, $bookingId);
    if ($booking === null) {
        return null;
    }
    $st = $db->prepare('SELECT * FROM revolut_transactions WHERE booking_id = :id');
    $st->execute([':id' => $bookingId]);
    $changes = hodbook_booking_evaluate($booking, $st->fetchAll(), $now);
    if ($changes === []) {
        return $booking;
    }
    $changes['updated_at'] = $now;
    $set = [];
    $params = [':id' => $bookingId];
    foreach ($changes as $col => $value) {
        $set[] = $col . ' = :' . $col;
        $params[':' . $col] = $value;
    }
    $db->prepare('UPDATE bookings SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
    return hodbook_booking_by_id($db, $bookingId);
}

/**
 * Pulizia periodica (circa una richiesta su 50, oppure sempre con $force):
 *  - prenotazioni non pagate e non in verifica più vecchie di 12 mesi (con le loro transazioni);
 *  - contatori del limite di frequenza più vecchi di 24 ore;
 *  - impronte dei webhook e transazioni non collegate a prenotazioni più vecchie di 90 giorni.
 * Le prenotazioni pagate restano (obblighi contrattuali e fiscali).
 */
function hodbook_housekeeping(PDO $db, int $now, bool $force = false): void
{
    if (!$force && random_int(1, 50) !== 1) {
        return;
    }
    hodbook_db_tx($db, static function (PDO $db) use ($now): void {
        $cutBookings = $now - 365 * 86400;
        $db->prepare('DELETE FROM revolut_transactions WHERE booking_id IN
            (SELECT id FROM bookings WHERE status NOT IN (\'paid\', \'review\') AND created_at < :cut)')
           ->execute([':cut' => $cutBookings]);
        $db->prepare('DELETE FROM bookings WHERE status NOT IN (\'paid\', \'review\') AND created_at < :cut')
           ->execute([':cut' => $cutBookings]);
        $db->prepare('DELETE FROM rate_limits WHERE ts < :cut')->execute([':cut' => $now - 86400]);
        $db->prepare('DELETE FROM revolut_events WHERE received_at < :cut')->execute([':cut' => $now - 90 * 86400]);
        $db->prepare('DELETE FROM revolut_transactions WHERE booking_id IS NULL AND updated_at < :cut')
           ->execute([':cut' => $now - 90 * 86400]);
    });
}
