<?php
/* Layout HTML condiviso: Tailwind da CDN, sfondo chiaro, scheda centrale. */

declare(strict_types=1);

const HODPAY_TAILWIND_CDN = 'https://cdn.tailwindcss.com/3.4.17';

function hodpay_mode_badge(string $mode): string
{
    $classes = match ($mode) {
        'live'  => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
        'test'  => 'bg-amber-100 text-amber-800 ring-amber-200',
        'demo'  => 'bg-slate-100 text-slate-700 ring-slate-200',
        default => 'bg-rose-100 text-rose-800 ring-rose-200',
    };
    return '<span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium ring-1 ' . $classes . '">'
        . '<span class="h-1.5 w-1.5 rounded-full bg-current"></span>' . hodpay_e(hodpay_mode_label($mode)) . '</span>';
}

/**
 * Stampa la pagina completa e termina.
 * $opts: scripts (array di URL relativi), badge (bool), subtitle (string), wide (bool)
 */
function hodpay_render_page(string $title, string $body, array $opts = []): never
{
    $cfg = hodpay_config();
    $nonce = hodpay_nonce();
    $mode = hodpay_mode($cfg);
    $scripts = '';
    foreach ($opts['scripts'] ?? [] as $src) {
        $scripts .= '<script src="' . hodpay_e($src) . '?v=' . HODPAY_VERSION . '" defer></script>' . "\n";
    }
    $badge = ($opts['badge'] ?? true) ? hodpay_mode_badge($mode) : '';
    $maxw = ($opts['wide'] ?? false) ? 'max-w-2xl' : 'max-w-md';
    $merchant = hodpay_e($cfg['merchant_name']);
    $titleEsc = hodpay_e($title);
    $HODPAY_TW = HODPAY_TAILWIND_CDN;

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!doctype html>
<html lang="it" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$titleEsc} · {$merchant}</title>
<script src="{$HODPAY_TW}"></script>
<script nonce="{$nonce}">
tailwind.config = { theme: { extend: { colors: { coral: { DEFAULT: '#FF6B5B', dark: '#EA5646', soft: '#FFE9E5' }, sand: '#FAF7F2', ink: '#1F1B18' },
  fontFamily: { sans: ['ui-sans-serif','system-ui','-apple-system','Segoe UI','Roboto','Helvetica Neue','Arial','sans-serif'] } } } };
</script>
<style>
  [x-cloak]{display:none}
  .field{border:1px solid #E6DED3;background:#fff;border-radius:.75rem;padding:.75rem 1rem;width:100%;color:#1F1B18;outline:none;transition:border-color .15s,box-shadow .15s}
  .field:focus{border-color:#FF6B5B;box-shadow:0 0 0 3px #FFE9E5}
  .field[aria-invalid="true"]{border-color:#E11D48}
  .btn-coral{background:#FF6B5B;color:#fff;border-radius:.75rem;padding:.875rem 1.25rem;font-weight:600;width:100%;transition:background .15s,transform .05s}
  .btn-coral:hover{background:#EA5646}.btn-coral:active{transform:translateY(1px)}
  .btn-coral:disabled{opacity:.6;cursor:not-allowed}
  .btn-ghost{border:1px solid #E6DED3;background:#fff;border-radius:.75rem;padding:.75rem 1rem;font-weight:500;color:#1F1B18;width:100%;transition:background .15s}
  .btn-ghost:hover{background:#FAF7F2}
  .spinner{display:inline-block;width:1rem;height:1rem;border:2px solid rgba(255,255,255,.5);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:-2px;margin-right:.5rem}
  @keyframes spin{to{transform:rotate(360deg)}}
</style>
{$scripts}
</head>
<body class="min-h-full bg-sand text-ink antialiased">
<div class="min-h-screen flex flex-col">
  <header class="px-4 pt-6 sm:pt-10">
    <div class="mx-auto {$maxw} flex items-center justify-between gap-3">
      <div class="flex items-center gap-2.5">
        <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-coral text-white font-bold">€</span>
        <span class="font-semibold tracking-tight">{$merchant}</span>
      </div>
      {$badge}
    </div>
  </header>
  <main class="flex-1 px-4 py-6 sm:py-10">
    <div class="mx-auto {$maxw}">
{$body}
    </div>
  </main>
  <footer class="px-4 pb-8 text-center text-xs text-slate-500">
    Pagamenti gestiti da Mollie. Dati trasmessi in modo sicuro.
  </footer>
</div>
</body>
</html>
HTML;
    exit;
}

function hodpay_alert(string $kind, string $html): string
{
    $cls = match ($kind) {
        'error'   => 'bg-rose-50 text-rose-800 ring-rose-200',
        'warn'    => 'bg-amber-50 text-amber-900 ring-amber-200',
        'success' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        default   => 'bg-slate-50 text-slate-700 ring-slate-200',
    };
    return '<div role="alert" class="rounded-xl px-4 py-3 text-sm ring-1 ' . $cls . '">' . $html . '</div>';
}

function hodpay_field_error(array $errors, string $key): string
{
    if (empty($errors[$key])) {
        return '';
    }
    return '<p class="mt-1 text-xs text-rose-700">' . hodpay_e($errors[$key]) . '</p>';
}
