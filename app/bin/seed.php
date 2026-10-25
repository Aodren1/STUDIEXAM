<?php
declare(strict_types=1);

/**
 * Génère les deux bases sources simulées, fidèles aux schémas de l'annexe :
 *   data/sources/abassurance_oracle.sqlite  (AB_CLIENT, AB_CONTRAT, AB_SINISTRE, AB_PAIEMENT)
 *   data/sources/assureplus_sqlserver.sqlite (AP_USERS, AP_CONTRACTS, AP_CLAIMS, AP_PAYMENTS)
 *
 * Volumétrie au 1/2 400e du réel (12 M et 5 M clients -> 5 000 et 2 080), même ratio.
 * Des défauts réalistes sont injectés volontairement (formats de dates texte, noms inversés,
 * doublons intra et inter-bases, orphelins, statuts hétérogènes) pour éprouver le pipeline.
 *
 * Usage : php bin/seed.php
 */

require __DIR__ . '/../src/bootstrap.php';

mt_srand(20261025);

// Facteur de volume (tests de performance) : SEED_SCALE=5 php bin/seed.php
define('SEED_SCALE', max(0.1, (float) (getenv('SEED_SCALE') ?: 1)));
define('N_AB', (int) round(5000 * SEED_SCALE));
define('N_AP', (int) round(2080 * SEED_SCALE));
const SHARED_RATE = 0.13;      // part des clients AP déjà clients AB (recouvrement des portefeuilles)
const DUP_AB_RATE = 0.02;      // doublons internes AB (ressaisie en agence)
const DUP_AP_RATE = 0.015;     // doublons internes AP (double inscription appli mobile)
const PAY_FROM = '2024-01-01'; // historique de paiements chargé

$t0 = microtime(true);

// ---------------------------------------------------------------- référentiels
$firstNames = ['Marie', 'Jean', 'Pierre', 'Sophie', 'Camille', 'Lucas', 'Léa', 'Hugo', 'Chloé', 'Louis', 'Emma', 'Gabriel',
    'Manon', 'Arthur', 'Inès', 'Jules', 'Sarah', 'Thomas', 'Julie', 'Nicolas', 'Laura', 'Antoine', 'Céline', 'Maxime',
    'Pauline', 'Alexandre', 'Élodie', 'Julien', 'Mathilde', 'François', 'Isabelle', 'Philippe', 'Nathalie', 'Stéphane',
    'Valérie', 'Olivier', 'Sandrine', 'Christophe', 'Aurélie', 'Mickaël', 'Hélène', 'Benoît', 'Anaïs', 'Raphaël',
    'Zoé', 'Noah', 'Jade', 'Adam', 'Lina', 'Yanis', 'Fatima', 'Mohamed', 'Karim', 'Amélie', 'Bastien', 'Clément',
    'Lucie', 'Romain', 'Margaux', 'Théo', 'Sven', 'Lotte', 'Pieter', 'Anke', 'Joël', 'Gaëlle', 'Hervé', 'Brigitte'];
$lastNames = ['Martin', 'Bernard', 'Dubois', 'Thomas', 'Robert', 'Richard', 'Petit', 'Durand', 'Leroy', 'Moreau',
    'Simon', 'Laurent', 'Lefèvre', 'Michel', 'Garcia', 'David', 'Bertrand', 'Roux', 'Vincent', 'Fournier', 'Morel',
    'Girard', 'André', 'Lefebvre', 'Mercier', 'Dupont', 'Lambert', 'Bonnet', 'François', 'Martinez', 'Legrand',
    'Garnier', 'Faure', 'Rousseau', 'Blanc', 'Guérin', 'Muller', 'Henry', 'Roussel', 'Nicolas', 'Perrin', 'Morin',
    'Mathieu', 'Clément', 'Gauthier', 'Dumont', 'Lopez', 'Fontaine', 'Chevalier', 'Robin', 'Masson', 'Sanchez',
    'Gérard', 'Nguyen', 'Boyer', 'Denis', 'Lemaire', 'Duval', 'Joly', 'Gautier', 'Roger', 'Roche', 'Roy', 'Noël',
    'Meyer', 'Lucas', 'Meunier', 'Jean', 'Pérez', 'Marchand', 'Dufour', 'Blanchard', 'Le Gall', 'Kerboul', 'Janssens',
    'Peeters', 'Maes', 'De Vries', 'Van Dijk', 'Schmit', 'Weber', 'Hoffmann', 'Benali', 'Haddad', 'Da Silva'];
