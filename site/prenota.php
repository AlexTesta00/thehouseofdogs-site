<?php
/* House of Dogs - prenotazione con bonifico istantaneo, passo 1: scelta del cucciolo e dati del cliente.
   GET  prenota.php?tipo=<chiave>   modulo (senza un tipo valido: elenco dei cuccioli)
   POST prenota.php                 crea la prenotazione, invia le due email (esercente e cliente)
                                    e porta (303) a prenotazione.php?c=<token>
   Niente cookie e niente sessioni: la protezione CSRF è un token firmato con la data di emissione.
   Limiti: 8 invii per IP all'ora, 3 prenotazioni per email in 24 ore (il modulo scrive all'indirizzo inserito).
   Un errore di invio email non blocca mai la prenotazione: finisce solo nel log privato.
   Se IBAN e intestatario non sono configurati il modulo non compare: resta la prenotazione via WhatsApp. */

declare(strict_types=1);

require_once __DIR__ . '/_prenotazioni/bootstrap.php';

hodbook_send_headers();

$cfg = hodbook_config();
$products = $cfg['products'];
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$isPost = $method === 'POST';

if (!$isPost && $method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, POST');
    hodbook_prenota_message($cfg, 'Richiesta non valida', '<p class="mt-4">Apri la pagina dal sito per prenotare.</p>', 405);
}

$tipoRaw = $isPost ? ($_POST['tipo'] ?? '') : ($_GET['tipo'] ?? '');
$tipo = is_string($tipoRaw) ? strtolower(trim($tipoRaw)) : '';
$product = ($tipo !== '' && isset($products[$tipo])) ? $products[$tipo] : null;

if (!hodbook_bank_configured($cfg)) {
    hodbook_prenota_unavailable($cfg, $product);
}

if (!$isPost) {
    if ($product === null) {
        hodbook_prenota_chooser($cfg);
    }
    $empty = ['nome' => '', 'cognome' => '', 'email' => '', 'telefono' => '', 'residenza' => '', 'codice_fiscale' => '', 'termini' => false];
    hodbook_prenota_form($cfg, $tipo, $product, $empty, [], null, hodbook_form_token(time()));
}

/* ---------- POST ---------- */

if (!hodbook_same_origin_check()) {
    hodbook_prenota_message($cfg, 'Richiesta non valida',
        '<p class="mt-4">Per sicurezza il modulo va inviato da questa pagina del sito. <a href="prenota.php' . ($product !== null ? '?tipo=' . hodbook_e(rawurlencode($tipo)) : '') . '">Torna al modulo</a> e riprova.</p>', 403);
}

$db = hodbook_db();
$now = time();

/* Massimo 8 invii (riusciti o no) per IP all'ora. L'IP si salva solo come hash con il segreto applicativo. */
if (!hodbook_rate_limit($db, hodbook_ip_bucket('prenota', hodbook_client_ip($cfg)), 8, 3600, $now)) {
    hodbook_prenota_message($cfg, 'Troppi tentativi',
        '<p class="mt-4">Hai inviato il modulo troppe volte in poco tempo. Riprova tra un\'ora, oppure prenota scrivendoci su WhatsApp: ti rispondiamo in giornata.</p>'
        . '<div class="mt-6">' . hodbook_wa_button($cfg, 'Ciao! Vorrei prenotare un cucciolo dal sito.', 'Prenota su WhatsApp') . '</div>', 429);
}
hodbook_housekeeping($db, $now);

if ($product === null) {
    hodbook_prenota_chooser($cfg, 'Scegli il cucciolo da prenotare.');
}

$values = [
    'nome'     => hodbook_clean_text($_POST['nome'] ?? '', 100),
    'cognome'  => hodbook_clean_text($_POST['cognome'] ?? '', 100),
    'email'    => hodbook_clean_text($_POST['email'] ?? '', 254),
    'telefono' => hodbook_clean_text($_POST['telefono'] ?? '', 40),
    'residenza' => hodbook_clean_text($_POST['residenza'] ?? '', 400),
    'codice_fiscale' => hodbook_clean_text($_POST['codice_fiscale'] ?? '', 40),
    'termini'  => hodbook_checkbox_on($_POST['termini'] ?? null),
];

