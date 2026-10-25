<?php
declare(strict_types=1);

namespace App\Stage;

use App\Bus;
use PDO;

/**
 * Étape 1 — rôle Talend (job d'extraction) puis job Spark d'atterrissage en zone bronze.
 *
 * Lit les tables sources telles quelles et publie un message par ligne dans un topic
 * nommé <couche>.<source>.<entité> (ex. src.ap.client). Les noms d'entités sont unifiés
 * dès le topic, les colonnes restent celles de la source (aucune transformation ici).
 * Un consommateur « sink » recopie ensuite chaque topic dans la zone bronze du lac.
 */
final class Ingestion
{
    /** source => [table source => [entité unifiée, colonne clé]] */
    public const MAPPING = [
        'ab' => [
            'AB_CLIENT' => ['client', 'AB_CLIENT_ID'],
            'AB_CONTRAT' => ['contrat', 'AB_POLICY_NUMBER'],
            'AB_SINISTRE' => ['sinistre', 'AB_CLAIM_ID'],
            'AB_PAIEMENT' => ['paiement', 'AB_PAYMENT_ID'],
        ],
        'ap' => [
            'AP_USERS' => ['client', 'AP_USER_ID'],
            'AP_CONTRACTS' => ['contrat', 'AP_CONTRACT_REF'],
            'AP_CLAIMS' => ['sinistre', 'AP_SINISTRE_NUM'],
            'AP_PAYMENTS' => ['paiement', 'AP_PAYMENT_REF'],
        ],
    ];

    public static function topic(string $source, string $entity): string
    {
        return "src.$source.$entity";
    }

    /** @param array<string, PDO> $sources */
    public static function extract(array $sources, Bus $bus, string $extractTs): array
    {
        $stats = [];
        foreach (self::MAPPING as $src => $tables) {
            foreach ($tables as $table => [$entity, $keyCol]) {
                $topic = self::topic($src, $entity);
                $n = 0;
                $stmt = $sources[$src]->query("SELECT * FROM $table");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $bus->publish($topic, (string) $row[$keyCol], [
                        'source' => $src,
                        'table' => $table,
                        'op' => 'c', // capture initiale ; les flux CDC enverront u / d
                        'ts' => $extractTs,
                        'after' => $row,
                    ]);
                    $n++;
                }
                $stats[] = ['table' => $table, 'topic' => $topic, 'messages' => $n];
            }
        }
        $bus->flush();
        return $stats;
    }

    /** Recopie brute des topics dans la zone bronze (fichiers JSONL compressés, partitionnés par date). */
    public static function sinkBronze(Bus $bus, string $lakeDir, string $date): array
    {
        $stats = [];
        foreach (self::MAPPING as $src => $tables) {
            foreach ($tables as [$entity]) {
                $topic = self::topic($src, $entity);
                $dir = "$lakeDir/bronze/$topic/date_ingestion=$date";
                @mkdir($dir, 0775, true);
                $gz = gzopen("$dir/part-00000.jsonl.gz", 'wb6');
                $n = 0;
                foreach ($bus->consume($topic, 'sink-hdfs-bronze') as $msg) {
                    gzwrite($gz, json_encode($msg['v'], JSON_UNESCAPED_UNICODE) . "\n");
                    $n++;
                }
                gzclose($gz);
                $stats[] = ['topic' => $topic, 'fichier' => "bronze/$topic/date_ingestion=$date/part-00000.jsonl.gz",
                    'lignes' => $n, 'octets' => filesize("$dir/part-00000.jsonl.gz")];
            }
        }
        return $stats;
    }
}
