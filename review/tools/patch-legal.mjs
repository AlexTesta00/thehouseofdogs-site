import { readFileSync, writeFileSync } from 'node:fs';
let s = readFileSync('index.php', 'utf8');
const oldFooter = `<li><a class="hover:text-cream transition-colors" href="termini.php">Termini e Condizioni</a></li>`;
const newFooter = `<li><a class="hover:text-cream transition-colors" href="termini.php">Condizioni di vendita</a></li>`;
if (s.includes(oldFooter)) s = s.replace(oldFooter, newFooter);
const anchor = `      </div>
    </div>
  </section>

  <!-- ============ PERCHE NOI ============ -->`;
const note = `      </div>

      <p class="reveal mt-8 text-sm text-inksoft max-w-3xl"><span class="part">Con il bottone <strong>Prenota</strong> versi una caparra di prenotazione su pagine sicure Stripe. Prima ti confermiamo sempre tutto per iscritto: cucciolo, prezzo totale, documenti e consegna. La prenotazione è regolata dalle nostre <a href="termini.php" class="underline underline-offset-2 text-accent">Condizioni di vendita</a>.</span></p>
    </div>
  </section>

  <!-- ============ PERCHE NOI ============ -->`;
if (s.includes(anchor) && !s.includes('caparra di prenotazione su pagine sicure')) s = s.replace(anchor, note);
writeFileSync('index.php', s);
console.log('footer:', s.includes(newFooter), 'nota:', s.includes('caparra di prenotazione su pagine sicure'));
