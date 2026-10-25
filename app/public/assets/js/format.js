/* Fonctions pures de formatage et de calcul par périmètre (testées dans tests/format.test.mjs). */
(function (root) {
  'use strict';

  // Séparateur de milliers : espace insécable simple (exigence « 1 000 »).
  const groupThousands = (s) => s.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

  function fmtInt(n) {
    if (n === null || n === undefined || Number.isNaN(n)) return '—';
    const sign = n < 0 ? '-' : '';
    return sign + groupThousands(String(Math.round(Math.abs(n))));
  }

  function fmtDec(n, digits = 1) {
    if (n === null || n === undefined || Number.isNaN(n)) return '—';
    const [i, d] = Math.abs(n).toFixed(digits).split('.');
    return (n < 0 ? '-' : '') + groupThousands(i) + (d ? ',' + d : '');
  }

  function fmtEur(n, compact = false) {
    if (n === null || n === undefined || Number.isNaN(n)) return '—';
    if (compact && Math.abs(n) >= 1e6) return fmtDec(n / 1e6, 2) + ' M€';
    if (compact && Math.abs(n) >= 1e4) return fmtDec(n / 1e3, 0) + ' k€';
    return fmtInt(n) + ' €';
  }

  function fmtPct(ratio, digits = 1) {
    if (ratio === null || ratio === undefined || !Number.isFinite(ratio)) return '—';
    return fmtDec(ratio * 100, digits) + ' %';
  }

  /** Valeur d'une ligne {AB, AP} selon le périmètre ALL | AB | AP. */
  function pick(row, scope) {
    if (!row) return 0;
    if (scope === 'AB' || scope === 'AP') return Number(row[scope] || 0);
    if (row.ALL !== undefined) return Number(row.ALL); // vue consolidée fournie (golden record)
    return Number(row.AB || 0) + Number(row.AP || 0);
  }

  /** Ratio recalculé à partir des numérateurs / dénominateurs ventilés par source. */
  function ratio(num, den, scope) {
    const d = pick(den, scope);
    return d ? pick(num, scope) / d : null;
  }

  const LABELS = {
    ACTIF: 'Actif', INACTIF: 'Inactif', RESILIE: 'Résilié', PROSPECT: 'Prospect', SUSPENDU: 'Suspendu', EN_ATTENTE: 'En attente',
    OUVERT: 'Ouvert', EN_INSTRUCTION: 'En instruction', CLOS_INDEMNISE: 'Clos / indemnisé', REFUSE: 'Refusé',
    AUTO: 'Auto', HABITATION: 'Habitation', SANTE: 'Santé', PROFESSIONNELLE: 'Professionnelle', ASSISTANCE: 'Assistance',
    PRELEVEMENT: 'Prélèvement', CARTE: 'Carte', PORTEFEUILLE: 'Portefeuille num.', VIREMENT: 'Virement', CHEQUE: 'Chèque',
    AGENCE: 'Agence', COURTIER: 'Courtier', DIGITAL: 'Digital (appli)',
    FR: 'France', BE: 'Belgique', LU: 'Luxembourg', NL: 'Pays-Bas', INCONNU: 'Indéterminé',
  };
  const label = (code) => LABELS[code] ?? code;

  const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  function fmtMonth(ym) {
    const [y, m] = String(ym).split('-');
    return m ? `${MONTHS[Number(m) - 1]} ${y.slice(2)}` : ym;
  }

  const api = { fmtInt, fmtDec, fmtEur, fmtPct, pick, ratio, label, fmtMonth };
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.F = api;
})(typeof window !== 'undefined' ? window : globalThis);
