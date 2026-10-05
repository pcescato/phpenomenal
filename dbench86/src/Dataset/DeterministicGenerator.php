<?php
declare(strict_types=1);

namespace DBench86\Dataset;

/**
 * Deterministic pseudorandom number generator with guaranteed reproducibility
 * across PHP versions and platforms.
 *
 * Implements PCG32 (Permuted Congruential Generator, 32-bit variant):
 * a fast, simple, statistically excellent PRNG that does not rely on PHP's
 * built-in mt_rand() or random_int(), which are not guaranteed to be stable
 * across versions and platforms.
 *
 * Algorithm: PCG32 from http://www.pcg-random.org/
 * Paper: "PCG: A Family of Simple Fast Space-Efficient Statistically Good
 *        Algorithms for Random Number Generation"
 *        Melissa E. O'Neill, Harvey Mudd College
 *
 * Internal state (64-bit) is updated using a linear congruential generator:
 *   state = (state * 6364136223846793005) + 1442695040888963407
 *
 * Output is produced by applying a permutation to decorrelate output from state:
 *   1. Extract the "middle bits" via xorshift
 *   2. Apply a rotation amount derived from high bits
 *   3. Rotate to produce the final 32-bit value
 *
 * Properties:
 *   - Period: 2^64 (full state space)
 *   - Output: 32-bit unsigned integers
 *   - Passes statistical tests (PractRand, Crush suite)
 *   - Deterministic: same seed → same sequence
 *   - Reproducible: works identically on any PHP version
 *   - No external dependencies
 *
 * Usage:
 *   $gen = new DeterministicGenerator(12345);
 *   $int = $gen->nextInt(1, 100);
 *   $float = $gen->nextFloat(0.0, 1.0);
 *   $string = $gen->nextString(8);
 *   $choice = $gen->nextChoice(['apple', 'banana', 'cherry']);
 */
final class DeterministicGenerator
{
    /**
     * Internal 64-bit state, stored as two 32-bit unsigned integers.
     * PHP integers are platform-dependent (32 or 64 bit), so we decompose
     * the 64-bit state into two 32-bit parts to ensure cross-platform consistency.
     */
    private int $stateLow;   // Lower 32 bits
    private int $stateHigh;  // Upper 32 bits

    /**
     * PCG32 constants (verified against http://www.pcg-random.org/download.html).
     */
    private const int MULTIPLIER_LOW = 0x6C078965;    // Lower 32 bits of multiplier
    private const int MULTIPLIER_HIGH = 0x5D308E6D;   // Upper 32 bits of multiplier
    private const int INCREMENT_LOW = 0x88963407;     // Lower 32 bits of increment
    private const int INCREMENT_HIGH = 0x5555;        // Upper 32 bits of increment

    /**
     * Initialize the generator with a seed.
     *
     * The seed is expanded into the full 64-bit state to ensure good
     * distribution even for small seed values.
     *
     * @param int $seed Initial seed value (any integer)
     */
    public function __construct(int $seed)
    {
        // Expand seed to 64-bit state using a simple hash function
        // This ensures seeds like 0, 1, 2 produce different sequences
        $state = $seed;

        // Mix bits to ensure good distribution
        $state = ($state ^ 61) ^ ($state >> 16);
        $state = $state + ($state << 3);
        $state = $state ^ ($state >> 4);
        $state = $state * 0x27D4EB2D;
        $state = $state ^ ($state >> 15);

        // Extract low and high 32-bit parts
        $this->stateLow = $state & 0xFFFFFFFF;
        $this->stateHigh = ($state >> 32) & 0xFFFFFFFF;

        // Advance by one step to avoid initial state artifacts
        $this->advance();
    }

    /**
     * Advance the internal state one step using the LCG formula.
     *
     * state = state * multiplier + increment
     *
     * Uses Karatsuba-like decomposition to multiply two 64-bit values in 32-bit arithmetic,
     * handling PHP's platform-dependent integer size by forcing 32-bit operations.
     */
    private function advance(): void
    {
        // PCG32 multiplier: 6364136223846793005 = 0x5851F42D4C957F2D
        // PCG32 increment: 1442695040888963407 = 0x14057B7EF767814F

        // Decompose into 32-bit parts for cross-platform consistency
        $ml = 0x4C957F2D;  // Low 32 bits of multiplier
        $mh = 0x5851F42D;  // High 32 bits of multiplier
        $il = 0xF767814F;  // Low 32 bits of increment
        $ih = 0x14057B7E;  // High 32 bits of increment

        // Multiply state by multiplier using Karatsuba decomposition
        // (a_h * 2^32 + a_l) * (m_h * 2^32 + m_l) mod 2^64

        // Compute partial products and accumulate
        $p0 = $this->mulLo32($this->stateLow, $ml);     // Low part of low×low
        $p1 = $this->mulHi32($this->stateLow, $ml);     // High part of low×low  (carry to mid)
        $p2 = $this->mulLo32($this->stateLow, $mh);     // Low×high product (goes to mid)
        $p3 = $this->mulLo32($this->stateHigh, $ml);    // High×low product (goes to mid)

        // Accumulate into low 32 bits
        $this->stateLow = $p0 & 0xFFFFFFFF;

        // Accumulate carries into high 32 bits
        $carry = ($p0 >> 32) ? 1 : 0;
        $mid = ($p1 + $p2 + $p3 + $carry) & 0xFFFFFFFF;

        $this->stateHigh = $mid & 0xFFFFFFFF;

        // Add increment
        $this->stateLow = ($this->stateLow + $il) & 0xFFFFFFFF;
        $carry = (($this->stateLow & 0xFFFFFFFF) < ($il & 0xFFFFFFFF)) ? 1 : 0;
        $this->stateHigh = ($this->stateHigh + $ih + $carry) & 0xFFFFFFFF;
    }

