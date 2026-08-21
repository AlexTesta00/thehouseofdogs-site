/* Collaudo automatico completo del sito (Fase 9).
   Uso: node selftest.mjs [url]
   Chrome headless via DevTools protocol. Verifica: scrub, flick test,
   leggibilita worst-frame, quiz, form, FAQ, reduced motion live. */

import { spawn } from 'node:child_process';
import { rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const url = process.argv[2] || 'http://127.0.0.1:8123/';
const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9224;
const profile = join(tmpdir(), 'hod-selftest-' + PORT);

const chrome = spawn(CHROME, [
  '--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`,
  `--user-data-dir=${profile}`, '--hide-scrollbars', '--mute-audio',
  '--autoplay-policy=no-user-gesture-required', 'about:blank'
], { stdio: 'ignore' });

const sleep = ms => new Promise(r => setTimeout(r, ms));
const results = [];
function check(name, pass, detail) { results.push({ name, pass, detail }); }

let msgId = 0; const pending = new Map(); const consoleErrs = []; let ws;
function send(method, params = {}) {
  return new Promise((resolve, reject) => {
    const id = ++msgId;
    pending.set(id, { resolve, reject });
    ws.send(JSON.stringify({ id, method, params }));
  });
}
async function evalJs(expression, awaitPromise = false) {
  const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise });
  if (r.exceptionDetails) throw new Error('eval: ' + (r.exceptionDetails.exception?.description || 'errore'));
  return r.result.value;
}

async function connect() {
  for (let i = 0; i < 40; i++) {
    try {
      const list = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
      const page = list.find(t => t.type === 'page');
      if (page) { ws = new WebSocket(page.webSocketDebuggerUrl); break; }
    } catch {}
    await sleep(250);
  }
  await new Promise(r => { ws.onopen = r; });
  ws.onmessage = ev => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) {
      const p = pending.get(m.id); pending.delete(m.id);
      if (m.error) p.reject(new Error(m.error.message)); else p.resolve(m.result);
    } else if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') {
      consoleErrs.push(m.params.args.map(a => a.value ?? a.description ?? '').join(' '));
    } else if (m.method === 'Log.entryAdded' && m.params.entry.level === 'error') {
      consoleErrs.push(m.params.entry.text + ' [' + (m.params.entry.url || '') + ']');
    }
  };
  await send('Page.enable'); await send('Runtime.enable'); await send('Log.enable');
}

async function navigate(u) {
  const loaded = new Promise(r => {
    const h = ev => { const m = JSON.parse(ev.data); if (m.method === 'Page.loadEventFired') { ws.removeEventListener('message', h); r(); } };
    ws.addEventListener('message', h); setTimeout(r, 15000);
  });
  await send('Page.navigate', { url: u });
  await loaded;
}

