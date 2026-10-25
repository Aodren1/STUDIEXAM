<?php
declare(strict_types=1);

/**
 * Mini-framework de test sans dépendance, partagé par les suites PHP.
 * Chaque test peut être rattaché à une exigence (BF-xx, BNF-xx, CA-xx) : le rapport JSON
 * sert ensuite de matrice de traçabilité exigence -> test -> résultat.
 *
 * Variables d'environnement :
 *   REPORT_FILE  chemin du rapport JSON à écrire (facultatif)
 */

$GLOBALS['__suite'] = ['nom' => 'php', 'groupe' => '', 'tests' => []];

function suite(string $name): void
{
    $GLOBALS['__suite']['nom'] = $name;
}

function group(string $name): void
{
    $GLOBALS['__suite']['groupe'] = $name;
    fwrite(STDOUT, $name . PHP_EOL);
}

function test(string $name, callable $fn, string $ref = ''): void
{
    $t = microtime(true);
    $status = 'reussi';
    $message = '';
    try {
        $fn();
    } catch (Throwable $e) {
        $status = 'echec';
        $message = $e->getMessage();
    }
    $GLOBALS['__suite']['tests'][] = [
        'groupe' => $GLOBALS['__suite']['groupe'],
        'nom' => $name,
        'exigence' => $ref,
        'statut' => $status,
        'duree_ms' => (int) round((microtime(true) - $t) * 1000),
        'message' => $message,
    ];
    fwrite(STDOUT, ($status === 'reussi' ? '  ✓ ' : '  ✗ ') . $name . ($message ? " — $message" : '') . PHP_EOL);
}

function eq(mixed $expected, mixed $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($what ? "$what : " : '') . 'attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true));
    }
}

function ok(bool $cond, string $message): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
}

/** Écrit le rapport, affiche le bilan et sort avec le bon code retour. */
function finish(): never
{
    $tests = $GLOBALS['__suite']['tests'];
    $failed = array_filter($tests, static fn ($t) => $t['statut'] !== 'reussi');
    $report = ['suite' => $GLOBALS['__suite']['nom'], 'total' => count($tests), 'echecs' => count($failed), 'tests' => $tests];
    if ($file = getenv('REPORT_FILE')) {
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    fwrite(STDOUT, PHP_EOL . sprintf('%d tests réussis, %d en échec', count($tests) - count($failed), count($failed)) . PHP_EOL);
    exit($failed ? 1 : 0);
}
