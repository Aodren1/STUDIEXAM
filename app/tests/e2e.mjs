// Tests de bout en bout du tableau de bord dans un vrai navigateur (Chrome / Edge headless),
// pilotés par le protocole DevTools (CDP) : aucune dépendance npm.
// Usage : BASE_URL=http://127.0.0.1:8766 node tests/e2e.mjs   (CHROME_PATH=… si le navigateur n'est pas trouvé)
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Suite } from './lib/suite.mjs';

const BASE = (process.env.BASE_URL || 'http://127.0.0.1:8766').replace(/\/$/, '');
const SHOTS = process.env.CAPTURES_DIR || '';
const suite = new Suite('e2e');

function findBrowser() {
  const candidates = [
    process.env.CHROME_PATH,
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    '/usr/bin/google-chrome', '/usr/bin/chromium-browser', '/usr/bin/chromium',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  ];
  return candidates.find((p) => p && existsSync(p));
}

async function launch() {
  const profile = mkdtempSync(join(tmpdir(), 'e2e-'));
  const proc = spawn(findBrowser(), ['--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`,
    '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--hide-scrollbars', '--force-prefers-reduced-motion',
    ...(process.platform === 'linux' ? ['--no-sandbox'] : []), 'about:blank'], { stdio: 'ignore' });
  const portFile = join(profile, 'DevToolsActivePort');
  for (let i = 0; i < 100 && !existsSync(portFile); i++) await new Promise((r) => setTimeout(r, 100));
  const port = readFileSync(portFile, 'utf8').split('\n')[0];
  const pages = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
  const page = pages.find((p) => p.type === 'page');
  return { proc, profile, wsUrl: page.webSocketDebuggerUrl };
}

/** Client CDP minimal : send(method, params) et écoute des événements. */
function cdp(wsUrl) {
  const ws = new WebSocket(wsUrl);
  let id = 0;
  const pending = new Map();
  const listeners = [];
  ws.onmessage = (ev) => {
    const msg = JSON.parse(ev.data);
    if (msg.id && pending.has(msg.id)) {
      const { resolve, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
    } else if (msg.method) {
      listeners.forEach((fn) => fn(msg));
    }
  };
  return new Promise((ready) => {
    ws.onopen = () => ready({
      send: (method, params = {}) => new Promise((resolve, reject) => {
        pending.set(++id, { resolve, reject });
        ws.send(JSON.stringify({ id, method, params }));
      }),
      on: (fn) => listeners.push(fn),
      close: () => ws.close(),
    });
  });
}

const { proc, profile, wsUrl } = await launch();
const c = await cdp(wsUrl);
const errors = [];
c.on((m) => {
  if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text);
  if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') errors.push(m.params.args.map((a) => a.value).join(' '));
  if (m.method === 'Log.entryAdded' && m.params.entry.level === 'error') errors.push(`${m.params.entry.text} ${m.params.entry.url || ''}`);
});
await Promise.all(['Page.enable', 'Runtime.enable', 'Log.enable', 'Network.enable'].map((m) => c.send(m)));

const js = async (expr) => {
  const r = await c.send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
  if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
  return r.result.value;
};
const waitFor = async (expr, ms = 8000) => {
  const end = Date.now() + ms;
  while (Date.now() < end) {
    if (await js(expr)) return true;
    await new Promise((r) => setTimeout(r, 50));
  }
  throw new Error(`délai dépassé : ${expr}`);
};
const viewport = (width, height, mobile = false) => c.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
const shot = async (name) => {
  if (!SHOTS) return;
  mkdirSync(SHOTS, { recursive: true });
  const { data } = await c.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
  writeFileSync(join(SHOTS, `${name}.png`), Buffer.from(data, 'base64'));
};
// Paramètre unique : sans lui, naviguer vers la même URL ne change que l'ancre et ne recharge rien
// (le premier temps d'affichage mesuré était de 3 ms — faux positif de mesure corrigé).
let visit = 0;
const open = async (hash = '') => {
  await c.send('Page.navigate', { url: `${BASE}/?v=${++visit}${hash}` });
  await waitFor("document.readyState === 'complete' && !document.getElementById('loading') && document.querySelectorAll('main .card').length > 0");
};
const kpiValue = (label) => js(`[...document.querySelectorAll('section:not([hidden]) .card.kpi')]
  .find((k) => k.querySelector('.label').textContent.trim() === ${JSON.stringify(label)})?.querySelector('.value').textContent.trim()`);

