<?php
/* Email: un livello di invio minimo e i due messaggi spediti dopo una prenotazione
   (notifica all'esercente e riepilogo al cliente).

   mail_mode (config):
     "mail"  mail() di PHP, come il modulo contatti (invia.php): mittente sul dominio del sito
     "file"  ogni messaggio diventa un file .eml in data/outbox/ (test e anteprima locale: qui mail() non funziona)
     "off"   nessun invio
   Testo semplice UTF-8; oggetto e nome del mittente codificati RFC 2047; corpo in base64 a righe di 76 caratteri,
   così anche le righe lunghe con lettere accentate viaggiano sempre intatte. Nessun valore può aggiungere
   intestazioni: CR e LF vengono tolti o il messaggio viene rifiutato. */

declare(strict_types=1);

/** Valore d'intestazione su una riga: ogni carattere di controllo (CR e LF compresi) diventa uno spazio. */
function hodbook_mail_header_clean(string $value): string
{
    return trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '');
}

/** Indirizzo per To e Reply-To: solo email "sicure per le intestazioni", altrimenti eccezione (messaggio non inviato). */
function hodbook_mail_address(string $email): string
{
    $email = trim($email);
    if ($email === '' || preg_match('/[\x00-\x20\x7F]/', $email) || !hodbook_email_header_safe($email)) {
        throw new InvalidArgumentException('Indirizzo non utilizzabile in un\'intestazione email.');
    }
    return $email;
}

/**
 * Testo libero in un'intestazione (oggetto, nome del mittente) come encoded-word RFC 2047 UTF-8/base64.
 * Parole da massimo 45 byte (75 caratteri una volta codificate) senza mai spezzare un carattere UTF-8,
 * separate da una ripiegatura (CRLF + spazio) che i client ignorano quando ricompongono il testo.
 */
function hodbook_mail_encode_words(string $text): string
{
    $text = hodbook_mail_header_clean($text);
    if ($text === '') {
        return '';
    }
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chars)) {
        $chars = str_split($text);
    }
    $words = [];
    $chunk = '';
    foreach ($chars as $char) {
        if ($chunk !== '' && strlen($chunk . $char) > 45) {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
            $chunk = '';
        }
        $chunk .= $char;
    }
    $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
    return implode("\r\n ", $words);
}

/** Nome visualizzato del mittente: tra virgolette se ASCII stampabile, altrimenti encoded-word. */
function hodbook_mail_display_name(string $name): string
{
    $name = hodbook_mail_header_clean($name);
    if ($name === '') {
        return '';
    }
    if (preg_match('/^[\x20-\x7E]+\z/', $name)) {
        return '"' . addcslashes($name, '"\\') . '"';
    }
    return hodbook_mail_encode_words($name);
}

/**
 * Prepara un messaggio. $message: to, reply_to (facoltativo), subject, body (testo UTF-8, righe con \n),
 * tag (facoltativo, solo per il nome del file .eml). Restituisce:
 *   to, subject (codificato), headers (nome => valore, senza To e Subject), body (base64 a righe di 76),
 *   text (corpo in chiaro con CRLF), params ("-f<mittente>" se il mittente è un indirizzo "stretto", altrimenti ''), tag.
 * Lancia InvalidArgumentException se un indirizzo non è valido.
 */
function hodbook_mail_build(array $cfg, array $message, int $now): array
{
    $to = hodbook_mail_address((string)($message['to'] ?? ''));
    $from = hodbook_mail_address((string)$cfg['mail_from_address']);
    $domain = substr($from, (int)strrpos($from, '@') + 1);
    $name = hodbook_mail_display_name((string)$cfg['mail_from_name']);

    $headers = ['From' => ($name !== '' ? $name . ' ' : '') . '<' . $from . '>'];
    $replyTo = (string)($message['reply_to'] ?? '');
    if ($replyTo !== '') {
        $headers['Reply-To'] = hodbook_mail_address($replyTo);
    }
    $headers += [
        'Date'                      => date(DATE_RFC2822, $now),
        'Message-ID'                => '<' . bin2hex(random_bytes(12)) . '.' . $now . '@' . $domain . '>',
        'MIME-Version'              => '1.0',
        'Content-Type'              => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => 'base64',
        'Auto-Submitted'            => 'auto-generated',
    ];
    /* Ultima difesa: nessun valore può contenere un a capo che non sia una ripiegatura (CRLF + spazio). */
    foreach ($headers as $value) {
        if (preg_match('/\r(?!\n[ \t])|(?<!\r)\n|\x00/', $value)) {
            throw new InvalidArgumentException('Intestazione email non valida.');
        }
    }

    $text = str_replace(["\r\n", "\r"], "\n", (string)($message['body'] ?? ''));
    $text = str_replace("\n", "\r\n", $text);

    return [
        'to'      => $to,
        'subject' => hodbook_mail_encode_words((string)($message['subject'] ?? '')),
        'headers' => $headers,
        'body'    => rtrim(chunk_split(base64_encode($text), 76, "\r\n")),
        'text'    => $text,
        'params'  => hodbook_email_strict($from) ? '-f' . $from : '',
        'tag'     => preg_replace('/[^A-Za-z0-9\-]+/', '', (string)($message['tag'] ?? '')) ?? '',
    ];
}

