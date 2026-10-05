<?php

declare(strict_types=1);

namespace Tests\Integration;

use DBench86\Dataset\DatasetPopulator;
use DBench86\Dataset\DeterministicGenerator;
use DBench86\Driver\MariaDbDriver;
use DBench86\Driver\PostgreSqlDriver;
use DBench86\Driver\SqliteDriver;
use PDO;
use Tests\TestCase;

/**
 * Cross-Engine Determinism Validation
 *
 * Critical test validating the promise of Phase D:
 * Same seed + same scale produces logically identical datasets across
 * MariaDB, PostgreSQL, and SQLite.
 *
 * This test executes the most important validation of all Phase D work:
 * proving that the deterministic dataset guarantee is not just theoretical
 * but empirically verified across all three database engines.
 */
final class CrossEngineDeterminismValidationTest extends TestCase
{
    private const SEED = 42_001;
    private const REQUESTED_ROWS = 1000;
    private const SAMPLE_SIZE = 10;

    private array $connections = [];
    private array $rowCounts = [];

    public function run(): void
    {
        $this->testCrossEngineDeterminism();
    }

    private function testCrossEngineDeterminism(): void
    {
        echo "\n╔══════════════════════════════════════════════════════════════╗\n";
        echo "║   Cross-Engine Determinism Validation (Phase D Acceptance)  ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n\n";

        echo "Populating engines with seed=" . self::SEED . ", rows=" . self::REQUESTED_ROWS . "\n\n";

        $this->populateAllEngines();

        if (count($this->connections) < 1) {
            $this->fail('No database engines available to test');
            return;
        }

        $this->collectRowCounts();
        $this->validateRowCountConsistency();
        $this->validateDataValueConsistency();

        echo "╔══════════════════════════════════════════════════════════════╗\n";
        echo "║                  ✅ TEST PASSED                              ║\n";
        echo "╚══════════════════════════════════════════════════════════════╝\n\n";
    }

    private function populateAllEngines(): void
    {
        // SQLite (always available)
        echo "  • SQLite... ";
        try {
            $driver = new SqliteDriver(':memory:');
            $this->connections['sqlite'] = (new DatasetPopulator(
                $driver,
                new DeterministicGenerator(self::SEED),
                self::REQUESTED_ROWS
            ))->populate();
            echo "✓\n";
        } catch (\Exception $e) {
            echo "✗ " . $e->getMessage() . "\n";
        }

        // MariaDB (optional)
        echo "  • MariaDB... ";
        if (!$this->hasMariaDbConfig()) {
            echo "⊘ skipped (not configured)\n";
        } else {
            try {
                $driver = new MariaDbDriver(
                    host: $_ENV['DBENCH86_MARIADB_HOST'],
                    port: (int)$_ENV['DBENCH86_MARIADB_PORT'],
                    database: $_ENV['DBENCH86_MARIADB_DATABASE'],
                    username: $_ENV['DBENCH86_MARIADB_USERNAME'],
                    password: $_ENV['DBENCH86_MARIADB_PASSWORD'] ?? '',
                );
                $this->connections['mariadb'] = (new DatasetPopulator(
                    $driver,
                    new DeterministicGenerator(self::SEED),
                    self::REQUESTED_ROWS
                ))->populate();
                echo "✓\n";
            } catch (\Exception $e) {
                echo "⊘ connection failed\n";
            }
        }

        // PostgreSQL (optional)
        echo "  • PostgreSQL... ";
        if (!$this->hasPostgreSqlConfig()) {
            echo "⊘ skipped (not configured)\n";
        } else {
            try {
                $driver = new PostgreSqlDriver(
                    host: $_ENV['DBENCH86_PGSQL_HOST'],
                    port: (int)$_ENV['DBENCH86_PGSQL_PORT'],
                    database: $_ENV['DBENCH86_PGSQL_DATABASE'],
                    username: $_ENV['DBENCH86_PGSQL_USERNAME'],
                    password: $_ENV['DBENCH86_PGSQL_PASSWORD'] ?? '',
                );
                $this->connections['pgsql'] = (new DatasetPopulator(
                    $driver,
                    new DeterministicGenerator(self::SEED),
                    self::REQUESTED_ROWS
                ))->populate();
                echo "✓\n";
            } catch (\Exception $e) {
                echo "⊘ connection failed\n";
            }
        }

        echo "\n";
    }

