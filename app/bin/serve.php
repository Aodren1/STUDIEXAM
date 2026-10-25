<?php
declare(strict_types=1);

/**
 * Routeur du serveur de développement PHP, à parité avec la production (Apache) :
 *   php -S 127.0.0.1:8766 -t public bin/serve.php
 * Sans lui, les fichiers statiques étaient servis sans en-têtes de sécurité (friction de recette R1-10 :
 * les tests passaient ou échouaient selon l'environnement).
 */

require_once __DIR__ . '/../src/bootstrap.php';

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($path === '/' || $path === '') {
    $path = '/index.html';
}
$root = realpath(__DIR__ . '/../public');
$file = realpath($root . $path);

if ($file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
    security_headers();
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Ressource introuvable';
    return true;
}
if (str_ends_with($file, '.php')) {
    return false; // api.php : exécuté normalement par le serveur intégré, il envoie ses propres en-têtes
}
$types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
    'svg' => 'image/svg+xml', 'png' => 'image/png', 'json' => 'application/json', 'ico' => 'image/x-icon'];
security_headers();
header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
header('Cache-Control: ' . (str_ends_with($file, '.html') ? 'no-cache' : 'public, max-age=3600'));
readfile($file);
return true;
