import { spawn } from 'node:child_process';
import { writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9229;
const profile = join(tmpdir(), 'hod-legal-' + PORT);
const chrome = spawn(CHROME, ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`, '--hide-scrollbars', 'about:blank'], { stdio: 'ignore' });
const sleep = ms => new Promise(r => setTimeout(r, ms));
let msgId = 0; const pending = new Map(); let ws; const errs = [];
function send(method, params = {}) { return new Promise((resolve, reject) => { const id = ++msgId; pending.set(id, { resolve, reject }); ws.send(JSON.stringify({ id, method, params })); }); }
async function run() {
  let page;
  for (let i = 0; i < 40; i++) { try { const list = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json(); page = list.find(t => t.type === 'page'); if (page) break; } catch {} await sleep(250); }
  ws = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise(r => { ws.onopen = r; });
  ws.onmessage = ev => { const m = JSON.parse(ev.data); if (m.id && pending.has(m.id)) { const p = pending.get(m.id); pending.delete(m.id); m.error ? p.reject(new Error(m.error.message)) : p.resolve(m.result); } else if (m.method === 'Log.entryAdded' && m.params.entry.level === 'error') errs.push(m.params.entry.text); };
  await send('Page.enable'); await send('Log.enable');
  await send('Emulation.setDeviceMetricsOverride', { width: 375, height: 812, deviceScaleFactor: 2, mobile: true });
  for (const [name, path] of [['privacy', 'privacy.php'], ['termini', 'termini.php'], ['home-nota', '#cuccioli']]) {
    await send('Page.navigate', { url: 'http://127.0.0.1:8124/' + path });
    await sleep(2500);
    if (name === 'home-nota') await send('Runtime.evaluate', { expression: `document.querySelector('a[href="termini.php"].text-accent').scrollIntoView({block:'center'})` });
    await sleep(800);
    const info = await send('Runtime.evaluate', { expression: `JSON.stringify({ w: document.documentElement.scrollWidth, vw: innerWidth, fraunces: document.fonts.check("600 20px Fraunces"), figtree: document.fonts.check("400 16px Figtree"), h: document.documentElement.scrollHeight })`, returnByValue: true });
    console.log(name, info.result.value);
    const s = await send('Page.captureScreenshot', { format: 'jpeg', quality: 80 });
    writeFileSync(`review/audit/legal-${name}.jpg`, Buffer.from(s.data, 'base64'));
  }
  console.log('errori:', JSON.stringify(errs));
}
run().catch(e => console.error('FAIL', e.message)).finally(() => { chrome.kill(); setTimeout(() => { try { rmSync(profile, { recursive: true, force: true }); } catch {} }, 400); });
