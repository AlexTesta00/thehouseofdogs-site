/* Harness di audit: guida Chrome headless via DevTools protocol.
   Uso: node audit.mjs <url> <outdir> [--rm] [--mobile] [--block=pattern]
   Screenshot a piu viewport + errori console + overflow orizzontale. */

import { execSync, spawn } from 'node:child_process';
import { mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const url = process.argv[2] || 'http://127.0.0.1:8123/';
const outDir = process.argv[3] || 'audit-out';
const flags = process.argv.slice(4);
const reducedMotion = flags.includes('--rm');
const mobileOnly = flags.includes('--mobile');
const blockArg = flags.find(f => f.startsWith('--block='));
const blockPattern = blockArg ? blockArg.slice(8) : null;

mkdirSync(outDir, { recursive: true });

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9223;
const profile = join(tmpdir(), 'hod-audit-profile-' + PORT);

const chrome = spawn(CHROME, [
  '--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`,
  `--user-data-dir=${profile}`, '--hide-scrollbars', '--mute-audio', 'about:blank'
], { stdio: 'ignore' });

const sleep = ms => new Promise(r => setTimeout(r, ms));

async function getTarget() {
  for (let i = 0; i < 40; i++) {
    try {
      const res = await fetch(`http://127.0.0.1:${PORT}/json/list`);
      const list = await res.json();
      const page = list.find(t => t.type === 'page');
      if (page) return page;
    } catch {}
    await sleep(250);
  }
  throw new Error('Chrome non raggiungibile');
}

let msgId = 0;
const pending = new Map();
const consoleMsgs = [];
let ws;

function send(method, params = {}, sessionId) {
  return new Promise((resolve, reject) => {
    const id = ++msgId;
    pending.set(id, { resolve, reject });
    ws.send(JSON.stringify(sessionId ? { id, method, params, sessionId } : { id, method, params }));
  });
}

async function run() {
  const target = await getTarget();
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise(r => { ws.onopen = r; });
  ws.onmessage = ev => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) {
      const p = pending.get(m.id);
      pending.delete(m.id);
      if (m.error) p.reject(new Error(m.error.message)); else p.resolve(m.result);
    } else if (m.method === 'Runtime.consoleAPICalled' && ['error', 'warning'].includes(m.params.type)) {
      consoleMsgs.push(m.params.type + ': ' + m.params.args.map(a => a.value ?? a.description ?? '').join(' '));
    } else if (m.method === 'Log.entryAdded' && ['error', 'warning'].includes(m.params.entry.level)) {
      consoleMsgs.push(m.params.entry.level + ': ' + m.params.entry.text + ' [' + (m.params.entry.url || '') + ']');
    }
  };

  await send('Page.enable');
  await send('Runtime.enable');
  await send('Log.enable');
  if (blockPattern) { await send('Network.enable'); await send('Network.setBlockedURLs', { urls: [blockPattern] }); }
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: reducedMotion ? 'reduce' : 'no-preference' }] });

  const desktopViews = [
    { name: 'desktop-1280x800', w: 1280, h: 800, mobile: false },
    { name: 'desktop-1440x900', w: 1440, h: 900, mobile: false },
  ];
  const mobileViews = [
    { name: 'mobile-375x812', w: 375, h: 812, mobile: true },
    { name: 'mobile-375x667', w: 375, h: 667, mobile: true },
  ];
  const views = mobileOnly ? mobileViews : desktopViews.concat(mobileViews);

  const report = { url, reducedMotion, blocked: blockPattern, views: {}, console: consoleMsgs };

  for (const v of views) {
    await send('Emulation.setDeviceMetricsOverride', { width: v.w, height: v.h, deviceScaleFactor: 2, mobile: v.mobile });
    if (v.mobile) await send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 });
    else await send('Emulation.setTouchEmulationEnabled', { enabled: false, maxTouchPoints: 5 });

    const loaded = new Promise(r => {
      const h = ev => {
        const m = JSON.parse(ev.data);
        if (m.method === 'Page.loadEventFired') { ws.removeEventListener('message', h); r(); }
      };
      ws.addEventListener('message', h);
      setTimeout(r, 12000);
    });
    await send('Page.navigate', { url });
    await loaded;
    await sleep(1800);

    const probe = await send('Runtime.evaluate', { expression: `JSON.stringify({
      docW: document.documentElement.scrollWidth, vw: innerWidth,
      overflowX: document.documentElement.scrollWidth > innerWidth,
      staticHero: getComputedStyle(document.getElementById('hero-static')).display,
      stage: document.getElementById('hero-stage').className,
      title: document.title
    })`, returnByValue: true });
    report.views[v.name] = JSON.parse(probe.result.value);

    const positions = v.mobile ? [0, 0.35, 0.75, 1] : [0, 0.12, 0.28, 0.45, 0.62, 0.8, 0.92, 1];
    for (let i = 0; i < positions.length; i++) {
      const p = positions[i];
      await send('Runtime.evaluate', { expression: `scrollTo(0, ${p} * (document.documentElement.scrollHeight - innerHeight))` });
      await sleep(900);
      const shot = await send('Page.captureScreenshot', { format: 'jpeg', quality: 82 });
      writeFileSync(join(outDir, `${v.name}-p${String(Math.round(p * 100)).padStart(3, '0')}.jpg`), Buffer.from(shot.data, 'base64'));
    }
  }

  writeFileSync(join(outDir, 'report.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
}

run().catch(e => { console.error('AUDIT FAIL:', e.message); process.exitCode = 1; })
  .finally(() => { chrome.kill(); setTimeout(() => { try { rmSync(profile, { recursive: true, force: true }); } catch {} }, 500); });
