<?php
/* Importi: sempre centesimi interi. Nessun float nei calcoli. */

declare(strict_types=1);

/**
 * Converte un importo digitato dal commerciante in centesimi.
 * Accetta "120", "120,50", "120.5", "€ 120,50", "1 200". Rifiuta separatori di migliaia
 * ambigui come "1.200" o "1,200" (potrebbero valere 1.200 € oppure 1,2 €).
 * Restituisce null se il formato non è riconosciuto.
 */
function hodpay_parse_amount_cents(string $input): ?int
{
    $s = trim($input);
    $s = str_replace(['€', 'EUR', 'eur', "\u{00A0}", ' '], '', $s);
    if ($s === '' || strlen($s) > 20) {
        return null;
    }
    if (!preg_match('/^(\d{1,7})(?:[.,](\d{1,2}))?$/', $s, $m)) {
        return null;
    }
    $euros = (int)$m[1];
    $dec = $m[2] ?? '';
    $cents = $dec === '' ? 0 : (int)str_pad($dec, 2, '0');
    return $euros * 100 + $cents;
}

/** Valore nel formato richiesto da Mollie: stringa con due decimali e punto. */
function hodpay_mollie_value(int $cents): string
{
    $sign = $cents < 0 ? '-' : '';
    $cents = abs($cents);
    return $sign . intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

/** Formato italiano per lo schermo: 1.250,00 € */
function hodpay_format_amount(int $cents, string $currency = 'EUR'): string
{
    $v = number_format($cents / 100, 2, ',', '.');
    return $currency === 'EUR' ? $v . ' €' : $v . ' ' . $currency;
}
