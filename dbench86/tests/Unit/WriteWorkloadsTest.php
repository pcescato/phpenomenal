<?php
declare(strict_types=1);

namespace Tests\Unit;

use DBench86\Dataset\DatasetPopulator;
use DBench86\Dataset\DeterministicGenerator;
use DBench86\Driver\SqliteDriver;
use DBench86\Workload\BatchInsertWorkload;
use DBench86\Workload\DeleteByPrimaryKeyWorkload;
use DBench86\Workload\SingleInsertWorkload;
use DBench86\Workload\TransactionalInsertWorkload;
use DBench86\Workload\UpdateByPrimaryKeyWorkload;
use PDO;
use Tests\TestCase;
use Tests\Support\TestBatchInsertWorkload;

final class WriteWorkloadsTest extends TestCase
{
    private SqliteDriver|null $driver = null;
    private PDO|null $connection = null;
    private string $dbPath = '';

    public function testSingleInsertWorkload(): void
    {
        $this->setupTestDatabase();
        $initialProductCount = $this->getProductCount();
        $workload = new SingleInsertWorkload();

        $this->assertTrue($workload->name() === 'single_insert', 'Workload name should be single_insert');
        $this->assertTrue($workload->boundParametersPerRow() === null, 'Single insert should return null for bound parameters');

        $workload->prepare($this->connection);
        $workload->cleanup($this->connection);

        // After cleanup, should be back to initial state
        $afterCleanupCount = $this->getProductCount();
        $this->assertTrue(
            $afterCleanupCount === $initialProductCount,
            "After cleanup, product count should be restored"
        );

        $this->cleanupTestDatabase();
    }

    public function testBatchInsertWorkload(): void
    {
        $this->setupTestDatabase();
        $initialProductCount = $this->getProductCount();

        $requestedBatchSize = 500;
        $workload = TestBatchInsertWorkload::createForTesting($requestedBatchSize);

        $this->assertTrue($workload->name() === 'batch_insert', 'Workload name should be batch_insert');
        $this->assertTrue(
            $workload->boundParametersPerRow() === 3,
            'Batch insert should return 3 for bound parameters (sku, name, price)'
        );

        $workload->prepare($this->connection);
        $workload->cleanup($this->connection);

        $afterCleanupCount = $this->getProductCount();
        $this->assertTrue(
            $afterCleanupCount === $initialProductCount,
            "After cleanup, product count should be restored"
        );

        $this->cleanupTestDatabase();
    }

    public function testBatchInsertWorkloadRespectsBatchLimit(): void
    {
        $this->setupTestDatabase();

        // Request a batch size much larger than the effective limit
        $requestedBatchSize = 5000;
        $workload = TestBatchInsertWorkload::createForTesting($requestedBatchSize);

        $workload->prepare($this->connection);

        $initialProductCount = $this->getProductCount();

        // Execute once
        $rowsInserted = $workload->execute($this->connection);

        // With 3 parameters per row and 10,000 max: effective = 10,000 / 3 = 3,333
        // Since we requested 5000, effective should be min(5000, 3333) = 3,333
        $maxEffective = (int)floor(10_000 / 3);
        $expectedMax = min($requestedBatchSize, $maxEffective);

        $this->assertTrue(
            $rowsInserted <= $expectedMax,
            "Batch size should respect the calculated effective limit: {$rowsInserted} <= {$expectedMax}"
        );

        $workload->cleanup($this->connection);
        $this->cleanupTestDatabase();
    }

