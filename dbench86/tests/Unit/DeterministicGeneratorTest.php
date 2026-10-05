<?php
declare(strict_types=1);

namespace Tests\Unit;

use DBench86\Dataset\DeterministicGenerator;
use Tests\TestCase;

/**
 * Unit tests for DeterministicGenerator.
 *
 * Verifies reproducible pseudorandom number generation across:
 * - Determinism: same seed produces identical sequences
 * - Divergence: different seeds produce different sequences
 * - Range correctness: values stay within specified bounds
 * - Distribution uniformity: statistical properties (chi-square test)
 * - All methods: nextInt, nextFloat, nextString, nextChoice
 */
final class DeterministicGeneratorTest extends TestCase
{
    public function run(): void
    {
        // Determinism and reproducibility tests
        $this->testSameSeedProducesSameSequenceOver10000Iterations();
        $this->testDifferentSeedsProduceDifferentSequences();
        $this->testSameSeedProducesSameSequenceAcrossMultipleMethods();

        // nextInt() tests
        $this->testNextIntReturnsValueInRange();
        $this->testNextIntThrowsOnInvalidRange();
        $this->testNextIntWithSingleValue();
        $this->testNextIntDistributionAcrossRanges();

        // nextFloat() tests
        $this->testNextFloatReturnsValueInRange();
        $this->testNextFloatThrowsOnInvalidRange();
        $this->testNextFloatDistribution();

        // nextString() tests
        $this->testNextStringReturnsCorrectLength();
        $this->testNextStringThrowsOnNegativeLength();
        $this->testNextStringUsesAlphanumericCharacters();
        $this->testNextStringWithZeroLength();
        $this->testNextStringDeterminism();

        // nextChoice() tests
        $this->testNextChoiceReturnsArrayElement();
        $this->testNextChoiceThrowsOnEmptyArray();
        $this->testNextChoiceDistributionAcrossOptions();
        $this->testNextChoiceDeterminism();

        // Statistical properties
        $this->testGeneratorHasGoodDistributionProperties();
    }

    private function testSameSeedProducesSameSequenceOver10000Iterations(): void
    {
        $seed = 42;
        $gen1 = new DeterministicGenerator($seed);
        $gen2 = new DeterministicGenerator($seed);

        $sequence1 = [];
        $sequence2 = [];

        for ($i = 0; $i < 10_000; $i++) {
            $sequence1[] = $gen1->nextInt(0, 1_000_000);
            $sequence2[] = $gen2->nextInt(0, 1_000_000);
        }

        $this->assertTrue(
            $sequence1 === $sequence2,
            'Same seed must produce identical sequences over 10,000 iterations'
        );
    }

    private function testDifferentSeedsProduceDifferentSequences(): void
    {
        $gen1 = new DeterministicGenerator(100);
        $gen2 = new DeterministicGenerator(200);

        $sequence1 = [];
        $sequence2 = [];

        for ($i = 0; $i < 1_000; $i++) {
            $sequence1[] = $gen1->nextInt(0, 1_000_000);
            $sequence2[] = $gen2->nextInt(0, 1_000_000);
        }

        // Sequences must differ (with overwhelming probability)
        $differences = 0;
        for ($i = 0; $i < count($sequence1); $i++) {
            if ($sequence1[$i] !== $sequence2[$i]) {
                $differences++;
            }
        }

        $this->assertTrue(
            $differences > 900,  // Expect >90% of values to differ
            'Different seeds must produce significantly different sequences'
        );
    }

    private function testSameSeedProducesSameSequenceAcrossMultipleMethods(): void
    {
        $seed = 999;
        $gen1 = new DeterministicGenerator($seed);
        $gen2 = new DeterministicGenerator($seed);

        $sequence1 = [];
        for ($i = 0; $i < 100; $i++) {
            $sequence1[] = $gen1->nextInt(1, 100);
            $sequence1[] = $gen1->nextFloat(0.0, 1.0);
            $sequence1[] = $gen1->nextString(5);
            $sequence1[] = $gen1->nextChoice(['a', 'b', 'c']);
        }

        $sequence2 = [];
        for ($i = 0; $i < 100; $i++) {
            $sequence2[] = $gen2->nextInt(1, 100);
            $sequence2[] = $gen2->nextFloat(0.0, 1.0);
            $sequence2[] = $gen2->nextString(5);
            $sequence2[] = $gen2->nextChoice(['a', 'b', 'c']);
        }

        $this->assertTrue(
            $sequence1 === $sequence2,
            'Same seed must produce same sequence across multiple method types'
        );
    }

