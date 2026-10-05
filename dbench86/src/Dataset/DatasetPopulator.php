<?php
declare(strict_types=1);

namespace DBench86\Dataset;

use PDO;
use DBench86\Driver\DatabaseDriver;
use DBench86\Exception\BenchmarkPreparationException;

/**
 * Orchestrates the complete dataset population cycle.
 *
 * Workflow:
 * 1. Call createSchema() on the driver to establish the database structure
 * 2. Generate rows using DatasetGenerator (deterministic, based on seed)
 * 3. Insert rows via prepared statements, reused across all rows per table
 * 4. Verify row counts match requested scale
 *
 * This class does NOT use BatchLimitCalculator yet — that's a workload concern
 * (when executing query benchmarks with specific batch constraints). Here, we
 * simply populate the database line-by-line using reused prepared statements
 * for efficiency, without worrying about batch limits.
 *
 * Insertion follows schema dependencies (users → products → orders → order_items)
 * to respect foreign key constraints.
 */
final class DatasetPopulator
{
    /**
     * Construct a DatasetPopulator instance.
     *
     * @param DatabaseDriver $driver The database driver to use for all operations
     * @param DeterministicGenerator $generator The pseudo-random generator for data
     * @param int $requestedRows The number of user rows to generate (scales other tables)
     */
    public function __construct(
        private readonly DatabaseDriver $driver,
        private readonly DeterministicGenerator $generator,
        private readonly int $requestedRows,
    ) {
        if ($this->requestedRows <= 0) {
            throw new \InvalidArgumentException(
                'requestedRows must be strictly positive'
            );
        }
    }

