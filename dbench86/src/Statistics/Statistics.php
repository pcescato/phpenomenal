<?php

declare(strict_types=1);

namespace DBench86\Statistics;

use InvalidArgumentException;

/**
 * Statistics calculator for benchmark measurements.
 *
 * All methods operate on arrays of numeric values (durations in nanoseconds,
 * converted to milliseconds, etc.) and return float results.
 *
 * Empty arrays are explicitly rejected with InvalidArgumentException rather
 * than silently returning NAN or 0.
 */
final class Statistics
{
    /**
     * Calculates the minimum value from an array of durations.
     *
     * @param array<int|float> $durations
     * @return float The minimum value
     * @throws InvalidArgumentException If the array is empty
     */
    public static function minimum(array $durations): float
    {
        if (count($durations) === 0) {
            throw new InvalidArgumentException('Cannot calculate minimum of empty array');
        }

        return (float) min($durations);
    }

    /**
     * Calculates the maximum value from an array of durations.
     *
     * @param array<int|float> $durations
     * @return float The maximum value
     * @throws InvalidArgumentException If the array is empty
     */
    public static function maximum(array $durations): float
    {
        if (count($durations) === 0) {
            throw new InvalidArgumentException('Cannot calculate maximum of empty array');
        }

        return (float) max($durations);
    }

    /**
     * Calculates the arithmetic mean of an array of durations.
     *
     * @param array<int|float> $durations
     * @return float The mean (average)
     * @throws InvalidArgumentException If the array is empty
     */
    public static function mean(array $durations): float
    {
        if (count($durations) === 0) {
            throw new InvalidArgumentException('Cannot calculate mean of empty array');
        }

        return (float) (array_sum($durations) / count($durations));
    }

    /**
     * Calculates the median (50th percentile) of an array of durations.
     *
     * MEDIAN CALCULATION METHOD:
     * - For odd-length arrays: the middle value after sorting
     * - For even-length arrays: the average of the two middle values (interpolation)
     *
     * This is the most common definition of median in statistics and provides
     * stability across arrays with slightly different lengths.
     *
     * Example:
     *   [1, 2, 3, 4, 5] → median = 3
     *   [1, 2, 3, 4]    → median = (2 + 3) / 2 = 2.5
     *
     * @param array<int|float> $durations
     * @return float The median value
     * @throws InvalidArgumentException If the array is empty
     */
    public static function median(array $durations): float
    {
        if (count($durations) === 0) {
            throw new InvalidArgumentException('Cannot calculate median of empty array');
        }

        $sorted = $durations;
        sort($sorted);
        $count = count($sorted);

        if ($count % 2 === 1) {
            // Odd-length array: return middle element
            return (float) $sorted[intdiv($count, 2)];
        }

        // Even-length array: interpolate between two middle elements
        $midPoint1 = $sorted[($count / 2) - 1];
        $midPoint2 = $sorted[($count / 2)];

        return ($midPoint1 + $midPoint2) / 2.0;
    }

    /**
     * Calculates the 95th percentile (p95) of an array of durations.
     *
     * P95 CALCULATION METHOD: Nearest-rank method (also called "lower inclusive" method)
     * - Calculate rank = ceil(0.95 * n), where n is the number of observations
     * - Return the value at that rank in the sorted array (1-indexed, converted to 0-indexed)
     *
     * This is a simple, deterministic method suitable for performance benchmarking.
     * It always returns an actual value from the dataset (no interpolation).
     *
     * Example:
     *   10 samples: rank = ceil(0.95 * 10) = ceil(9.5) = 10 → 10th element (index 9)
     *   100 samples: rank = ceil(0.95 * 100) = ceil(95) = 95 → 95th element (index 94)
     *   7 samples: rank = ceil(0.95 * 7) = ceil(6.65) = 7 → 7th element (index 6)
     *
     * @param array<int|float> $durations
     * @return float The 95th percentile value
     * @throws InvalidArgumentException If the array is empty
     */
    public static function p95(array $durations): float
    {
        if (count($durations) === 0) {
            throw new InvalidArgumentException('Cannot calculate p95 of empty array');
        }

        $sorted = $durations;
        sort($sorted);
        $count = count($sorted);

        // Nearest-rank method: ceil(0.95 * n)
        $rank = (int) ceil(0.95 * $count);

        // Convert to 0-indexed array offset (rank 1 → index 0)
        $index = $rank - 1;

        return (float) $sorted[$index];
    }

    /**
     * Calculates the standard deviation of an array of durations.
     *
     * STANDARD DEVIATION METHOD: Sample standard deviation (using n-1 divisor)
     * - Formula: sqrt(sum((x - mean)²) / (n - 1))
     * - Uses Bessel's correction (dividing by n-1 instead of n)
     *
     * This is the appropriate choice for benchmark measurements because:
     * 1. We typically measure a sample of execution times, not the entire population
     * 2. Sample SD (with n-1) provides an unbiased estimator of population variance
     * 3. It's the default in most statistical tools and benchmarking frameworks
     *
     * Special case: Single observation (n=1) returns 0.0, since there is no variance
     * to estimate from a single data point (denominator would be 0, returning infinity
     * would be undefined; 0 is a more practical choice for benchmarking).
     *
     * @param array<int|float> $durations
     * @return float The sample standard deviation
     * @throws InvalidArgumentException If the array is empty
     */
    public static function standardDeviation(array $durations): float
    {
        if (count($durations) === 0) {
            throw new InvalidArgumentException('Cannot calculate standard deviation of empty array');
        }

        $count = count($durations);

        // Single observation: no variance to estimate
        if ($count === 1) {
            return 0.0;
        }

        $mean = self::mean($durations);

        // Calculate sum of squared deviations
        $sumSquaredDeviations = 0.0;
        foreach ($durations as $value) {
            $deviation = $value - $mean;
            $sumSquaredDeviations += $deviation * $deviation;
        }

        // Sample variance (using n-1)
        $sampleVariance = $sumSquaredDeviations / ($count - 1);

        return sqrt($sampleVariance);
    }
}
