<?php
/* SQLite tramite PDO. Il file vive nella cartella privata data/. */

declare(strict_types=1);

function hodpay_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('Estensione PHP pdo_sqlite non disponibile.');
    }
    if (!is_dir(HODPAY_DATA_DIR) && !@mkdir(HODPAY_DATA_DIR, 0750, true)) {
        throw new RuntimeException('Cartella dati non scrivibile: ' . HODPAY_DATA_DIR);
    }
    $path = HODPAY_DATA_DIR . '/payments.sqlite';
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    hodpay_db_migrate($pdo);
    return $pdo;
}

function hodpay_db_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS payment_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token_hash TEXT NOT NULL UNIQUE,
        customer_name TEXT NOT NULL,
        amount_cents INTEGER NOT NULL,
        currency TEXT NOT NULL,
        description TEXT NOT NULL,
        mode TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "pending",
        idempotency_key TEXT NOT NULL,
        billing_json TEXT,
        klarna_available INTEGER,
        allowed_methods TEXT,
        mollie_link_id TEXT,
        mollie_link_url TEXT,
        attempts INTEGER NOT NULL DEFAULT 0,
        last_error TEXT,
        created_at INTEGER NOT NULL,
        expires_at INTEGER NOT NULL,
        completed_at INTEGER
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_requests_expires ON payment_requests (expires_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (
        bucket TEXT NOT NULL,
        ts INTEGER NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rate_bucket ON rate_limits (bucket, ts)');
}
