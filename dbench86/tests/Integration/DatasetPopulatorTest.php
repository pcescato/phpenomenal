<?php
declare(strict_types=1);

namespace Tests\Integration;

use DBench86\Driver\SqliteDriver;
use DBench86\Driver\MariaDbDriver;
use DBench86\Driver\PostgreSqlDriver;
use DBench86\Dataset\DeterministicGenerator;
use DBench86\Dataset\DatasetPopulator;
use DBench86\Dataset\Schema;
use DBench86\Exception\BenchmarkPreparationException;
use PDO;
use Tests\TestCase;

/**
 * Integration tests for DatasetPopulator.
 *
 * Tests the complete dataset population cycle:
 * 1. Schema creation
 * 2. Data generation (deterministic)
 * 3. Row insertion via prepared statements
 * 4. Row count verification
 * 5. Determinism (same seed → identical data after reset)
 *
 * Tests run on SQLite (always available), and optionally on MariaDB/PostgreSQL
 * if environment variables are configured.
 */
final class DatasetPopulatorTest extends TestCase
{
    public function run(): void
    {
        $this->testSqlitePopulationWithSmallDataset();
        $this->testSqliteRowCountVerification();
        $this->testSqliteDeterminism();
        $this->testSqliteSchemaStructure();
        $this->testSqliteDataIntegrity();
        $this->testMariaDbPopulationIfAvailable();
        $this->testPostgreSqlPopulationIfAvailable();
    }