// [code postal, ville, poids]
$places = [
    ['75011', 'Paris', 30], ['75015', 'Paris', 25], ['92100', 'Boulogne-Billancourt', 8], ['93200', 'Saint-Denis', 7],
    ['94000', 'Créteil', 6], ['78000', 'Versailles', 6], ['91000', 'Évry', 5], ['95000', 'Cergy', 5], ['77000', 'Melun', 4],
    ['13001', 'Marseille', 16], ['06000', 'Nice', 8], ['83000', 'Toulon', 6], ['84000', 'Avignon', 5],
    ['69003', 'Lyon', 15], ['38000', 'Grenoble', 6], ['42000', 'Saint-Étienne', 5], ['63000', 'Clermont-Ferrand', 5], ['74000', 'Annecy', 4],
    ['31000', 'Toulouse', 12], ['34000', 'Montpellier', 9], ['30000', 'Nîmes', 5], ['66000', 'Perpignan', 4],
    ['33000', 'Bordeaux', 11], ['87000', 'Limoges', 4], ['64000', 'Pau', 4], ['17000', 'La Rochelle', 3],
    ['44000', 'Nantes', 10], ['49000', 'Angers', 5], ['72000', 'Le Mans', 4], ['85000', 'La Roche-sur-Yon', 3],
    ['35000', 'Rennes', 8], ['29200', 'Brest', 5], ['56000', 'Vannes', 3],
    ['59000', 'Lille', 11], ['80000', 'Amiens', 4], ['62000', 'Arras', 3],
    ['67000', 'Strasbourg', 9], ['57000', 'Metz', 5], ['51100', 'Reims', 5], ['54000', 'Nancy', 4],
    ['76000', 'Rouen', 6], ['14000', 'Caen', 5], ['21000', 'Dijon', 5], ['25000', 'Besançon', 4],
    ['37000', 'Tours', 5], ['45000', 'Orléans', 5], ['20000', 'Ajaccio', 2], ['97400', 'Saint-Denis', 2],
    ['1000', 'Bruxelles', 9], ['4000', 'Liège', 4], ['5000', 'Namur', 2], ['9000', 'Gand', 3],
    ['L-1009', 'Luxembourg', 4], ['L-4002', 'Esch-sur-Alzette', 1], ['1012 AB', 'Amsterdam', 3], ['3011 AA', 'Rotterdam', 2],
];
$streets = ['rue de la République', 'avenue Jean Jaurès', 'boulevard Victor Hugo', 'rue Pasteur', 'rue du Moulin',
    'place de la Mairie', 'rue des Lilas', 'avenue de la Gare', 'chemin des Vignes', 'rue Nationale', 'allée des Tilleuls',
    'rue Gambetta', 'impasse des Roses', 'quai des Chartrons', 'rue Saint-Michel', 'avenue Foch'];
$domains = ['gmail.com', 'orange.fr', 'free.fr', 'hotmail.fr', 'yahoo.fr', 'outlook.com', 'laposte.net', 'sfr.fr', 'icloud.com', 'proton.me'];

$placeTotal = array_sum(array_column($places, 2));

function pick(array $a): mixed
{
    return $a[mt_rand(0, count($a) - 1)];
}

function chance(float $p): bool
{
    return mt_rand() / mt_getrandmax() < $p;
}

/** Tirage pondéré : ['VAL' => poids, ...]. */
function weighted(array $w): string
{
    $r = mt_rand(1, array_sum($w));
    foreach ($w as $k => $v) {
        $r -= $v;
        if ($r <= 0) {
            return (string) $k;
        }
    }
    return (string) array_key_first($w);
}

function rand_date(string $from, string $to): string
{
    $a = strtotime($from);
    $b = strtotime($to);
    return date('Y-m-d', mt_rand($a, max($a, $b)));
}

function rand_ts(string $from, string $to): string
{
    $a = strtotime($from);
    $b = strtotime($to);
    return date('Y-m-d H:i:s', mt_rand($a, max($a, $b)));
}

/** Montant log-normal autour d'une médiane, borné. */
function lognormal(float $median, float $sigma, float $min, float $max): float
{
    $u1 = max(1e-9, mt_rand() / mt_getrandmax());
    $u2 = mt_rand() / mt_getrandmax();
    $z = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    return round(min($max, max($min, $median * exp($sigma * $z))), 2);
}

