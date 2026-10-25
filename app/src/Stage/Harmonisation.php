<?php
declare(strict_types=1);

namespace App\Stage;

use App\Bus;
use PDO;

/**
 * Étape 2 — rôle Spark (job de transformation bronze -> silver).
 *
 * Consomme les topics src.* et charge le modèle unifié (zone silver) :
 *   silver_client, silver_contrat, silver_sinistre, silver_paiement
 * Chaque règle de qualité déclenchée est tracée dans dq_anomalie (code DQxx).
 * Les identifiants sont préfixés par la source (AB-…, AP-…) : unicité garantie
 * sans perte du lien vers la ligne d'origine (colonne source_id).
 */
final class Harmonisation
{
    /** code => [libellé, gravité, action appliquée] */
    public const RULES = [
        'DQ01' => ['Date de naissance hors format YYYY-MM-DD', 'mineure', 'Convertie au format ISO'],
        'DQ02' => ['Date de naissance inexploitable', 'majeure', 'Mise à NULL, exclue des calculs d’âge'],
        'DQ03' => ['E-mail absent ou invalide', 'mineure', 'Mis à NULL'],
        'DQ04' => ['Nom complet saisi « NOM Prénom » ou sans casse', 'info', 'Découpé en nom / prénom'],
        'DQ05' => ['Statut hors référentiel (casse, libellé)', 'info', 'Mappé sur le référentiel unifié'],
        'DQ06' => ['Contrat orphelin (client inconnu)', 'critique', 'Conservé, isolé des statistiques clients'],
        'DQ07' => ['Prime nulle ou négative', 'majeure', 'Exclue des indicateurs financiers'],
        'DQ08' => ['Date de sinistre hors format', 'mineure', 'Convertie au format ISO'],
        'DQ09' => ['Date de sinistre inexploitable', 'majeure', 'Mise à NULL, exclue des séries temporelles'],
        'DQ10' => ['Sinistre orphelin (contrat inconnu)', 'critique', 'Conservé, isolé des ratios S/P'],
        'DQ11' => ['Numéro fiscal absent', 'mineure', 'Aucune (clé de rapprochement indisponible)'],
        'DQ12' => ['Téléphone non normalisable E.164', 'mineure', 'Mis à NULL'],
        'DQ13' => ['Paiement sans statut de transaction (source AB)', 'info', 'Réputé encaissé (REUSSI)'],
        'DQ14' => ['Paiement orphelin (contrat inconnu)', 'critique', 'Conservé, isolé des encaissements'],
        'DQ15' => ['Paiement encaissé plus de 31 jours après la fin du contrat', 'majeure', 'Conservé, signalé au contrôle de gestion'],
    ];

    /** Tolérance de régularisation après résiliation (dernier prélèvement, prorata) validée avec le PO en recette. */
    public const TOLERANCE_FIN_CONTRAT_JOURS = 31;

    public const STATUT_CLIENT = ['ACTIF' => 'ACTIF', 'INACTIF' => 'INACTIF', 'RESILIE' => 'RESILIE', 'PROSPECT' => 'PROSPECT',
        'active' => 'ACTIF', 'inactive' => 'INACTIF', 'churned' => 'RESILIE', 'lead' => 'PROSPECT'];
    public const STATUT_CONTRAT = ['ACTIF' => 'ACTIF', 'RESILIE' => 'RESILIE', 'SUSPENDU' => 'SUSPENDU', 'EN_ATTENTE' => 'EN_ATTENTE',
        'active' => 'ACTIF', 'cancelled' => 'RESILIE', 'suspended' => 'SUSPENDU', 'pending' => 'EN_ATTENTE'];
    public const STATUT_SINISTRE = ['OUVERT' => 'OUVERT', 'EN_COURS' => 'EN_INSTRUCTION', 'CLOS' => 'CLOS_INDEMNISE', 'REFUSE' => 'REFUSE',
        'OPEN' => 'OUVERT', 'UNDER_REVIEW' => 'EN_INSTRUCTION', 'SETTLED' => 'CLOS_INDEMNISE', 'REJECTED' => 'REFUSE'];
    public const BRANCHE_AP = ['AUTO_TOUS_RISQUES' => 'AUTO', 'AUTO_TIERS' => 'AUTO', 'AUTO_FLEX_KM' => 'AUTO',
        'ASSIST_PREMIUM' => 'ASSISTANCE', 'MRH_ESSENTIEL' => 'HABITATION'];
    public const MODE_PAIEMENT = ['PRELEVEMENT' => 'PRELEVEMENT', 'CB' => 'CARTE', 'CHEQUE' => 'CHEQUE', 'VIREMENT' => 'VIREMENT',
        'SEPA_DD' => 'PRELEVEMENT', 'CARD' => 'CARTE', 'APPLE_PAY' => 'PORTEFEUILLE', 'PAYPAL' => 'PORTEFEUILLE'];
    public const STATUT_PAIEMENT = ['SUCCEEDED' => 'REUSSI', 'FAILED' => 'ECHOUE', 'REFUNDED' => 'REMBOURSE', 'PENDING' => 'EN_ATTENTE'];

