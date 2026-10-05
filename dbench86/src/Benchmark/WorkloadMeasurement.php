<?php
declare(strict_types=1);

namespace DBench86\Benchmark;

/**
 * Encapsulates timing and outcome metrics for a single workload execution.
 *
 * Stores:
 * - Elapsed time in nanoseconds (from hrtime(true))
 * - Number of rows affected by the workload
 * - Whether the workload timed out
 * - Whether the workload failed (threw an exception)
 *
 * This data is used by WorkloadExecutor to aggregate performance statistics
 * and identify problematic workloads that exceed timeout thresholds or throw exceptions.
 */
final class WorkloadMeasurement
{
    /**
     * @param int $durationNanoseconds Elapsed wall time in nanoseconds (hrtime(true) based).
     *                                  Not affected by system clock adjustments.
     * @param int $rowsAffected Number of rows affected by the workload execution.
     *                           Zero if the workload failed or timed out before returning.
     * @param bool $timedOut True if execution exceeded the configured timeout.
     * @param bool $failed True if the workload threw an exception during execute().
     */
    public function __construct(
        public readonly int $durationNanoseconds,
        public readonly int $rowsAffected,
        public readonly bool $timedOut = false,
        public readonly bool $failed = false,
    ) {}

    /**
     * Get elapsed duration as seconds (floating point).
     *
     * Useful for human-readable reporting and simple averaging.
     * Nanoseconds are divided by 1,000,000,000 to convert to seconds.
     *
     * Example:
     *   $elapsed = 1_500_000_000 nanoseconds
     *   $seconds = 1.5 seconds
     */
    public function durationSeconds(): float
    {
        return $this->durationNanoseconds / 1_000_000_000;
    }

    /**
     * Get elapsed duration as milliseconds (floating point).
     *
     * Useful for sub-second precision reporting.
     * Nanoseconds are divided by 1,000,000 to convert to milliseconds.
     *
     * Example:
     *   $elapsed = 1_500_000_000 nanoseconds
     *   $milliseconds = 1500.0 ms
     */
    public function durationMilliseconds(): float
    {
        return $this->durationNanoseconds / 1_000_000;
    }

    /**
     * Get throughput: rows affected per second.
     *
     * Returns 0 if duration is zero (prevents division by zero).
     * Useful for comparative performance analysis.
     *
     * Example:
     *   $elapsed = 1.0 second
     *   $rows = 1000
     *   $throughput = 1000.0 rows/sec
     */
    public function rowsPerSecond(): float
    {
        if ($this->durationNanoseconds === 0) {
            return 0.0;
        }
        return $this->rowsAffected / $this->durationSeconds();
    }
}
