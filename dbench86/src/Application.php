<?php
declare(strict_types=1);

namespace DBench86;

use DBench86\Exception\DBench86Exception;
use DBench86\Exception\InvalidConfigurationException;
use DBench86\Exception\RuntimeRequirementException;
use DBench86\Cli\CommandDefinition;
use DBench86\Driver\DatabaseDriver;
use DBench86\Driver\MariaDbDriver;
use DBench86\Driver\PostgreSqlDriver;
use DBench86\Driver\SqliteDriver;
use DBench86\Dataset\DatasetPopulator;
use DBench86\Dataset\DeterministicGenerator;
use DBench86\Benchmark\WorkloadExecutor;
use DBench86\Benchmark\WorkloadResult;
use DBench86\Benchmark\BatchLimitCalculator;
use SortDirection;
use DBench86\Workload\DeterministicSamples;
use DBench86\Workload\{
    PrimaryKeySelectWorkload,
    IndexedSelectWorkload,
    RangeSelectWorkload,
    JoinWorkload,
    AggregationWorkload,
    SingleInsertWorkload,
    BatchInsertWorkload,
    TransactionalInsertWorkload,
    UpdateByPrimaryKeyWorkload,
    DeleteByPrimaryKeyWorkload
};
use Time\Duration;
use PDO;

class Application
{
    private bool $debug = false;

    public function getName(): string
    {
        return 'DBench86';
    }

    public function run(array $argv): int
    {
        try {
            // Parse arguments
            $parser = new Cli\ArgumentParser();
            try {
                $parsed = $parser->parse($argv);
            } catch (\InvalidArgumentException $e) {
                throw new InvalidConfigurationException($e->getMessage());
            }

            $this->debug = $parsed['debug'];

            // Handle --help before validation
            if ($parsed['help']) {
                echo CommandDefinition::getHelpText();
                return ExitCode::Success->value;
            }

            // Validate configuration
            try {
                $config = new Configuration\BenchmarkConfiguration($parsed);
            } catch (\InvalidArgumentException $e) {
                throw new InvalidConfigurationException($e->getMessage());
            }

            // Execute benchmark for selected databases
            $hadFailures = !$this->executeBenchmark($config);

            return $hadFailures ? ExitCode::BenchmarkExecutionFailed->value : ExitCode::Success->value;
        } catch (DBench86Exception $e) {
            $this->printError($e->getMessage());
            return $e->exitCode()->value;
        } catch (\Throwable $e) {
            if ($this->debug) {
                $this->printError($e . '');
            } else {
                $this->printError('An unexpected error occurred: ' . $e->getMessage());
            }
            return ExitCode::GenericFailure->value;
        }
    }

    private function executeBenchmark(Configuration\BenchmarkConfiguration $config): bool
    {
        echo "\n╔════════════════════════════════════════════════════════════════╗\n";
        echo "║              DBench86 Benchmark Execution                     ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n\n";

        echo "Configuration:\n";
        echo "  • Database(s): {$config->database}\n";
        echo "  • Rows: {$config->rows}\n";
        echo "  • Runs: {$config->runs}\n";
        echo "  • Warmup: {$config->warmup}\n";
        echo "  • Timeout: {$config->timeout}s\n\n";

        // Determine which engines to run
        $engines = $this->getEngineList($config->database);

        // Get workloads for listing (mode listing seul—driver=null)
        // BatchInsertWorkload accepts null driver for name() retrieval only
        $workloads = $this->getAllWorkloads(null);

        echo "Workloads to execute: " . count($workloads) . "\n";
        foreach ($workloads as $w) {
            echo "  • {$w->name()}\n";
        }
        echo "\n";

        $this->reportMemory('startup');

        // Execute benchmark for each engine
        $allSucceeded = true;
        foreach ($engines as $engine) {
            $succeeded = $this->benchmarkEngine($engine, $config);
            if (!$succeeded) {
                $allSucceeded = false;
            }
        }

        $this->reportMemory('final');
        return $allSucceeded;
    }

    private function getEngineList(string $database): array
    {
        return match ($database) {
            'all' => ['mariadb', 'postgresql', 'sqlite'],
            'mariadb' => ['mariadb'],
            'postgresql' => ['postgresql'],
            'sqlite' => ['sqlite'],
            default => []
        };
    }

    private function getAllWorkloads(?DatabaseDriver $driver = null, ?DeterministicSamples $samples = null, int $batchSize = 100): array
    {
        $samples ??= new DeterministicSamples();
        return [
            new PrimaryKeySelectWorkload($samples),
            new IndexedSelectWorkload($samples),
            new RangeSelectWorkload($samples),
            new JoinWorkload($samples),
            new AggregationWorkload(),
            new SingleInsertWorkload(),
            new BatchInsertWorkload($driver, $batchSize),
            new TransactionalInsertWorkload(),
            new UpdateByPrimaryKeyWorkload($samples),
            new DeleteByPrimaryKeyWorkload($samples),
        ];
    }

