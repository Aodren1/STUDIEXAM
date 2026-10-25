<?php
declare(strict_types=1);

namespace App\Stage;

use PDO;

/**
 * Étape 3 — rôle Spark (job de rapprochement / « entity resolution »).
 *
 * 1. Blocage : on ne compare que les clients partageant au moins une clé
 *    (e-mail, téléphone, nom+date de naissance, nom+code postal) -> évite le produit cartésien
 *    (17 M² comparaisons en réel).
 * 2. Score pondéré par critère concordant.
 * 3. Classement : >= 0,70 « certain » (fusion automatique), 0,45–0,70 « probable »
 *    (file de validation métier), en dessous : écarté.
 * 4. Fusion des paires certaines par union-find -> master_uid (golden record).
 */
final class Dedoublonnage
{
    public const WEIGHTS = ['email' => 0.35, 'telephone' => 0.25, 'nom' => 0.20, 'naissance' => 0.15, 'adresse' => 0.10];
    public const SEUIL_CERTAIN = 0.70;
    public const SEUIL_PROBABLE = 0.45;
    private const MAX_BLOCK = 40;

    private array $parent = [];

    public function __construct(private readonly PDO $db)
    {
    }

    public function run(?array $truthPairs = null): array
    {
        $clients = [];
        foreach ($this->db->query('SELECT client_uid, nom_cle, date_naissance, email, telephone, adresse, code_postal FROM silver_client') as $c) {
            $clients[$c['client_uid']] = $c;
        }

        // 1. blocage
        $blocks = [];
        foreach ($clients as $uid => $c) {
            if ($c['email']) {
                $blocks['e:' . $c['email']][] = $uid;
            }
            if ($c['telephone']) {
                $blocks['t:' . $c['telephone']][] = $uid;
            }
            if ($c['nom_cle'] && $c['date_naissance']) {
                $blocks['n:' . $c['nom_cle'] . '|' . $c['date_naissance']][] = $uid;
            }
            if ($c['nom_cle'] && $c['code_postal']) {
                $blocks['a:' . $c['nom_cle'] . '|' . $c['code_postal']][] = $uid;
            }
        }
        $candidates = [];
        foreach ($blocks as $members) {
            $n = count($members);
            if ($n < 2 || $n > self::MAX_BLOCK) {
                continue;
            }
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = min($members[$i], $members[$j]);
                    $b = max($members[$i], $members[$j]);
                    $candidates["$a|$b"] = true;
                }
            }
        }

        // 2-3. score et classement
        $this->db->exec('DROP TABLE IF EXISTS silver_doublon; CREATE TABLE silver_doublon (
            uid_a TEXT, uid_b TEXT, type_paire TEXT, score REAL, niveau TEXT,
            c_email INTEGER, c_telephone INTEGER, c_nom INTEGER, c_naissance INTEGER, c_adresse INTEGER)');
        $ins = $this->db->prepare('INSERT INTO silver_doublon VALUES (?,?,?,?,?,?,?,?,?,?)');
        $this->db->beginTransaction();
        $found = [];
        foreach (array_keys($candidates) as $key) {
            [$a, $b] = explode('|', $key);
            $crit = self::compare($clients[$a], $clients[$b]);
            $score = 0.0;
            foreach ($crit as $k => $v) {
                $score += self::WEIGHTS[$k] * $v;
            }
            $score = round($score, 3);
            if ($score < self::SEUIL_PROBABLE) {
                continue;
            }
            $niveau = $score >= self::SEUIL_CERTAIN ? 'CERTAIN' : 'PROBABLE';
            $type = substr($a, 0, 2) . '-' . substr($b, 0, 2);
            $ins->execute([$a, $b, $type, $score, $niveau, (int) ($crit['email'] == 1), (int) ($crit['telephone'] == 1),
                (int) ($crit['nom'] >= 0.5), (int) ($crit['naissance'] == 1), (int) ($crit['adresse'] >= 0.5)]);
            $found[] = [$a, $b, $niveau];
            if ($niveau === 'CERTAIN') {
                $this->union($a, $b);
            }
        }

        // 4. golden record
        $upd = $this->db->prepare('UPDATE silver_client SET master_uid = ? WHERE client_uid = ?');
        foreach (array_keys($clients) as $uid) {
            $upd->execute([$this->find($uid), $uid]);
        }
        $this->db->commit();

        return [
            'clients' => count($clients),
            'blocs' => count($blocks),
            'paires_comparees' => count($candidates),
            'comparaisons_evitees' => (int) (count($clients) * (count($clients) - 1) / 2 - count($candidates)),
            'evaluation' => $truthPairs === null ? null : self::evaluate($found, $truthPairs),
        ];
    }

    /** @return array<string, float> concordance par critère, de 0 à 1 */
    public static function compare(array $x, array $y): array
    {
        $nom = 0.0;
        if ($x['nom_cle'] !== '' && $x['nom_cle'] === $y['nom_cle']) {
            $nom = 1.0;
        } elseif ($x['nom_cle'] !== '' && $y['nom_cle'] !== '' && levenshtein($x['nom_cle'], $y['nom_cle']) <= 2) {
            $nom = 0.5;
        }
        $adresse = 0.0;
        if ($x['code_postal'] !== '' && $x['code_postal'] === $y['code_postal']) {
            similar_text($x['adresse'], $y['adresse'], $pct);
            $adresse = $pct >= 85 ? 1.0 : 0.3;
        }
        return [
            'email' => (float) ($x['email'] !== null && $x['email'] === $y['email']),
            'telephone' => (float) ($x['telephone'] !== null && $x['telephone'] === $y['telephone']),
            'nom' => $nom,
            'naissance' => (float) ($x['date_naissance'] !== null && $x['date_naissance'] === $y['date_naissance']),
            'adresse' => $adresse,
        ];
    }

    /** Précision / rappel par rapport à la vérité terrain générée avec les données de test. */
    public static function evaluate(array $found, array $truthPairs): array
    {
        $parent = [];
        $find = static function (string $x) use (&$parent, &$find): string {
            if (!isset($parent[$x]) || $parent[$x] === $x) {
                return $parent[$x] = $x;
            }
            return $parent[$x] = $find($parent[$x]);
        };
        foreach ($truthPairs as [$a, $b]) {
            $parent[$find($a)] = $find($b);
        }
        $clusters = [];
        foreach (array_keys($parent) as $uid) {
            $clusters[$find($uid)][] = $uid;
        }
        $truthCount = 0;
        foreach ($clusters as $members) {
            $truthCount += count($members) * (count($members) - 1) / 2;
        }
        $res = [];
        foreach (['CERTAIN' => ['CERTAIN'], 'CERTAIN+PROBABLE' => ['CERTAIN', 'PROBABLE']] as $label => $levels) {
            $tp = 0;
            $predicted = 0;
            foreach ($found as [$a, $b, $niveau]) {
                if (!in_array($niveau, $levels, true)) {
                    continue;
                }
                $predicted++;
                if (isset($parent[$a], $parent[$b]) && $find($a) === $find($b)) {
                    $tp++;
                }
            }
            $res[$label] = [
                'paires_detectees' => $predicted,
                'vrais_positifs' => $tp,
                'paires_reelles' => (int) $truthCount,
                'precision' => $predicted ? round($tp / $predicted, 4) : null,
                'rappel' => $truthCount ? round($tp / $truthCount, 4) : null,
            ];
        }
        return $res;
    }

    private function find(string $x): string
    {
        if (!isset($this->parent[$x]) || $this->parent[$x] === $x) {
            return $this->parent[$x] = $x;
        }
        return $this->parent[$x] = $this->find($this->parent[$x]);
    }

    private function union(string $a, string $b): void
    {
        $ra = $this->find($a);
        $rb = $this->find($b);
        if ($ra !== $rb) {
            // la racine est l'identifiant le plus petit : AB prioritaire (acquéreur, référentiel maître)
            [$keep, $drop] = strcmp($ra, $rb) < 0 ? [$ra, $rb] : [$rb, $ra];
            $this->parent[$drop] = $keep;
        }
    }
}