function slug(string $s): string
{
    return strtolower(str_replace(' ', '', norm_text($s)));
}

function uuid4(): string
{
    $b = '';
    for ($i = 0; $i < 16; $i++) {
        $b .= chr(mt_rand(0, 255));
    }
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return strtoupper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)));
}

function new_person(): array
{
    global $firstNames, $lastNames, $places, $placeTotal, $streets, $domains;
    $prenom = pick($firstNames);
    $nom = pick($lastNames);
    $r = mt_rand(1, $placeTotal);
    foreach ($places as $p) {
        $r -= $p[2];
        if ($r <= 0) {
            break;
        }
    }
    $country = country_from_zip($p[0]);
    $mobile = match ($country) {
        'BE' => '+324' . mt_rand(70, 99) . sprintf('%06d', mt_rand(0, 999999)),
        'LU' => '+3526' . mt_rand(21, 91) . sprintf('%06d', mt_rand(0, 999999)),
        'NL' => '+316' . sprintf('%08d', mt_rand(0, 99999999)),
        default => '0' . pick(['6', '7']) . sprintf('%08d', mt_rand(0, 99999999)),
    };
    $sep = pick(['.', '', '_', '-']);
    $local = slug($prenom) . $sep . slug($nom) . (chance(0.35) ? (string) mt_rand(1, 99) : '');
    return [
        'prenom' => $prenom,
        'nom' => $nom,
        'dob' => rand_date('1940-01-01', '2006-12-31'),
        'email' => $local . '@' . pick($domains),
        'phone' => $mobile,
        'adresse' => mt_rand(1, 180) . ' ' . pick($streets),
        'cp' => $p[0],
        'ville' => $p[1],
        'fiscal' => $country === 'FR' ? (string) mt_rand(1, 3) . sprintf('%012d', mt_rand(0, 999999999999)) : null,
    ];
}

function fr_phone_spaced(string $p): string
{
    return str_starts_with($p, '0') ? trim(chunk_split($p, 2, ' ')) : $p;
}

// ---------------------------------------------------------------- schémas
@mkdir(SOURCES_DIR, 0775, true);
$ab = sqlite(SOURCES_DIR . '/abassurance_oracle.sqlite', true);
$ap = sqlite(SOURCES_DIR . '/assureplus_sqlserver.sqlite', true);

