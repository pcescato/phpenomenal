<?php
declare(strict_types=1);

namespace DBench86\Benchmark;

use DBench86\Driver\DatabaseDriver;
use InvalidArgumentException;

/**
 * Batch limit calculation engine for DBench86 benchmark workloads.
 *
 * This class implements the batch size limitation algorithm defined in the
 * technical requirements (§2.2-§2.4 in TECHNICAL_REQUIREMENTS.md), computing safe batch sizes for all three
 * database drivers based on their operational constraints.
 *
 * The algorithm accounts for:
 * - Driver-specific bound parameter limits (MAX_BOUND_PARAMETERS)
 * - Driver-specific batch size limits (MAX_BATCH_SIZE)
 * - Per-row parameter count (varies by table schema)
 *
 * The result is a batch size that is safe for all drivers simultaneously,
 * ensuring that a single batch request will not exceed any driver's limits
 * in terms of bound parameters or batch size.
 */
final class BatchLimitCalculator
{
    /**
     * Calculate the maximum batch size for a single driver, given row-level
     * parameter constraints.
     *
     * This method computes how many rows can be batched in a single statement
     * for a specific driver, accounting for:
     *
     * 1. The driver's absolute MAX_BATCH_SIZE (operational constraint)
     * 2. The driver's MAX_BOUND_PARAMETERS limit divided by row parameter count
     *
     * The final limit is the minimum of these two constraints.
     *
     * Example:
     *   Driver: PostgreSQL (MAX_BATCH_SIZE=5000, MAX_BOUND_PARAMETERS=32000)
     *   Row parameters: 8 (e.g., INSERT into table with 8 bindable columns)
     *
     *   parameterLimit = floor(32000 / 8) = 4000
     *   result = min(5000, 4000) = 4000 rows max per batch
     *
     * @param DatabaseDriver $driver The database driver (provides MAX_* constants)
     * @param int $boundParametersPerRow Number of bound parameters per row in the workload.
     *                                    For INSERT: typically column count.
     *                                    For UPDATE: typically column count + WHERE clause params.
     *
     * @return int Maximum rows per batch for this driver.
     *
     * @throws InvalidArgumentException if boundParametersPerRow is not strictly positive.
     */
    public static function driverLimit(
        DatabaseDriver $driver,
        int $boundParametersPerRow,
    ): int {
        if ($boundParametersPerRow <= 0) {
            throw new InvalidArgumentException(
                'boundParametersPerRow must be strictly positive, got ' . $boundParametersPerRow
            );
        }

        // How many rows can we insert given the bound parameter limit?
        $parameterLimit = intdiv($driver::MAX_BOUND_PARAMETERS, $boundParametersPerRow);

        // Return the smaller of the two constraints.
        return min($driver::MAX_BATCH_SIZE, $parameterLimit);
    }

    /**
     * Calculate the maximum batch size that is safe for all drivers.
     *
     * When benchmarking multiple databases simultaneously, we must respect
     * the most conservative limit across all drivers. This method computes
     * the intersection of all driver-specific limits.
     *
     * Example:
     *   MariaDB driverLimit: 400 rows
     *   PostgreSQL driverLimit: 4000 rows
     *   SQLite driverLimit: 100 rows
     *
     *   result = min(400, 4000, 100) = 100 rows
     *
     * A batch size of 100 rows is safe for all three drivers, even though
     * PostgreSQL could technically handle 4000.
     *
     * @param array $driverLimits Array of driver-specific batch limits, typically
     *                             [mariadbLimit, postgresLimit, sqliteLimit].
     *                             Order does not matter; only the minimum is used.
     *
     * @return int Maximum batch size safe for all drivers.
     *
     * @throws InvalidArgumentException if $driverLimits is empty.
     */
    public static function commonLimit(array $driverLimits): int
    {
        if ($driverLimits === []) {
            throw new InvalidArgumentException(
                'At least one driver limit required'
            );
        }

        return min($driverLimits);
    }

    /**
     * Clamp the requested batch size to the effective limit.
     *
     * Users may request a batch size (via CLI: --batch-size=N), but the actual
     * batch size used must not exceed the calculated common limit across all
     * drivers, nor be less than 1.
     *
     * This method uses native PHP 8.6 clamp() to enforce bounds:
     *   - If requestedBatchSize < 1: clamped to 1
     *   - If requestedBatchSize > commonLimit: clamped to commonLimit
     *   - Otherwise: unchanged
     *
     * Example:
     *   commonLimit: 100
     *   requestedBatchSize: 50   → result: 50 (within bounds, unchanged)
     *   requestedBatchSize: 150  → result: 100 (clamped to limit)
     *   requestedBatchSize: 0    → result: 1 (minimum batch size)
     *
     * @param int $requestedBatchSize Batch size requested by user or application.
     * @param int $commonLimit Maximum batch size safe for all drivers.
     *
     * @return int Effective batch size to use in the benchmark workload.
     */
    public static function effectiveBatchSize(
        int $requestedBatchSize,
        int $commonLimit,
    ): int
    {
        return clamp($requestedBatchSize, 1, $commonLimit);
    }
}
