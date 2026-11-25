<?php
/* Coordinate bancarie mostrate al cliente nella pagina della prenotazione e nell'email di riepilogo.
   Non sono dati riservati (le vede chiunque prenoti), quindi stanno nel repository: per cambiare conto
   basta modificare questo file. L'intestatario deve essere scritto esattamente come risulta alla banca,
   perché la banca di chi paga confronta nome e IBAN prima di eseguire il bonifico.
   Un eventuale config.php (generato dal deploy dai segreti GitHub) ha la precedenza su questi valori. */

declare(strict_types=1);

return [
    'bank_account_holder' => 'THE HOUSE OF DOG DI G.F.',
    'bank_iban'           => 'IT44 T036 6701 6000 1057 0279 542',
    'bank_bic'            => '',
    'bank_name'           => 'Zurich Bank',
];
