<?php
/* Coordinate bancarie mostrate al cliente nella pagina della prenotazione e nell'email di riepilogo.
   Non sono dati riservati (le vede chiunque prenoti), quindi stanno nel repository: per cambiare conto
   basta modificare questo file. L'intestatario deve essere scritto esattamente come risulta alla banca,
   perché la banca di chi paga confronta nome e IBAN prima di eseguire il bonifico.

   Le chiavi bank_* sono il conto principale. In bank_extra_accounts si possono aggiungere altri conti con le
   stesse quattro chiavi: il cliente li vede tutti e fa il bonifico su uno, a sua scelta. Al massimo 4 conti
   in tutto; un conto con intestatario vuoto o IBAN non valido viene scartato, così come un IBAN ripetuto.
   Un eventuale config.php (generato dal deploy dai segreti GitHub) ha la precedenza su questi valori:
   i segreti BOOKING_* cambiano solo il conto principale, gli altri conti si modificano soltanto qui. */

declare(strict_types=1);

return [
    'bank_account_holder' => 'THE HOUSE OF DOG DI G.F.',
    'bank_iban'           => 'LT35 3250 0558 4990 2257',
    'bank_bic'            => 'REVOLT21',
    'bank_name'           => 'Revolut',

    'bank_extra_accounts' => [
        [
            'bank_account_holder' => 'Giuseppe Falcicchio',
            'bank_iban'           => 'IT42 A036 6901 6000 6701 2321 526',
            'bank_bic'            => 'REVOITM2',
            'bank_name'           => 'Revolut',
        ],
    ],
];