    private PDO $db;
    private \PDOStatement $dq;
    private array $dqCount = [];

    public function __construct(PDO $silver)
    {
        $this->db = $silver;
        $this->createSchema();
        $this->dq = $this->db->prepare('INSERT INTO dq_anomalie (code, source, entite, source_id, valeur) VALUES (?,?,?,?,?)');
    }

    private function createSchema(): void
    {
        $this->db->exec(<<<SQL
        CREATE TABLE silver_client (
          client_uid TEXT PRIMARY KEY, source TEXT NOT NULL, source_id TEXT NOT NULL,
          nom TEXT, prenom TEXT, nom_cle TEXT, date_naissance TEXT, email TEXT, telephone TEXT,
          adresse TEXT, code_postal TEXT, pays TEXT, region TEXT, date_creation TEXT, statut TEXT,
          num_fiscal TEXT, score_fidelite INTEGER, master_uid TEXT);
        CREATE TABLE silver_contrat (
          contrat_uid TEXT PRIMARY KEY, source TEXT NOT NULL, source_id TEXT NOT NULL, client_uid TEXT,
          branche TEXT, produit_source TEXT, date_debut TEXT, date_fin TEXT, prime_annuelle REAL,
          statut TEXT, canal TEXT, code_distributeur TEXT, est_orphelin INTEGER DEFAULT 0);
        CREATE TABLE silver_sinistre (
          sinistre_uid TEXT PRIMARY KEY, source TEXT NOT NULL, source_id TEXT NOT NULL, contrat_uid TEXT,
          date_survenance TEXT, montant_estime REAL, statut TEXT, score_fraude REAL, libelle TEXT, est_orphelin INTEGER DEFAULT 0);
        CREATE TABLE silver_paiement (
          paiement_uid TEXT PRIMARY KEY, source TEXT NOT NULL, source_id TEXT NOT NULL, contrat_uid TEXT,
          date_paiement TEXT, montant REAL, mode TEXT, statut TEXT, est_orphelin INTEGER DEFAULT 0);
        CREATE TABLE dq_anomalie (code TEXT, source TEXT, entite TEXT, source_id TEXT, valeur TEXT);
        SQL);
    }

    private function flag(string $code, string $src, string $entity, string $id, mixed $value = null): void
    {
        $this->dq->execute([$code, $src, $entity, $id, $value === null ? null : mb_substr((string) $value, 0, 120)]);
        $this->dqCount[$code] = ($this->dqCount[$code] ?? 0) + 1;
    }

