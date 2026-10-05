<?php
declare(strict_types=1);

namespace DBench86\Workload;

use PDO;

final class PrimaryKeySelectWorkload implements Workload
{
    private array $userIds = [];
    private int $currentIndex = 0;
    private \PDOStatement|null $statement = null;

    public function __construct(private readonly DeterministicSamples $samples = new DeterministicSamples()) {}

    public function name(): string
    {
        return 'pk_select';
    }

    public function prepare(PDO $pdo): void
    {
        $this->userIds = $this->samples->tableIds($pdo, 'users', $this->name());

        if (empty($this->userIds)) {
            throw new \RuntimeException('No users found in database for PrimaryKeySelectWorkload');
        }

        $this->statement = $pdo->prepare('SELECT id, email, name, created_at FROM users WHERE id = ?');
        $this->currentIndex = 0;
    }

    public function execute(PDO $pdo): int
    {
        if ($this->statement === null) {
            throw new \RuntimeException('Workload not prepared');
        }

        $id = $this->userIds[$this->currentIndex % count($this->userIds)];
        $this->currentIndex++;

        $this->statement->execute([$id]);
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
