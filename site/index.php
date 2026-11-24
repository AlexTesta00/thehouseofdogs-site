<?php
/* House of Dogs - pagina unica. Il form invia a invia.php.
   Flag di ritorno per il fallback senza JavaScript. */
$formInviato = isset($_GET['inviato']);
$formErrore  = isset($_GET['errore']);
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cuccioli Toy in Vendita da Allevamento | House of Dogs</title>
  <meta name="description" content="Barboncino Toy, Maltipoo, Maltese Toy e Bassotto Kaninchen: cuccioli selezionati, sani e socializzati. Consegna in tutta Italia. Prenota il tuo cucciolo.">
  <link rel="canonical" href="https://www.thehouseofdog.it/">
  <link rel="preload" href="assets/fonts/inter-var-latin.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="assets/css/fonts.css">
  <meta name="theme-color" content="#0c3a66">

  <meta property="og:type" content="website">
  <meta property="og:locale" content="it_IT">
  <meta property="og:site_name" content="House of Dogs">
  <meta property="og:title" content="Cuccioli Toy in Vendita da Allevamento | House of Dogs">
  <meta property="og:description" content="Barboncino Toy, Maltipoo, Maltese Toy e Bassotto Kaninchen: cuccioli selezionati, sani e socializzati. Consegna a mano in tutta Italia.">
  <meta property="og:url" content="https://www.thehouseofdog.it/">
  <!-- Anteprima condivisione (WhatsApp, Facebook, Instagram, Telegram): il logo su fondo bianco, 1200x630 -->
  <meta property="og:image" content="https://www.thehouseofdog.it/assets/img/og-image.jpg">
  <meta property="og:image:secure_url" content="https://www.thehouseofdog.it/assets/img/og-image.jpg">
  <meta property="og:image:type" content="image/jpeg">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="Logo House of Dogs">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="Cuccioli Toy in Vendita da Allevamento | House of Dogs">
  <meta name="twitter:description" content="Cuccioli toy selezionati, sani e socializzati. Consegna a mano in tutta Italia.">
  <meta name="twitter:image" content="https://www.thehouseofdog.it/assets/img/og-image.jpg">
  <meta name="twitter:image:alt" content="Logo House of Dogs">

  <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32.png">
  <link rel="icon" type="image/png" sizes="192x192" href="assets/img/favicon-192.png">
  <link rel="apple-touch-icon" sizes="180x180" href="assets/img/apple-touch-icon.png">


  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: { extend: {
        /* Palette bianco e azzurro dal logo. I nomi cream/creamsoft restano per compatibilita con il markup:
           oggi valgono bianco e azzurro ghiaccio (testo sulle sezioni blu). */
        colors: { accent: '#1470b3', sky: '#1a90d0', canvas: '#ffffff', panel: '#f3f8fd', deep: '#0c3a66', deeppanel: '#11457a', ink: '#0f2a44', inksoft: '#4a6079', cream: '#ffffff', creamsoft: '#c5dcf2' },
        /* Un solo carattere: Inter. display/body/monolabel puntano tutti a Inter, i pesi li decide custom.css */
        fontFamily: { display: ['Inter', 'system-ui', 'sans-serif'], body: ['Inter', 'system-ui', 'sans-serif'], monolabel: ['Inter', 'system-ui', 'sans-serif'] }
      } }
    };
  </script>
  <link rel="stylesheet" href="assets/css/custom.css">

  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "LocalBusiness",
    "name": "House of Dogs",
    "legalName": "The House Of Dog di G.F.",
    "description": "Selezione di cuccioli toy italiani: Barboncino Toy, Maltipoo Toy, Maltese Toy e Bassotto Kaninchen. Consegna in tutta Italia.",
    "url": "https://www.thehouseofdog.it/",
    "telephone": "+393770876017",
    "email": "houseofdogdigiuseppe@outlook.it",
    "vatID": "IT03933680401",
    "address": { "@type": "PostalAddress", "streetAddress": "Contrada Marinella s.n.c.", "postalCode": "70022", "addressLocality": "Altamura", "addressRegion": "BA", "addressCountry": "IT" },
    "areaServed": { "@type": "Country", "name": "Italia" },
    "image": "https://www.thehouseofdog.it/assets/img/og-image.jpg",
    "logo": "https://www.thehouseofdog.it/assets/img/logo.jpg",
    "sameAs": [
      "https://www.instagram.com/house_of_dog_official/",
      "https://www.facebook.com/mysweetspuppies"
    ]
  }
  </script>
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "ItemList",
    "name": "Cuccioli toy in vendita",
    "itemListElement": [
      { "@type": "ListItem", "position": 1, "name": "Barboncino Toy", "url": "https://www.thehouseofdog.it/#barboncino-toy", "image": "https://www.thehouseofdog.it/assets/img/barboncino-toy.webp" },
      { "@type": "ListItem", "position": 2, "name": "Maltipoo Toy", "url": "https://www.thehouseofdog.it/#maltipoo-toy", "image": "https://www.thehouseofdog.it/assets/img/maltipoo-toy.webp" },
      { "@type": "ListItem", "position": 3, "name": "Maltese Toy", "url": "https://www.thehouseofdog.it/#maltese-toy", "image": "https://www.thehouseofdog.it/assets/img/maltese-toy.webp" },
      { "@type": "ListItem", "position": 4, "name": "Bassotto Kaninchen", "url": "https://www.thehouseofdog.it/#bassotto-kaninchen", "image": "https://www.thehouseofdog.it/assets/img/bassotto-kaninchen.webp" }
    ]
  }
  </script>
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    "mainEntity": [
      { "@type": "Question", "name": "Come faccio a fidarmi? Il web è pieno di truffe sui cuccioli.", "acceptedAnswer": { "@type": "Answer", "text": "Hai ragione a essere prudente. Per questo non ti chiediamo mai di fidarti al buio: ti mostriamo i cuccioli in foto e video dal vivo prima di qualsiasi decisione, rispondiamo al telefono con nome e cognome e ogni consegna viaggia con documenti e accordi scritti. Siamo un'attività registrata, con partita IVA visibile sul sito, e in 20 anni abbiamo accompagnato centinaia di famiglie." } },
      { "@type": "Question", "name": "Il cucciolo resterà davvero piccolo?", "acceptedAnswer": { "@type": "Answer", "text": "La taglia da adulto la scrive la genetica, non le promesse. Per questo selezioniamo linee toy seguite nel tempo e ti diciamo sempre peso e taglia dei genitori, con una stima onesta di come crescerà." } },
      { "@type": "Question", "name": "Che documenti ricevo con il cucciolo?", "acceptedAnswer": { "@type": "Answer", "text": "Microchip registrato, libretto sanitario compilato dal veterinario con vaccinazioni e sverminazioni, certificato di tipo (Barboncino Toy, Maltipoo, Maltese Toy o Bassotto Kaninchen) in prima origine (si specifica che la richiesta deve essere fatta dal cliente e che eventuali costi saranno a carico del cliente) e accordo di vendita scritto. Tutto ti viene consegnato insieme a lui. Si dichiara esplicitamente che non viene rilasciato pedigree ENCI." } },
      { "@type": "Question", "name": "A che età consegnate i cuccioli?", "acceptedAnswer": { "@type": "Answer", "text": "Mai prima dei 60 giorni di vita, come prevede la legge italiana: è il tempo che serve a un cucciolo per crescere sereno accanto alla mamma. Alla consegna ha già il primo ciclo vaccinale." } },
      { "@type": "Question", "name": "Quanto costa un cucciolo toy?", "acceptedAnswer": { "@type": "Answer", "text": "Dipende dal tipo di cucciolo (Barboncino Toy, Maltipoo, Maltese Toy o Bassotto Kaninchen), dal colore e dalla linea di sangue. Scrivici su WhatsApp: ti diciamo subito disponibilità e prezzo, chiaro e completo di tutto quello che include. Si prenota con una caparra e il saldo si può pagare anche a rate con finanziamento, previa approvazione. Niente costi a sorpresa dopo." } },
      { "@type": "Question", "name": "Consegnate davvero in tutta Italia?", "acceptedAnswer": { "@type": "Answer", "text": "Sì, e lo facciamo a mano: il cucciolo viaggia accompagnato, non in un pacco. Concordiamo insieme giorno, modalità e rimborso delle spese di viaggio, sempre prima della prenotazione." } },
      { "@type": "Question", "name": "Barboncino e Maltipoo perdono pelo?", "acceptedAnswer": { "@type": "Answer", "text": "Pochissimo: sono tra i tipi di cane con la minor dispersione di pelo, per questo sono amatissime in appartamento. Nessun cane è ipoallergenico al cento per cento, ma qui siamo molto vicini." } },
      { "@type": "Question", "name": "La taglia toy è riconosciuta ufficialmente?", "acceptedAnswer": { "@type": "Answer", "text": "Dipende dal tipo: il Barboncino Toy è una varietà riconosciuta, mentre per il Maltese la parola toy indica la selezione di taglia più piccola, non una categoria ufficiale. Te lo diciamo chiaramente perché è così che vogliamo lavorare: parole oneste, cuccioli veri." } }
    ]
  }
  </script>
