<?php
declare(strict_types=1);

namespace Tests\Unit;

use DBench86\Benchmark\ExecutionMetrics;
use Tests\TestCase;

final class ExecutionMetricsTest extends TestCase
{
    public function run(): void
    {
        $this->testExecutionMetricsClassExists();
        $this->testExecutionMetricsIsFinal();
        $this->testExecutionMetricsHasQueriesProperty();
        $this->testExecutionMetricsHasRunsCompletedProperty();
        $this->testExecutionMetricsHasFailuresProperty();
        $this->testExecutionMetricsHasTimeoutsProperty();
        $this->testExecutionMetricsPropertiesInitializeToZero();
        $this->testExecutionMetricsPropertiesCanBeIncremented();
        $this->testExecutionMetricsConstantExists();
        $this->testExecutionMetricsConstantIsExecutionMetricsInstance();
        $this->testExecutionMetricsConstantCanHavePropertiesModified();
        $this->testExecutionMetricsConstantIsUnique();
        $this->testExecutionMetricsCanTrackMultipleOperations();
    }

    public function testExecutionMetricsClassExists(): void
    {
        $this->assertTrue(class_exists(ExecutionMetrics::class));
    }

    public function testExecutionMetricsIsFinal(): void
    {
        $reflection = new \ReflectionClass(ExecutionMetrics::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testExecutionMetricsHasQueriesProperty(): void
    {
        $reflection = new \ReflectionClass(ExecutionMetrics::class);
        $this->assertTrue($reflection->hasProperty('queries'));

        $property = $reflection->getProperty('queries');
        $this->assertTrue($property->isPublic());

        // Check type is int
        $type = $property->getType();
        $this->assertTrue($type !== null, 'queries property should have type');
        $this->assertEquals('int', (string)$type);
    }

    public function testExecutionMetricsHasRunsCompletedProperty(): void
    {
        $reflection = new \ReflectionClass(ExecutionMetrics::class);
        $this->assertTrue($reflection->hasProperty('runsCompleted'));

        $property = $reflection->getProperty('runsCompleted');
        $this->assertTrue($property->isPublic());

        // Check type is int
        $type = $property->getType();
        $this->assertTrue($type !== null, 'runsCompleted property should have type');
        $this->assertEquals('int', (string)$type);
    }

    public function testExecutionMetricsHasFailuresProperty(): void
    {
        $reflection = new \ReflectionClass(ExecutionMetrics::class);
        $this->assertTrue($reflection->hasProperty('failures'));

        $property = $reflection->getProperty('failures');
        $this->assertTrue($property->isPublic());

        // Check type is int
        $type = $property->getType();
        $this->assertTrue($type !== null, 'failures property should have type');
        $this->assertEquals('int', (string)$type);
    }

    public function testExecutionMetricsHasTimeoutsProperty(): void
    {
        $reflection = new \ReflectionClass(ExecutionMetrics::class);
        $this->assertTrue($reflection->hasProperty('timeouts'));

        $property = $reflection->getProperty('timeouts');
        $this->assertTrue($property->isPublic());

        // Check type is int
        $type = $property->getType();
        $this->assertTrue($type !== null, 'timeouts property should have type');
        $this->assertEquals('int', (string)$type);
    }

    public function testExecutionMetricsPropertiesInitializeToZero(): void
    {
        $metrics = new ExecutionMetrics();

        $this->assertEquals(0, $metrics->queries);
        $this->assertEquals(0, $metrics->runsCompleted);
        $this->assertEquals(0, $metrics->failures);
        $this->assertEquals(0, $metrics->timeouts);
    }

    public function testExecutionMetricsPropertiesCanBeIncremented(): void
    {
        $metrics = new ExecutionMetrics();

        $metrics->queries += 100;
        $metrics->runsCompleted += 1;
        $metrics->failures += 2;
        $metrics->timeouts += 1;

        $this->assertEquals(100, $metrics->queries);
        $this->assertEquals(1, $metrics->runsCompleted);
        $this->assertEquals(2, $metrics->failures);
        $this->assertEquals(1, $metrics->timeouts);
    }

    public function testExecutionMetricsConstantExists(): void
    {
        $this->assertTrue(defined('DBench86\\Benchmark\\EXECUTION_METRICS'));
    }

    public function testExecutionMetricsConstantIsExecutionMetricsInstance(): void
    {
        $this->assertTrue(\DBench86\Benchmark\EXECUTION_METRICS instanceof ExecutionMetrics);
    }

    public function testExecutionMetricsConstantCanHavePropertiesModified(): void
    {
        // Save initial state
        $initialQueries = \DBench86\Benchmark\EXECUTION_METRICS->queries;
        $initialRuns = \DBench86\Benchmark\EXECUTION_METRICS->runsCompleted;

        // Modify via constant reference
        \DBench86\Benchmark\EXECUTION_METRICS->queries += 50;
        \DBench86\Benchmark\EXECUTION_METRICS->runsCompleted += 5;

        // Verify modifications
        $this->assertEquals($initialQueries + 50, \DBench86\Benchmark\EXECUTION_METRICS->queries);
        $this->assertEquals($initialRuns + 5, \DBench86\Benchmark\EXECUTION_METRICS->runsCompleted);

        // Restore for other tests
        \DBench86\Benchmark\EXECUTION_METRICS->queries = $initialQueries;
        \DBench86\Benchmark\EXECUTION_METRICS->runsCompleted = $initialRuns;
    }

    public function testExecutionMetricsConstantIsUnique(): void
    {
        // Verify that all references to \DBench86\Benchmark\EXECUTION_METRICS point to the same instance
        $ref1 = \DBench86\Benchmark\EXECUTION_METRICS;
        $ref2 = \DBench86\Benchmark\EXECUTION_METRICS;

        $this->assertTrue($ref1 === $ref2);
    }

    public function testExecutionMetricsCanTrackMultipleOperations(): void
    {
        $metrics = new ExecutionMetrics();

        // Simulate a workload run sequence
        $metrics->queries += 1000;      // First workload: 1000 ops
        $metrics->runsCompleted += 1;

        $metrics->queries += 500;       // Second workload: 500 ops (partial result)
        $metrics->runsCompleted += 1;

        $metrics->queries += 0;         // Third workload failed
        $metrics->failures += 1;

        $this->assertEquals(1500, $metrics->queries);
        $this->assertEquals(2, $metrics->runsCompleted);
        $this->assertEquals(1, $metrics->failures);
    }
}

$executionMetricsTest = new ExecutionMetricsTest();
$executionMetricsTest->run();
