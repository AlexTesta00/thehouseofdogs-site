<?php
/* House of Dogs Payments - configurazione privata.
   1. Copia questo file in config.php (stessa cartella).
   2. Compila almeno admin_password_hash e mollie_api_key.
   Il file config.php NON va mai copiato dentro la cartella pubblica e non va nel repository. */

return [

    /* Chiave API Mollie del profilo. Vuota = modalità demo (nessuna chiamata a Mollie).
       Inizia con "test_" per le prove, "live_" per i pagamenti reali.
       Creazione: Mollie Dashboard > Developers > API keys > Create access token > Standard API key.
       La chiave viene mostrata una sola volta: conservala subito. */
    'mollie_api_key' => '',

    /* Login del pannello commerciante. false = il pannello è aperto a chi conosce l'URL
       (scelta del venditore: i link generati portano solo a pagamenti verso il suo conto Mollie).
       true = richiede la password il cui hash è in admin_password_hash. */
    'require_admin_login' => false,

    /* Hash della password del commerciante (usato solo se require_admin_login è true). Generalo con:
         php tools/make-password-hash.php
       e incolla qui il risultato (inizia con $2y$ oppure $argon2). */
    'admin_password_hash' => '',

    /* Nome mostrato in alto nelle pagine e nella pagina cliente. */
    'merchant_name' => 'The House Of Dog',

    /* Descrizione dell'acquisto, configurata una volta sola: finisce nella riga d'ordine Mollie
       e, insieme al nome del cliente, nella descrizione del pagamento. Max 120 caratteri. */
    'purchase_description' => 'Caparra cucciolo - The House Of Dog',

    /* Valuta ISO 4217. Klarna in Italia lavora in EUR. */
    'currency' => 'EUR',

    /* Limiti dell'importo in centesimi interi: da 1,00 € a 4.000,00 €
       (massimo Klarna per l'Italia secondo la documentazione Mollie). */
    'min_amount_cents' => 100,
    'max_amount_cents' => 400000,

    /* Ore di validità del link cliente e del payment link Mollie. */
    'customer_link_hours' => 72,

    /* Paese preselezionato nel modulo cliente e paesi ammessi (ISO 3166-1 alpha-2).
       Tutti i paesi elencati sono paesi EUR in cui Mollie offre Klarna. */
    'default_country' => 'IT',
    'allowed_countries' => ['IT', 'AT', 'BE', 'DE', 'ES', 'FI', 'FR', 'IE', 'NL', 'PT'],

    /* Aliquota IVA inclusa nel prezzo, come stringa (es. '22.00'), per compilare vatRate/vatAmount
       nella riga Mollie. null = non inviare i campi IVA (facoltativi per Klarna). */
    'vat_rate' => null,

    /* URL pubblico della cartella pagamenti, senza slash finale, es. 'https://thehouseofdog.it/payments'.
       null = rilevamento automatico dall'host della richiesta. In produzione è consigliato impostarlo. */
    'base_url' => null,

    /* Dove torna il cliente dopo Mollie: 'auto' = pagina grazie.php di questa app,
       un URL https personalizzato, oppure null per la pagina di conferma standard di Mollie. */
    'redirect_url' => 'auto',

    /* Webhook Mollie facoltativo (https). Non necessario: gli stati si seguono nella dashboard Mollie. */
    'webhook_url' => null,

    /* Collegamento "Apri Mollie" nel pannello. */
    'mollie_dashboard_url' => 'https://my.mollie.com/dashboard',

    /* Sessione PHP: nome del cookie e minuti di inattività prima della disconnessione. */
    'session_name' => 'hodpay',
    'session_idle_minutes' => 30,

    /* true solo se l'app è dietro un proxy/CDN fidato che imposta X-Forwarded-For e X-Forwarded-Proto. */
    'trust_proxy_headers' => false,

    /* Cookie di sessione solo su HTTPS: 'auto' li marca secure quando la richiesta è HTTPS. */
    'cookie_secure' => 'auto',
];
