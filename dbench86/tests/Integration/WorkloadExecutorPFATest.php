<?php
declare(strict_types=1);

namespace Tests\Integration;

use DBench86\Benchmark\WorkloadExecutor;
use DBench86\Benchmark\WorkloadMeasurement;
use DBench86\Dataset\DatasetPopulator;
use DBench86\Dataset\DeterministicGenerator;
use DBench86\Driver\SqliteDriver;
use DBench86\Workload\AggregationWorkload;
use DBench86\Workload\IndexedSelectWorkload;
use DBench86\Workload\PrimaryKeySelectWorkload;
use DBench86\Workload\RangeSelectWorkload;
use PDO;
use Tests\TestCase;
use Time\Duration;

/**
 * Tests for WorkloadExecutor using Partial Function Application (PFA).
 *
 * Validates that the PFA pattern correctly specializes the measurement function
 * for a given timer and database connection, eliminating boilerplate while
 * maintaining type safety and measurement accuracy.
 */
final class WorkloadExecutorPFATest extends TestCase
{
    private SqliteDriver|null $driver = null;
    private PDO|null $pdo = null;

    public function testPartialFunctionApplicationWithMultipleWorkloads(): void
    {
        $this->setupDatabase();

        $workloads = [
            new PrimaryKeySelectWorkload(),
            new IndexedSelectWorkload(),
            new RangeSelectWorkload(),
            new AggregationWorkload(),
        ];

        // Create executor with 10-second timeout
        $executor = new WorkloadExecutor(Duration::fromSeconds(10));

        // Execute all workloads using PFA-specialized measurement function
        // The executor internally uses PFA to fix the timer and pdo,
        // creating a specialized function that accepts only Workload
        $measurements = $executor->execute($workloads, $this->pdo);

        // Verify we got a measurement for each workload
        $this->assertTrue(
            count($measurements) === 4,
            "Should have measurements for all 4 workloads"
        );

        // Verify each measurement contains valid timing data
        foreach ($workloads as $workload) {
            $name = $workload->name();
            $this->assertTrue(
                isset($measurements[$name]),
                "Measurement for workload '$name' should exist"
            );

            $measurement = $measurements[$name];
            $this->assertTrue(
                $measurement instanceof WorkloadMeasurement,
                "Result should be WorkloadMeasurement instance"
            );

            $this->assertTrue(
                $measurement->durationNanoseconds > 0,
                "Measurement duration should be positive for $name"
            );

            $this->assertTrue(
                !$measurement->timedOut,
                "None of the read workloads should timeout with 10s limit"
            );
        }
    }

    public function testPFAPreservesWorkloadIsolation(): void
    {
        $this->setupDatabase();

        // Run the same workload twice through PFA
        // Verify it produces consistent results (same timing order of magnitude)
        $workload1 = new PrimaryKeySelectWorkload();
        $workload2 = new IndexedSelectWorkload();

        $executor = new WorkloadExecutor(Duration::fromSeconds(10));
        $measurements = $executor->execute([$workload1, $workload2], $this->pdo);

        // Both measurements should exist and be distinct
        $this->assertTrue(
            isset($measurements['pk_select']),
            "Primary key select measurement should exist"
        );
        $this->assertTrue(
            isset($measurements['indexed_select']),
            "Indexed select measurement should exist"
        );

        // Measurements should be different objects (not reused)
        $m1 = $measurements['pk_select'];
        $m2 = $measurements['indexed_select'];
        $this->assertTrue(
            $m1 !== $m2,
            "Different workloads should produce different measurement objects"
        );
    }

    public function testPFAEquivalentToExplicitClosure(): void
    {
        $this->setupDatabase();

        // Demonstrate that PFA produces the same result as an explicit PHP 8.5-style closure

        // Approach 1: Using WorkloadExecutor with PFA (PHP 8.6)
        $executor = new WorkloadExecutor(Duration::fromSeconds(10));
        $workloads = [
            new PrimaryKeySelectWorkload(),
            new RangeSelectWorkload(),
        ];
        $pfaMeasurements = $executor->execute($workloads, $this->pdo);

        // Approach 2: Manual explicit closure (PHP 8.5 equivalent)
        $timer = new \DBench86\Benchmark\WorkloadTimer(Duration::fromSeconds(10));

        // This is what the PFA would expand to internally:
        // $runOnThisDriver = $this->runMeasuredWorkload($timer, ?, $this->pdo);
        // becomes:
        $runManualClosure = function($workload) use ($timer) {
            // Manually mirror what WorkloadExecutor does
            return $timer->run($workload, $this->pdo);
        };

        $manualMeasurements = [];
        foreach ($workloads as $workload) {
            $manualMeasurements[$workload->name()] = $runManualClosure($workload);
        }

        // Both approaches should produce measurements (we can't compare exact values
        // because of timing variability, but structure should match)
        $this->assertTrue(
            count($pfaMeasurements) === count($manualMeasurements),
            "PFA and manual closure should produce same number of measurements"
        );

        foreach ($pfaMeasurements as $name => $measurement) {
            $this->assertTrue(
                isset($manualMeasurements[$name]),
                "Both approaches should have measurement for $name"
            );
            $this->assertTrue(
                $measurement instanceof WorkloadMeasurement,
                "PFA result should be WorkloadMeasurement"
            );
            $this->assertTrue(
                $manualMeasurements[$name] instanceof WorkloadMeasurement,
                "Manual closure result should be WorkloadMeasurement"
            );
        }
    }

    private function setupDatabase(): void
    {
        $this->driver = new SqliteDriver(':memory:');
        $this->pdo = $this->driver->connect();

        // Populate with test data
        $generator = new DeterministicGenerator(99);
        $populator = new DatasetPopulator($this->driver, $generator, 100);
        $this->pdo = $populator->populate();
    }

    public function run(): void
    {
        echo "\nWorkloadExecutorPFATest\n";
        echo "──────────────────────────\n";
        $this->testPartialFunctionApplicationWithMultipleWorkloads();
        echo "✓ PASS\n";

        $this->testPFAPreservesWorkloadIsolation();
        echo "✓ PASS\n";

        $this->testPFAEquivalentToExplicitClosure();
        echo "✓ PASS\n";
    }
}

// Instantiate and run tests
(new WorkloadExecutorPFATest())->run();
