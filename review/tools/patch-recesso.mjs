import { readFileSync, writeFileSync } from 'node:fs';
function patch(file, pairs) {
  let s = readFileSync(file, 'utf8');
  for (const [from, to, label] of pairs) {
    if (!s.includes(from)) throw new Error(file + ': non trovato -> ' + label);
    s = s.replace(from, to);
  }
  writeFileSync(file, s);
  console.log(file, 'ok', pairs.length, 'sostituzioni');
}

/* ---------- termini.php ---------- */
const t = readFileSync('termini.php', 'utf8');
const sec8start = t.indexOf('<h2 id="recesso">');
const sec8end = t.indexOf('<h2 id="garanzia">');
const sec14start = t.indexOf('<h2 id="modulo">');
const sec14end = t.indexOf('<p class="mt-10 text-sm"', sec14start);
if (sec8start < 0 || sec8end < 0 || sec14start < 0 || sec14end < 0) throw new Error('termini: sezioni non trovate');
const newSec8 = `<h2 id="recesso">8. Caparra non rimborsabile e assenza di recesso</h2>
  <p>La prenotazione di un cucciolo è un impegno reciproco: dal momento in cui versi la caparra, House of Dogs ferma il cucciolo per te, rinuncia ad altre richieste per lo stesso cucciolo e organizza visite veterinarie, documenti e consegna. Per questo <strong>una volta versata la caparra non è previsto alcun diritto di recesso e la caparra non è rimborsabile</strong>: se, dopo averla versata, rinunci all'acquisto per qualsiasi motivo, la somma resta acquisita da House of Dogs a titolo di caparra confirmatoria ai sensi dell'art. 1385 del Codice Civile, come indicato al punto 5. House of Dogs non riconosce alcun diritto di ripensamento convenzionale.</p>
  <p>Prima di versare la caparra ti invitiamo quindi a leggere con attenzione la conferma scritta delle condizioni (punto 4) e a chiederci qualsiasi chiarimento: rispondiamo a tutto, anche alle domande scomode.</p>
  <p>La caparra ti viene sempre restituita integralmente se la consegna diventa impossibile per cause non dipendenti da te (punto 11) o se House of Dogs non può confermare la prenotazione (punto 4). Restano in ogni caso fermi i diritti riconosciuti al consumatore da norme inderogabili di legge.</p>

  `;
let t2 = t.slice(0, sec8start) + newSec8 + t.slice(sec8end, sec14start) + t.slice(sec14end);
writeFileSync('termini.php', t2);
patch('termini.php', [
  ['<p>Il venditore è <strong>House of Dogs</strong>, P.IVA 03933680401,', '<p>Il venditore è <strong>House of Dogs di Giuseppe Beppe Pato</strong> (ditta individuale), P.IVA 03933680401,', 'venditore'],
  ['<li><a href="#recesso">Diritto di recesso</a></li>', '<li><a href="#recesso">Caparra non rimborsabile e assenza di recesso</a></li>', 'toc 8'],
  ['      <li><a href="#modulo">Modulo di recesso</a></li>\n', '', 'toc 14'],
  [`<li>se, trascorso il periodo di recesso di cui al punto 8, rinunci all'acquisto, House of Dogs trattiene la caparra a titolo di indennizzo;</li>`, `<li>se, dopo averla versata, rinunci all'acquisto per qualsiasi motivo, House of Dogs trattiene la caparra a titolo di indennizzo: non è previsto alcun diritto di recesso (punto 8);</li>`, 'caparra bullet'],
  ['prenotazione con caparra, pagamento, consegna, diritto di recesso, garanzia legale.', 'prenotazione con caparra non rimborsabile, pagamento, consegna, garanzia legale.', 'meta'],
]);

/* ---------- privacy.php ---------- */
patch('privacy.php', [
  ['<p>Il titolare del trattamento è <strong>House of Dogs</strong>, P.IVA 03933680401.</p>', '<p>Il titolare del trattamento è <strong>Giuseppe Beppe Pato</strong>, titolare della ditta individuale House of Dogs, P.IVA 03933680401.</p>', 'titolare'],
]);

/* ---------- index.php ---------- */
patch('index.php', [
  ['Prima ti confermiamo sempre tutto per iscritto: cucciolo, prezzo totale, documenti e consegna. La prenotazione è regolata dalle nostre', 'Prima ti confermiamo sempre tutto per iscritto: cucciolo, prezzo totale, documenti e consegna. <strong>La caparra non è rimborsabile</strong> in caso di rinuncia. La prenotazione è regolata dalle nostre', 'nota card'],
  ['© 2026 House of Dogs. Tutti i diritti riservati. · P.IVA 03933680401', '© 2026 House of Dogs di Giuseppe Beppe Pato. Tutti i diritti riservati. · P.IVA 03933680401', 'footer'],
  ['"name": "House of Dogs",\n    "description"', '"name": "House of Dogs",\n    "legalName": "House of Dogs di Giuseppe Beppe Pato",\n    "description"', 'jsonld'],
]);
