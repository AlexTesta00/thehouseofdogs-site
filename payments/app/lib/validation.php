<?php
/* Validazione lato server dei dati inseriti da commerciante e cliente. */

declare(strict_types=1);

function hodpay_clean_text(mixed $v, int $max): string
{
    $s = is_string($v) ? $v : '';
    $s = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s) ?? '';
    $s = preg_replace('/\s+/u', ' ', $s) ?? '';
    return hodpay_cut(trim($s), $max);
}

/** Nome cliente scritto dal commerciante. */
function hodpay_validate_customer_name(mixed $v): array
{
    $name = hodpay_clean_text($v, 100);
    if (hodpay_len($name) < 2) {
        return [null, 'Inserisci il nome del cliente (almeno 2 caratteri).'];
    }
    if (preg_match('/^[\d\s.,-]+$/u', $name)) {
        return [null, 'Il nome non può essere composto solo da numeri.'];
    }
    return [$name, null];
}

function hodpay_validate_amount(mixed $v, array $cfg): array
{
    $cents = hodpay_parse_amount_cents(is_string($v) ? $v : '');
    if ($cents === null) {
        return [null, 'Importo non valido. Usa il formato 120 oppure 120,50.'];
    }
    if ($cents < $cfg['min_amount_cents'] || $cents > $cfg['max_amount_cents']) {
        return [null, sprintf(
            'L\'importo deve essere compreso tra %s e %s.',
            hodpay_format_amount((int)$cfg['min_amount_cents'], $cfg['currency']),
            hodpay_format_amount((int)$cfg['max_amount_cents'], $cfg['currency'])
        )];
    }
    return [$cents, null];
}

/** Nome o cognome per Klarna: almeno 2 caratteri, non solo numeri. */
function hodpay_validate_person_name(mixed $v, string $label): array
{
    $s = hodpay_clean_text($v, 100);
    if (hodpay_len($s) < 2) {
        return [null, "$label: inserisci almeno 2 caratteri."];
    }
    if (preg_match('/^[\d\s.,-]+$/u', $s)) {
        return [null, "$label: non può contenere solo numeri."];
    }
    if (!preg_match('/^[\p{L}\p{M}\s\'\-.]+$/u', $s)) {
        return [null, "$label: usa solo lettere, spazi, apostrofi o trattini."];
    }
    return [$s, null];
}

function hodpay_validate_email(mixed $v): array
{
    $s = strtolower(hodpay_clean_text($v, 254));
    if ($s === '') {
        return [null, 'Email: campo obbligatorio.'];
    }
    if (!filter_var($s, FILTER_VALIDATE_EMAIL) || !preg_match('/^[\x21-\x7E]+$/', $s)) {
        return [null, 'Email: indirizzo non valido.'];
    }
    return [$s, null];
}

function hodpay_validate_postal_code(mixed $v, string $country): array
{
    $s = strtoupper(hodpay_clean_text($v, 16));
    if ($s === '') {
        return [null, 'CAP: campo obbligatorio.'];
    }
    $patterns = [
        'IT' => '/^\d{5}$/',
        'DE' => '/^\d{5}$/',
        'ES' => '/^\d{5}$/',
        'FI' => '/^\d{5}$/',
        'FR' => '/^\d{5}$/',
        'AT' => '/^\d{4}$/',
        'BE' => '/^\d{4}$/',
        'NL' => '/^\d{4} ?[A-Z]{2}$/',
        'PT' => '/^\d{4}-\d{3}$/',
        'IE' => '/^[A-Z0-9]{3} ?[A-Z0-9]{4}$/',
    ];
    $re = $patterns[$country] ?? '/^[A-Z0-9][A-Z0-9 \-]{1,14}$/';
    if (!preg_match($re, $s)) {
        return [null, $country === 'IT' ? 'CAP: devono essere 5 cifre.' : 'CAP: formato non valido per il paese scelto.'];
    }
    return [$s, null];
}

/**
 * Valida i dati di fatturazione del cliente. Restituisce [dati, errori].
 * I campi sono quelli richiesti da Mollie per Klarna; il paese deve essere tra quelli consentiti.
 */
function hodpay_validate_billing(array $in, array $cfg): array
{
    $errors = [];
    $out = [];

    $country = strtoupper(hodpay_clean_text($in['country'] ?? '', 2));
    if (!in_array($country, $cfg['allowed_countries'], true)) {
        $errors['country'] = 'Paese non supportato.';
        $country = $cfg['default_country'];
    }
    $out['country'] = $country;

    [$out['given_name'], $e] = hodpay_validate_person_name($in['given_name'] ?? '', 'Nome');
    if ($e) { $errors['given_name'] = $e; }
    [$out['family_name'], $e] = hodpay_validate_person_name($in['family_name'] ?? '', 'Cognome');
    if ($e) { $errors['family_name'] = $e; }
    [$out['email'], $e] = hodpay_validate_email($in['email'] ?? '');
    if ($e) { $errors['email'] = $e; }

    $street = hodpay_clean_text($in['street'] ?? '', 200);
    if (hodpay_len($street) < 3) {
        $errors['street'] = 'Via e numero civico: campo obbligatorio.';
    } elseif (!preg_match('/\d/', $street)) {
        $errors['street'] = 'Via e numero civico: indica anche il numero civico.';
    }
    $out['street'] = $street;

    [$out['postal_code'], $e] = hodpay_validate_postal_code($in['postal_code'] ?? '', $country);
    if ($e) { $errors['postal_code'] = $e; }

    $city = hodpay_clean_text($in['city'] ?? '', 100);
    if (hodpay_len($city) < 2) {
        $errors['city'] = 'Città: campo obbligatorio.';
    } elseif (!preg_match('/^[\p{L}\p{M}\s\'\-.]+$/u', $city)) {
        $errors['city'] = 'Città: usa solo lettere.';
    }
    $out['city'] = $city;

    return [$out, $errors];
}
