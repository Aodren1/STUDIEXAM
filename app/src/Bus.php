<?php
declare(strict_types=1);

namespace App;

/**
 * Journal de messages partitionné, même sémantique que Kafka :
 * un topic = N partitions append-only ; la partition est choisie par hachage de la clé
 * (ordre garanti par clé) ; chaque groupe de consommateurs a ses propres offsets.
 *
 * Stockage : data/bus/<topic>/partition-<n>.log (une ligne JSON par message)
 *            data/bus/_offsets.json             ({groupe: {topic: {partition: offset}}})
 */
final class Bus
{
    public const PARTITIONS = 3;

    /** @var array<string, resource> */
    private array $handles = [];
    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(private readonly string $dir)
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    public static function reset(string $dir): void
    {
        if (is_dir($dir)) {
            foreach (glob($dir . '/*/partition-*.log') ?: [] as $f) {
                unlink($f);
            }
            @unlink($dir . '/_offsets.json');
        }
    }

    public function publish(string $topic, string $key, array $value): void
    {
        $partition = crc32($key) % self::PARTITIONS;
        $id = $topic . '#' . $partition;
        if (!isset($this->handles[$id])) {
            @mkdir($this->dir . '/' . $topic, 0775, true);
            $this->handles[$id] = fopen($this->dir . "/$topic/partition-$partition.log", 'ab');
        }
        fwrite($this->handles[$id], json_encode(['k' => $key, 'v' => $value], JSON_UNESCAPED_UNICODE) . "\n");
        $this->counts[$topic] = ($this->counts[$topic] ?? 0) + 1;
    }

    public function flush(): void
    {
        foreach ($this->handles as $h) {
            fclose($h);
        }
        $this->handles = [];
    }

    /** @return array<string, int> messages publiés par topic depuis la création de l'instance */
    public function publishedCounts(): array
    {
        return $this->counts;
    }

    /**
     * Lit les messages non encore consommés par le groupe, partition par partition,
     * puis valide les offsets (commit) en fin de lecture.
     *
     * @return \Generator<int, array{k: string, v: array, p: int, o: int}>
     */
    public function consume(string $topic, string $group): \Generator
    {
        $offsets = $this->loadOffsets();
        for ($p = 0; $p < self::PARTITIONS; $p++) {
            $file = $this->dir . "/$topic/partition-$p.log";
            if (!is_file($file)) {
                continue;
            }
            $start = $offsets[$group][$topic][$p] ?? 0;
            $fh = fopen($file, 'rb');
            $o = 0;
            while (($line = fgets($fh)) !== false) {
                if ($o >= $start) {
                    $msg = json_decode($line, true);
                    yield $msg + ['p' => $p, 'o' => $o];
                }
                $o++;
            }
            fclose($fh);
            $offsets[$group][$topic][$p] = $o;
        }
        $this->saveOffsets($offsets);
    }

    /** Retard (lag) d'un groupe : messages présents non consommés. */
    public function lag(string $topic, string $group): int
    {
        $offsets = $this->loadOffsets();
        $lag = 0;
        for ($p = 0; $p < self::PARTITIONS; $p++) {
            $file = $this->dir . "/$topic/partition-$p.log";
            if (is_file($file)) {
                $lines = 0;
                $fh = fopen($file, 'rb');
                while (fgets($fh) !== false) {
                    $lines++;
                }
                fclose($fh);
                $lag += $lines - ($offsets[$group][$topic][$p] ?? 0);
            }
        }
        return $lag;
    }

    private function loadOffsets(): array
    {
        $f = $this->dir . '/_offsets.json';
        return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    }

    private function saveOffsets(array $offsets): void
    {
        file_put_contents($this->dir . '/_offsets.json', json_encode($offsets, JSON_PRETTY_PRINT));
    }
}