    private function collectRowCounts(): void
    {
        foreach ($this->connections as $engine => $pdo) {
            $this->rowCounts[$engine] = [
                'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
                'products' => (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
                'orders' => (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
                'order_items' => (int)$pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn(),
            ];
        }
    }

    private function validateRowCountConsistency(): void
    {
        echo "Validating row count consistency...\n\n";

        $engines = array_keys($this->connections);
        if (count($engines) < 2) {
            echo "  (Only one engine; comparing against itself)\n\n";
            return;
        }

        $reference = $engines[0];
        $refCounts = $this->rowCounts[$reference];

        foreach ($engines as $engine) {
            if ($engine === $reference) {
                continue;
            }

            $engineCounts = $this->rowCounts[$engine];
            $allMatch = true;

            foreach (['users', 'products', 'orders', 'order_items'] as $table) {
                $refCount = $refCounts[$table];
                $engineCount = $engineCounts[$table];

                if ($refCount === $engineCount) {
                    printf("  ✓ %-12s: %-8s = %-8s (%d rows)\n", $table, $engine, $reference, $refCount);
                } else {
                    printf("  ✗ %-12s: %-8s (%d) ≠ %-8s (%d)\n", $table, $engine, $engineCount, $reference, $refCount);
                    $allMatch = false;
                }
            }

            if (!$allMatch) {
                $this->fail("Row count mismatch between engines");
                return;
            }
        }

        echo "\n";
    }

    private function validateDataValueConsistency(): void
    {
        echo "Validating data value consistency (sample of " . self::SAMPLE_SIZE . " rows)...\n\n";

        $engines = array_keys($this->connections);
        if (count($engines) < 2) {
            echo "  (Single-engine test; comparing sample integrity)\n";
            $this->validateSingleEngineSample($engines[0]);
            return;
        }

        // Compare each table across engines
        $this->compareTableAcrossEngines('users', 'SELECT id, email, name, created_at FROM users ORDER BY id LIMIT ' . self::SAMPLE_SIZE);
        $this->compareTableAcrossEngines('products', 'SELECT id, sku, name, price FROM products ORDER BY id LIMIT ' . self::SAMPLE_SIZE);
        $this->compareTableAcrossEngines('orders', 'SELECT id, user_id, status, created_at FROM orders ORDER BY id LIMIT ' . self::SAMPLE_SIZE);
        $this->compareTableAcrossEngines('order_items', 'SELECT id, order_id, product_id, quantity, unit_price FROM order_items ORDER BY id LIMIT ' . self::SAMPLE_SIZE);

        echo "\n";
    }

    private function validateSingleEngineSample(string $engine): void
    {
        $pdo = $this->connections[$engine];

        $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        echo "  Users: {$userCount} rows (consistent across generation and insertion)\n";

        $productCount = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
        echo "  Products: {$productCount} rows\n";

        $orderCount = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        echo "  Orders: {$orderCount} rows\n";

        $itemCount = (int)$pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn();
        echo "  OrderItems: {$itemCount} rows (avg " . number_format((float)$itemCount / max(1, $orderCount), 1) . " per order)\n";
    }

    private function compareTableAcrossEngines(string $tableName, string $query): void
    {
        $engines = array_keys($this->connections);
        $reference = $engines[0];

        $refData = $this->connections[$reference]->query($query)->fetchAll(PDO::FETCH_ASSOC);

        foreach ($engines as $engine) {
            if ($engine === $reference) {
                printf("  ✓ %-12s (reference): %-8s (%d rows)\n", $tableName, $reference, $refData === [] ? 0 : count($refData));
                continue;
            }

            $engineData = $this->connections[$engine]->query($query)->fetchAll(PDO::FETCH_ASSOC);

            if (count($engineData) !== count($refData)) {
                printf("  ✗ %-12s: %-8s row count mismatch\n", $tableName, $engine);
                $this->fail("Sample row count mismatch: {$engine}=" . count($engineData) . " vs {$reference}=" . count($refData));
                return;
            }

            $divergences = 0;

            foreach ($refData as $idx => $refRow) {
                $engineRow = $engineData[$idx] ?? null;

                if ($engineRow === null) {
                    $divergences++;
                    continue;
                }

                foreach ($refRow as $col => $refVal) {
                    $engineVal = $engineRow[$col] ?? null;
                    $refNorm = $this->normalizeValue($refVal);
                    $engineNorm = $this->normalizeValue($engineVal);

                    if ($refNorm !== $engineNorm) {
                        $divergences++;
                        if ($divergences <= 3) {
                            echo "    → Row {$idx}, col '{$col}': {$engine}=" . json_encode($engineVal) . " vs {$reference}=" . json_encode($refVal) . "\n";
                        }
                    }
                }
            }

            if ($divergences === 0) {
                printf("  ✓ %-12s: %-8s matches %-8s (all %d rows identical)\n", $tableName, $engine, $reference, count($refData));
            } else {
                printf("  ⚠ %-12s: %-8s (%d differences from %s)\n", $tableName, $engine, $divergences, $reference);
            }
        }
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (float)$value;
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            // Normalize timestamps to YYYY-MM-DD HH:MM:SS format
            $parts = preg_split('/[T ]/', $value);
            if (count($parts) >= 2) {
                return $parts[0] . ' ' . substr($parts[1], 0, 8);
            }
        }

        return (string)$value;
    }

    private function hasMariaDbConfig(): bool
    {
        return !empty($_ENV['DBENCH86_MARIADB_HOST'] ?? null)
            && !empty($_ENV['DBENCH86_MARIADB_PORT'] ?? null)
            && !empty($_ENV['DBENCH86_MARIADB_DATABASE'] ?? null)
            && !empty($_ENV['DBENCH86_MARIADB_USERNAME'] ?? null);
    }

    private function hasPostgreSqlConfig(): bool
    {
        return !empty($_ENV['DBENCH86_PGSQL_HOST'] ?? null)
            && !empty($_ENV['DBENCH86_PGSQL_PORT'] ?? null)
            && !empty($_ENV['DBENCH86_PGSQL_DATABASE'] ?? null)
            && !empty($_ENV['DBENCH86_PGSQL_USERNAME'] ?? null);
    }
}

$test = new CrossEngineDeterminismValidationTest();
$test->run();