await viewport(1440, 900);
const api = await (await fetch(`${BASE}/api.php?f=indicateurs`)).json();
const fmtInt = (n) => String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '\u202f').replace(/\u202f/g, ' ');

await suite.test('chargement du tableau de bord sans erreur JavaScript ni ressource manquante', async () => {
  const t = Date.now();
  await open();
  suite.metric('chargement_complet_ms', Date.now() - t);
  if (errors.length) throw new Error(errors.join(' | '));
}, 'BF-12');

await suite.test('les 7 rubriques s’affichent et portent des graphiques ou tableaux', async () => {
  for (const tab of ['synthese', 'clients', 'contrats', 'sinistres', 'finances', 'doublons', 'qualite']) {
    await js(`document.querySelector('.tab[data-tab="${tab}"]').click()`);
    await waitFor(`!document.querySelector('[data-panel="${tab}"]').hidden && document.querySelectorAll('[data-panel="${tab}"] .card').length > 1`);
    await new Promise((r) => setTimeout(r, 250));
    await shot(`onglet-${tab}`);
  }
  if (errors.length) throw new Error(errors.join(' | '));
}, 'CA-02');

await suite.test('filtre de périmètre : KPI identiques à l’API pour AB, AP et consolidé', async () => {
  await js(`document.querySelector('.tab[data-tab="synthese"]').click()`);
  for (const [scope, expected] of [['AB', api.kpis.clients.AB], ['AP', api.kpis.clients.AP], ['ALL', api.kpis.clients.AB + api.kpis.clients.AP]]) {
    await js(`document.querySelector('#scope [data-scope="${scope}"]').click()`);
    const v = (await kpiValue('Fiches clients')).replace(/\s/g, ' ');
    if (v !== fmtInt(expected)) throw new Error(`${scope} : affiché ${v}, attendu ${fmtInt(expected)}`);
  }
}, 'BF-17');

await suite.test('chaque graphique dispose d’une alternative tabulaire', async () => {
  for (const tab of ['clients', 'contrats', 'sinistres', 'finances']) {
    await js(`document.querySelector('.tab[data-tab="${tab}"]').click()`);
    const r = await js(`(() => {
      const p = document.querySelector('[data-panel="${tab}"]');
      const charts = p.querySelectorAll('canvas').length, toggles = p.querySelectorAll('[data-toggle]');
      toggles.forEach((t) => t.click());
      const shown = [...toggles].filter((t) => !document.getElementById(t.dataset.toggle + '-table').hidden).length;
      return { charts, toggles: toggles.length, shown };
    })()`);
    if (r.toggles < r.charts || r.shown !== r.toggles) throw new Error(`${tab} : ${r.charts} graphiques, ${r.toggles} bascules, ${r.shown} tableaux affichés`);
  }
}, 'CA-06');

