<?php
declare(strict_types=1);

namespace Tests\Unit;

use DBench86\Benchmark\EXECUTION_METRICS;
use DBench86\Benchmark\WorkloadTimer;
use DBench86\Benchmark\WorkloadMeasurement;
use DBench86\Dataset\DatasetPopulator;
use DBench86\Dataset\DeterministicGenerator;
use DBench86\Driver\SqliteDriver;
use DBench86\Workload\Workload;
use PDO;
use Time\Duration;

/**
 * Comprehensive tests for WorkloadTimer with timeout handling.
 *
 * Validates:
 * - Monotonic timing (hrtime-based)
 * - Timeout detection via Time\Duration
 * - Metrics integration
 * - Cleanup and preparation exclusion from timing
 * - Workload isolation after timeout
 */
final class WorkloadTimerTest extends \Tests\TestCase
{
    private ?PDO $pdo = null;

    public function setup(): void
    {
        // Reset metrics before each test
        \DBench86\Benchmark\EXECUTION_METRICS->queries = 0;
        \DBench86\Benchmark\EXECUTION_METRICS->timeouts = 0;
        \DBench86\Benchmark\EXECUTION_METRICS->failures = 0;
        \DBench86\Benchmark\EXECUTION_METRICS->runsCompleted = 0;

        // Create temp SQLite database for testing
        $driver = new SqliteDriver(':memory:');
        $this->pdo = $driver->connect();

        // Populate with test data using DatasetPopulator
        // (This handles schema creation and data generation)
        $generator = new DeterministicGenerator(42);
        $populator = new DatasetPopulator($driver, $generator, 100);
        $this->pdo = $populator->populate(); // populate() returns the PDO connection
    }

