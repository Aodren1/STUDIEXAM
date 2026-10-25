// Assemble docs/src/*.html en docs/dossier.html puis imprime le PDF avec Chrome headless.
// Les tableaux du backlog et des campagnes de tests sont générés depuis les fichiers sources
// (docs/trello/*.json, reports/*/rapport.json) : aucun chiffre recopié à la main.
// Usage : node docs/build.mjs        (GITHUB_URL=… pour le lien du dépôt)
import { readdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');
const json = (p) => JSON.parse(readFileSync(join(root, p), 'utf8'));
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const nb = (n) => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

const LINKS = {
  TRELLO_URL: 'https://trello.com/b/N4SHAra8/exam2-studi',
  GITHUB_URL: process.env.GITHUB_URL || 'https://github.com/Aodren1/STUDIEXAM',
};

const backlog = json('docs/trello/backlog.json');
const cartes = json('docs/trello/cartes.json');
const run1 = json('reports/run-1-preprod/rapport.json');
const run2 = json('reports/run-2-correctifs/rapport.json');

const moscowTag = (m) => {
  const k = m.startsWith('Must') ? 'must' : m.startsWith('Should') ? 'should' : m.startsWith('Could') ? 'could' : 'wont';
  return `<span class="tag t-${k}">${esc(m)}</span>`;
};
const ETAT = { fait: 'Livrée', fait_s6: 'Livrée (S6)', revue: 'En revue', en_cours: 'En cours', a_faire: 'À faire', backlog: 'Backlog' };
const plan = (u) => (u.version ? u.version : u.sprint ? `S${u.sprint}` : '—');

const gen = {
  // une table par EPIC : code, récit, exigences, priorité, points, planification
  backlog: () => backlog.epics.map((e) => {
    const pts = e.us.reduce((a, u) => a + u.pts, 0);
    return `<div class="epic avoid"><h4><span class="ep ep-${e.couleur}">${e.code}</span> ${esc(e.titre)} <span class="muted small">— ${e.us.length} US, ${pts} points · ${esc(e.exigences)}</span></h4>
<table class="compact us"><thead><tr><th style="width:8%">Réf.</th><th>User Story (titre — récit)</th><th style="width:11%">Exigences</th><th style="width:10%">Priorité</th><th class="num" style="width:5%">Pts</th><th style="width:10%">Plan. / état</th></tr></thead><tbody>
${e.us.map((u) => `<tr><td><b>${u.code}</b></td><td><b>${esc(u.titre)}</b><br><span class="small">En tant que ${esc(u.role)}, je veux ${esc(u.veux)}, afin de ${esc(u.afin)}.</span></td><td class="small">${esc(u.exig)}</td><td>${moscowTag(u.moscow)}</td><td class="num">${u.pts}</td><td class="small">${plan(u)} · ${ETAT[u.etat]}</td></tr>`).join('\n')}
</tbody></table></div>`;
  }).join('\n'),

  // critères d'acceptation de toutes les US (annexe)
  criteres: () => backlog.epics.map((e) => e.us.map((u) => `<div class="ca avoid"><p><b>${u.code} · ${esc(u.titre)}</b> <span class="muted small">(${esc(u.moscow)}, ${u.pts} pts)</span></p><ul>${u.ca.map((c) => `<li>${esc(c)}</li>`).join('')}</ul></div>`).join('\n')).join('\n'),

  synthese_backlog: () => {
    const rows = backlog.epics.map((e) => {
      const s = (f) => e.us.filter(f).reduce((a, u) => a + u.pts, 0);
      const total = s(() => true), livre = s((u) => u.etat.startsWith('fait'));
      return `<tr><td><span class="ep ep-${e.couleur}">${e.code}</span> ${esc(e.titre)}</td><td>${esc(e.module)}</td><td class="num">${e.us.length}</td><td class="num">${total}</td><td class="num">${livre}</td><td class="num">${Math.round((100 * livre) / total)} %</td></tr>`;
    });
    const all = backlog.epics.flatMap((e) => e.us);
    const t = all.reduce((a, u) => a + u.pts, 0), l = all.filter((u) => u.etat.startsWith('fait')).reduce((a, u) => a + u.pts, 0);
    return `<table class="compact"><thead><tr><th>EPIC</th><th>Module Bloc 1</th><th class="num">US</th><th class="num">Points</th><th class="num">Livrés</th><th class="num">Avancement</th></tr></thead><tbody>${rows.join('')}
<tr><td><b>Total</b></td><td></td><td class="num"><b>${all.length}</b></td><td class="num"><b>${t}</b></td><td class="num"><b>${l}</b></td><td class="num"><b>${Math.round((100 * l) / t)} %</b></td></tr></tbody></table>`;
  },

  dor: () => `<ol class="check">${cartes.regles.find((r) => r.titre.includes('DoR')).checklist.items.map((i) => `<li>${esc(i)}</li>`).join('')}</ol>`,
  dod: () => `<ol class="check">${cartes.regles.find((r) => r.titre.includes('DoD')).checklist.items.map((i) => `<li>${esc(i)}</li>`).join('')}</ol>`,

  campagnes: () => {
    const suites = ['unitaires', 'unitaires-js', 'integration', 'api-securite', 'e2e', 'performance'];
    const LBL = { unitaires: 'Unitaires (PHP)', 'unitaires-js': 'Unitaires (JS)', integration: 'Intégration / réconciliation', 'api-securite': 'API et sécurité', e2e: 'Bout en bout (navigateur)', performance: 'Charge' };
    const cell = (r, s) => {
      const e = r.etapes.find((x) => x.suite === s);
      if (!e || e.total == null) return '<td class="num muted">non compté</td>';
      return `<td class="num ${e.echecs ? 'ko' : 'ok'}">${e.total - e.echecs} / ${e.total}</td>`;
    };
    return `<table class="compact"><thead><tr><th>Suite de tests</th><th class="num">Campagne 1 (pré-production)</th><th class="num">Campagne 2 (après correctifs)</th></tr></thead><tbody>
${suites.map((s) => `<tr><td>${LBL[s]}</td>${cell(run1, s)}${cell(run2, s)}</tr>`).join('')}
<tr><td><b>Total</b></td><td class="num ko"><b>${run1.reussis} / ${run1.total}</b></td><td class="num ok"><b>${run2.reussis} / ${run2.total}</b></td></tr></tbody></table>`;
  },

  echecs_run1: () => `<table class="compact"><thead><tr><th style="width:9%">Anomalie</th><th style="width:11%">Suite</th><th>Test en échec</th><th style="width:9%">Exigence</th></tr></thead><tbody>
${run1.tests.filter((t) => t.statut !== 'reussi').map((t, i) => `<tr><td><b>R1-${String([0, 1, 7, 2, 3, 4, 5, 8, 9, 10, 6][i] + 1).padStart(2, '0')}</b></td><td>${esc(t.suite)}</td><td>${esc(t.nom)}<br><span class="small muted">${esc(t.message.split('\n')[0].slice(0, 120))}</span></td><td>${esc(t.exigence)}</td></tr>`).join('\n')}
</tbody></table>`,

  anomalies: () => `<table class="compact"><thead><tr><th style="width:7%">Réf.</th><th style="width:19%">Anomalie</th><th style="width:10%">Gravité</th><th>Cause racine</th><th>Ajustement réalisé</th></tr></thead><tbody>
${cartes.anomalies.map((a) => `<tr><td><b>${a.code}</b></td><td>${esc(a.titre)}<br><span class="small muted">${esc(a.exig)}</span></td><td class="small">${esc(a.gravite)}</td><td class="small">${esc(a.cause)}</td><td class="small">${esc(a.correctif)}</td></tr>`).join('\n')}
</tbody></table>`,

  // matrice de traçabilité exigence -> tests (campagne 2)
  tracabilite: () => {
    const by = {};
    for (const t of run2.tests) (by[t.exigence || '—'] ??= []).push(t);
    const key = (k) => { const m = k.match(/^(BNF|BF|CA)-(\d+)/); return m ? ({ BF: 0, BNF: 1, CA: 2 }[m[1]] * 100 + Number(m[2])) : 999; };
    return `<table class="compact"><thead><tr><th style="width:9%">Exigence</th><th class="num" style="width:6%">Tests</th><th>Tests rattachés (campagne 2, tous réussis)</th></tr></thead><tbody>
${Object.keys(by).sort((a, b) => key(a) - key(b)).map((k) => `<tr><td><b>${k}</b></td><td class="num">${by[k].length}</td><td class="small">${by[k].map((t) => esc(t.nom)).join(' · ')}</td></tr>`).join('\n')}
</tbody></table>`;
  },

  perf: () => {
    const p1 = run1.mesures.performance, p2 = run2.mesures.performance;
    const row = (l, k, u = ' ms') => `<tr><td>${l}</td><td class="num">${nb(p1[k])}${u}</td><td class="num">${nb(p2[k])}${u}</td></tr>`;
    return `<table class="compact"><thead><tr><th>Mesure (20 utilisateurs simultanés, 20 s)</th><th class="num">Campagne 1</th><th class="num">Campagne 2</th></tr></thead><tbody>
${row('Requêtes servies', 'requetes', '')}${row('Débit', 'debit_req_s', ' req/s')}${row('Erreurs', 'erreurs', '')}${row('API — p50', 'api_p50_ms')}${row('API — p95 (seuil 500 ms)', 'api_p95_ms')}${row('Ouverture complète du tableau de bord — p95 (seuil 3 000 ms)', 'parcours_p95_ms')}
<tr><td>Affichage navigateur (Chrome, cache désactivé) — médiane</td><td class="num muted">mesure fausse (3 ms)</td><td class="num">${run2.mesures.e2e.affichage_ms_median} ms</td></tr></tbody></table>`;
  },

  liens: () => `<table class="compact links"><tbody>
<tr><td style="width:30%"><b>Trello</b> — backlog, EPICs, User Stories, DoR, DoD, anomalies</td><td><a href="${LINKS.TRELLO_URL}">${LINKS.TRELLO_URL}</a></td></tr>
<tr><td><b>GitHub</b> — code du logiciel de validation, tests, rapports</td><td><a href="${LINKS.GITHUB_URL}">${LINKS.GITHUB_URL}</a></td></tr>
<tr><td><b>GitHub Actions</b> — intégration continue (analyse, tests, recette Docker)</td><td><a href="${LINKS.GITHUB_URL}/actions">${LINKS.GITHUB_URL}/actions</a></td></tr>
<tr><td><b>Rapports de tests</b> — campagnes 1 et 2 (JSON)</td><td><a href="${LINKS.GITHUB_URL}/tree/main/reports">${LINKS.GITHUB_URL}/tree/main/reports</a></td></tr>
</tbody></table><p class="small muted">Accès public en lecture : consultables sans création de compte.</p>`,
};

const parts = readdirSync(join(here, 'src')).filter((f) => f.endsWith('.html')).sort();
let html = parts.map((f) => readFileSync(join(here, 'src', f), 'utf8')).join('\n');
html = html.replace(/<!--GEN:(\w+)-->/g, (_, k) => {
  if (!gen[k]) throw new Error(`générateur inconnu : ${k}`);
  return gen[k]();
});
html = html.replace(/\{\{(\w+)\}\}/g, (_, k) => LINKS[k] ?? `{{${k}}}`);
writeFileSync(join(here, 'dossier.html'), html);

const pdf = join(root, 'BCDBigData_Examen_B2_copiearendre_FLORIDE_Aodren.pdf');
const chrome = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
if (!process.argv.includes('--html-only') && existsSync(chrome)) {
  execFileSync(chrome, ['--headless=new', '--disable-gpu', '--no-pdf-header-footer', '--virtual-time-budget=5000',
    `--print-to-pdf=${pdf}`, pathToFileURL(join(here, 'dossier.html')).href], { stdio: 'inherit' });
  console.log(`${parts.length} parties assemblées → ${pdf}`);
  const slides = join(here, 'soutenance', 'slides.html');
  if (existsSync(slides)) {
    const out = join(here, 'soutenance', 'Soutenance_Bloc2_FLORIDE_Aodren.pdf');
    execFileSync(chrome, ['--headless=new', '--disable-gpu', '--no-pdf-header-footer', '--virtual-time-budget=5000',
      `--print-to-pdf=${out}`, pathToFileURL(slides).href], { stdio: 'inherit' });
    console.log(`slides → ${out}`);
  }
}
