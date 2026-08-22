import { readFileSync, writeFileSync } from 'node:fs';
function patch(file, pairs) {
  let s = readFileSync(file, 'utf8');
  for (const [from, to, label] of pairs) {
    if (!s.includes(from)) throw new Error(file + ': non trovato -> ' + label);
    s = s.split(from).join(to);
  }
  writeFileSync(file, s);
  console.log(file, 'ok');
}
patch('privacy.php', [
  [`<p class="mt-3">Non svolgiamo attività di marketing, non inviamo newsletter, non facciamo profilazione e non prendiamo decisioni basate unicamente su trattamenti automatizzati.`,
   `<p class="mt-3">I tuoi dati non vengono utilizzati per fini pubblicitari né ceduti o venduti a terzi per scopi commerciali. Non svolgiamo attività di marketing, non inviamo newsletter, non facciamo profilazione e non prendiamo decisioni basate unicamente su trattamenti automatizzati.`, 'impegni'],
  [`<p>Per concludere la vendita e consegnare il cucciolo trattiamo dati anagrafici, indirizzo di consegna, codice fiscale e gli estremi di un documento d'identità, necessari per l'accordo scritto, per la fatturazione e per la registrazione del passaggio di proprietà all'anagrafe degli animali d'affezione, come prevede la legge.</p>`,
   `<p>Per concludere la vendita e consegnare il cucciolo trattiamo dati anagrafici, indirizzo di consegna, codice fiscale e copia di un documento d'identità, richiesti al momento della prenotazione e necessari per l'accordo scritto, per la fatturazione e per la registrazione del passaggio di proprietà all'anagrafe degli animali d'affezione, come prevede la legge. Se scegli di pagare il saldo con un finanziamento, la richiesta viene gestita direttamente dalla finanziaria convenzionata, che tratta i tuoi dati come titolare autonomo secondo la propria informativa.</p>`, 'dati contrattuali'],
]);
patch('index.php', [
  [`Sì, e lo facciamo a mano: il cucciolo viaggia accompagnato, non in un pacco. Concordiamo insieme giorno e modalità di consegna.`,
   `Sì, e lo facciamo a mano: il cucciolo viaggia accompagnato, non in un pacco. Concordiamo insieme giorno, modalità e rimborso delle spese di viaggio, sempre prima della prenotazione.`, 'faq consegna (2 occorrenze)'],
  [`Dipende da razza, colore e linea di sangue. Scrivici su WhatsApp: ti diciamo subito disponibilità e prezzo, chiaro e completo di tutto quello che include. Niente costi a sorpresa dopo.`,
   `Dipende da razza, colore e linea di sangue. Scrivici su WhatsApp: ti diciamo subito disponibilità e prezzo, chiaro e completo di tutto quello che include. Si prenota con una caparra, di norma il 30%, e il saldo si può pagare anche a rate con finanziamento, previa approvazione. Niente costi a sorpresa dopo.`, 'faq prezzo (2 occorrenze)'],
]);