    private function benchmarkEngine(
        string $engine,
        Configuration\BenchmarkConfiguration $config,
    ): bool {
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "  {$engine}\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        try {
            // Create driver
            $driver = $this->createDriver($engine);
            echo "✓ Driver instantiated: {$driver->name}\n";

            // Populate dataset
            $pdo = $this->populateDataset($driver, $config->rows);
            echo "✓ Dataset populated: {$config->rows} rows\n";

            $this->reportMemory('dataset populated');

            // Discover engine version
            $version = $driver->discoverEngineVersion($pdo);
            echo "✓ Engine version: {$version}\n\n";

            $batchLimit = BatchLimitCalculator::driverLimit($driver, 3);
            $this->displayBatchSize($config->batchSize, BatchLimitCalculator::effectiveBatchSize($config->batchSize, $batchLimit));

            // Create fresh workload instances for this engine
            $freshWorkloads = $this->getAllWorkloads($driver, new DeterministicSamples($config->warmup + $config->runs), $config->batchSize);

            // Execute benchmark
            $results = $this->runBenchmark(
                $pdo,
                $freshWorkloads,
                $config->warmup,
                $config->runs,
                $config->timeout
            );

            // Display results
            $hasFailures = $this->displayResults($engine, $results, $config->runs, $config->sort, $config->direction, $config->warmup);
            $this->reportMemory($engine . ' completed');

            return !$hasFailures;

        } catch (RuntimeRequirementException $e) {
            throw $e;
        } catch (\Exception $e) {
            echo "✗ Error: " . $e->getMessage() . "\n\n";
            return false;
        }
    }

    private function createDriver(string $engine): DatabaseDriver
    {
        return match ($engine) {
            'mariadb' => $this->createMariaDbDriver(),
            'postgresql' => $this->createPostgreSqlDriver(),
            'sqlite' => new SqliteDriver(':memory:'),
            default => throw new \InvalidArgumentException("Unknown engine: {$engine}")
        };
    }

    private function createMariaDbDriver(): MariaDbDriver
    {
        $username = getenv('DBENCH86_MARIADB_USERNAME');
        if ($username === false || $username === '') {
            throw new RuntimeRequirementException("DBENCH86_MARIADB_USERNAME n'est pas défini — voir CONFIGURATION.md");
        }

        return new MariaDbDriver(
            host: getenv('DBENCH86_MARIADB_HOST') ?: '127.0.0.1',
            port: (int)(getenv('DBENCH86_MARIADB_PORT') ?: 3306),
            database: getenv('DBENCH86_MARIADB_DATABASE') ?: 'dbench86',
            username: $username,
            password: (($password = getenv('DBENCH86_MARIADB_PASSWORD')) === false ? '' : $password),
        );
    }

    private function createPostgreSqlDriver(): PostgreSqlDriver
    {
        $username = getenv('DBENCH86_PGSQL_USERNAME');
        if ($username === false || $username === '') {
            throw new RuntimeRequirementException("DBENCH86_PGSQL_USERNAME n'est pas défini — voir CONFIGURATION.md");
        }

        return new PostgreSqlDriver(
            host: getenv('DBENCH86_PGSQL_HOST') ?: '127.0.0.1',
            port: (int)(getenv('DBENCH86_PGSQL_PORT') ?: 5432),
            database: getenv('DBENCH86_PGSQL_DATABASE') ?: 'dbench86',
            username: $username,
            password: (($password = getenv('DBENCH86_PGSQL_PASSWORD')) === false ? '' : $password),
        );
    }

    private function populateDataset(DatabaseDriver $driver, int $rows): PDO
    {
        $populator = new DatasetPopulator(
            $driver,
            new DeterministicGenerator(42_000),
            $rows
        );
        return $populator->populate();
    }

    private function runBenchmark(
        PDO $pdo,
        array $workloads,
        int $warmupRuns,
        int $actualRuns,
        int $timeoutSeconds
    ): array {
        $executor = new WorkloadExecutor(
            Duration::fromSeconds($timeoutSeconds)
        );

        $results = [];

        // Process each workload independently
        // Note: Each workload is responsible for its own isolation via prepare/cleanup
        foreach ($workloads as $workload) {
            $workloadName = $workload->name();
            $result = new WorkloadResult($actualRuns);
            $results[$workloadName] = $result;

            try {
                // Prepare once for all runs of this workload
                $workload->prepare($pdo);
                if ($this->debug) {
                    $this->reportMemory($workloadName . ' prepared');
                }

                // Warmup runs (excluded from statistics)
                for ($i = 0; $i < $warmupRuns; $i++) {
                    $warmupMeasurements = $executor->execute([$workload], $pdo);
                    $result->record($warmupMeasurements[$workloadName], true);
                }

                // Actual runs (for statistics)
                for ($run = 0; $run < $actualRuns; $run++) {
                    $measurements = $executor->execute([$workload], $pdo);

                    // Store durations (convert nanoseconds to milliseconds)
                    foreach ($measurements as $measurement) {
                        $result->record($measurement);
                    }
                }

            } catch (\Throwable $e) {
                // Capture workload failure, log it, but continue with next workload
                echo "  ✗ {$workloadName}: " . $e->getMessage() . "\n";
                $result->errors[] = $e->getMessage();
            } finally {
                try {
                    $workload->cleanup($pdo);
                } catch (\Throwable $e) {
                    echo "  ✗ {$workloadName} cleanup: " . $e->getMessage() . "\n";
                    $result->errors[] = 'cleanup: ' . $e->getMessage();
                }
            }
        }

        return $results;
    }

    private function displayResults(
        string $engine,
        array $results,
        int $runs,
        string $sort = 'median',
        SortDirection $direction = SortDirection::Ascending,
        int $warmup = 0,
    ): bool {
        echo "Results: {$runs} measured runs + {$warmup} warmups per workload\n";
        echo "Latency statistics include successful measured runs only; warmups excluded.\n";
        $warmupIssues = [];
        foreach ($results as $name => $result) {
            if ($result->warmupPerformed !== $warmup || $result->warmupFailed > 0 || $result->warmupTimedOut > 0) {
                $warmupIssues[$name] = $result;
            }
        }
        if ($warmupIssues !== []) {
            echo "Warmup issues:\n";
            foreach ($warmupIssues as $name => $result) {
                $successful = $result->warmupPerformed - $result->warmupFailed - $result->warmupTimedOut;
                printf("  %s: %d/%d successful | %d failed | %d timed out",
                    $name, $successful, $warmup, $result->warmupFailed, $result->warmupTimedOut);
                if ($result->warmupPerformed !== $warmup) {
                    printf(" | requested=%d | performed=%d", $warmup, $result->warmupPerformed);
                }
                echo "\n";
            }
        } elseif ($warmup > 0) {
            echo "Warmup: all workloads completed successfully ({$warmup}/{$warmup}).\n";
        } else {
            echo "Warmup: none requested.\n";
        }
        echo "\n";
        $rows = [];
        foreach ($results as $name => $result) {
            $rows[] = ['name' => $name, 'result' => $result, 'statistics' => $result->statistics()];
        }
        usort($rows, static function (array $a, array $b) use ($sort, $direction): int {
            // Rows without a valid sample always follow rows with latency statistics.
            if ($a['statistics'] === null || $b['statistics'] === null) {
                return ($a['statistics'] === null) <=> ($b['statistics'] === null);
            }
            $comparison = $a['statistics'][$sort] <=> $b['statistics'][$sort];
            return $direction === SortDirection::Ascending ? $comparison : -$comparison;
        });

        $format = "%-22s %7s %9s %11s %9s %9s%s\n";
        printf($format, 'Workload', 'Success', 'Min (ms)', 'Median (ms)', 'P95 (ms)', 'Max (ms)', '');
        echo str_repeat('-', 74) . "\n";
        $hasFailures = false;
        foreach ($rows as $row) {
            $result = $row['result'];
            $stats = $row['statistics'];
            $exceptional = $result->performed !== $result->requested
                || $result->successful !== $result->requested
                || $result->failed > 0 || $result->timedOut > 0;
            printf($format,
                $row['name'], "{$result->successful}/{$result->requested}",
                $stats === null ? 'N/A' : sprintf('%.3f', $stats['min']),
                $stats === null ? 'N/A' : sprintf('%.3f', $stats['median']),
                $stats === null ? 'N/A' : sprintf('%.3f', $stats['p95']),
                $stats === null ? 'N/A' : sprintf('%.3f', $stats['max']),
                $exceptional || $result->errors !== [] ? ' !' : '');
            if ($exceptional) {
                if ($result->performed !== $result->requested) {
                    printf("  requested=%d | performed=%d | successful=%d | failed=%d | timed out=%d\n",
                        $result->requested, $result->performed, $result->successful, $result->failed, $result->timedOut);
                } else {
                    printf("  %d successful | %d failed | %d timed out\n",
                        $result->successful, $result->failed, $result->timedOut);
                }
            }
            if ($result->errors !== []) {
                echo '  [FAILED] ' . implode('; ', $result->errors) . "\n";
            }
            $hasFailures = $result->hasFailures() || $hasFailures;
        }
        echo "\n";
        return $hasFailures;
    }

    private function displayBatchSize(int $requested, int $effective): void
    {
        if ($requested === $effective) {
            printf("Batch size: %d rows\n\n", $effective);
        } else {
            printf("Batch size: %d requested -> %d effective\n\n", $requested, $effective);
        }
    }

    private function reportMemory(string $phase): void
    {
        printf("PHP memory (%s)\n  limit: %s\n  usage: %.1f MiB (%d bytes)\n  peak usage: %.1f MiB (%d bytes)\n\n",
            $phase, ini_get('memory_limit') === '-1' ? 'unlimited (-1)' : sprintf('%.1f MiB (%s)', ini_parse_quantity(ini_get('memory_limit')) / 1048576, ini_get('memory_limit')),
            memory_get_usage(true) / 1048576, memory_get_usage(true),
            memory_get_peak_usage(true) / 1048576, memory_get_peak_usage(true));
    }

    private function printError(string $message): void
    {
        fwrite(STDERR, "Error: {$message}\n");
    }
}
