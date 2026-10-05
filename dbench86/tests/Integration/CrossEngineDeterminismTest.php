<?php

declare(strict_types=1);

namespace Tests\Integration;

use DBench86\Dataset\DatasetGenerator;
use DBench86\Dataset\DatasetPopulator;
use DBench86\Dataset\DeterministicGenerator;
use DBench86\Driver\MariaDbDriver;
use DBench86\Driver\PostgreSqlDriver;
use DBench86\Driver\SqliteDriver;
use PDO;

/**
 * Cross-Engine Determinism Test
 *
 * Validates that the same seed produces logically equivalent datasets across
 * all three supported database engines (MariaDB, PostgreSQL, SQLite).
 *
 * This is the critical validation of the "Deterministic Dataset" promise:
 * identical logical values (users, products, orders, order_items) regardless
 * of target database dialect.
 */
final class CrossEngineDeterminismTest
{
    private const SEED = 42_001;
    private const REQUESTED_ROWS = 1000;
    private const SAMPLE_SIZE = 10;

    /**
     * @var array<string, PDO>
     */
    private array $connections = [];

    /**
     * @var array<string, array{
     *   users: int,
     *   products: int,
     *   orders: int,
     *   order_items: int
     * }>
     */
    private array $rowCounts = [];

    /**
     * @var array<string, array<mixed>>
     */
    private array $sampleUsers = [];

    /**
     * @var array<string, array<mixed>>
     */
    private array $sampleProducts = [];

    /**
     * @var array<string, array<mixed>>
     */
    private array $sampleOrders = [];

    /**
     * @var array<string, array<mixed>>
     */
    private array $sampleOrderItems = [];

    private int $divergenceCount = 0;

    public function run(): void
    {
        echo "\n╔════════════════════════════════════════════╗\n";
        echo "║  Cross-Engine Determinism Test (Phase D)  ║\n";
        echo "╚════════════════════════════════════════════╝\n\n";

        $this->populateEngines();
        $this->collectRowCounts();
        $this->collectSamples();
        $this->compareRowCounts();
        $this->compareDataValues();
        $this->printResults();
    }

    private function populateEngines(): void
    {
        echo "Populating engines with seed=" . self::SEED . ", rows=" . self::REQUESTED_ROWS . "...\n\n";

        // SQLite (always available)
        echo "  SQLite... ";
        $sqliteDriver = new SqliteDriver(':memory:');
        $sqliteGen = new DeterministicGenerator(self::SEED);
        $this->connections['sqlite'] = (new DatasetPopulator(
            $sqliteDriver,
            $sqliteGen,
            self::REQUESTED_ROWS
        ))->populate();
        echo "✓\n";

        // MariaDB (optional)
        if ($this->hasMariaDbConfig()) {
            echo "  MariaDB... ";
            $mariaDbDriver = new MariaDbDriver(
                host: $_ENV['DBENCH86_MARIADB_HOST'],
                port: (int)$_ENV['DBENCH86_MARIADB_PORT'],
                database: $_ENV['DBENCH86_MARIADB_DATABASE'],
                username: $_ENV['DBENCH86_MARIADB_USERNAME'],
                password: $_ENV['DBENCH86_MARIADB_PASSWORD'] ?? '',
            );
            $this->connections['mariadb'] = (new DatasetPopulator(
                $mariaDbDriver,
                new DeterministicGenerator(self::SEED),
                self::REQUESTED_ROWS
            ))->populate();
            echo "✓\n";
        } else {
            \Tests\test_runner()->skip("MariaDB determinism not configured");
        }

        // PostgreSQL (optional)
        if ($this->hasPostgreSqlConfig()) {
            echo "  PostgreSQL... ";
            $postgresDriver = new PostgreSqlDriver(
                host: $_ENV['DBENCH86_PGSQL_HOST'],
                port: (int)$_ENV['DBENCH86_PGSQL_PORT'],
                database: $_ENV['DBENCH86_PGSQL_DATABASE'],
                username: $_ENV['DBENCH86_PGSQL_USERNAME'],
                password: $_ENV['DBENCH86_PGSQL_PASSWORD'] ?? '',
            );
            $this->connections['pgsql'] = (new DatasetPopulator(
                $postgresDriver,
                new DeterministicGenerator(self::SEED),
                self::REQUESTED_ROWS
            ))->populate();
            echo "✓\n";
        } else {
            \Tests\test_runner()->skip("PostgreSQL determinism not configured");
        }

        echo "\n";
    }

