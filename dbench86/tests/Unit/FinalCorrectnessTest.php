<?php
declare(strict_types=1);

namespace Tests\Unit;

use DBench86\Application;
use DBench86\Benchmark\{WorkloadMeasurement, WorkloadResult};
use DBench86\Cli\{ArgumentParser, CommandDefinition};
use DBench86\Configuration\BenchmarkConfiguration;
use DBench86\Driver\SqliteDriver;
use DBench86\Workload\Workload;
use PDO;
use SortDirection;
use Tests\TestCase;

final class FinalCorrectnessTest extends TestCase
{
    public function run(): void
    {
        $this->testSuccessfulSamplesOnly();
        $this->testApplicationAccounting();
        $this->testReportingAndSorting();
        $this->testCliOptions();
        $this->testExplicitCredentials();
    }

    private function testSuccessfulSamplesOnly(): void
    {
        foreach ([[], [4], [8, 2, 4], [2, 4, 6, 8]] as $samples) {
            $result = new WorkloadResult(count($samples));
            foreach ($samples as $ms) {
                $result->record(new WorkloadMeasurement($ms * 1_000_000, 1));
            }
            $stats = $result->statistics();
            if ($samples === []) {
                $this->assertEquals(null, $stats, 'Zero successes have no invented statistics');
            } else {
                $sorted = $samples;
                sort($sorted);
                $median = count($sorted) === 1 ? 4.0 : 5.0;
                if (count($sorted) === 3) {
                    $median = 4.0;
                }
                $this->assertEquals((float)min($samples), $stats['min']);
                $this->assertEquals($median, $stats['median']);
                $this->assertEquals((float)max($samples), $stats['p95']);
                $this->assertEquals((float)max($samples), $stats['max']);
                $this->assertTrue(!$result->hasFailures(), 'All successful samples complete the request');
            }
        }
        $mixed = new WorkloadResult(4);
        $mixed->record(new WorkloadMeasurement(2_000_000, 1));
        $mixed->record(new WorkloadMeasurement(99_000_000, 0, false, true));
        $mixed->record(new WorkloadMeasurement(88_000_000, 1, true));
        $mixed->record(new WorkloadMeasurement(4_000_000, 1));
        $this->assertEquals([2.0, 4.0], $mixed->durations, 'Unsuccessful durations never become latency samples');
        $this->assertEquals(['min' => 2.0, 'median' => 3.0, 'p95' => 4.0, 'max' => 4.0], $mixed->statistics());
        $this->assertEquals([4, 4, 2, 1, 1], [$mixed->requested, $mixed->performed, $mixed->successful, $mixed->failed, $mixed->timedOut]);
        $mixed->record(new WorkloadMeasurement(1, 0, false, true), true);
        $mixed->record(new WorkloadMeasurement(1, 0, true), true);
        $this->assertEquals([2, 1, 1], [$mixed->warmupPerformed, $mixed->warmupFailed, $mixed->warmupTimedOut]);
        $this->assertEquals(4, $mixed->performed, 'Warmups do not change measured accounting');
        $this->assertTrue($mixed->hasFailures());
    }

    private function testApplicationAccounting(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $app = new Application();
        $method = new \ReflectionMethod($app, 'runBenchmark');
        $workload = new AccountingWorkload();
        $results = $method->invoke($app, $pdo, [$workload], 1, 3, 30);
        $result = $results['accounting'];
        $this->assertEquals([3, 3, 2, 1, 0], [$result->requested, $result->performed, $result->successful, $result->failed, $result->timedOut]);
        $this->assertEquals(2, count($result->durations));
        $this->assertEquals([1, 4, 1], [$workload->prepares, $workload->executions, $workload->cleanups], 'Preparation/timing/cleanup lifecycle preserved');

        $slow = new AccountingWorkload();
        $slow->slow = true;
        $result = $method->invoke($app, $pdo, [$slow], 0, 1, 1)['accounting'];
        $this->assertEquals([1, 1, 0, 0, 1], [$result->requested, $result->performed, $result->successful, $result->failed, $result->timedOut]);
        $this->assertEquals(null, $result->statistics(), 'Real timed-out execution excluded');

        $broken = new AccountingWorkload();
        $broken->prepareFails = true;
        ob_start();
        try {
            $result = $method->invoke($app, $pdo, [$broken], 5, 3, 30)['accounting'];
        } finally {
            ob_end_clean();
        }
        $this->assertEquals(3, $result->requested, 'Preparation failure preserves requested runs');
        $this->assertEquals(0, $result->performed);
        $this->assertTrue($result->errors !== [] && $result->hasFailures());
        $this->assertEquals(1, $broken->cleanups, 'Cleanup still runs on preparation failure');

        $cleanup = new AccountingWorkload();
        $cleanup->cleanupFails = true;
        ob_start();
        try {
            $result = $method->invoke($app, $pdo, [$cleanup], 0, 1, 30)['accounting'];
        } finally {
            ob_end_clean();
        }
        $this->assertEquals(1, $result->successful, 'Cleanup failure preserves observed samples');
        $this->assertTrue($result->hasFailures() && $result->errors !== []);
    }

