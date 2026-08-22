import { readFileSync, writeFileSync } from 'node:fs';
const s = readFileSync('old-bundle.js', 'utf8');
const anchor = s.indexOf('Saldo Finale');
const a = Math.max(0, anchor - 7000), b = Math.min(s.length, anchor + 2500);
const seg = s.slice(a, b);
// stringhe JSX: children:"..." oppure children:'...'
const re = new RegExp('children:\\s*(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\')', 'g');
const out = [];
let m;
while ((m = re.exec(seg))) { const t = (m[1] ?? m[2]); if (t && t.length > 2) out.push(t); }
writeFileSync('old-payment-text.txt', out.join('\n'));
console.log(out.join('\n'));