/* Token del modulo: se è autentico e non scaduto lo si riusa anche nel modulo ripresentato (conserva la sua età). */
$submitted = $_POST['token'] ?? null;
$tokenState = hodbook_form_token_check($submitted, $now);
$formToken = in_array($tokenState, ['ok', 'too_fresh'], true) && is_string($submitted) ? $submitted : hodbook_form_token($now);

if ($tokenState !== 'ok') {
    $message = match ($tokenState) {
        'too_fresh' => 'Un attimo: controlla i dati e premi di nuovo il pulsante.',
        'expired'   => 'La pagina è rimasta aperta a lungo: controlla i dati e premi di nuovo il pulsante.',
        default     => 'Non siamo riusciti a verificare il modulo: controlla i dati e premi di nuovo il pulsante.',
    };
    hodbook_prenota_form($cfg, $tipo, $product, $values, [], hodbook_e($message), $formToken);
}

/* Honeypot: il campo nascosto "sito_web" lo compilano solo i bot. Nessuna prenotazione, messaggio generico. */
$honeypot = $_POST['sito_web'] ?? '';
if (!is_string($honeypot) || trim($honeypot) !== '') {
    hodbook_prenota_form($cfg, $tipo, $product, $values, [],
        hodbook_e('Non è stato possibile completare la prenotazione. Riprova oppure scrivici su WhatsApp.'), $formToken);
}

[$data, $errors] = hodbook_validate_booking_form(['tipo' => $tipo] + $_POST, $products);
if ($errors !== []) {
    hodbook_prenota_form($cfg, $tipo, $product, $values, $errors, null, $formToken);
}

/* Al massimo 3 prenotazioni per email in 24 ore: oltre, niente prenotazione e niente email. */
$created = hodbook_booking_create_limited($db, $cfg, $tipo, $data, $now);
if ($created === null) {
    hodbook_log('prenotazione rifiutata: limite di richieste per email raggiunto');
    hodbook_prenota_form($cfg, $tipo, $product, $values, [],
        hodbook_e('Hai già inviato più richieste con questa email. ')
        . '<a href="' . hodbook_e(hodbook_wa_url($cfg, 'Ciao! Vorrei prenotare: ' . $product['name'] . '.')) . '" target="_blank" rel="noopener noreferrer">Scrivici su WhatsApp</a>.',
        $formToken);
}
[$bookingToken, $booking] = $created;
hodbook_log(sprintf('prenotazione creata #%d codice=%s tipo=%s', (int)$booking['id'], $booking['reference'], $tipo));

/* Le due email partono dopo il salvataggio e prima del redirect; un errore non blocca nulla (solo una riga di log). */
hodbook_booking_send_emails($cfg, $booking, $bookingToken, $now, $db);

header('Location: prenotazione.php?c=' . $bookingToken, true, 303);
exit;

/* ---------- Pagine ---------- */

function hodbook_prenota_message(array $cfg, string $title, string $html, int $status): never
{
    hodbook_render_page($title, hodbook_back_link()
        . '<p class="eyebrow mt-8">Prenotazione</p>'
        . '<h1 class="font-display text-3xl sm:text-4xl mt-3">' . hodbook_e($title) . '</h1>'
        . $html
        . hodbook_legal_links(), ['status' => $status]);
}

/** Banca non configurata: niente modulo, si prenota su WhatsApp. HTTP 200. */
function hodbook_prenota_unavailable(array $cfg, ?array $product): never
{
    $what = $product !== null ? $product['name'] : 'un cucciolo';
    hodbook_render_page('Prenotazione', hodbook_back_link()
        . '<p class="eyebrow mt-8">Prenotazione</p>'
        . '<h1 class="font-display text-3xl sm:text-4xl mt-3">La prenotazione online non è disponibile in questo momento</h1>'
        . '<p class="mt-4">Puoi prenotare lo stesso scrivendoci su WhatsApp: ti rispondiamo in giornata con disponibilità, conferma scritta delle condizioni e dati per la caparra.</p>'
        . '<div class="mt-6">' . hodbook_wa_button($cfg, 'Ciao! Vorrei prenotare: ' . $what . '.', 'Prenota su WhatsApp') . '</div>'
        . hodbook_legal_links());
}