async function run() {
  await connect();
  await send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 800, deviceScaleFactor: 1, mobile: false });
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });
  await navigate(url);

  /* attesa video pronto (blob + canplay) */
  let videoReady = false;
  for (let i = 0; i < 40; i++) {
    videoReady = await evalJs(`document.getElementById('hero-stage').classList.contains('video-ready')`);
    if (videoReady) break;
    await sleep(500);
  }
  check('video-ready', videoReady, 'blob caricato e canplay');

  /* scrub: a meta hero il video deve essere avanzato */
  await evalJs(`(() => { const pin = document.getElementById('hero-pin'); scrollTo(0, (pin.offsetHeight - innerHeight) * 0.5); })()`);
  await sleep(1600);
  const t1 = await evalJs(`document.getElementById('hero-video').currentTime`);
  await evalJs(`(() => { const pin = document.getElementById('hero-pin'); scrollTo(0, (pin.offsetHeight - innerHeight) * 0.95); })()`);
  await sleep(1600);
  const t2 = await evalJs(`document.getElementById('hero-video').currentTime`);
  check('scrub-seeks', t1 > 0.5 && t2 > t1 + 1, `t(50%)=${t1?.toFixed(2)} t(95%)=${t2?.toFixed(2)}`);

  /* flick test: 120, 240, 360 px */
  const flickRes = await evalJs(`(async () => {
    const bands = [...document.querySelectorAll('.band')];
    const runs = [];
    for (const [step, count] of [[120, 26], [240, 14], [360, 10]]) {
      scrollTo(0, 0); await new Promise(r => setTimeout(r, 700));
      const maxOp = bands.map(() => 0);
      const consec = bands.map(() => 0); const bestConsec = bands.map(() => 0);
      for (let i = 0; i < count; i++) {
        scrollBy(0, step);
        await new Promise(r => setTimeout(r, 400));
        bands.forEach((b, n) => {
          const op = parseFloat(getComputedStyle(b).opacity);
          maxOp[n] = Math.max(maxOp[n], op);
          if (op > 0.95) { consec[n]++; bestConsec[n] = Math.max(bestConsec[n], consec[n]); }
          else consec[n] = 0;
        });
      }
      runs.push({ step, maxOp: maxOp.map(v => +v.toFixed(2)), bestConsec });
    }
    return runs;
  })()`, true);
  const run120 = flickRes.find(r => r.step === 120);
  const run360 = flickRes.find(r => r.step === 360);
  const readable = run120.bestConsec.every((c, i) => i === run120.bestConsec.length - 1 ? c >= 3 : c >= 5);
  const noSkip = run360.maxOp.every(op => op > 0.6);
  check('flick-120-leggibile', readable, JSON.stringify(run120.bestConsec));
  check('flick-360-nessun-salto', noSkip, JSON.stringify(run360.maxOp));

  /* leggibilita worst-frame: campiona il frame video sotto il testo con lo scrim */
  const legib = await evalJs(`(async () => {
    const video = document.getElementById('hero-video');
    const pin = document.getElementById('hero-pin');
    const bands = [...document.querySelectorAll('.band')];
    const lum = (r, g, b) => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
    const textLum = lum(244, 236, 225);
    const out = [];
    for (let n = 0; n < bands.length; n++) {
      const bd = bands[n];
      const a = +bd.dataset.a, bb = +bd.dataset.b;
      const sa = parseFloat((bd.getAttribute('style') || '').match(/--sa:(\\.\\d+|\\d*\\.?\\d+)/)?.[1] || '0.62');
      let worstRatio = 99;
      for (const frac of [0.15, 0.5, 0.85]) {
        const p = a + (bb - a) * frac;
        scrollTo(0, (pin.offsetHeight - innerHeight) * p);
        await new Promise(r => setTimeout(r, 900));
        const inner = bd.querySelector('.band-inner');
        const tb = inner.getBoundingClientRect();
        const c = document.createElement('canvas');
        c.width = video.videoWidth; c.height = video.videoHeight;
        const ctx = c.getContext('2d');
        ctx.drawImage(video, 0, 0);
        const sx = video.videoWidth / innerWidth, sy = video.videoHeight / innerHeight;
        const zx = Math.max(0, tb.left * sx), zy = Math.max(0, tb.top * sy);
        const zw = Math.min(c.width - zx, tb.width * sx), zh = Math.min(c.height - zy, tb.height * sy);
        if (zw <= 0 || zh <= 0) continue;
        const d = ctx.getImageData(zx, zy, Math.floor(zw), Math.floor(zh)).data;
        const alpha = Math.min(0.95, sa * 0.8 + 0.20);
        let worst = 0;
        for (let i = 0; i < d.length; i += 16) {
          const px = [0, 1, 2].map(j => d[i + j] * (1 - alpha) + [14, 8, 4][j] * alpha);
          worst = Math.max(worst, lum(px[0], px[1], px[2]));
        }
        const hi = Math.max(textLum, worst), lo = Math.min(textLum, worst);
        worstRatio = Math.min(worstRatio, (hi + 0.05) / (lo + 0.05));
      }
      out.push({ band: n + 1, ratio: +worstRatio.toFixed(2) });
    }
    return out;
  })()`, true);
  const legOk = legib.every(l => l.ratio >= 3.5);
  check('leggibilita-3.5', legOk, JSON.stringify(legib));

  /* quiz: 3 click e risultato con CTA WhatsApp */
  const quizOk = await evalJs(`(async () => {
    scrollTo(0, document.getElementById('quale-toy').offsetTop);
    await new Promise(r => setTimeout(r, 600));
    const clickStep = async (step) => {
      const btn = document.querySelector('.quiz-step[data-step="' + step + '"] .quiz-opt');
      btn.click();
      await new Promise(r => setTimeout(r, 500));
    };
    await clickStep(1); await clickStep(2); await clickStep(3);
    const res = document.querySelector('.quiz-step[data-step="result"]');
    const cta = document.getElementById('quiz-cta');
    return res.classList.contains('active') && cta.href.includes('wa.me');
  })()`, true);
  check('quiz-funziona', quizOk === true, 'tre click, risultato con wa.me');

  /* form: POST reale a invia.php */
  const formRes = await evalJs(`(async () => {
    const fd = new FormData();
    fd.set('nome', 'Test Collaudo'); fd.set('telefono', '3330000000');
    fd.set('email', 'test@example.com'); fd.set('razza', 'Barboncino Toy');
    fd.set('messaggio', 'Messaggio di collaudo automatico.'); fd.set('privacy', 'on');
    const r = await fetch('invia.php', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd });
    return await r.json();
  })()`, true);
  check('form-post', formRes && formRes.ok === true, JSON.stringify(formRes));

  /* FAQ toggle */
  const faqOk = await evalJs(`(() => { const d = document.querySelector('.faq-item'); d.open = true; return d.open; })()`);
  check('faq-apre', faqOk === true, '');

  /* overflow orizzontale dopo click ancora */
  const ovOk = await evalJs(`(async () => {
    document.querySelector('a[href="#cuccioli"]').click();
    await new Promise(r => setTimeout(r, 800));
    return document.documentElement.scrollWidth <= innerWidth;
  })()`, true);
  check('nessun-overflow-ancore', ovOk === true, '');

  /* reduced motion LIVE: flip on -> hero statico; flip off -> scrub riparte */
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
  await sleep(900);
  const rmOn = await evalJs(`getComputedStyle(document.getElementById('hero-static')).display`);
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });
  await sleep(900);
  const rmOff = await evalJs(`(async () => {
    const pin = document.getElementById('hero-pin');
    scrollTo(0, (pin.offsetHeight - innerHeight) * 0.4);
    await new Promise(r => setTimeout(r, 1400));
    return { display: getComputedStyle(document.getElementById('hero-static')).display, t: document.getElementById('hero-video').currentTime };
  })()`, true);
  check('reduced-motion-live', rmOn === 'flex' && rmOff.display === 'none' && rmOff.t > 0.5, `on=${rmOn} off=${rmOff.display} t=${rmOff.t?.toFixed(2)}`);

  /* mobile: il video ambient parte da solo, muto, in loop */
  await send('Emulation.setDeviceMetricsOverride', { width: 375, height: 812, deviceScaleFactor: 2, mobile: true });
  await send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 });
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });
  await navigate(url);
  await sleep(3000);
  const amb = await evalJs(`JSON.stringify((() => { const v = document.getElementById('hero-ambient'); return {
    staticHero: getComputedStyle(document.getElementById('hero-static')).display,
    src: (v.getAttribute('src') || '').includes('hero-ambient'),
    t: +v.currentTime.toFixed(2), paused: v.paused,
    on: document.getElementById('hero-static').classList.contains('ambient-on')
  }; })())`);
  const ambJ = JSON.parse(amb);
  check('mobile-ambient-parte', ambJ.staticHero === 'flex' && ambJ.src && ambJ.t > 0.3 && !ambJ.paused && ambJ.on, amb);

  /* a fine corsa il video si ferma sul frame finale e non riparte.
     Avanti-veloce con playbackRate: il server dev non supporta i Range,
     quindi un seek qui verrebbe riportato a 0 e falserebbe il test. */
  const ambEnd = await evalJs(`(async () => {
    const v = document.getElementById('hero-ambient');
    v.playbackRate = 8;
    for (let i = 0; i < 30 && !v.ended; i++) await new Promise(r => setTimeout(r, 200));
    v.playbackRate = 1;
    const afterEnd = { ended: v.ended, t: +v.currentTime.toFixed(2), on: document.getElementById('hero-static').classList.contains('ambient-on') };
    scrollTo(0, document.body.scrollHeight); await new Promise(r => setTimeout(r, 600));
    scrollTo(0, 0); await new Promise(r => setTimeout(r, 900));
    return JSON.stringify({ afterEnd, ancoraFermo: v.ended });
  })()`, true);
  const ambEndJ = JSON.parse(ambEnd);
  check('mobile-ambient-si-ferma', ambEndJ.afterEnd.ended && ambEndJ.afterEnd.on && ambEndJ.ancoraFermo, ambEnd);

  /* mobile + riduci animazioni: nessun video, statico puro */
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
  await navigate(url);
  await sleep(2200);
  const ambRm = await evalJs(`JSON.stringify((() => { const v = document.getElementById('hero-ambient'); return {
    display: getComputedStyle(v).display, src: v.getAttribute('src') || '', paused: v.paused
  }; })())`);
  const ambRmJ = JSON.parse(ambRm);
  check('mobile-rm-statico', ambRmJ.display === 'none' && ambRmJ.src === '' , ambRm);

  /* ripristino desktop per i test successivi */
  await send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 800, deviceScaleFactor: 1, mobile: false });
  await send('Emulation.setTouchEmulationEnabled', { enabled: false, maxTouchPoints: 5 });
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });

  /* pagina completa senza video */
  await send('Network.enable');
  await send('Network.setBlockedURLs', { urls: ['*hero-scrub.mp4'] });
  await navigate(url);
  await sleep(3500);
  const noVideo = await evalJs(`JSON.stringify({
    failed: document.getElementById('hero-stage').classList.contains('video-failed'),
    poster: document.getElementById('hero-poster').style.backgroundImage.includes('hero-poster'),
    h1: !!document.querySelector('h1')
  })`);
  const nv = JSON.parse(noVideo);
  check('completa-senza-video', nv.failed && nv.poster && nv.h1, noVideo);
  await send('Network.setBlockedURLs', { urls: [] });

  const errsClean = consoleErrs.filter(e => !e.includes('hero-scrub.mp4'));
  check('console-pulita', errsClean.length === 0, errsClean.slice(0, 5).join(' | ') || 'zero errori');

  console.log(JSON.stringify({ results, consoleErrs: errsClean.slice(0, 10) }, null, 2));
  const failed = results.filter(r => !r.pass);
  process.exitCode = failed.length ? 1 : 0;
}

run().catch(e => { console.error('SELFTEST FAIL:', e.message); console.log(JSON.stringify({ results }, null, 2)); process.exitCode = 1; })
  .finally(() => { chrome.kill(); setTimeout(() => { try { rmSync(profile, { recursive: true, force: true }); } catch {} }, 500); });