// Types Oracle conservés dans le DDL pour la traçabilité (SQLite les accepte par affinité).
$ab->exec(<<<SQL
CREATE TABLE AB_CLIENT (
  AB_CLIENT_ID NUMBER PRIMARY KEY, AB_NOM VARCHAR2(100), AB_PRENOM VARCHAR2(100), AB_DATE_NAISSANCE DATE,
  AB_EMAIL VARCHAR2(150), AB_TELEPHONE VARCHAR2(20), AB_ADRESSE VARCHAR2(255), AB_CODE_POSTAL VARCHAR2(10),
  AB_NUM_FISCAL VARCHAR2(30), AB_DATE_CREATION TIMESTAMP, AB_STATUT_CLIENT VARCHAR2(20));
CREATE TABLE AB_CONTRAT (
  AB_POLICY_NUMBER VARCHAR2(30) PRIMARY KEY, AB_CLIENT_ID NUMBER, AB_TYPE_ASSURANCE VARCHAR2(50), AB_DATE_DEBUT DATE,
  AB_DATE_FIN DATE, AB_PRIME_ANNUELLE NUMBER(10,2), AB_STATUT_CONTRAT VARCHAR2(20), AB_AGENCE_ID NUMBER);
CREATE TABLE AB_SINISTRE (
  AB_CLAIM_ID NUMBER PRIMARY KEY, AB_POLICY_NUMBER VARCHAR2(30), AB_DATE_SINISTRE TIMESTAMP, AB_MONTANT_ESTIME NUMBER(12,2),
  AB_STATUT_SINISTRE VARCHAR2(30), AB_DESCRIPTION CLOB);
CREATE TABLE AB_PAIEMENT (
  AB_PAYMENT_ID NUMBER PRIMARY KEY, AB_POLICY_NUMBER VARCHAR2(30), AB_DATE_PAIEMENT DATE, AB_MONTANT NUMBER(10,2),
  AB_MODE_PAIEMENT VARCHAR2(30));
SQL);
$ap->exec(<<<SQL
CREATE TABLE AP_USERS (
  AP_USER_ID INT PRIMARY KEY, AP_FULL_NAME NVARCHAR(200), AP_BIRTH_DATE VARCHAR(10), AP_MAIL_ADDRESS NVARCHAR(150),
  AP_PHONE_NUMBER NVARCHAR(25), AP_STREET_ADDRESS NVARCHAR(255), AP_ZIP_CODE NVARCHAR(10), AP_CREATED_AT DATETIME,
  AP_CUSTOMER_STATUS NVARCHAR(20), AP_LOYALTY_SCORE INT);
CREATE TABLE AP_CONTRACTS (
  AP_CONTRACT_REF NVARCHAR(30) PRIMARY KEY, AP_USER_ID INT, AP_PRODUCT_CODE NVARCHAR(50), AP_START_DATE DATETIME,
  AP_END_DATE DATETIME, AP_MONTHLY_PREMIUM DECIMAL(10,2), AP_CONTRACT_STATE NVARCHAR(20), AP_BROKER_CODE NVARCHAR(30));
CREATE TABLE AP_CLAIMS (
  AP_SINISTRE_NUM INT PRIMARY KEY, AP_CONTRACT_REF NVARCHAR(30), AP_INCIDENT_DATE VARCHAR(19), AP_ESTIMATED_AMOUNT DECIMAL(12,2),
  AP_CLAIM_STATUS NVARCHAR(30), AP_CLAIM_COMMENT NVARCHAR(4000), AP_FRAUD_SCORE DECIMAL(5,2));
CREATE TABLE AP_PAYMENTS (
  AP_PAYMENT_REF UNIQUEIDENTIFIER PRIMARY KEY, AP_CONTRACT_REF NVARCHAR(30), AP_PAYMENT_DATETIME DATETIME,
  AP_AMOUNT_PAID DECIMAL(10,2), AP_PAYMENT_CHANNEL NVARCHAR(30), AP_TRANSACTION_STATUS NVARCHAR(20));
SQL);

$ab->beginTransaction();
$ap->beginTransaction();

$truth = []; // paires [uid1, uid2] désignant la même personne réelle

// ---------------------------------------------------------------- AB_CLIENT
$insAbClient = $ab->prepare('INSERT INTO AB_CLIENT VALUES (?,?,?,?,?,?,?,?,?,?,?)');
$abPersons = [];
$abId = 100000;
for ($i = 0; $i < N_AB; $i++) {
    $p = new_person();
    $id = ++$abId;
    $created = rand_ts('1996-01-01', '2026-09-30');
    $statut = weighted(['ACTIF' => 78, 'INACTIF' => 9, 'RESILIE' => 10, 'PROSPECT' => 3]);
    $insAbClient->execute([$id, mb_strtoupper($p['nom']), $p['prenom'], $p['dob'],
        chance(0.04) ? null : $p['email'], (chance(0.004) ? '01 23' : fr_phone_spaced($p['phone'])), $p['adresse'], $p['cp'],
        chance(0.08) ? null : $p['fiscal'], $created, $statut]);
    $abPersons[$id] = $p + ['created' => $created, 'statut' => $statut];
}
// doublons internes AB : même personne ressaisie avec variantes
foreach (array_rand($abPersons, (int) (N_AB * DUP_AB_RATE)) as $origId) {
    $p = $abPersons[$origId];
    $id = ++$abId;
    $email = chance(0.6) ? strtoupper($p['email']) : (chance(0.5) ? $p['email'] : null);
    $phone = str_starts_with($p['phone'], '0') ? '+33' . substr($p['phone'], 1) : $p['phone'];
    $insAbClient->execute([$id, mb_strtoupper($p['nom']), chance(0.3) ? mb_strtoupper($p['prenom']) : $p['prenom'], $p['dob'],
        $email, $phone, str_replace('rue', 'r.', $p['adresse']), $p['cp'], null,
        rand_ts(substr($p['created'], 0, 10), '2026-10-01'), 'ACTIF']);
    $abPersons[$id] = $p + ['created' => $p['created'], 'statut' => 'ACTIF'];
    $truth[] = ['AB-' . $origId, 'AB-' . $id];
}