await suite.test('accessibilité : langue, lien d’évitement, noms accessibles, contrastes AA', async () => {
  await open('#synthese');
  const r = await js(`(() => {
    const lum = (rgb) => { const [r, g, b] = rgb.match(/\\d+(\\.\\d+)?/g).slice(0, 3).map(Number).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
    const bg = (el) => { for (; el; el = el.parentElement) { const c = getComputedStyle(el).backgroundColor; if (!/rgba\\(.*, 0\\)$|transparent/.test(c)) return c; } return 'rgb(255,255,255)'; };
    const ratio = (el) => { const a = lum(getComputedStyle(el).color), b = lum(bg(el)); return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05); };
    const textEls = [...document.querySelectorAll('main p, main h2, main h3, main td, main th, main small, .tab, .footer p, #scope button')].filter((e) => e.offsetParent && e.textContent.trim());
    const faibles = textEls.filter((e) => ratio(e) < 4.5).map((e) => e.tagName + '.' + e.className + ' « ' + e.textContent.trim().slice(0, 25) + ' » ' + ratio(e).toFixed(2));
    return {
      lang: document.documentElement.lang,
      skip: document.querySelector('a.skip')?.getAttribute('href'),
      canvasSansNom: [...document.querySelectorAll('canvas')].filter((cv) => !cv.getAttribute('aria-label')).length,
      boutonsSansNom: [...document.querySelectorAll('button')].filter((b) => !(b.textContent.trim() || b.getAttribute('aria-label'))).length,
      faibles: [...new Set(faibles)].slice(0, 6), nbFaibles: faibles.length,
    };
  })()`);
  const pb = [];
  if (r.lang !== 'fr') pb.push(`lang=${r.lang}`);
  if (r.skip !== '#contenu') pb.push('lien d’évitement absent');
  if (r.canvasSansNom) pb.push(`${r.canvasSansNom} graphiques sans nom accessible`);
  if (r.boutonsSansNom) pb.push(`${r.boutonsSansNom} boutons sans nom`);
  if (r.nbFaibles) pb.push(`${r.nbFaibles} textes sous 4,5:1 (${r.faibles.join(' ; ')})`);
  if (pb.length) throw new Error(pb.join(' | '));
}, 'BNF-12');

await suite.test('affichage mobile (375 px) sans défilement horizontal', async () => {
  await viewport(375, 812, true);
  await open('#synthese');
  const r = await js(`({ sw: document.documentElement.scrollWidth, w: innerWidth,
    larges: [...document.querySelectorAll('body *')].filter((e) => e.getBoundingClientRect().right > innerWidth + 1 && !e.closest('.table-wrap')).map((e) => e.tagName + '.' + e.className).slice(0, 5) })`);
  await shot('mobile-synthese');
  await viewport(1440, 900);
  if (r.sw > r.w) throw new Error(`largeur ${r.sw}px > ${r.w}px : ${r.larges.join(', ')}`);
}, 'BNF-12');

await suite.test('thème sombre : bascule et persistance', async () => {
  await open('#synthese');
  await js(`document.documentElement.removeAttribute('data-theme'); localStorage.removeItem('abfusion.theme'); document.getElementById('theme').click()`);
  const first = await js('document.documentElement.dataset.theme');
  await open('#synthese');
  const after = await js('document.documentElement.dataset.theme');
  if (!first || first !== after) throw new Error(`thème ${first} non conservé (${after})`);
  if (after === 'dark') await shot('theme-sombre');
  await js(`localStorage.removeItem('abfusion.theme')`);
}, 'BNF-12');

await suite.test('temps d’affichage du tableau de bord < 3 s (5 chargements)', async () => {
  const times = [];
  for (let i = 0; i < 5; i++) {
    await c.send('Network.setCacheDisabled', { cacheDisabled: true });
    const t = Date.now();
    await open('#synthese');
    times.push(Date.now() - t);
  }
  times.sort((a, b) => a - b);
  suite.metric('affichage_ms_median', times[2]);
  suite.metric('affichage_ms_max', times[4]);
  if (times[4] >= 3000) throw new Error(`max ${times[4]} ms`);
}, 'BNF-09');

c.close();
proc.kill();
await new Promise((r) => setTimeout(r, 300));
try { rmSync(profile, { recursive: true, force: true }); } catch { /* profil verrouillé sous Windows : ignoré */ }
suite.finish();
