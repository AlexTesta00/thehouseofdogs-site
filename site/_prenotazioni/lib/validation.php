<?php
/* Validazione e normalizzazione: testo, dati del modulo di prenotazione, IBAN, BIC, segreti webhook.
   Il file definisce solo funzioni e non dipende dal resto dell'app: il deploy GitHub lo include da solo
   per controllare IBAN e segreti prima di generare config.php. */

declare(strict_types=1);

/** Lunghezza in caratteri, anche senza estensione mbstring. */
function hodbook_len(string $s): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($s, 'UTF-8');
    }
    $n = preg_match_all('/./su', $s);
    return is_int($n) ? $n : strlen($s);
}

/** Taglio a $max caratteri, anche senza estensione mbstring. */
function hodbook_cut(string $s, int $max): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max, 'UTF-8');
    }
    if (preg_match('/^.{0,' . max(0, $max) . '}/su', $s, $m)) {
        return $m[0];
    }
    return substr($s, 0, $max);
}

/** Testo su una riga: UTF-8 valido, niente caratteri di controllo o invisibili, spazi compressi. */
function hodbook_clean_text(mixed $v, int $max): string
{
    if (!is_string($v) || $v === '' || !preg_match('//u', $v)) {
        return '';
    }
    $s = preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{2028}\x{2029}\x{FEFF}]/u', ' ', $v) ?? '';
    $s = preg_replace('/\s+/u', ' ', $s) ?? '';
    return hodbook_cut(trim($s), $max);
}

/* ---------- Modulo di prenotazione ---------- */

/**
 * Nome o cognome: 2-100 caratteri, solo lettere, spazi, apostrofi e trattini.
 * L'apostrofo tipografico (quello che iPhone inserisce da solo) diventa un apostrofo semplice.
 * Restituisce [valore normalizzato, null] oppure [null, messaggio di errore].
 */
function hodbook_validate_name(mixed $v, string $label): array
{
    $s = hodbook_clean_text($v, 200);
    $s = str_replace(["\u{2019}", "\u{2018}", "\u{02BC}", "\u{00B4}", '`'], "'", $s);
    $s = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}"], '-', $s);
    if ($s === '') {
        return [null, "Inserisci il {$label}."];
    }
    if (hodbook_len($s) > 100) {
        return [null, "Il {$label} è troppo lungo (massimo 100 caratteri)."];
    }
    if (!preg_match("/^[\\p{L}\\p{M}' \\-]+\\z/u", $s)) {
        return [null, "Il {$label} può contenere solo lettere, spazi, apostrofi e trattini."];
    }
    if (hodbook_len($s) < 2 || preg_match_all('/\p{L}/u', $s) < 2) {
        return [null, "Il {$label} deve avere almeno 2 lettere."];
    }
    return [$s, null];
}

/** Email in caratteri ASCII, salvata in minuscolo. */
function hodbook_validate_email(mixed $v): array
{
    $s = strtolower(hodbook_clean_text($v, 300));
    if ($s === '') {
        return [null, "Inserisci l'email."];
    }
    if (strlen($s) > 254 || !preg_match('/^[\x21-\x7E]+\z/', $s) || filter_var($s, FILTER_VALIDATE_EMAIL) === false) {
        return [null, "L'email non sembra valida: ricontrollala."];
    }
    return [$s, null];
}

/**
 * Telefono: si tolgono spazi, punti, trattini, barre e parentesi; restano 6-15 cifre con un + facoltativo davanti.
 * Restituisce il numero normalizzato, ad esempio "+393771234567".
 */
function hodbook_validate_phone(mixed $v): array
{
    $s = hodbook_clean_text($v, 100);
    if ($s === '') {
        return [null, 'Inserisci il numero di telefono.'];
    }
    $n = preg_replace('/[\s.\-\/()]+/u', '', $s) ?? '';
    if (!preg_match('/^\+?\d{6,15}\z/', $n)) {
        return [null, 'Il numero di telefono non sembra valido: scrivi solo cifre, con il + davanti se usi il prefisso internazionale.'];
    }
    return [$n, null];
}

/** Casella "Ho letto e accetto le Condizioni di vendita". */
function hodbook_checkbox_on(mixed $v): bool
{
    return $v === '1' || $v === 'on';
}

/**
 * Valida il modulo di prenotazione. $in sono i campi POST (nome, cognome, email, telefono, termini, tipo),
 * $products la mappa dei prodotti in configurazione. Restituisce [dati normalizzati, errori per campo].
 */
