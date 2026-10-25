<?php
declare(strict_types=1);

/**
 * Tests de contrat de l'API et tests de sécurité « boîte noire » sur un serveur démarré.
 * Usage : BASE_URL=http://127.0.0.1:8766 php tests/api.php
 */

require __DIR__ . '/lib/harness.php';

suite('api-securite');

$base = rtrim(getenv('BASE_URL') ?: 'http://127.0.0.1:8766', '/');

/** @return array{status: int, headers: array<string, string>, body: string, ms: float} */
function http(string $url, string $method = 'GET'): array
{
    $t = microtime(true);
    $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 10,
        'header' => "Accept-Encoding: identity\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    $headers = [];
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) {
            $status = (int) $m[1];
            $headers = [];
        } elseif (str_contains($h, ':')) {
            [$k, $v] = explode(':', $h, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    return ['status' => $status, 'headers' => $headers, 'body' => (string) $body, 'ms' => (microtime(true) - $t) * 1000];
}

group('Contrat de l’API');
test('les 4 jeux de la zone gold répondent en JSON valide', function () use ($base) {
    foreach (['indicateurs', 'doublons', 'qualite', 'pipeline'] as $f) {
        $r = http("$base/api.php?f=$f");
        eq(200, $r['status'], $f);
        ok(str_starts_with($r['headers']['content-type'] ?? '', 'application/json'), "$f : content-type {$r['headers']['content-type']}");
        ok(is_array(json_decode($r['body'], true)), "$f : JSON invalide");
    }
}, 'BF-19');
test('jeu inconnu ou chemin forgé : 404 sans fuite de chemin', function () use ($base) {
    foreach (['inconnu', '../../src/bootstrap', '..%2F..%2Fdata%2Flake%2Fsilver', 'indicateurs%00'] as $f) {
        $r = http("$base/api.php?f=$f");
        eq(404, $r['status'], $f);
        ok(!preg_match('#[A-Z]:\\\\|/var/www|data/lake#i', $r['body']), "$f : chemin divulgué");
    }
}, 'BNF-06');
test('API en lecture seule : méthodes d’écriture refusées (405)', function () use ($base) {
    foreach (['POST', 'PUT', 'DELETE'] as $m) {
        eq(405, http("$base/api.php?f=indicateurs", $m)['status'], $m);
    }
}, 'BNF-06');

group('En-têtes de sécurité');
test('en-têtes de durcissement sur la page et sur l’API', function () use ($base) {
    foreach (['/', '/api.php?f=qualite'] as $path) {
        $h = http($base . $path)['headers'];
        eq('nosniff', $h['x-content-type-options'] ?? null, "$path X-Content-Type-Options");
        ok(isset($h['referrer-policy']), "$path : Referrer-Policy absent");
        ok(isset($h['x-frame-options']) || str_contains($h['content-security-policy'] ?? '', 'frame-ancestors'), "$path : protection anti-clickjacking absente");
    }
}, 'BNF-05');
test('politique de sécurité du contenu (CSP) sur le tableau de bord', function () use ($base) {
    $csp = http("$base/")['headers']['content-security-policy'] ?? '';
    ok($csp !== '', 'Content-Security-Policy absente');
    ok(str_contains($csp, "default-src 'self'") && !str_contains($csp, "script-src *"), "CSP trop permissive : $csp");
}, 'BNF-05');
test('données personnelles non mises en cache partagé', function () use ($base) {
    $cc = http("$base/api.php?f=doublons")['headers']['cache-control'] ?? '';
    ok(!str_contains($cc, 'public'), "Cache-Control : $cc");
}, 'BNF-07');

group('Exposition des fichiers');
test('bases, code et configuration inaccessibles depuis le web', function () use ($base) {
    foreach (['/../data/lake/silver.sqlite', '/data/lake/silver.sqlite', '/../src/bootstrap.php', '/.env', '/../bin/seed.php', '/tests/run.php'] as $p) {
        $r = http($base . $p);
        ok($r['status'] !== 200 || !str_contains($r['body'], 'SQLite format') && !str_contains($r['body'], '<?php'), "$p exposé ({$r['status']})");
    }
}, 'BNF-06');
test('ressources statiques servies avec le bon type MIME', function () use ($base) {
    foreach (['/' => 'text/html', '/assets/js/app.js' => 'javascript', '/assets/css/app.css' => 'text/css'] as $p => $type) {
        $r = http($base . $p);
        eq(200, $r['status'], $p);
        ok(str_contains($r['headers']['content-type'] ?? '', $type), "$p : {$r['headers']['content-type']}");
    }
}, 'BNF-10');

finish();