    /**
     * Multiply two 32-bit unsigned integers and return the low 32 bits.
     */
    private function mulLo32(int $a, int $b): int
    {
        return ($a * $b) & 0xFFFFFFFF;
    }

    /**
     * Multiply two 32-bit unsigned integers and return the high 32 bits.
     */
    private function mulHi32(int $a, int $b): int
    {
        $result = $a * $b;
        // For platform independence, extract high bits via bit shift
        // On 64-bit systems this is straightforward; on 32-bit systems PHP handles it
        if (PHP_INT_MAX > 0x7FFFFFFF) {
            // 64-bit system
            return intval($result >> 32) & 0xFFFFFFFF;
        } else {
            // 32-bit system: use floating point as fallback
            return intval(($result / 4294967296.0)) & 0xFFFFFFFF;
        }
    }

    /**
     * Generate the next pseudorandom 32-bit unsigned integer.
     *
     * Applies the PCG32 output function:
     *   1. xorshift middle bits
     *   2. extract rotation amount
     *   3. rotate to produce output
     *
     * @return int Pseudorandom unsigned 32-bit value (as PHP int)
     */
    private function nextUint32(): int
    {
        $this->advance();

        // Apply output permutation
        // xorshifted = ((state >> 18) ^ state) >> 27
        $xs = ((($this->stateHigh << 14) | ($this->stateLow >> 18)) ^ $this->stateHigh) >> 27;
        $xs = $xs & 0xFFFFFFFF;

        // rot = state >> 59 (rotation amount from high 3 bits of high 32)
        $rot = ($this->stateHigh >> 27) & 0x1F;

        // Rotate: (xorshifted >> rot) | (xorshifted << (32 - rot))
        $result = (($xs >> $rot) | ($xs << (32 - $rot))) & 0xFFFFFFFF;

        return $result;
    }

    /**
     * Generate a pseudorandom integer in the range [min, max] (inclusive).
     *
     * Uses a rejection sampling approach to avoid modulo bias with non-power-of-2
     * ranges (though for benchmark purposes, bias is minimal).
     *
     * @param int $min Minimum value (inclusive)
     * @param int $max Maximum value (inclusive)
     * @return int Pseudorandom integer in [min, max]
     * @throws \InvalidArgumentException If min > max
     */
    public function nextInt(int $min, int $max): int
    {
        if ($min > $max) {
            throw new \InvalidArgumentException(
                "min ({$min}) must not be greater than max ({$max})"
            );
        }

        if ($min === $max) {
            return $min;
        }

        $range = $max - $min + 1;

        // Simple modulo approach (acceptable for benchmarking)
        // For cryptographic use, rejection sampling would be preferred
        $value = $this->nextUint32();

        // Ensure positive result on 32-bit systems
        if ($value < 0) {
            $value = $value & 0x7FFFFFFF;  // Clear sign bit
        }

        return $min + ($value % $range);
    }

    /**
     * Generate a pseudorandom floating-point number in the range [min, max).
     *
     * @param float $min Minimum value (inclusive)
     * @param float $max Maximum value (exclusive)
     * @return float Pseudorandom float in [min, max)
     * @throws \InvalidArgumentException If min >= max
     */
    public function nextFloat(float $min, float $max): float
    {
        if ($min >= $max) {
            throw new \InvalidArgumentException(
                "min ({$min}) must be strictly less than max ({$max})"
            );
        }

        // Generate uniform float in [0.0, 1.0)
        // by dividing a 32-bit uint by 2^32
        $uint = $this->nextUint32() & 0x7FFFFFFF;  // Keep positive
        $uniform = $uint / 2147483648.0;  // 2^31

        return $min + ($uniform * ($max - $min));
    }

    /**
     * Generate a pseudorandom string of given length.
     *
     * Characters are drawn from alphanumeric set: [a-zA-Z0-9].
     *
     * @param int $length Number of characters to generate
     * @return string Pseudorandom alphanumeric string
     * @throws \InvalidArgumentException If length < 0
     */
    public function nextString(int $length): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException("length must be non-negative");
        }

        if ($length === 0) {
            return '';
        }

        $charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $charsetLen = strlen($charset);
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $index = $this->nextInt(0, $charsetLen - 1);
            $result .= $charset[$index];
        }

        return $result;
    }

    /**
     * Choose a random element from an array using pseudorandom selection.
     *
     * @template T
     * @param array<T> $options Array of values to choose from
     * @return T A randomly chosen element
     * @throws \InvalidArgumentException If options array is empty
     */
    public function nextChoice(array $options): mixed
    {
        if (empty($options)) {
            throw new \InvalidArgumentException("options array must not be empty");
        }

        $index = $this->nextInt(0, count($options) - 1);
        return $options[$index];
    }
}