    public function testBatchInsertWorkloadUsesCorrectDriverLimit(): void
    {
        $this->setupTestDatabase();

        // Create BatchInsertWorkload with SQLite driver (MAX_BOUND_PARAMETERS=900)
        // With 3 params per row: limit = min(500, 900/3) = min(500, 300) = 300
        $requestedBatchSize = 400; // Larger than SQLite limit but smaller than MariaDB
        $workloadWithDriver = new BatchInsertWorkload($this->driver, $requestedBatchSize);

        $workloadWithDriver->prepare($this->connection);
        $rowsInsertedWithDriver = $workloadWithDriver->execute($this->connection);
        $workloadWithDriver->cleanup($this->connection);

        // Create BatchInsertWorkload with TestDriver (uses AbstractDriver defaults)
        // AbstractDriver: MAX_BOUND_PARAMETERS=10_000, so limit = min(500, 10000/3) = min(500, 3333) = 500
        $workloadWithoutDriver = TestBatchInsertWorkload::createForTesting($requestedBatchSize);
        $workloadWithoutDriver->prepare($this->connection);
        $rowsInsertedWithoutDriver = $workloadWithoutDriver->execute($this->connection);
        $workloadWithoutDriver->cleanup($this->connection);

        // SQLite driver should produce fewer rows due to stricter limit (300 vs 400)
        // With TestDriver falls back to min(400, 500) = 400
        $this->assertTrue(
            $rowsInsertedWithDriver < $rowsInsertedWithoutDriver,
            "BatchInsertWorkload with SQLite driver should insert fewer rows ({$rowsInsertedWithDriver}) " .
            "than with TestDriver ({$rowsInsertedWithoutDriver}) due to stricter SQLite parameter limit"
        );

        $this->cleanupTestDatabase();
    }

    public function testTransactionalInsertWorkload(): void
    {
        $this->setupTestDatabase();
        $initialProductCount = $this->getProductCount();

        $rowsPerTransaction = 10;
        $workload = new TransactionalInsertWorkload($rowsPerTransaction);

        $this->assertTrue($workload->name() === 'transactional_insert', 'Workload name should be transactional_insert');

        $workload->prepare($this->connection);
        $workload->cleanup($this->connection);

        $afterCleanupCount = $this->getProductCount();
        $this->assertTrue(
            $afterCleanupCount === $initialProductCount,
            "After cleanup, product count should be restored"
        );

        $this->cleanupTestDatabase();
    }

    public function testUpdateByPrimaryKeyWorkload(): void
    {
        $this->setupTestDatabase();
        $workload = new UpdateByPrimaryKeyWorkload();

        $this->assertTrue($workload->name() === 'update_by_pk', 'Workload name should be update_by_pk');

        // Inspect the first deterministic sampled user before modification.
        $selectedId = (new \DBench86\Workload\DeterministicSamples())->ids(500, $workload->name())[0];
        $stmt = $this->connection->prepare('SELECT name FROM users WHERE id = ' . $selectedId);
        $stmt->execute();
        $originalName = $stmt->fetchColumn();

        $workload->prepare($this->connection);
        $workload->execute($this->connection);

        // After execute, names should be modified
        $stmt = $this->connection->prepare('SELECT name FROM users WHERE id = ' . $selectedId);
        $stmt->execute();
        $modifiedName = $stmt->fetchColumn();
        $this->assertTrue(
            $modifiedName !== $originalName,
            "User name should be modified after execute"
        );

        $workload->cleanup($this->connection);

        // After cleanup, names should be restored
        $stmt = $this->connection->prepare('SELECT name FROM users WHERE id = ' . $selectedId);
        $stmt->execute();
        $restoredName = $stmt->fetchColumn();
        $this->assertTrue(
            $restoredName === $originalName,
            "User name should be restored after cleanup"
        );

        $this->cleanupTestDatabase();
    }

