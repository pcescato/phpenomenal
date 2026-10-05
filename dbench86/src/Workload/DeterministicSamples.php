<?php
declare(strict_types=1);

namespace DBench86\Workload;

use PDO;

/** Small, stratified samples from the generated contiguous primary keys 1..N. */
final class DeterministicSamples
{
    public function __construct(
        public readonly int $executions = 35,
        public readonly int $seed = 42_000,
    ) {
        if ($executions <= 0) {
            throw new \InvalidArgumentException('Sample execution count must be positive');
        }
    }

    /** @return list<int> O(executions), never constructs range(1, rows). */
    public function ids(int $rows, string $identity): array
    {
        if ($rows <= 0) {
            throw new \RuntimeException("No rows available for {$identity}");
        }
        $count = min($rows, $this->executions);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $low = (int)floor($i * ($rows / $count)) + 1;
            $high = (int)floor(($i + 1) * ($rows / $count));
            $hash = hexdec(substr(hash('sha256', "{$this->seed}:{$rows}:{$identity}:{$i}"), 0, 8));
            $ids[] = $low + ($hash % ($high - $low + 1));
        }
        return $ids;
    }

    /** Verify the dataset invariant before deriving keys instead of scanning candidates. */
    public function tableIds(PDO $pdo, string $table, string $identity): array
    {
        if (!in_array($table, ['users', 'orders', 'products'], true)) {
            throw new \InvalidArgumentException('Unsupported sample table');
        }
        $bounds = $pdo->query("SELECT COUNT(*) AS n, MIN(id) AS lo, MAX(id) AS hi FROM {$table}")->fetch(PDO::FETCH_ASSOC);
        $rows = (int)$bounds['n'];
        if ($rows === 0 || (int)$bounds['lo'] !== 1 || (int)$bounds['hi'] !== $rows) {
            throw new \RuntimeException("{$identity} requires generated contiguous {$table} IDs 1..N; repopulate the dataset");
        }
        return $this->ids($rows, $identity);
    }
}