/** Elenco dei cuccioli prenotabili, ciascuno con la sua caparra. */
function hodbook_prenota_chooser(array $cfg, ?string $message = null): never
{
    $items = '';
    foreach ($cfg['products'] as $key => $p) {
        $items .= '<li><a class="choice" href="prenota.php?tipo=' . hodbook_e(rawurlencode((string)$key)) . '">'
            . '<span class="font-semibold">' . hodbook_e($p['name']) . '</span>'
            . '<span class="nowrap font-semibold text-accent">' . hodbook_e(hodbook_format_amount((int)$p['amount_cents'], $cfg['currency'])) . '</span>'
            . '</a></li>';
    }
    $alert = $message !== null ? '<div class="alert alert-error mt-6" role="alert"><p>' . hodbook_e($message) . '</p></div>' : '';
    hodbook_render_page('Prenota il tuo cucciolo', hodbook_back_link()
        . '<p class="eyebrow mt-8">Prenotazione con bonifico istantaneo</p>'
        . '<h1 class="font-display text-3xl sm:text-4xl mt-3">Quale cucciolo vuoi prenotare?</h1>'
        . '<p class="mt-3 muted">Scegli il tipo di cucciolo: ti chiediamo pochi dati e ti mostriamo subito i dati per il bonifico istantaneo della caparra.</p>'
        . $alert
        . '<ul class="mt-7 grid gap-3" role="list">' . $items . '</ul>'
        . '<p class="mt-8 text-sm muted">Hai domande prima di prenotare? <a href="' . hodbook_e(hodbook_wa_url($cfg, 'Ciao! Ho una domanda prima di prenotare un cucciolo.')) . '" target="_blank" rel="noopener noreferrer">Scrivici su WhatsApp</a>.</p>'
        . hodbook_legal_links());
}

/**
 * Modulo di prenotazione, con eventuali errori per campo e valori già inseriti (sempre escapati).
 * $formErrorHtml: messaggio generale già in HTML sicuro (testo passato da hodbook_e, eventuale link costruito qui).
 */