</head>
<body class="font-body">
<a class="skip-link" href="#main">Vai al contenuto</a>

<!-- ambiente: un solo layer fisso -->
<div class="env-layer" aria-hidden="true"></div>

<!-- ============ NAV ============ -->
<header id="topnav" class="fixed top-0 inset-x-0 z-50 transition-colors duration-500">
  <nav class="mx-auto max-w-6xl flex items-center justify-between gap-3 px-4 sm:px-6 py-3" aria-label="Principale">
    <a href="#" class="flex items-center gap-2.5 text-cream" aria-label="House of Dogs, torna su">
      <img src="assets/img/favicon-192.png" alt="" width="40" height="40" decoding="async" class="w-10 h-10 rounded-full shrink-0 ring-2 ring-white/80 bg-white">
      <span class="font-display text-lg leading-none">House of Dogs</span>
    </a>
    <div class="hidden md:flex items-center gap-7 text-sm text-cream/90">
      <a class="nav-link hover:text-white transition-colors" href="#cuccioli">Cuccioli</a>
      <a class="nav-link hover:text-white transition-colors" href="#perche-noi">Perché noi</a>
      <a class="nav-link hover:text-white transition-colors" href="#come-funziona">Come funziona</a>
      <a class="nav-link hover:text-white transition-colors" href="#domande">Domande</a>
      <a class="nav-link hover:text-white transition-colors" href="#contatti">Prenota</a>
    </div>
    <button id="menu-btn" class="md:hidden text-cream p-2 rounded-lg" aria-expanded="false" aria-controls="mobile-menu" aria-label="Apri il menu">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
  </nav>
  <div id="mobile-menu" class="hidden md:hidden bg-deep/95 backdrop-blur border-t border-white/10">
    <div class="px-6 py-4 flex flex-col gap-4 text-cream">
      <a class="nav-link" href="#cuccioli">Cuccioli</a>
      <a class="nav-link" href="#perche-noi">Perché noi</a>
      <a class="nav-link" href="#come-funziona">Come funziona</a>
      <a class="nav-link" href="#domande">Domande</a>
      <a class="nav-link" href="#contatti">Prenota</a>
    </div>
  </div>
</header>

