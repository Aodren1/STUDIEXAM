/* Tableau de bord de validation — lit la zone gold et rend chaque rubrique selon le périmètre choisi. */
(function () {
  'use strict';

  const { fmtInt, fmtDec, fmtEur, fmtPct, pick, ratio, label, fmtMonth } = window.F;
  const SRC_NAME = { AB: 'AbAssurance', AP: 'AssurePlus', ALL: 'Consolidé' };
  const state = { scope: 'ALL', tab: 'synthese', data: null, charts: [] };

  const $ = (sel, ctx = document) => ctx.querySelector(sel);
  const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const sum = (rows, scope) => rows.reduce((a, r) => a + pick(r, scope), 0);
  let uid = 0;

  // ------------------------------------------------------------------ données
  async function load() {
    const names = ['indicateurs', 'doublons', 'qualite', 'pipeline'];
    const res = await Promise.all(names.map((n) => fetch(`api.php?f=${n}`).then((r) => {
      if (!r.ok) throw new Error(`${n} : HTTP ${r.status}`);
      return r.json();
    })));
    state.data = Object.fromEntries(names.map((n, i) => [n, res[i]]));
  }

  // ------------------------------------------------------------------ séries
  /** Séries à tracer ; une rubrique qui fournit sa vue consolidée (colonne ALL, ex. golden record) n'est pas une somme AB + AP. */
  function sourcesFor(scope, rows) {
    if (scope === 'ALL' && rows?.[0]?.ALL !== undefined) return ['ALL'];
    return scope === 'ALL' ? ['AB', 'AP'] : [scope];
  }

  function color(src) {
    return src === 'AP' ? css('--series-2') : css('--series-1');
  }

  function datasets(rows, scope, opts = {}) {
    return sourcesFor(scope, rows).map((s) => ({
      label: SRC_NAME[s],
      data: rows.map((r) => (opts.value ? opts.value(r, s) : Number(r[s] || 0))),
      backgroundColor: color(s),
      borderColor: opts.line ? color(s) : css('--surface-1'),
      borderWidth: opts.line ? 2 : 1,
      borderRadius: opts.line ? 0 : 4,
      pointRadius: 0,
      pointHoverRadius: 5,
      pointHoverBorderWidth: 2,
      pointHoverBorderColor: css('--surface-1'),
      tension: 0.25,
      fill: false,
      _src: s,
    }));
  }

  // ------------------------------------------------------------------ cartes
  function legendHtml(scope, rows) {
    if (sourcesFor(scope, rows).length < 2) return '';
    return `<div class="legend" aria-hidden="true"><span><i style="background:${color('AB')}"></i>AbAssurance</span>
      <span><i style="background:${color('AP')}"></i>AssurePlus</span></div>`;
  }

  /**
   * Carte graphique : canvas + tableau alternatif (bouton « Tableau »).
   * spec = { title, sub, span, height, type, rows, opts, fmt, note }
   */
  function chartCard(spec) {
    const id = `c${++uid}`;
    spec.id = id;
    pending.push(spec);
    const table = spec.tableHtml || dataTable(spec.rows, spec.scope ?? state.scope, spec.fmt || fmtInt, spec.labelFn || label, spec.valueFn);
    return `<article class="card ${spec.span || 'span-6'}">
      <div class="card-head"><div><h3>${esc(spec.title)}</h3>${spec.sub ? `<p class="sub">${esc(spec.sub)}</p>` : ''}</div>
        <button class="toggle" data-toggle="${id}" aria-pressed="false" aria-controls="${id}-table">Tableau</button></div>
      ${spec.legend === false ? '' : legendHtml(spec.scope ?? state.scope, spec.rows)}
      <div class="chart-box ${spec.height || ''}" id="${id}-box"><canvas id="${id}" role="img" aria-label="${esc(spec.title)}"></canvas></div>
      <div class="table-wrap" id="${id}-table" hidden>${table}</div>
      ${spec.note ? `<p class="note">${spec.note}</p>` : ''}
    </article>`;
  }

  function dataTable(rows, scope, fmt, labelFn, valueFn) {
    const srcs = sourcesFor(scope, rows);
    const head = srcs.map((s) => `<th class="num">${SRC_NAME[s]}</th>`).join('') + (srcs.length > 1 ? '<th class="num">Total</th>' : '');
    const body = rows.map((r) => {
      const vals = srcs.map((s) => (valueFn ? valueFn(r, s) : Number(r[s] || 0)));
      const total = vals.reduce((a, b) => a + b, 0);
      return `<tr><td>${esc(labelFn(r.label))}</td>${vals.map((v) => `<td class="num">${fmt(v)}</td>`).join('')}${srcs.length > 1 && !valueFn ? `<td class="num">${fmt(total)}</td>` : (srcs.length > 1 ? '<td class="num">—</td>' : '')}</tr>`;
    }).join('');
    return `<table><thead><tr><th>Libellé</th>${head}</tr></thead><tbody>${body}</tbody></table>`;
  }

  function kpi(lbl, value, opts = {}) {
    const split = opts.split && state.scope === 'ALL'
      ? `<div class="split"><span><span class="dot ab"></span>${opts.split.AB}</span><span><span class="dot ap"></span>${opts.split.AP}</span></div>` : '';
    return `<article class="card kpi ${opts.span || 'span-3'}"><p class="label">${esc(lbl)}</p><p class="value">${value}</p>${split}
      ${opts.hint ? `<p class="hint">${opts.hint}</p>` : ''}</article>`;
  }

  function splitOf(obj, fmt) {
    return { AB: fmt(obj.AB), AP: fmt(obj.AP) };
  }

  let pending = [];

  function mountCharts() {
    const grid = css('--grid');
    const muted = css('--text-muted');
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.font.size = 11.5;
    Chart.defaults.color = muted;
    for (const spec of pending) {
      const canvas = document.getElementById(spec.id);
      if (!canvas) continue;
      const scope = spec.scope ?? state.scope;
      const isLine = spec.type === 'line';
      const horizontal = !!spec.horizontal;
      const fmt = spec.fmt || fmtInt;
      const labels = spec.rows.map((r) => (spec.labelFn || label)(r.label));
      const ds = spec.datasets || datasets(spec.rows, scope, { line: isLine, value: spec.valueFn });
      const valueAxis = {
        beginAtZero: true, stacked: !!spec.stacked,
        grid: { color: grid, drawTicks: false }, border: { display: false },
        ticks: { padding: 6, callback: (v) => (spec.axisFmt || spec.fmt || fmtInt)(v), maxTicksLimit: 6 },
      };
      const catAxis = {
        stacked: !!spec.stacked, grid: { display: false }, border: { color: css('--axis') },
        ticks: { autoSkip: horizontal ? false : labels.length > 14, maxRotation: 0, padding: 4, autoSkipPadding: 10 },
      };
      const chart = new Chart(canvas, {
        type: isLine ? 'line' : 'bar',
        data: { labels, datasets: ds },
        options: {
          indexAxis: horizontal ? 'y' : 'x',
          maintainAspectRatio: false,
          animation: false,
          interaction: { mode: 'index', intersect: false, axis: horizontal ? 'y' : 'x' },
          datasets: { bar: { maxBarThickness: 34, categoryPercentage: 0.72, barPercentage: 0.9 } },
          scales: horizontal ? { x: valueAxis, y: catAxis } : { x: catAxis, y: valueAxis },
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: css('--surface-1'), titleColor: css('--text-primary'), bodyColor: css('--text-secondary'),
              borderColor: css('--border'), borderWidth: 1, padding: 10, boxPadding: 4, usePointStyle: true,
              callbacks: {
                label: (ctx) => ` ${ctx.dataset.label} : ${fmt(ctx.parsed[horizontal ? 'x' : 'y'])}`,
                footer: (items) => (items.length > 1 && spec.stacked !== false && !spec.noTotal
                  ? `Total : ${fmt(items.reduce((a, i) => a + i.parsed[horizontal ? 'x' : 'y'], 0))}` : ''),
              },
            },
          },
        },
      });
      state.charts.push(chart);
    }
    pending = [];
    // La grille n'a pas toujours sa largeur définitive à la création : on recale après le premier rendu.
    requestAnimationFrame(() => state.charts.forEach((c) => c.resize()));
  }

  // ------------------------------------------------------------------ heatmap (tableau coloré, séquentiel bleu)
  const RAMP = ['--seq-100', '--seq-200', '--seq-300', '--seq-400', '--seq-500', '--seq-600', '--seq-700'];
  function heatCell(v, max, fmt = fmtInt) {
    if (!v) return `<td class="cell" style="background:var(--surface-2);color:var(--text-muted)">0</td>`;
    const idx = Math.min(RAMP.length - 1, Math.floor(Math.sqrt(v / max) * (RAMP.length - 0.001)));
    const ink = idx >= 3 ? '#fff' : '#0b0b0b';
    return `<td class="cell" style="background:var(${RAMP[idx]});color:${ink}">${fmt(v)}</td>`;
  }
  const heatLegend = `<div class="heat-legend" aria-hidden="true">moins<span class="ramp">${RAMP.map((r) => `<i style="background:var(${r})"></i>`).join('')}</span>plus</div>`;

  // ------------------------------------------------------------------ rubriques
  const views = {};

  views.synthese = (d, s) => {
    const k = d.indicateurs.kpis;
    const dd = d.doublons;
    const sp = ratio(k.charge_sinistres_12m, k.encaisse_12m, s);
    const echec = ratio(k.paiements_echoues_12m, k.paiements_12m, s);
    const pairs = Object.entries(dd.matrice_sources)
      .filter(([t]) => s === 'ALL' || t.includes(s))
      .reduce((a, [, v]) => a + v.CERTAIN + v.PROBABLE, 0);
    const steps = d.pipeline.etapes;
    return `<div class="grid">
      ${kpi('Fiches clients', fmtInt(pick(k.clients, s)), { split: splitOf(k.clients, fmtInt),
        hint: s === 'ALL' ? `soit <b>${fmtInt(dd.clients_uniques)}</b> clients uniques après dédoublonnage` : `${fmtPct(ratio(k.clients_actifs, k.clients, s))} actifs` })}
      ${kpi('Contrats actifs', fmtInt(pick(k.contrats_actifs, s)), { split: splitOf(k.contrats_actifs, fmtInt) })}
      ${kpi('Primes annualisées (portefeuille actif)', fmtEur(pick(k.primes_actives, s), true), { split: splitOf(k.primes_actives, (v) => fmtEur(v, true)) })}
      ${kpi('Encaissé sur 12 mois', fmtEur(pick(k.encaisse_12m, s), true), { split: splitOf(k.encaisse_12m, (v) => fmtEur(v, true)) })}
      ${kpi('Sinistres déclarés (12 mois)', fmtInt(pick(k.sinistres_12m, s)), { split: splitOf(k.sinistres_12m, fmtInt),
        hint: `charge estimée ${fmtEur(pick(k.charge_sinistres_12m, s), true)}` })}
      ${kpi('Ratio sinistres / primes (12 mois)', fmtPct(sp), { split: { AB: fmtPct(ratio(k.charge_sinistres_12m, k.encaisse_12m, 'AB')), AP: fmtPct(ratio(k.charge_sinistres_12m, k.encaisse_12m, 'AP')) },
        hint: 'charge estimée hors refus ÷ primes encaissées' })}
      ${kpi('Taux d’échec des paiements', fmtPct(echec, 2), { hint: s === 'AB' ? 'AB_PAIEMENT ne porte pas de statut (règle DQ13)' : 'sur 12 mois glissants' })}
      ${kpi('Paires de doublons détectées', fmtInt(pairs), { hint: s === 'ALL' ? `${fmtInt(dd.clients_communs_ab_ap)} clients communs aux deux assureurs` : 'intra-base et avec l’autre base' })}
    </div>
    <div class="grid">
      ${chartCard({ title: 'Contrats actifs par branche', sub: 'Branches unifiées (codes produits AP rattachés)', rows: d.indicateurs.contrats.par_branche, stacked: true })}
      ${chartCard({ title: 'Encaissements mensuels', sub: 'Paiements réussis, en euros', type: 'line', rows: d.indicateurs.finances.encaissements_mensuels,
        labelFn: fmtMonth, fmt: fmtEur, axisFmt: (v) => fmtEur(v, true), noTotal: s !== 'ALL' })}
    </div>
    <div class="grid"><article class="card span-12">
      <div class="card-head"><div><h3>Dernière exécution du pipeline</h3><p class="sub">${esc(d.pipeline.id)} · ${fmtDec(d.pipeline.duree_totale_ms / 1000, 1)} s au total</p></div></div>
      ${flowHtml(steps)}
    </article></div>`;
  };

  function flowHtml(steps) {
    return `<div class="flow">${steps.map((st, i) => `${i ? '<span class="arrow" aria-hidden="true">→</span>' : ''}
      <div class="step"><small>${esc(st.code)} · ${esc(st.role)}</small><b>${esc(st.nom)}</b><p class="ms">${fmtInt(st.duree_ms)} ms</p></div>`).join('')}
      <span class="arrow" aria-hidden="true">→</span><div class="step"><small>Restitution</small><b>Tableau de bord</b><p class="ms">zone gold</p></div></div>`;
  }

  views.clients = (d, s) => {
    const c = d.indicateurs.clients;
    const k = d.indicateurs.kpis;
    const horsFr = sum(c.par_pays.filter((r) => r.label !== 'FR'), s);
    const age = pick(k.age_somme, s) / (pick(k.age_effectif, s) || 1);
    const regions = c.par_region.slice(0, 14);
    return `<div class="grid">
      ${kpi('Fiches clients', fmtInt(pick(k.clients, s)), { split: splitOf(k.clients, fmtInt) })}
      ${kpi('Clients actifs', fmtPct(ratio(k.clients_actifs, k.clients, s)), { hint: `${fmtInt(pick(k.clients_actifs, s))} fiches au statut Actif` })}
      ${kpi('Âge moyen', `${fmtDec(age, 1)} ans`, { hint: 'dates de naissance exploitables uniquement' })}
      ${kpi('Clients hors de France', fmtPct(horsFr / (sum(c.par_pays, s) || 1)), { hint: 'pays déduit du format du code postal' })}
    </div>
    <div class="grid">
      ${chartCard({ title: 'Répartition par statut', rows: c.par_statut, stacked: true, span: 'span-4' })}
      ${chartCard({ title: 'Répartition par tranche d’âge', rows: c.par_tranche_age, stacked: true, span: 'span-8' })}
      ${chartCard({ title: 'Répartition par région (France)', sub: 'Région déduite du département', rows: regions, stacked: true, horizontal: true, height: 'tall', span: 'span-7' })}
      ${chartCard({ title: 'Répartition par pays', rows: c.par_pays.filter((r) => sum([r], 'ALL') > 0), stacked: true, span: 'span-5', height: 'tall' })}
      ${chartCard({ title: 'Nouveaux clients par année', type: 'line', rows: c.creations_par_annee, span: 'span-8', noTotal: s !== 'ALL' })}
      ${chartCard({ title: 'Multi-équipement', sub: 'Contrats actifs par client unique (golden record)', rows: c.multi_equipement, stacked: true, span: 'span-4' })}
      ${s === 'AB' ? `<article class="card span-12"><h3>Score de fidélité</h3><p class="empty">Indicateur propre à AssurePlus (AP_LOYALTY_SCORE) : absent du schéma AbAssurance.</p></article>`
        : chartCard({ title: 'Score de fidélité (AssurePlus)', sub: 'AP_LOYALTY_SCORE, sans équivalent côté AbAssurance : candidat à généraliser', rows: c.fidelite, scope: 'AP', span: 'span-12', height: 'short' })}
    </div>`;
  };

  views.contrats = (d, s) => {
    const c = d.indicateurs.contrats;
    const k = d.indicateurs.kpis;
    const resil = c.par_statut.find((r) => r.label === 'RESILIE');
    const produits = c.produits.filter((p) => s === 'ALL' || p.source === s);
    return `<div class="grid">
      ${kpi('Contrats (tous statuts)', fmtInt(pick(k.contrats, s)), { split: splitOf(k.contrats, fmtInt) })}
      ${kpi('Contrats actifs', fmtInt(pick(k.contrats_actifs, s)), { split: splitOf(k.contrats_actifs, fmtInt) })}
      ${kpi('Prime annuelle moyenne', fmtEur(ratio(k.primes_actives, k.contrats_actifs, s)), { hint: 'AP : prime mensuelle × 12', split: { AB: fmtEur(ratio(k.primes_actives, k.contrats_actifs, 'AB')), AP: fmtEur(ratio(k.primes_actives, k.contrats_actifs, 'AP')) } })}
      ${kpi('Taux de résiliation', fmtPct(pick(resil, s) / (pick(k.contrats, s) || 1)), { hint: 'contrats résiliés ÷ contrats totaux' })}
    </div>
    <div class="grid">
      ${chartCard({ title: 'Contrats actifs par branche', rows: c.par_branche, stacked: true, span: 'span-6' })}
      ${chartCard({ title: 'Primes annualisées par branche', sub: 'Portefeuille actif, en euros', rows: c.primes_par_branche, stacked: true, fmt: fmtEur, axisFmt: (v) => fmtEur(v, true), span: 'span-6' })}
      ${chartCard({ title: 'Contrats par statut', rows: c.par_statut, stacked: true, span: 'span-4' })}
      ${chartCard({ title: 'Canal de distribution (contrats actifs)', rows: c.par_canal, stacked: true, span: 'span-4', note: 'AB : réseau d’agences · AP : courtiers et application mobile' })}
      ${chartCard({ title: 'Souscriptions par année', type: 'line', rows: c.souscriptions_par_annee, span: 'span-4', noTotal: s !== 'ALL' })}
      <article class="card span-12"><div class="card-head"><div><h3>Détail par produit</h3><p class="sub">Contrats actifs, prime annuelle unifiée</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Source</th><th>Branche</th><th>Produit source</th><th class="num">Contrats</th><th class="num">Prime moyenne</th><th class="num">Primes annualisées</th></tr></thead>
        <tbody>${produits.map((p) => `<tr><td><span class="src ${p.source}">${p.source}</span></td><td>${label(p.branche)}</td><td><code>${esc(p.produit)}</code></td>
          <td class="num">${fmtInt(p.contrats)}</td><td class="num">${fmtEur(p.prime_moyenne)}</td><td class="num">${fmtEur(p.primes)}</td></tr>`).join('')}</tbody></table></div></article>
    </div>`;
  };

  views.sinistres = (d, s) => {
    const x = d.indicateurs.sinistres;
    const total = sum(x.par_statut, s);
    const refus = pick(x.par_statut.find((r) => r.label === 'REFUSE'), s);
    const charge = sum(x.charge_par_branche, s);
    const alertes = x.alertes_fraude.filter((a) => s !== 'AB');
    return `<div class="grid">
      ${kpi('Sinistres (depuis 2019)', fmtInt(total), { split: { AB: fmtInt(sum(x.par_statut, 'AB')), AP: fmtInt(sum(x.par_statut, 'AP')) } })}
      ${kpi('Charge estimée (hors refus)', fmtEur(charge, true), { split: { AB: fmtEur(sum(x.charge_par_branche, 'AB'), true), AP: fmtEur(sum(x.charge_par_branche, 'AP'), true) } })}
      ${kpi('Coût moyen d’un sinistre', fmtEur(charge / ((total - refus) || 1)), { hint: 'charge ÷ sinistres non refusés' })}
      ${kpi('Taux de refus', fmtPct(refus / (total || 1)), { hint: `${fmtInt(refus)} dossiers refusés` })}
    </div>
    <div class="grid">
      ${chartCard({ title: 'Sinistres déclarés par mois', sub: '36 derniers mois — dates non exploitables exclues (DQ09)', type: 'line', rows: x.mensuel, labelFn: fmtMonth, span: 'span-12', noTotal: s !== 'ALL' })}
      ${chartCard({ title: 'Sinistres par branche', rows: x.par_branche, stacked: true, span: 'span-4' })}
      ${chartCard({ title: 'Charge estimée par branche', rows: x.charge_par_branche, stacked: true, fmt: fmtEur, axisFmt: (v) => fmtEur(v, true), span: 'span-4' })}
      ${chartCard({ title: 'Sinistres par statut', rows: x.par_statut, stacked: true, span: 'span-4' })}
      ${chartCard({ title: 'Distribution des montants estimés', rows: x.tranches_montant, stacked: true, span: 'span-6', labelFn: (l) => l })}
      ${s === 'AB' ? `<article class="card span-6"><h3>Score de fraude</h3><p class="empty">AB_SINISTRE ne contient pas de score de fraude : c’est une expertise AssurePlus à étendre au portefeuille AbAssurance (cas d’usage IA n° 1).</p></article>`
        : chartCard({ title: 'Score de fraude (AssurePlus)', sub: 'AP_FRAUD_SCORE par tranche de 10 points', rows: x.fraude, scope: 'AP', span: 'span-6', labelFn: (l) => `${Number(l)}–${Number(l) + 9}` })}
      ${alertes.length ? `<article class="card span-12"><div class="card-head"><div><h3>Alertes fraude à traiter</h3><p class="sub">Score ≥ 70, dossier non clos — ${fmtInt(x.nb_alertes_fraude)} sinistres au-dessus du seuil au total</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Sinistre</th><th>Branche</th><th>Survenance</th><th class="num">Montant estimé</th><th class="num">Score</th><th>Statut</th></tr></thead>
        <tbody>${alertes.map((a) => `<tr><td><code>${esc(a.sinistre_uid)}</code></td><td>${label(a.branche)}</td><td>${esc((a.date_survenance || '—').slice(0, 10))}</td>
          <td class="num">${fmtEur(a.montant_estime)}</td><td class="num">${fmtDec(a.score_fraude, 1)}</td><td><span class="badge ${a.score_fraude >= 85 ? 'critique' : 'majeure'}">${label(a.statut)}</span></td></tr>`).join('')}</tbody></table></div></article>` : ''}
    </div>`;
  };

  views.finances = (d, s) => {
    const f = d.indicateurs.finances;
    const k = d.indicateurs.kpis;
    const spRows = f.sp_primes_12m.map((p) => {
      const sin = f.sp_sinistres_12m.find((r) => r.label === p.label) || {};
      return { label: p.label, primes: p, sinistres: sin, AB: p.AB ? (sin.AB || 0) / p.AB : 0, AP: p.AP ? (sin.AP || 0) / p.AP : 0 };
    }).filter((r) => pick(r.primes, s) > 0);
    return `<div class="grid">
      ${kpi('Primes annualisées', fmtEur(pick(k.primes_actives, s), true), { split: splitOf(k.primes_actives, (v) => fmtEur(v, true)) })}
      ${kpi('Encaissé sur 12 mois', fmtEur(pick(k.encaisse_12m, s), true), { split: splitOf(k.encaisse_12m, (v) => fmtEur(v, true)) })}
      ${kpi('Ratio S/P (12 mois)', fmtPct(ratio(k.charge_sinistres_12m, k.encaisse_12m, s)), { hint: 'seuil de rentabilité technique usuel : 70 – 75 %' })}
      ${kpi('Paiements échoués (12 mois)', fmtInt(pick(k.paiements_echoues_12m, s)), { hint: `sur ${fmtInt(pick(k.paiements_12m, s))} transactions` })}
    </div>
    <div class="grid">
      ${chartCard({ title: 'Encaissements mensuels', sub: 'Paiements réussis depuis janvier 2024', type: 'line', rows: f.encaissements_mensuels, labelFn: fmtMonth, fmt: fmtEur, axisFmt: (v) => fmtEur(v, true), span: 'span-8', noTotal: s !== 'ALL' })}
      ${chartCard({ title: 'Encaissements par moyen de paiement', sub: '12 derniers mois', rows: f.par_mode.filter((r) => pick(r, 'ALL') > 0), stacked: true, fmt: fmtEur, axisFmt: (v) => fmtEur(v, true), span: 'span-4', horizontal: true })}
      ${chartCard({ title: 'Ratio sinistres / primes par branche', sub: 'Charge estimée 12 mois ÷ primes encaissées 12 mois', rows: spRows, fmt: (v) => fmtPct(v), axisFmt: (v) => fmtPct(v, 0), span: 'span-6', stacked: false, noTotal: true,
        valueFn: (r, src) => r[src] })}
      ${s === 'AB' ? `<article class="card span-6"><h3>Taux d’échec mensuel</h3><p class="empty">Indisponible pour AbAssurance : la table AB_PAIEMENT n’enregistre que les paiements reçus (règle DQ13).</p></article>`
        : chartCard({ title: 'Taux d’échec des paiements (AssurePlus)', sub: 'AP_TRANSACTION_STATUS = FAILED, en % des transactions', type: 'line', rows: f.echecs_mensuels, scope: 'AP', labelFn: fmtMonth, fmt: (v) => `${fmtDec(v, 2)} %`, span: 'span-6', noTotal: true })}
    </div>`;
  };

  views.doublons = (d) => {
    const x = d.doublons;
    const ev = x.evaluation || {};
    const mat = x.matrice_sources;
    const maxM = Math.max(...Object.values(mat).flatMap((v) => [v.CERTAIN, v.PROBABLE]));
    const typeLabel = { 'AB-AB': 'AbAssurance × AbAssurance', 'AB-AP': 'AbAssurance × AssurePlus', 'AP-AP': 'AssurePlus × AssurePlus' };
    const cm = x.matrice_criteres;
    const maxC = Math.max(...cm.flat());
    const scoreRows = [];
    for (const r of x.distribution_scores) {
      let row = scoreRows.find((e) => e.label === r.tranche);
      if (!row) scoreRows.push(row = { label: r.tranche, CERTAIN: 0, PROBABLE: 0 });
      row[r.niveau] = r.n;
    }
    const scoreDs = [
      { label: 'Probable (validation métier)', data: scoreRows.map((r) => r.PROBABLE), backgroundColor: css('--seq-300'), borderColor: css('--surface-1'), borderWidth: 1, borderRadius: 4 },
      { label: 'Certain (fusion automatique)', data: scoreRows.map((r) => r.CERTAIN), backgroundColor: css('--seq-600'), borderColor: css('--surface-1'), borderWidth: 1, borderRadius: 4 },
    ];
    const ck = (v) => (v ? '<span class="check yes" aria-label="concordant">✓</span>' : '<span class="check no" aria-label="différent">·</span>');
    const certain = ev.CERTAIN || {};
    const all = ev['CERTAIN+PROBABLE'] || {};
    return `<div class="grid">
      ${kpi('Fiches clients analysées', fmtInt(x.clients_total), { hint: `${fmtInt(x.paires_comparees)} paires comparées au lieu de ${fmtInt(x.paires_comparees + x.comparaisons_evitees)} (blocage)` })}
      ${kpi('Clients uniques (golden records)', fmtInt(x.clients_uniques), { hint: `${fmtInt(x.clients_total - x.clients_uniques)} fiches fusionnées automatiquement` })}
      ${kpi('Clients communs AB ∩ AP', fmtInt(x.clients_communs_ab_ap), { hint: 'potentiel de multi-équipement croisé' })}
      ${kpi('Fiabilité du rapprochement', `${fmtPct(certain.precision, 1)}`, { hint: `précision des fusions automatiques · rappel ${fmtPct(certain.rappel, 1)} (${fmtPct(all.rappel, 0)} avec la file « probable »)` })}
    </div>
    <div class="grid">
      <article class="card span-5"><div class="card-head"><div><h3>Matrice des doublons par source</h3><p class="sub">Nombre de paires détectées</p></div></div>
        <div class="table-wrap"><table class="heat"><thead><tr><th></th><th class="num">Certain</th><th class="num">Probable</th><th class="num">Total</th></tr></thead>
        <tbody>${Object.entries(mat).map(([t, v]) => `<tr><th class="row" scope="row">${typeLabel[t]}</th>${heatCell(v.CERTAIN, maxM)}${heatCell(v.PROBABLE, maxM)}<td class="num"><b>${fmtInt(v.CERTAIN + v.PROBABLE)}</b></td></tr>`).join('')}</tbody></table></div>
        ${heatLegend}
        <p class="note">Seuils : certain ≥ ${fmtDec(x.seuils.certain, 2)}, probable ≥ ${fmtDec(x.seuils.probable, 2)}. Les paires « probables » alimentent une file de validation par les gestionnaires.</p></article>
      <article class="card span-7"><div class="card-head"><div><h3>Matrice des critères concordants</h3><p class="sub">Paires dont les deux critères concordent (diagonale : critère seul)</p></div></div>
        <div class="table-wrap"><table class="heat"><thead><tr><th></th>${x.criteres.map((c) => `<th class="num">${esc(c)}</th>`).join('')}</tr></thead>
        <tbody>${cm.map((row, i) => `<tr><th class="row" scope="row">${esc(x.criteres[i])}</th>${row.map((v) => heatCell(v, maxC)).join('')}</tr>`).join('')}</tbody></table></div>
        ${heatLegend}
        <p class="note">Pondération du score : ${Object.entries(x.poids).map(([k, v]) => `${k} ${fmtDec(v, 2)}`).join(' · ')}. L’e-mail seul ne suffit pas (adresses partagées, changements d’opérateur).</p></article>
      ${chartCard({ title: 'Distribution des scores de similarité', rows: scoreRows, datasets: scoreDs, stacked: true, legend: false, span: 'span-12', height: 'short',
        labelFn: (l) => `≥ ${l.replace('.', ',')}`, scope: 'ALL',
        tableHtml: `<table><thead><tr><th>Score</th><th class="num">Certain</th><th class="num">Probable</th></tr></thead><tbody>${scoreRows.map((r) => `<tr><td>≥ ${r.label.replace('.', ',')}</td><td class="num">${fmtInt(r.CERTAIN)}</td><td class="num">${fmtInt(r.PROBABLE)}</td></tr>`).join('')}</tbody></table>`,
        note: 'Bleu foncé : certain (fusion automatique) · bleu clair : probable (validation métier).' })}
      <article class="card span-12"><div class="card-head"><div><h3>Exemples de paires détectées</h3><p class="sub">Données pseudonymisées à l’affichage (RGPD)</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Type</th><th>Fiche A</th><th>Fiche B</th><th>E-mail</th><th>Tél.</th><th>Nom</th><th>Naiss.</th><th>Adresse</th><th class="num">Score</th><th>Décision</th></tr></thead>
        <tbody>${x.exemples.map((e) => `<tr><td>${e.type}</td>
          <td><code>${esc(e.a.uid)}</code><br>${esc(e.a.nom)}<br><small>${esc(e.a.email || '—')} · ${e.a.naissance ? `né(e) en ${esc(e.a.naissance)}` : 'DDN ?'}</small></td>
          <td><code>${esc(e.b.uid)}</code><br>${esc(e.b.nom)}<br><small>${esc(e.b.email || '—')} · ${e.b.naissance ? `né(e) en ${esc(e.b.naissance)}` : 'DDN ?'}</small></td>
          <td>${ck(e.criteres.email)}</td><td>${ck(e.criteres.telephone)}</td><td>${ck(e.criteres.nom)}</td><td>${ck(e.criteres.naissance)}</td><td>${ck(e.criteres.adresse)}</td>
          <td class="num">${fmtDec(e.score, 2)}</td><td><span class="badge ${e.niveau === 'CERTAIN' ? 'ok' : 'mineure'}">${e.niveau === 'CERTAIN' ? 'Fusion auto' : 'À valider'}</span></td></tr>`).join('')}</tbody></table></div></article>
    </div>`;
  };

  views.qualite = (d, s) => {
    const q = d.qualite;
    const p = d.pipeline;
    const anomalies = q.anomalies.filter((a) => pick(a, s) > 0);
    const extract = p.etapes.find((e) => e.code === 'E1').resultat;
    const bronze = p.etapes.find((e) => e.code === 'E2').resultat;
    return `<div class="grid">
      <article class="card span-12"><div class="card-head"><div><h3>Chaîne de traitement</h3><p class="sub">${esc(p.id)} · démarrée le ${esc(p.debut.slice(0, 19).replace('T', ' '))}</p></div></div>${flowHtml(p.etapes)}</article>
      <article class="card span-7"><div class="card-head"><div><h3>Règles de qualité déclenchées</h3><p class="sub">Chaque anomalie est tracée (table <code>dq_anomalie</code>) puis corrigée ou isolée</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Code</th><th>Règle</th><th>Gravité</th>${sourcesFor(s).map((x) => `<th class="num">${x}</th>`).join('')}<th>Traitement</th></tr></thead>
        <tbody>${anomalies.map((a) => `<tr><td><code>${a.code}</code></td><td>${esc(a.libelle)}</td><td><span class="badge ${a.gravite}">${a.gravite}</span></td>
          ${sourcesFor(s).map((x) => `<td class="num">${fmtInt(a[x])}</td>`).join('')}<td><small>${esc(a.action)}</small></td></tr>`).join('')}</tbody></table></div></article>
      ${chartCard({ title: 'Complétude des fiches clients', sub: '% de valeurs renseignées et valides après harmonisation', rows: q.completude_clients, fmt: (v) => `${fmtDec(v, 1)} %`, axisFmt: (v) => `${v} %`,
        horizontal: true, stacked: false, noTotal: true, span: 'span-5', height: 'tall', labelFn: (l) => l,
        note: 'N° fiscal : AB uniquement · score de fidélité : AP uniquement → 0 % attendu pour l’autre source.' })}
      <article class="card span-6"><div class="card-head"><div><h3>Topics Kafka</h3><p class="sub">Nomenclature <code>couche.source.entité</code> · ${p.topics[0].partitions} partitions</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Table source</th><th>Topic</th><th class="num">Messages</th><th class="num">Lag Spark</th></tr></thead>
        <tbody>${extract.filter((e) => s === 'ALL' || e.topic.includes(`.${s.toLowerCase()}.`)).map((e) => `<tr><td><code>${e.table}</code></td><td><code>${e.topic}</code></td>
          <td class="num">${fmtInt(e.messages)}</td><td class="num">${fmtInt(p.topics.find((t) => t.topic === e.topic).lag_spark_silver)}</td></tr>`).join('')}</tbody></table></div></article>
      <article class="card span-6"><div class="card-head"><div><h3>Zone bronze (HDFS)</h3><p class="sub">Copie brute horodatée, rejouable · JSONL compressé</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Fichier</th><th class="num">Lignes</th><th class="num">Taille</th></tr></thead>
        <tbody>${bronze.filter((e) => s === 'ALL' || e.topic.includes(`.${s.toLowerCase()}.`)).map((e) => `<tr><td><small><code>${esc(e.fichier)}</code></small></td><td class="num">${fmtInt(e.lignes)}</td>
          <td class="num">${fmtDec(e.octets / 1024, 0)} Ko</td></tr>`).join('')}</tbody></table></div></article>
    </div>`;
  };

  // ------------------------------------------------------------------ rendu
  function render() {
    state.charts.forEach((c) => c.destroy());
    state.charts = [];
    pending = [];
    const d = state.data;
    const ctx = state.scope === 'ALL' ? 'Consolidé (AbAssurance + AssurePlus)' : SRC_NAME[state.scope];
    $('#context').innerHTML = `Périmètre : <b>${ctx}</b> · date de référence ${d.indicateurs.date_reference.split('-').reverse().join('/')}
      ${state.tab === 'doublons' ? ' · <i>la détection des doublons porte toujours sur les deux bases</i>' : ''}`;
    document.querySelectorAll('[data-panel]').forEach((p) => {
      const on = p.dataset.panel === state.tab;
      p.hidden = !on;
      p.innerHTML = on ? views[state.tab](d, state.scope) : '';
    });
    mountCharts();
  }

  function setScope(scope) {
    state.scope = scope;
    document.querySelectorAll('#scope button').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.scope === scope)));
    save();
    render();
  }

  function setTab(tab) {
    state.tab = tab;
    document.querySelectorAll('.tab').forEach((b) => (b.dataset.tab === tab ? b.setAttribute('aria-current', 'page') : b.removeAttribute('aria-current')));
    history.replaceState(null, '', `#${tab}`);
    save();
    render();
  }

  function save() {
    try { localStorage.setItem('abfusion.scope', state.scope); } catch (e) { /* stockage indisponible */ }
  }

  document.addEventListener('click', (e) => {
    const scopeBtn = e.target.closest('#scope button');
    if (scopeBtn) return setScope(scopeBtn.dataset.scope);
    const tabBtn = e.target.closest('.tab');
    if (tabBtn) return setTab(tabBtn.dataset.tab);
    const tog = e.target.closest('[data-toggle]');
    if (tog) {
      const id = tog.dataset.toggle;
      const showTable = tog.getAttribute('aria-pressed') !== 'true';
      tog.setAttribute('aria-pressed', String(showTable));
      tog.textContent = showTable ? 'Graphique' : 'Tableau';
      document.getElementById(`${id}-table`).hidden = !showTable;
      document.getElementById(`${id}-box`).hidden = showTable;
    }
    if (e.target.closest('#theme')) {
      const root = document.documentElement;
      const dark = root.dataset.theme ? root.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
      root.dataset.theme = dark ? 'light' : 'dark';
      try { localStorage.setItem('abfusion.theme', root.dataset.theme); } catch (err) { /* ignoré */ }
      render();
    }
  });
  matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => state.data && render());

  (async function init() {
    try {
      const t = localStorage.getItem('abfusion.theme');
      if (t) document.documentElement.dataset.theme = t;
      const sc = localStorage.getItem('abfusion.scope');
      if (sc && SRC_NAME[sc]) state.scope = sc;
    } catch (e) { /* ignoré */ }
    const fromHash = location.hash.slice(1);
    if (views[fromHash]) state.tab = fromHash;
    try {
      await load();
      $('#loading').remove();
      setScope(state.scope);
      setTab(state.tab);
    } catch (err) {
      $('#loading').textContent = `Impossible de charger les indicateurs (${err.message}).`;
    }
  })();
})();
