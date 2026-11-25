<?php
/* Coordinate bancarie mostrate al cliente nella pagina della prenotazione e nell'email di riepilogo.
   Non sono dati riservati (le vede chiunque prenoti), quindi stanno nel repository: per cambiare conto
   basta modificare questo file. L'intestatario deve essere scritto esattamente come risulta alla banca,
   perché la banca di chi paga confronta nome e IBAN prima di eseguire il bonifico.
   Un eventuale config.php (generato dal deploy dai segreti GitHub) ha la precedenza su questi valori. */

declare(strict_types=1);

return [
    'bank_account_holder' => 'THE HOUSE OF DOG DI G.F.',
    'bank_iban'           => 'LT35 3250 0558 4990 2257',
    'bank_bic'            => 'REVOLT21',
    'bank_name'           => 'Revolut',
];
