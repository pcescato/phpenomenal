<?php
declare(strict_types=1);

namespace Tests\Unit;

use DBench86\Dataset\DatasetGenerator;
use DBench86\Dataset\DatasetPopulator;
use DBench86\Dataset\DeterministicGenerator;
use DBench86\Driver\SqliteDriver;
use DBench86\Workload\AggregationWorkload;
use DBench86\Workload\IndexedSelectWorkload;
use DBench86\Workload\JoinWorkload;
use DBench86\Workload\PrimaryKeySelectWorkload;
use DBench86\Workload\RangeSelectWorkload;
use PDO;
use Tests\TestCase;

final class ReadWorkloadsTest extends TestCase
{
    private SqliteDriver|null $driver = null;
    private PDO|null $connection = null;
    private string $dbPath = '';

    public function testPrimaryKeySelectWorkload(): void
    {
        $this->setupTestDatabase();
        $workload = new PrimaryKeySelectWorkload();

        // Verify workload properties
        $this->assertTrue($workload->name() === 'pk_select', 'Workload name should be pk_select');

        // Prepare and execute
        $workload->prepare($this->connection);
        // Multiple executions should work
        for ($i = 0; $i < 5; $i++) {
            $count = $workload->execute($this->connection);
            $this->assertTrue($count > 0, "Primary key select iteration $i should return rows");
        }

        $workload->cleanup($this->connection);
        $this->assertTrue($workload->boundParametersPerRow() === null, 'Read workloads should return null for bound parameters');

        $this->cleanupTestDatabase();
    }

    public function testIndexedSelectWorkload(): void
    {
        $this->setupTestDatabase();
        $workload = new IndexedSelectWorkload();

        $this->assertTrue($workload->name() === 'indexed_select', 'Workload name should be indexed_select');

        $workload->prepare($this->connection);
        for ($i = 0; $i < 5; $i++) {
            $count = $workload->execute($this->connection);
            $this->assertTrue($count > 0, "Indexed select iteration $i should return rows");
        }

        $workload->cleanup($this->connection);

        $this->cleanupTestDatabase();
    }

    public function testRangeSelectWorkload(): void
    {
        $this->setupTestDatabase();
        $workload = new RangeSelectWorkload();

        $this->assertTrue($workload->name() === 'range_select', 'Workload name should be range_select');

        $workload->prepare($this->connection);
        for ($i = 0; $i < 5; $i++) {
            $count = $workload->execute($this->connection);
            $this->assertTrue($count > 0, "Range select iteration $i should return rows");
        }

        $workload->cleanup($this->connection);

        $this->cleanupTestDatabase();
    }

    public function testJoinWorkload(): void
    {
        $this->setupTestDatabase();
        $workload = new JoinWorkload();

        $this->assertTrue($workload->name() === 'join', 'Workload name should be join');

        $workload->prepare($this->connection);
        for ($i = 0; $i < 5; $i++) {
            $count = $workload->execute($this->connection);
            $this->assertTrue($count > 0, "Join workload iteration $i should return rows");
        }

        $workload->cleanup($this->connection);

        $this->cleanupTestDatabase();
    }

    public function testAggregationWorkload(): void
    {
        $this->setupTestDatabase();
        $workload = new AggregationWorkload();

        $this->assertTrue($workload->name() === 'aggregation', 'Workload name should be aggregation');

        $workload->prepare($this->connection);
        for ($i = 0; $i < 5; $i++) {
            $count = $workload->execute($this->connection);
            $this->assertTrue($count > 0, "Aggregation workload iteration $i should return rows");
        }

        $workload->cleanup($this->connection);

        $this->cleanupTestDatabase();
    }

    public function testAllWorkloadsExecuteTogether(): void
    {
        $this->setupTestDatabase();

        $workloads = [
            new PrimaryKeySelectWorkload(),
            new IndexedSelectWorkload(),
            new RangeSelectWorkload(),
            new JoinWorkload(),
            new AggregationWorkload(),
        ];

        $connection = $this->connection;

        foreach ($workloads as $workload) {
            $workload->prepare($connection);
        }

        foreach ($workloads as $workload) {
            $rowCount = $workload->execute($connection);
            $this->assertTrue($rowCount > 0, "Workload {$workload->name()} should return rows");
        }

        foreach ($workloads as $workload) {
            $workload->cleanup($connection);
        }

        $this->cleanupTestDatabase();
    }

    public function run(): void
    {
        echo "\nReadWorkloadsTest\n";
        echo "─────────────────\n";
        $this->testPrimaryKeySelectWorkload();
        $this->testIndexedSelectWorkload();
        $this->testRangeSelectWorkload();
        $this->testJoinWorkload();
        $this->testAggregationWorkload();
        $this->testAllWorkloadsExecuteTogether();
    }

    private function setupTestDatabase(): void
    {
        $this->dbPath = '/tmp/dbench86_readworkload_test.sqlite';
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }

        $this->driver = new SqliteDriver($this->dbPath);
        $this->connection = $this->driver->connect();

        $seed = 12345;
        $rows = 1000;

        $deterministicGenerator = new DeterministicGenerator($seed);

        $populator = new DatasetPopulator($this->driver, $deterministicGenerator, $rows);
        $this->connection = $populator->populate();

        // Verify the database was populated
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
}

$readWorkloadsTest = new ReadWorkloadsTest();
$readWorkloadsTest->run();