<!-- ============ HERO ============ -->
<div id="hero-pin">
  <section id="hero-stage" aria-label="House of Dogs, cuccioli toy selezionati"
    data-video="assets/hero-scrub.mp4" data-poster="assets/img/hero-poster.jpg" data-video-bytes="3424091">

    <video id="hero-video" preload="none" muted playsinline aria-hidden="true" tabindex="-1"></video>
    <div id="hero-poster" aria-hidden="true"></div>
    <div class="scrim" aria-hidden="true"></div>

    <svg id="hero-ring" viewBox="0 0 48 48" aria-hidden="true">
      <circle cx="24" cy="24" r="20" fill="none" stroke="currentColor" stroke-width="3" stroke-dasharray="126" style="stroke-dashoffset:var(--ld,126)"/>
    </svg>

    <!-- banda 1: il gancio -->
    <div class="band" data-a="0.00" data-b="0.26" style="--sx:32%;--sa:.66">
      <div class="band-inner mx-auto max-w-6xl px-6">
        <div class="max-w-xl">
          <p class="font-monolabel text-xs text-creamsoft mb-4 approach">HOUSE OF DOGS · DA 20 ANNI</p>
          <p class="font-display text-4xl lg:text-6xl leading-tight approach">
            <span class="sr-only">C'è un posto in casa che aspetta solo lui.</span>
            <span aria-hidden="true">C'è un posto in casa che aspetta solo lui.</span>
          </p>
        </div>
      </div>
    </div>

    <!-- banda 2: la fiducia -->
    <div class="band" data-a="0.285" data-b="0.55" style="--sx:32%;--sa:.62">
      <div class="band-inner mx-auto max-w-6xl px-6">
        <div class="max-w-xl driftdown">
          <p class="font-display text-3xl lg:text-5xl leading-tight split-words">
            <span class="sr-only">Cuccioli toy italiani, selezionati con 20 anni di passione.</span>
            <span aria-hidden="true" data-split>Cuccioli toy italiani, selezionati con 20 anni di passione.</span>
          </p>
          <p class="mt-4 text-lg text-cream/85 split-words">
            <span class="sr-only">Sani, socializzati, con documenti veri.</span>
            <span aria-hidden="true" data-split data-th-base="0.3">Sani, socializzati, con documenti veri.</span>
          </p>
        </div>
      </div>
    </div>

    <!-- banda 3: i tipi di cucciolo, staccato -->
    <div class="band" data-a="0.555" data-b="0.825" data-spread="0.4" style="--sx:32%;--sa:.62">
      <div class="band-inner mx-auto max-w-6xl px-6">
        <div class="max-w-2xl split">
          <p class="font-display text-3xl lg:text-5xl leading-snug">
            <span class="sr-only">Barboncino. Maltipoo. Maltese. Bassotto.</span>
            <span aria-hidden="true" data-split-chars>Barboncino. Maltipoo. Maltese. Bassotto.</span>
          </p>
          <p class="mt-4 text-lg text-cream/85 driftdown">
            <span class="sr-only">Il tuo ti sta già aspettando.</span>
            <span aria-hidden="true" data-split data-th-base="0.45">Il tuo ti sta già aspettando.</span>
          </p>
        </div>
      </div>
    </div>

    <!-- banda 4: il settle -->
    <div class="band settle" data-a="0.84" data-b="1.00" data-ramp="0.05" style="--sx:50%;--sa:.62">
      <div class="band-inner mx-auto max-w-6xl px-6 text-center" style="transform:translateY(-14vh)">
        <p class="font-display text-5xl lg:text-7xl leading-tight">
          <span class="sr-only">Portalo a casa.</span>
          <span aria-hidden="true" data-split-rise>Portalo a casa.</span>
        </p>
        <p class="settle-sub mt-5 text-lg lg:text-xl text-cream/90 max-w-xl mx-auto">Consegna a mano in tutta Italia, con microchip, libretto sanitario e certificato di tipo (Barboncino Toy, Maltipoo, Maltese Toy o Bassotto Kaninchen) in prima origine (si specifica che la richiesta deve essere fatta dal cliente e che eventuali costi saranno a carico del cliente).</p>
        <div class="settle-cta mt-8 flex flex-wrap items-center justify-center gap-3">
          <a href="https://wa.me/393770876017?text=Ciao!%20Ho%20visto%20il%20sito%20e%20vorrei%20informazioni%20sui%20cuccioli%20disponibili." target="_blank" rel="noopener" class="btn btn-wa rounded-full px-6 py-3 font-semibold">Scrivici su WhatsApp</a>
          <a href="#cuccioli" class="btn btn-ghost rounded-full px-6 py-3 font-semibold">Scopri i cuccioli</a>
        </div>
      </div>
    </div>

    <!-- cue di scroll -->
    <div id="hero-cue" aria-hidden="true" class="flex flex-col items-center gap-1.5">
      <span class="font-monolabel text-[10px]">SCORRI</span>
      <svg width="14" height="22" viewBox="0 0 14 22" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="1" y="1" width="12" height="20" rx="6"/><circle class="cue-dot" cx="7" cy="7" r="1.6" fill="currentColor" stroke="none"/></svg>
    </div>

    <!-- hero statico: telefoni, tablet portrait, reduced motion.
         Su telefono (senza riduci-animazioni) parte il video ambient muto in loop. -->
    <div id="hero-static" style="background-image:url('assets/img/hero-ending.jpg')">
      <video id="hero-ambient" muted playsinline preload="none" poster="assets/img/hero-ending.jpg" aria-hidden="true" tabindex="-1"></video>
      <div class="static-copy w-full px-6 pb-14 pt-32 max-w-xl mx-auto text-center sm:text-left sm:mx-0">
        <p class="font-monolabel text-xs text-creamsoft mb-3">HOUSE OF DOGS · DA 20 ANNI</p>
        <p class="font-display text-4xl leading-tight">C'è un posto in casa che aspetta solo lui.</p>
        <p class="mt-4 text-base text-cream/90">Cuccioli toy italiani, sani e socializzati. Consegna a mano in tutta Italia, con documenti veri.</p>
        <div class="mt-7 flex flex-wrap justify-center sm:justify-start gap-3">
          <a href="https://wa.me/393770876017?text=Ciao!%20Ho%20visto%20il%20sito%20e%20vorrei%20informazioni%20sui%20cuccioli%20disponibili." target="_blank" rel="noopener" class="btn btn-wa rounded-full px-6 py-3 font-semibold">Scrivici su WhatsApp</a>
          <a href="#cuccioli" class="btn btn-ghost rounded-full px-6 py-3 font-semibold">Scopri i cuccioli</a>
        </div>
      </div>
    </div>
  </section>
</div>