function hodbook_validate_booking_form(array $in, array $products): array
{
    $errors = [];
    $out = [];

    [$out['first_name'], $e] = hodbook_validate_name($in['nome'] ?? null, 'nome');
    if ($e !== null) {
        $errors['nome'] = $e;
    }
    [$out['last_name'], $e] = hodbook_validate_name($in['cognome'] ?? null, 'cognome');
    if ($e !== null) {
        $errors['cognome'] = $e;
    }
    [$out['email'], $e] = hodbook_validate_email($in['email'] ?? null);
    if ($e !== null) {
        $errors['email'] = $e;
    }
    [$out['phone'], $e] = hodbook_validate_phone($in['telefono'] ?? null);
    if ($e !== null) {
        $errors['telefono'] = $e;
    }
    if (!hodbook_checkbox_on($in['termini'] ?? null)) {
        $errors['termini'] = 'Per prenotare devi accettare le Condizioni di vendita.';
    }
    $tipo = is_string($in['tipo'] ?? null) ? $in['tipo'] : '';
    if ($tipo === '' || !isset($products[$tipo])) {
        $errors['tipo'] = 'Scegli il cucciolo da prenotare.';
    }
    $out['product_key'] = $tipo;

    return [$out, $errors];
}

/* ---------- Coordinate bancarie ---------- */

/** IBAN senza spazi né trattini, in maiuscolo. */
function hodbook_iban_normalize(string $s): string
{
    return strtoupper(preg_replace('/[\s\-\x{00A0}]+/u', '', $s) ?? '');
}

/** Lunghezze ufficiali per i paesi SEPA più comuni: un errore di battitura si vede subito. */
function hodbook_iban_lengths(): array
{
    return [
        'AD' => 24, 'AT' => 20, 'BE' => 16, 'BG' => 22, 'CH' => 21, 'CY' => 28, 'CZ' => 24, 'DE' => 22,
        'DK' => 18, 'EE' => 20, 'ES' => 24, 'FI' => 18, 'FR' => 27, 'GB' => 22, 'GI' => 23, 'GR' => 27,
        'HR' => 21, 'HU' => 28, 'IE' => 22, 'IS' => 26, 'IT' => 27, 'LI' => 21, 'LT' => 20, 'LU' => 20,
        'LV' => 21, 'MC' => 27, 'MT' => 31, 'NL' => 18, 'NO' => 15, 'PL' => 28, 'PT' => 25, 'RO' => 24,
        'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27, 'VA' => 22,
    ];
}

/** IBAN valido: formato, lunghezza del paese (se nota) e cifre di controllo mod-97 (ISO 13616). */
function hodbook_iban_valid(string $iban): bool
{
    $iban = hodbook_iban_normalize($iban);
    if (!preg_match('/^([A-Z]{2})\d{2}[A-Z0-9]{11,30}\z/', $iban, $m)) {
        return false;
    }
    $lengths = hodbook_iban_lengths();
    if (isset($lengths[$m[1]]) && strlen($iban) !== $lengths[$m[1]]) {
        return false;
    }
    /* Le prime 4 posizioni vanno in coda, le lettere diventano numeri (A=10 ... Z=35), il resto mod 97 deve valere 1.
       Il calcolo procede una cifra alla volta: nessun numero grande, nessun float. */
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $remainder = 0;
    $len = strlen($rearranged);
    for ($i = 0; $i < $len; $i++) {
        $o = ord($rearranged[$i]);
        $digits = ($o >= 65 && $o <= 90) ? (string)($o - 55) : $rearranged[$i];
        $dl = strlen($digits);
        for ($j = 0; $j < $dl; $j++) {
            $remainder = ($remainder * 10 + (int)$digits[$j]) % 97;
        }
    }
    return $remainder === 1;
}

/** IBAN a gruppi di 4 caratteri, come lo stampano le banche: IT60 X054 2811 ... */
function hodbook_iban_format(string $iban): string
{
    $iban = hodbook_iban_normalize($iban);
    return $iban === '' ? '' : trim(chunk_split($iban, 4, ' '));
}

/** BIC/SWIFT: 8 o 11 caratteri (banca, paese, località, filiale facoltativa). */
function hodbook_bic_valid(string $bic): bool
{
    return (bool)preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?\z/', $bic);
}

/** Segreto di firma dei webhook Revolut: inizia con wsk_ (Revolut Business > API > Webhooks). */
function hodbook_webhook_secret_valid(string $s): bool
{
    return (bool)preg_match('/^wsk_\S{8,}\z/', $s);
}
