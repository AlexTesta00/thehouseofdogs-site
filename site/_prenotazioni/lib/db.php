<?php
/* SQLite tramite PDO (WAL, busy_timeout). Il file vive nella cartella privata data/; lo schema si aggiorna da solo all'apertura. */

declare(strict_types=1);

const HODBOOK_SCHEMA_VERSION = 2;

/** Connessione al database dell'app, aperta una volta per richiesta. */
function hodbook_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!is_dir(HODBOOK_DATA_DIR) && !@mkdir(HODBOOK_DATA_DIR, 0750, true) && !is_dir(HODBOOK_DATA_DIR)) {
        throw new RuntimeException('Cartella dati non scrivibile.');
    }
    return $pdo = hodbook_db_open(HODBOOK_DATA_DIR . '/prenotazioni.sqlite');
}

function hodbook_db_open(string $path): PDO
{
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('Estensione PHP pdo_sqlite non disponibile.');
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    hodbook_db_migrate($pdo);
    return $pdo;
}

/**
 * Esegue $fn dentro una transazione BEGIN IMMEDIATE: il lock di scrittura si prende subito,
 * così due richieste concorrenti si mettono in fila (busy_timeout) invece di andare in stallo.
 * In caso di eccezione la transazione viene annullata e l'eccezione rilanciata.
 */
function hodbook_db_tx(PDO $db, callable $fn): mixed
{
    $db->exec('BEGIN IMMEDIATE');
    try {
        $result = $fn($db);
        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
            /* transazione già chiusa: niente da annullare */
        }
        throw $e;
    }
}

function hodbook_db_is_unique_violation(PDOException $e): bool
{
    return (string)$e->getCode() === '23000' && str_contains($e->getMessage(), 'UNIQUE');
}

/**
 * Migrazioni numerate con PRAGMA user_version, serializzate da BEGIN IMMEDIATE. Ogni passo porta lo schema
 * dalla versione precedente alla sua: un database nuovo li esegue tutti in fila, uno esistente solo i mancanti.
 */
function hodbook_db_migrate(PDO $db): void
{
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() >= HODBOOK_SCHEMA_VERSION) {
        return;
    }
    hodbook_db_tx($db, static function (PDO $db): void {
        $version = (int)$db->query('PRAGMA user_version')->fetchColumn();
        if ($version < 1) {
            /* Prenotazioni. Il token del link non viene mai salvato in chiaro: solo il suo sha256.
               Nome prodotto e importo sono copiati alla creazione: cambiare la config non altera le prenotazioni esistenti. */
            $db->exec('CREATE TABLE IF NOT EXISTS bookings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token_hash TEXT NOT NULL UNIQUE,
                reference TEXT NOT NULL UNIQUE,
                product_key TEXT NOT NULL,
                product_name TEXT NOT NULL,
                amount_cents INTEGER NOT NULL,
                currency TEXT NOT NULL,
                first_name TEXT NOT NULL,
                last_name TEXT NOT NULL,
                email TEXT NOT NULL,
                phone TEXT NOT NULL,
                terms_accepted_at INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT \'pending\',
                received_cents INTEGER,
                paid_tx_id TEXT,
                paid_at INTEGER,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_bookings_status_created ON bookings (status, created_at)');

            /* Webhook già ricevuti (sha256 del corpo grezzo): una consegna ripetuta non viene rielaborata. */
            $db->exec('CREATE TABLE IF NOT EXISTS revolut_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                body_hash TEXT NOT NULL UNIQUE,
                event TEXT NOT NULL,
                tx_id TEXT,
                received_at INTEGER NOT NULL
            )');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_events_received ON revolut_events (received_at)');

            /* Transazioni Revolut viste dai webhook. state_event_ts = timestamp (microsecondi) dell'evento che ha
               impostato lo stato attuale: un evento più vecchio non sovrascrive mai uno stato più recente. */
            $db->exec('CREATE TABLE IF NOT EXISTS revolut_transactions (
                tx_id TEXT PRIMARY KEY,
                state TEXT,
                state_event_ts INTEGER,
                amount_cents INTEGER,
                currency TEXT,
                reference_text TEXT,
                booking_id INTEGER,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_tx_booking ON revolut_transactions (booking_id)');

            /* Limite di frequenza del modulo: IP solo come hash con segreto applicativo. */
            $db->exec('CREATE TABLE IF NOT EXISTS rate_limits (
                bucket TEXT NOT NULL,
                ts INTEGER NOT NULL
            )');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_rate_bucket ON rate_limits (bucket, ts)');
        }
        if ($version < 2) {
            /* Residenza e codice fiscale richiesti dal modulo; data di invio dell'email di riepilogo al cliente.
               Le righe già presenti (solo database di sviluppo) restano valide con valori vuoti. */
            $columns = array_column($db->query('PRAGMA table_info(bookings)')->fetchAll(), 'name');
            if (!in_array('residence', $columns, true)) {
                $db->exec("ALTER TABLE bookings ADD COLUMN residence TEXT NOT NULL DEFAULT ''");
            }
            if (!in_array('tax_code', $columns, true)) {
                $db->exec("ALTER TABLE bookings ADD COLUMN tax_code TEXT NOT NULL DEFAULT ''");
            }
            if (!in_array('customer_email_sent_at', $columns, true)) {
                $db->exec('ALTER TABLE bookings ADD COLUMN customer_email_sent_at INTEGER');
            }
            /* Limite di prenotazioni per indirizzo email nelle ultime 24 ore. */
            $db->exec('CREATE INDEX IF NOT EXISTS idx_bookings_email_created ON bookings (email, created_at)');
        }
        $db->exec('PRAGMA user_version = ' . HODBOOK_SCHEMA_VERSION);
    });
}
