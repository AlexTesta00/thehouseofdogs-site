<?php
/* House of Dogs - prenotazione con bonifico, passo 2: la pagina personale della prenotazione (link con token).
   GET prenotazione.php?c=<token>              stato, riepilogo, dati per il bonifico, link da salvare
   GET prenotazione.php?c=<token>&format=json  solo {"status":"pending|incoming|paid|review"}, nessun dato personale.
   Il token (64 caratteri esadecimali) è l'unica chiave di accesso: nel database ne esiste solo l'hash. */

declare(strict_types=1);

require_once __DIR__ . '/_prenotazioni/bootstrap.php';

hodbook_send_headers();

$cfg = hodbook_config();
$json = ($_GET['format'] ?? '') === 'json';
hodbook_json_mode($json);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET');
    if ($json) {
        hodbook_json_response(405, ['error' => 'method_not_allowed']);
    }
    hodbook_render_page('Richiesta non valida', hodbook_back_link()
        . '<h1 class="font-display text-3xl sm:text-4xl mt-8">Richiesta non valida</h1>', ['status' => 405]);
}

$tokenRaw = $_GET['c'] ?? '';
$token = is_string($tokenRaw) ? strtolower(trim($tokenRaw)) : '';
$booking = hodbook_valid_token_format($token) ? hodbook_booking_by_token(hodbook_db(), $token) : null;

if ($booking === null) {
    if ($json) {
        hodbook_json_response(404, ['error' => 'not_found']);
    }
    hodbook_render_page('Prenotazione non trovata', hodbook_back_link()
        . '<p class="eyebrow mt-8">Prenotazione</p>'
        . '<h1 class="font-display text-3xl sm:text-4xl mt-3">Prenotazione non trovata</h1>'
        . '<p class="mt-4">Il link non è corretto oppure la prenotazione non esiste più. Controlla di averlo copiato per intero, oppure scrivici su WhatsApp indicando il tuo codice di prenotazione.</p>'
        . '<div class="mt-6 flex flex-wrap items-center gap-4">'
        . hodbook_wa_button($cfg, 'Ciao! Non riesco ad aprire la pagina della mia prenotazione.', 'Scrivici su WhatsApp')
        . '<a href="./" class="text-sm">Vai al sito</a></div>'
        . hodbook_legal_links(), ['status' => 404]);
}

$status = hodbook_booking_status($booking);
if ($json) {
    hodbook_json_response(200, ['status' => $status]);
}

/* ---------- Pagina ---------- */

$info = hodbook_status_info($status);
/* Aggiornamento automatico dello stato: esiste solo se è configurato il webhook della banca. Senza, la pagina
   non promette nulla che non possa mantenere: la ricezione viene confermata dal venditore. */
$auto = hodbook_webhook_configured($cfg);
$currency = (string)$booking['currency'];
$amountCents = (int)$booking['amount_cents'];
$amount = hodbook_format_amount($amountCents, $currency);
$reference = (string)$booking['reference'];
$productName = (string)$booking['product_name'];
$pageUrl = hodbook_absolute_url($cfg, 'prenotazione.php?c=' . $token);

$explain = match ($status) {
    'incoming' => '<p class="mt-2">Abbiamo visto il tuo bonifico. Appena la banca lo completa la caparra risulta ricevuta: non devi fare altro.</p>',
    'paid'     => '<p class="mt-2">'
        . ($booking['paid_at'] !== null ? 'Caparra ricevuta il ' . hodbook_e(hodbook_date((int)$booking['paid_at'])) . '. ' : '')
        . 'Ti scriviamo noi per i prossimi passi: documenti, consegna e saldo.</p>',
    'review'   => '<p class="mt-2">Importo ricevuto: <strong class="nowrap">'
        . hodbook_e($booking['received_cents'] !== null ? hodbook_format_amount((int)$booking['received_cents'], $currency) : '-')
        . '</strong>. Importo della caparra: <strong class="nowrap">' . hodbook_e($amount) . '</strong>.</p>',
    default    => $auto
        ? '<p class="mt-2">Fai il bonifico istantaneo della caparra con i dati qui sotto: quando lo riceviamo, questa pagina lo mostra.</p>'
        : '<p class="mt-2">Fai il bonifico istantaneo della caparra con i dati qui sotto. Appena lo vediamo sul conto ti confermiamo noi la ricezione, via email o WhatsApp.</p>',
};
$live = $auto && in_array($status, ['pending', 'incoming'], true)
    ? '<p class="mt-2 text-sm" data-js-only hidden>La pagina controlla lo stato da sola ogni 20 secondi.</p>'
      . '<noscript><p class="mt-2 text-sm">Ricarica la pagina per vedere lo stato aggiornato.</p></noscript>'
    : '';