<main id="main" tabindex="-1">

  <!-- ============ STRISCIA FIDUCIA ============ -->
  <section class="bg-canvas border-b border-ink/10" aria-label="Le nostre garanzie in breve">
    <div class="trust-strip mx-auto max-w-6xl px-6 py-6 grid grid-cols-2 md:grid-cols-4 gap-x-6 gap-y-4 font-monolabel text-[11px] md:text-xs text-inksoft">
      <div class="flex items-center gap-2.5"><span class="w-1.5 h-1.5 rounded-full bg-accent shrink-0" aria-hidden="true"></span>20 anni di selezione</div>
      <div class="flex items-center gap-2.5"><span class="w-1.5 h-1.5 rounded-full bg-accent shrink-0" aria-hidden="true"></span>Consegna a mano in tutta Italia</div>
      <div class="flex items-center gap-2.5"><span class="w-1.5 h-1.5 rounded-full bg-accent shrink-0" aria-hidden="true"></span>Microchip e libretto sanitario</div>
      <div class="flex items-center gap-2.5"><span class="w-1.5 h-1.5 rounded-full bg-accent shrink-0" aria-hidden="true"></span>Assistenza per tutta la vita</div>
    </div>
  </section>

  <!-- ============ CUCCIOLI ============ -->
  <section id="cuccioli" class="bg-canvas py-20 md:py-28 relative">
    <div class="mx-auto max-w-6xl px-6">
      <div class="reveal max-w-3xl">
        <p class="part font-monolabel text-xs text-accent mb-4">I NOSTRI CUCCIOLI</p>
        <h1 class="part font-display text-3xl md:text-5xl leading-tight text-ink">Cuccioli toy in vendita: Barboncino, Maltipoo, Maltese e Bassotto Kaninchen</h1>
        <p class="part mt-5 text-lg text-inksoft max-w-2xl">Quattro tipi di cucciolo (Barboncino Toy, Maltipoo, Maltese Toy e Bassotto Kaninchen), una promessa sola: taglia mini, carattere equilibrato e una crescita seguita giorno per giorno, da quando nascono a quando ti guardano per la prima volta.</p>
      </div>

      <div class="mt-12 grid gap-6 md:grid-cols-2 reveal">
        <!-- Barboncino -->
        <article id="barboncino-toy" class="part breed-card bg-panel rounded-3xl overflow-hidden border border-ink/10">
          <div class="overflow-hidden aspect-[4/3]">
            <img src="assets/img/barboncino-toy.webp" alt="Cucciolo di Barboncino Toy rosso che riposa su una coperta" width="600" height="600" loading="lazy" decoding="async" class="w-full h-full object-cover">
          </div>
          <div class="p-6 md:p-7">
            <h2 class="font-display text-2xl text-ink">Barboncino Toy</h2>
            <p class="mt-2 text-inksoft">Intelligente da vendere, non perde pelo, ama imparare.</p>
            <div class="mt-4 flex flex-wrap gap-2 font-monolabel text-[10px] text-inksoft">
              <span class="px-3 py-1.5 rounded-full border border-ink/15">non perde pelo</span>
              <span class="px-3 py-1.5 rounded-full border border-ink/15">super intelligente</span>
              <span class="px-3 py-1.5 rounded-full border border-ink/15">red e albicocca</span>
            </div>
            <p class="mt-4 text-sm text-inksoft">Il piccolo genio di casa: attento, elegante, sempre in sintonia con te.</p>
            <div class="mt-5 flex flex-wrap items-start gap-2.5">
              <a href="https://wa.me/393770876017?text=Ciao!%20Vorrei%20informazioni%20sui%20cuccioli%20di%20Barboncino%20Toy%20disponibili." target="_blank" rel="noopener" class="btn btn-primary rounded-full px-5 py-2.5 inline-flex text-sm font-semibold">Chiedi disponibilità</a>
              <!-- PRENOTAZIONE CON BONIFICO (prenota.php): CAPARRA BARBONCINO TOY -->
              <a id="prenota-barboncino" href="prenota.php?tipo=barboncino" class="btn btn-outline rounded-full px-5 py-2.5 inline-flex text-sm font-semibold">Prenota</a>
            </div>
          </div>
        </article>
        <!-- Maltipoo -->
        <article id="maltipoo-toy" class="part breed-card bg-panel rounded-3xl overflow-hidden border border-ink/10 md:mt-10">
          <div class="overflow-hidden aspect-[4/3]">
            <img src="assets/img/maltipoo-toy.webp" alt="Tre cuccioli di Maltipoo Toy color albicocca seduti su una coperta" width="900" height="606" loading="lazy" decoding="async" class="w-full h-full object-cover">
          </div>
          <div class="p-6 md:p-7">
            <h2 class="font-display text-2xl text-ink">Maltipoo Toy</h2>
            <p class="mt-2 text-inksoft">Il musetto da peluche che conquista tutta la famiglia.</p>
            <div class="mt-4 flex flex-wrap gap-2 font-monolabel text-[10px] text-inksoft">
              <span class="px-3 py-1.5 rounded-full border border-ink/15">aspetto teddy</span>
              <span class="px-3 py-1.5 rounded-full border border-ink/15">poco pelo in giro</span>
              <span class="px-3 py-1.5 rounded-full border border-ink/15">dolcissimo</span>
            </div>
            <p class="mt-4 text-sm text-inksoft">Morbido e affettuoso, nato per stare in braccio. Le nostre cucciolate sono italiane, da genitori selezionati.</p>
            <div class="mt-5 flex flex-wrap items-start gap-2.5">
              <a href="https://wa.me/393770876017?text=Ciao!%20Vorrei%20informazioni%20sui%20cuccioli%20di%20Maltipoo%20Toy%20disponibili." target="_blank" rel="noopener" class="btn btn-primary rounded-full px-5 py-2.5 inline-flex text-sm font-semibold">Chiedi disponibilità</a>
              <!-- PRENOTAZIONE CON BONIFICO (prenota.php): CAPARRA MALTIPOO TOY -->
              <a id="prenota-maltipoo" href="prenota.php?tipo=maltipoo" class="btn btn-outline rounded-full px-5 py-2.5 inline-flex text-sm font-semibold">Prenota</a>
            </div>
          </div>
        </article>
        <!-- Maltese -->
        <article id="maltese-toy" class="part breed-card bg-panel rounded-3xl overflow-hidden border border-ink/10">
          <div class="overflow-hidden aspect-[4/3]">
            <img src="assets/img/maltese-toy.webp" alt="Cucciolo di Maltese Toy bianco tenuto delicatamente tra le mani" width="563" height="680" loading="lazy" decoding="async" class="w-full h-full object-cover object-top">
          </div>
          <div class="p-6 md:p-7">
            <h2 class="font-display text-2xl text-ink">Maltese Toy</h2>
            <p class="mt-2 text-inksoft">Un batuffolo bianco dal carattere nobile e tranquillo.</p>
            <div class="mt-4 flex flex-wrap gap-2 font-monolabel text-[10px] text-inksoft">
              <span class="px-3 py-1.5 rounded-full border border-ink/15">mantello di seta</span>
              <span class="px-3 py-1.5 rounded-full border border-ink/15">tranquillo</span>
              <span class="px-3 py-1.5 rounded-full border border-ink/15">da appartamento</span>
            </div>
            <p class="mt-4 text-sm text-inksoft">Compagno storico dei salotti italiani: pulito, silenzioso, devoto.</p>
            <div class="mt-5 flex flex-wrap items-start gap-2.5">
              <a href="https://wa.me/393770876017?text=Ciao!%20Vorrei%20informazioni%20sui%20cuccioli%20di%20Maltese%20Toy%20disponibili." target="_blank" rel="noopener" class="btn btn-primary rounded-full px-5 py-2.5 inline-flex text-sm font-semibold">Chiedi disponibilità</a>
              <!-- PRENOTAZIONE CON BONIFICO (prenota.php): CAPARRA MALTESE TOY -->
              <a id="prenota-maltese" href="prenota.php?tipo=maltese" class="btn btn-outline rounded-full px-5 py-2.5 inline-flex text-sm font-semibold">Prenota</a>
            </div>
          </div>
        </article>
        <!-- Bassotto -->
        <article id="bassotto-kaninchen" class="part breed-card bg-panel rounded-3xl overflow-hidden border border-ink/10 md:mt-10">
          <div class="overflow-hidden aspect-[4/3]">
            <img src="assets/img/bassotto-kaninchen.webp" alt="Tre cuccioli di Bassotto Kaninchen arlecchino su una poltrona" width="900" height="615" loading="lazy" decoding="async" class="w-full h-full object-cover">
          </div>
          <div class="p-6 md:p-7">
            <h2 class="font-display text-2xl text-ink">Bassotto Kaninchen</h2>
            <p class="mt-2 text-inksoft">Piccolo fuori, coraggioso dentro. Un carisma unico.</p>
            <div class="mt-4 flex flex-wrap gap-2 font-monolabel text-[10px] text-inksoft">
              <span class="px-3 py-1.5 rounded-full border border-ink/15">taglia kaninchen</span>
              <span class="px-3 py-1.5 rounded-full border border-ink/15">giocherellone</span>
              <span class="px-3 py-1.5 rounded-full border border-ink/15">anche arlecchino</span>
            </div>
            <p class="mt-4 text-sm text-inksoft">Il più piccolo dei bassotti: curioso, buffo, incollato a te.</p>
            <div class="mt-5 flex flex-wrap items-start gap-2.5">
              <a href="https://wa.me/393770876017?text=Ciao!%20Vorrei%20informazioni%20sui%20cuccioli%20di%20Bassotto%20Kaninchen%20disponibili." target="_blank" rel="noopener" class="btn btn-primary rounded-full px-5 py-2.5 inline-flex text-sm font-semibold">Chiedi disponibilità</a>
              <!-- PRENOTAZIONE CON BONIFICO (prenota.php): il Bassotto ha due varianti di colore, ARLECCHINO e CIOCCOLATO -->
              <details id="prenota-bassotto" class="prenota-variants">
                <summary class="btn btn-outline rounded-full px-5 py-2.5 inline-flex text-sm font-semibold">Prenota</summary>
                <div class="mt-2.5 flex flex-wrap gap-2">
                  <a href="prenota.php?tipo=bassotto-arlecchino" class="btn btn-outline rounded-full px-4 py-2 inline-flex text-xs font-semibold">Arlecchino</a>
                  <a href="prenota.php?tipo=bassotto-cioccolato" class="btn btn-outline rounded-full px-4 py-2 inline-flex text-xs font-semibold">Cioccolato</a>
                </div>
              </details>
            </div>
          </div>
        </article>
      </div>

      <p class="reveal mt-8 text-sm text-inksoft max-w-3xl"><span class="part">Con il bottone <strong>Prenota</strong> prenoti online e versi la caparra di prenotazione con bonifico bancario istantaneo. Prima ti confermiamo sempre tutto per iscritto: cucciolo, prezzo totale, documenti e consegna. <strong>La caparra non è rimborsabile</strong> in caso di rinuncia. La prenotazione è regolata dalle nostre <a href="termini.php" class="underline underline-offset-2 text-accent">Condizioni di vendita</a>.</span></p>
    </div>
  </section>

  <!-- ============ PERCHE NOI ============ -->
  <section id="perche-noi" class="bg-deep text-cream py-20 md:py-28 relative overflow-hidden alive">
    <div class="mx-auto max-w-6xl px-6 grid lg:grid-cols-2 gap-12 lg:gap-16 items-start">
      <div class="reveal lg:sticky lg:top-24">
        <p class="part font-monolabel text-xs text-accent mb-4">PERCHÉ HOUSE OF DOGS</p>
        <h2 class="part font-display text-3xl md:text-5xl leading-tight">Da 20 anni portiamo cuccioli sani nelle case italiane.</h2>
        <p class="part mt-5 text-lg text-creamsoft max-w-lg">Sappiamo cosa si prova a cercare un cucciolo online: tante promesse, poca chiarezza. Noi lavoriamo al contrario. Prima ti mostriamo tutto, poi decidi.</p>
        <div class="part mt-8 rounded-3xl overflow-hidden border border-white/10 relative glowpulse breathe">
          <img src="assets/img/hero-ending.jpg" alt="Cucciolo toy che dorme sereno su un plaid color crema" width="1280" height="720" loading="lazy" decoding="async" class="w-full object-cover">
        </div>
      </div>
      <ol class="reveal grid gap-5 list-none">
        <li class="part bg-deeppanel rounded-2xl p-6 md:p-7 border border-white/5">
          <p class="font-monolabel text-[10px] text-accent mb-2">01</p>
          <h3 class="font-display text-xl">Cuccioli italiani, linee controllate</h3>
          <p class="mt-2 text-creamsoft">Selezioniamo cucciolate italiane da linee di sangue seguite nel tempo. Niente importazioni misteriose, niente cuccioli senza storia.</p>
        </li>
        <li class="part bg-deeppanel rounded-2xl p-6 md:p-7 border border-white/5">
          <p class="font-monolabel text-[10px] text-accent mb-2">02</p>
          <h3 class="font-display text-xl">Salute prima di tutto</h3>
          <p class="mt-2 text-creamsoft">Test genetici sui riproduttori e controlli veterinari sui cuccioli. Ti consegniamo un cucciolo visitato, vaccinato e sverminato.</p>
        </li>
        <li class="part bg-deeppanel rounded-2xl p-6 md:p-7 border border-white/5">
          <p class="font-monolabel text-[10px] text-accent mb-2">03</p>
          <h3 class="font-display text-xl">Documenti veri, sempre</h3>
          <p class="mt-2 text-creamsoft">Ogni cucciolo parte con microchip e libretto sanitario compilato dal veterinario; su richiesta, certificato di tipo (Barboncino Toy, Maltipoo, Maltese Toy o Bassotto Kaninchen) in prima origine (si specifica che la richiesta deve essere fatta dal cliente e che eventuali costi saranno a carico del cliente). Tutto nero su bianco.</p>
        </li>
        <li class="part bg-deeppanel rounded-2xl p-6 md:p-7 border border-white/5">
          <p class="font-monolabel text-[10px] text-accent mb-2">04</p>
          <h3 class="font-display text-xl">Con te anche dopo</h3>
          <p class="mt-2 text-creamsoft">La consegna non è un addio: per dubbi, consigli e crescita, restiamo il tuo punto di riferimento per tutta la vita del cane.</p>
        </li>
      </ol>
    </div>
  </section>

  <!-- ============ COME FUNZIONA ============ -->
  <section id="come-funziona" class="bg-canvas py-20 md:py-28 relative">
    <div class="mx-auto max-w-4xl px-6 relative">
      <div class="reveal text-center">
        <p class="part font-monolabel text-xs text-accent mb-4">COME FUNZIONA</p>
        <h2 class="part font-display text-3xl md:text-5xl leading-tight text-ink">Dal primo messaggio alla tua porta, senza sorprese.</h2>
      </div>

      <div class="relative mt-16">
        <!-- il guinzaglio: si disegna con lo scroll -->
        <svg class="leash-svg absolute left-5 md:left-1/2 md:-translate-x-1/2 top-0 h-full w-8 md:w-24" viewBox="0 0 100 1000" preserveAspectRatio="none" aria-hidden="true">
          <path id="leash-path" class="leash-path" d="M50 0 C 20 120, 80 200, 50 310 C 25 400, 75 480, 50 590 C 28 680, 72 760, 50 870 C 42 920, 50 960, 50 1000" vector-effect="non-scaling-stroke"/>
        </svg>

        <ol class="relative grid gap-10 md:gap-14 list-none">
          <li class="step-item reveal md:grid md:grid-cols-2 md:gap-10 items-center pl-14 md:pl-0">
            <div class="part md:text-right md:pr-8">
              <p class="font-monolabel text-[10px] text-accent mb-2 flex items-center gap-2 md:justify-end"><span class="paw-step" aria-hidden="true">🐾</span>PASSO 1</p>
              <h3 class="font-display text-2xl text-ink">Scrivici</h3>
              <p class="mt-2 text-inksoft">Un messaggio su WhatsApp basta. Dicci che tipo di cucciolo ami (Barboncino Toy, Maltipoo, Maltese Toy o Bassotto Kaninchen) e che casa lo aspetta.</p>
            </div><div aria-hidden="true"></div>
          </li>
          <li class="step-item reveal md:grid md:grid-cols-2 md:gap-10 items-center pl-14 md:pl-0">
            <div aria-hidden="true" class="hidden md:block"></div>
            <div class="part md:pl-8">
              <p class="font-monolabel text-[10px] text-accent mb-2 flex items-center gap-2"><span class="paw-step" aria-hidden="true">🐾</span>PASSO 2</p>
              <h3 class="font-display text-2xl text-ink">Guarda i cuccioli dal vivo</h3>
              <p class="mt-2 text-inksoft">Ti mandiamo foto e video reali dei cuccioli disponibili. Vedi come sono, non come sembrano.</p>
            </div>
          </li>
          <li class="step-item reveal md:grid md:grid-cols-2 md:gap-10 items-center pl-14 md:pl-0">
            <div class="part md:text-right md:pr-8">
              <p class="font-monolabel text-[10px] text-accent mb-2 flex items-center gap-2 md:justify-end"><span class="paw-step" aria-hidden="true">🐾</span>PASSO 3</p>
              <h3 class="font-display text-2xl text-ink">Prenota il tuo</h3>
              <p class="mt-2 text-inksoft">Scelto il cucciolo, lo fermiamo per te e concordiamo tutto con calma, per iscritto.</p>
            </div><div aria-hidden="true"></div>
          </li>
          <li class="step-item reveal md:grid md:grid-cols-2 md:gap-10 items-center pl-14 md:pl-0">
            <div aria-hidden="true" class="hidden md:block"></div>
            <div class="part md:pl-8">
              <p class="font-monolabel text-[10px] text-accent mb-2 flex items-center gap-2"><span class="paw-step" aria-hidden="true">🐾</span>PASSO 4</p>
              <h3 class="font-display text-2xl text-ink">Aprigli la porta</h3>
              <p class="mt-2 text-inksoft">Consegna a mano in tutta Italia, mai prima dei 60 giorni di vita, con tutti i documenti al seguito.</p>
            </div>
          </li>
        </ol>
      </div>

      <p class="reveal mt-14 text-center"><span class="part inline-block bg-panel border border-ink/10 rounded-full px-6 py-3 text-inksoft text-sm">Nessun anticipo al buio, nessun corriere anonimo: ogni passaggio lo facciamo insieme.</span></p>
    </div>
  </section>

  <!-- ============ QUIZ ============ -->
  <section id="quale-toy" class="bg-canvas pb-20 md:pb-28">
    <div class="mx-auto max-w-3xl px-6">
      <div class="bg-deep text-cream rounded-3xl p-7 md:p-12 relative overflow-hidden alive">
        <div class="reveal relative">
          <p class="part font-monolabel text-xs text-accent mb-3">NON SAI QUALE SCEGLIERE?</p>
          <h2 class="part font-display text-3xl md:text-4xl leading-tight">Tre domande e ti diciamo chi fa per te.</h2>

          <div class="part mt-8" id="quiz" data-quiz>
            <div class="quiz-step active" data-step="1">
              <p class="font-semibold text-lg mb-4">Con chi vivrà?</p>
              <div class="grid gap-3 sm:grid-cols-3">
                <button type="button" class="quiz-opt border border-white/15 rounded-2xl px-4 py-4 text-left" data-q="1" data-scores="barboncino:1,maltipoo:2,bassotto:1">Bambini e famiglia</button>
                <button type="button" class="quiz-opt border border-white/15 rounded-2xl px-4 py-4 text-left" data-q="1" data-scores="maltese:1,maltipoo:1,bassotto:1">In coppia</button>
                <button type="button" class="quiz-opt border border-white/15 rounded-2xl px-4 py-4 text-left" data-q="1" data-scores="maltese:2,barboncino:1">Con me, è la mia ombra</button>
              </div>
            </div>
            <div class="quiz-step" data-step="2">
              <p class="font-semibold text-lg mb-4">Quanto conta il pelo in giro per casa?</p>
              <div class="grid gap-3 sm:grid-cols-2">
                <button type="button" class="quiz-opt border border-white/15 rounded-2xl px-4 py-4 text-left" data-q="2" data-scores="barboncino:2,maltipoo:1">Zero pelo, per favore</button>
                <button type="button" class="quiz-opt border border-white/15 rounded-2xl px-4 py-4 text-left" data-q="2" data-scores="bassotto:2,maltese:1">Non mi preoccupa</button>
              </div>
            </div>
            <div class="quiz-step" data-step="3">
              <p class="font-semibold text-lg mb-4">Che carattere cerchi?</p>
              <div class="grid gap-3 sm:grid-cols-3">
                <button type="button" class="quiz-opt border border-white/15 rounded-2xl px-4 py-4 text-left" data-q="3" data-scores="barboncino:2,bassotto:1">Vivace e giocherellone</button>
                <button type="button" class="quiz-opt border border-white/15 rounded-2xl px-4 py-4 text-left" data-q="3" data-scores="maltese:2,maltipoo:2">Dolce e tranquillo</button>
                <button type="button" class="quiz-opt border border-white/15 rounded-2xl px-4 py-4 text-left" data-q="3" data-scores="bassotto:2">Piccolo peperino</button>
              </div>
            </div>
            <div class="quiz-step" data-step="result">
              <div class="flex flex-col sm:flex-row gap-6 items-center">
                <img id="quiz-img" src="assets/img/barboncino-toy.webp" alt="" width="160" height="160" loading="lazy" decoding="async" class="w-36 h-36 rounded-2xl object-cover border border-white/15">
                <div>
                  <p class="font-monolabel text-[10px] text-accent mb-1">IL TUO MATCH</p>
                  <p class="font-display text-3xl" id="quiz-name">Barboncino Toy</p>
                  <p class="text-creamsoft mt-1" id="quiz-line">Intelligente da vendere, non perde pelo, ama imparare.</p>
                  <p class="mt-3 font-semibold">È lui. Vuoi vederlo dal vivo in video?</p>
                  <a id="quiz-cta" href="#" target="_blank" rel="noopener" class="btn btn-wa rounded-full px-5 py-2.5 mt-4 inline-flex text-sm font-semibold">Sì, mostratemelo su WhatsApp</a>
                  <button type="button" id="quiz-restart" class="block mt-3 text-sm text-creamsoft underline underline-offset-4">Rifai il test</button>
                </div>
              </div>
            </div>
            <div class="mt-6 flex gap-1.5" aria-hidden="true">
              <span class="quiz-dot w-8 h-1 rounded-full bg-white/20" data-dot="1"></span>
              <span class="quiz-dot w-8 h-1 rounded-full bg-white/20" data-dot="2"></span>
              <span class="quiz-dot w-8 h-1 rounded-full bg-white/20" data-dot="3"></span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ============ FAQ ============ -->
  <section id="domande" class="bg-canvas pb-20 md:pb-28">
    <div class="mx-auto max-w-6xl px-6 grid lg:grid-cols-[1fr_1.6fr] gap-10">
      <div class="reveal lg:sticky lg:top-28 self-start">
        <p class="part font-monolabel text-xs text-accent mb-4">DOMANDE FREQUENTI</p>
        <h2 class="part font-display text-3xl md:text-4xl leading-tight text-ink">Le domande che ci fanno tutti. Giustamente.</h2>
        <p class="part mt-4 text-inksoft">Se la tua non c'è, falla su WhatsApp: rispondiamo in giornata.</p>
      </div>
      <div class="reveal">
        <details class="part faq-item py-5" >
          <summary class="faq-q flex items-start justify-between gap-4 font-semibold text-ink text-lg"><span>Come faccio a fidarmi? Il web è pieno di truffe sui cuccioli.</span><span class="faq-chev text-accent shrink-0 mt-1" aria-hidden="true">+</span></summary>
          <p class="mt-3 text-inksoft">Hai ragione a essere prudente. Per questo non ti chiediamo mai di fidarti al buio: ti mostriamo i cuccioli in foto e video dal vivo prima di qualsiasi decisione, rispondiamo al telefono con nome e cognome e ogni consegna viaggia con documenti e accordi scritti. Siamo un'attività registrata, con partita IVA visibile qui sotto, e in 20 anni abbiamo accompagnato centinaia di famiglie. Fai tutte le domande che vuoi: è il segno che sarai un ottimo proprietario.</p>
        </details>
        <details class="part faq-item py-5">
          <summary class="faq-q flex items-start justify-between gap-4 font-semibold text-ink text-lg"><span>Il cucciolo resterà davvero piccolo?</span><span class="faq-chev text-accent shrink-0 mt-1" aria-hidden="true">+</span></summary>
          <p class="mt-3 text-inksoft">La taglia da adulto la scrive la genetica, non le promesse. Per questo selezioniamo linee toy seguite nel tempo e ti diciamo sempre peso e taglia dei genitori, con una stima onesta di come crescerà. Un cucciolo va amato anche per il mezzo chilo in più che potrà avere da adulto.</p>
        </details>
        <details class="part faq-item py-5">
          <summary class="faq-q flex items-start justify-between gap-4 font-semibold text-ink text-lg"><span>Che documenti ricevo con il cucciolo?</span><span class="faq-chev text-accent shrink-0 mt-1" aria-hidden="true">+</span></summary>
          <p class="mt-3 text-inksoft">Microchip registrato, libretto sanitario compilato dal veterinario con vaccinazioni e sverminazioni, certificato di tipo (Barboncino Toy, Maltipoo, Maltese Toy o Bassotto Kaninchen) in prima origine (si specifica che la richiesta deve essere fatta dal cliente e che eventuali costi saranno a carico del cliente) e accordo di vendita scritto. Tutto ti viene consegnato insieme a lui. <strong>Si dichiara esplicitamente che non viene rilasciato pedigree ENCI.</strong></p>
        </details>
        <details class="part faq-item py-5">
          <summary class="faq-q flex items-start justify-between gap-4 font-semibold text-ink text-lg"><span>A che età consegnate i cuccioli?</span><span class="faq-chev text-accent shrink-0 mt-1" aria-hidden="true">+</span></summary>
          <p class="mt-3 text-inksoft">Mai prima dei 60 giorni di vita, come prevede la legge italiana: è il tempo che serve a un cucciolo per crescere sereno accanto alla mamma. Alla consegna ha già il primo ciclo vaccinale.</p>
        </details>
        <details class="part faq-item py-5">
          <summary class="faq-q flex items-start justify-between gap-4 font-semibold text-ink text-lg"><span>Quanto costa un cucciolo toy?</span><span class="faq-chev text-accent shrink-0 mt-1" aria-hidden="true">+</span></summary>
          <p class="mt-3 text-inksoft">Dipende dal tipo di cucciolo (Barboncino Toy, Maltipoo, Maltese Toy o Bassotto Kaninchen), dal colore e dalla linea di sangue. Scrivici su WhatsApp: ti diciamo subito disponibilità e prezzo, chiaro e completo di tutto quello che include. Si prenota con una caparra e il saldo si può pagare anche a rate con finanziamento, previa approvazione. Niente costi a sorpresa dopo.</p>
        </details>
        <details class="part faq-item py-5">
          <summary class="faq-q flex items-start justify-between gap-4 font-semibold text-ink text-lg"><span>Consegnate davvero in tutta Italia?</span><span class="faq-chev text-accent shrink-0 mt-1" aria-hidden="true">+</span></summary>
          <p class="mt-3 text-inksoft">Sì, e lo facciamo a mano: il cucciolo viaggia accompagnato, non in un pacco. Concordiamo insieme giorno, modalità e rimborso delle spese di viaggio, sempre prima della prenotazione.</p>
        </details>
        <details class="part faq-item py-5">
          <summary class="faq-q flex items-start justify-between gap-4 font-semibold text-ink text-lg"><span>Barboncino e Maltipoo perdono pelo?</span><span class="faq-chev text-accent shrink-0 mt-1" aria-hidden="true">+</span></summary>
          <p class="mt-3 text-inksoft">Pochissimo: sono tra i tipi di cane con la minor dispersione di pelo, per questo sono amatissime in appartamento. Nessun cane è ipoallergenico al cento per cento, ma qui siamo molto vicini.</p>
        </details>
        <details class="part faq-item py-5">
          <summary class="faq-q flex items-start justify-between gap-4 font-semibold text-ink text-lg"><span>La taglia toy è riconosciuta ufficialmente?</span><span class="faq-chev text-accent shrink-0 mt-1" aria-hidden="true">+</span></summary>
          <p class="mt-3 text-inksoft">Dipende dal tipo: il Barboncino Toy è una varietà riconosciuta, mentre per il Maltese la parola toy indica la selezione di taglia più piccola, non una categoria ufficiale. Te lo diciamo chiaramente perché è così che vogliamo lavorare: parole oneste, cuccioli veri.</p>
        </details>
      </div>
    </div>
  </section>

  <!-- ============ CONTATTI ============ -->
  <section id="contatti" class="bg-deep text-cream py-20 md:py-28 relative overflow-hidden alive">
    <div class="mx-auto max-w-6xl px-6 grid lg:grid-cols-[1.1fr_1fr] gap-12">
      <div class="reveal">
        <p class="part font-monolabel text-xs text-accent mb-4">PRENOTA IL TUO CUCCIOLO</p>
        <h2 class="part font-display text-3xl md:text-5xl leading-tight">Raccontaci chi stai cercando.</h2>
        <p class="part mt-5 text-lg text-creamsoft max-w-lg">Rispondiamo in giornata con disponibilità, foto e video reali. Se preferisci fare prima, WhatsApp è la via più rapida.</p>

        <div class="part mt-9 grid gap-4 max-w-md">
          <a href="https://wa.me/393770876017?text=Ciao!%20Ho%20visto%20il%20sito%20e%20vorrei%20informazioni%20sui%20cuccioli%20disponibili." target="_blank" rel="noopener" class="btn btn-wa rounded-2xl px-6 py-4 font-semibold flex items-center justify-between">
            <span>Scrivici su WhatsApp</span><span aria-hidden="true">→</span>
          </a>
          <a href="tel:+393770876017" class="btn btn-ghost rounded-2xl px-6 py-4 font-semibold flex items-center justify-between">
            <span>Chiamaci: +39 377 087 6017</span><span aria-hidden="true">→</span>
          </a>
          <a href="mailto:houseofdogdigiuseppe@outlook.it" class="btn btn-ghost rounded-2xl px-6 py-4 font-semibold flex items-center justify-between overflow-hidden">
            <span class="truncate">houseofdogdigiuseppe@outlook.it</span><span aria-hidden="true" class="shrink-0 ml-2">→</span>
          </a>
          <a href="https://www.instagram.com/house_of_dog_official/" target="_blank" rel="noopener" class="btn btn-social rounded-2xl px-6 py-4 font-semibold flex items-center justify-between gap-3">
            <span class="flex items-center gap-3">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg>
              <span>Seguici su Instagram</span>
            </span><span aria-hidden="true">→</span>
          </a>
          <a href="https://www.facebook.com/mysweetspuppies" target="_blank" rel="noopener" class="btn btn-social rounded-2xl px-6 py-4 font-semibold flex items-center justify-between gap-3">
            <span class="flex items-center gap-3">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.5 21v-7.2h2.4l.4-2.9h-2.8V9.1c0-.8.3-1.4 1.4-1.4h1.5V5.1c-.3 0-1.2-.1-2.2-.1-2.2 0-3.7 1.3-3.7 3.8v2.1H8v2.9h2.5V21h3Z"/></svg>
              <span>Seguici su Facebook</span>
            </span><span aria-hidden="true">→</span>
          </a>
        </div>
      </div>

      <div class="reveal">
        <form id="prenota-form" class="part bg-deeppanel border border-white/10 rounded-3xl p-6 md:p-8 grid gap-4" method="post" action="invia.php" novalidate>
          <?php if ($formInviato): ?>
          <p class="rounded-2xl bg-accent/20 border border-accent/40 px-4 py-3 text-sm">Richiesta arrivata! Ti ricontattiamo entro poche ore con foto e disponibilità.</p>
          <?php elseif ($formErrore): ?>
          <p class="rounded-2xl bg-red-500/15 border border-red-400/40 px-4 py-3 text-sm">Qualcosa non torna nei dati inseriti: ricontrolla e riprova, oppure scrivici su WhatsApp.</p>
          <?php endif; ?>

          <div class="hp-field" aria-hidden="true">
            <label>Non compilare questo campo<input type="text" name="sito_web" tabindex="-1" autocomplete="off"></label>
          </div>
          <div>
            <label for="f-nome" class="font-monolabel text-[10px] text-creamsoft block mb-1.5">NOME *</label>
            <input id="f-nome" name="nome" type="text" required autocomplete="name" class="field w-full rounded-xl px-4 py-3 text-ink" placeholder="Il tuo nome">
          </div>
          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label for="f-tel" class="font-monolabel text-[10px] text-creamsoft block mb-1.5">TELEFONO</label>
              <input id="f-tel" name="telefono" type="tel" autocomplete="tel" class="field w-full rounded-xl px-4 py-3 text-ink" placeholder="Per richiamarti prima">
            </div>
            <div>
              <label for="f-email" class="font-monolabel text-[10px] text-creamsoft block mb-1.5">EMAIL</label>
              <input id="f-email" name="email" type="email" autocomplete="email" class="field w-full rounded-xl px-4 py-3 text-ink" placeholder="La tua email">
            </div>
          </div>
          <div>
            <label for="f-razza" class="font-monolabel text-[10px] text-creamsoft block mb-1.5">TIPO DI CUCCIOLO</label>
            <select id="f-razza" name="razza" class="field w-full rounded-xl px-4 py-3 text-ink">
              <option>Non so ancora</option>
              <option>Barboncino Toy</option>
              <option>Maltipoo Toy</option>
              <option>Maltese Toy</option>
              <option>Bassotto Kaninchen</option>
            </select>
          </div>
          <div>
            <label for="f-msg" class="font-monolabel text-[10px] text-creamsoft block mb-1.5">MESSAGGIO</label>
            <textarea id="f-msg" name="messaggio" rows="4" class="field w-full rounded-xl px-4 py-3 text-ink" placeholder="Es. Cerco una femmina di Barboncino Toy albicocca, siamo una famiglia con due bambini..."></textarea>
          </div>
          <label class="flex items-start gap-3 text-sm text-creamsoft">
            <input type="checkbox" name="privacy" required class="mt-1 accent-[#8ccdfb]">
            <span>Ho letto l'<a href="privacy.php" class="underline underline-offset-2 hover:text-cream">informativa privacy</a> e acconsento al trattamento dei dati per essere ricontattato.</span>
          </label>
          <button type="submit" class="btn btn-primary rounded-full px-8 py-3.5 font-semibold justify-self-start">Invia la richiesta</button>
          <p id="form-feedback" class="text-sm hidden" role="status" aria-live="polite"></p>
        </form>
      </div>
    </div>
  </section>