/** Trasporto "mail": mail() di PHP. $mailFn sostituisce mail() nei test (stessi argomenti). */
function hodbook_mail_transport_mail(array $built, ?callable $mailFn = null): bool
{
    if ($mailFn === null) {
        if (!function_exists('mail')) {
            return false;
        }
        $mailFn = 'mail';
    }
    return $built['params'] !== ''
        ? (bool)$mailFn($built['to'], $built['subject'], $built['body'], $built['headers'], $built['params'])
        : (bool)$mailFn($built['to'], $built['subject'], $built['body'], $built['headers']);
}

/** Trasporto "file": il messaggio diventa data/outbox/<data>-<tag>-<casuale>.eml, con il corpo in chiaro (8bit). */
function hodbook_mail_transport_file(array $built, int $now): bool
{
    $dir = HODBOOK_DATA_DIR . '/outbox';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Cartella outbox non scrivibile.');
    }
    $headers = $built['headers'];
    $headers['Content-Transfer-Encoding'] = '8bit';
    $lines = ['To: ' . $built['to'], 'Subject: ' . $built['subject']];
    foreach ($headers as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }
    $file = $dir . '/' . date('Ymd-His', $now) . '-' . ($built['tag'] !== '' ? $built['tag'] . '-' : '') . bin2hex(random_bytes(3)) . '.eml';
    if (@file_put_contents($file, implode("\r\n", $lines) . "\r\n\r\n" . $built['text'] . "\r\n", LOCK_EX) === false) {
        throw new RuntimeException('Impossibile salvare il messaggio in outbox.');
    }
    return true;
}

/**
 * Consegna un messaggio secondo mail_mode. Con "off" non fa nulla e restituisce false.
 * $transport (solo test) sostituisce il trasporto: riceve il messaggio preparato e restituisce true/false.
 * Può lanciare eccezioni (indirizzo non valido, disco non scrivibile): chi chiama decide come gestirle.
 */
function hodbook_mail_send(array $cfg, array $message, int $now, ?callable $transport = null): bool
{
    if ($cfg['mail_mode'] === 'off') {
        return false;
    }
    $built = hodbook_mail_build($cfg, $message, $now);
    if ($transport !== null) {
        return (bool)$transport($built);
    }
    return $cfg['mail_mode'] === 'file' ? hodbook_mail_transport_file($built, $now) : hodbook_mail_transport_mail($built);
}

/* ---------- Le due email della prenotazione ---------- */

