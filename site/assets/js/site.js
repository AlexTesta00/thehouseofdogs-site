/* House of Dogs - coreografie di pagina, quiz, guinzaglio, form. */
(function () {
  'use strict';

  var reduceMotion = matchMedia('(prefers-reduced-motion: reduce)');

  /* ---------- rng con seme: offset identici a ogni caricamento ---------- */
  function rng(seed) {
    var s = seed >>> 0;
    return function () { s = (s * 1664525 + 1013904223) >>> 0; return s / 4294967296; };
  }

  /* ---------- splitting del testo delle bande, una volta al load ---------- */
  var rand = rng(20240614);

  function splitWords(el, thBase) {
    var words = el.textContent.split(/\s+/).filter(Boolean);
    el.textContent = '';
    var total = words.length;
    words.forEach(function (word, i) {
      var w = document.createElement('span');
      w.className = 'w';
      w.textContent = word;
      var th = thBase + (i / Math.max(1, total)) * 0.3 + rand() * 0.04;
      w.style.setProperty('--th', Math.min(0.6, th).toFixed(3));
      el.appendChild(w);
      if (i < total - 1) el.appendChild(document.createTextNode(' '));
    });
  }

  function splitChars(el, spread) {
    var words = el.textContent.split(/\s+/).filter(Boolean);
    el.textContent = '';
    var totalChars = words.join('').length;
    var idx = 0;
    words.forEach(function (word, wi) {
      var w = document.createElement('span');
      w.className = 'w';
      for (var j = 0; j < word.length; j++) {
        var c = document.createElement('span');
        c.className = 'c';
        c.textContent = word[j];
        var th = (idx / Math.max(1, totalChars)) * spread + rand() * 0.06;
        c.style.setProperty('--th', Math.min(0.55, th).toFixed(3));
        c.style.setProperty('--jx', (18 + rand() * 34).toFixed(0) + 'px');
        c.style.setProperty('--jy', '0px');
        c.style.setProperty('--jr', '0deg');
        w.appendChild(c);
        idx++;
      }
      el.appendChild(w);
      if (wi < words.length - 1) el.appendChild(document.createTextNode(' '));
    });
  }

  function splitRise(el) {
    var words = el.textContent.split(/\s+/).filter(Boolean);
    el.textContent = '';
    var total = words.length;
    words.forEach(function (word, i) {
      var w = document.createElement('span');
      w.className = 'rise-w';
      w.textContent = word;
      w.style.setProperty('--th', ((i / Math.max(1, total)) * 0.32).toFixed(3));
      el.appendChild(w);
      if (i < total - 1) el.appendChild(document.createTextNode(' '));
    });
  }

  document.querySelectorAll('[data-split]').forEach(function (el) {
    splitWords(el, Number(el.getAttribute('data-th-base')) || 0);
  });
  document.querySelectorAll('[data-split-chars]').forEach(function (el) {
    var band = el.closest('.band');
    var spread = band && band.hasAttribute('data-spread') ? Number(band.getAttribute('data-spread')) : 0.4;
    splitChars(el, spread);
  });
  document.querySelectorAll('[data-split-rise]').forEach(splitRise);

  /* ---------- nav: fondo pieno dopo l'hero, menu mobile ---------- */
  var nav = document.getElementById('topnav');
  var waFloat = document.getElementById('wa-float');
  var heroPin = document.getElementById('hero-pin');
  var navSolid = false, floatOn = false;
  function onScrollNav() {
    var past = window.scrollY > 40;
    if (past !== navSolid) {
      navSolid = past;
      nav.classList.toggle('nav-solid', past);
    }
    if (heroPin && waFloat) {
      var pastHero = window.scrollY > heroPin.offsetHeight - window.innerHeight * 0.5;
      if (pastHero !== floatOn) {
        floatOn = pastHero;
        waFloat.classList.toggle('hidden', !pastHero);
        waFloat.classList.toggle('flex', pastHero);
      }
    }
  }
  addEventListener('scroll', onScrollNav, { passive: true });
  onScrollNav();

  var style = document.createElement('style');
  style.textContent = '#topnav.nav-solid{background:rgba(12,58,102,.92);backdrop-filter:blur(12px);box-shadow:0 8px 30px -18px rgba(4,16,30,.5)}';
  document.head.appendChild(style);

  var menuBtn = document.getElementById('menu-btn');
  var mobileMenu = document.getElementById('mobile-menu');
  if (menuBtn && mobileMenu) {
    menuBtn.addEventListener('click', function () {
      var open = mobileMenu.classList.toggle('hidden');
      menuBtn.setAttribute('aria-expanded', String(!open));
    });
    mobileMenu.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () {
        mobileMenu.classList.add('hidden');
        menuBtn.setAttribute('aria-expanded', 'false');
      });
    });
  }

  /* ---------- entrate coreografate + ritiro dei ritardi ---------- */
  var revealObs = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (e.isIntersecting) {
        e.target.classList.add('in');
        revealObs.unobserve(e.target);
        setTimeout(function () { e.target.classList.add('done'); }, 1400);
      }
    });
  }, { threshold: 0.12 });
  document.querySelectorAll('.reveal').forEach(function (el) { revealObs.observe(el); });

  /* ---------- sezioni vive solo quando visibili, pausa su tab nascosta ---------- */
  var aliveObs = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) { e.target.classList.toggle('alive-on', e.isIntersecting); });
  }, { threshold: 0.05 });
  document.querySelectorAll('.alive').forEach(function (el) { aliveObs.observe(el); });
  document.addEventListener('visibilitychange', function () {
    document.body.classList.toggle('paused', document.hidden);
  });

  /* ---------- il guinzaglio: si disegna con lo scroll ---------- */
  var leash = document.getElementById('leash-path');
  var leashSection = document.getElementById('come-funziona');
  var leashLen = 0, lastOffset = -1;
  if (leash && leashSection) {
    leashLen = leash.getTotalLength();
    leash.style.strokeDasharray = leashLen + ' ' + leashLen;
    leash.style.strokeDashoffset = leashLen;
    var drawLeash = function () {
      if (reduceMotion.matches) return;
      var r = leashSection.getBoundingClientRect();
      var vh = window.innerHeight;
      var prog = Math.min(1, Math.max(0, (vh * 0.75 - r.top) / (r.height * 0.9)));
      var off = Math.round(leashLen * (1 - prog));
      if (Math.abs(off - lastOffset) > 2) {
        lastOffset = off;
        leash.style.strokeDashoffset = off;
      }
    };
    addEventListener('scroll', drawLeash, { passive: true });
    drawLeash();
  }
  var pawObs = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (e.isIntersecting) {
        var paw = e.target.querySelector('.paw-step');
        if (paw) paw.classList.add('on');
      }
    });
  }, { threshold: 0.5 });
  document.querySelectorAll('.step-item').forEach(function (el) { pawObs.observe(el); });

  /* ---------- reduced motion live: stati finali, poi ritorno allo scroll ---------- */
  window.hodPinToFinalStates = function () {
    document.body.classList.add('rm-pinned');
    if (leash) { leash.style.strokeDashoffset = 0; }
    document.querySelectorAll('.reveal').forEach(function (el) { el.classList.add('in', 'done'); });
    document.querySelectorAll('.paw-step').forEach(function (el) { el.classList.add('on'); });
  };
  window.hodUnpinFinalStates = function () {
    document.body.classList.remove('rm-pinned');
    lastOffset = -1;
  };
  if (reduceMotion.matches) window.hodPinToFinalStates();

  /* ---------- quiz: tre tocchi, un match ---------- */
  var quiz = document.getElementById('quiz');
  if (quiz) {
    var scores = { barboncino: 0, maltipoo: 0, maltese: 0, bassotto: 0 };
    var breeds = {
      barboncino: { name: 'Barboncino Toy', line: 'Intelligente da vendere, non perde pelo, ama imparare.', img: 'assets/img/barboncino-toy.webp', wa: 'Barboncino%20Toy' },
      maltipoo: { name: 'Maltipoo Toy', line: 'Il musetto da peluche che conquista tutta la famiglia.', img: 'assets/img/maltipoo-toy.webp', wa: 'Maltipoo%20Toy' },
      maltese: { name: 'Maltese Toy', line: 'Un batuffolo bianco dal carattere nobile e tranquillo.', img: 'assets/img/maltese-toy.webp', wa: 'Maltese%20Toy' },
      bassotto: { name: 'Bassotto Kaninchen', line: 'Piccolo fuori, coraggioso dentro. Un carisma unico.', img: 'assets/img/bassotto-kaninchen.webp', wa: 'Bassotto%20Kaninchen' }
    };
    var current = 1;
    function showStep(n) {
      quiz.querySelectorAll('.quiz-step').forEach(function (s) {
        s.classList.toggle('active', s.getAttribute('data-step') === String(n));
      });
      quiz.querySelectorAll('.quiz-dot').forEach(function (d) {
        var dn = Number(d.getAttribute('data-dot'));
        d.style.background = (typeof n === 'number' && dn <= n) || n === 'result' ? 'var(--accent-on-deep)' : 'rgba(255,255,255,.2)';
      });
    }
    function finish() {
      var best = 'maltipoo', bestScore = -1;
      ['maltipoo', 'barboncino', 'maltese', 'bassotto'].forEach(function (k) {
        if (scores[k] > bestScore) { bestScore = scores[k]; best = k; }
      });
      var b = breeds[best];
      document.getElementById('quiz-img').src = b.img;
      document.getElementById('quiz-img').alt = 'Cucciolo di ' + b.name;
      document.getElementById('quiz-name').textContent = b.name;
      document.getElementById('quiz-line').textContent = b.line;
      document.getElementById('quiz-cta').href = 'https://wa.me/393770876017?text=Ciao!%20Ho%20fatto%20il%20test%20sul%20sito%20e%20il%20mio%20match%20%C3%A8%20il%20' + b.wa + '.%20Posso%20vedere%20i%20cuccioli%20disponibili%3F';
      showStep('result');
    }
    quiz.querySelectorAll('.quiz-opt').forEach(function (btn) {
      btn.addEventListener('click', function () {
        btn.parentElement.querySelectorAll('.quiz-opt').forEach(function (b) { b.classList.remove('picked'); });
        btn.classList.add('picked');
        String(btn.getAttribute('data-scores')).split(',').forEach(function (pair) {
          var kv = pair.split(':');
          if (scores[kv[0]] !== undefined) scores[kv[0]] += Number(kv[1]) || 0;
        });
        var next = Number(btn.getAttribute('data-q')) + 1;
        setTimeout(function () {
          if (next > 3) finish(); else { current = next; showStep(next); }
        }, reduceMotion.matches ? 0 : 260);
      });
    });
    var restart = document.getElementById('quiz-restart');
    if (restart) restart.addEventListener('click', function () {
      scores = { barboncino: 0, maltipoo: 0, maltese: 0, bassotto: 0 };
      quiz.querySelectorAll('.quiz-opt').forEach(function (b) { b.classList.remove('picked'); });
      current = 1;
      showStep(1);
    });
  }

  /* ---------- form: invio senza ricaricare la pagina ---------- */
  var form = document.getElementById('prenota-form');
  if (form) {
    var feedback = document.getElementById('form-feedback');
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (!form.reportValidity()) return;
      var btn = form.querySelector('button[type="submit"]');
      btn.disabled = true;
      btn.textContent = 'Invio in corso...';
      fetch(form.action, {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: new FormData(form)
      }).then(function (r) { return r.json(); }).then(function (data) {
        feedback.classList.remove('hidden');
        feedback.style.color = data.ok ? '#8fd6a5' : '#f2a0a0';
        feedback.textContent = data.msg;
        if (data.ok) {
          form.querySelectorAll('input:not([type="checkbox"]), textarea').forEach(function (f) { f.value = ''; });
          btn.textContent = 'Richiesta inviata ✓';
        } else {
          btn.disabled = false;
          btn.textContent = 'Invia la richiesta';
        }
      }).catch(function () {
        feedback.classList.remove('hidden');
        feedback.style.color = '#f2a0a0';
        feedback.textContent = 'Connessione assente: riprova, oppure scrivici direttamente su WhatsApp.';
        btn.disabled = false;
        btn.textContent = 'Invia la richiesta';
      });
    });
  }
})();