</main>

<!-- ============ FOOTER ============ -->
<footer class="bg-deep text-creamsoft border-t border-white/10">
  <div class="mx-auto max-w-6xl px-6 py-14 grid gap-10 md:grid-cols-[1.4fr_1fr_1fr]">
    <div>
      <div class="flex items-center gap-4 text-cream">
        <span class="inline-flex items-center justify-center bg-white rounded-2xl p-2 shrink-0">
          <img src="assets/img/logo.png" alt="Logo House of Dogs" width="96" height="96" loading="lazy" decoding="async" class="w-24 h-24">
        </span>
        <span class="font-display text-lg">House of Dogs</span>
      </div>
      <p class="mt-4 max-w-sm text-sm">Da 20 anni, cuccioli toy selezionati con passione, dalla nostra famiglia alla tua.</p>
      <p class="mt-6 text-xs opacity-70">I visual di apertura sono generati digitalmente. I cuccioli nelle foto dei tipi sono reali.</p>
    </div>
    <nav aria-label="Tipi di cucciolo" class="text-sm">
      <p class="font-monolabel text-[10px] text-accent mb-4">TIPI DI CUCCIOLO</p>
      <ul class="grid gap-2.5">
        <li><a class="hover:text-cream transition-colors" href="#barboncino-toy">Barboncino Toy</a></li>
        <li><a class="hover:text-cream transition-colors" href="#maltipoo-toy">Maltipoo Toy</a></li>
        <li><a class="hover:text-cream transition-colors" href="#maltese-toy">Maltese Toy</a></li>
        <li><a class="hover:text-cream transition-colors" href="#bassotto-kaninchen">Bassotto Kaninchen</a></li>
      </ul>
    </nav>
    <div class="text-sm">
      <p class="font-monolabel text-[10px] text-accent mb-4">CONTATTI E LEGALE</p>
      <ul class="grid gap-2.5">
        <li><a class="hover:text-cream transition-colors" href="tel:+393770876017">+39 377 087 6017</a></li>
        <li><a class="hover:text-cream transition-colors break-all" href="mailto:houseofdogdigiuseppe@outlook.it">houseofdogdigiuseppe@outlook.it</a></li>
        <li><a class="hover:text-cream transition-colors" href="privacy.php">Privacy Policy</a></li>
        <li><a class="hover:text-cream transition-colors" href="termini.php">Condizioni di vendita</a></li>
      </ul>
    </div>
  </div>
  <div class="border-t border-white/10">
    <p class="mx-auto max-w-6xl px-6 py-5 text-xs opacity-70">© 2026 The House Of Dog di G.F. Tutti i diritti riservati. · P.IVA 03933680401 · PEC: casadipluto@pec.buffetti.it</p>
  </div>
</footer>

<!-- WhatsApp flottante -->
<a id="wa-float" href="https://wa.me/393770876017?text=Ciao!%20Ho%20visto%20il%20sito%20e%20vorrei%20informazioni%20sui%20cuccioli%20disponibili." target="_blank" rel="noopener" aria-label="Scrivici su WhatsApp" class="fixed bottom-5 right-5 z-40 btn btn-wa rounded-full w-14 h-14 items-center justify-center shadow-xl hidden">
  <svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm5.4 14.1c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.3-.7-2.8-1.1-4.6-4-4.7-4.2-.1-.2-1.1-1.5-1.1-2.9s.7-2 1-2.3c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.9 2.1c.1.2.1.4 0 .6l-.4.6-.4.4c-.1.1-.3.3-.1.6.2.3.8 1.3 1.7 2.1 1.2 1.1 2.2 1.4 2.5 1.5.3.1.5.1.7-.1l1-1.2c.2-.3.4-.2.7-.1l2 1c.3.1.5.2.6.3 0 .1 0 .8-.1 1.4Z"/></svg>
</a>

<script src="assets/js/hero.js" defer></script>
<script src="assets/js/site.js" defer></script>
</body>
</html>
