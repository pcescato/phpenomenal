<?php
declare(strict_types=1);

namespace DBench86\Workload;

use PDO;

final class IndexedSelectWorkload implements Workload
{
    private array $emails = [];
    private int $currentIndex = 0;
    private \PDOStatement|null $statement = null;

    public function __construct(private readonly DeterministicSamples $samples = new DeterministicSamples()) {}

    public function name(): string
    {
        return 'indexed_select';
    }

    public function prepare(PDO $pdo): void
    {
        $ids = $this->samples->tableIds($pdo, 'users', $this->name());
        $lookup = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $this->emails = [];
        foreach ($ids as $id) {
            $lookup->execute([$id]);
            $this->emails[] = $lookup->fetchColumn();
            $lookup->closeCursor();
        }

        if (empty($this->emails)) {
            throw new \RuntimeException('No users found in database for IndexedSelectWorkload');
        }

        $this->statement = $pdo->prepare('SELECT id, email, name, created_at FROM users WHERE email = ?');
        $this->currentIndex = 0;
    }

    public function execute(PDO $pdo): int
    {
        if ($this->statement === null) {
            throw new \RuntimeException('Workload not prepared');
        }

        $email = $this->emails[$this->currentIndex % count($this->emails)];
        $this->currentIndex++;

        $this->statement->execute([$email]);
        $rows = $this->statement->fetchAll(\PDO::FETCH_ASSOC);
        $rowCount = count($rows);


        return $rowCount;
    }

    public function cleanup(PDO $pdo): void
    {
    }

    public function boundParametersPerRow(): ?int
    {
        return null;
    }
}
