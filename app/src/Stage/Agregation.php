<?php
declare(strict_types=1);

namespace App\Stage;

use PDO;

/**
 * Étape 4 — rôle Spark SQL (silver -> gold).
 *
 * Produit les indicateurs consommés par le tableau de bord. Chaque mesure est ventilée
 * par source (AB / AP) : le front calcule la vue consolidée ou filtrée sans nouvel aller-retour,
 * et les ratios sont livrés avec numérateur et dénominateur pour rester recalculables.
 * Les lignes orphelines (DQ06, DQ10, DQ14) sont exclues des indicateurs.
 */
final class Agregation
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function run(string $goldDir, array $dedupReport): array
    {
        @mkdir($goldDir, 0775, true);
        $files = [
            'indicateurs.json' => $this->indicateurs(),
            'doublons.json' => $this->doublons($dedupReport),
            'qualite.json' => $this->qualite(),
        ];
        foreach ($files as $name => $data) {
            file_put_contents("$goldDir/$name", json_encode(['genere_le' => date('c'), 'date_reference' => REF_DATE] + $data,
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        return array_map(static fn ($f) => "gold/$f", array_keys($files));
    }

    /** Exécute une requête renvoyant (label, source, valeur) et pivote en [{label, AB, AP}]. */
    private function pivot(string $sql, array $order = []): array
    {
        $out = [];
        foreach ($this->db->query($sql) as $r) {
            $label = (string) $r['label'];
            $out[$label] ??= ['label' => $label, 'AB' => 0, 'AP' => 0];
            $out[$label][$r['source']] = is_numeric($r['valeur']) ? $r['valeur'] + 0 : $r['valeur'];
        }
        if ($order) {
            $sorted = [];
            foreach ($order as $o) {
                $sorted[] = $out[$o] ?? ['label' => $o, 'AB' => 0, 'AP' => 0];
            }
            return $sorted;
        }
        return array_values($out);
    }

    private function scalarBySource(string $sql): array
    {
        $res = ['AB' => 0, 'AP' => 0];
        foreach ($this->db->query($sql) as $r) {
            $res[$r['source']] = $r['valeur'] === null ? 0 : $r['valeur'] + 0;
        }
        return $res;
    }

    private static function months(string $from, string $to): array
    {
        $m = [];
        for ($d = new \DateTimeImmutable($from . '-01'); $d->format('Y-m') <= $to; $d = $d->modify('+1 month')) {
            $m[] = $d->format('Y-m');
        }
        return $m;
    }

    /**
     * Contrats actifs par client unique (golden record) : par source, puis en vue consolidée (colonne ALL).
     * Anomalie de recette R1-05 : le calcul par fiche source comptait deux fois les 260 clients communs AB ∩ AP.
     */
    private function multiEquipement(): array
    {
        $band = "CASE WHEN n >= 4 THEN '4 et +' ELSE CAST(n AS TEXT) END";
        $base = "FROM silver_contrat c JOIN silver_client k USING (client_uid) WHERE c.est_orphelin = 0 AND c.statut = 'ACTIF'";
        $rows = $this->pivot("SELECT $band label, source, COUNT(*) valeur FROM
            (SELECT c.source, COUNT(*) n $base GROUP BY c.source, k.master_uid) GROUP BY 1, 2", ['1', '2', '3', '4 et +']);
        $all = $this->db->query("SELECT $band label, COUNT(*) valeur FROM (SELECT COUNT(*) n $base GROUP BY k.master_uid) GROUP BY 1")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as &$r) {
            $r['ALL'] = (int) ($all[$r['label']] ?? 0);
        }
        return $rows;
    }

    private function indicateurs(): array
    {
        $ref = REF_DATE;
        $since12 = (new \DateTimeImmutable($ref))->modify('-12 months')->format('Y-m-d');
        $ctr = "silver_contrat WHERE est_orphelin = 0";
        $branches = ['AUTO', 'HABITATION', 'SANTE', 'PROFESSIONNELLE', 'ASSISTANCE'];

        $kpis = [
            'clients' => $this->scalarBySource('SELECT source, COUNT(*) valeur FROM silver_client GROUP BY source'),
            'clients_actifs' => $this->scalarBySource("SELECT source, COUNT(*) valeur FROM silver_client WHERE statut = 'ACTIF' GROUP BY source"),
            'contrats' => $this->scalarBySource("SELECT source, COUNT(*) valeur FROM $ctr GROUP BY source"),
            'contrats_actifs' => $this->scalarBySource("SELECT source, COUNT(*) valeur FROM $ctr AND statut = 'ACTIF' GROUP BY source"),
            'primes_actives' => $this->scalarBySource("SELECT source, ROUND(SUM(prime_annuelle), 2) valeur FROM $ctr AND statut = 'ACTIF' GROUP BY source"),
            'sinistres_12m' => $this->scalarBySource("SELECT source, COUNT(*) valeur FROM silver_sinistre WHERE est_orphelin = 0 AND date_survenance >= '$since12' GROUP BY source"),
            'charge_sinistres_12m' => $this->scalarBySource("SELECT source, ROUND(SUM(montant_estime), 2) valeur FROM silver_sinistre
                WHERE est_orphelin = 0 AND statut <> 'REFUSE' AND date_survenance >= '$since12' GROUP BY source"),
            'encaisse_12m' => $this->scalarBySource("SELECT source, ROUND(SUM(montant), 2) valeur FROM silver_paiement
                WHERE est_orphelin = 0 AND statut = 'REUSSI' AND date_paiement >= '$since12' GROUP BY source"),
            'paiements_12m' => $this->scalarBySource("SELECT source, COUNT(*) valeur FROM silver_paiement WHERE est_orphelin = 0 AND date_paiement >= '$since12' GROUP BY source"),
            'paiements_echoues_12m' => $this->scalarBySource("SELECT source, COUNT(*) valeur FROM silver_paiement
                WHERE est_orphelin = 0 AND statut = 'ECHOUE' AND date_paiement >= '$since12' GROUP BY source"),
            'age_somme' => $this->scalarBySource("SELECT source, ROUND(SUM((julianday('$ref') - julianday(date_naissance)) / 365.25), 1) valeur FROM silver_client WHERE date_naissance IS NOT NULL GROUP BY source"),
            'age_effectif' => $this->scalarBySource('SELECT source, COUNT(*) valeur FROM silver_client WHERE date_naissance IS NOT NULL GROUP BY source'),
        ];

        $ageBand = "CASE WHEN a < 26 THEN '18-25' WHEN a < 36 THEN '26-35' WHEN a < 46 THEN '36-45' WHEN a < 56 THEN '46-55'
            WHEN a < 66 THEN '56-65' WHEN a < 76 THEN '66-75' ELSE '76 et +' END";
        $clients = [
            'par_statut' => $this->pivot('SELECT statut label, source, COUNT(*) valeur FROM silver_client GROUP BY 1, 2',
                ['ACTIF', 'INACTIF', 'RESILIE', 'PROSPECT']),
            'par_tranche_age' => $this->pivot("SELECT $ageBand label, source, COUNT(*) valeur FROM
                (SELECT source, (julianday('$ref') - julianday(date_naissance)) / 365.25 a FROM silver_client WHERE date_naissance IS NOT NULL) GROUP BY 1, 2",
                ['18-25', '26-35', '36-45', '46-55', '56-65', '66-75', '76 et +']),
            'par_pays' => $this->pivot("SELECT pays label, source, COUNT(*) valeur FROM silver_client GROUP BY 1, 2", ['FR', 'BE', 'LU', 'NL', 'INCONNU']),
            'par_region' => $this->pivot("SELECT COALESCE(region, 'Non déterminée') label, source, COUNT(*) valeur FROM silver_client WHERE pays = 'FR' GROUP BY 1, 2"),
            'creations_par_annee' => $this->pivot("SELECT substr(date_creation, 1, 4) label, source, COUNT(*) valeur FROM silver_client
                WHERE date_creation >= '2005' GROUP BY 1, 2 ORDER BY 1"),
            'multi_equipement' => $this->multiEquipement(),
            'fidelite' => $this->pivot("SELECT printf('%02d-%02d', (MIN(99, score_fidelite) / 10) * 10, (MIN(99, score_fidelite) / 10) * 10 + 9) label, source, COUNT(*) valeur
                FROM silver_client WHERE score_fidelite IS NOT NULL GROUP BY 1, 2 ORDER BY 1"),
        ];
        usort($clients['par_region'], static fn ($a, $b) => ($b['AB'] + $b['AP']) <=> ($a['AB'] + $a['AP']));

        $contrats = [
            'par_branche' => $this->pivot("SELECT branche label, source, COUNT(*) valeur FROM $ctr AND statut = 'ACTIF' GROUP BY 1, 2", $branches),
            'primes_par_branche' => $this->pivot("SELECT branche label, source, ROUND(SUM(prime_annuelle), 2) valeur FROM $ctr AND statut = 'ACTIF' GROUP BY 1, 2", $branches),
            'par_statut' => $this->pivot("SELECT statut label, source, COUNT(*) valeur FROM $ctr GROUP BY 1, 2", ['ACTIF', 'RESILIE', 'SUSPENDU', 'EN_ATTENTE']),
            'souscriptions_par_annee' => $this->pivot("SELECT substr(date_debut, 1, 4) label, source, COUNT(*) valeur FROM $ctr AND date_debut >= '2010' GROUP BY 1, 2 ORDER BY 1"),
            'resiliations_par_annee' => $this->pivot("SELECT substr(date_fin, 1, 4) label, source, COUNT(*) valeur FROM $ctr AND statut = 'RESILIE' AND date_fin >= '2015' GROUP BY 1, 2 ORDER BY 1"),
            'par_canal' => $this->pivot("SELECT canal label, source, COUNT(*) valeur FROM $ctr AND statut = 'ACTIF' GROUP BY 1, 2", ['AGENCE', 'COURTIER', 'DIGITAL']),
            'produits' => $this->db->query("SELECT source, branche, produit_source produit, COUNT(*) contrats, ROUND(AVG(prime_annuelle), 2) prime_moyenne,
                ROUND(SUM(prime_annuelle), 2) primes FROM $ctr AND statut = 'ACTIF' GROUP BY 1, 2, 3 ORDER BY primes DESC")->fetchAll(),
        ];

        $sin = "silver_sinistre WHERE est_orphelin = 0";
        $from36 = (new \DateTimeImmutable($ref))->modify('-35 months')->format('Y-m');
        $sinMensuel = $this->pivot("SELECT substr(date_survenance, 1, 7) label, source, COUNT(*) valeur FROM $sin
            AND date_survenance >= '$from36' GROUP BY 1, 2", self::months($from36, substr($ref, 0, 7)));
        $sinistres = [
            'par_statut' => $this->pivot("SELECT statut label, source, COUNT(*) valeur FROM $sin GROUP BY 1, 2", ['OUVERT', 'EN_INSTRUCTION', 'CLOS_INDEMNISE', 'REFUSE']),
            'par_branche' => $this->pivot("SELECT c.branche label, s.source, COUNT(*) valeur FROM silver_sinistre s JOIN silver_contrat c USING (contrat_uid)
                WHERE s.est_orphelin = 0 GROUP BY 1, 2", $branches),
            'charge_par_branche' => $this->pivot("SELECT c.branche label, s.source, ROUND(SUM(s.montant_estime), 2) valeur FROM silver_sinistre s JOIN silver_contrat c USING (contrat_uid)
                WHERE s.est_orphelin = 0 AND s.statut <> 'REFUSE' GROUP BY 1, 2", $branches),
            'mensuel' => $sinMensuel,
            'tranches_montant' => $this->pivot("SELECT CASE WHEN montant_estime < 500 THEN '< 500 €' WHEN montant_estime < 2000 THEN '500 – 2 000 €'
                WHEN montant_estime < 10000 THEN '2 000 – 10 000 €' WHEN montant_estime < 50000 THEN '10 000 – 50 000 €' ELSE '≥ 50 000 €' END label,
                source, COUNT(*) valeur FROM $sin GROUP BY 1, 2", ['< 500 €', '500 – 2 000 €', '2 000 – 10 000 €', '10 000 – 50 000 €', '≥ 50 000 €']),
            'fraude' => $this->pivot("SELECT printf('%02d', MIN(90, CAST(score_fraude / 10 AS INT) * 10)) label, source, COUNT(*) valeur FROM $sin
                AND score_fraude IS NOT NULL GROUP BY 1, 2 ORDER BY 1"),
            'alertes_fraude' => $this->db->query("SELECT s.sinistre_uid, c.branche, s.date_survenance, s.montant_estime, s.score_fraude, s.statut
                FROM silver_sinistre s JOIN silver_contrat c USING (contrat_uid) WHERE s.score_fraude >= 70 AND s.statut IN ('OUVERT', 'EN_INSTRUCTION')
                ORDER BY s.score_fraude DESC LIMIT 10")->fetchAll(),
            'nb_alertes_fraude' => (int) $this->db->query("SELECT COUNT(*) FROM silver_sinistre WHERE score_fraude >= 70")->fetchColumn(),
        ];

        $pay = "silver_paiement WHERE est_orphelin = 0";
        $finances = [
            'encaissements_mensuels' => $this->pivot("SELECT substr(date_paiement, 1, 7) label, source, ROUND(SUM(montant), 2) valeur FROM $pay
                AND statut = 'REUSSI' AND date_paiement >= '2024-01' GROUP BY 1, 2", self::months('2024-01', substr($ref, 0, 7))),
            'par_mode' => $this->pivot("SELECT mode label, source, ROUND(SUM(montant), 2) valeur FROM $pay AND statut = 'REUSSI' AND date_paiement >= '$since12' GROUP BY 1, 2",
                ['PRELEVEMENT', 'CARTE', 'PORTEFEUILLE', 'VIREMENT', 'CHEQUE']),
            'echecs_mensuels' => $this->pivot("SELECT substr(date_paiement, 1, 7) label, source,
                ROUND(100.0 * SUM(statut = 'ECHOUE') / COUNT(*), 2) valeur FROM $pay AND date_paiement >= '2024-01' GROUP BY 1, 2",
                self::months('2024-01', substr($ref, 0, 7))),
            'sp_primes_12m' => $this->pivot("SELECT c.branche label, p.source, ROUND(SUM(p.montant), 2) valeur FROM silver_paiement p JOIN silver_contrat c USING (contrat_uid)
                WHERE p.est_orphelin = 0 AND p.statut = 'REUSSI' AND p.date_paiement >= '$since12' GROUP BY 1, 2", $branches),
            'sp_sinistres_12m' => $this->pivot("SELECT c.branche label, s.source, ROUND(SUM(s.montant_estime), 2) valeur FROM silver_sinistre s JOIN silver_contrat c USING (contrat_uid)
                WHERE s.est_orphelin = 0 AND s.statut <> 'REFUSE' AND s.date_survenance >= '$since12' GROUP BY 1, 2", $branches),
        ];

        return ['kpis' => $kpis, 'clients' => $clients, 'contrats' => $contrats, 'sinistres' => $sinistres, 'finances' => $finances];
    }

    /** « Marie DUPONT » -> « M••• D••• » : initiales seulement (BNF-07, anomalie de recette R1-06). */
    private static function maskName(string $prenom, string $nom): string
    {
        $m = static fn (string $s) => $s === '' ? '' : mb_substr($s, 0, 1) . '•••';
        return trim($m($prenom) . ' ' . $m($nom));
    }

    private static function mask(?string $email): ?string
    {
        if (!$email) {
            return null;
        }
        [$l, $d] = explode('@', $email) + [1 => ''];
        return mb_substr($l, 0, 1) . str_repeat('•', max(2, min(6, mb_strlen($l) - 1))) . '@' . $d;
    }

    private function doublons(array $report): array
    {
        $matrice = [];
        foreach (['AB-AB', 'AB-AP', 'AP-AP'] as $t) {
            $matrice[$t] = ['CERTAIN' => 0, 'PROBABLE' => 0];
        }
        foreach ($this->db->query('SELECT type_paire, niveau, COUNT(*) n FROM silver_doublon GROUP BY 1, 2') as $r) {
            $matrice[$r['type_paire']][$r['niveau']] = (int) $r['n'];
        }

        $crit = ['email', 'telephone', 'nom', 'naissance', 'adresse'];
        $cooc = array_fill(0, 5, array_fill(0, 5, 0));
        foreach ($this->db->query('SELECT c_email, c_telephone, c_nom, c_naissance, c_adresse FROM silver_doublon') as $r) {
            $v = array_values(array_map('intval', $r));
            for ($i = 0; $i < 5; $i++) {
                for ($j = 0; $j < 5; $j++) {
                    $cooc[$i][$j] += $v[$i] & $v[$j];
                }
            }
        }

        $scores = $this->db->query("SELECT printf('%.2f', MIN(1.0, CAST(score / 0.05 AS INT) * 0.05)) tranche, niveau, COUNT(*) n
            FROM silver_doublon GROUP BY 1, 2 ORDER BY 1")->fetchAll();

        $exemples = [];
        $sql = "SELECT d.*, a.nom a_nom, a.prenom a_prenom, a.date_naissance a_ddn, a.email a_email, a.telephone a_tel, a.code_postal a_cp,
                b.nom b_nom, b.prenom b_prenom, b.date_naissance b_ddn, b.email b_email, b.telephone b_tel, b.code_postal b_cp
            FROM silver_doublon d JOIN silver_client a ON a.client_uid = d.uid_a JOIN silver_client b ON b.client_uid = d.uid_b
            WHERE d.type_paire = ? AND d.niveau = ? ORDER BY d.score " . '%s' . " LIMIT ?";
        foreach ([['AB-AP', 'CERTAIN', 'DESC', 4], ['AB-AP', 'PROBABLE', 'DESC', 3], ['AB-AB', 'CERTAIN', 'ASC', 2], ['AP-AP', 'CERTAIN', 'ASC', 2], ['AB-AP', 'PROBABLE', 'ASC', 2]] as [$t, $n, $o, $l]) {
            $st = $this->db->prepare(sprintf($sql, $o));
            $st->execute([$t, $n, $l]);
            foreach ($st as $r) {
                $side = static fn ($p) => [
                    'uid' => $r["uid_$p"],
                    'nom' => self::maskName((string) $r["{$p}_prenom"], (string) $r["{$p}_nom"]),
                    'naissance' => $r["{$p}_ddn"] ? substr($r["{$p}_ddn"], 0, 4) : null, // année seule
                    'email' => self::mask($r["{$p}_email"]),
                    'telephone' => $r["{$p}_tel"] ? substr($r["{$p}_tel"], 0, -4) . '••••' : null,
                    'cp' => $r["{$p}_cp"] ? substr($r["{$p}_cp"], 0, 2) . '•••' : null,
                ];
                $exemples[] = ['type' => $t, 'niveau' => $n, 'score' => (float) $r['score'], 'a' => $side('a'), 'b' => $side('b'),
                    'criteres' => ['email' => (int) $r['c_email'], 'telephone' => (int) $r['c_telephone'], 'nom' => (int) $r['c_nom'],
                        'naissance' => (int) $r['c_naissance'], 'adresse' => (int) $r['c_adresse']]];
            }
        }

        $clientsUniques = (int) $this->db->query('SELECT COUNT(DISTINCT master_uid) FROM silver_client')->fetchColumn();
        $communs = (int) $this->db->query("SELECT COUNT(*) FROM (SELECT master_uid FROM silver_client GROUP BY master_uid
            HAVING SUM(source = 'AB') > 0 AND SUM(source = 'AP') > 0)")->fetchColumn();

        return [
            'seuils' => ['certain' => Dedoublonnage::SEUIL_CERTAIN, 'probable' => Dedoublonnage::SEUIL_PROBABLE],
            'poids' => Dedoublonnage::WEIGHTS,
            'matrice_sources' => $matrice,
            'criteres' => ['E-mail', 'Téléphone', 'Nom + prénom', 'Date de naissance', 'Adresse'],
            'matrice_criteres' => $cooc,
            'distribution_scores' => $scores,
            'exemples' => $exemples,
            'clients_total' => $report['clients'],
            'clients_uniques' => $clientsUniques,
            'clients_communs_ab_ap' => $communs,
            'paires_comparees' => $report['paires_comparees'],
            'comparaisons_evitees' => $report['comparaisons_evitees'],
            'evaluation' => $report['evaluation'],
        ];
    }

    private function qualite(): array
    {
        $anomalies = [];
        $counts = [];
        foreach ($this->db->query('SELECT code, source, COUNT(*) n FROM dq_anomalie GROUP BY 1, 2') as $r) {
            $counts[$r['code']][strtoupper($r['source'])] = (int) $r['n'];
        }
        foreach (Harmonisation::RULES as $code => [$label, $gravite, $action]) {
            $anomalies[] = ['code' => $code, 'libelle' => $label, 'gravite' => $gravite, 'action' => $action,
                'AB' => $counts[$code]['AB'] ?? 0, 'AP' => $counts[$code]['AP'] ?? 0];
        }
        $fields = ['email' => 'E-mail', 'telephone' => 'Téléphone', 'date_naissance' => 'Date de naissance',
            'num_fiscal' => 'N° fiscal', 'score_fidelite' => 'Score fidélité', 'region' => 'Région (FR)'];
        $completude = [];
        foreach ($fields as $f => $label) {
            $row = ['label' => $label];
            foreach (['AB', 'AP'] as $s) {
                $row[$s] = (float) $this->db->query("SELECT ROUND(100.0 * SUM($f IS NOT NULL) / COUNT(*), 1) FROM silver_client
                    WHERE source = '$s'" . ($f === 'region' ? " AND pays = 'FR'" : ''))->fetchColumn();
            }
            $completude[] = $row;
        }
        return ['anomalies' => $anomalies, 'completude_clients' => $completude];
    }
}
