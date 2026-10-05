<?php
declare(strict_types=1);

namespace DBench86\Workload;

use DBench86\Dataset\DeterministicGenerator;
use PDO;

final class TransactionalInsertWorkload implements Workload
{
    private \PDOStatement|null $statement = null;
    private DeterministicGenerator|null $generator = null;
    private int $executionCount = 0;
    private int $rowsPerTransaction = 10;

    public function __construct(int $rowsPerTransaction = 10)
    {
        $this->rowsPerTransaction = $rowsPerTransaction;
    }

    public function name(): string
    {
        return 'transactional_insert';
    }

    public function prepare(PDO $pdo): void
    {
        $this->generator = new DeterministicGenerator(77777);
        $this->executionCount = 0;

        $this->statement = $pdo->prepare(
            'INSERT INTO products (sku, name, price) VALUES (?, ?, ?)'
        );
    }

    public function execute(PDO $pdo): int
    {
        if ($this->statement === null || $this->generator === null) {
            throw new \RuntimeException('Workload not prepared');
        }

        $pdo->beginTransaction();
        try {
            $rowsInserted = 0;
            for ($i = 0; $i < $this->rowsPerTransaction; $i++) {
                $sku = $this->generator->nextString(16);
                $name = $this->generator->nextString(50);
                $price = $this->generator->nextFloat(1.0, 999.99);

                $this->statement->execute([$sku, $name, round($price, 2)]);
                $rowsInserted++;
            }

            $pdo->commit();
        } catch (\Throwable $executionError) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (\Throwable $rollbackError) {
                    throw new \RuntimeException(
                        'Transactional insert rollback failed: ' . $rollbackError->getMessage(),
                        previous: $executionError,
                    );
                }
            }
            // Reset a failed prepared statement before the next sample (notably SQLite).
            try {
                $this->statement->closeCursor();
            } catch (\Throwable $resetError) {
                throw new \RuntimeException(
                    'Transactional insert statement reset failed: ' . $resetError->getMessage(),
                    previous: $executionError,
                );
            }
            throw $executionError;
        }

        $this->executionCount += $rowsInserted;


        return $rowsInserted;
    }

    public function cleanup(PDO $pdo): void
    {
        if ($this->executionCount > 0) {
            // Get the IDs of inserted products
            $lastInsertedIds = $pdo->query(
                'SELECT id FROM products ORDER BY id DESC LIMIT ' . $this->executionCount
            )->fetchAll(\PDO::FETCH_COLUMN);

            if (!empty($lastInsertedIds)) {
                $idList = implode(',', $lastInsertedIds);
                // First delete order_items that reference these products (FK constraint)
                $pdo->exec("DELETE FROM order_items WHERE product_id IN ({$idList})");
                // Then delete the products themselves
                $pdo->exec("DELETE FROM products WHERE id IN ({$idList})");
            }
        }
    }

    public function boundParametersPerRow(): ?int
    {
        return null;
    }
}
