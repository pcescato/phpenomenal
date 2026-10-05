<?php
declare(strict_types=1);

namespace DBench86\Workload;

use DBench86\Dataset\DeterministicGenerator;
use PDO;

final class SingleInsertWorkload implements Workload
{
    private \PDOStatement|null $statement = null;
    private DeterministicGenerator|null $generator = null;
    private int $executionCount = 0;

    public function name(): string
    {
        return 'single_insert';
    }

    public function prepare(PDO $pdo): void
    {
        $this->generator = new DeterministicGenerator(99999);
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

        $sku = $this->generator->nextString(16);
        $name = $this->generator->nextString(50);
        $price = $this->generator->nextFloat(1.0, 999.99);

        $this->statement->execute([$sku, $name, round($price, 2)]);
        $this->executionCount++;


        return 1;
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