/** [notifica all'esercente, riepilogo al cliente] per una prenotazione appena creata. $token è quello del link. */
function hodbook_booking_messages(array $cfg, array $booking, string $token): array
{
    $reference = (string)$booking['reference'];
    $product = (string)$booking['product_name'];
    $amount = hodbook_format_amount((int)$booking['amount_cents'], (string)$booking['currency']);
    $link = $cfg['site_url'] . '/prenotazione.php?c=' . $token;
    $fullName = trim($booking['first_name'] . ' ' . $booking['last_name']);

    $merchant = [
        'to'       => $cfg['merchant_email'],
        'reply_to' => (string)$booking['email'],
        'subject'  => 'Nuova richiesta di prenotazione: ' . $product . ' - ' . $fullName . ' (' . $reference . ')',
        'tag'      => $reference . '-esercente',
        'body'     => implode("\n", [
            'Nuova richiesta di prenotazione dal sito.',
            '',
            'Cucciolo: ' . $product,
            'Caparra: ' . $amount,
            'Codice di prenotazione (causale): ' . $reference,
            '',
            'Nome: ' . $booking['first_name'],
            'Cognome: ' . $booking['last_name'],
            'Email: ' . $booking['email'],
            'Telefono: ' . $booking['phone'],
            'Residenza: ' . $booking['residence'],
            'Codice fiscale: ' . $booking['tax_code'],
            '',
            'Condizioni di vendita accettate il: ' . date('d/m/Y H:i', (int)$booking['terms_accepted_at']),
            'Richiesta inviata il: ' . date('d/m/Y H:i', (int)$booking['created_at']),
            '',
            'Pagina della prenotazione (stato del bonifico):',
            $link,
            '',
            'Al cliente abbiamo indicato le coordinate bancarie e che sono accettati solo bonifici istantanei.',
        ]) . "\n",
    ];

    $bank = [
        'DATI PER IL BONIFICO',
        'Intestatario: ' . $cfg['bank_account_holder'],
        'IBAN: ' . hodbook_iban_format($cfg['bank_iban']),
    ];
    if ($cfg['bank_bic'] !== '') {
        $bank[] = 'BIC: ' . $cfg['bank_bic'];
    }
    array_push($bank, 'Banca: ' . $cfg['bank_name'], 'Importo: ' . $amount, 'Causale: ' . $reference);

    $customer = [
        'to'       => (string)$booking['email'],
        'reply_to' => $cfg['merchant_email'],
        'subject'  => 'Abbiamo ricevuto la tua richiesta di prenotazione (' . $reference . ')',
        'tag'      => $reference . '-cliente',
        'body'     => implode("\n", array_merge([
            'Ciao ' . $booking['first_name'] . ',',
            '',
            'abbiamo ricevuto la tua richiesta di prenotazione. Ecco il riepilogo.',
            '',
            'Cucciolo: ' . $product,
            'Caparra: ' . $amount,
            'Codice di prenotazione: ' . $reference,
            '',
        ], $bank, [
            '',
            'IMPORTANTE',
            HODBOOK_INSTANT_TRANSFER_RULE,
            'Scrivi nella causale solo il codice di prenotazione, senza altro testo.',
            '',
            hodbook_webhook_configured($cfg)
                ? 'Puoi controllare lo stato della prenotazione in qualsiasi momento da questa pagina:'
                : 'Puoi ritrovare questi dati in qualsiasi momento da questa pagina:',
            $link,
            '',
            'La caparra non è rimborsabile in caso di rinuncia. La prenotazione è regolata dalle nostre Condizioni di vendita:',
            $cfg['site_url'] . '/termini.php',
            '',
            'Per qualsiasi domanda rispondi a questa email o scrivici su WhatsApp al ' . hodbook_phone_display($cfg) . '.',
            '',
            $cfg['merchant_name'],
        ])) . "\n",
    ];

    return [$merchant, $customer];
}

/**
 * Invia le due email di una prenotazione appena creata. Non lancia mai eccezioni e non blocca nulla: un errore
 * diventa una riga nel log privato (quale email, id della prenotazione, tipo di errore; nessun dato personale).
 * Se l'email al cliente parte e c'è $db, la prenotazione lo registra (la pagina può dirlo al cliente).
 * Restituisce ['merchant' => bool, 'customer' => bool]; con mail_mode "off" entrambi false e nessun invio.
 */
function hodbook_booking_send_emails(array $cfg, array $booking, string $token, int $now, ?PDO $db = null, ?callable $transport = null): array
{
    $result = ['merchant' => false, 'customer' => false];
    if ($cfg['mail_mode'] === 'off') {
        return $result;
    }
    $id = (int)$booking['id'];
    try {
        [$merchant, $customer] = hodbook_booking_messages($cfg, $booking, $token);
    } catch (Throwable $e) {
        hodbook_log(sprintf('email non preparate per la prenotazione #%d (%s)', $id, get_class($e)));
        return $result;
    }
    $failed = [];
    foreach (['merchant' => [$merchant, 'esercente'], 'customer' => [$customer, 'cliente']] as $kind => [$message, $label]) {
        try {
            $result[$kind] = hodbook_mail_send($cfg, $message, $now, $transport);
            if (!$result[$kind]) {
                $failed[] = $label . ' (rifiutata dal trasporto)';
            }
        } catch (Throwable $e) {
            $failed[] = $label . ' (' . get_class($e) . ')';
        }
    }
    if ($failed !== []) {
        hodbook_log(sprintf('email NON inviata per la prenotazione #%d, modalità %s: %s', $id, $cfg['mail_mode'], implode(', ', $failed)));
    } else {
        hodbook_log(sprintf('email inviate per la prenotazione #%d (esercente e cliente, modalità %s)', $id, $cfg['mail_mode']));
    }
    if ($result['customer'] && $db !== null) {
        try {
            hodbook_booking_mark_customer_email_sent($db, $id, $now);
        } catch (Throwable $e) {
            error_log('[hodbook] email cliente non registrata: ' . get_class($e) . ': ' . $e->getMessage());
        }
    }
    return $result;
}
