<?php
declare(strict_types=1);

/**
 * Tests unitaires des règles de transformation.
 * Usage : php tests/run.php   (REPORT_FILE=… pour produire le rapport JSON)
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/lib/harness.php';

use App\Bus;
use App\Stage\{Agregation, Dedoublonnage, Harmonisation};

suite('unitaires');

group('Normalisation');
test('dates texte hétérogènes AP_BIRTH_DATE', function () {
    eq('1985-03-12', parse_date('1985-03-12'));
    eq('1985-03-12', parse_date('12/03/1985'));
    eq('1985-03-12', parse_date('1985/03/12'));
    eq(null, parse_date('0000-00-00'));
    eq(null, parse_date('N/A'));
    eq(null, parse_date('2099-13-45 25:61:00', true));
    eq('2025-04-01 14:30:00', parse_date('01/04/2025 14:30', true));
}, 'BF-07');
test('téléphone vers E.164', function () {
    eq('+33612345678', norm_phone('06 12 34 56 78'));
    eq('+33612345678', norm_phone('+33 6 12 34 56 78'));
    eq('+32470123456', norm_phone('0032470123456'));
    eq(null, norm_phone('06 12 34'));
    eq(null, norm_phone('N/A'));
}, 'BF-07');
test('e-mail en minuscules, invalide rejeté', function () {
    eq('marie.dupont@orange.fr', norm_email(' Marie.Dupont@ORANGE.fr '));
    eq(null, norm_email('marie.dupont@'));
}, 'BF-07');
test('pays et région déduits du code postal', function () {
    eq('FR', country_from_zip('75011'));
    eq('BE', country_from_zip('1000'));
    eq('LU', country_from_zip('L-1009'));
    eq('NL', country_from_zip('1012 AB'));
    eq('Île-de-France', region_from_zip('75011'));
    eq('Corse', region_from_zip('20000'));
    eq(null, region_from_zip('1000'));
}, 'BF-12');
test('clé nom indépendante de l’ordre et des accents', function () {
    eq(name_key('Hélène BERTRAND'), name_key('BERTRAND helene'));
    eq('GALL LE MARIE', name_key('Marie Le Gall'));
}, 'BF-09');

group('Harmonisation');
test('découpage AP_FULL_NAME', function () {
    eq(['DUPONT', 'Marie', false], Harmonisation::splitFullName('Marie DUPONT'));
    eq(['DUPONT', 'Marie', true], Harmonisation::splitFullName('DUPONT Marie'));
    eq(['LE GALL', 'Marie', false], Harmonisation::splitFullName('Marie LE GALL'));
    eq(['DUPONT', 'Marie', true], Harmonisation::splitFullName('marie dupont'));
}, 'BF-07');
test('abréviations d’adresse développées', function () {
    eq('12 RUE PASTEUR', Harmonisation::normAddress('12 r. Pasteur'));
    eq('3 AVENUE FOCH', Harmonisation::normAddress('3 av Foch'));
}, 'BF-07');
test('référentiels de statuts couvrant les deux sources', function () {
    eq('RESILIE', Harmonisation::STATUT_CLIENT['churned']);
    eq('EN_INSTRUCTION', Harmonisation::STATUT_SINISTRE['UNDER_REVIEW']);
    eq('EN_INSTRUCTION', Harmonisation::STATUT_SINISTRE['EN_COURS']);
    eq('ASSISTANCE', Harmonisation::BRANCHE_AP['ASSIST_PREMIUM']);
}, 'BF-07');

group('Dédoublonnage');
$base = ['nom_cle' => 'DUPONT MARIE', 'date_naissance' => '1980-01-01', 'email' => 'm@x.fr', 'telephone' => '+33600000000',
    'adresse' => '1 RUE PASTEUR', 'code_postal' => '75011'];
$score = static function (array $a, array $b): float {
    $s = 0.0;
    foreach (Dedoublonnage::compare($a, $b) as $k => $v) {
        $s += Dedoublonnage::WEIGHTS[$k] * $v;
    }
    return round($s, 3);
};
test('identité complète = certain', function () use ($base, $score) {
    eq(true, $score($base, $base) >= Dedoublonnage::SEUIL_CERTAIN);
}, 'BF-09');
test('e-mail différent, reste identique = certain', function () use ($base, $score) {
    eq(true, $score($base, ['email' => 'autre@y.fr'] + $base) >= Dedoublonnage::SEUIL_CERTAIN);
}, 'BF-09');
test('déménagement + nouvel e-mail = probable (validation humaine)', function () use ($base, $score) {
    $s = $score($base, ['email' => 'autre@y.fr', 'adresse' => '9 RUE X', 'code_postal' => '69003'] + $base);
    eq(true, $s >= Dedoublonnage::SEUIL_PROBABLE && $s < Dedoublonnage::SEUIL_CERTAIN);
}, 'BF-11');
test('homonyme dans la même ville = écarté', function () use ($base, $score) {
    $s = $score($base, ['email' => 'h@z.fr', 'telephone' => '+33611111111', 'date_naissance' => '1955-05-05', 'adresse' => '8 RUE Y'] + $base);
    eq(true, $s < Dedoublonnage::SEUIL_PROBABLE);
}, 'BF-09');
test('évaluation précision / rappel par clusters', function () {
    $r = Dedoublonnage::evaluate([['A', 'B', 'CERTAIN'], ['A', 'C', 'CERTAIN'], ['X', 'Y', 'CERTAIN']], [['A', 'B'], ['B', 'C']]);
    eq(3, $r['CERTAIN']['paires_reelles']);
    eq(2, $r['CERTAIN']['vrais_positifs']);
}, 'CA-03');

group('Bus de messages');
test('partitionnement par clé et offsets par groupe', function () {
    $dir = sys_get_temp_dir() . '/bus_test_' . getmypid();
    Bus::reset($dir);
    $bus = new Bus($dir);
    foreach (range(1, 30) as $i) {
        $bus->publish('t.test', "k$i", ['i' => $i]);
    }
    $bus->flush();
    eq(30, iterator_count($bus->consume('t.test', 'g1')));
    eq(0, iterator_count($bus->consume('t.test', 'g1')));  // déjà consommé
    eq(30, iterator_count($bus->consume('t.test', 'g2')));  // autre groupe : relit tout
    $bus->publish('t.test', 'k1', ['i' => 31]);
    $bus->flush();
    eq(1, $bus->lag('t.test', 'g1'));
    Bus::reset($dir);
}, 'BF-02');


// ---------------------------------------------------------------- campagne Bloc 2 : valeurs limites et cas métier
group('Valeurs limites (campagne de recette)');
test('date impossible (31/02) rejetée, pas convertie', function () {
    eq(null, parse_date('31/02/1980'));
    eq(null, parse_date('1980-02-30'));
}, 'BF-07');
test('date au format américain (mois > 12) rejetée', function () {
    eq(null, parse_date('12/31/1980'));
}, 'BF-07');
test('téléphone local belge converti avec l’indicatif +32', function () {
    eq('+32470123456', norm_phone('0470 12 34 56', 'BE'));
    eq('+3226421234', norm_phone('02 642 12 34', 'BE'));
    eq('+31612345678', norm_phone('06 12345678', 'NL'));
    eq('+33612345678', norm_phone('06 12 34 56 78', 'FR'));
}, 'BF-07');
test('nom AP en un seul mot = nom de famille, prénom vide', function () {
    eq(['MADONNA', '', true], Harmonisation::splitFullName('MADONNA'));
    eq(['NGUYEN', '', true], Harmonisation::splitFullName('nguyen'));
}, 'BF-07');
test('e-mail absent des deux côtés : jamais concordant', function () {
    $c = ['nom_cle' => 'A B', 'date_naissance' => null, 'email' => null, 'telephone' => null, 'adresse' => '', 'code_postal' => ''];
    $r = Dedoublonnage::compare($c, $c);
    eq([0.0, 0.0, 0.0], [$r['email'], $r['telephone'], $r['naissance']]);
}, 'BF-09');
test('masquage des e-mails dans les exemples de doublons', function () {
    $m = new ReflectionMethod(Agregation::class, 'mask');
    eq('m••••••@orange.fr', $m->invoke(null, 'marie.dupont@orange.fr'));
    eq(null, $m->invoke(null, null));
}, 'BNF-07');

group('Configuration');
test('date de référence pilotée par l’environnement (APP_REF_DATE)', function () {
    $script = tempnam(sys_get_temp_dir(), 'ref') . '.php';
    file_put_contents($script, '<?php require ' . var_export(realpath(__DIR__ . '/../src/bootstrap.php'), true) . '; echo REF_DATE;');
    $php = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    $set = static fn (string $v) => PHP_OS_FAMILY === 'Windows' ? "set \"APP_REF_DATE=$v\" && $php" : "APP_REF_DATE=$v $php";
    eq('2025-01-31', trim((string) shell_exec($set('2025-01-31'))), 'avec APP_REF_DATE');
    eq(date('Y-m-d'), trim((string) shell_exec($set(''))), 'sans APP_REF_DATE (production)');
    unlink($script);
}, 'BNF-10');

finish();