// ---------------------------------------------------------------- AP_USERS
$insApUser = $ap->prepare('INSERT INTO AP_USERS VALUES (?,?,?,?,?,?,?,?,?,?)');
$apPersons = [];
$apId = 500;
$abIds = array_keys($abPersons);
$sharedSources = array_rand(array_flip(array_slice($abIds, 0, N_AB)), (int) (N_AP * SHARED_RATE));
$sharedSources = array_flip($sharedSources);

function ap_full_name(array $p): string
{
    return match (weighted(['std' => 70, 'inv' => 18, 'low' => 7, 'nospace' => 5])) {
        'std' => $p['prenom'] . ' ' . mb_strtoupper($p['nom']),
        'inv' => mb_strtoupper($p['nom']) . ' ' . $p['prenom'],
        'low' => mb_strtolower($p['prenom'] . ' ' . $p['nom']),
        default => '  ' . $p['prenom'] . '  ' . $p['nom'] . ' ',
    };
}

function ap_birth(string $iso): string
{
    // Format attendu YYYY-MM-DD ; 7 % de saisies non conformes, 1 % de valeurs inexploitables
    $d = new DateTimeImmutable($iso);
    return match (weighted(['iso' => 92, 'fr' => 4, 'slash' => 3, 'bad' => 1])) {
        'iso' => $iso,
        'fr' => $d->format('d/m/Y'),
        'slash' => $d->format('Y/m/d'),
        default => pick(['0000-00-00', '', 'N/A', '1900-01-01']),
    };
}

function ap_phone(string $p): string
{
    if (chance(0.008)) {
        return pick(["N/A", "06 12 34", "0000000000"]);
    }
    if (str_starts_with($p, '0')) {
        return match (weighted(['e164' => 50, 'spaced' => 30, 'raw' => 20])) {
            'e164' => '+33' . substr($p, 1),
            'spaced' => '+33 ' . trim(chunk_split(substr($p, 1), 2, ' ')),
            default => $p,
        };
    }
    return $p;
}

$sharedList = array_keys($sharedSources);
for ($i = 0; $i < N_AP; $i++) {
    $id = ++$apId;
    $isShared = $i < count($sharedList);
    if ($isShared) {
        $abSrc = $sharedList[$i];
        $p = $abPersons[$abSrc];
        unset($p['created'], $p['statut']);
        if (chance(0.3)) { // adresse mail différente chez l'autre assureur
            $p['email'] = slug($p['prenom']) . '.' . slug($p['nom']) . mt_rand(100, 999) . '@' . pick($domains);
        }
        if (chance(0.15)) { // déménagement
            $p2 = new_person();
            $p['adresse'] = $p2['adresse'];
            $p['cp'] = $p2['cp'];
        }
        $truth[] = ['AB-' . $abSrc, 'AP-' . $id];
    } else {
        $p = new_person();
    }
    $created = rand_ts('2003-01-01', '2026-10-15');
    $status = weighted(['active' => 80, 'inactive' => 8, 'churned' => 9, 'lead' => 3]);
    if (chance(0.1)) {
        $status = strtoupper($status);
    }
    $insApUser->execute([$id, ap_full_name($p), ap_birth($p['dob']), chance(0.03) ? null : (chance(0.1) ? ucfirst($p['email']) : $p['email']),
        ap_phone($p['phone']), $p['adresse'], $p['cp'], $created, $status, mt_rand(0, 100)]);
    $apPersons[$id] = $p + ['created' => $created, 'statut' => strtolower($status)];
}
foreach (array_rand($apPersons, (int) (N_AP * DUP_AP_RATE)) as $origId) {
    $p = $apPersons[$origId];
    $id = ++$apId;
    $insApUser->execute([$id, ap_full_name($p), ap_birth($p['dob']), strtoupper($p['email']), ap_phone($p['phone']),
        $p['adresse'], $p['cp'], rand_ts(substr($p['created'], 0, 10), '2026-10-20'), 'active', mt_rand(0, 40)]);
    $apPersons[$id] = $p;
    $truth[] = ['AP-' . $origId, 'AP-' . $id];
}

// ---------------------------------------------------------------- AB_CONTRAT / SINISTRE / PAIEMENT
$insAbCtr = $ab->prepare('INSERT INTO AB_CONTRAT VALUES (?,?,?,?,?,?,?,?)');
$insAbClm = $ab->prepare('INSERT INTO AB_SINISTRE VALUES (?,?,?,?,?,?)');
$insAbPay = $ab->prepare('INSERT INTO AB_PAIEMENT VALUES (?,?,?,?,?)');

