<?php
declare(strict_types=1);

namespace Tests\Integration;

use DBench86\Application;
use DBench86\Benchmark\WorkloadTimer;
use DBench86\Cli\ArgumentParser;
use DBench86\Configuration\BenchmarkConfiguration;
use DBench86\Dataset\{DatasetGenerator, DatasetPopulator, DeterministicGenerator};
use DBench86\Driver\SqliteDriver;
use DBench86\Workload\{AggregationWorkload, DeleteByPrimaryKeyWorkload, DeterministicSamples, TransactionalInsertWorkload};
use PDO;
use Tests\TestCase;
use Time\Duration;

final class CopilotCorrectnessTest extends TestCase
{
    public function run(): void
    {
        $this->testIntegerInputs();
        $this->testUtcDataset();
        $this->testDeleteRestoration();
        $this->testTransactionRecovery();
        $this->testEmptyAggregation();
        $this->testCompleteCycle();
    }

    private function database(): array
    {
        $driver = new SqliteDriver(':memory:');
        return [$driver, (new DatasetPopulator($driver, new DeterministicGenerator(42000), 100))->populate()];
    }

    private function snapshot(PDO $pdo): array
    {
        $state = [];
        foreach (['users', 'products', 'orders', 'order_items'] as $table) {
            $state[$table] = $pdo->query("SELECT * FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        }
        return $state;
    }

    private function testIntegerInputs(): void
    {
        $parser = new ArgumentParser();
        foreach (['rows', 'runs', 'warmup', 'batch-size'] as $option) {
            foreach (['1.9', '1e3', '1E3', '-1', '+1', ' 1', '1 ', '', 'NaN', '0x10', (string) PHP_INT_MAX . '0', '9223372036854775808'] as $value) {
                $this->assertThrows(\InvalidArgumentException::class,
                    fn() => new BenchmarkConfiguration($parser->parse(['dbench86.php', "--{$option}={$value}"])),
                    "Reject malformed/overflow --{$option} input");
            }
            $maximum = $option === 'warmup' ? PHP_INT_MAX - 1 : PHP_INT_MAX;
            foreach (['1', '0001', (string) $maximum] as $value) {
                $config = new BenchmarkConfiguration($parser->parse(['dbench86.php', '--runs=1', '--warmup=0', "--{$option}={$value}"]));
                $property = $option === 'batch-size' ? 'batchSize' : $option;
                $this->assertEquals((int) $value, $config->$property);
            }
            if ($option === 'warmup') {
                $this->assertEquals(0, (new BenchmarkConfiguration($parser->parse(['dbench86.php', '--warmup=0'])))->warmup);
            } else {
                $this->assertThrows(\InvalidArgumentException::class,
                    fn() => new BenchmarkConfiguration($parser->parse(['dbench86.php', "--{$option}=0"])));
            }
        }
        $this->assertThrows(\InvalidArgumentException::class,
            fn() => new BenchmarkConfiguration($parser->parse(['dbench86.php', '--runs=' . PHP_INT_MAX, '--warmup=1'])));
        foreach (['rows', 'runs', 'warmup', 'batch_size'] as $key) {
            foreach ([1.9, 1.0, true, null] as $value) {
                $parsed = $parser->parse(['dbench86.php']);
                $parsed[$key] = $value;
                $this->assertThrows(\InvalidArgumentException::class, fn() => new BenchmarkConfiguration($parsed));
            }
        }
    }

    private function testUtcDataset(): void
    {
        $original = date_default_timezone_get();
        try {
            $datasets = [];
            foreach (['UTC', 'Pacific/Honolulu', 'Asia/Kathmandu'] as $timezone) {
                date_default_timezone_set($timezone);
                $generator = new DatasetGenerator(new DeterministicGenerator(42), 30);
                $datasets[] = [iterator_to_array($generator->generateUsers()), iterator_to_array($generator->generateOrders(30))];
            }
            $this->assertEquals($datasets[0], $datasets[1], 'Same seed generates identical user/order timestamps in another timezone');
            $this->assertEquals($datasets[0], $datasets[2]);
        } finally {
            date_default_timezone_set($original);
        }
    }

    private function testDeleteRestoration(): void
    {
        [, $pdo] = $this->database();
        $before = $this->snapshot($pdo);
        $workload = new DeleteByPrimaryKeyWorkload(new DeterministicSamples(20));
        $workload->prepare($pdo);
        for ($i = 0; $i < 20; $i++) {
            $workload->execute($pdo);
        }
        $this->assertTrue((int) $pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn() < count($before['order_items']));
        $workload->cleanup($pdo);
        $this->assertEquals($before, $this->snapshot($pdo), 'Restore every original product and dependent order item value');

        // Partial failure: dependent deletion succeeded but the product deletion failed.
        $workload->prepare($pdo);
        $pdo->exec("CREATE TRIGGER reject_delete BEFORE DELETE ON products BEGIN SELECT RAISE(ABORT, 'controlled delete failure'); END");
        $this->assertThrows(\PDOException::class, fn() => $workload->execute($pdo));
        $pdo->exec('DROP TRIGGER reject_delete');
        $workload->cleanup($pdo);
        $this->assertEquals($before, $this->snapshot($pdo), 'Partial delete failure restores dependencies without duplicating existing products');

        $workload->prepare($pdo);
        $workload->execute($pdo);
        $pdo->exec("CREATE TRIGGER reject_restore BEFORE INSERT ON products BEGIN SELECT RAISE(ABORT, 'controlled restoration failure'); END");
        $this->assertThrows(\PDOException::class, fn() => $workload->cleanup($pdo), 'Cleanup errors must propagate');
        $pdo->exec('DROP TRIGGER reject_restore');
        $workload->cleanup($pdo);
        $this->assertEquals($before, $this->snapshot($pdo), 'Restoration can be retried after its failure is reported');
    }

    private function testTransactionRecovery(): void
    {
        [, $pdo] = $this->database();
        $before = $this->snapshot($pdo);
        $workload = new TransactionalInsertWorkload(3);
        $workload->prepare($pdo);
        $pdo->exec("CREATE TRIGGER reject_second_insert BEFORE INSERT ON products WHEN (SELECT COUNT(*) FROM products) > 15 BEGIN SELECT RAISE(ABORT, 'controlled insert failure'); END");
        $timer = new WorkloadTimer(Duration::fromSeconds(10));
        $failed = $timer->run($workload, $pdo);
        $this->assertTrue($failed->failed && !$failed->timedOut);
        $this->assertTrue(!$pdo->inTransaction(), 'Failed sample must leave no active transaction');
        $this->assertEquals($before, $this->snapshot($pdo), 'Rollback removes the first insert too');
        $pdo->exec('DROP TRIGGER reject_second_insert');
        $success = $timer->run($workload, $pdo);
        $this->assertTrue(!$success->failed && !$success->timedOut);
        $this->assertEquals(3, $success->rowsAffected);
        $this->assertTrue(!$pdo->inTransaction());
        $workload->cleanup($pdo);
        $this->assertEquals($before, $this->snapshot($pdo));

        // Deferred foreign-key rejection exercises COMMIT failure and original exception type.
        $pdo->exec('CREATE TABLE commit_guard (id INTEGER REFERENCES users(id) DEFERRABLE INITIALLY DEFERRED)');
        $pdo->exec('CREATE TRIGGER reject_commit AFTER INSERT ON products BEGIN INSERT INTO commit_guard VALUES (999999); END');
        $this->assertThrows(\PDOException::class, fn() => $workload->execute($pdo));
        $this->assertTrue(!$pdo->inTransaction(), 'COMMIT failure must also roll back');
        $this->assertEquals(0, (int) $pdo->query('SELECT COUNT(*) FROM commit_guard')->fetchColumn());
        $this->assertEquals($before, $this->snapshot($pdo));
        $pdo->exec('DROP TRIGGER reject_commit');

        $brokenRollback = new RollbackFailureConnection();
        (new SqliteDriver(':memory:'))->createSchema($brokenRollback);
        $brokenRollback->exec("CREATE TRIGGER reject_insert BEFORE INSERT ON products BEGIN SELECT RAISE(ABORT, 'original insert failure'); END");
        $workload = new TransactionalInsertWorkload(1);
        $workload->prepare($brokenRollback);
        try {
            $workload->execute($brokenRollback);
            $this->runner->fail('Expected rollback failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'controlled rollback failure'));
            $this->assertTrue($e->getPrevious() instanceof \PDOException, 'Rollback error retains original execution exception');
        } finally {
            $brokenRollback->recover();
        }
    }

    private function testEmptyAggregation(): void
    {
        [, $pdo] = $this->database();
        $pdo->exec("UPDATE orders SET status = 'cancelled'");
        $workload = new AggregationWorkload();
        $workload->prepare($pdo);
        $this->assertEquals(0, $workload->execute($pdo), 'Pending/completed predicate genuinely returns no groups');
        $workload->cleanup($pdo);
    }

    private function testCompleteCycle(): void
    {
        $app = new Application();
        $factory = new \ReflectionMethod($app, 'getAllWorkloads');
        $run = new \ReflectionMethod($app, 'runBenchmark');
        foreach (['sqlite', 'mariadb', 'postgresql'] as $engine) {
            if ($engine !== 'sqlite' && !getenv($engine === 'mariadb' ? 'DBENCH86_MARIADB_USERNAME' : 'DBENCH86_PGSQL_USERNAME')) {
                $this->skip("{$engine} credentials unavailable for full cycle test");
                continue;
            }
            $driver = (new \ReflectionMethod($app, 'createDriver'))->invoke($app, $engine);
            $pdo = (new \ReflectionMethod($app, 'populateDataset'))->invoke($app, $driver, 100);
            $before = $this->snapshot($pdo);
            for ($cycle = 0; $cycle < 2; $cycle++) {
                $workloads = $factory->invoke($app, $driver, new DeterministicSamples(5), 7);
                $results = $run->invoke($app, $pdo, $workloads, 2, 3, 30);
                $this->assertEquals(10, count($results));
                foreach ($results as $result) {
                    $this->assertTrue(!$result->hasFailures());
                    $this->assertEquals([3, 2, 0, 0], [$result->successful, $result->warmupPerformed, $result->warmupFailed, $result->warmupTimedOut]);
                }
                $this->assertEquals($before, $this->snapshot($pdo), "{$engine} full cycle restores logical values in all four tables");
                $this->assertTrue(!$pdo->inTransaction());
            }
        }
    }
}

final class RollbackFailureConnection extends PDO
{
    public function __construct() { parent::__construct('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
    public function rollBack(): bool { throw new \RuntimeException('controlled rollback failure'); }
    public function recover(): void { if ($this->inTransaction()) { parent::rollBack(); } }
}

(new CopilotCorrectnessTest())->run();
