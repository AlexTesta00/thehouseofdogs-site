// Scarica Inter (variabile, latin + latin-ext) da Google Fonts in site/assets/fonts.
// Google serve lo stesso file variabile per ogni peso: dopo il download i duplicati sono stati uniti a mano
// in inter-var-latin(.ext).woff2 con font-weight:100 900 (vedi site/assets/css/fonts.css).
// riscrivendo site/assets/css/fonts.css. Eseguire dalla cartella site/: node ../review/tools/fetch-inter.mjs
import { writeFileSync, mkdirSync, readdirSync, unlinkSync } from 'node:fs';
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const cssUrl = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap';
const css = await (await fetch(cssUrl, { headers: { 'User-Agent': UA } })).text();
const re = /\/\*\s*([a-z-]+)\s*\*\/\s*@font-face\s*\{([^}]*)\}/g;
let out = '/* Font self-hosted (nessuna richiesta a Google): Inter, unico carattere del sito. */\n';
let n = 0, m;
mkdirSync('assets/fonts', { recursive: true });
for (const f of readdirSync('assets/fonts')) if (/^(fraunces|figtree|dm-mono)-/.test(f)) unlinkSync('assets/fonts/' + f);
const seen = new Set();
while ((m = re.exec(css))) {
  const sub = m[1], b = m[2];
  if (!['latin', 'latin-ext'].includes(sub)) continue;
  const fam = b.match(/font-family:\s*'([^']+)'/)[1];
  const style = (b.match(/font-style:\s*(\w+)/) || [])[1] || 'normal';
  const weight = (b.match(/font-weight:\s*([\d ]+)/) || [])[1].trim();
  const url = b.match(/url\((https:[^)]+)\)/)[1];
  const range = b.match(/unicode-range:\s*([^;]+);/)[1];
  const file = `${fam.toLowerCase().replace(/\s+/g, '-')}-${weight.replace(/\s+/g, '-')}-${sub}.woff2`;
  if (!seen.has(file)) {
    writeFileSync('assets/fonts/' + file, Buffer.from(await (await fetch(url)).arrayBuffer()));
    seen.add(file); n++;
  }
  out += `@font-face{font-family:'${fam}';font-style:${style};font-weight:${weight};font-display:swap;src:url('../fonts/${file}') format('woff2');unicode-range:${range}}\n`;
}
writeFileSync('assets/css/fonts.css', out);
console.log('scaricati', n, 'file');