$abBranches = [
    'AUTO' => ['w' => 35, 'prime' => [520, 0.35, 250, 2400], 'freq' => 0.22, 'cost' => [1050, 1.1, 80, 45000]],
    'HABITATION' => ['w' => 30, 'prime' => [310, 0.35, 120, 1500], 'freq' => 0.09, 'cost' => [1400, 1.0, 100, 60000]],
    'SANTE' => ['w' => 22, 'prime' => [1150, 0.35, 360, 4200], 'freq' => 3.5, 'cost' => [150, 0.9, 15, 9000]],
    'PROFESSIONNELLE' => ['w' => 13, 'prime' => [2300, 0.55, 600, 18000], 'freq' => 0.25, 'cost' => [4200, 1.2, 200, 250000]],
];
$abDescriptions = [
    'AUTO' => ['Accrochage sur parking', 'Bris de glace pare-brise', 'Collision au carrefour', 'Vol du véhicule', 'Choc arrière dans un bouchon'],
    'HABITATION' => ['Dégât des eaux salle de bain', 'Cambriolage avec effraction', 'Incendie cuisine', 'Tempête, toiture endommagée', 'Bris de vitre'],
    'SANTE' => ['Consultation spécialiste', 'Frais dentaires', 'Hospitalisation courte', 'Optique', 'Kinésithérapie'],
    'PROFESSIONNELLE' => ['Responsabilité civile pro, réclamation client', 'Perte d’exploitation', 'Dégâts locaux professionnels', 'Vol de matériel'],
];
$policySeq = 0;
$claimSeq = 9000000;
$paySeq = 0;
$abWeights = array_map(static fn ($b) => $b['w'], $abBranches);

foreach ($abPersons as $clientId => $p) {
    $n = weighted(['1' => 55, '2' => 30, '3' => 12, '4' => 3]);
    for ($k = 0; $k < (int) $n; $k++) {
        $branche = weighted($abWeights);
        $cfg = $abBranches[$branche];
        $policy = 'ABP-' . sprintf('%08d', ++$policySeq);
        $start = rand_date(max(substr($p['created'], 0, 10), '2005-01-01'), '2026-10-01');
        $statut = weighted(['ACTIF' => 74, 'RESILIE' => 18, 'SUSPENDU' => 4, 'EN_ATTENTE' => 4]);
        if (in_array($p['statut'], ['RESILIE', 'INACTIF'], true) && chance(0.8)) {
            $statut = 'RESILIE';
        }
        $end = $statut === 'RESILIE' ? rand_date($start, REF_DATE) : (chance(0.5) ? null : date('Y-m-d', strtotime($start . ' +' . mt_rand(1, 3) . ' years')));
        if ($end !== null && $statut === 'ACTIF' && $end < REF_DATE) {
            $end = date('Y-m-d', strtotime(REF_DATE . ' +' . mt_rand(10, 360) . ' days')); // tacite reconduction
        }
        $prime = lognormal(...$cfg['prime']);
        if (chance(0.003)) {
            $prime = chance(0.5) ? null : -$prime; // valeurs aberrantes
        }
        $owner = chance(0.004) ? 999000 + mt_rand(1, 900) : $clientId; // orphelins
        $insAbCtr->execute([$policy, $owner, $branche, $start, $end, $prime, $statut, mt_rand(1, 42)]);

        // sinistres sur la période couverte (fenêtre 2019+)
        $covFrom = max($start, '2019-01-01');
        $covTo = min($end ?? REF_DATE, REF_DATE);
        $years = max(0, (strtotime($covTo) - strtotime($covFrom)) / 31557600);
        $expected = $cfg['freq'] * $years;
        $nClaims = (int) floor($expected) + (chance($expected - floor($expected)) ? 1 : 0);
        for ($c = 0; $c < $nClaims; $c++) {
            $date = rand_ts($covFrom, $covTo . ' 23:59:59');
            $ageDays = (strtotime(REF_DATE) - strtotime($date)) / 86400;
            $st = $ageDays < 30 ? weighted(['OUVERT' => 60, 'EN_COURS' => 40])
                : ($ageDays < 120 ? weighted(['EN_COURS' => 45, 'CLOS' => 45, 'REFUSE' => 10])
                : weighted(['CLOS' => 86, 'REFUSE' => 12, 'EN_COURS' => 2]));
            $insAbClm->execute([++$claimSeq, $policy, $date, lognormal(...$cfg['cost']), $st, pick($abDescriptions[$branche])]);
        }

        // paiements depuis PAY_FROM
        if ($prime === null || $prime <= 0 || $statut === 'EN_ATTENTE') {
            continue;
        }
        $mode = weighted(['PRELEVEMENT' => 70, 'CB' => 14, 'CHEQUE' => 8, 'VIREMENT' => 8]);
        $monthly = $mode === 'PRELEVEMENT' || chance(0.2);
        $from = max($start, PAY_FROM);
        $to = min($end ?? REF_DATE, REF_DATE);
        $cursor = new DateTimeImmutable(substr($from, 0, 8) . '05');
        $anniv = (int) substr($start, 5, 2);
        while ($cursor->format('Y-m-d') <= $to) {
            if ($monthly || (int) $cursor->format('n') === $anniv) {
                if (!($statut === 'SUSPENDU' && $cursor->format('Y-m') >= '2026-06')) {
                    $amount = $monthly ? round($prime / 12, 2) : $prime;
                    $insAbPay->execute([++$paySeq, $policy, $cursor->modify('+' . mt_rand(0, 4) . ' days')->format('Y-m-d'), $amount, $mode]);
                }
            }
            $cursor = $cursor->modify('+1 month');
        }
    }
}

