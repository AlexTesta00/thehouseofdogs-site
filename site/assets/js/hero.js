/* House of Dogs - scrub hero engine
   Scroll progress through the pinned hero drives the video's time.
   Static designed hero on phones, portrait tablets, landscape phones,
   coarse-pointer portrait and reduced motion. */
(function () {
  'use strict';

  var stage = document.getElementById('hero-stage');
  var heroPin = document.getElementById('hero-pin');
  var video = document.getElementById('hero-video');
  if (!stage || !heroPin || !video) return;

  var ring = document.getElementById('hero-ring');
  var posterLayer = document.getElementById('hero-poster');
  var VIDEO_URL = stage.getAttribute('data-video');
  var POSTER_URL = stage.getAttribute('data-poster');
  var VIDEO_BYTES = Number(stage.getAttribute('data-video-bytes')) || 6000000;

  /* ---------- bands ---------- */
  var bandEls = Array.prototype.slice.call(document.querySelectorAll('.band'));
  var bands = bandEls.map(function (el, i) {
    return {
      el: el,
      a: Number(el.getAttribute('data-a')),
      b: Number(el.getAttribute('data-b')),
      ramp: el.hasAttribute('data-ramp') ? Number(el.getAttribute('data-ramp')) : null,
      first: i === 0,
      last: i === bandEls.length - 1,
      op: -1,
      k: -1
    };
  });

  function smoothstep(p, e0, e1) {
    var t = Math.min(1, Math.max(0, (p - e0) / (e1 - e0)));
    return t * t * (3 - 2 * t);
  }
  function clamp(v, lo, hi) { return Math.min(hi, Math.max(lo, v)); }

  /* Band one opens settled: one-time load ramp handed over to scroll. */
  var loadK = 0, loadKStart = 0, loadKRaf = null;
  function driveLoadK(now) {
    if (!loadKStart) loadKStart = now;
    var t = clamp((now - loadKStart) / 1400, 0, 1);
    loadK = t * t * (3 - 2 * t);
    updateCaptions(shown);
    if (t < 1) loadKRaf = requestAnimationFrame(driveLoadK);
    else loadKRaf = null;
  }

  function updateCaptions(p) {
    for (var i = 0; i < bands.length; i++) {
      var bd = bands[i];
      var f = Math.min(0.02, (bd.b - bd.a) / 3);
      var inE = bd.first ? 1 : smoothstep(p, bd.a, bd.a + f);
      var outE = bd.last ? 1 : (1 - smoothstep(p, bd.b - f, bd.b));
      var op = inE * outE;
      var ramp = bd.ramp || Math.min(0.025, (bd.b - bd.a) * 0.35);
      var k = clamp((p - bd.a) / ramp, 0, 1);
      if (bd.first) k = Math.max(k, loadK);
      if (Math.abs(op - bd.op) > 0.008) {
        bd.op = op;
        bd.el.style.opacity = op.toFixed(3);
        var vis = op > 0.01;
        if (bd.el._vis !== vis) { bd.el._vis = vis; bd.el.style.visibility = vis ? 'visible' : 'hidden'; }
      }
      if (Math.abs(k - bd.k) > 0.008) {
        bd.k = k;
        bd.el.style.setProperty('--k', k.toFixed(3));
      }
    }
    updateHeroExtras(p);
  }

  /* Scroll-driven extras over the hero (progress paw, scroll cue). Delta-gated. */
  var cue = document.getElementById('hero-cue');
  var lastCueOn = -1;
  function updateHeroExtras(p) {
    if (cue) {
      var on = p < 0.04 ? 1 : 0;
      if (on !== lastCueOn) { lastCueOn = on; cue.style.opacity = on; }
    }
  }

  /* ---------- progress ---------- */
  function heroProgress() {
    var r = heroPin.getBoundingClientRect();
    var range = heroPin.offsetHeight - window.innerHeight;
    if (range <= 0) return 0;
    return clamp(-r.top / range, 0, 1);
  }

  /* ---------- seek gate (deadlock-safe) ---------- */
  var seekBusy = false, pendingTime = null;
  function requestSeek(t) {
    if (!video.duration || !isFinite(t)) return;
    if (seekBusy) { pendingTime = t; return; }
    seekBusy = true;
    video.currentTime = t;
  }
  video.addEventListener('seeked', function () {
    seekBusy = false;
    if (pendingTime !== null) { var t = pendingTime; pendingTime = null; requestSeek(t); }
  });
  video.addEventListener('error', function () {
    seekBusy = false; pendingTime = null;
  });

  /* ---------- lerp loop (rests when converged) ---------- */
  var target = 0, shown = 0, rafId = null, lastTick = 0;
  var heroOnScreen = true;
  function tick(now) {
    var dt = Math.min(100, now - (lastTick || now));
    lastTick = now;
    var k = 0.16;
    shown += (target - shown) * (1 - Math.pow(1 - k, dt / 16.667));
    if (Math.abs(target - shown) < 0.0005) {
      shown = target; rafId = null; lastTick = 0;
    } else {
      rafId = requestAnimationFrame(tick);
    }
    if (video.duration) requestSeek(shown * video.duration);
    updateCaptions(shown);
  }
  function onScroll() {
    target = heroProgress();
    if (rafId === null && heroOnScreen) rafId = requestAnimationFrame(tick);
  }

  new IntersectionObserver(function (entries) {
    heroOnScreen = entries[0].isIntersecting;
    if (heroOnScreen && scrubOn) onScroll();
    if (ambientMode && ambient && !ambient.ended) {
      if (heroOnScreen) { var p = ambient.play(); if (p && p.catch) p.catch(function () {}); }
      else ambient.pause();
    }
  }).observe(heroPin);

  /* ---------- blob loader with honest ring ---------- */
  var heroInited = false;
  function initHeroOnce() {
    if (heroInited) return;
    heroInited = true;
    posterLayer.style.backgroundImage = "url('" + POSTER_URL + "')";
    var started = false;
    function startBlobFetch() {
      if (started) return;
      started = true;
      loadHeroBlob().catch(failVideo);
    }
    var posterImg = new Image();
    posterImg.onload = startBlobFetch;
    posterImg.onerror = startBlobFetch;
    posterImg.src = POSTER_URL;
    setTimeout(startBlobFetch, 4000);
  }

  function loadHeroBlob() {
    var ctrl = new AbortController();
    var watchdog = setTimeout(function () { ctrl.abort(); }, 20000);
    return fetch(VIDEO_URL, { priority: 'low', signal: ctrl.signal }).then(function (res) {
      if (!res.ok || !res.body) throw new Error('fetch failed');
      var total = Number(res.headers.get('Content-Length')) || VIDEO_BYTES;
      var reader = res.body.getReader();
      var chunks = [], got = 0, lastRing = 0;
      function pump() {
        return reader.read().then(function (r) {
          if (r.done) return;
          clearTimeout(watchdog);
          watchdog = setTimeout(function () { ctrl.abort(); }, 20000);
          chunks.push(r.value);
          got += r.value.length;
          var frac = Math.min(1, got / total);
          var now = performance.now();
          if (now - lastRing > 100 || frac === 1) {
            lastRing = now;
            if (ring) ring.style.setProperty('--ld', String(Math.round(126 * (1 - frac))));
          }
          return pump();
        });
      }
      return pump().then(function () {
        clearTimeout(watchdog);
        if (ring) ring.style.setProperty('--ld', '0');
        video.src = URL.createObjectURL(new Blob(chunks, { type: 'video/mp4' }));
        video.load();
        video.addEventListener('canplay', function onCan() {
          video.removeEventListener('canplay', onCan);
          requestSeek(heroProgress() * video.duration);
          stage.classList.add('video-ready');
        });
      });
    });
  }

  function failVideo() {
    if (ring && ring.parentNode) ring.parentNode.removeChild(ring);
    stage.classList.add('video-failed');
  }
  video.addEventListener('error', failVideo);

  /* ---------- the five static-hero gates, live ---------- */
  var GATES = [
    '(max-width: 720px)',
    '(orientation: portrait) and (max-width: 1024px)',
    '(orientation: portrait) and (pointer: coarse)',
    '(orientation: landscape) and (pointer: coarse) and (max-height: 560px)',
    '(prefers-reduced-motion: reduce)'
  ];
  var scrubOn = false;

  /* Ambient mobile: il video parte muto in loop dietro l'hero statico.
     Mai con riduci-animazioni attivo, mai con il risparmio dati attivo. */
  var ambient = document.getElementById('hero-ambient');
  var staticHero = document.getElementById('hero-static');
  var ambientMode = false;
  function setAmbient(on) {
    if (!ambient || !staticHero) return;
    if (on && navigator.connection && navigator.connection.saveData) on = false;
    ambientMode = on;
    if (on) {
      if (!ambient.getAttribute('src')) {
        ambient.addEventListener('playing', function () {
          staticHero.classList.add('ambient-on');
        });
        ambient.src = 'assets/hero-ambient.mp4';
      }
      /* Corsa singola: finito il viaggio, resta sul frame finale. */
      if (ambient.ended) return;
      var p = ambient.play();
      if (p && p.catch) p.catch(function () {});
    } else {
      ambient.pause();
      staticHero.classList.remove('ambient-on');
    }
  }
  function enableScrub() {
    if (scrubOn) return; scrubOn = true;
    initHeroOnce();
    addEventListener('scroll', onScroll, { passive: true });
    bands.forEach(function (b) { b.op = -1; b.k = -1; });
    if (window.hodUnpinFinalStates) window.hodUnpinFinalStates();
    target = heroProgress();
    shown = target;
    updateCaptions(shown);
    if (loadKRaf === null && loadK < 1) loadKRaf = requestAnimationFrame(driveLoadK);
    onScroll();
  }
  function disableScrub() {
    if (!scrubOn) return; scrubOn = false;
    removeEventListener('scroll', onScroll);
    if (rafId !== null) { cancelAnimationFrame(rafId); rafId = null; }
  }
  function applyHeroMode() {
    var reduced = matchMedia(GATES[4]).matches;
    var sizeGate = GATES.slice(0, 4).some(function (q) { return matchMedia(q).matches; });
    if (reduced) { disableScrub(); setAmbient(false); }
    else if (sizeGate) { disableScrub(); setAmbient(true); }
    else { setAmbient(false); enableScrub(); }
  }
  var MQLS = GATES.map(function (q) { return matchMedia(q); });
  MQLS.forEach(function (m) {
    if (m.addEventListener) m.addEventListener('change', applyHeroMode);
    else m.addListener(applyHeroMode);
  });

  /* Reduced motion honored live, both directions. */
  var rmq = matchMedia('(prefers-reduced-motion: reduce)');
  function onRmFlip(e) {
    if (e.matches) { if (window.hodPinToFinalStates) window.hodPinToFinalStates(); }
    else applyHeroMode();
  }
  if (rmq.addEventListener) rmq.addEventListener('change', onRmFlip);
  else rmq.addListener(onRmFlip);

  applyHeroMode();
})();
