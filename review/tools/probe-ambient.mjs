import { spawn } from 'node:child_process';
import { rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const url = process.argv[2] || 'http://127.0.0.1:8123/';
const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9227;
const profile = join(tmpdir(), 'hod-probe-amb-' + PORT);
const chrome = spawn(CHROME, ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`, 'about:blank'], { stdio: 'ignore' });
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
  await send('Emulation.setDeviceMetricsOverride', { width: 375, height: 812, deviceScaleFactor: 2, mobile: true });
  await send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 });
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });
  await send('Page.navigate', { url });
  await sleep(3000);
  const r = await send('Runtime.evaluate', { expression: `(async () => {
    const v = document.getElementById('hero-ambient');
    const ranges = tl => { const o = []; for (let i = 0; i < tl.length; i++) o.push([+tl.start(i).toFixed(2), +tl.end(i).toFixed(2)]); return o; };
    const out = { duration: +v.duration.toFixed(2), seekable: ranges(v.seekable), buffered: ranges(v.buffered), readyState: v.readyState, netState: v.networkState };
    v.currentTime = 4.0;
    out.rightAfterSet4 = +v.currentTime.toFixed(2);
    await new Promise(r => setTimeout(r, 700));
    out.after4 = +v.currentTime.toFixed(2);
    v.currentTime = 5.74;
    out.rightAfterSet574 = +v.currentTime.toFixed(2);
    await new Promise(r => setTimeout(r, 1500));
    out.final = { t: +v.currentTime.toFixed(2), ended: v.ended, paused: v.paused };
    return JSON.stringify(out, null, 1);
  })()`, returnByValue: true, awaitPromise: true });
  console.log(r.result.value);
}
run().catch(e => console.error('PROBE FAIL:', e.message))
  .finally(() => { chrome.kill(); setTimeout(() => { try { rmSync(profile, { recursive: true, force: true }); } catch {} }, 400); });