// ---------------------------------------------------------------- AP_CONTRACTS / CLAIMS / PAYMENTS
$insApCtr = $ap->prepare('INSERT INTO AP_CONTRACTS VALUES (?,?,?,?,?,?,?,?)');
$insApClm = $ap->prepare('INSERT INTO AP_CLAIMS VALUES (?,?,?,?,?,?,?)');
$insApPay = $ap->prepare('INSERT INTO AP_PAYMENTS VALUES (?,?,?,?,?,?)');
$apProducts = [
    'AUTO_TOUS_RISQUES' => ['w' => 34, 'prime' => [68, 0.3, 30, 220], 'freq' => 0.30, 'cost' => [1600, 1.0, 90, 40000]],
    'AUTO_TIERS' => ['w' => 24, 'prime' => [36, 0.3, 18, 120], 'freq' => 0.18, 'cost' => [1100, 0.9, 80, 25000]],
    'AUTO_FLEX_KM' => ['w' => 14, 'prime' => [29, 0.35, 12, 90], 'freq' => 0.16, 'cost' => [1200, 0.9, 80, 25000]],
    'ASSIST_PREMIUM' => ['w' => 22, 'prime' => [14, 0.25, 6, 40], 'freq' => 0.40, 'cost' => [240, 0.6, 40, 3500]],
    'MRH_ESSENTIEL' => ['w' => 6, 'prime' => [19, 0.3, 9, 60], 'freq' => 0.08, 'cost' => [1700, 1.0, 100, 50000]],
];
$apComments = ['Rear-end collision, third party identified', 'Panne batterie, dépannage domicile', 'Bris de glace',
    'Crevaison autoroute A7', 'Vol de véhicule déclaré via appli', 'Accident sans tiers', 'Remorquage suite panne moteur',
    'Dégât des eaux', 'Claim declared through mobile app with photos', 'Collision stationnement'];
$contractSeq = 0;
$claimNum = 70000;
$apWeights = array_map(static fn ($b) => $b['w'], $apProducts);

