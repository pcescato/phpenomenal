<?php
declare(strict_types=1);

namespace DBench86\Benchmark;

/**
 * Global execution metrics for benchmark runs.
 *
 * Tracks aggregate statistics across all workload executions in a benchmark session.
 * This is the single source of truth for run counters; it must never be replaced,
 * only mutated by incrementing its public properties.
 *
 * Intended usage pattern:
 *   EXECUTION_METRICS->queries += 1;  // One query = one execute() call
 *   EXECUTION_METRICS->runsCompleted++;
 *
 * Not intended for:
 *   - Storing workload-specific results (each workload returns its own data)
 *   - Tracking database-specific performance metrics (those belong in dedicated result objects)
 *   - Thread-local or process-local state (this is global, not concurrent-safe)
 */
final class ExecutionMetrics
{
    /**
     * Total number of queries executed (one per execute() call).
     *
     * Incremented by 1 for each successful workload.execute() invocation,
     * regardless of the number of rows affected or returned by that query.
     * For tracking rows affected, see the WorkloadMeasurement return value.
     */
    public int $queries = 0;

    /**
     * Number of workload runs that completed successfully.
     *
     * Incremented once per successful execute() invocation.
     */
    public int $runsCompleted = 0;

    /**
     * Number of workload runs that failed.
     *
     * Incremented when a workload.execute() throws an exception or returns an error.
     * Not incremented for connection failures or benchmark infrastructure errors.
     */
    public int $failures = 0;

    /**
     * Number of workload runs that timed out.
     *
     * Incremented when a workload execution exceeds the configured timeout threshold.
     * May be a subset of $failures (timeouts can be treated as failures) depending on
     * benchmark configuration.
     */
    public int $timeouts = 0;
}

/**
 * Global constant holding the single ExecutionMetrics instance for this benchmark session.
 *
 * This constant is initialized once at startup and never reassigned. All workload
 * executions increment its properties directly:
 *
 *   EXECUTION_METRICS->queries += 1;  // One query = one execute() call
 *   EXECUTION_METRICS->runsCompleted++;
 *   EXECUTION_METRICS->failures++;
 *
 * Attempting to reassign the constant itself results in a deprecation warning in PHP 8.6+
 * (not a fatal error), which is acceptable for this stateless instrumentation pattern.
 * The constant's immutability is a documentation/design intent, not enforced at runtime.
 *
 * @see https://wiki.php.net/rfc/typed_properties_constants_deprecation
 */
const EXECUTION_METRICS = new ExecutionMetrics();