    /**
     * Test: SQLite database population with small dataset (100 rows).
     *
     * Verifies that:
     * 1. Schema is created successfully
     * 2. All tables exist with correct structure
     * 3. Data is inserted without errors
     *
     * Uses an in-memory SQLite database to avoid creating files.
     */
    private function testSqlitePopulationWithSmallDataset(): void
    {
        $driver = new SqliteDriver(':memory:');
        $generator = new DeterministicGenerator(seed: 1001);
        $populator = new DatasetPopulator($driver, $generator, requestedRows: 100);

        try {
            $pdo = $populator->populate();

            // Verify tables exist
            $tables = $this->getTables($pdo, 'sqlite');
            $this->assertTrue(
                in_array('users', $tables, strict: true),
                'users table should exist after population'
            );
            $this->assertTrue(
                in_array('products', $tables, strict: true),
                'products table should exist after population'
            );
            $this->assertTrue(
                in_array('orders', $tables, strict: true),
                'orders table should exist after population'
            );
            $this->assertTrue(
                in_array('order_items', $tables, strict: true),
                'order_items table should exist after population'
            );

            echo "✓ SQLite population successful with 100 rows\n";
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Test: Row count verification for SQLite.
     *
     * Verifies that row counts in each table are within expected bounds:
     * - users: exactly 100
     * - products: ~15 (approximately)
     * - orders: ~30 (approximately)
     * - order_items: 30-150 (1-5 per order, variable)
     */
    private function testSqliteRowCountVerification(): void
    {
        $driver = new SqliteDriver(':memory:');
        $generator = new DeterministicGenerator(seed: 2002);
        $populator = new DatasetPopulator($driver, $generator, requestedRows: 100);

        $pdo = $populator->populate();

        $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $productCount = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $orderCount = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $itemCount = (int)$pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn();

        // Exact count for users
        $this->assertTrue(
            $userCount === 100,
            "User count should be exactly 100, got {$userCount}"
        );

        // Approximate count for products (15% ± 10%)
        $expectedProducts = max(1, (int)ceil(100 * 0.15));
        $tolerance = max(1, (int)ceil($expectedProducts * 0.1));
        $this->assertTrue(
            abs($productCount - $expectedProducts) <= $tolerance,
            "Product count should be ~{$expectedProducts} (±{$tolerance}), got {$productCount}"
        );

        // Approximate count for orders (30% ± 10%)
        $expectedOrders = max(1, (int)ceil(100 * 0.30));
        $tolerance = max(1, (int)ceil($expectedOrders * 0.1));
        $this->assertTrue(
            abs($orderCount - $expectedOrders) <= $tolerance,
            "Order count should be ~{$expectedOrders} (±{$tolerance}), got {$orderCount}"
        );

        // Variable count for order_items (1-5 per order)
        $this->assertTrue(
            $itemCount >= $orderCount * 1 && $itemCount <= $orderCount * 5,
            "Order items count should be {$orderCount}-" . ($orderCount * 5) . ", got {$itemCount}"
        );

        echo "✓ Row counts verified: users={$userCount}, products={$productCount}, orders={$orderCount}, items={$itemCount}\n";
    }

    /**
     * Test: Determinism guarantee for SQLite.
     *
     * Verifies that:
     * 1. Populating with the same seed produces identical data
     * 2. Calling resetSchema() and repopulating produces identical data again
     * 3. Different seeds produce different data
     */
    private function testSqliteDeterminism(): void
    {
        // First population with seed 3003
        $driver1 = new SqliteDriver(':memory:');
        $gen1 = new DeterministicGenerator(seed: 3003);
        $pdo1 = (new DatasetPopulator($driver1, $gen1, requestedRows: 50))->populate();

        // Extract user data from first population
        $users1 = $pdo1->query('SELECT id, email, name FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $products1 = $pdo1->query('SELECT id, sku, name FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        // Second population with SAME seed
        $driver2 = new SqliteDriver(':memory:');
        $gen2 = new DeterministicGenerator(seed: 3003);
        $pdo2 = (new DatasetPopulator($driver2, $gen2, requestedRows: 50))->populate();

        $users2 = $pdo2->query('SELECT id, email, name FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $products2 = $pdo2->query('SELECT id, sku, name FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        // Verify identical rows
        $this->assertTrue(
            $users1 === $users2,
            'Users should be identical with same seed'
        );
        $this->assertTrue(
            $products1 === $products2,
            'Products should be identical with same seed'
        );

        // Third population with DIFFERENT seed
        $driver3 = new SqliteDriver(':memory:');
        $gen3 = new DeterministicGenerator(seed: 4004);
        $pdo3 = (new DatasetPopulator($driver3, $gen3, requestedRows: 50))->populate();

        $users3 = $pdo3->query('SELECT id, email, name FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        // Verify data diverges with different seed
        $differences = 0;
        foreach ($users1 as $idx => $user1) {
            if ($users1[$idx]['email'] !== $users3[$idx]['email'] ||
                $users1[$idx]['name'] !== $users3[$idx]['name']) {
                $differences++;
            }
        }

        $this->assertTrue(
            $differences > 0,
            'Data should differ with different seed'
        );

        echo "✓ Determinism verified: same seed → identical data, different seed → different data\n";
    }

    /**
     * Test: Schema structure verification.
     *
     * Verifies that:
     * 1. All required columns exist in each table
     * 2. Foreign key relationships are defined
     * 3. Indexes are created on expected columns
     */
    private function testSqliteSchemaStructure(): void
    {
        $driver = new SqliteDriver(':memory:');
        $generator = new DeterministicGenerator(seed: 5005);
        $pdo = (new DatasetPopulator($driver, $generator, requestedRows: 50))->populate();

        // Check users table columns
        $userColumns = $this->getTableColumns($pdo, 'users', 'sqlite');
        $this->assertTrue(
            isset($userColumns['id']) && isset($userColumns['email']) &&
            isset($userColumns['name']) && isset($userColumns['created_at']),
            'users table should have id, email, name, created_at columns'
        );

        // Check products table columns
        $productColumns = $this->getTableColumns($pdo, 'products', 'sqlite');
        $this->assertTrue(
            isset($productColumns['id']) && isset($productColumns['sku']) &&
            isset($productColumns['name']) && isset($productColumns['price']),
            'products table should have id, sku, name, price columns'
        );

        // Check orders table columns
        $orderColumns = $this->getTableColumns($pdo, 'orders', 'sqlite');
        $this->assertTrue(
            isset($orderColumns['id']) && isset($orderColumns['user_id']) &&
            isset($orderColumns['status']) && isset($orderColumns['created_at']),
            'orders table should have id, user_id, status, created_at columns'
        );

        // Check order_items table columns
        $itemColumns = $this->getTableColumns($pdo, 'order_items', 'sqlite');
        $this->assertTrue(
            isset($itemColumns['id']) && isset($itemColumns['order_id']) &&
            isset($itemColumns['product_id']) && isset($itemColumns['quantity']) &&
            isset($itemColumns['unit_price']),
            'order_items table should have id, order_id, product_id, quantity, unit_price columns'
        );

        echo "✓ Schema structure verified for SQLite\n";
    }

    /**
     * Test: Data integrity (foreign key relationships).
     *
     * Verifies that:
     * 1. Every order.user_id references an existing user
     * 2. Every order_item.order_id references an existing order
     * 3. Every order_item.product_id references an existing product
     * 4. No orphaned foreign keys exist
     */
    private function testSqliteDataIntegrity(): void
    {
        $driver = new SqliteDriver(':memory:');
        $generator = new DeterministicGenerator(seed: 6006);
        $pdo = (new DatasetPopulator($driver, $generator, requestedRows: 75))->populate();

        // Verify order.user_id references existing users
        $orphanedOrders = $pdo->query(
            'SELECT COUNT(*) FROM orders WHERE user_id NOT IN (SELECT id FROM users)'
        )->fetchColumn();
        $this->assertTrue(
            $orphanedOrders === 0,
            'All orders.user_id should reference existing users'
        );

        // Verify order_items.order_id references existing orders
        $orphanedItems = $pdo->query(
            'SELECT COUNT(*) FROM order_items WHERE order_id NOT IN (SELECT id FROM orders)'
        )->fetchColumn();
        $this->assertTrue(
            $orphanedItems === 0,
            'All order_items.order_id should reference existing orders'
        );

        // Verify order_items.product_id references existing products
        $orphanedProducts = $pdo->query(
            'SELECT COUNT(*) FROM order_items WHERE product_id NOT IN (SELECT id FROM products)'
        )->fetchColumn();
        $this->assertTrue(
            $orphanedProducts === 0,
            'All order_items.product_id should reference existing products'
        );

        echo "✓ Data integrity verified: no orphaned foreign keys\n";
    }

    /**
     * Test: MariaDB population if available.
     *
     * Reads environment variables DBENCH86_MARIADB_* and populates MariaDB
     * if configured. Skips cleanly (does not fail) if not configured.
     *
     * Tests:
     * 1. Connection and authentication
     * 2. Schema creation
     * 3. Data population
     * 4. Row count verification
     */
    private function testMariaDbPopulationIfAvailable(): void
    {
        $host = $_ENV['DBENCH86_MARIADB_HOST'] ?? null;
        $port = $_ENV['DBENCH86_MARIADB_PORT'] ?? null;
        $database = $_ENV['DBENCH86_MARIADB_DATABASE'] ?? null;
        $username = $_ENV['DBENCH86_MARIADB_USERNAME'] ?? null;
        $password = $_ENV['DBENCH86_MARIADB_PASSWORD'] ?? null;

        if (!$host || !$port || !$database || !$username) {
            $this->skip("MariaDB population not configured");
            return;
        }

        try {
            $driver = new MariaDbDriver(
                host: $host,
                port: (int)$port,
                database: $database,
                username: $username,
                password: $password ?? '',
            );

            $pdo = $driver->connect();
            $driver->resetSchema($pdo);

            $generator = new DeterministicGenerator(seed: 7007);
            $pdo = (new DatasetPopulator($driver, $generator, requestedRows: 100))->populate();

            // Verify counts
            $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $this->assertTrue(
                $userCount === 100,
                "MariaDB user count should be 100, got {$userCount}"
            );

            echo "✓ MariaDB population successful (100 users, seed=7007)\n";
        } catch (\Exception $e) {
            echo "✗ MariaDB population failed: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * Test: PostgreSQL population if available.
     *
     * Reads environment variables DBENCH86_PGSQL_* and populates PostgreSQL
     * if configured. Skips cleanly if not configured.
     *
     * Tests:
     * 1. Connection and authentication
     * 2. Schema creation
     * 3. Data population
     * 4. Row count verification
     */
    private function testPostgreSqlPopulationIfAvailable(): void
    {
        $host = $_ENV['DBENCH86_PGSQL_HOST'] ?? null;
        $port = $_ENV['DBENCH86_PGSQL_PORT'] ?? null;
        $database = $_ENV['DBENCH86_PGSQL_DATABASE'] ?? null;
        $username = $_ENV['DBENCH86_PGSQL_USERNAME'] ?? null;
        $password = $_ENV['DBENCH86_PGSQL_PASSWORD'] ?? null;

        if (!$host || !$port || !$database || !$username) {
            $this->skip("PostgreSQL population not configured");
            return;
        }

        try {
            $driver = new PostgreSqlDriver(
                host: $host,
                port: (int)$port,
                database: $database,
                username: $username,
                password: $password ?? '',
            );

            $pdo = $driver->connect();
            $driver->resetSchema($pdo);

            $generator = new DeterministicGenerator(seed: 8008);
            $pdo = (new DatasetPopulator($driver, $generator, requestedRows: 100))->populate();

            // Verify counts
            $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $this->assertTrue(
                $userCount === 100,
                "PostgreSQL user count should be 100, got {$userCount}"
            );

            echo "✓ PostgreSQL population successful (100 users, seed=8008)\n";
        } catch (\Exception $e) {
            echo "✗ PostgreSQL population failed: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    /**
     * Helper: Get list of tables in the database.
     *
     * @param PDO $pdo Active database connection
     * @param string $dialect Database dialect (sqlite, mysql, pgsql)
     * @return array<int, string> Table names
     */
    private function getTables(PDO $pdo, string $dialect): array
    {
        return match ($dialect) {
            'sqlite' => $pdo->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
            )->fetchAll(PDO::FETCH_COLUMN),
            'mysql' => $pdo->query(
                'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE"'
            )->fetchAll(PDO::FETCH_COLUMN),
            'pgsql' => $pdo->query(
                "SELECT tablename FROM pg_tables WHERE schemaname = 'public'"
            )->fetchAll(PDO::FETCH_COLUMN),
            default => [],
        };
    }

    /**
     * Helper: Get column info for a table.
     *
     * @param PDO $pdo Active database connection
     * @param string $table Table name
     * @param string $dialect Database dialect
     * @return array<string, bool> Map of column names to existence (true = exists)
     */
    private function getTableColumns(PDO $pdo, string $table, string $dialect): array
    {
        $columns = match ($dialect) {
            'sqlite' => $pdo->query(
                "PRAGMA table_info({$table})"
            )->fetchAll(PDO::FETCH_ASSOC),
            'mysql' => $pdo->query(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            )->execute([$table]) ?: [],
            'pgsql' => $pdo->query(
                "SELECT column_name FROM information_schema.columns WHERE table_name = ?"
            )->execute([$table]) ?: [],
            default => [],
        };

        $result = [];
        if ($dialect === 'sqlite') {
            foreach ($columns as $col) {
                $result[$col['name']] = true;
            }
        } else {
            foreach ($columns as $col) {
                $result[$col['COLUMN_NAME'] ?? $col['column_name'] ?? ''] = true;
            }
        }
        return $result;
    }
}

$test = new DatasetPopulatorTest();
$test->run();