    private function collectRowCounts(): void
    {
        foreach ($this->connections as $engine => $pdo) {
            $this->rowCounts[$engine] = [
                'users' => $this->countRows($pdo, 'users'),
                'products' => $this->countRows($pdo, 'products'),
                'orders' => $this->countRows($pdo, 'orders'),
                'order_items' => $this->countRows($pdo, 'order_items'),
            ];
        }
    }

    private function collectSamples(): void
    {
        foreach ($this->connections as $engine => $pdo) {
            $this->sampleUsers[$engine] = $pdo->query(
                "SELECT id, email, name, created_at FROM users ORDER BY id LIMIT " . self::SAMPLE_SIZE
            )->fetchAll(PDO::FETCH_ASSOC);

            $this->sampleProducts[$engine] = $pdo->query(
                "SELECT id, sku, name, price FROM products ORDER BY id LIMIT " . self::SAMPLE_SIZE
            )->fetchAll(PDO::FETCH_ASSOC);

            $this->sampleOrders[$engine] = $pdo->query(
                "SELECT id, user_id, status, created_at FROM orders ORDER BY id LIMIT " . self::SAMPLE_SIZE
            )->fetchAll(PDO::FETCH_ASSOC);

            $this->sampleOrderItems[$engine] = $pdo->query(
                "SELECT id, order_id, product_id, quantity, unit_price FROM order_items ORDER BY id LIMIT " . self::SAMPLE_SIZE
            )->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    private function compareRowCounts(): void
    {
        echo "Comparing row counts across engines...\n";

        $engines = array_keys($this->connections);
        $reference = array_shift($engines);

        $refCounts = $this->rowCounts[$reference];

        foreach ($engines as $engine) {
            $engineCounts = $this->rowCounts[$engine];

            foreach (['users', 'products', 'orders', 'order_items'] as $table) {
                $refCount = $refCounts[$table];
                $engineCount = $engineCounts[$table];

                if ($refCount === $engineCount) {
                    echo "  ✓ {$table}: {$engine} = {$reference} ({$refCount} rows)\n";
                } else {
                    echo "  ✗ {$table}: {$engine} ({$engineCount}) ≠ {$reference} ({$refCount})\n";
                    throw new \RuntimeException(
                        "Row count mismatch in {$table}: {$engine}={$engineCount} vs {$reference}={$refCount}"
                    );
                }
            }
        }

        echo "\n";
    }

    private function compareDataValues(): void
    {
        echo "Comparing sample data values...\n\n";

        // Compare users
        echo "Users (first " . self::SAMPLE_SIZE . "):\n";
        $this->compareTable('users', $this->sampleUsers);

        // Compare products
        echo "\nProducts (first " . self::SAMPLE_SIZE . "):\n";
        $this->compareTable('products', $this->sampleProducts);

        // Compare orders
        echo "\nOrders (first " . self::SAMPLE_SIZE . "):\n";
        $this->compareTable('orders', $this->sampleOrders);

        // Compare order_items
        echo "\nOrderItems (first " . self::SAMPLE_SIZE . "):\n";
        $this->compareTable('order_items', $this->sampleOrderItems);

        echo "\n";
    }

    private function compareTable(string $tableName, array $samplesByEngine): void
    {
        $engines = array_keys($samplesByEngine);
        $reference = $engines[0];
        $referenceRows = $samplesByEngine[$reference];

        foreach ($engines as $engine) {
            if ($engine === $reference) {
                continue;
            }

            $engineRows = $samplesByEngine[$engine];

            if (count($engineRows) !== count($referenceRows)) {
                echo "  ⚠ Row count mismatch: {$engine}=" . count($engineRows)
                    . " vs {$reference}=" . count($referenceRows) . "\n";
                $this->divergenceCount++;
                continue;
            }

            $divergences = 0;

            foreach ($referenceRows as $idx => $refRow) {
                if (!isset($engineRows[$idx])) {
                    echo "  ✗ {$engine} row $idx missing\n";
                    $divergences++;
                    $this->divergenceCount++;
                    continue;
                }

                $engineRow = $engineRows[$idx];

                foreach ($refRow as $column => $refValue) {
                    if (!isset($engineRow[$column])) {
                        echo "  ✗ {$engine} row $idx missing column '$column'\n";
                        $divergences++;
                        $this->divergenceCount++;
                        continue;
                    }

                    $engineValue = $engineRow[$column];

                    // Normalize numeric values for comparison (handle float precision)
                    $refNorm = $this->normalizeValue($refValue);
                    $engineNorm = $this->normalizeValue($engineValue);

                    if ($refNorm !== $engineNorm) {
                        echo "  ✗ {$tableName} row $idx, column '{$column}': ";
                        echo "{$engine}=" . json_encode($engineValue) . " ";
                        echo "vs {$reference}=" . json_encode($refValue) . "\n";
                        $divergences++;
                        $this->divergenceCount++;
                    }
                }
            }

            if ($divergences === 0) {
                echo "  ✓ {$engine} matches {$reference} (all " . count($referenceRows) . " rows identical)\n";
            } else {
                echo "  ⚠ {$engine} diverges from {$reference} ({$divergences} differences found)\n";
            }
        }
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // For numeric strings (prices, timestamps, etc.)
        if (is_string($value)) {
            // Try to parse as float for price comparison (handles DECIMAL vs REAL)
            if (is_numeric($value)) {
                return (float)$value;
            }

            // Normalize ISO timestamps to common format
            if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]/', $value)) {
                // Strip timezone suffix if present (PostgreSQL returns "+00", "+00:00", etc.)
                // Regex matches: [+-]HH or [+-]HHMM or [+-]HH:MM
                $normalized = preg_replace('/[+-]\d{2}(?::?\d{2})?\s*$/', '', $value);

                // Parse and re-format to ensure consistency
                try {
                    $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $normalized);
                    if ($dt) {
                        return $dt->format('Y-m-d H:i:s');
                    }
                    // Try ISO format with T
                    $dt = \DateTime::createFromFormat('Y-m-d\TH:i:s', $normalized);
                    if ($dt) {
                        return $dt->format('Y-m-d H:i:s');
                    }
                } catch (\Exception) {
                    // Fall through to return normalized as-is
                }

                return $normalized;
            }

            return $value;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return $value;
        }

