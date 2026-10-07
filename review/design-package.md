# Design Package — House of Dogs (thehouseofdogs.it)

Tier 1, una pagina. Ogni riga di copy qui dentro va nel sito ESATTAMENTE com'e scritta.
Range e numeri di banda sono punti di partenza, validati dopo con il flick test.

## 1. La premessa del brand

Una parola sola: **CASA**. E nel nome del brand (House of Dogs) e nel desiderio
verbale degli acquirenti ("un amico a 4 zampe che mi faccia tanta compagnia",
"la mia ombra"). Il sito insegna e vende una sola idea: la casa diventa completa
quando arriva il cucciolo giusto, e House of Dogs lo porta fino alla tua porta,
con documenti veri e zero brutte sorprese. Il viaggio dell'hero E' l'arrivo a casa.
Ogni sezione sotto risponde a una paura che separa il visitatore dal "portalo a casa".
La CTA unica: contattaci su WhatsApp (secondaria: il form di prenotazione).

## 2. La palette (token CSS)

Campionata dal mondo del girato progettato (mattina dorata, coperte crema,
manto albicocca) e dalle foto reali dei cuccioli. Da rifinire dopo il video gate.

```css
:root{
  --canvas:#f8f3ec;          /* avorio caldo */
  --panel:#fffdf9;
  --deep:#241a12;            /* espresso caldo per le sezioni scure */
  --deep-panel:#31251b;
  --accent:#b06a2e;          /* caramello miele, dal manto */
  --accent-hover:#94551f;
  --accent-muted:rgba(176,106,46,.32);
  --text-primary:#2b211a;
  --text-secondary:#6b5d50;
  --text-on-deep:#f4ece1;
  --text-on-deep-2:#c9b8a6;
}
```

Nota anti-cliche: la direzione crema+caramello e GUADAGNATA, non di default:
e il mondo materiale reale del soggetto (manti albicocca/miele, coperte crema
nelle foto vere dei loro cuccioli). Elemento firma + layout non-template la
tengono lontana dal look stock.

## 3. Il trio tipografico

- Display: **Fraunces** (500, 600) — serif morbido con carattere, caldo, "da casa".
- Body: **Figtree** (400, 600, 700) — quieto, molto leggibile su mobile.
- Mono etichette: **DM Mono** (400) — kicker, tag, microcopy tecnico.
Google Fonts, solo i pesi elencati, preconnect.

## 4. Il concept dell'hero (Tier 1, 6 secondi, 16:9)

**"L'arrivo a casa."** Camera che avanza lentamente in un soggiorno italiano
caldo, mattina dorata: pulviscolo nell'aria, tende che respirano, luce che
scivola sul legno. La camera arriva a una poltrona con un plaid crema dove
dorme un cucciolo di barboncino toy albicocca, raggomitolato. Il finale si
posa sul cucciolo che dorme, composto basso-centro con margine generoso sopra.

Leggi rispettate: scroll giu = avvicinarsi/arrivare (1), un soggetto un moto (2),
cucciolo che DORME = anatomia al sicuro, dettaglio rischioso fermo (5),
vita ambientale: pulviscolo, tende, respiro del cucciolo (3), finale a riposo
componibile con l'header sopra (4), corsia d'azione al centro-destra, testo a
sinistra (7), niente testo/loghi nel prompt (12).

## 5. La mappa delle bande (hero 400vh)

