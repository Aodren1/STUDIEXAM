<?php
declare(strict_types=1);

/**
 * Point d'accès en lecture seule à la zone gold du lac.
 * GET api.php?f=indicateurs|doublons|qualite|pipeline
 */

require_once __DIR__ . '/../src/bootstrap.php';

const ALLOWED = ['indicateurs', 'doublons', 'qualite', 'pipeline'];
/** Jeux contenant des données personnelles, même pseudonymisées (BNF-07). */
const PERSONAL = ['doublons'];

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) { // R1-09 : API en lecture seule
    header('Allow: GET, HEAD');
    json_out(['erreur' => 'Méthode non autorisée : API en lecture seule'], 405);
}
$f = (string) ($_GET['f'] ?? '');
if (!in_array($f, ALLOWED, true)) {
    json_out(['erreur' => 'Jeu de données inconnu', 'disponibles' => ALLOWED], 404);
}
$file = LAKE_DIR . "/gold/$f.json";
if (!is_file($file)) {
    json_out(['erreur' => 'Zone gold vide : exécutez bin/pipeline.php'], 503);
}
json_out(json_decode((string) file_get_contents($file), true), 200, in_array($f, PERSONAL, true));
