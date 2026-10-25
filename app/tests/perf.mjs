// Test de charge : N utilisateurs simultanés ouvrent le tableau de bord (page + ressources + 4 jeux gold).
// Usage : BASE_URL=http://127.0.0.1:8766 USERS=20 DURATION_S=20 node tests/perf.mjs
import { Suite } from './lib/suite.mjs';

const BASE = (process.env.BASE_URL || 'http://127.0.0.1:8766').replace(/\/$/, '');
const USERS = Number(process.env.USERS || 20);
const DURATION = Number(process.env.DURATION_S || 20) * 1000;
const suite = new Suite('performance');

const PARCOURS = ['/', '/assets/css/app.css', '/assets/js/format.js', '/assets/js/app.js', '/assets/vendor/chart.umd.min.js',
  '/api.php?f=indicateurs', '/api.php?f=doublons', '/api.php?f=qualite', '/api.php?f=pipeline'];

const pct = (arr, p) => arr[Math.min(arr.length - 1, Math.floor((p / 100) * arr.length))];

const requests = [];
const sessions = [];
let errors = 0;

async function user(end) {
  while (Date.now() < end) {
    const t0 = performance.now();
    // le navigateur charge la page puis ses ressources en parallèle (6 connexions)
    const fetchOne = async (path) => {
      const t = performance.now();
      try {
        const r = await fetch(BASE + path, { headers: { 'Accept-Encoding': 'gzip' } });
        await r.arrayBuffer();
        if (!r.ok) errors++;
      } catch {
        errors++;
      }
      requests.push({ api: path.startsWith('/api'), ms: performance.now() - t });
    };
    await fetchOne(PARCOURS[0]);
    await Promise.all(PARCOURS.slice(1).map(fetchOne));
    sessions.push(performance.now() - t0);
  }
}

const end = Date.now() + DURATION;
await Promise.all(Array.from({ length: USERS }, () => user(end)));

const all = requests.map((r) => r.ms).sort((a, b) => a - b);
const api = requests.filter((r) => r.api).map((r) => r.ms).sort((a, b) => a - b);
sessions.sort((a, b) => a - b);
const m = {
  utilisateurs: USERS, duree_s: DURATION / 1000, requetes: requests.length, erreurs: errors,
  debit_req_s: Math.round(requests.length / (DURATION / 1000)),
  api_p50_ms: Math.round(pct(api, 50)), api_p95_ms: Math.round(pct(api, 95)), api_p99_ms: Math.round(pct(api, 99)),
  parcours_p50_ms: Math.round(pct(sessions, 50)), parcours_p95_ms: Math.round(pct(sessions, 95)), parcours: sessions.length,
};
Object.entries(m).forEach(([k, v]) => suite.metric(k, v));
console.log(m);

await suite.test(`taux d’erreur nul sous ${USERS} utilisateurs simultanés`, () => {
  if (errors) throw new Error(`${errors} erreurs sur ${requests.length} requêtes`);
}, 'BNF-04');
await suite.test('ouverture complète du tableau de bord p95 < 3 s', () => {
  if (m.parcours_p95_ms >= 3000) throw new Error(`p95 = ${m.parcours_p95_ms} ms`);
}, 'BNF-09');
await suite.test('API gold p95 < 500 ms', () => {
  if (m.api_p95_ms >= 500) throw new Error(`p95 = ${m.api_p95_ms} ms`);
}, 'BNF-09');

suite.finish();