function hodbook_prenota_form(array $cfg, string $tipo, array $product, array $values, array $errors, ?string $formErrorHtml, string $token): never
{
    $amount = hodbook_format_amount((int)$product['amount_cents'], $cfg['currency']);

    $alert = '';
    if ($formErrorHtml !== null) {
        $alert = '<div class="alert alert-error" role="alert"><p>' . $formErrorHtml . '</p></div>';
    } elseif ($errors !== []) {
        $n = count($errors);
        $alert = '<div class="alert alert-error" role="alert"><p>' . ($n === 1 ? 'Controlla il campo evidenziato.' : 'Controlla i ' . $n . ' campi evidenziati.') . '</p></div>';
    }

    $fields = '<div class="grid gap-4 sm:grid-cols-2">'
        . hodbook_field('nome', 'Nome', $values, $errors, ['type' => 'text', 'autocomplete' => 'given-name', 'maxlength' => '100'])
        . hodbook_field('cognome', 'Cognome', $values, $errors, ['type' => 'text', 'autocomplete' => 'family-name', 'maxlength' => '100'])
        . '</div>'
        . hodbook_field('email', 'Email', $values, $errors, ['type' => 'email', 'autocomplete' => 'email', 'inputmode' => 'email', 'maxlength' => '254', 'spellcheck' => 'false'])
        . hodbook_field('telefono', 'Telefono', $values, $errors, ['type' => 'tel', 'autocomplete' => 'tel', 'inputmode' => 'tel', 'maxlength' => '40', 'placeholder' => 'Es. 333 123 4567'])
        . hodbook_field('residenza', 'Residenza', $values, $errors,
            ['rows' => '2', 'maxlength' => '200', 'autocomplete' => 'street-address', 'placeholder' => 'Es. Via Roma 12, 70022 Altamura (BA)'],
            'Via e numero civico, CAP, città e provincia', true)
        . hodbook_field('codice_fiscale', 'Codice fiscale', $values, $errors,
            ['type' => 'text', 'maxlength' => '16', 'autocapitalize' => 'characters', 'autocomplete' => 'off', 'spellcheck' => 'false', 'data-upper' => '1'],
            '16 caratteri: lo trovi sulla tessera sanitaria.');

    $termsError = $errors['termini'] ?? null;
    $termsAria = $termsError !== null ? ' aria-invalid="true" aria-describedby="f-termini-err"' : '';
    $checked = !empty($values['termini']) ? ' checked' : '';
    $terms = '<div>'
        . '<label class="flex items-start gap-3 text-sm" for="f-termini">'
        . '<input id="f-termini" type="checkbox" name="termini" value="1" required class="mt-0.5 h-5 w-5 shrink-0 accent-[#1470b3]"' . $checked . $termsAria . '>'
        . '<span>Ho letto e accetto le <a href="termini.php" target="_blank" rel="noopener">Condizioni di vendita</a></span>'
        . '</label>'
        . ($termsError !== null ? '<p id="f-termini-err" class="err">' . hodbook_e($termsError) . '</p>' : '')
        . '</div>';

    $body = hodbook_back_link()
        . '<p class="eyebrow mt-8">Prenotazione con bonifico istantaneo</p>'
        . '<h1 class="font-display text-3xl sm:text-4xl mt-3">Prenota il tuo cucciolo</h1>'
        . '<p class="mt-3 muted">Lascia i tuoi dati: ti assegniamo un codice di prenotazione e ti mostriamo subito i dati per versare la caparra con bonifico istantaneo. Ti mandiamo anche un riepilogo via email.</p>'
        . '<section class="box mt-7" aria-label="Cucciolo scelto">'
        . '<p class="text-xs font-semibold uppercase tracking-widest muted">Hai scelto</p>'
        . '<div class="mt-1.5 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">'
        . '<p class="text-lg font-bold">' . hodbook_e($product['name']) . '</p>'
        . '<p class="text-lg font-bold nowrap">' . hodbook_e($amount) . '</p>'
        . '</div>'
        . '<p class="mt-1 text-sm muted">Importo della caparra di prenotazione. <a href="prenota.php">Cambia cucciolo</a></p>'
        . '</section>'
        . '<form id="prenota-form" method="post" action="prenota.php" class="mt-7 grid gap-5" novalidate>'
        . $alert
        . '<input type="hidden" name="tipo" value="' . hodbook_e($tipo) . '">'
        . '<input type="hidden" name="token" value="' . hodbook_e($token) . '">'
        . '<div class="hp-field" aria-hidden="true"><label>Non compilare questo campo<input type="text" name="sito_web" tabindex="-1" autocomplete="off"></label></div>'
        . $fields
        . $terms
        . '<p class="text-sm muted">Usiamo questi dati solo per gestire la prenotazione, mandarti il riepilogo via email e ricontattarti, come spiegato nell\'<a href="privacy.php" target="_blank" rel="noopener">informativa privacy</a>.</p>'
        . '<div class="warn"><p>La caparra non è rimborsabile in caso di rinuncia. Prima del pagamento ti confermiamo sempre per iscritto cucciolo, prezzo totale, documenti e consegna.</p>'
        . '<p class="mt-2">' . hodbook_e(HODBOOK_INSTANT_TRANSFER_RULE) . '</p></div>'
        . '<button type="submit" id="prenota-submit" class="btn btn-primary rounded-full px-7 py-3.5 font-semibold w-full sm:w-auto sm:justify-self-start">Prenota e vedi i dati per il bonifico</button>'
        . '</form>'
        . '<p class="mt-8 text-sm muted">Preferisci parlarne prima? <a href="' . hodbook_e(hodbook_wa_url($cfg, 'Ciao! Vorrei informazioni prima di prenotare: ' . $product['name'] . '.')) . '" target="_blank" rel="noopener noreferrer">Scrivici su WhatsApp</a>.</p>'
        . hodbook_legal_links();

    hodbook_render_page('Prenota il tuo cucciolo', $body, ['script' => true]);
}