    private function testNextIntReturnsValueInRange(): void
    {
        $gen = new DeterministicGenerator(1);

        for ($i = 0; $i < 1_000; $i++) {
            $value = $gen->nextInt(10, 20);
            $this->assertTrue(
                $value >= 10 && $value <= 20,
                "nextInt(10, 20) must return value in [10, 20], got {$value}"
            );
        }
    }

    private function testNextIntThrowsOnInvalidRange(): void
    {
        $gen = new DeterministicGenerator(1);

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => $gen->nextInt(100, 50),
            'nextInt should throw InvalidArgumentException when min > max'
        );
    }

    private function testNextIntWithSingleValue(): void
    {
        $gen = new DeterministicGenerator(1);

        $value = $gen->nextInt(42, 42);
        $this->assertTrue(
            $value === 42,
            'nextInt(n, n) must always return n'
        );
    }

    private function testNextIntDistributionAcrossRanges(): void
    {
        $gen = new DeterministicGenerator(777);
        $counts = [];

        for ($i = 0; $i < 10_000; $i++) {
            $value = $gen->nextInt(1, 4);
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        // Each value should appear roughly equally often (~2500 times)
        foreach ([1, 2, 3, 4] as $value) {
            $this->assertTrue(
                isset($counts[$value]) && $counts[$value] > 2000 && $counts[$value] < 3000,
                "Value {$value} distribution should be roughly uniform, got {$counts[$value]} occurrences"
            );
        }
    }

    private function testNextFloatReturnsValueInRange(): void
    {
        $gen = new DeterministicGenerator(1);

        for ($i = 0; $i < 1_000; $i++) {
            $value = $gen->nextFloat(0.0, 1.0);
            $this->assertTrue(
                $value >= 0.0 && $value < 1.0,
                "nextFloat(0.0, 1.0) must return value in [0.0, 1.0), got {$value}"
            );
        }
    }

    private function testNextFloatThrowsOnInvalidRange(): void
    {
        $gen = new DeterministicGenerator(1);

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => $gen->nextFloat(1.0, 0.5),
            'nextFloat should throw InvalidArgumentException when min >= max'
        );

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => $gen->nextFloat(1.0, 1.0),
            'nextFloat should throw InvalidArgumentException when min == max'
        );
    }

    private function testNextFloatDistribution(): void
    {
        $gen = new DeterministicGenerator(555);

        $belowHalf = 0;
        $aboveHalf = 0;

        for ($i = 0; $i < 10_000; $i++) {
            $value = $gen->nextFloat(0.0, 1.0);
            if ($value < 0.5) {
                $belowHalf++;
            } else {
                $aboveHalf++;
            }
        }

        // Should be roughly split 50/50
        $this->assertTrue(
            $belowHalf > 4000 && $belowHalf < 6000,
            "nextFloat should produce roughly uniform distribution, got {$belowHalf} below 0.5"
        );
    }

    private function testNextStringReturnsCorrectLength(): void
    {
        $gen = new DeterministicGenerator(1);

        for ($length = 0; $length <= 100; $length += 10) {
            $string = $gen->nextString($length);
            $this->assertTrue(
                strlen($string) === $length,
                "nextString({$length}) must return string of exactly {$length} chars, got " . strlen($string)
            );
        }
    }

    private function testNextStringThrowsOnNegativeLength(): void
    {
        $gen = new DeterministicGenerator(1);

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => $gen->nextString(-5),
            'nextString should throw InvalidArgumentException for negative length'
        );
    }

    private function testNextStringUsesAlphanumericCharacters(): void
    {
        $gen = new DeterministicGenerator(111);

        for ($i = 0; $i < 100; $i++) {
            $string = $gen->nextString(20);
            $this->assertTrue(
                preg_match('/^[a-zA-Z0-9]+$/', $string) === 1,
                "nextString must use only alphanumeric characters, got: {$string}"
            );
        }
    }

    private function testNextStringWithZeroLength(): void
    {
        $gen = new DeterministicGenerator(1);

        $string = $gen->nextString(0);
        $this->assertTrue(
            $string === '',
            'nextString(0) must return empty string'
        );
    }

    private function testNextStringDeterminism(): void
    {
        $gen1 = new DeterministicGenerator(6666);
        $gen2 = new DeterministicGenerator(6666);

        $strings1 = [];
        $strings2 = [];

        for ($i = 0; $i < 500; $i++) {
            $strings1[] = $gen1->nextString(10);
            $strings2[] = $gen2->nextString(10);
        }

        $this->assertTrue(
            $strings1 === $strings2,
            'nextString sequences must be identical for same seed'
        );
    }

    private function testNextChoiceReturnsArrayElement(): void
    {
        $options = ['apple', 'banana', 'cherry', 'date'];
        $gen = new DeterministicGenerator(1);

        for ($i = 0; $i < 1_000; $i++) {
            $choice = $gen->nextChoice($options);
            $this->assertTrue(
                in_array($choice, $options, true),
                "nextChoice must return one of the provided options, got: {$choice}"
            );
        }
    }

    private function testNextChoiceThrowsOnEmptyArray(): void
    {
        $gen = new DeterministicGenerator(1);

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => $gen->nextChoice([]),
            'nextChoice should throw InvalidArgumentException for empty array'
        );
    }

    private function testNextChoiceDistributionAcrossOptions(): void
    {
        $options = ['a', 'b', 'c', 'd'];
        $gen = new DeterministicGenerator(8888);
        $counts = [];

        for ($i = 0; $i < 10_000; $i++) {
            $choice = $gen->nextChoice($options);
            $counts[$choice] = ($counts[$choice] ?? 0) + 1;
        }

        // Each option should appear roughly equally often (~2500 times)
        foreach ($options as $option) {
            $this->assertTrue(
                isset($counts[$option]) && $counts[$option] > 2000 && $counts[$option] < 3000,
                "Option '{$option}' distribution should be roughly uniform, got {$counts[$option]} occurrences"
            );
        }
    }

    private function testNextChoiceDeterminism(): void
    {
        $options = ['alpha', 'beta', 'gamma', 'delta'];
        $gen1 = new DeterministicGenerator(4444);
        $gen2 = new DeterministicGenerator(4444);

        $choices1 = [];
        $choices2 = [];

        for ($i = 0; $i < 500; $i++) {
            $choices1[] = $gen1->nextChoice($options);
            $choices2[] = $gen2->nextChoice($options);
        }

        $this->assertTrue(
            $choices1 === $choices2,
            'nextChoice sequences must be identical for same seed'
        );
    }

    private function testGeneratorHasGoodDistributionProperties(): void
    {
        $gen = new DeterministicGenerator(99999);

        // Test chi-square statistic for uniform distribution over 10 bins
        $bins = array_fill(0, 10, 0);
        $samples = 10_000;

        for ($i = 0; $i < $samples; $i++) {
            $value = $gen->nextFloat(0.0, 1.0);
            $bin = intval($value * 10) % 10;
            $bins[$bin]++;
        }

        $expected = $samples / 10;
        $chiSquare = 0.0;

        foreach ($bins as $observed) {
            $chiSquare += (($observed - $expected) ** 2) / $expected;
        }

        // Chi-square for 10 bins at 0.05 significance: critical value ~18.3
        // We use a more lenient threshold (25) for practical testing
        $this->assertTrue(
            $chiSquare < 25,
            "Distribution should pass chi-square test, got chi-square={$chiSquare}"
        );
    }
}
