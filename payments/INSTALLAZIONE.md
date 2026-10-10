# House of Dogs Payments – guida di installazione

Piccola app PHP per creare link di pagamento Mollie (con Klarna quando disponibile) partendo solo da **nome cliente** e **importo**. Il commerciante genera un link, lo invia al cliente; il cliente completa i dati di fatturazione e viene portato alla pagina di pagamento Mollie.

```
payments/
├── public/          ← UNICA cartella da esporre sul web (es. https://dominio.it/payments/)
│   ├── index.php    pannello commerciante (login + generatore)
│   ├── pay.php      pagina cliente (apre con ?t=TOKEN)
│   ├── grazie.php   ritorno dopo Mollie
│   ├── logout.php
│   ├── api/create-link.php
│   ├── assets/app.js, pay.js
│   └── app-path.php percorso della cartella privata
├── app/             ← PRIVATA: codice PHP (bootstrap + lib/)
├── config/          ← PRIVATA: config.example.php → config.php (chiave Mollie, hash password)
├── data/            ← PRIVATA: payments.sqlite, log (creati automaticamente)
└── tools/           make-password-hash.php, selftest.php
```

Le cartelle `app/`, `config/` e `data/` contengono già un `.htaccess` che le blocca dal web, ma la soluzione corretta è tenerle **fuori dalla document root**.

---

## 1. Requisiti dell'hosting

