<?php
declare(strict_types=1);

namespace Tests\Unit;

use DBench86\Dataset\{DatasetGenerator, DatasetPopulator, DeterministicGenerator};
use DBench86\Driver\SqliteDriver;
use DBench86\Workload\{DeterministicSamples, PrimaryKeySelectWorkload, IndexedSelectWorkload, RangeSelectWorkload, JoinWorkload, UpdateByPrimaryKeyWorkload, DeleteByPrimaryKeyWorkload};
use Tests\TestCase;

final class ScalabilityTest extends TestCase
{
    public function run(): void
    {
        $samples = new DeterministicSamples(35, 42000);
        foreach ([1, 7, 10000, 100000, 1000000, 2000000, 10000000] as $rows) {
            $ids = $samples->ids($rows, 'indexed_select');
            $this->assertEquals(min(35, $rows), count($ids), 'Input storage stays bounded');
            $this->assertEquals($ids, $samples->ids($rows, 'indexed_select'), 'Reproducible inputs');
            $this->assertEquals(count($ids), count(array_unique($ids)), 'Distinct strata');
            $this->assertTrue(min($ids) >= 1 && max($ids) <= $rows, 'Valid generated IDs');
            if ($rows >= 10000) {
                $this->assertTrue(max($ids) > $rows * 0.9, 'Samples span the dataset');
                $this->assertTrue($ids !== $samples->ids($rows, 'join'), 'Workload identity changes inputs');
                $this->assertTrue($ids !== (new DeterministicSamples(35, 42001))->ids($rows, 'indexed_select'), 'Seed changes inputs');
            }
        }
        $this->assertThrows(\InvalidArgumentException::class, fn() => new DeterministicSamples(0));
        $oversized = (new \DBench86\Cli\ArgumentParser())->parse(['dbench86', '--runs=' . PHP_INT_MAX, '--warmup=1']);
        $this->assertThrows(\InvalidArgumentException::class, fn() => new \DBench86\Configuration\BenchmarkConfiguration($oversized), 'Execution-count overflow diagnosed before preparation');

        // Compact ranges must preserve every draw, including singleton ranges.
        foreach ([1, 30] as $count) {
            $old = new DatasetGenerator(new DeterministicGenerator(42000), 100);
            $new = new DatasetGenerator(new DeterministicGenerator(42000), 100);
            $this->assertEquals(iterator_to_array($old->generateOrders(range(1, $count))), iterator_to_array($new->generateOrders($count)));
            $this->assertEquals(iterator_to_array($old->generateOrderItems(range(1, $count), range(1, $count))), iterator_to_array($new->generateOrderItems($count, $count)));
        }

        $pdo = (new DatasetPopulator(new SqliteDriver(':memory:'), new DeterministicGenerator(42000), 1000))->populate();
        foreach ([new PrimaryKeySelectWorkload($samples), new IndexedSelectWorkload($samples), new RangeSelectWorkload($samples), new JoinWorkload($samples), new UpdateByPrimaryKeyWorkload($samples), new DeleteByPrimaryKeyWorkload($samples)] as $workload) {
            $workload->prepare($pdo);
            foreach ((new \ReflectionObject($workload))->getProperties() as $property) {
                $value = $property->getValue($workload);
                if (is_array($value)) {
                    $this->assertTrue(count($value) <= 35, $workload->name() . ' retains only execution inputs');
                }
            }
            if ($workload instanceof RangeSelectWorkload) {
                // Compare the bounded endpoint algorithm with the original algorithm.
                $timestamps = $pdo->query('SELECT created_at FROM users ORDER BY created_at')->fetchAll(\PDO::FETCH_COLUMN);
                for ($i = 0; $i < 35; $i++) {
                    $expected = $pdo->prepare('SELECT COUNT(*) FROM users WHERE created_at BETWEEN ? AND ?');
                    $expected->execute([$timestamps[$i], $timestamps[$i + 100]]);
                    $this->assertEquals((int)$expected->fetchColumn(), $workload->execute($pdo), 'Identical range result cardinality');
                }
            } else {
                $this->assertTrue($workload->execute($pdo) > 0, 'Workload operates on a valid sampled key');
            }
            $workload->cleanup($pdo);
        }
        // Non-multiples of ten and wraparound preserve the original rank arithmetic.
        foreach ([1, 7, 37] as $rows) {
            $small = (new DatasetPopulator(new SqliteDriver(':memory:'), new DeterministicGenerator(42000), $rows))->populate();
            $timestamps = $small->query('SELECT created_at FROM users ORDER BY created_at')->fetchAll(\PDO::FETCH_COLUMN);
            $range = new RangeSelectWorkload($samples);
            $range->prepare($small);
            for ($i = 0; $i < 35; $i++) {
                $start = $i % $rows;
                $end = (int)($i + $rows / 10) % $rows;
                $expected = $small->prepare('SELECT COUNT(*) FROM users WHERE created_at BETWEEN ? AND ?');
                $expected->execute([$timestamps[min($start, $end)], $timestamps[max($start, $end)]]);
                $this->assertEquals((int)$expected->fetchColumn(), $range->execute($small), 'Original range ranks preserved across wraparound');
            }
            $range->cleanup($small);
        }
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY); INSERT INTO users VALUES (1), (3)');
        $this->assertThrows(\RuntimeException::class, fn() => $samples->tableIds($pdo, 'users', 'pk_select'), 'Noncontiguous dataset diagnosed');

