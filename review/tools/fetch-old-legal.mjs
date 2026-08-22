import { spawn } from 'node:child_process';
import { writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9231;
const profile = join(tmpdir(), 'hod-old-' + PORT);
const chrome = spawn(CHROME, ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`, 'about:blank'], { stdio: 'ignore' });
const sleep = ms => new Promise(r => setTimeout(r, ms));
let msgId = 0; const pending = new Map(); let ws;
function send(method, params = {}) { return new Promise((resolve, reject) => { const id = ++msgId; pending.set(id, { resolve, reject }); ws.send(JSON.stringify({ id, method, params })); }); }
async function evalJs(expression, awaitPromise = false) { const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise }); return r.result.value; }
async function run() {
  let page;
  for (let i = 0; i < 40; i++) { try { const list = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json(); page = list.find(t => t.type === 'page'); if (page) break; } catch {} await sleep(250); }
  ws = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise(r => { ws.onopen = r; });
  ws.onmessage = ev => { const m = JSON.parse(ev.data); if (m.id && pending.has(m.id)) { const p = pending.get(m.id); pending.delete(m.id); m.error ? p.reject(new Error(m.error.message)) : p.resolve(m.result); } };
  await send('Page.enable');
  await send('Page.navigate', { url: 'https://www.thehouseofdogs.it/' });
  await sleep(6000);
  const links = await evalJs(`JSON.stringify([...document.querySelectorAll('a,button')].filter(e => /privacy|termini|condizioni/i.test(e.textContent)).map(e => ({ tag: e.tagName, text: e.textContent.trim(), href: e.getAttribute('href') })))`);
  console.log('LINKS', links);
  const out = {};
  for (const label of ['privacy', 'termini']) {
    await send('Page.navigate', { url: 'https://www.thehouseofdogs.it/' });
    await sleep(5000);
    const clicked = await evalJs(`(() => { const re = ${label === 'privacy' ? '/privacy/i' : '/termini|condizioni/i'}; const el = [...document.querySelectorAll('a,button')].find(e => re.test(e.textContent)); if (!el) return 'none'; el.click(); return el.getAttribute('href') || 'clicked'; })()`);
    await sleep(4000);
    const url = await evalJs('location.href');
    const text = await evalJs(`(() => { const m = document.querySelector('main') || document.body; return m.innerText; })()`);
    out[label] = { clicked, url, text };
    console.log(label, clicked, url, 'chars:', (text || '').length);
  }
  writeFileSync('review/old-site-legal.json', JSON.stringify(out, null, 2));
}
run().catch(e => console.error('FAIL', e.message)).finally(() => { chrome.kill(); setTimeout(() => { try { rmSync(profile, { recursive: true, force: true }); } catch {} }, 400); });