    public function testDeleteByPrimaryKeyWorkload(): void
    {
        $this->setupTestDatabase();
        $initialProductCount = $this->getProductCount();
        $workload = new DeleteByPrimaryKeyWorkload();

        $this->assertTrue($workload->name() === 'delete_by_pk', 'Workload name should be delete_by_pk');

        $workload->prepare($this->connection);

        // Execute 5 times to delete 5 products
        for ($i = 0; $i < 5; $i++) {
            $workload->execute($this->connection);
        }

        // After deletes, product count should be reduced
        $afterDeleteCount = $this->getProductCount();
        $this->assertTrue(
            $afterDeleteCount === $initialProductCount - 5,
            "Should have deleted 5 products"
        );

        $workload->cleanup($this->connection);

        // After cleanup, products should be restored
        $afterCleanupCount = $this->getProductCount();
        $this->assertTrue(
            $afterCleanupCount === $initialProductCount,
            "After cleanup, product count should be restored"
        );

        $this->cleanupTestDatabase();
    }

    public function testAllWriteWorkloadsTogetherMaintainIsolation(): void
    {
        $this->setupTestDatabase();

        $workloads = [
            new SingleInsertWorkload(),
            TestBatchInsertWorkload::createForTesting(100),
            new TransactionalInsertWorkload(5),
            new UpdateByPrimaryKeyWorkload(),
            new DeleteByPrimaryKeyWorkload(),
        ];

        $connection = $this->connection;
        $initialProductCount = $this->getProductCount();
        $initialUserCount = $this->getUserCount();

        // Prepare all
        foreach ($workloads as $workload) {
            $workload->prepare($connection);
        }

        // Execute each once
        foreach ($workloads as $workload) {
            $workload->execute($connection);
        }

        // Verify state changed
        $afterExecProductCount = $this->getProductCount();
        $afterExecUserCount = $this->getUserCount();

        $this->assertTrue(
            $afterExecProductCount > $initialProductCount,
            "Insert workloads should have increased product count"
        );

        // Clean up all
        foreach ($workloads as $workload) {
            $workload->cleanup($connection);
        }

        // Verify state restored
        $finalProductCount = $this->getProductCount();
        $finalUserCount = $this->getUserCount();

        $this->assertTrue(
            $finalProductCount === $initialProductCount,
            "After cleanup, product count should be restored to initial"
        );

        $this->assertTrue(
            $finalUserCount === $initialUserCount,
            "After cleanup, user count should be restored to initial"
        );

        $this->cleanupTestDatabase();
    }

    public function run(): void
    {
        echo "\nWriteWorkloadsTest\n";
        echo "──────────────────\n";
        $this->testSingleInsertWorkload();
        $this->testBatchInsertWorkload();
        $this->testBatchInsertWorkloadRespectsBatchLimit();
        $this->testBatchInsertWorkloadUsesCorrectDriverLimit();
        $this->testTransactionalInsertWorkload();
        $this->testUpdateByPrimaryKeyWorkload();
        $this->testDeleteByPrimaryKeyWorkload();
        $this->testAllWriteWorkloadsTogetherMaintainIsolation();
    }

    private function setupTestDatabase(): void
    {
        $this->dbPath = '/tmp/dbench86_writeworkload_test.sqlite';
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }

        $this->driver = new SqliteDriver($this->dbPath);
        $this->connection = $this->driver->connect();

        $seed = 42;
        $rows = 500;

        $deterministicGenerator = new DeterministicGenerator($seed);
        $populator = new DatasetPopulator($this->driver, $deterministicGenerator, $rows);
        $this->connection = $populator->populate();

        $result = $this->connection->query('SELECT COUNT(*) FROM users');
        $count = $result->fetchColumn();
        $this->assertTrue($count > 0, 'Database should be populated with users');
    }

    private function cleanupTestDatabase(): void
    {
        $this->driver = null;
        $this->connection = null;

        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
            $this->dbPath = '';
        }
    }

    private function getProductCount(): int
    {
        $stmt = $this->connection->query('SELECT COUNT(*) FROM products');
        return (int)$stmt->fetchColumn();
    }

    private function getUserCount(): int
    {
        $stmt = $this->connection->query('SELECT COUNT(*) FROM users');
        return (int)$stmt->fetchColumn();
    }
}

$writeWorkloadsTest = new WriteWorkloadsTest();
$writeWorkloadsTest->run();