    public function run(Bus $bus): array
    {
        $stats = [];
        $this->db->beginTransaction();
        foreach (['client', 'contrat', 'sinistre', 'paiement'] as $entity) {
            foreach (['ab', 'ap'] as $src) {
                $topic = Ingestion::topic($src, $entity);
                $n = 0;
                foreach ($bus->consume($topic, 'spark-silver') as $msg) {
                    $this->{$entity}($src, $msg['v']['after']);
                    $n++;
                }
                $stats[] = ['topic' => $topic, 'table' => "silver_$entity", 'lignes' => $n];
            }
        }
        $this->db->exec('CREATE INDEX ix_ctr_client ON silver_contrat(client_uid);
            CREATE INDEX ix_sin_ctr ON silver_sinistre(contrat_uid);
            CREATE INDEX ix_pay_ctr ON silver_paiement(contrat_uid);
            CREATE INDEX ix_pay_date ON silver_paiement(date_paiement);');
        $this->flagOrphans();
        $this->db->commit();
        ksort($this->dqCount);
        return ['tables' => $stats, 'anomalies' => $this->dqCount];
    }

    // ------------------------------------------------------------------ clients
    private ?\PDOStatement $insClient = null;

    private function client(string $src, array $r): void
    {
        $this->insClient ??= $this->db->prepare('INSERT INTO silver_client VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL)');
        if ($src === 'ab') {
            $id = (string) $r['AB_CLIENT_ID'];
            $nom = mb_strtoupper(trim((string) $r['AB_NOM']));
            $prenom = mb_convert_case(trim((string) $r['AB_PRENOM']), MB_CASE_TITLE);
            $dob = parse_date($r['AB_DATE_NAISSANCE']);
            if ($dob === null && $r['AB_DATE_NAISSANCE'] !== null) {
                $this->flag('DQ02', $src, 'client', $id, $r['AB_DATE_NAISSANCE']);
            }
            $rawEmail = $r['AB_EMAIL'];
            $rawPhone = $r['AB_TELEPHONE'];
            $adresse = $r['AB_ADRESSE'];
            $cp = $r['AB_CODE_POSTAL'];
            $created = substr((string) $r['AB_DATE_CREATION'], 0, 19);
            $rawStatut = (string) $r['AB_STATUT_CLIENT'];
            $fiscal = $r['AB_NUM_FISCAL'];
            $fidelite = null;
            if ($fiscal === null && country_from_zip($cp) === 'FR') {
                $this->flag('DQ11', $src, 'client', $id);
            }
        } else {
            $id = (string) $r['AP_USER_ID'];
            [$nom, $prenom, $reordered] = self::splitFullName((string) $r['AP_FULL_NAME']);
            if ($reordered) {
                $this->flag('DQ04', $src, 'client', $id, $r['AP_FULL_NAME']);
            }
            $rawDob = trim((string) $r['AP_BIRTH_DATE']);
            $dob = parse_date($rawDob);
            if ($dob === null || $dob === '1900-01-01') {
                $dob = null;
                $this->flag('DQ02', $src, 'client', $id, $rawDob);
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDob)) {
                $this->flag('DQ01', $src, 'client', $id, $rawDob);
            }
            $rawEmail = $r['AP_MAIL_ADDRESS'];
            $rawPhone = $r['AP_PHONE_NUMBER'];
            $adresse = $r['AP_STREET_ADDRESS'];
            $cp = $r['AP_ZIP_CODE'];
            $created = substr((string) $r['AP_CREATED_AT'], 0, 19);
            $rawStatut = (string) $r['AP_CUSTOMER_STATUS'];
            $fiscal = null;
            $fidelite = $r['AP_LOYALTY_SCORE'] === null ? null : (int) $r['AP_LOYALTY_SCORE'];
        }
        $email = norm_email($rawEmail);
        if ($email === null) {
            $this->flag('DQ03', $src, 'client', $id, $rawEmail);
        }
        $phone = norm_phone($rawPhone, country_from_zip($cp));
        if ($phone === null) {
            $this->flag('DQ12', $src, 'client', $id, $rawPhone);
        }
        $statut = self::STATUT_CLIENT[$rawStatut] ?? self::STATUT_CLIENT[strtolower($rawStatut)] ?? 'INCONNU';
        if ($src === 'ap' && $rawStatut !== strtolower($rawStatut)) {
            $this->flag('DQ05', $src, 'client', $id, $rawStatut);
        }
        $this->insClient->execute([strtoupper($src) . '-' . $id, strtoupper($src), $id, $nom, $prenom, name_key("$prenom $nom"),
            $dob, $email, $phone, self::normAddress($adresse), trim((string) $cp), country_from_zip($cp), region_from_zip($cp),
            $created, $statut, $fiscal, $fidelite]);
    }

    /**
     * Découpe AP_FULL_NAME : les mots entièrement en capitales sont le nom de famille ;
     * sans indice de casse, on suppose l'ordre « Prénom Nom ».
     * @return array{0: string, 1: string, 2: bool} [nom, prénom, nécessitait une correction]
     */
    public static function splitFullName(string $full): array
    {
        $tokens = preg_split('/\s+/', trim($full)) ?: [];
        if (count($tokens) === 1) { // un seul mot : c'est le nom de famille, le prénom reste inconnu
            return [mb_strtoupper($tokens[0]), '', true];
        }
        $upper = array_filter($tokens, static fn ($t) => mb_strlen($t) > 1 && $t === mb_strtoupper($t) && $t !== mb_strtolower($t));
        if ($upper && count($upper) < count($tokens)) {
            $nom = implode(' ', $upper);
            $prenom = implode(' ', array_diff_key($tokens, $upper));
            $reordered = array_key_first($upper) === 0;
        } else {
            $prenom = (string) array_shift($tokens);
            $nom = implode(' ', $tokens);
            $reordered = true; // aucune casse exploitable : découpage par position
        }
        return [mb_strtoupper($nom), mb_convert_case(mb_strtolower($prenom), MB_CASE_TITLE), $reordered];
    }