| Requisito | Dettaglio |
|---|---|
| PHP | 8.2 o successivo (testato con 8.4; il CI del sito usa 8.2) |
| Estensioni PHP | `curl`, `pdo_sqlite` (oppure `sqlite3`), `openssl`, `json`; consigliata `mbstring` |
| Web server | Apache o LiteSpeed con `.htaccess` abilitato (Hostinger: sì). Nginx: replicare i blocchi "deny" per le cartelle private |
| HTTPS | Obbligatorio in produzione (certificato Let's Encrypt gratuito su Hostinger) |
| Scrittura su disco | La cartella `data/` deve essere scrivibile da PHP (permessi 750 o 755) |
| Composer / Node | **Non necessari** |

Per verificare le estensioni sul server: crea un file temporaneo `info.php` con `<?php phpinfo();`, aprilo, controlla `curl` e `pdo_sqlite`, poi **cancellalo**.

---

## 2. Dominio e cartella pubblica

### Layout consigliato su Hostinger

```
/home/uXXXX/domains/thehouseofdog.it/
├── public_html/              ← document root del sito
│   └── payments/             ← contenuto di payments/public/
└── payments-private/         ← contenuto di payments/app, config, data
    ├── app/
    ├── config/
    └── data/
```

1. Via File Manager o FTP, carica il contenuto di `payments/public/` in `public_html/payments/`.
2. Crea `payments-private/` **accanto** a `public_html/` (non dentro) e carica lì `app/`, `config/`, `data/`.
3. Apri `public_html/payments/app-path.php` e imposta il percorso:
   ```php
   $private = getenv('HODPAY_PRIVATE_DIR') ?: dirname(__DIR__, 2) . '/payments-private';
   ```
   (`dirname(__DIR__, 2)` da `public_html/payments/` risale a `thehouseofdog.it/`.)
4. Imposta i permessi: cartella `data/` scrivibile (755), file `config/config.php` leggibile solo dal proprietario (600 o 640).

### Layout semplificato (tutto dentro public_html)

Se l'hosting non consente cartelle fuori dalla document root, carica l'intera cartella `payments/` in `public_html/payments/` così com'è e lascia `app-path.php` invariato. In questo caso l'URL pubblico diventa `https://dominio.it/payments/public/`. I `.htaccess` nelle cartelle private rispondono 403 a qualunque richiesta diretta: verifica aprendo `https://dominio.it/payments/config/config.php` (deve dare **403 Forbidden**).

### Nota sul deploy automatico del sito

Il workflow GitHub `.github/workflows/ci-cd.yml` carica via FTP **solo** la cartella `site/`. La cartella `payments/` non viene pubblicata automaticamente: il caricamento è manuale (o va aggiunto al workflow quando si decide di andare online).

---

## 3. Password amministratore (facoltativa)

Per scelta del venditore il pannello è **aperto senza login** (`'require_admin_login' => false`, valore di default): chi conosce l'URL può creare link cliente. I link portano solo a pagamenti verso il conto Mollie del venditore e la creazione è limitata a 60 link l'ora per IP. Se in futuro si vuole proteggere il pannello, imposta `'require_admin_login' => true` e configura la password come segue.

La password non viene mai salvata in chiaro: in configurazione va il suo **hash**.

```bash
php payments/tools/make-password-hash.php
```

Lo script chiede la password (minimo 12 caratteri) e stampa la riga da incollare in `config/config.php`:

```php
'admin_password_hash' => '$2y$10$...',
```

Per cambiare password basta rigenerare l'hash e sostituirlo. Dopo 8 tentativi falliti in 15 minuti dallo stesso IP il login viene bloccato temporaneamente. La sessione scade dopo 30 minuti di inattività (`session_idle_minutes`).

---

## 4. Chiave API Mollie

### Creare la chiave (versione web di Mollie)

1. Accedi a <https://my.mollie.com>.
2. Nel menu laterale apri **Developers → API keys**.
3. Verifica di essere sul **profilo (sito web) corretto**: la chiave è legata al profilo scelto in alto.
4. Clicca **Create access token → Standard API key**.
5. Scegli inizialmente la modalità **Test**. Copia la chiave (`test_...`) **subito**: viene mostrata **una sola volta**. Se la perdi, dovrai rigenerarla.
6. Quando sarai pronto per i pagamenti reali, ripeti la procedura in modalità **Live** (`live_...`).

> Fonte: documentazione Mollie "Authentication" (docs.mollie.com/reference/authentication) e articolo "What is an API key" dell'help center. La voce "API keys" compare solo dopo aver creato almeno un profilo sito web.

### Inserire la chiave

Copia `config/config.example.php` in `config/config.php` e compila:

```php
'mollie_api_key'      => 'test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
'admin_password_hash' => '$2y$10$...',
'purchase_description' => 'Caparra cucciolo - The House Of Dog',
'base_url'            => 'https://thehouseofdog.it/payments',
```

Regole:

- La chiave vive **solo** in `config/config.php`, letta da PHP. Non compare mai in HTML, JavaScript, URL, risposte JSON o log (il client Mollie registra solo codice HTTP e messaggio d'errore).
- `config.php` è ignorato da git. Non va mai copiato nella cartella pubblica né inviato via chat.
- `base_url`: in produzione impostalo esplicitamente, così i link generati non dipendono dall'header Host.
- `purchase_description` è la descrizione dell'acquisto, configurata una volta: il modulo del commerciante resta a due campi.

### Le tre modalità

| Chiave | Modalità | Comportamento |
|---|---|---|
| vuota | **Demo** | Si creano i link cliente e si prova il modulo, ma **nessuna chiamata a Mollie** e nessun pagamento, nemmeno simulato. La pagina cliente termina con "Modalità demo". |
| `test_…` | **Test** | Vengono creati veri payment link in test mode: Mollie mostra una pagina di prova dove scegliere l'esito, senza addebiti. Badge giallo nelle pagine. |
| `live_…` | **Live** | Pagamenti reali. Badge verde nel pannello; nessun badge nella pagina cliente. |

Una chiave con formato diverso viene segnalata come "non valida" e blocca il checkout.

---

## 5. Avvio locale

Serve PHP 8.2+ con le estensioni `curl`, `pdo_sqlite`, `openssl`, `mbstring`.

```bash
cp payments/config/config.example.php payments/config/config.php
php payments/tools/make-password-hash.php        # incolla l'hash in config.php
php -S 127.0.0.1:8124 -t payments/public
```

Apri <http://127.0.0.1:8124/>. Senza chiave Mollie sei in modalità demo.

Su Windows con il PHP installato da WinGet (nessun `php.ini` attivo) le estensioni vanno passate esplicitamente; nel progetto è già pronta la configurazione `payments` in `.claude/launch.json`, equivalente a:

```bash
php -n -d extension_dir="<cartella php>\ext" -d extension=curl -d extension=openssl -d extension=pdo_sqlite -d extension=mbstring -S 127.0.0.1:8124 -t payments/public
```

### Test automatici

```bash
php payments/tools/selftest.php --http
```

Esegue 118 controlli su: parsing importi, limiti 1–4.000 €, modalità chiave, token, CSRF, limiti di frequenza, autenticazione, validazione dati cliente, costruzione del payload Mollie (lines, billingAddress, allowedMethods, reusable, expiresAt), idempotenza (retry senza duplicati, rotazione chiave su 422, chiave conservata su 5xx/409), scadenza link, modalità demo. Con `--http` avvia il server integrato e prova l'intero percorso via HTTP (login, API, pagina cliente, logout, intestazioni di sicurezza).

Controllo sintassi di tutti i file:

```bash
find payments -name '*.php' -exec php -l {} \;
```

---

## 6. Prova con chiave test e passaggio a live

### Con la chiave test

1. Inserisci la chiave `test_…` in `config/config.php`.
2. Apri il pannello, crea un link con un nome e un importo (es. 350,00).
3. Apri il link cliente (meglio in una finestra privata, come farebbe il cliente), compila i dati e premi **Procedi al pagamento**.
4. Vieni portato su Mollie: in test mode compare la pagina di simulazione in cui scegliere l'esito (pagato, fallito, annullato…).
5. Al ritorno atterri su `grazie.php`. Nella dashboard Mollie (modalità Test attiva) trovi il payment link e il pagamento.
6. Riapri lo stesso link cliente: vieni reindirizzato allo **stesso** payment link Mollie, senza duplicati.

Klarna in test mode: Mollie elenca i metodi per importo e paese tramite `GET /v2/methods`. L'app propone Klarna **solo se compare in quella risposta**; altrimenti il link viene creato con gli altri metodi e il cliente non vede promesse su Klarna. Per vedere Klarna devi averlo **attivato sul profilo** nella dashboard Mollie (Settings → Website profiles → Payment methods). L'approvazione finale del cliente resta sempre di Klarna.

### Passaggio a live

1. Attiva Klarna e gli altri metodi sul profilo live (Mollie può richiedere verifica dell'attività).
2. Crea la chiave **Live** (Developers → API keys → Create access token → Standard API key → Live).
3. Sostituisci `mollie_api_key` in `config/config.php` con la chiave `live_…`. Nessun'altra modifica.
4. Verifica che il sito risponda solo in HTTPS e che `base_url` sia corretto.
5. Fai un pagamento reale di importo minimo (1 €) e rimborsalo dalla dashboard per verificare l'intero giro.

Cosa **non** è possibile provare senza credenziali Mollie reali: la risposta effettiva di `GET /v2/methods` per il tuo profilo (quindi la presenza di Klarna), la creazione del payment link, la pagina di pagamento e la gestione degli stati. I test automatici coprono tutto il resto con un client Mollie simulato.

---

## 7. Catture, stati e dashboard Mollie

Questa app **non ha una propria dashboard**: lo stato dei pagamenti si segue su Mollie, raggiungibile dal collegamento **Apri Mollie** nel pannello.

- **Dove guardare**: Mollie Dashboard → **Payments** (ogni pagamento ricevuto tramite link) e **Browse → Payment links** (i link creati, con stato e scadenza). Nella descrizione trovi `<descrizione acquisto> - <nome cliente>`.
- **Klarna e capture**: con i payment link Mollie cattura automaticamente l'importo autorizzato da Klarna. Se preferisci autorizzare prima e catturare dopo la consegna (consigliato da Mollie quando l'evasione supera 24 ore), la cattura manuale va fatta dalla **dashboard Mollie** o tramite l'API Payments con `captureMode: manual`, che questa app non usa. Decidi la politica con Mollie prima di andare live.
- **Rimborsi**: dalla dashboard, aprendo il pagamento → Refund. Klarna consente rimborsi fino a 3 anni.
- **Stati tipici**: `open` (cliente non ha ancora pagato), `pending`, `authorized` (Klarna approvato, in attesa di cattura), `paid`, `failed`, `canceled`, `expired`. Il payment link è `reusable: false`: una volta pagato non accetta altri pagamenti.
- **Scadenze**: il link cliente e il payment link Mollie scadono dopo `customer_link_hours` (default 72 ore). Se scaduto, il cliente vede un messaggio chiaro e il commerciante crea un nuovo link.
- **Webhook**: facoltativo. Se un giorno servisse una notifica automatica, imposta `webhook_url` (https) in configurazione: Mollie lo chiamerà agli aggiornamenti di stato.

---

## 8. Sicurezza: cosa fa l'app

- Login facoltativo (`require_admin_login`): password con `password_hash`, sessione PHP (cookie HttpOnly, SameSite=Lax, Secure su HTTPS, scadenza per inattività, rigenerazione ID al login).
- CSRF: token di sessione su ogni form e sull'API (`X-CSRF-Token`), più controllo `Sec-Fetch-Site`/`Origin`.
- Limiti di frequenza su login, creazione link, apertura pagina cliente e checkout (per IP e per token).
- Token cliente da 256 bit casuali; nel database è salvato solo l'hash SHA-256.
- Importi sempre in centesimi interi; limiti 1–4.000 € lato server; il cliente non può alterare importo, descrizione o metodi (vivono solo nel database).
- Richieste a Mollie con `Idempotency-Key` salvata per richiesta: un retry non crea duplicati; su errore 422 la chiave viene ruotata, così la correzione dei dati non viene rifiutata.
- cURL con verifica certificati attiva (`VERIFYPEER`, `VERIFYHOST=2`), solo HTTPS, timeout.
- Intestazioni: CSP, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Cache-Control: no-store`, HSTS su HTTPS.
- Dati di fatturazione del cliente salvati in `data/payments.sqlite` (necessari come traccia della richiesta): ricordati di citarli nell'informativa privacy; le righe scadute da più di 90 giorni vengono eliminate automaticamente.

---

## 9. Fonti consultate

- Payment Links API – Create payment link: <https://docs.mollie.com/reference/create-payment-link>
- Klarna via Mollie: <https://docs.mollie.com/docs/klarna>
- List payment methods: <https://docs.mollie.com/reference/list-methods>
- Idempotenza: <https://docs.mollie.com/reference/api-idempotency>
- Autenticazione e chiavi API: <https://docs.mollie.com/reference/authentication>
- Help center "What is an API key": <https://help.mollie.com/hc/en-us/articles/360023732273-What-is-an-API-key> (al momento della stesura la pagina rispondeva 403 agli accessi automatici: i passaggi sono stati verificati sulla documentazione "Authentication").