        // Every remaining application fetchAll is bounded by a key, grouping, execution
        // count, or schema metadata; range execution must never materialize its result.
        $root = __DIR__ . '/../../src/';
        foreach (['PrimaryKeySelectWorkload', 'IndexedSelectWorkload', 'JoinWorkload', 'UpdateByPrimaryKeyWorkload', 'DeleteByPrimaryKeyWorkload', 'RangeSelectWorkload'] as $name) {
            $source = file_get_contents($root . 'Workload/' . $name . '.php');
            $prepare = substr($source, strpos($source, 'public function prepare'), strpos($source, 'public function execute') - strpos($source, 'public function prepare'));
            $this->assertTrue(!str_contains($prepare, 'fetchAll'), 'Preparation never materializes whole columns');
        }
        $auditedFetchAll = [
            'IndexedSelectWorkload.php' => 1, // UNIQUE email: at most one row.
            'PrimaryKeySelectWorkload.php' => 1, // Primary key: at most one row.
            'JoinWorkload.php' => 1, // One grouped primary-key order.
            'AggregationWorkload.php' => 1, // Two status groups.
            'SingleInsertWorkload.php' => 1, // LIMIT execution count.
            'BatchInsertWorkload.php' => 1, // LIMIT executions * effective batch.
            'TransactionalInsertWorkload.php' => 1, // LIMIT executions * transaction size.
            'MariaDbDriver.php' => 1, // Schema tables, independent of dataset rows.
            'PostgreSqlDriver.php' => 2, // Schema tables and sequences.
            'SqliteDriver.php' => 1, // Schema tables.
        ];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $calls = preg_match_all('/->fetchAll\s*\(/', file_get_contents($file->getPathname()));
            $this->assertEquals($auditedFetchAll[$file->getFilename()] ?? 0, $calls, 'Every fetchAll must belong to the bounded audit: ' . $file->getFilename());
        }
        $large = new DatasetGenerator(new DeterministicGenerator(42000), 10000000);
        $stream = $large->generateUsers();
        $this->assertTrue($stream instanceof \Generator, 'Dataset records are yielded incrementally');
        $this->assertEquals(1, $stream->current()['id'], 'Huge datasets can yield a row without materializing the dataset');

        $source = file_get_contents($root . 'Dataset/DatasetPopulator.php');
        $this->assertTrue(!str_contains($source, 'fetchAll') && !str_contains($source, 'Ids = []'), 'Population never retains ID arrays');
        $source = file_get_contents($root . 'Workload/RangeSelectWorkload.php');
        $this->assertTrue(!str_contains($source, 'fetchAll') && !str_contains($source, 'OFFSET'), 'Range reads remain bounded');
        foreach (['ATTR_PREFETCH', 'ATTR_USE_BUFFERED_QUERY', 'ATTR_CHUNK_SIZE', 'ATTR_CURSOR', 'setAttribute('] as $deliveryOverride) {
            $this->assertTrue(!str_contains($source, $deliveryOverride), 'Range must preserve PDO result-delivery defaults: ' . $deliveryOverride);
        }
        foreach (['DECLARE ', 'FETCH FORWARD', 'beginTransaction(', 'commit(', 'rollBack('] as $cursorOperation) {
            $this->assertTrue(!str_contains($source, $cursorOperation), 'Range must not add SQL cursor/transaction overhead: ' . $cursorOperation);
        }
    }
}
(new ScalabilityTest())->run();