| Banda | Range | Momento del girato | Copy (verbatim) | Entrata |
|---|---|---|---|---|
| 1 | 0.00-0.26 | soggiorno vuoto, luce dorata, camera parte | kicker: `HOUSE OF DOGS · DA 20 ANNI` / titolo: `C'e un posto in casa che aspetta solo lui.` | approach-from-depth (eco della camera che avanza) |
| 2 | 0.30-0.55 | la camera attraversa la stanza, luce che scende | titolo: `Cuccioli toy italiani, selezionati con 20 anni di passione.` / sotto: `Sani, socializzati, con documenti veri.` | drift-down (eco della luce che scende) |
| 3 | 0.58-0.80 | la poltrona entra in campo, il cucciolo si intuisce | titolo staccato (dispositivo di brand): `Barboncino. Maltipoo. Maltese. Bassotto.` / sotto: `Il tuo ti sta gia aspettando.` | grid snap-align (eco dei dettagli che vanno a fuoco) |
| 4 settle | 0.84-1.00 | il finale a riposo: cucciolo che dorme sul plaid | titolo: `Portalo a casa.` / sotto: `Consegna a mano in tutta Italia, con microchip, libretto sanitario e pedigree.` / CTA: `Scrivici su WhatsApp` + `Scopri i cuccioli` | word-by-word rise a tappe (eco dell'arrivo) |

Scrim: banda 1 sa=.66, bande 2-3 sa=.62, settle sa=.70. Posizione testo: sinistra
(--sx:32%), settle centrato in alto (--sx:50%). Audit worst-frame a 3.5:1 minimo.

## 6. Il blocco hero statico (telefoni, reduced motion)

Sfondo: il frame finale del video (cucciolo che dorme). Gradiente scuro dal basso.
- kicker: `HOUSE OF DOGS · DA 20 ANNI`
- titolo: `C'e un posto in casa che aspetta solo lui.`
- sotto: `Cuccioli toy italiani, sani e socializzati. Consegna a mano in tutta Italia, con documenti veri.`
- CTA: `Scrivici su WhatsApp` + `Scopri i cuccioli`

## 7. La struttura sotto il fold (una pagina)

Tutte le sezioni incanalano verso UNA call to action: WhatsApp (ancora #contatti).

### 7a. Striscia fiducia (subito sotto l'hero)
4 voci mono, essenziali:
`20 anni di selezione` · `Consegna a mano in tutta Italia` · `Microchip e libretto sanitario` · `Assistenza per tutta la vita`

### 7b. #cuccioli — I tipi di cucciolo (H1 SEO della pagina)
H1: `Cuccioli toy in vendita: Barboncino, Maltipoo, Maltese e Bassotto Kaninchen`
Lede: `Quattro tipi di cucciolo (Barboncino Toy, Maltipoo, Maltese Toy e Bassotto Kaninchen), una promessa sola: taglia mini, carattere equilibrato e una crescita seguita giorno per giorno, da quando nascono a quando ti guardano per la prima volta.`

Card tipo di cucciolo (foto reali). Ogni card: nome, anima in una riga, 3 tag, CTA WhatsApp.

1. **Barboncino Toy** — `Intelligente da vendere, non perde pelo, ama imparare.`
   tag: `non perde pelo` `super intelligente` `red e albicocca`
   micro: `Il piccolo genio di casa: attento, elegante, sempre in sintonia con te.`
2. **Maltipoo Toy** — `Il musetto da peluche che conquista tutta la famiglia.`
   tag: `aspetto teddy` `poco pelo in giro` `dolcissimo`
   micro: `Morbido e affettuoso, nato per stare in braccio. Le nostre cucciolate sono italiane, da genitori selezionati.`
3. **Maltese Toy** — `Un batuffolo bianco dal carattere nobile e tranquillo.`
   tag: `mantello di seta` `tranquillo` `da appartamento`
   micro: `Compagno storico dei salotti italiani: pulito, silenzioso, devoto.`
4. **Bassotto Kaninchen** — `Piccolo fuori, coraggioso dentro. Un carisma unico.`
   tag: `taglia kaninchen` `giocherellone` `anche arlecchino`
   micro: `Il piu piccolo dei bassotti: curioso, buffo, incollato a te.`

CTA card: `Chiedi disponibilita` (link wa.me con testo precompilato per tipo di cucciolo).

### 7c. #perche-noi — sezione scura, fiducia (usa il frame finale come immagine)
Kicker: `PERCHE HOUSE OF DOGS`
Titolo: `Da 20 anni portiamo cuccioli sani nelle case italiane.`
Lede: `Sappiamo cosa si prova a cercare un cucciolo online: tante promesse, poca chiarezza. Noi lavoriamo al contrario. Prima ti mostriamo tutto, poi decidi.`

4 pilastri:
1. `Cuccioli italiani, linee controllate` — `Selezioniamo cucciolate italiane da linee di sangue seguite nel tempo. Niente importazioni misteriose, niente cuccioli senza storia.`
2. `Salute prima di tutto` — `Test genetici sui riproduttori e controlli veterinari sui cuccioli. Ti consegniamo un cucciolo visitato, vaccinato e sverminato.`
3. `Documenti veri, sempre` — `Ogni cucciolo parte con microchip, libretto sanitario compilato dal veterinario e pedigree. Tutto nero su bianco.`
4. `Con te anche dopo` — `La consegna non e un addio: per dubbi, consigli e crescita, restiamo il tuo punto di riferimento per tutta la vita del cane.`

### 7d. #come-funziona — 4 passi (uccide l'ansia da truffa)
Kicker: `COME FUNZIONA`
Titolo: `Dal primo messaggio alla tua porta, senza sorprese.`
1. `Scrivici` — `Un messaggio su WhatsApp basta. Dicci che tipo di cucciolo ami (Barboncino Toy, Maltipoo, Maltese Toy o Bassotto Kaninchen) e che casa lo aspetta.`
2. `Guarda i cuccioli dal vivo` — `Ti mandiamo foto e video reali dei cuccioli disponibili. Vedi come sono, non come sembrano.`
3. `Prenota il tuo` — `Scelto il cucciolo, lo fermiamo per te e concordiamo tutto con calma, per iscritto.`
4. `Aprigli la porta` — `Consegna a mano in tutta Italia, mai prima dei 60 giorni di vita, con tutti i documenti al seguito.`
Chiusa: `Nessun anticipo al buio, nessun corriere anonimo: ogni passaggio lo facciamo insieme.`

### 7e. #quale-toy — il momento interattivo (quiz, 3 tocchi)
Kicker: `NON SAI QUALE SCEGLIERE?`
Titolo: `Tre domande e ti diciamo chi fa per te.`
D1 `Con chi vivra?` → `Bambini e famiglia` / `In coppia` / `Con me, e la mia ombra`
D2 `Quanto conta il pelo in giro per casa?` → `Zero pelo, per favore` / `Non mi preoccupa`
D3 `Che carattere cerchi?` → `Vivace e giocherellone` / `Dolce e tranquillo` / `Piccolo peperino`
Risultato: card tipo di cucciolo + `E lui. Vuoi vederlo dal vivo in video?` + CTA WhatsApp precompilata.
Reduced motion: risultato immediato senza animazioni.

### 7f. #domande — FAQ (obiezioni reali dalla ricerca)
Titolo: `Le domande che ci fanno tutti. Giustamente.`
1. `Come faccio a fidarmi? Il web e pieno di truffe sui cuccioli.` → `Hai ragione a essere prudente. Per questo non ti chiediamo mai di fidarti al buio: ti mostriamo i cuccioli in foto e video dal vivo prima di qualsiasi decisione, rispondiamo al telefono con nome e cognome e ogni consegna viaggia con documenti e accordi scritti. Siamo un'attivita registrata, con partita IVA visibile qui sotto, e in 20 anni abbiamo accompagnato centinaia di famiglie. Fai tutte le domande che vuoi: e il segno che sarai un ottimo proprietario.`
2. `Il cucciolo restera davvero piccolo?` → `La taglia da adulto la scrive la genetica, non le promesse. Per questo selezioniamo linee toy seguite nel tempo e ti diciamo sempre peso e taglia dei genitori, con una stima onesta di come crescera. Un cucciolo va amato anche per il mezzo chilo in piu che potra avere da adulto.`
3. `Che documenti ricevo con il cucciolo?` → `Microchip registrato, libretto sanitario compilato dal veterinario con vaccinazioni e sverminazioni, pedigree del cucciolo e accordo di vendita scritto. Tutto ti viene consegnato insieme a lui.`
4. `A che eta consegnate i cuccioli?` → `Mai prima dei 60 giorni di vita, come prevede la legge italiana: e il tempo che serve a un cucciolo per crescere sereno accanto alla mamma. Alla consegna ha gia il primo ciclo vaccinale.`
5. `Quanto costa un cucciolo toy?` → `Dipende dal tipo di cucciolo (Barboncino Toy, Maltipoo, Maltese Toy o Bassotto Kaninchen), dal colore e dalla linea di sangue. Scrivici su WhatsApp: ti diciamo subito disponibilita e prezzo, chiaro e completo di tutto quello che include. Niente costi a sorpresa dopo.`
6. `Consegnate davvero in tutta Italia?` → `Si, e lo facciamo a mano: il cucciolo viaggia accompagnato, non in un pacco. Concordiamo insieme giorno e modalita di consegna.`
7. `Barboncino e Maltipoo perdono pelo?` → `Pochissimo: sono tra i tipi di cane con la minor dispersione di pelo, per questo sono amatissime in appartamento. Nessun cane e ipoallergenico al cento per cento, ma qui siamo molto vicini.`
8. `La taglia toy e riconosciuta ufficialmente?` → `Dipende dal tipo: il Barboncino Toy e una varieta riconosciuta, mentre per il Maltese la parola toy indica la selezione di taglia piu piccola, non una categoria ufficiale. Te lo diciamo chiaramente perche e cosi che vogliamo lavorare: parole oneste, cuccioli veri.`

### 7g. #contatti — prenotazione
Kicker: `PRENOTA IL TUO CUCCIOLO`
Titolo: `Raccontaci chi stai cercando.`
Lede: `Rispondiamo in giornata con disponibilita, foto e video reali. Se preferisci fare prima, WhatsApp e la via piu rapida.`
Form: NOME* / TELEFONO / EMAIL / TIPO DI CUCCIOLO (select: le 4 + `Non so ancora`) / MESSAGGIO /
checkbox: `Ho letto l'informativa privacy e acconsento al trattamento dei dati per essere ricontattato.`
Bottone: `Invia la richiesta`
Placeholder messaggio: `Es. Cerco una femmina di Barboncino Toy albicocca, siamo una famiglia con due bambini...`
Stato di successo: `Richiesta arrivata! Ti ricontattiamo entro poche ore con foto e disponibilita.`
Colonna contatti: WhatsApp `+39 377 087 6017` (CTA primaria), telefono, email `houseofdogdigiuseppe@outlook.it`.

### 7h. Footer
Brand + una riga: `Da 20 anni, cuccioli toy selezionati con passione, dalla nostra famiglia alla tua.`
Colonne: Tipi di cucciolo (ancore) / Contatti / Legale (Privacy, P.IVA 03933680401, PEC casadipluto@pec.buffetti.it).
Nota AI (scelta dall'utente): `I visual di apertura sono generati digitalmente. I cuccioli nelle foto dei tipi sono reali.`
Copyright: `© 2026 House of Dogs. Tutti i diritti riservati.`

## 8. Il piano del layer vettoriale

- **Logo nuovo** (SVG inline, disegnato a mano): casetta dal tetto morbido con
  un'impronta di zampa al posto della porta. Sostituisce il vecchio logo
  (personaggio Disney, non riutilizzabile). Anche favicon SVG.
- **Il guinzaglio**: linea SVG curva che si disegna con lo scroll lungo la
  sezione come-funziona, collega i 4 passi e finisce con un'impronta accanto
  alla CTA. Elemento firma del sito.
- **Impronte di zampa**: piccole, si accendono in sequenza accanto ai passi.
- Particelle pulviscolo a livello sussurro nell'hero e nella sezione scura.
- Tutto rispetta reduced motion: stati finali, niente drive.

## 9. La lista ingegneristica

Standard completo di scrub-pipeline.md, gia implementato in assets/js/hero.js e
assets/css/custom.css: Blob streaming con anello, lerp dt-normalizzato, seek con
gate anti-deadlock, scritture DOM delta-gated, bande con plateau 80-130vh e flick
test, sistema di leggibilita a 4 livelli con audit worst-frame 3.5:1, i 5 gate
statici identici in CSS e JS con listener live, reduced motion live in entrambe
le direzioni, pagina completa senza video, overflow-x clip, focus-visible,
target touch 44px, un solo layer ambiente fisso, entrate con stagger ritirato.

## 10. SEO (una pagina)

- title: `Cuccioli Toy in Vendita da Allevamento | House of Dogs`
- meta description: `Barboncino Toy, Maltipoo, Maltese Toy e Bassotto Kaninchen: cuccioli selezionati, sani e socializzati. Consegna in tutta Italia. Prenota il tuo cucciolo.`
- H1 unico nella sezione #cuccioli (keyword transazionale), hero con testo non-heading + span sr-only.
- JSON-LD: LocalBusiness (name, url, telephone, email, vatID, areaServed IT, logo),
  FAQPage (le 8 FAQ visibili), ItemList dei 4 tipi di cucciolo. NIENTE Product/Offer con
  prezzo (animali vivi), NIENTE rating auto-prodotti.
- OG/Twitter con URL assoluti su https://www.thehouseofdogs.it/ (dominio noto).
- sitemap.xml + robots.txt. Immagini: file rinominati con keyword, alt descrittivi,
  lazy sotto il fold, dimensioni esplicite per zero CLS.

## 11. La riga del gate sul copy

Ogni riga sopra va in pagina verbatim. Gate Fase 9 prima di mostrare qualsiasi
cosa: zero em dash, zero parole stock (leverage, seamless, empower, unlock,
robust, actionable, data-driven, solutions), sweep dei tell AI ("non e solo X,
e Y", range finti, attribuzioni vaghe, chiuse generiche). Dispositivo voluto che
RESTA: lo staccato `Barboncino. Maltipoo. Maltese. Bassotto.` della banda 3.
