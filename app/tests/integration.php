<?php
declare(strict_types=1);

/**
 * Tests d'intégration de la chaîne complète (sources -> topics -> bronze -> silver -> gold).
 * Prérequis : php bin/seed.php && php bin/pipeline.php
 * Chaque indicateur de la zone gold est recalculé par une requête indépendante (contre-calcul).
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/lib/harness.php';

use App\Bus;
use App\Stage\{Harmonisation, Ingestion};

suite('integration');

$gold = static fn (string $f): array => json_decode((string) file_get_contents(LAKE_DIR . "/gold/$f.json"), true);
$silver = sqlite(LAKE_DIR . '/silver.sqlite');
$src = ['ab' => sqlite(SOURCES_DIR . '/abassurance_oracle.sqlite'), 'ap' => sqlite(SOURCES_DIR . '/assureplus_sqlserver.sqlite')];
$pipeline = $gold('pipeline');
$ind = $gold('indicateurs');
$val = static fn (string $sql) => $silver->query($sql)->fetchColumn();

group('Réconciliation des volumes');
test('source = topic = bronze = silver pour les 8 tables', function () use ($pipeline, $src, $silver) {
    $e = array_column($pipeline['etapes'], 'resultat', 'code');
    $topic = array_column($e['E1'], 'messages', 'table');
    $bronze = array_column($e['E2'], 'lignes', 'topic');
    foreach (Ingestion::MAPPING as $s => $tables) {
        foreach ($tables as $table => [$entity]) {
            $n = (int) $src[$s]->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            $t = Ingestion::topic($s, $entity);
            $nSilver = (int) $silver->query("SELECT COUNT(*) FROM silver_$entity WHERE source = '" . strtoupper($s) . "'")->fetchColumn();
            eq($n, $topic[$table], "$table -> topic");
            eq($n, $bronze[$t], "$t -> bronze");
            eq($n, $nSilver, "$table -> silver");
        }
    }
}, 'CA-01');
test('aucun retard de consommation (lag Kafka = 0)', function () use ($pipeline) {
    foreach ($pipeline['topics'] as $t) {
        eq(0, $t['lag_spark_silver'], $t['topic']);
    }
}, 'CA-01');
test('identifiants unifiés uniques et préfixés par la source', function () use ($val) {
    foreach (['client', 'contrat', 'sinistre', 'paiement'] as $e) {
        eq((int) $val("SELECT COUNT(*) FROM silver_$e"), (int) $val("SELECT COUNT(DISTINCT {$e}_uid) FROM silver_$e"), "unicité $e");
        eq(0, (int) $val("SELECT COUNT(*) FROM silver_$e WHERE {$e}_uid NOT LIKE source || '-%'"), "préfixe $e");
    }
}, 'BF-06');
test('lignage : chaque ligne silver retrouve sa ligne source', function () use ($silver, $src) {
    $keys = ['client' => ['AB_CLIENT', 'AB_CLIENT_ID', 'AP_USERS', 'AP_USER_ID'], 'sinistre' => ['AB_SINISTRE', 'AB_CLAIM_ID', 'AP_CLAIMS', 'AP_SINISTRE_NUM']];
    foreach ($keys as $e => [$abT, $abK, $apT, $apK]) {
        foreach ($silver->query("SELECT source, source_id FROM silver_$e ORDER BY random() LIMIT 300") as $r) {
            [$t, $k, $db] = $r['source'] === 'AB' ? [$abT, $abK, $src['ab']] : [$apT, $apK, $src['ap']];
            $st = $db->prepare("SELECT COUNT(*) FROM $t WHERE $k = ?");
            $st->execute([$r['source_id']]);
            eq(1, (int) $st->fetchColumn(), "{$r['source']}-{$r['source_id']}");
        }
    }
}, 'BNF-08');

group('Qualité des données');
test('chaque anomalie est tracée avec sa règle', function () use ($gold, $silver) {
    $q = $gold('qualite');
    eq(array_keys(Harmonisation::RULES), array_column($q['anomalies'], 'code'), 'référentiel des règles');
    $total = array_sum(array_map(static fn ($a) => $a['AB'] + $a['AP'], $q['anomalies']));
    eq((int) $silver->query('SELECT COUNT(*) FROM dq_anomalie')->fetchColumn(), $total, 'total tracé');
}, 'CA-04');
test('dates invalides côté AbAssurance tracées comme côté AssurePlus', function () use ($src) {
    // Jeu d'essai : on rejoue une vraie ligne AB avec une date impossible, dans un bus et un silver jetables.
    $dir = sys_get_temp_dir() . '/it_bus_' . getmypid();
    Bus::reset($dir);
    $bus = new Bus($dir);
    $client = $src['ab']->query('SELECT * FROM AB_CLIENT LIMIT 1')->fetch();
    $client['AB_DATE_NAISSANCE'] = '31/02/1980';
    $contrat = $src['ab']->query("SELECT * FROM AB_CONTRAT WHERE AB_CLIENT_ID = {$client['AB_CLIENT_ID']} LIMIT 1")->fetch();
    $sinistre = $src['ab']->query('SELECT * FROM AB_SINISTRE LIMIT 1')->fetch();
    $sinistre['AB_DATE_SINISTRE'] = 'inconnue';
    $sinistre['AB_POLICY_NUMBER'] = $contrat['AB_POLICY_NUMBER'];
    foreach (['client' => $client, 'contrat' => $contrat, 'sinistre' => $sinistre] as $e => $row) {
        $bus->publish("src.ab.$e", 'k', ['after' => $row]);
    }
    $bus->flush();
    $db = sqlite($dir . '/silver.sqlite', true);
    (new Harmonisation($db))->run($bus);
    $codes = $db->query("SELECT code FROM dq_anomalie WHERE source = 'ab' AND code IN ('DQ02', 'DQ09')")->fetchAll(PDO::FETCH_COLUMN);
    $db = null;
    Bus::reset($dir);
    sort($codes);
    eq(['DQ02', 'DQ09'], $codes, 'anomalies AB tracées');
}, 'BF-08');
// Requalifié après la campagne 1 (R1-04) : 85 paiements tombaient 1 à 6 jours après la résiliation
// (dernier prélèvement, prorata). Faux positif du test ; règle métier validée par le PO : tolérance de 31 jours.
test('paiement encaissé au-delà de la tolérance après fin de contrat : toujours tracé (DQ15)', function () use ($val) {
    $tol = Harmonisation::TOLERANCE_FIN_CONTRAT_JOURS;
    $n = (int) $val("SELECT COUNT(*) FROM silver_paiement p JOIN silver_contrat c USING (contrat_uid)
        WHERE c.date_fin IS NOT NULL AND julianday(p.date_paiement) - julianday(c.date_fin) > $tol
        AND p.source_id NOT IN (SELECT source_id FROM dq_anomalie WHERE code = 'DQ15')");
    eq(0, $n, "paiements à plus de $tol jours non tracés");
}, 'BF-08');
test('jeu d’essai : un paiement 90 jours après résiliation déclenche DQ15', function () use ($src) {
    $dir = sys_get_temp_dir() . '/it_bus15_' . getmypid();
    Bus::reset($dir);
    $bus = new Bus($dir);
    $contrat = $src['ab']->query("SELECT * FROM AB_CONTRAT WHERE AB_DATE_FIN IS NOT NULL LIMIT 1")->fetch();
    $paiement = $src['ab']->query('SELECT * FROM AB_PAIEMENT LIMIT 1')->fetch();
    $paiement['AB_POLICY_NUMBER'] = $contrat['AB_POLICY_NUMBER'];
    $paiement['AB_DATE_PAIEMENT'] = date('Y-m-d', strtotime($contrat['AB_DATE_FIN'] . ' +90 days'));
    $bus->publish('src.ab.contrat', 'k', ['after' => $contrat]);
    $bus->publish('src.ab.paiement', 'k', ['after' => $paiement]);
    $bus->flush();
    $db = sqlite($dir . '/silver.sqlite', true);
    (new Harmonisation($db))->run($bus);
    $n = (int) $db->query("SELECT COUNT(*) FROM dq_anomalie WHERE code = 'DQ15'")->fetchColumn();
    $db = null;
    Bus::reset($dir);
    eq(1, $n, 'DQ15 déclenchée');
}, 'BF-08');

group('Contre-calcul des indicateurs');
test('KPI contrats : orphelins exclus, ventilation par source exacte', function () use ($ind, $val) {
    foreach (['AB', 'AP'] as $s) {
        eq((int) $val("SELECT COUNT(*) FROM silver_contrat WHERE est_orphelin = 0 AND source = '$s'"), $ind['kpis']['contrats'][$s], "contrats $s");
        eq((int) $val("SELECT COUNT(*) FROM silver_contrat WHERE est_orphelin = 0 AND statut = 'ACTIF' AND source = '$s'"), $ind['kpis']['contrats_actifs'][$s], "actifs $s");
    }
}, 'BF-13');
test('S/P par branche recalculable depuis numérateur et dénominateur', function () use ($ind, $silver) {
    $since = (new DateTimeImmutable(REF_DATE))->modify('-12 months')->format('Y-m-d');
    $num = $silver->query("SELECT c.branche, SUM(s.montant_estime) FROM silver_sinistre s JOIN silver_contrat c USING (contrat_uid)
        WHERE s.est_orphelin = 0 AND s.statut <> 'REFUSE' AND s.date_survenance >= '$since' GROUP BY 1")->fetchAll(PDO::FETCH_KEY_PAIR);
    $den = $silver->query("SELECT c.branche, SUM(p.montant) FROM silver_paiement p JOIN silver_contrat c USING (contrat_uid)
        WHERE p.est_orphelin = 0 AND p.statut = 'REUSSI' AND p.date_paiement >= '$since' GROUP BY 1")->fetchAll(PDO::FETCH_KEY_PAIR);
    $g = static fn (array $rows) => array_column(array_map(static fn ($r) => ['l' => $r['label'], 'v' => $r['AB'] + $r['AP']], $rows), 'v', 'l');
    $gn = $g($ind['finances']['sp_sinistres_12m']);
    $gd = $g($ind['finances']['sp_primes_12m']);
    foreach ($den as $b => $d) {
        ok(abs(($num[$b] ?? 0) / $d - ($gn[$b] ?? 0) / $gd[$b]) < 0.0005, "S/P $b : silver " . round(($num[$b] ?? 0) / $d, 4) . ' ≠ gold ' . round(($gn[$b] ?? 0) / $gd[$b], 4));
    }
}, 'CA-05');
test('multi-équipement consolidé compté par client unique (golden record)', function () use ($ind, $val) {
    $attendu = (int) $val("SELECT COUNT(DISTINCT k.master_uid) FROM silver_contrat c JOIN silver_client k USING (client_uid)
        WHERE c.est_orphelin = 0 AND c.statut = 'ACTIF'");
    $rows = $ind['clients']['multi_equipement'];
    $obtenu = array_sum(array_map(static fn ($r) => $r['ALL'] ?? ($r['AB'] + $r['AP']), $rows));
    eq($attendu, $obtenu, 'clients équipés en vue consolidée');
}, 'BF-12');
test('précision des fusions automatiques ≥ 98 %', function () use ($gold) {
    $ev = $gold('doublons')['evaluation']['CERTAIN'];
    ok($ev['precision'] >= 0.98, "précision {$ev['precision']}");
}, 'CA-03');

group('Protection des données (zone gold)');
test('exemples de doublons pseudonymisés (ni nom complet ni date de naissance)', function () use ($gold, $val) {
    foreach ($gold('doublons')['exemples'] as $ex) {
        foreach (['a', 'b'] as $p) {
            $nom = (string) $val("SELECT nom FROM silver_client WHERE client_uid = '{$ex[$p]['uid']}'");
            ok(!str_contains(mb_strtoupper($ex[$p]['nom']), $nom), "nom de famille en clair pour {$ex[$p]['uid']}");
            ok(!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $ex[$p]['naissance']), "date de naissance complète pour {$ex[$p]['uid']}");
        }
    }
}, 'BNF-07');

finish();