    private function testReportingAndSorting(): void
    {
        $fast = new WorkloadResult(3);
        foreach ([1, 1, 20] as $ms) { $fast->record(new WorkloadMeasurement($ms * 1_000_000, 1)); }
        $slow = new WorkloadResult(1);
        $slow->record(new WorkloadMeasurement(4_000_000, 1));
        $empty = new WorkloadResult(2);
        $empty->record(new WorkloadMeasurement(99_000_000, 0, false, true));
        $empty->record(new WorkloadMeasurement(88_000_000, 1, true));
        $display = new \ReflectionMethod(Application::class, 'displayResults');
        foreach (['min', 'median', 'p95', 'max'] as $metric) {
            foreach ([SortDirection::Ascending, SortDirection::Descending] as $direction) {
                ob_start();
                try {
                    $failed = $display->invoke(new Application(), 'sqlite', ['slow_row' => $slow, 'no_samples' => $empty, 'fast_row' => $fast], 2, $metric, $direction);
                    $text = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                $fastPosition = strpos($text, 'fast_row');
                $slowPosition = strpos($text, 'slow_row');
                $fastFirst = in_array($metric, ['min', 'median'], true);
                if ($direction === SortDirection::Descending) { $fastFirst = !$fastFirst; }
                $this->assertTrue($fastFirst ? $fastPosition < $slowPosition : $slowPosition < $fastPosition, 'Selected metric and enum control reporting order');
                $this->assertTrue(strpos($text, 'no_samples') > max($fastPosition, $slowPosition), 'No-sample row last in either direction');
                $this->assertTrue(str_contains($text, 'N/A') && str_contains($text, '0/2') && str_contains($text, '0 successful | 1 failed | 1 timed out'));
                $this->assertTrue($failed, 'Unsuccessful measured runs cause failure status');
            }
        }
        ob_start();
        try {
            $failed = $display->invoke(new Application(), 'sqlite', ['fast_row' => $fast], 1);
        } finally {
            ob_end_clean();
        }
        $this->assertTrue(!$failed, 'Successful reporting returns success');
    }

    private function testCliOptions(): void
    {
        $parser = new ArgumentParser();
        $defaults = new BenchmarkConfiguration($parser->parse(['dbench86.php']));
        $this->assertEquals(100, $defaults->batchSize, 'Default actual workload size unchanged');
        $config = new BenchmarkConfiguration($parser->parse(['dbench86.php', '--database=sqlite', '--rows=1000', '--runs=2', '--warmup=0', '--batch-size=7', '--sort=max', '--direction=desc', '--debug']));
        $this->assertEquals([7, 'max', SortDirection::Descending, true], [$config->batchSize, $config->sort, $config->direction, $config->debug]);
        $pdo = new PDO('sqlite::memory:');
        $driver = new SqliteDriver(':memory:');
        $driver->createSchema($pdo);
        $workloads = (new \ReflectionMethod(Application::class, 'getAllWorkloads'))->invoke(new Application(), $driver, null, $config->batchSize);
        foreach ($workloads as $workload) {
            if ($workload->name() === 'batch_insert') {
                $workload->prepare($pdo);
                $this->assertEquals(7, $workload->execute($pdo), 'CLI batch size reaches actual SQL execution');
                $this->assertEquals(7, (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
                $workload->cleanup($pdo);
            }
        }
        $this->assertThrows(\InvalidArgumentException::class, fn() => $parser->parse(['dbench86.php', '--output=result.json']));
        $this->assertThrows(\InvalidArgumentException::class, fn() => new BenchmarkConfiguration($parser->parse(['dbench86.php', '--sort=p99'])));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $parser->parse(['dbench86.php', 'unfinished-command']));
        $this->assertTrue(!str_contains(CommandDefinition::getHelpText(), '--output'));
        foreach (['--database', '--rows', '--runs', '--warmup', '--batch-size', '--timeout', '--sort', '--direction', '--debug', '--help'] as $option) {
            $this->assertTrue(str_contains(CommandDefinition::getHelpText(), $option), 'Supported option documented');
        }
    }

    private function testExplicitCredentials(): void
    {
        // Isolate from the development .env and private fixture file; only dummy values.
        $directory = sys_get_temp_dir() . '/dbench86-credentials-' . bin2hex(random_bytes(6));
        mkdir($directory);
        mkdir($directory . '/tests');
        $fixture = $directory . '/tests/DatabaseCredentials.php';
        $configFile = $directory . '/.dbench86-test-config.php';
        file_put_contents($configFile, "<?php return ['DBENCH86_MARIADB_PORT' => 3307, 'DBENCH86_PGSQL_PORT' => 5433];");
        copy(__DIR__ . '/../DatabaseCredentials.php', $fixture);
        $code = <<<'PHP'
require $argv[1];
spl_autoload_register(static function (string $class) use ($argv): void {
    if (str_starts_with($class, 'DBench86\\')) {
        require $argv[2] . '/' . str_replace('\\', '/', substr($class, 9)) . '.php';
    }
});
$c = Tests\DatabaseCredentials::getInstance();
$app = new DBench86\Application();
$accepted = [];
foreach (['mariadb', 'postgresql'] as $engine) {
    try {
        (new ReflectionMethod($app, 'createDriver'))->invoke($app, $engine);
        $accepted[] = true;
    } catch (DBench86\Exception\RuntimeRequirementException $e) {
        $accepted[] = false;
    }
}
echo json_encode([$c->mariadbUser, $c->postgresqlUser, $c->mariadbPassword, $accepted, [$c->mariadbPort, $c->postgresqlPort]]);
PHP;
        try {
            foreach ([
                [[], ['', '', '', [false, false], [3307, 5433]]],
                [['DBENCH86_MARIADB_USERNAME' => 'fixture-user', 'DBENCH86_PGSQL_USERNAME' => 'fixture-user', 'DBENCH86_MARIADB_PASSWORD' => '0'], ['fixture-user', 'fixture-user', '0', [true, true], [3307, 5433]]],
                [['DBENCH86_MARIADB_USERNAME' => '', 'DBENCH86_PGSQL_USERNAME' => ''], ['', '', '', [false, false], [3307, 5433]]],
                [['DBENCH86_MARIADB_PORT' => '3308', 'DBENCH86_PGSQL_PORT' => '5434'], ['', '', '', [false, false], [3308, 5434]]],
            ] as [$env, $expected]) {
                $process = proc_open([PHP_BINARY, '-r', $code, $fixture, realpath(__DIR__ . '/../../src')], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertEquals(0, proc_close($process), 'Isolated credential fixture runs');
                $this->assertEquals('', $error);
                $this->assertTrue(json_decode($output, true) === $expected, 'Explicit canonical credentials only; no implicit usernames');
            }
        } finally {
            unlink($fixture);
            unlink($configFile);
            rmdir($directory . '/tests');
            rmdir($directory);
        }
    }
}

final class AccountingWorkload implements Workload
{
    public int $prepares = 0;
    public int $executions = 0;
    public int $cleanups = 0;
    public bool $slow = false;
    public bool $prepareFails = false;
    public bool $cleanupFails = false;

    public function name(): string { return 'accounting'; }
    public function prepare(PDO $pdo): void
    {
        $this->prepares++;
        if ($this->prepareFails) { throw new \RuntimeException('fixture preparation failed'); }
    }
    public function execute(PDO $pdo): int
    {
        $this->executions++;
        if ($this->slow) { usleep(1_050_000); }
        if ($this->executions === 2) { throw new \RuntimeException('fixture execution failed'); }
        return 1;
    }
    public function cleanup(PDO $pdo): void
    {
        $this->cleanups++;
        if ($this->cleanupFails) { throw new \RuntimeException('fixture cleanup failed'); }
    }
    public function boundParametersPerRow(): ?int { return null; }
}

(new FinalCorrectnessTest())->run();