$statusBox = '<div class="status ' . $info['class'] . ' mt-6" role="status">'
    . '<p class="st-label"><span class="st-dot" aria-hidden="true"></span><span>' . hodbook_e($info['label']) . '</span></p>'
    . $explain . $live
    . '</div>';

$summary = '<section class="box mt-6" aria-labelledby="riepilogo">'
    . '<h2 id="riepilogo">Riepilogo</h2>'
    . '<dl class="kv mt-4">'
    . '<div><dt>Prenotazione</dt><dd><span class="val">' . hodbook_e($productName) . '</span></dd></div>'
    . '<div><dt>Importo della caparra</dt><dd><span class="val nowrap">' . hodbook_e($amount) . '</span></dd></div>'
    . '<div><dt>Codice di prenotazione</dt><dd><span class="val val-code copyable">' . hodbook_e($reference) . '</span></dd></div>'
    . '<div><dt>Prenotata il</dt><dd><span class="val">' . hodbook_e(hodbook_date((int)$booking['created_at'])) . '</span></dd></div>'
    . '</dl></section>';

$bank = '';
$bankCss = '';
if ($status !== 'paid') {
    /* Uno o più conti (bank.php): con più conti il cliente ne sceglie uno; importo e causale sono gli stessi. */
    $accounts = hodbook_bank_accounts($cfg);
    if ($accounts !== []) {
        $bank = hodbook_bank_section($accounts, $amount, hodbook_amount_plain($amountCents), $reference, $auto);
        $bankCss = hodbook_bank_section_css($accounts);
    } else {
        $bank = '<section class="box mt-6"><h2>Dati per il bonifico</h2>'
            . '<p class="mt-2">I dati per il bonifico non sono disponibili in questo momento: scrivici su WhatsApp indicando il codice <strong>' . hodbook_e($reference) . '</strong> e ti rispondiamo in giornata.</p></section>';
    }
}

$saveLink = '<section class="box mt-6" aria-labelledby="salva">'
    . '<h2 id="salva">Salva questo link</h2>'
    . '<p class="mt-1 text-sm">Salva il link di questa pagina (nei preferiti o in una nota): ti serve per '
    . ($auto ? 'controllare lo stato della prenotazione' : 'ritrovare i dati del bonifico') . ' quando vuoi.</p>'
    . '<p class="mt-3 text-sm copyable" style="overflow-wrap:anywhere;color:var(--text-secondary)">' . hodbook_e($pageUrl) . '</p>'
    . '<button type="button" class="copy-btn mt-3" data-copy="' . hodbook_e($pageUrl) . '" data-done="Link copiato" hidden>Copia link</button>'
    . '</section>';

$waText = 'Ciao! Ho prenotato sul sito: ' . $productName . '. Codice prenotazione: ' . $reference . '.';
$contact = '<div class="mt-8">'
    . '<p class="text-sm muted">' . ($status === 'paid' ? 'Per qualsiasi domanda scrivici:' : 'Hai fatto il bonifico o hai una domanda? Scrivici:') . '</p>'
    . '<div class="mt-3">' . hodbook_wa_button($cfg, $waText, 'Avvisaci su WhatsApp') . '</div>'
    . '</div>';

/* Riga sull'email di riepilogo: solo se l'invio è attivo e il messaggio è stato davvero consegnato al sistema di posta. */
$mailLine = ($cfg['mail_mode'] !== 'off' && ($booking['customer_email_sent_at'] ?? null) !== null)
    ? '<p class="mt-3 text-sm muted">Ti abbiamo inviato un\'email di riepilogo a <strong>' . hodbook_e(hodbook_mask_email((string)$booking['email']))
      . '</strong>. Se non la trovi controlla la posta indesiderata.</p>'
    : '';

$body = hodbook_back_link()
    . '<p class="eyebrow mt-8">Prenotazione ' . hodbook_e($reference) . '</p>'
    . '<h1 class="font-display text-3xl sm:text-4xl mt-3">Ciao ' . hodbook_e((string)$booking['first_name']) . ', grazie per la prenotazione</h1>'
    . $mailLine
    . $statusBox
    . $summary
    . $bank
    . $saveLink
    . $contact
    . '<p id="copy-live" class="sr-only" aria-live="polite"></p>'
    . hodbook_legal_links();

$statusUrl = 'prenotazione.php?c=' . $token . '&format=json';
hodbook_render_page('Prenotazione ' . $reference, $body, [
    'script' => true,
    /* Senza webhook lo stato non cambia da solo: niente interrogazioni periodiche dal browser. */
    'attrs'  => $auto ? ' data-status="' . hodbook_e($status) . '" data-status-url="' . hodbook_e($statusUrl) . '"' : '',
    'css'    => $bankCss,
]);
