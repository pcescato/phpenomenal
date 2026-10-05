<?php
declare(strict_types=1);

namespace DBench86\Benchmark;

use DBench86\Workload\Workload;
use PDO;
use Time\Duration;

/**
 * High-resolution workload timing engine with timeout support.
 *
 * Measures workload execution time using monotonic timers (hrtime(true)) to avoid
 * being affected by system clock adjustments. Implements timeout checking using
 * PHP 8.6's native Time\Duration class for robust duration handling.
 *
 * Design principle: Measure ONLY the Workload::execute() call, exclude prepare()
 * and cleanup() from timing measurements. These operations are one-time setup/teardown,
 * not representative of benchmark workload performance.
 *
 * Timeout handling: If execution exceeds the timeout duration, mark the measurement
 * as timed out and increment EXECUTION_METRICS->timeouts. Do NOT throw exceptions,
 * which would interrupt the entire benchmark.
 *
 * Limitation (documented): PHP does not support interrupting a running PDO query
 * from within the same process. Timeout detection is therefore a posteriori
 * (after execution completes), not a priori (during execution). A workload that
 * takes 10 minutes will still complete even if timeout is 1 second.
 *
 * @see Workload interface contract (Phase E1)
 * @see EXECUTION_METRICS constant (Phase E1)
 * @see Time\Duration native PHP 8.6 feature
 */
final class WorkloadTimer
{
    /**
     * @param Duration $timeout Maximum allowed execution duration per workload.
     *                           Measured from start of Workload::execute() to end.
     *                           If exceeded, measurement is marked as timed out
     *                           and no exception is raised.
     */
    public function __construct(
        private readonly Duration $timeout,
    ) {}

    /**
     * Execute a workload with timing and timeout checking.
     *
     * IMPORTANT: This method ONLY times the Workload::execute() call.
     * Workload::prepare() and cleanup() are called by the orchestrator (Application.php),
     * NOT by this timer. This ensures:
     * - prepare() is called exactly once per workload (not per execution)
     * - cleanup() is called exactly once per workload (not per execution)
     * - executionCount accurately tracks total executions across all runs
     *
     * Workflow:
     * 1. Start high-resolution monotonic timer
     * 2. Call Workload::execute($pdo) with exception handling
     *    - If execute() throws: catch it, increment failures, continue benchmark
     *    - If execute() succeeds: capture return value (rows affected)
     * 3. Stop timer
     * 4. Check if elapsed time exceeds timeout
     * 5. Update metrics:
     *    - EXECUTION_METRICS->queries += 1 (one execution attempt, regardless of outcome)
     *    - If successful AND not timed out: EXECUTION_METRICS->runsCompleted += 1
     *    - If exception thrown: EXECUTION_METRICS->failures += 1
     *    - If timed out: EXECUTION_METRICS->timeouts += 1
     * 6. Return WorkloadMeasurement with elapsed time, rows affected, timeout flag, failure flag
     *
     * Semantic guarantee:
     * - queries = total execution attempts (always incremented, success or failure)
     * - runsCompleted = successful executions (queries - failures - timeouts, typically)
     * - failures = execution exceptions not caught at higher level
     * - timeouts = runs exceeding timeout threshold
     *
     * @param Workload $workload The workload to execute and measure.
     * @param PDO $pdo Database connection for the workload.
     *
     * @return WorkloadMeasurement Result containing duration, rows affected, timeout flag, failed flag.
     */
    public function run(Workload $workload, PDO $pdo): WorkloadMeasurement
    {
        // Phase 1: Start monotonic timer (nanosecond precision)
        // hrtime(true) is monotonic and not affected by system clock adjustments
        // NOTE: prepare() is NOT called here; it's called by the orchestrator (Application.php)
        // This ensures prepare() is called exactly once per workload, not per execution
        $startTime = hrtime(true);

        // Phase 2: Execute workload (STRICTLY TIMED) with exception handling
        // The execute() method returns the number of logical operations performed.
        // If it throws an exception, we catch it to prevent interrupting the benchmark.
        $rowsAffected = 0;
        $executionFailed = false;
        $executionException = null;

        try {
            $rowsAffected = $workload->execute($pdo);
        } catch (\Throwable $e) {
            $executionFailed = true;
            $executionException = $e;
        }

        // Phase 3: Stop monotonic timer
        $endTime = hrtime(true);
        $elapsedNanoseconds = $endTime - $startTime;

        // Phase 4: Check for timeout (a posteriori)
        // Convert elapsed nanoseconds to Duration for proper comparison.
        // Use Duration::compare() for explicit, documented comparison rather than
        // relying on operator overloading (which works, but compare() is the standard API).
        $elapsedDuration = Duration::fromNanoseconds($elapsedNanoseconds);
        $isTimeout = !$executionFailed &&
                     Duration::compare($elapsedDuration, $this->timeout) > 0;

        // Phase 5: Update metrics
        // These are fast O(1) operations that happen AFTER timing
        // NOTE: cleanup() is NOT called here; it's called by the orchestrator (Application.php)
        // This ensures cleanup() is called exactly once per workload, not per execution
        EXECUTION_METRICS->queries += 1; // One workload execution attempt

        // Only count as completed if no failure and no timeout
        if (!$executionFailed && !$isTimeout) {
            EXECUTION_METRICS->runsCompleted += 1;
        }

        // Increment failure counter if exception occurred
        if ($executionFailed) {
            EXECUTION_METRICS->failures += 1;
        }

        // Increment timeout counter if execution exceeded timeout
        if ($isTimeout) {
            EXECUTION_METRICS->timeouts += 1;
        }

        // Phase 6: Return measurement result
        return new WorkloadMeasurement(
            durationNanoseconds: $elapsedNanoseconds,
            rowsAffected: $rowsAffected,
            timedOut: $isTimeout,
            failed: $executionFailed,
        );
    }
}
