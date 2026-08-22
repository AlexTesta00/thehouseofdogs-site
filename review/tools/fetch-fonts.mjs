import { writeFileSync, mkdirSync } from 'node:fs';
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
const cssUrl = 'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Figtree:wght@400;600;700&family=DM+Mono:wght@400&display=swap';
const css = await (await fetch(cssUrl, { headers: { 'User-Agent': UA } })).text();
const re = /\/\*\s*([a-z-]+)\s*\*\/\s*@font-face\s*\{([^}]*)\}/g;
let out = '/* Font self-hosted (nessuna richiesta a Google): Fraunces, Figtree, DM Mono. */\n';
let n = 0, m;
mkdirSync('assets/fonts', { recursive: true });
while ((m = re.exec(css))) {
  const sub = m[1], b = m[2];
  if (!['latin', 'latin-ext'].includes(sub)) continue;
  const fam = b.match(/font-family:\s*'([^']+)'/)[1];
  const style = (b.match(/font-style:\s*(\w+)/) || [])[1] || 'normal';
  const weight = (b.match(/font-weight:\s*([\d ]+)/) || [])[1].trim();
  const url = b.match(/url\((https:[^)]+)\)/)[1];
  const range = b.match(/unicode-range:\s*([^;]+);/)[1];
  const file = `${fam.toLowerCase().replace(/\s+/g, '-')}-${weight.replace(/\s+/g, '-')}-${sub}.woff2`;
  writeFileSync('assets/fonts/' + file, Buffer.from(await (await fetch(url)).arrayBuffer()));
  n++;
  out += `@font-face{font-family:'${fam}';font-style:${style};font-weight:${weight};font-display:swap;src:url('../fonts/${file}') format('woff2');unicode-range:${range}}\n`;
}
writeFileSync('assets/css/fonts.css', out);
console.log('scaricati', n, 'file');
