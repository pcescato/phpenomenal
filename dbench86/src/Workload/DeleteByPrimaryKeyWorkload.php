<?php
declare(strict_types=1);

namespace DBench86\Workload;

use DBench86\Dataset\DeterministicGenerator;
use PDO;

final class DeleteByPrimaryKeyWorkload implements Workload
{
    private \PDOStatement|null $deleteStatement = null;
    private \PDOStatement|null $insertStatement = null;
    private DeterministicGenerator|null $generator = null;
    private array $productIds = [];
    private int $currentIndex = 0;
    private array $deletedProducts = [];
    private array $productsDeletedInRun = [];
    private array $originalOrderItems = [];

    public function __construct(private readonly DeterministicSamples $samples = new DeterministicSamples()) {}

    public function name(): string
    {
        return 'delete_by_pk';
    }

    public function prepare(PDO $pdo): void
    {
        $this->generator = new DeterministicGenerator(55555);
        $this->currentIndex = 0;
        $this->productsDeletedInRun = [];
        $this->productIds = [];

        $this->productIds = $this->samples->tableIds($pdo, 'products', $this->name());
        $this->deletedProducts = [];
        $this->originalOrderItems = [];
        $items = $pdo->prepare('SELECT id, order_id, product_id, quantity, unit_price FROM order_items WHERE product_id = ?');
        $lookup = $pdo->prepare('SELECT id, sku, name, price FROM products WHERE id = ?');
        foreach ($this->productIds as $id) {
            $lookup->execute([$id]);
            $this->deletedProducts[$id] = $lookup->fetch(PDO::FETCH_ASSOC);
            $lookup->closeCursor();
            $items->execute([$id]);
            $this->originalOrderItems[$id] = [];
            while ($item = $items->fetch(PDO::FETCH_ASSOC)) {
                $this->originalOrderItems[$id][] = $item;
            }
            $items->closeCursor();
        }

        $this->deleteStatement = $pdo->prepare('DELETE FROM products WHERE id = ?');
        $this->insertStatement = $pdo->prepare(
            'INSERT INTO products (id, sku, name, price) VALUES (?, ?, ?, ?)'
        );
    }

    public function execute(PDO $pdo): int
    {
        if ($this->deleteStatement === null) {
            throw new \RuntimeException('Workload not prepared');
        }

        $productId = $this->productIds[$this->currentIndex % count($this->productIds)];
        $this->currentIndex++;

        // Track which product will be deleted
        $this->productsDeletedInRun[$productId] = true;

        // First delete order_items that reference this product to maintain FK constraint
        $pdo->prepare('DELETE FROM order_items WHERE product_id = ?')->execute([$productId]);

        // Then delete the product itself
        $this->deleteStatement->execute([$productId]);

        return 1;
    }

    public function cleanup(PDO $pdo): void
    {
        if ($this->insertStatement === null || $this->productsDeletedInRun === []) {
            return;
        }
        // Preparation snapshots only sampled products and their dependent items.
        // Existence checks also handle an execution that failed between the two deletes.
        $productExists = $pdo->prepare('SELECT id FROM products WHERE id = ?');
        $itemExists = $pdo->prepare('SELECT id FROM order_items WHERE id = ?');
        $restoreItem = $pdo->prepare(
            'INSERT INTO order_items (id, order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?, ?)'
        );
        foreach (array_keys($this->productsDeletedInRun) as $productId) {
            $product = $this->deletedProducts[$productId];
            $productExists->execute([$productId]);
            $exists = $productExists->fetchColumn() !== false;
            $productExists->closeCursor();
            if (!$exists) {
                try {
                    $this->insertStatement->execute([
                        $product['id'], $product['sku'], $product['name'], $product['price'],
                    ]);
                } finally {
                    $this->insertStatement->closeCursor();
                }
            }
            foreach ($this->originalOrderItems[$productId] as $item) {
                $itemExists->execute([$item['id']]);
                $exists = $itemExists->fetchColumn() !== false;
                $itemExists->closeCursor();
                if (!$exists) {
                    $restoreItem->execute([
                        $item['id'], $item['order_id'], $item['product_id'],
                        $item['quantity'], $item['unit_price'],
                    ]);
                }
            }
            unset($this->productsDeletedInRun[$productId]);
        }
    }

    public function boundParametersPerRow(): ?int
    {
        return null;
    }
}
