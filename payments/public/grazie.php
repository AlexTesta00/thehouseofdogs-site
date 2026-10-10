<?php
/* Pagina di ritorno dopo Mollie. Mollie reindirizza qui per qualsiasi esito:
   lo stato definitivo lo vede il venditore nella dashboard Mollie. */

declare(strict_types=1);

require __DIR__ . '/app-path.php';

hodpay_session_start();
hodpay_send_security_headers(hodpay_nonce());
$merchant = hodpay_e(hodpay_config()['merchant_name']);

hodpay_render_page('Grazie', <<<HTML
<div class="rounded-2xl bg-white p-6 sm:p-8 shadow-sm ring-1 ring-black/5 text-center">
  <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-coral-soft text-coral-dark text-2xl">✓</span>
  <h1 class="mt-4 text-2xl font-semibold tracking-tight">Grazie!</h1>
  <p class="mt-2 text-sm text-slate-600">Abbiamo ricevuto la tua operazione. Se il pagamento è andato a buon fine riceverai la conferma via email da Mollie e {$merchant} ti contatterà a breve.</p>
  <p class="mt-2 text-sm text-slate-600">Se hai annullato o qualcosa non ha funzionato, puoi riaprire il link ricevuto e riprovare.</p>
</div>
HTML, ['badge' => false]);