    public static function normAddress(?string $a): string
    {
        $a = ' ' . norm_text($a) . ' ';
        $a = strtr($a, [' R ' => ' RUE ', ' AV ' => ' AVENUE ', ' BD ' => ' BOULEVARD ', ' PL ' => ' PLACE ', ' CH ' => ' CHEMIN ']);
        return trim($a);
    }

    // ------------------------------------------------------------------ contrats
    private ?\PDOStatement $insContrat = null;

    private function contrat(string $src, array $r): void
    {
        $this->insContrat ??= $this->db->prepare('INSERT INTO silver_contrat VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0)');
        if ($src === 'ab') {
            $id = (string) $r['AB_POLICY_NUMBER'];
            $client = 'AB-' . $r['AB_CLIENT_ID'];
            $branche = (string) $r['AB_TYPE_ASSURANCE'];
            $produit = $branche;
            $prime = $r['AB_PRIME_ANNUELLE'] === null ? null : (float) $r['AB_PRIME_ANNUELLE'];
            $rawStatut = (string) $r['AB_STATUT_CONTRAT'];
            $debut = parse_date($r['AB_DATE_DEBUT']);
            $fin = parse_date($r['AB_DATE_FIN']);
            $canal = 'AGENCE';
            $code = 'AG-' . str_pad((string) $r['AB_AGENCE_ID'], 3, '0', STR_PAD_LEFT);
        } else {
            $id = (string) $r['AP_CONTRACT_REF'];
            $client = 'AP-' . $r['AP_USER_ID'];
            $produit = (string) $r['AP_PRODUCT_CODE'];
            $branche = self::BRANCHE_AP[$produit] ?? 'AUTRE';
            // Prime mensuelle -> annuelle : unité commune du modèle unifié
            $prime = $r['AP_MONTHLY_PREMIUM'] === null ? null : round((float) $r['AP_MONTHLY_PREMIUM'] * 12, 2);
            $rawStatut = (string) $r['AP_CONTRACT_STATE'];
            if ($rawStatut !== ucfirst(strtolower($rawStatut))) {
                $this->flag('DQ05', $src, 'contrat', $id, $rawStatut);
            }
            $debut = parse_date($r['AP_START_DATE']);
            $fin = parse_date($r['AP_END_DATE']);
            $canal = $r['AP_BROKER_CODE'] ? 'COURTIER' : 'DIGITAL';
            $code = $r['AP_BROKER_CODE'] ?: 'APP-MOBILE';
        }
        if ($prime === null || $prime <= 0) {
            $this->flag('DQ07', $src, 'contrat', $id, $prime);
            $prime = null;
        }
        $statut = self::STATUT_CONTRAT[$rawStatut] ?? self::STATUT_CONTRAT[strtolower($rawStatut)] ?? 'INCONNU';
        $this->insContrat->execute([strtoupper($src) . '-' . $id, strtoupper($src), $id, $client, $branche, $produit,
            $debut, $fin, $prime, $statut, $canal, $code]);
    }

    // ------------------------------------------------------------------ sinistres
    private ?\PDOStatement $insSinistre = null;

