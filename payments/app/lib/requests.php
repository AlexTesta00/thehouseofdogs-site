<?php
/* Richieste di pagamento: la riga interna che collega nome+importo al token del cliente. */

declare(strict_types=1);

/**
 * Crea la richiesta e restituisce [token in chiaro, riga].
 * Il token in chiaro viene mostrato una sola volta al commerciante dentro il link.
 */
function hodpay_request_create(string $customerName, int $amountCents, array $cfg): array
{
    $db = hodpay_db();
    $token = hodpay_random_token();
    $now = time();
    $st = $db->prepare('INSERT INTO payment_requests
        (token_hash, customer_name, amount_cents, currency, description, mode, status, idempotency_key, created_at, expires_at)
        VALUES (:h, :n, :a, :c, :d, :m, "pending", :k, :ca, :ea)');
    $st->execute([
        ':h'  => hodpay_token_hash($token),
        ':n'  => $customerName,
        ':a'  => $amountCents,
        ':c'  => $cfg['currency'],
        ':d'  => $cfg['purchase_description'],
        ':m'  => hodpay_mode($cfg),
        ':k'  => hodpay_uuid4(),
        ':ca' => $now,
        ':ea' => $now + (int)$cfg['customer_link_hours'] * 3600,
    ]);
    $row = hodpay_request_by_id((int)$db->lastInsertId());
    return [$token, $row];
}

function hodpay_request_by_id(int $id): ?array
{
    $st = hodpay_db()->prepare('SELECT * FROM payment_requests WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function hodpay_request_by_token(string $token): ?array
{
    if (!hodpay_valid_token_format($token)) {
        return null;
    }
    $st = hodpay_db()->prepare('SELECT * FROM payment_requests WHERE token_hash = :h');
    $st->execute([':h' => hodpay_token_hash($token)]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function hodpay_request_is_expired(array $row, ?int $now = null): bool
{
    return (int)$row['expires_at'] <= ($now ?? time());
}

function hodpay_request_update(int $id, array $fields): void
{
    if ($fields === []) {
        return;
    }
    $allowed = ['status', 'billing_json', 'klarna_available', 'allowed_methods', 'mollie_link_id',
                'mollie_link_url', 'attempts', 'last_error', 'completed_at', 'idempotency_key'];
    $set = [];
    $params = [':id' => $id];
    foreach ($fields as $k => $v) {
        if (!in_array($k, $allowed, true)) {
            throw new InvalidArgumentException("Campo non aggiornabile: $k");
        }
        $set[] = "$k = :$k";
        $params[":$k"] = $v;
    }
    hodpay_db()->prepare('UPDATE payment_requests SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
}

function hodpay_customer_link(string $token): string
{
    return hodpay_base_url() . '/pay.php?t=' . $token;
}

/** Pulizia periodica: elimina le righe scadute da oltre 90 giorni e i contatori vecchi. */
function hodpay_housekeeping(): void
{
    if (random_int(1, 50) !== 1) {
        return;
    }
    $db = hodpay_db();
    $db->prepare('DELETE FROM payment_requests WHERE expires_at < :cut')->execute([':cut' => time() - 90 * 86400]);
    $db->prepare('DELETE FROM rate_limits WHERE ts < :cut')->execute([':cut' => time() - 86400]);
}
