// Équivalent JavaScript de tests/lib/harness.php : même format de rapport JSON (REPORT_FILE).
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

export class Suite {
  constructor(name) {
    this.name = name;
    this.tests = [];
    this.metrics = {};
  }

  async test(name, fn, ref = '') {
    const t = Date.now();
    let statut = 'reussi';
    let message = '';
    try {
      await fn();
    } catch (e) {
      statut = 'echec';
      message = e.message;
    }
    this.tests.push({ groupe: '', nom: name, exigence: ref, statut, duree_ms: Date.now() - t, message });
    console.log(`  ${statut === 'reussi' ? '✓' : '✗'} ${name}${message ? ` — ${message}` : ''}`);
  }

  metric(key, value) {
    this.metrics[key] = value;
  }

  finish() {
    const echecs = this.tests.filter((t) => t.statut !== 'reussi').length;
    const report = { suite: this.name, total: this.tests.length, echecs, mesures: this.metrics, tests: this.tests };
    if (process.env.REPORT_FILE) {
      mkdirSync(dirname(process.env.REPORT_FILE), { recursive: true });
      writeFileSync(process.env.REPORT_FILE, JSON.stringify(report, null, 2));
    }
    console.log(`\n${this.tests.length - echecs} tests réussis, ${echecs} en échec`);
    process.exit(echecs ? 1 : 0);
  }
}