        return $value;
    }

    private function printResults(): void
    {
        echo "╔════════════════════════════════════════════╗\n";
        echo "║            Test Summary                    ║\n";
        echo "╚════════════════════════════════════════════╝\n\n";

        echo "Seed:           " . self::SEED . "\n";
        echo "Requested rows: " . self::REQUESTED_ROWS . "\n";
        echo "Engines tested: " . implode(', ', array_keys($this->connections)) . "\n";
        echo "Sample size:    " . self::SAMPLE_SIZE . " rows per table\n\n";

        echo "Row Counts:\n";
        foreach ($this->rowCounts as $engine => $counts) {
            echo "  {$engine}:\n";
            echo "    users:       " . $counts['users'] . "\n";
            echo "    products:    " . $counts['products'] . "\n";
            echo "    orders:      " . $counts['orders'] . "\n";
            echo "    order_items: " . $counts['order_items'] . "\n";
        }

        echo "\n";

        if ($this->divergenceCount > 0) {
            echo "❌ TEST FAILED: {$this->divergenceCount} divergences detected in data values!\n";
            \Tests\test_runner()->fail('Cross-engine data divergences: ' . $this->divergenceCount);
        } else {
            \Tests\test_runner()->assertTrue(true, "Cross-engine determinism validated");
        }
    }

    private function countRows(PDO $pdo, string $table): int
    {
        return (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    private function hasMariaDbConfig(): bool
    {
        return isset($_ENV['DBENCH86_MARIADB_HOST'])
            && isset($_ENV['DBENCH86_MARIADB_PORT'])
            && isset($_ENV['DBENCH86_MARIADB_DATABASE'])
            && isset($_ENV['DBENCH86_MARIADB_USERNAME']);
    }

    private function hasPostgreSqlConfig(): bool
    {
        return isset($_ENV['DBENCH86_PGSQL_HOST'])
            && isset($_ENV['DBENCH86_PGSQL_PORT'])
            && isset($_ENV['DBENCH86_PGSQL_DATABASE'])
            && isset($_ENV['DBENCH86_PGSQL_USERNAME']);
    }
}

// Exceptions are accounted for by the canonical runner.
(new CrossEngineDeterminismTest())->run();
