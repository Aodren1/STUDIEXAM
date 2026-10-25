// Tests des fonctions de formatage du tableau de bord : node --test tests/format.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const F = createRequire(import.meta.url)('../public/assets/js/format.js');
const NB = ' ';

test('séparateur de milliers par espace', () => {
  assert.equal(F.fmtInt(1000), `1${NB}000`);
  assert.equal(F.fmtInt(1234567), `1${NB}234${NB}567`);
  assert.equal(F.fmtInt(999), '999');
  assert.equal(F.fmtInt(-2500), `-2${NB}500`);
});

test('montants et pourcentages au format français', () => {
  assert.equal(F.fmtEur(1500), `1${NB}500${NB}€`);
  assert.equal(F.fmtEur(5650000, true), `5,65${NB}M€`);
  assert.equal(F.fmtPct(0.514), `51,4${NB}%`);
  assert.equal(F.fmtPct(null), '—');
});

test('valeur selon le périmètre', () => {
  const row = { AB: 10, AP: 5 };
  assert.equal(F.pick(row, 'ALL'), 15);
  assert.equal(F.pick(row, 'AB'), 10);
  assert.equal(F.pick(row, 'AP'), 5);
});

test('ratio recalculé depuis numérateur et dénominateur, pas moyenne de ratios', () => {
  const num = { AB: 50, AP: 80 };
  const den = { AB: 100, AP: 100 };
  assert.equal(F.ratio(num, den, 'ALL'), 0.65);
  assert.equal(F.ratio(num, { AB: 0, AP: 0 }, 'ALL'), null);
});

test('libellés unifiés', () => {
  assert.equal(F.label('CLOS_INDEMNISE'), 'Clos / indemnisé');
  assert.equal(F.fmtMonth('2026-10'), 'oct. 26');
});

test('vue consolidée fournie par la zone gold prioritaire sur la somme AB + AP (R1-05)', () => {
  const row = { AB: 10, AP: 5, ALL: 13 }; // 2 clients communs aux deux bases
  assert.equal(F.pick(row, 'ALL'), 13);
  assert.equal(F.pick(row, 'AB'), 10);
});