    private function sinistre(string $src, array $r): void
    {
        $this->insSinistre ??= $this->db->prepare('INSERT INTO silver_sinistre VALUES (?,?,?,?,?,?,?,?,?,0)');
        if ($src === 'ab') {
            $id = (string) $r['AB_CLAIM_ID'];
            $contrat = 'AB-' . $r['AB_POLICY_NUMBER'];
            $date = parse_date(substr((string) $r['AB_DATE_SINISTRE'], 0, 19), true);
            if ($date === null) {
                $this->flag('DQ09', $src, 'sinistre', $id, $r['AB_DATE_SINISTRE']);
            }
            $montant = (float) $r['AB_MONTANT_ESTIME'];
            $rawStatut = (string) $r['AB_STATUT_SINISTRE'];
            $fraude = null;
            $libelle = $r['AB_DESCRIPTION'];
        } else {
            $id = (string) $r['AP_SINISTRE_NUM'];
            $contrat = 'AP-' . $r['AP_CONTRACT_REF'];
            $raw = trim((string) $r['AP_INCIDENT_DATE']);
            $date = parse_date($raw, true);
            if ($date === null) {
                $this->flag('DQ09', $src, 'sinistre', $id, $raw);
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw)) {
                $this->flag('DQ08', $src, 'sinistre', $id, $raw);
            }
            $montant = (float) $r['AP_ESTIMATED_AMOUNT'];
            $rawStatut = (string) $r['AP_CLAIM_STATUS'];
            $fraude = $r['AP_FRAUD_SCORE'] === null ? null : (float) $r['AP_FRAUD_SCORE'];
            $libelle = $r['AP_CLAIM_COMMENT'];
        }
        $this->insSinistre->execute([strtoupper($src) . '-' . $id, strtoupper($src), $id, $contrat, $date, $montant,
            self::STATUT_SINISTRE[$rawStatut] ?? 'INCONNU', $fraude, $libelle]);
    }

    // ------------------------------------------------------------------ paiements
    private ?\PDOStatement $insPaiement = null;
    private bool $dq13Flagged = false;

    private function paiement(string $src, array $r): void
    {
        $this->insPaiement ??= $this->db->prepare('INSERT INTO silver_paiement VALUES (?,?,?,?,?,?,?,?,0)');
        if ($src === 'ab') {
            $id = (string) $r['AB_PAYMENT_ID'];
            $contrat = 'AB-' . $r['AB_POLICY_NUMBER'];
            $date = parse_date($r['AB_DATE_PAIEMENT']);
            $montant = (float) $r['AB_MONTANT'];
            $mode = self::MODE_PAIEMENT[$r['AB_MODE_PAIEMENT']] ?? 'AUTRE';
            $statut = 'REUSSI';
            if (!$this->dq13Flagged) { // règle structurelle : tracée une fois pour toute la table
                $this->flag('DQ13', $src, 'paiement', '*', 'AB_PAIEMENT sans colonne de statut');
                $this->dq13Flagged = true;
            }
        } else {
            $id = (string) $r['AP_PAYMENT_REF'];
            $contrat = 'AP-' . $r['AP_CONTRACT_REF'];
            $date = parse_date(substr((string) $r['AP_PAYMENT_DATETIME'], 0, 10));
            $montant = (float) $r['AP_AMOUNT_PAID'];
            $mode = self::MODE_PAIEMENT[$r['AP_PAYMENT_CHANNEL']] ?? 'AUTRE';
            $statut = self::STATUT_PAIEMENT[$r['AP_TRANSACTION_STATUS']] ?? 'INCONNU';
        }
        $this->insPaiement->execute([strtoupper($src) . '-' . $id, strtoupper($src), $id, $contrat, $date, $montant, $mode, $statut]);
    }

    // ------------------------------------------------------------------ intégrité référentielle
    private function flagOrphans(): void
    {
        $checks = [
            ['silver_contrat', 'contrat_uid', 'client_uid', 'silver_client', 'client_uid', 'DQ06', 'contrat'],
            ['silver_sinistre', 'sinistre_uid', 'contrat_uid', 'silver_contrat', 'contrat_uid', 'DQ10', 'sinistre'],
            ['silver_paiement', 'paiement_uid', 'contrat_uid', 'silver_contrat', 'contrat_uid', 'DQ14', 'paiement'],
        ];
        foreach ($checks as [$table, $pk, $fk, $ref, $refPk, $code, $entity]) {
            $rows = $this->db->query("SELECT t.$pk AS uid, t.source, t.source_id, t.$fk AS fk FROM $table t
                LEFT JOIN $ref r ON r.$refPk = t.$fk WHERE r.$refPk IS NULL")->fetchAll();
            foreach ($rows as $row) {
                $this->flag($code, strtolower($row['source']), $entity, $row['source_id'], $row['fk']);
            }
            $this->db->exec("UPDATE $table SET est_orphelin = 1 WHERE $fk NOT IN (SELECT $refPk FROM $ref)");
        }
        $late = $this->db->query('SELECT p.source, p.source_id, p.date_paiement, c.date_fin FROM silver_paiement p
            JOIN silver_contrat c USING (contrat_uid) WHERE c.date_fin IS NOT NULL
            AND julianday(p.date_paiement) - julianday(c.date_fin) > ' . self::TOLERANCE_FIN_CONTRAT_JOURS)->fetchAll();
        foreach ($late as $row) {
            $this->flag('DQ15', strtolower($row['source']), 'paiement', $row['source_id'], "{$row['date_paiement']} > fin {$row['date_fin']}");
        }
    }
}
