<?php
declare(strict_types=1);

namespace DBench86\Workload;

use DBench86\Dataset\DeterministicGenerator;
use PDO;

final class UpdateByPrimaryKeyWorkload implements Workload
{
    private \PDOStatement|null $updateStatement = null;
    private DeterministicGenerator|null $generator = null;
    private int $executionCount = 0;
    private array $userIds = [];
    private array $originalNames = [];
    private int $currentIndex = 0;

    public function __construct(private readonly DeterministicSamples $samples = new DeterministicSamples()) {}

    public function name(): string
    {
        return 'update_by_pk';
    }

    public function prepare(PDO $pdo): void
    {
        $this->generator = new DeterministicGenerator(66666);
        $this->executionCount = 0;
        $this->currentIndex = 0;

        $this->userIds = $this->samples->tableIds($pdo, 'users', $this->name());
        $this->originalNames = [];
        $lookup = $pdo->prepare('SELECT name FROM users WHERE id = ?');
        foreach ($this->userIds as $id) {
            $lookup->execute([$id]);
            $this->originalNames[$id] = $lookup->fetchColumn();
            $lookup->closeCursor();
        }

        if (empty($this->userIds)) {
            throw new \RuntimeException('No users found in database for UpdateByPrimaryKeyWorkload');
        }

        $this->updateStatement = $pdo->prepare('UPDATE users SET name = ? WHERE id = ?');
    }

    public function execute(PDO $pdo): int
    {
        if ($this->updateStatement === null || $this->generator === null) {
            throw new \RuntimeException('Workload not prepared');
        }

        $userId = $this->userIds[$this->currentIndex % count($this->userIds)];
        $this->currentIndex++;

        $newName = $this->generator->nextString(50);

        $this->updateStatement->execute([$newName, $userId]);


        $this->executionCount++;

        return 1;
    }

    public function cleanup(PDO $pdo): void
    {
        // Workload Isolation Strategy:
        // We tracked the original names during prepare() and now restore them.
        // This ensures perfect restoration regardless of how the dataset was initially generated.

        if ($this->executionCount > 0) {
            // Restore original names for all modified users
            $count = 0;
            foreach ($this->userIds as $userId) {
                if ($count >= $this->executionCount) {
                    break;
                }

                $originalName = $this->originalNames[$userId];
                $stmt = $pdo->prepare('UPDATE users SET name = ? WHERE id = ?');
                $stmt->execute([$originalName, $userId]);
                $count++;
            }
        }
    }

    public function boundParametersPerRow(): ?int
    {
        return null;
    }
}
