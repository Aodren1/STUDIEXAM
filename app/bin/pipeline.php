<?php
declare(strict_types=1);

/**
 * Orchestrateur du pipeline (rôle tenu par Airflow / Talend TAC dans l'architecture cible).
 *
 *   [Oracle AB]  ─┐
 *                 ├─ 1. Ingestion (Talend) ─► topics src.* (Kafka) ─► 2. Sink bronze (HDFS)
 *   [SQL Server] ─┘                                   │
 *                                                     ▼
 *                       3. Harmonisation (Spark) ─► silver ─► 4. Dédoublonnage (Spark) ─► 5. Agrégation ─► gold ─► Dataviz
 *
 * Usage : php bin/pipeline.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Bus;
use App\Stage\{Agregation, Dedoublonnage, Harmonisation, Ingestion};

$run = ['id' => 'run-' . date('Ymd-His'), 'debut' => date('c'), 'etapes' => []];
$step = static function (string $code, string $name, string $role, callable $fn) use (&$run) {
    $t = microtime(true);
    out("▶ $name ($role)");
    $result = $fn();
    $ms = (int) round((microtime(true) - $t) * 1000);
    $run['etapes'][] = ['code' => $code, 'nom' => $name, 'role' => $role, 'duree_ms' => $ms, 'resultat' => $result];
    out("  ✓ {$ms} ms");
    return $result;
};

if (!is_file(SOURCES_DIR . '/abassurance_oracle.sqlite')) {
    fwrite(STDERR, "Sources absentes : lancez d'abord php bin/seed.php\n");
    exit(1);
}

$sources = [
    'ab' => sqlite(SOURCES_DIR . '/abassurance_oracle.sqlite'),
    'ap' => sqlite(SOURCES_DIR . '/assureplus_sqlserver.sqlite'),
];
Bus::reset(BUS_DIR);
$bus = new Bus(BUS_DIR);
foreach (glob(LAKE_DIR . '/bronze/*/*/*.gz') ?: [] as $f) {
    unlink($f);
}

$step('E1', 'Extraction des sources', 'Talend', fn () => Ingestion::extract($sources, $bus, date('c')));
$step('E2', 'Atterrissage zone bronze', 'Spark (atterrissage) → HDFS', fn () => Ingestion::sinkBronze($bus, LAKE_DIR, REF_DATE));

$silver = sqlite(LAKE_DIR . '/silver.sqlite', true);
$step('E3', 'Harmonisation vers le modèle unifié', 'Spark', fn () => (new Harmonisation($silver))->run($bus));

$truthFile = SOURCES_DIR . '/_verite_terrain.json';
$truth = is_file($truthFile) ? json_decode((string) file_get_contents($truthFile), true) : null;
$dedup = $step('E4', 'Rapprochement et dédoublonnage', 'Spark', fn () => (new Dedoublonnage($silver))->run($truth));

$step('E5', 'Agrégation des indicateurs', 'Spark SQL', fn () => (new Agregation($silver))->run(LAKE_DIR . '/gold', $dedup));

$run['fin'] = date('c');
$run['duree_totale_ms'] = array_sum(array_column($run['etapes'], 'duree_ms'));
$run['topics'] = [];
foreach (Ingestion::MAPPING as $src => $tables) {
    foreach ($tables as [$entity]) {
        $t = Ingestion::topic($src, $entity);
        $run['topics'][] = ['topic' => $t, 'partitions' => Bus::PARTITIONS, 'lag_spark_silver' => $bus->lag($t, 'spark-silver')];
    }
}
file_put_contents(LAKE_DIR . '/gold/pipeline.json', json_encode($run, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
out(sprintf('Pipeline terminé en %.1f s.', $run['duree_totale_ms'] / 1000));
