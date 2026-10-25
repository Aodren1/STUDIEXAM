<?php
declare(strict_types=1);

/**
 * Socle commun : chemins, connexions et fonctions de normalisation.
 *
 * Nomenclature des zones (voir docs) :
 *   data/sources/   -> bases sources simulées (Oracle AB_*, SQL Server AP_*)
 *   data/bus/       -> journal des topics (rôle Kafka)
 *   data/lake/      -> lac de données (rôle HDFS) : bronze/, silver.sqlite, gold/
 */

const APP_ROOT = __DIR__ . '/..';
const DATA_DIR = APP_ROOT . '/data';
const SOURCES_DIR = DATA_DIR . '/sources';
const BUS_DIR = DATA_DIR . '/bus';
const LAKE_DIR = DATA_DIR . '/lake';

/**
 * Date de référence des indicateurs : date du jour en production, figée par APP_REF_DATE
 * dans les environnements de test pour que les calculs restent reproductibles.
 * (Anomalie de recette R1-08 : la date était codée en dur, les indicateurs auraient été gelés en production.)
 */
define('REF_DATE', (static function (): string {
    $env = (string) getenv('APP_REF_DATE');
    if ($env === '') {
        return date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $env)) {
        throw new InvalidArgumentException("APP_REF_DATE invalide : $env (attendu YYYY-MM-DD)");
    }
    return $env;
})());

spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/' . str_replace(['App\\', '\\'], ['', '/'], $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

function sqlite(string $path, bool $reset = false): PDO
{
    if ($reset && is_file($path)) {
        unlink($path);
    }
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA synchronous = OFF; PRAGMA foreign_keys = OFF;');
    return $pdo;
}

function out(string $msg): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $msg . PHP_EOL);
    }
}

/** Supprime accents et ponctuation, passe en majuscules. */
function norm_text(?string $s): string
{
    if ($s === null) {
        return '';
    }
    $s = trim($s);
    $map = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i', 'ñ' => 'n',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ß' => 'ss',
        'œ' => 'oe', 'æ' => 'ae',
    ];
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s) ?? '';
    return strtoupper(trim(preg_replace('/\s+/', ' ', $s) ?? ''));
}

/** Clé nom indépendante de l'ordre (gère « NOM Prénom » vs « Prénom NOM »). */
function name_key(string $fullName): string
{
    $tokens = array_filter(explode(' ', norm_text($fullName)), static fn ($t) => strlen($t) > 1);
    sort($tokens);
    return implode(' ', $tokens);
}

function norm_email(?string $email): ?string
{
    $email = strtolower(trim((string) $email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

/** Indicatifs des pays couverts (le pays est déduit du code postal). */
const DIAL_CODES = ['FR' => '33', 'BE' => '32', 'NL' => '31', 'LU' => '352'];

/**
 * Téléphone au format E.164 (+33…, +32…, +352…, +31…).
 * Un numéro national (0…) prend l'indicatif du pays du client, et non plus systématiquement +33
 * (anomalie de recette R1-01 : un mobile belge 0470… devenait +33470…).
 */
function norm_phone(?string $phone, string $country = 'FR'): ?string
{
    $digits = preg_replace('/[^0-9+]/', '', (string) $phone) ?? '';
    if ($digits === '') {
        return null;
    }
    if (str_starts_with($digits, '00')) {
        $digits = '+' . substr($digits, 2);
    }
    $national = match ($country) {
        'BE' => '/^0[1-9][0-9]{7,8}$/',
        'LU' => '/^[2-9][0-9]{5,8}$/',
        default => '/^0[1-9][0-9]{8}$/',
    };
    if (preg_match($national, $digits)) {
        return '+' . (DIAL_CODES[$country] ?? '33') . ltrim($digits, '0');
    }
    return preg_match('/^\+[1-9][0-9]{7,13}$/', $digits) ? $digits : null;
}

/**
 * Parse une date venant de champs texte hétérogènes.
 * Retourne YYYY-MM-DD (ou YYYY-MM-DD HH:MM:SS si $withTime) ou null si invalide.
 */
function parse_date(?string $raw, bool $withTime = false): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '' || str_starts_with($raw, '0000')) {
        return null;
    }
    $formats = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'Y/m/d', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y', 'Y-m-d\TH:i:s'];
    foreach ($formats as $f) {
        $d = DateTimeImmutable::createFromFormat('!' . $f, $raw);
        $errors = DateTimeImmutable::getLastErrors();
        if ($d !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            $year = (int) $d->format('Y');
            if ($year < 1900 || $year > 2100) {
                return null;
            }
            return $d->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
        }
    }
    return null;
}

/** Pays déduit du format du code postal (les sources n'ont pas de champ pays). */
function country_from_zip(?string $zip): string
{
    $zip = strtoupper(trim((string) $zip));
    return match (true) {
        (bool) preg_match('/^[0-9]{5}$/', $zip) => 'FR',
        (bool) preg_match('/^L-?[0-9]{4}$/', $zip) => 'LU',
        (bool) preg_match('/^[0-9]{4} ?[A-Z]{2}$/', $zip) => 'NL',
        (bool) preg_match('/^[0-9]{4}$/', $zip) => 'BE',
        default => 'INCONNU',
    };
}

function region_from_zip(?string $zip): ?string
{
    static $regions = null;
    if ($regions === null) {
        $regions = require __DIR__ . '/regions.php';
    }
    $zip = trim((string) $zip);
    if (!preg_match('/^[0-9]{5}$/', $zip)) {
        return null;
    }
    $dept = substr($zip, 0, 2);
    if ($dept === '20') {
        return 'Corse';
    }
    if ($dept === '97') {
        return 'Outre-mer';
    }
    return $regions[$dept] ?? null;
}

/** Politique de sécurité du contenu : tout est servi par l'application (aucun CDN, aucun script inline). */
const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
    . "connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

/**
 * En-têtes de durcissement, envoyés par l'application elle-même (anomalies de recette R1-10 / R1-11) :
 * ils ne dépendent plus de la configuration Apache, donc identiques en local, en recette et en production.
 */
function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Content-Security-Policy: ' . CSP);
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
}

/** @param bool $personal données personnelles (même pseudonymisées) : jamais en cache partagé (R1-07) */
function json_out(mixed $data, int $status = 200, bool $personal = false): never
{
    http_response_code($status);
    security_headers();
    header('Content-Type: application/json; charset=utf-8');
    header($personal ? 'Cache-Control: private, no-store' : 'Cache-Control: private, max-age=300');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
