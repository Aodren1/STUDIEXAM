// Orchestrateur de la campagne de tests : exécute toutes les suites et consolide un rapport.
//
//   node tests/run-all.mjs --label run-1                 (démarre un serveur php -S local)
//   node tests/run-all.mjs --label ci --base-url http://127.0.0.1:8095   (cible un conteneur déjà lancé)
//   options : --skip-pipeline  --skip perf,e2e  --web-only (suites HTTP seules, contre --base-url)
//
// Sorties : reports/<label>/<suite>.json, reports/<label>/rapport.json, reports/<label>/captures/*.png
import { spawn, spawnSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const arg = (name, def = null) => {
  const i = process.argv.indexOf(`--${name}`);
  return i < 0 ? def : (process.argv[i + 1] && !process.argv[i + 1].startsWith('--') ? process.argv[i + 1] : true);
};
const label = arg('label', 'local');
const skip = String(arg('skip', '')).split(',');
const OUT = join(ROOT, '..', 'reports', label);
mkdirSync(OUT, { recursive: true });

// Sous Windows, l'extension pdo_sqlite n'est pas chargée par défaut (friction relevée au sprint 1).
const PHP = ['php', ...(process.platform === 'win32' ? ['-d', 'extension=pdo_sqlite'] : []), '-d', 'memory_limit=1G'];
const env = { ...process.env, APP_REF_DATE: process.env.APP_REF_DATE || '2026-10-25' };

const suites = [];
function run(name, cmd, args, extraEnv = {}) {
  const t = Date.now();
  const reportFile = join(OUT, `${name}.json`);
  console.log(`\n■ ${name}`);
  const r = spawnSync(cmd, args, { cwd: ROOT, stdio: 'inherit', env: { ...env, ...extraEnv, REPORT_FILE: reportFile } });
  const report = existsSync(reportFile) ? JSON.parse(readFileSync(reportFile, 'utf8')) : null;
  suites.push({ suite: name, code: r.status, duree_ms: Date.now() - t, total: report?.total ?? null, echecs: report?.echecs ?? null });
  return r.status === 0;
}

const webOnly = Boolean(arg('web-only'));
if (!arg('skip-pipeline') && !webOnly) {
  run('preparation', PHP[0], [...PHP.slice(1), 'bin/seed.php']);
  run('pipeline', PHP[0], [...PHP.slice(1), 'bin/pipeline.php']);
}
if (!webOnly) run('unitaires', PHP[0], [...PHP.slice(1), 'tests/run.php']);
// node:test ne connaît pas notre format : rapport JUnit converti (friction : ces tests n'apparaissaient pas au rapport de la campagne 1).
if (!webOnly) {
  const junit = join(OUT, 'unitaires-js.xml');
  run('unitaires-js', 'node', ['--test', '--test-reporter=spec', '--test-reporter-destination=stdout',
    '--test-reporter=junit', `--test-reporter-destination=${junit}`, 'tests/format.test.mjs']);
  if (existsSync(junit)) {
    const xml = readFileSync(junit, 'utf8');
    const tests = [...xml.matchAll(/<testcase name="([^"]+)"[^>]*time="([\d.]+)"[^>]*?(\/>|>([\s\S]*?)<\/testcase>)/g)].map((m) => ({
      groupe: 'Formatage (navigateur)', nom: m[1].replace(/&apos;/g, '’').replace(/&#39;/g, '’'), exigence: 'BF-17',
      statut: /<failure/.test(m[4] || '') ? 'echec' : 'reussi', duree_ms: Math.round(Number(m[2]) * 1000), message: '' }));
    const echecs = tests.filter((t) => t.statut !== 'reussi').length;
    writeFileSync(join(OUT, 'unitaires-js.json'), JSON.stringify({ suite: 'unitaires-js', total: tests.length, echecs, tests }, null, 2));
    Object.assign(suites.at(-1), { total: tests.length, echecs });
  }
}
if (!webOnly) run('integration', PHP[0], [...PHP.slice(1), 'tests/integration.php']);

let base = arg('base-url');
let server = null;
if (!base) {
  const port = 8800 + Math.floor(Math.random() * 100);
  base = `http://127.0.0.1:${port}`;
  const router = existsSync(join(ROOT, 'bin/serve.php')) ? ['bin/serve.php'] : [];
  server = spawn(PHP[0], [...PHP.slice(1), '-S', `127.0.0.1:${port}`, '-t', 'public', ...router], { cwd: ROOT, stdio: 'ignore', env });
  for (let i = 0; i < 50; i++) {
    try { await fetch(base); break; } catch { await new Promise((r) => setTimeout(r, 100)); }
  }
}
const web = { BASE_URL: base, CAPTURES_DIR: join(OUT, 'captures') };
if (!skip.includes('api')) run('api-securite', PHP[0], [...PHP.slice(1), 'tests/api.php'], web);
if (!skip.includes('e2e')) run('e2e', 'node', ['tests/e2e.mjs'], web);
if (!skip.includes('perf')) run('performance', 'node', ['tests/perf.mjs'], web);
server?.kill();

// rapport consolidé
const detail = {};
for (const s of suites) {
  const f = join(OUT, `${s.suite}.json`);
  if (existsSync(f)) detail[s.suite] = JSON.parse(readFileSync(f, 'utf8'));
}
const tests = Object.values(detail).flatMap((d) => d.tests.map((t) => ({ suite: d.suite, ...t })));
const rapport = {
  label, date: new Date().toISOString(), cible: base, plateforme: `${process.platform} / node ${process.version}`,
  total: tests.length, reussis: tests.filter((t) => t.statut === 'reussi').length, echecs: tests.filter((t) => t.statut !== 'reussi').length,
  etapes: suites, mesures: Object.fromEntries(Object.entries(detail).filter(([, d]) => d.mesures).map(([k, d]) => [k, d.mesures])), tests,
};
writeFileSync(join(OUT, 'rapport.json'), JSON.stringify(rapport, null, 2));
console.log(`\n══ ${label} : ${rapport.reussis}/${rapport.total} tests réussis, ${rapport.echecs} en échec → reports/${label}/rapport.json`);
process.exit(rapport.echecs || suites.some((s) => s.code !== 0) ? 1 : 0);
