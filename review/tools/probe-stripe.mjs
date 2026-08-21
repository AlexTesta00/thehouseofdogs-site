/* Verifica i bottoni Prenota: href giusti, dropdown bassotto apribile, screenshot. */
import { spawn } from 'node:child_process';
import { writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const url = process.argv[2] || 'http://127.0.0.1:8123/';
const shot = process.argv[3] || 'C:/Users/alext/Desktop/thehouseofdogs/review/audit/stripe-check.jpg';
const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9228;
const profile = join(tmpdir(), 'hod-stripe-' + PORT);
const chrome = spawn(CHROME, ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`, '--hide-scrollbars', 'about:blank'], { stdio: 'ignore' });
const sleep = ms => new Promise(r => setTimeout(r, ms));
let msgId = 0; const pending = new Map(); let ws;
function send(method, params = {}) {
  return new Promise((resolve, reject) => {
    const id = ++msgId; pending.set(id, { resolve, reject });
    ws.send(JSON.stringify({ id, method, params }));
  });
}
async function run() {
  let page;
  for (let i = 0; i < 40; i++) {
    try { const list = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json(); page = list.find(t => t.type === 'page'); if (page) break; } catch {}
    await sleep(250);
  }
  ws = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise(r => { ws.onopen = r; });
  ws.onmessage = ev => { const m = JSON.parse(ev.data); if (m.id && pending.has(m.id)) { const p = pending.get(m.id); pending.delete(m.id); m.error ? p.reject(new Error(m.error.message)) : p.resolve(m.result); } };
  await send('Page.enable');
  await send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 2, mobile: false });
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });
  await send('Page.navigate', { url });
  await sleep(2500);
  const r = await send('Runtime.evaluate', { expression: `(async () => {
    const links = {
      barboncino: document.getElementById('prenota-barboncino').href,
      maltipoo: document.getElementById('prenota-maltipoo').href,
      maltese: document.getElementById('prenota-maltese').href,
      bassotto: [...document.querySelectorAll('#prenota-bassotto a')].map(a => a.href)
    };
    const d = document.getElementById('prenota-bassotto');
    d.open = true;
    await new Promise(r => setTimeout(r, 500));
    document.getElementById('bassotto-kaninchen').scrollIntoView({ block: 'center' });
    await new Promise(r => setTimeout(r, 800));
    return JSON.stringify({ links, open: d.open, overflow: document.documentElement.scrollWidth > innerWidth });
  })()`, returnByValue: true, awaitPromise: true });
  console.log(r.result.value);
  const s = await send('Page.captureScreenshot', { format: 'jpeg', quality: 85 });
  writeFileSync(shot, Buffer.from(s.data, 'base64'));
}
run().catch(e => console.error('PROBE FAIL:', e.message))
  .finally(() => { chrome.kill(); setTimeout(() => { try { rmSync(profile, { recursive: true, force: true }); } catch {} }, 400); });
