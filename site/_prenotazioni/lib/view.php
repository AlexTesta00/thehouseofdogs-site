<?php
/* Pagine HTML condivise: stessa testa delle pagine legali (Inter self-hosted, Tailwind CDN, custom.css),
   intestazioni HTTP comuni, formati di importi e date, piccoli componenti. */

declare(strict_types=1);

function hodbook_e(mixed $s): string
{
    return htmlspecialchars(is_scalar($s) ? (string)$s : '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Modalità di risposta per il gestore delle eccezioni: JSON (webhook, stato) oppure pagina HTML. */
function hodbook_json_mode(?bool $set = null): bool
{
    static $json = false;
    if ($set !== null) {
        $json = $set;
    }
    return $json;
}

/** Intestazioni comuni alle pagine pubbliche. Il token sta nell'URL: nessun referrer verso altri siti. */
function hodbook_send_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
}

function hodbook_json_response(int $status, array $payload): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** 55000 -> "550,00 €" (formato italiano, solo aritmetica intera). */
function hodbook_format_amount(int $cents, string $currency = 'EUR'): string
{
    $sign = $cents < 0 ? '-' : '';
    $cents = abs($cents);
    $value = $sign . number_format(intdiv($cents, 100), 0, ',', '.') . ',' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    return $currency === 'EUR' ? $value . ' €' : $value . ' ' . $currency;
}

/** 55000 -> "550,00": il valore da incollare nel campo importo dell'app della banca (senza separatore delle migliaia). */
function hodbook_amount_plain(int $cents): string
{
    $cents = abs($cents);
    return intdiv($cents, 100) . ',' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

function hodbook_date(int $ts): string
{
    return date('d/m/Y', $ts) . ' alle ' . date('H:i', $ts);
}

/** Link wa.me con messaggio precompilato (un semplice collegamento: nessuna notifica automatica). */
function hodbook_wa_url(array $cfg, string $text): string
{
    return 'https://wa.me/' . $cfg['whatsapp_number'] . '?text=' . rawurlencode($text);
}

/** Etichetta e classe del riquadro di stato. */
function hodbook_status_info(string $status): array
{
    return match ($status) {
        'incoming' => ['label' => 'Bonifico in arrivo: la banca lo sta elaborando', 'class' => 'st-incoming'],
        'paid'     => ['label' => 'Caparra ricevuta. Grazie!', 'class' => 'st-paid'],
        'review'   => ['label' => 'Abbiamo ricevuto un bonifico con un importo diverso: ti contattiamo noi', 'class' => 'st-review'],
        default    => ['label' => 'In attesa del bonifico', 'class' => 'st-pending'],
    };
}

/** URL assoluto di una pagina nella stessa cartella dello script corrente (per mostrare e copiare il link). */
function hodbook_absolute_url(array $cfg, string $relative): string
{
    $scheme = hodbook_is_https($cfg) ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?\z/', $host)) {
        $host = 'www.thehouseofdog.it';
    }
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    return $scheme . '://' . $host . $dir . '/' . ltrim($relative, '/');
}

/* ---------- Componenti ---------- */

function hodbook_back_link(): string
{
    return '<a href="./" class="text-sm underline underline-offset-4" style="color:var(--accent)">← Torna al sito</a>';
}

function hodbook_legal_links(): string
{
    return '<p class="mt-10 text-sm muted">Leggi le <a href="termini.php">Condizioni di vendita</a> e l\'<a href="privacy.php">Informativa privacy</a>.</p>';
}

function hodbook_wa_button(array $cfg, string $text, string $label): string
{
    return '<a href="' . hodbook_e(hodbook_wa_url($cfg, $text)) . '" target="_blank" rel="noopener noreferrer" '
        . 'class="btn btn-wa rounded-full px-6 py-3 font-semibold inline-flex items-center justify-center gap-2">'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm5.4 14.1c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.3-.7-2.8-1.1-4.6-4-4.7-4.2-.1-.2-1.1-1.5-1.1-2.9s.7-2 1-2.3c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.9 2.1c.1.2.1.4 0 .6l-.4.6-.4.4c-.1.1-.3.3-.1.6.2.3.8 1.3 1.7 2.1 1.2 1.1 2.2 1.4 2.5 1.5.3.1.5.1.7-.1l1-1.2c.2-.3.4-.2.7-.1l2 1c.3.1.5.2.6.3 0 .1 0 .8-.1 1.4Z"/></svg>'
        . '<span>' . hodbook_e($label) . '</span></a>';
}

/**
 * Campo del modulo con etichetta, suggerimento facoltativo, valore già inserito (escapato) ed eventuale errore.
 * $multiline = true produce una textarea (es. residenza) invece di un input.
 */
function hodbook_field(string $name, string $label, array $values, array $errors, array $attrs = [], string $hint = '', bool $multiline = false): string
{
    $id = 'f-' . $name;
    $error = $errors[$name] ?? null;
    $extra = '';
    foreach ($attrs as $k => $v) {
        $extra .= ' ' . $k . '="' . hodbook_e($v) . '"';
    }
    $describedBy = [];
    if ($hint !== '') {
        $describedBy[] = $id . '-hint';
    }
    if ($error !== null) {
        $describedBy[] = $id . '-err';
    }
    $aria = ($error !== null ? ' aria-invalid="true"' : '') . ($describedBy !== [] ? ' aria-describedby="' . implode(' ', $describedBy) . '"' : '');
    $value = hodbook_e($values[$name] ?? '');
    $class = 'field w-full rounded-xl px-4 py-3 text-ink';
    $control = $multiline
        ? '<textarea id="' . $id . '" name="' . $name . '" required class="' . $class . '"' . $aria . $extra . '>' . $value . '</textarea>'
        : '<input id="' . $id . '" name="' . $name . '" value="' . $value . '" required class="' . $class . '"' . $aria . $extra . '>';
    return '<div>'
        . '<label for="' . $id . '" class="block text-sm font-semibold mb-1.5">' . hodbook_e($label) . ' <span class="text-accent" aria-hidden="true">*</span></label>'
        . ($hint !== '' ? '<p id="' . $id . '-hint" class="hint">' . hodbook_e($hint) . '</p>' : '')
        . $control
        . ($error !== null ? '<p id="' . $id . '-err" class="err">' . hodbook_e($error) . '</p>' : '')
        . '</div>';
}

/** m***@example.com: abbastanza per riconoscere il proprio indirizzo, non per leggerlo da sopra una spalla. */
function hodbook_mask_email(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false || $at === 0) {
        return '***';
    }
    return substr($email, 0, 1) . '***' . substr($email, $at);
}