    /**
     * Execute the complete population cycle.
     *
     * Flow:
     * 1. Connect to the database via $driver
     * 2. Call driver->createSchema() to establish tables
     * 3. Generate and insert rows for all 4 tables
     * 4. Verify row counts against expected values
     *
     * @return PDO The active database connection used for population
     * @throws BenchmarkPreparationException If any step fails
     */
    public function populate(): PDO
    {
        $pdo = $this->driver->connect();

        try {
            // Reset schema if it already exists (truncate tables, reset auto_increment)
            // This handles re-population scenarios gracefully
            try {
                $this->driver->resetSchema($pdo);
            } catch (\Exception $e) {
                // If resetSchema fails (e.g., no existing schema), that's ok
                // We'll create it fresh in the next step
            }

            // Create schema (tables, indexes, constraints)
            // If schema already exists after reset, this is a no-op
            $this->driver->createSchema($pdo);

            // Generate dataset
            $datasetGen = new DatasetGenerator($this->generator, $this->requestedRows);

            // Generate and insert data in dependency order
            $this->insertUsers($pdo, $datasetGen);
            $this->insertProducts($pdo, $datasetGen);
            $this->insertOrders($pdo, $datasetGen);
            $this->insertOrderItems($pdo, $datasetGen);

            // Verify row counts
            $this->verifyRowCounts($pdo);

            return $pdo;
        } catch (\Exception $e) {
            if (!($e instanceof BenchmarkPreparationException)) {
                throw new BenchmarkPreparationException(
                    'Dataset population failed: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
            throw $e;
        }
    }

    /**
     * Stream users one row at a time.
     *
     * Uses a reusable prepared statement to insert each user row.
     *
     * @param PDO $pdo Active database connection
     * @param DatasetGenerator $datasetGen The generator for user data
     * @throws BenchmarkPreparationException If insertion fails
     */
    private function insertUsers(PDO $pdo, DatasetGenerator $datasetGen): void
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users (email, name, created_at) VALUES (?, ?, ?)'
            );

            foreach ($datasetGen->generateUsers() as $user) {
                $stmt->execute([
                    $user['email'],
                    $user['name'],
                    $user['created_at'],
                ]);
            }
        } catch (\PDOException $e) {
            throw new BenchmarkPreparationException(
                'Failed to insert users: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Stream products one row at a time.
     *
     * Uses a reusable prepared statement to insert each product row.
     *
     * @param PDO $pdo Active database connection
     * @param DatasetGenerator $datasetGen The generator for product data
     * @throws BenchmarkPreparationException If insertion fails
     */
    private function insertProducts(PDO $pdo, DatasetGenerator $datasetGen): void
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO products (sku, name, price) VALUES (?, ?, ?)'
            );

            foreach ($datasetGen->generateProducts() as $product) {
                $stmt->execute([
                    $product['sku'],
                    $product['name'],
                    (float)$product['price'],
                ]);
            }
        } catch (\PDOException $e) {
            throw new BenchmarkPreparationException(
                'Failed to insert products: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Insert all orders.
     *
     * Uses a reusable prepared statement to insert each order row.
     * Requires user IDs from insertUsers() to satisfy foreign key constraints.
     *
     * @param PDO $pdo Active database connection
     * @param DatasetGenerator $datasetGen The generator for order data
     * @throws BenchmarkPreparationException If insertion fails
     */
    private function insertOrders(
        PDO $pdo,
        DatasetGenerator $datasetGen,
    ): void {
        try {
            // Reset schemas assign contiguous IDs 1..N. Counts replace O(N) ID lists.
            $userIds = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if (empty($userIds)) {
                throw new BenchmarkPreparationException(
                    'No users found in database for order FK references'
                );
            }

            $stmt = $pdo->prepare(
                'INSERT INTO orders (user_id, status, created_at) VALUES (?, ?, ?)'
            );

            foreach ($datasetGen->generateOrders($userIds) as $order) {
                $stmt->execute([
                    (int)$order['user_id'],
                    $order['status'],
                    $order['created_at'],
                ]);
            }
        } catch (\PDOException $e) {
            throw new BenchmarkPreparationException(
                'Failed to insert orders: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Insert all order_items.
     *
     * Uses a reusable prepared statement to insert each order_item row.
     * Requires order IDs and product IDs from the database to satisfy FK constraints.
     *
     * @param PDO $pdo Active database connection
     * @param DatasetGenerator $datasetGen The generator for order_item data
     * @throws BenchmarkPreparationException If insertion fails
     */
    private function insertOrderItems(
        PDO $pdo,
        DatasetGenerator $datasetGen,
    ): void {
        try {
            // Contiguous ID counts preserve the exact generator sequence without ID arrays.
            $orderIds = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
            $productIds = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();

            if (empty($orderIds)) {
                throw new BenchmarkPreparationException(
                    'No orders found in database for order_items FK references'
                );
            }
            if (empty($productIds)) {
                throw new BenchmarkPreparationException(
                    'No products found in database for order_items FK references'
                );
            }

            $stmt = $pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)'
            );

            foreach ($datasetGen->generateOrderItems($orderIds, $productIds) as $item) {
                $stmt->execute([
                    (int)$item['order_id'],
                    (int)$item['product_id'],
                    (int)$item['quantity'],
                    (float)$item['unit_price'],
                ]);
            }
        } catch (\PDOException $e) {
            throw new BenchmarkPreparationException(
                'Failed to insert order_items: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Verify that row counts in all tables match expected values.
     *
     * Queries each table for actual row count and compares against expected
     * values based on the requested scale and cardinality factors.
     *
     * Expected row counts:
     * - users: exactly requestedRows
     * - products: ceil(requestedRows * PRODUCT_SCALE_FACTOR) ≈ 15% of users
     * - orders: ceil(requestedRows * ORDER_SCALE_FACTOR) ≈ 30% of users
     * - order_items: variable (1-5 per order, typically 3x orders)
     *
     * @param PDO $pdo Active database connection
     * @throws BenchmarkPreparationException If any count doesn't match expected
     */
    private function verifyRowCounts(PDO $pdo): void
    {
        try {
            $counts = [
                'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
                'products' => (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
                'orders' => (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
                'order_items' => (int)$pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn(),
            ];

            // Verify users count (must be exact)
            if ($counts['users'] !== $this->requestedRows) {
                throw new BenchmarkPreparationException(
                    sprintf(
                        'User count mismatch: expected %d, got %d',
                        $this->requestedRows,
                        $counts['users']
                    )
                );
            }

            // Verify products count (approximately 15% of users)
            $expectedProducts = max(1, (int)ceil($this->requestedRows * 0.15));
            $tolerance = max(1, (int)ceil($expectedProducts * 0.1)); // 10% tolerance
            if (abs($counts['products'] - $expectedProducts) > $tolerance) {
                throw new BenchmarkPreparationException(
                    sprintf(
                        'Product count unexpected: expected ~%d (±%d), got %d',
                        $expectedProducts,
                        $tolerance,
                        $counts['products']
                    )
                );
            }

            // Verify orders count (approximately 30% of users)
            $expectedOrders = max(1, (int)ceil($this->requestedRows * 0.30));
            $tolerance = max(1, (int)ceil($expectedOrders * 0.1));
            if (abs($counts['orders'] - $expectedOrders) > $tolerance) {
                throw new BenchmarkPreparationException(
                    sprintf(
                        'Order count unexpected: expected ~%d (±%d), got %d',
                        $expectedOrders,
                        $tolerance,
                        $counts['orders']
                    )
                );
            }

            // Verify order_items count (1-5 per order, typically ~3x orders)
            $minItems = $counts['orders'] * 1;
            $maxItems = $counts['orders'] * 5;
            if ($counts['order_items'] < $minItems || $counts['order_items'] > $maxItems) {
                throw new BenchmarkPreparationException(
                    sprintf(
                        'Order items count out of range: expected %d-%d (1-5 per order), got %d',
                        $minItems,
                        $maxItems,
                        $counts['order_items']
                    )
                );
            }
        } catch (\PDOException $e) {
            throw new BenchmarkPreparationException(
                'Failed to verify row counts: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