foreach ($apPersons as $userId => $p) {
    $n = weighted(['1' => 68, '2' => 26, '3' => 6]);
    for ($k = 0; $k < (int) $n; $k++) {
        $product = weighted($apWeights);
        $cfg = $apProducts[$product];
        $start = rand_date(max(substr($p['created'] ?? '2003-01-01', 0, 10), '2008-01-01'), '2026-10-10');
        $ref = 'AP-' . substr($start, 0, 4) . '-' . sprintf('%06d', ++$contractSeq);
        $state = weighted(['Active' => 76, 'Cancelled' => 17, 'Suspended' => 3, 'Pending' => 4]);
        if (in_array($p['statut'] ?? '', ['churned', 'inactive'], true) && chance(0.8)) {
            $state = 'Cancelled';
        }
        $stateRaw = chance(0.12) ? strtoupper($state) : $state;
        $end = $state === 'Cancelled' ? rand_ts($start, REF_DATE) : date('Y-m-d 00:00:00', strtotime($start . ' +1 year'));
        if ($state !== 'Cancelled' && $end < REF_DATE) {
            $end = date('Y-m-d 00:00:00', strtotime(REF_DATE . ' +' . mt_rand(5, 360) . ' days'));
        }
        $premium = lognormal(...$cfg['prime']);
        $user = chance(0.004) ? 99000 + mt_rand(1, 900) : $userId;
        $insApCtr->execute([$ref, $user, $product, $start . ' 00:00:00', $end, $premium, $stateRaw, chance(0.4) ? null : 'BRK-' . sprintf('%03d', mt_rand(1, 60))]);

        $covFrom = max($start, '2019-01-01');
        $covTo = min(substr($end, 0, 10), REF_DATE);
        $years = max(0, (strtotime($covTo) - strtotime($covFrom)) / 31557600);
        $expected = $cfg['freq'] * $years;
        $nClaims = (int) floor($expected) + (chance($expected - floor($expected)) ? 1 : 0);
        for ($c = 0; $c < $nClaims; $c++) {
            $ts = rand_ts($covFrom, $covTo . ' 23:59:59');
            $incident = match (weighted(['iso' => 93, 'fr' => 5, 'bad' => 2])) {
                'iso' => $ts,
                'fr' => date('d/m/Y H:i', strtotime($ts)),
                default => pick(['', 'unknown', '2099-13-45 25:61:00']),
            };
            $ageDays = (strtotime(REF_DATE) - strtotime($ts)) / 86400;
            $st = $ageDays < 20 ? weighted(['OPEN' => 50, 'UNDER_REVIEW' => 50])
                : ($ageDays < 90 ? weighted(['UNDER_REVIEW' => 35, 'SETTLED' => 55, 'REJECTED' => 10])
                : weighted(['SETTLED' => 85, 'REJECTED' => 13, 'UNDER_REVIEW' => 2]));
            $fraud = $st === 'REJECTED' ? lognormal(55, 0.35, 5, 99) : lognormal(12, 0.8, 0, 98);
            $insApClm->execute([++$claimNum, chance(0.005) ? 'AP-0000-' . sprintf('%06d', mt_rand(1, 999999)) : $ref, $incident,
                lognormal(...$cfg['cost']), $st, pick($apComments), round($fraud, 2)]);
        }

        if ($state === 'Pending') {
            continue;
        }
        $channel = weighted(['SEPA_DD' => 62, 'CARD' => 24, 'APPLE_PAY' => 8, 'PAYPAL' => 6]);
        $from = max($start, PAY_FROM);
        $to = min(substr($end, 0, 10), REF_DATE);
        $cursor = new DateTimeImmutable(substr($from, 0, 8) . '01');
        while ($cursor->format('Y-m-d') <= $to) {
            $status = weighted(['SUCCEEDED' => 94, 'FAILED' => 4, 'REFUNDED' => 1, 'PENDING' => 1]);
            $insApPay->execute([uuid4(), $ref, $cursor->format('Y-m-d') . sprintf(' %02d:%02d:%02d', mt_rand(0, 23), mt_rand(0, 59), mt_rand(0, 59)),
                $premium, $channel, $status]);
            if ($status === 'FAILED' && chance(0.7)) { // nouvelle présentation
                $insApPay->execute([uuid4(), $ref, $cursor->modify('+7 days')->format('Y-m-d 10:00:00'), $premium, $channel, 'SUCCEEDED']);
            }
            $cursor = $cursor->modify('+1 month');
        }
    }
}

$ab->commit();
$ap->commit();

file_put_contents(SOURCES_DIR . '/_verite_terrain.json', json_encode($truth, JSON_PRETTY_PRINT));

foreach (['AB' => [$ab, ['AB_CLIENT', 'AB_CONTRAT', 'AB_SINISTRE', 'AB_PAIEMENT']], 'AP' => [$ap, ['AP_USERS', 'AP_CONTRACTS', 'AP_CLAIMS', 'AP_PAYMENTS']]] as $src => [$pdo, $tables]) {
    foreach ($tables as $t) {
        out(sprintf('  %-14s %8s lignes', $t, number_format((int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(), 0, ',', ' ')));
    }
}
out(sprintf('Sources générées en %.1f s, %d paires de doublons de référence.', microtime(true) - $t0, count($truth)));