/**
 * Riga del riquadro dati per il bonifico: etichetta e bottone "Copia" sulla stessa riga, valore sotto a tutta larghezza
 * (così anche l'IBAN lungo resta leggibile sul telefono). Il bottone compare solo con JavaScript.
 */
function hodbook_copy_row(string $label, string $display, string $copy, string $extraClass = ''): string
{
    $spoken = strtoupper($label) === $label ? $label : hodbook_lower($label);   /* "Copia IBAN", "Copia intestatario" */
    return '<div><dt><span>' . hodbook_e($label) . '</span>'
        . '<button type="button" class="copy-btn" data-copy="' . hodbook_e($copy) . '" aria-label="Copia ' . hodbook_e($spoken) . '" hidden>Copia</button>'
        . '</dt><dd><span class="val copyable' . ($extraClass !== '' ? ' ' . $extraClass : '') . '">' . hodbook_e($display) . '</span></dd></div>';
}

function hodbook_lower(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/**
 * Stampa la pagina completa e termina. Testa uguale alle pagine legali (termini.php, privacy.php).
 * $opts: status (codice HTTP), script (true = carica assets/js/prenota.js), attrs (attributi extra di <main>).
 */
function hodbook_render_page(string $title, string $body, array $opts = []): never
{
    if (!headers_sent()) {
        http_response_code((int)($opts['status'] ?? 200));
        header('Content-Type: text/html; charset=utf-8');
    }
    $titleEsc = hodbook_e($title);
    $version = rawurlencode(HODBOOK_VERSION);
    $script = !empty($opts['script']) ? '<script src="assets/js/prenota.js?v=' . $version . '" defer></script>' : '';
    $attrs = (string)($opts['attrs'] ?? '');

    echo <<<HTML
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$titleEsc} | House of Dogs</title>
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="same-origin">
  <link rel="preload" href="assets/fonts/inter-var-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="assets/css/fonts.css">
  <meta name="theme-color" content="#0c3a66">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32.png">
  <link rel="icon" type="image/png" sizes="192x192" href="assets/img/favicon-192.png">
  <link rel="apple-touch-icon" sizes="180x180" href="assets/img/apple-touch-icon.png">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: { extend: {
        colors: { accent: '#1470b3', sky: '#1a90d0', canvas: '#ffffff', panel: '#f3f8fd', deep: '#0c3a66', deeppanel: '#11457a', ink: '#0f2a44', inksoft: '#4a6079', cream: '#ffffff', creamsoft: '#c5dcf2' },
        fontFamily: { display: ['Inter', 'system-ui', 'sans-serif'], body: ['Inter', 'system-ui', 'sans-serif'], monolabel: ['Inter', 'system-ui', 'sans-serif'] }
      } }
    };
  </script>
  <link rel="stylesheet" href="assets/css/custom.css">
  <style>
    .legal h2{font-family:'Inter',system-ui,sans-serif;font-size:1.2rem;font-weight:700;letter-spacing:-.015em;color:var(--text-primary)}
    .legal p,.legal li{line-height:1.65;color:var(--text-primary)}
    .legal a:not(.btn){color:var(--accent);text-decoration:underline;text-underline-offset:3px}
    .box{background:var(--panel);border:1px solid rgba(74,96,121,.2);border-radius:1rem;padding:1.1rem 1.25rem}
    .warn{background:rgba(23,122,189,.1);border:1px solid rgba(23,122,189,.35);border-radius:1rem;padding:1rem 1.2rem;font-weight:600}
    .legal .rule{background:var(--deep);color:#fff;border-radius:1rem;padding:1rem 1.2rem;font-weight:700;line-height:1.5}
    .legal p.hint{font-size:.8rem;line-height:1.4;color:var(--text-secondary);margin:-.2rem 0 .45rem}
    textarea.field{resize:vertical;min-height:5.2rem;line-height:1.45}
    input[data-upper]{text-transform:uppercase}
    .eyebrow{color:var(--accent);font-weight:600;letter-spacing:.12em;text-transform:uppercase;font-size:.75rem}
    .legal p.muted,.muted{color:var(--text-secondary)}
    .nowrap{white-space:nowrap}
    button.btn-primary{background:var(--accent);color:#fff}
    button.btn-primary:hover{background:var(--accent-hover)}
    button.btn-primary:disabled{opacity:.7;cursor:progress;transform:none;box-shadow:none}
    .field[aria-invalid="true"]{border-color:#b42318}
    .legal p.err,.err{color:#b42318;font-size:.875rem;line-height:1.4;margin-top:.35rem}
    .alert{border-radius:1rem;padding:.9rem 1.1rem;border:1px solid;font-size:.95rem}
    .alert-error{background:#fef3f2;border-color:#fda29b;color:#7a271a}
    .legal .alert-error p{color:#7a271a}
    .kv{display:grid;gap:1rem;margin:0}
    .kv>div{display:grid;gap:.25rem}
    .kv dt{display:flex;align-items:center;justify-content:space-between;gap:.75rem;font-size:.7rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--text-secondary)}
    .kv dd{margin:0}
    .val{font-weight:600;font-size:1.05rem;overflow-wrap:anywhere;font-variant-numeric:tabular-nums}
    .copyable{-webkit-user-select:all;user-select:all}
    .val-code{font-size:1.25rem;letter-spacing:.06em}
    .val-iban{font-size:clamp(.9rem,4.2vw,1.05rem)}
    button.copy-btn{display:inline-flex;align-items:center;justify-content:center;flex:none;min-height:36px;padding:.3rem .95rem;border-radius:999px;border:1.5px solid var(--accent);background:#fff;color:var(--accent);font-size:.85rem;font-weight:600;line-height:1;letter-spacing:normal;text-transform:none;cursor:pointer;transition:background-color .2s,color .2s,border-color .2s}
    button.copy-btn:hover{background:var(--accent);color:#fff}
    button.copy-btn.done{background:#15803d;border-color:#15803d;color:#fff}
    button.copy-btn[hidden]{display:none}
    @media (pointer:coarse){button.copy-btn{min-height:44px}}
    .status{border-radius:1rem;padding:1rem 1.15rem;border:1px solid}
    .status .st-label{display:flex;align-items:flex-start;gap:.6rem;font-weight:700;font-size:1.05rem;line-height:1.4}
    .status .st-dot{flex:none;width:.7rem;height:.7rem;border-radius:999px;background:currentColor;margin-top:.4rem}
    .legal .status p{color:inherit}
    .st-pending{background:#fffaeb;border-color:#fedf89;color:#7a2e0e}
    .st-incoming{background:rgba(26,144,208,.1);border-color:rgba(26,144,208,.45);color:#0c3a66}
    .st-paid{background:#ecfdf3;border-color:#75e0a7;color:#085d3a}
    .st-review{background:#fef6ee;border-color:#f9b98c;color:#772917}
    .st-incoming .st-dot{animation:hbpulse 1.6s ease-in-out infinite}
    @keyframes hbpulse{0%,100%{opacity:1}50%{opacity:.35}}
    .choice{display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:1rem 1.15rem;border-radius:1rem;border:1px solid rgba(74,96,121,.2);background:var(--panel);color:var(--text-primary);text-decoration:none;transition:border-color .2s,transform .2s}
    .choice:hover{border-color:var(--accent);transform:translateY(-1px)}
    .legal a.choice{color:var(--text-primary);text-decoration:none}
  </style>
</head>
<body class="font-body" style="font-family:'Inter',system-ui,sans-serif">
<main class="legal mx-auto max-w-xl px-4 sm:px-6 py-10 sm:py-14"{$attrs}>
{$body}
</main>
{$script}
</body>
</html>
HTML;
    exit;
}
