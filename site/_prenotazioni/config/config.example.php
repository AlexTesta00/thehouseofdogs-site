<?php
/* House of Dogs - prenotazioni con bonifico: configurazione privata.
   In produzione config.php viene generato dal deploy GitHub (segreti BOOKING_IBAN, BOOKING_ACCOUNT_HOLDER,
   BOOKING_BIC facoltativo, REVOLUT_WEBHOOK_SECRET): non va mai nel repository.
   Per provare in locale: copia questo file in config.php (stessa cartella) e compila i campi.
   Le chiavi non indicate prendono il valore predefinito (vedi lib/config.php). */

return [

    /* Intestatario del conto e IBAN su cui il cliente fa il bonifico della caparra.
       L'IBAN si scrive con o senza spazi: viene salvato senza spazi e mostrato a gruppi di 4.
       Senza intestatario o con un IBAN non valido (controllo mod-97) la prenotazione online resta spenta
       e la pagina prenota.php propone la prenotazione via WhatsApp. */
    'bank_account_holder' => '',
    'bank_iban'           => '',

    /* BIC/SWIFT (facoltativo, 8 o 11 caratteri) e nome della banca mostrati al cliente. */
    'bank_bic'  => '',
    'bank_name' => 'Revolut',

    /* Segreto di firma del webhook Revolut (inizia con wsk_): Revolut Business > API > Webhooks.
       Più segreti = rotazione senza interruzioni (vale una firma valida con uno qualsiasi).
       Vuoto = il webhook risponde 503 e gli stati delle prenotazioni non si aggiornano da soli.
       URL da registrare: https://www.thehouseofdog.it/webhook-revolut.php
       Eventi: TransactionCreated e TransactionStateChanged. */
    'revolut_webhook_secrets' => [],

    /* ID dei conti Revolut da considerare (facoltativo). Vuoto = tutti i conti dell'azienda. */
    'revolut_account_ids' => [],

    /* Prodotti prenotabili: chiave usata nei link "prenota.php?tipo=..." => nome e caparra in centesimi interi.
       Se questa chiave è presente sostituisce per intero l'elenco predefinito, quindi elencali tutti.
       Nome e importo vengono copiati in ogni prenotazione: cambiarli qui non modifica quelle già fatte. */
    // 'products' => [
    //     'barboncino'          => ['name' => 'Caparra Barboncino Toy', 'amount_cents' => 55000],
    //     'maltipoo'            => ['name' => 'Caparra Maltipoo Toy', 'amount_cents' => 45000],
    //     'maltese'             => ['name' => 'Caparra Maltese Toy', 'amount_cents' => 40000],
    //     'bassotto-arlecchino' => ['name' => 'Caparra Bassotto Kaninchen Arlecchino', 'amount_cents' => 55000],
    //     'bassotto-cioccolato' => ['name' => 'Caparra Bassotto Kaninchen Cioccolato', 'amount_cents' => 45000],
    // ],

    /* Valuta (solo i bonifici in entrata in questa valuta vengono abbinati) e prefisso del codice causale. */
    'currency'         => 'EUR',
    'reference_prefix' => 'HOD',

    /* Numero WhatsApp (solo cifre, con prefisso internazionale) e nome del venditore. */
    'whatsapp_number' => '393770876017',
    'merchant_name'   => 'The House Of Dog',

    /* Tolleranza sull'orario del webhook (Revolut-Request-Timestamp), in secondi. */
    'timestamp_tolerance_seconds' => 300,

    /* true solo se il sito è dietro un proxy fidato che imposta X-Forwarded-For / X-Forwarded-Proto. */
    'trust_proxy_headers' => false,
];
