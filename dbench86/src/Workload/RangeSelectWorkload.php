<?php
declare(strict_types=1);

namespace DBench86\Workload;

use PDO;

final class RangeSelectWorkload implements Workload
{
    private array $windows = [];
    private int $currentIndex = 0;
    private \PDOStatement|null $statement = null;

    private const string SQL = 'SELECT id, email, name, created_at FROM users WHERE created_at BETWEEN ? AND ?';

    public function __construct(private readonly DeterministicSamples $samples = new DeterministicSamples()) {}

    public function name(): string
    {
        return 'range_select';
    }

    public function prepare(PDO $pdo): void
    {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count === 0) {
            throw new \RuntimeException('No users found in database for RangeSelectWorkload');
        }

        // Preserve the original sorted timestamp ranks and ten-percent windows.
        // Rank discovery happens in the database; only the required endpoints reach PHP.
        $ranks = [];
        for ($i = 0; $i < $this->samples->executions; $i++) {
            $ranks[$i % $count] = true;
            $ranks[($i + (int)($count / 10)) % $count] = true;
        }
        $rankList = implode(',', array_keys($ranks));
        $result = $pdo->query(
            'SELECT sample_rank, created_at FROM '
            . '(SELECT created_at, ROW_NUMBER() OVER (ORDER BY created_at) - 1 AS sample_rank FROM users) ranked '
            . "WHERE sample_rank IN ({$rankList})"
        );
        $timestamps = [];
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $timestamps[(int)$row['sample_rank']] = $row['created_at'];
        }
        $result->closeCursor();
        $this->windows = [];
        for ($i = 0; $i < $this->samples->executions; $i++) {
            $start = $i % $count;
            $end = ($i + (int)($count / 10)) % $count;
            $this->windows[] = [$timestamps[min($start, $end)], $timestamps[max($start, $end)]];
        }
        $this->currentIndex = 0;
        // Driver result delivery stays at its defaults in every benchmark campaign.
        $this->statement = $pdo->prepare(self::SQL);
    }

    public function execute(PDO $pdo): int
    {
        if ($this->statement === null) {
            throw new \RuntimeException('Workload not prepared');
        }
        $window = $this->windows[$this->currentIndex % count($this->windows)];
        $this->currentIndex++;
        $rowCount = 0;
        $this->statement->execute($window);
        while ($this->statement->fetch(PDO::FETCH_ASSOC) !== false) {
            $rowCount++;
        }
        $this->statement->closeCursor();
        return $rowCount;
    }

    public function cleanup(PDO $pdo): void
    {
        $this->statement?->closeCursor();
    }

    public function boundParametersPerRow(): ?int
    {
        return null;
    }
}
