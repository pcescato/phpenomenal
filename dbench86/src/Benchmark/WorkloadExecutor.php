<?php
declare(strict_types=1);

namespace DBench86\Benchmark;

use DBench86\Workload\Workload;
use PDO;
use Time\Duration;

/**
 * Orchestrates the execution of multiple workloads with consistent timing and metrics.
 *
 * Primary responsibility:
 * - Execute each workload in the benchmark suite using WorkloadTimer
 * - Track execution metrics across all runs
 * - Apply Partial Function Application (PFA) to specialize the measurement function
 *   for a given database connection and timer, reducing boilerplate when iterating
 *   over multiple workloads
 *
 * Design Rationale for PFA:
 * When running multiple workloads against the same database driver, the WorkloadTimer
 * and PDO connection remain constant across iterations. Using PFA, we specialize
 * the measurement function once, then iterate over workloads without repeating the
 * timer/connection arguments.
 *
 * Before PFA (PHP 8.5 style - using explicit closure):
 *   $measurements = [];
 *   foreach ($workloads as $workload) {
 *       $measurements[] = $timer->run($workload, $pdo);
 *   }
 *
 * With PFA (PHP 8.6 style - using native placeholder):
 *   $runOnThisDriver = $this->runMeasuredWorkload($timer, ?, $pdo);
 *   foreach ($workloads as $workload) {
 *       $measurements[] = $runOnThisDriver($workload);
 *   }
 *
 * The PFA approach is cleaner because:
 * 1. The partial function signature clearly shows what varies ($workload) vs. what's fixed
 * 2. No manual closure boilerplate needed
 * 3. Type safety is preserved at specialization time
 *
 * @see WorkloadTimer For the actual timing measurement logic
 * @see EXECUTION_METRICS For global metrics tracking
 */
final class WorkloadExecutor
{
    /**
     * @param Duration $timeout Timeout duration for each workload execution.
     */
    public function __construct(
        private readonly Duration $timeout,
    ) {}

    /**
     * Execute a collection of workloads against a specific database connection.
     *
     * Uses Partial Function Application to specialize the measurement function
     * for the given timer and PDO connection, creating a specialized function
     * that accepts only a Workload parameter.
     *
     * @param array<Workload> $workloads List of workloads to execute.
     * @param PDO $pdo Database connection for the workloads.
     *
     * @return array<string, WorkloadMeasurement> Map of workload name -> measurement result.
     */
    public function execute(array $workloads, PDO $pdo): array
    {
        $timer = new WorkloadTimer($this->timeout);

        // PHP 8.6 Partial Function Application:
        // Specialize runMeasuredWorkload() by fixing the timer and pdo,
        // leaving the workload parameter free. The resulting partial function
        // accepts only a Workload and returns a WorkloadMeasurement.
        //
        // Equivalent PHP 8.5 implementation (explicit closure):
        //   $runOnThisDriver = function(Workload $workload): WorkloadMeasurement {
        //       return $timer->run($workload, $pdo);
        //   };
        //
        // With PFA, the function signature is declarative and type-checked at
        // specialization time, not hidden inside a closure body.
        $runOnThisDriver = $this->runMeasuredWorkload($timer, ?, $pdo);

        $measurements = [];
        foreach ($workloads as $workload) {
            // Call the specialized function with the workload
            // The timer and pdo are already bound from the PFA
            $measurement = $runOnThisDriver($workload);
            $measurements[$workload->name()] = $measurement;
        }

        return $measurements;
    }

    /**
     * Generic workload measurement function to be specialized via PFA.
     *
     * This function is designed to be partially applied: the timer and pdo
     * are fixed, leaving the workload as the free parameter.
     *
     * In PHP 8.6, the placeholder syntax $this->runMeasuredWorkload($timer, ?, $pdo)
     * creates a Closure that binds $timer and $pdo, with the ? position accepting
     * a Workload when the partial is called.
     *
     * @param WorkloadTimer $timer The timer to use for measurement.
     * @param Workload $workload The workload to execute and measure.
     * @param PDO $pdo The database connection.
     *
     * @return WorkloadMeasurement The execution measurement result.
     */
    private function runMeasuredWorkload(
        WorkloadTimer $timer,
        Workload $workload,
        PDO $pdo,
    ): WorkloadMeasurement {
        return $timer->run($workload, $pdo);
    }
}