    public function teardown(): void
    {
        if ($this->pdo) {
            $this->pdo = null;
        }
        // Reset metrics after each test to ensure isolation
        \DBench86\Benchmark\EXECUTION_METRICS->queries = 0;
        \DBench86\Benchmark\EXECUTION_METRICS->timeouts = 0;
        \DBench86\Benchmark\EXECUTION_METRICS->failures = 0;
        \DBench86\Benchmark\EXECUTION_METRICS->runsCompleted = 0;
        // SQLite :memory: database cleaned up automatically
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: Basic timing measurement
    // ─────────────────────────────────────────────────────────────────

    public function testTimerMeasuresExecutionDuration(): void
    {
        $timeout = Duration::fromSeconds(10);
        $timer = new WorkloadTimer($timeout);

        $measurement = $this->measure($timer,
            new \DBench86\Workload\PrimaryKeySelectWorkload(),
            $this->pdo
        );

        // Measurement should have recorded duration
        $this->assertTrue($measurement->durationNanoseconds > 0,
            'Duration should be positive');
        $this->assertTrue($measurement->durationNanoseconds < 1_000_000_000,
            'Fast query should complete in < 1 second');
        $this->assertTrue(!$measurement->timedOut,
            'Fast query should not timeout with 10s limit');
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: Duration conversion methods
    // ─────────────────────────────────────────────────────────────────

    public function testDurationConversions(): void
    {
        $measurement = new WorkloadMeasurement(
            durationNanoseconds: 1_500_000_000, // 1.5 seconds
            rowsAffected: 50,
        );

        $seconds = $measurement->durationSeconds();
        $this->assertTrue($seconds === 1.5,
            'Should convert nanoseconds to seconds correctly');

        $milliseconds = $measurement->durationMilliseconds();
        $this->assertTrue($milliseconds === 1500.0,
            'Should convert nanoseconds to milliseconds correctly');
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: Throughput calculation
    // ─────────────────────────────────────────────────────────────────

    public function testRowsPerSecondCalculation(): void
    {
        $measurement = new WorkloadMeasurement(
            durationNanoseconds: 1_000_000_000, // 1 second
            rowsAffected: 1000,
        );

        $throughput = $measurement->rowsPerSecond();
        $this->assertTrue($throughput === 1000.0,
            'Should calculate 1000 rows/second correctly');

        // Test division by zero protection
        $zeroTime = new WorkloadMeasurement(
            durationNanoseconds: 0,
            rowsAffected: 1000,
        );
        $this->assertTrue($zeroTime->rowsPerSecond() === 0.0,
            'Should return 0.0 for zero duration (prevent div by zero)');
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: Timeout detection with Time\Duration
    // ─────────────────────────────────────────────────────────────────

    public function testTimeoutDetectionWithDuration(): void
    {
        // Very short timeout (10ms)
        $shortTimeout = Duration::fromMilliseconds(10);
        $timer = new WorkloadTimer($shortTimeout);

        $workload = new SlowTestWorkload(200); // Sleep 200ms in execute
        $measurement = $this->measure($timer, $workload, $this->pdo);

        $this->assertTrue($measurement->timedOut,
            'Workload taking 200ms should timeout with 10ms limit');
        $this->assertEquals(1, $measurement->rowsAffected, 'Completed timeout retains returned operation count');
        $this->assertTrue($measurement->durationNanoseconds > 200_000_000,
            'Duration should reflect actual execution time (>200ms)');
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: No timeout with sufficient duration
    // ─────────────────────────────────────────────────────────────────

    public function testNoTimeoutWithSufficientDuration(): void
    {
        // Generous timeout (1 second)
        $generousTimeout = Duration::fromSeconds(1);
        $timer = new WorkloadTimer($generousTimeout);

        $workload = new SlowTestWorkload(50); // Sleep 50ms
        $measurement = $this->measure($timer, $workload, $this->pdo);

        $this->assertTrue(!$measurement->timedOut,
            'Workload taking 50ms should not timeout with 1s limit');
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: EXECUTION_METRICS integration
    // ─────────────────────────────────────────────────────────────────

    public function testExecutionMetricsIncremented(): void
    {
        // Reset metrics before this specific test
        \DBench86\Benchmark\EXECUTION_METRICS->queries = 0;
        \DBench86\Benchmark\EXECUTION_METRICS->timeouts = 0;

        $timeout = Duration::fromSeconds(10);
        $timer = new WorkloadTimer($timeout);

        // Execute fast workload
        $this->measure($timer,
            new \DBench86\Workload\PrimaryKeySelectWorkload(),
            $this->pdo
        );

        $this->assertTrue(\DBench86\Benchmark\EXECUTION_METRICS->queries === 1,
            'queries should increment by 1');
        $this->assertTrue(\DBench86\Benchmark\EXECUTION_METRICS->timeouts === 0,
            'timeouts should stay 0 for non-timeout execution');
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: EXECUTION_METRICS timeout increment
    // ─────────────────────────────────────────────────────────────────

    public function testExecutionMetricsTimeoutIncremented(): void
    {
        // Reset metrics before this specific test
        \DBench86\Benchmark\EXECUTION_METRICS->queries = 0;
        \DBench86\Benchmark\EXECUTION_METRICS->timeouts = 0;

        $shortTimeout = Duration::fromMilliseconds(10);
        $timer = new WorkloadTimer($shortTimeout);

        $workload = new SlowTestWorkload(200); // Will timeout
        $this->measure($timer, $workload, $this->pdo);

        $this->assertTrue(\DBench86\Benchmark\EXECUTION_METRICS->queries === 1,
            'queries should increment even on timeout');
        $this->assertTrue(\DBench86\Benchmark\EXECUTION_METRICS->timeouts === 1,
            'timeouts should increment when measurement is timed out');
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: Multiple consecutive measurements
    // ─────────────────────────────────────────────────────────────────

    public function testMultipleMeasurements(): void
    {
        // Reset metrics before this specific test
        \DBench86\Benchmark\EXECUTION_METRICS->queries = 0;
        \DBench86\Benchmark\EXECUTION_METRICS->timeouts = 0;

        $timeout = Duration::fromSeconds(10);
        $timer = new WorkloadTimer($timeout);
        $workload = new \DBench86\Workload\PrimaryKeySelectWorkload();

        // Execute 5 times
        for ($i = 0; $i < 5; $i++) {
            $measurement = $this->measure($timer, $workload, $this->pdo);
            $this->assertTrue(!$measurement->timedOut, "Iteration $i should not timeout");
        }

        $this->assertTrue(\DBench86\Benchmark\EXECUTION_METRICS->queries === 5,
            'Should have 5 queries after 5 executions');
        $this->assertTrue(\DBench86\Benchmark\EXECUTION_METRICS->timeouts === 0,
            'Should have 0 timeouts for fast queries');
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: Prepare and cleanup not included in timing
    // ─────────────────────────────────────────────────────────────────

    public function testPrepareAndCleanupExcludedFromTiming(): void
    {
        $timeout = Duration::fromSeconds(10);
        $timer = new WorkloadTimer($timeout);

        $workload = new TimingCheckWorkload();
        $measurement = $this->measure($timer, $workload, $this->pdo);

        // The TimingCheckWorkload tracks whether prepare/cleanup were timed
        // This test verifies they were NOT included in the duration
        $this->assertTrue($measurement->durationNanoseconds > 0,
            'Should have recorded execution time');

        $this->assertTrue($measurement->durationNanoseconds < $workload->getPrepareAndCleanupNanoseconds(),
            'Preparation and cleanup delays must be outside measured execution');

        // Verify workload's bookkeeping shows timing was correct
        $prepareAndCleanupTime = $workload->getPrepareAndCleanupNanoseconds();
        $this->assertTrue($prepareAndCleanupTime >= 20_000_000,
            'Prepare and cleanup overhead tracked separately');
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: Workload isolation after timeout
    // ─────────────────────────────────────────────────────────────────

    public function testWorkloadIsolationAfterTimeout(): void
    {
        $shortTimeout = Duration::fromMilliseconds(10);
        $timer = new WorkloadTimer($shortTimeout);

        // First workload times out
        $workload = new SlowTestWorkload(200);
        $measurement1 = $this->measure($timer, $workload, $this->pdo);
        $this->assertTrue($measurement1->timedOut, 'First should timeout');

        // Second workload should execute normally
        // (isolation ensures no state leakage from timed-out workload)
        $measurement2 = $this->measure($timer,
            new \DBench86\Workload\PrimaryKeySelectWorkload(),
            $this->pdo
        );
        $this->assertTrue(!$measurement2->timedOut,
            'Second workload should complete normally despite prior timeout');
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: Time\Duration comparison correctness
    // ─────────────────────────────────────────────────────────────────

    public function testTimeDurationComparisonBoundary(): void
    {
        // Test exact boundary: execution time == timeout
        $measurement = new WorkloadMeasurement(
            durationNanoseconds: 1_000_000_000, // Exactly 1 second
            rowsAffected: 100,
        );

        $timeout = Duration::fromSeconds(1);
        $elapsedDuration = Duration::fromNanoseconds($measurement->durationNanoseconds);

        // Time\Duration should handle equality correctly
        $isTimeout = Duration::compare($elapsedDuration, $timeout) > 0; // Not >= , strictly >
        $this->assertTrue(!$isTimeout,
            'Execution time == timeout should NOT trigger timeout (use > not >=)');
    }

    // ─────────────────────────────────────────────────────────────────
    // Test: Nanosecond precision preservation
    // ─────────────────────────────────────────────────────────────────

    public function testNanosecondPrecision(): void
    {
        $timeout = Duration::fromSeconds(10);
        $timer = new WorkloadTimer($timeout);

        // Execute a fast query multiple times
        $workload = new \DBench86\Workload\PrimaryKeySelectWorkload();
        $measurements = [];
        for ($i = 0; $i < 3; $i++) {
            $measurements[] = $this->measure($timer, $workload, $this->pdo);
        }

        // All measurements should have nanosecond-precision values
        foreach ($measurements as $m) {
            $this->assertTrue($m->durationNanoseconds > 0,
                'Each measurement should record nanoseconds');
            $this->assertTrue(is_int($m->durationNanoseconds),
                'Duration should be integer nanoseconds');
        }

        // Verify measurements are not identical (different query execution times)
        $durations = array_map(fn($m) => $m->durationNanoseconds, $measurements);
        $uniqueDurations = array_unique($durations);
        $this->assertTrue(count($uniqueDurations) > 1,
            'Multiple query executions should have different durations');
    }

    private function measure(WorkloadTimer $timer, Workload $workload, PDO $pdo): WorkloadMeasurement
    {
        $workload->prepare($pdo);
        try {
            $measurement = $timer->run($workload, $pdo);
            $this->assertTrue(!$measurement->failed, 'Timer fixture must execute successfully');
            return $measurement;
        } finally {
            $workload->cleanup($pdo);
        }
    }

    public function run(): void
    {
        echo "WorkloadTimerTest\n";
        echo "──────────────────\n";

        $methods = [
            'testTimerMeasuresExecutionDuration',
            'testDurationConversions',
            'testRowsPerSecondCalculation',
            'testTimeoutDetectionWithDuration',
            'testNoTimeoutWithSufficientDuration',
            'testExecutionMetricsIncremented',
            'testExecutionMetricsTimeoutIncremented',
            'testMultipleMeasurements',
            'testPrepareAndCleanupExcludedFromTiming',
            'testWorkloadIsolationAfterTimeout',
            'testTimeDurationComparisonBoundary',
            'testNanosecondPrecision',
        ];

        foreach ($methods as $method) {
            try {
                $this->setup();
                $this->$method();

            } catch (\AssertionError $e) {
                $this->runner->fail($e->getMessage());
            } catch (\Throwable $e) {
                $this->runner->fail($e->getMessage());
            } finally {
                $this->teardown();
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────
// Test Fixtures: Slow Workload
// ─────────────────────────────────────────────────────────────────

/**
 * Test fixture: A workload that sleeps in execute() to test timeout detection.
 * Not for production use.
 */
final class SlowTestWorkload implements Workload
{
    public function __construct(private int $sleepMilliseconds = 100) {}

    public function name(): string
    {
        return 'slow_test_workload';
    }

    public function prepare(\PDO $pdo): void
    {
        // No-op
    }

    public function execute(\PDO $pdo): int
    {
        // Sleep for specified milliseconds
        // usleep() takes microseconds: 1ms = 1000 µs
        usleep($this->sleepMilliseconds * 1000);
        return 1;
    }

    public function cleanup(\PDO $pdo): void
    {
        // No-op
    }

    public function boundParametersPerRow(): ?int
    {
        return null;
    }
}

/**
 * Test fixture: A workload that tracks whether prepare/cleanup were included in timing.
 * Not for production use.
 */
final class TimingCheckWorkload implements Workload
{
    private int $prepareAndCleanupNanoseconds = 0;

    public function name(): string
    {
        return 'timing_check_workload';
    }

    public function prepare(\PDO $pdo): void
    {
        $start = hrtime(true);
        usleep(10000); // 10ms overhead in prepare
        $this->prepareAndCleanupNanoseconds += hrtime(true) - $start;
    }

    public function execute(\PDO $pdo): int
    {
        // Quick operation
        usleep(5000); // 5ms for execute
        return 1;
    }

    public function cleanup(\PDO $pdo): void
    {
        $start = hrtime(true);
        usleep(10000); // 10ms overhead in cleanup
        $this->prepareAndCleanupNanoseconds += hrtime(true) - $start;
    }

    public function boundParametersPerRow(): ?int
    {
        return null;
    }

    public function getPrepareAndCleanupNanoseconds(): int
    {
        return $this->prepareAndCleanupNanoseconds;
    }
}

// Instantiate and run tests
(new WorkloadTimerTest())->run();
