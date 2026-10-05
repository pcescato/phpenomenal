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
        $lookup = $pdo->prepare('SELECT id, sku, name, price FROM products WHERE id = ?');
        foreach ($this->productIds as $id) {
            $lookup->execute([$id]);
            $this->deletedProducts[$id] = $lookup->fetch(PDO::FETCH_ASSOC);
            $lookup->closeCursor();
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
        // Workload Isolation Strategy:
        // After deletions, we must restore the deleted products to ensure the dataset
        // is in a consistent state for repeated benchmark cycles.
        //
        // Challenge: Products are referenced by order_items via foreign key constraint.
        // When we delete a product, any order_items referencing it become orphaned.
        //
        // Solution: Before re-inserting products, we must first delete any orphaned
        // order_items that reference products not yet restored. This maintains referential
        // integrity and allows the products to be re-inserted successfully.

        if (!empty($this->productsDeletedInRun) && $this->insertStatement !== null) {
            // Get the actual product IDs that were deleted in this run
            $deletedIds = array_keys($this->productsDeletedInRun);

            // First, delete orphaned order_items that reference deleted products
            $idList = implode(',', $deletedIds);
            $pdo->exec("DELETE FROM order_items WHERE product_id IN ({$idList})");

            // Now re-insert the deleted products from our stored data
            foreach ($deletedIds as $productId) {
                if (!isset($this->deletedProducts[$productId])) {
                    // This should not happen in normal operation
                    // Skip if product data is missing
                    continue;
                }

                $product = $this->deletedProducts[$productId];
                try {
                    $this->insertStatement->execute([
                        $product['id'],
                        $product['sku'],
                        $product['name'],
                        $product['price']
                    ]);
                } catch (\PDOException $e) {
                    // Product may already exist (concurrent execution or race condition)
                    // Skip and continue
                }
            }
        }
    }

    public function boundParametersPerRow(): ?int
    {
        return null;
    }
}
