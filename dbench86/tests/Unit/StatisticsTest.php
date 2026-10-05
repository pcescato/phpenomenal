<?php

declare(strict_types=1);

namespace Tests\Unit;

use DBench86\Statistics\Statistics;
use Tests\TestCase;

final class StatisticsTest extends TestCase
{
    public function run(): void
    {
        $this->testMinimumWithTypicalData();
        $this->testMinimumWithSingleElement();
        $this->testMinimumThrowsOnEmptyArray();
        $this->testMaximumWithTypicalData();
        $this->testMaximumThrowsOnEmptyArray();
        $this->testMeanWithOddLengthArray();
        $this->testMeanWithEvenLengthArray();
        $this->testMeanWithSingleElement();
        $this->testMeanThrowsOnEmptyArray();
        $this->testMedianWithOddLengthArray();
        $this->testMedianWithOddLengthArrayUnsorted();
        $this->testMedianWithEvenLengthArray();
        $this->testMedianWithEvenLengthArrayUnsorted();
        $this->testMedianWithTwoElements();
        $this->testMedianWithSingleElement();
        $this->testMedianThrowsOnEmptyArray();
        $this->testP95WithTenElements();
        $this->testP95WithHundredElements();
        $this->testP95WithSevenElements();
        $this->testP95WithUnsortedData();
        $this->testP95WithSingleElement();
        $this->testP95ThrowsOnEmptyArray();
        $this->testStandardDeviationWithFiveElements();
        $this->testStandardDeviationWithIdenticalValues();
        $this->testStandardDeviationWithSingleElement();
        $this->testStandardDeviationWithTwoElements();
        $this->testStandardDeviationThrowsOnEmptyArray();
        $this->testStandardDeviationUsesSampleVariance();
        $this->testP95WithSmallDatasets();
    }

    public function testMinimumWithTypicalData(): void
    {
        $durations = [5.0, 1.0, 3.0, 2.0, 4.0];
        $this->assertEquals(1.0, Statistics::minimum($durations));
    }

    public function testMinimumWithSingleElement(): void
    {
        $durations = [42.0];
        $this->assertEquals(42.0, Statistics::minimum($durations));
    }

    public function testMinimumThrowsOnEmptyArray(): void
    {
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => Statistics::minimum([])
        );
    }

    public function testMaximumWithTypicalData(): void
    {
        $durations = [5.0, 1.0, 3.0, 2.0, 4.0];
        $this->assertEquals(5.0, Statistics::maximum($durations));
    }

    public function testMaximumThrowsOnEmptyArray(): void
    {
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => Statistics::maximum([])
        );
    }

    public function testMeanWithOddLengthArray(): void
    {
        $durations = [1.0, 2.0, 3.0, 4.0, 5.0];
        $this->assertEquals(3.0, Statistics::mean($durations));
    }

    public function testMeanWithEvenLengthArray(): void
    {
        $durations = [1.0, 2.0, 3.0, 4.0];
        $this->assertEquals(2.5, Statistics::mean($durations));
    }

    public function testMeanWithSingleElement(): void
    {
        $durations = [7.5];
        $this->assertEquals(7.5, Statistics::mean($durations));
    }

    public function testMeanThrowsOnEmptyArray(): void
    {
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => Statistics::mean([])
        );
    }

    public function testMedianWithOddLengthArray(): void
    {
        $durations = [1.0, 2.0, 3.0, 4.0, 5.0];
        $this->assertEquals(3.0, Statistics::median($durations));
    }

    public function testMedianWithOddLengthArrayUnsorted(): void
    {
        $durations = [5.0, 1.0, 3.0, 2.0, 4.0];
        $this->assertEquals(3.0, Statistics::median($durations));
    }

    public function testMedianWithEvenLengthArray(): void
    {
        $durations = [1.0, 2.0, 3.0, 4.0];
        $this->assertEquals(2.5, Statistics::median($durations));
    }

    public function testMedianWithEvenLengthArrayUnsorted(): void
    {
        $durations = [4.0, 1.0, 3.0, 2.0];
        $this->assertEquals(2.5, Statistics::median($durations));
    }

    public function testMedianWithTwoElements(): void
    {
        $durations = [10.0, 20.0];
        $this->assertEquals(15.0, Statistics::median($durations));
    }

    public function testMedianWithSingleElement(): void
    {
        $durations = [42.0];
        $this->assertEquals(42.0, Statistics::median($durations));
    }

    public function testMedianThrowsOnEmptyArray(): void
    {
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => Statistics::median([])
        );
    }

    public function testP95WithTenElements(): void
    {
        $durations = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0];
        $this->assertEquals(10.0, Statistics::p95($durations));
    }

    public function testP95WithHundredElements(): void
    {
        $durations = array_map(fn($i) => (float) $i, range(1, 100));
        $this->assertEquals(95.0, Statistics::p95($durations));
    }

    public function testP95WithSevenElements(): void
    {
        $durations = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0];
        $this->assertEquals(7.0, Statistics::p95($durations));
    }

    public function testP95WithUnsortedData(): void
    {
        $durations = [10.0, 5.0, 3.0, 8.0, 1.0, 9.0, 2.0, 7.0, 4.0, 6.0];
        $this->assertEquals(10.0, Statistics::p95($durations));
    }

    public function testP95WithSingleElement(): void
    {
        $durations = [42.0];
        $this->assertEquals(42.0, Statistics::p95($durations));
    }

    public function testP95ThrowsOnEmptyArray(): void
    {
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => Statistics::p95([])
        );
    }

    public function testStandardDeviationWithFiveElements(): void
    {
        $durations = [1.0, 2.0, 3.0, 4.0, 5.0];
        $expected = sqrt(2.5);
        $this->assertApproximately($expected, Statistics::standardDeviation($durations), 1e-9);
    }

    public function testStandardDeviationWithIdenticalValues(): void
    {
        $durations = [5.0, 5.0, 5.0, 5.0];
        $this->assertEquals(0.0, Statistics::standardDeviation($durations));
    }

    public function testStandardDeviationWithSingleElement(): void
    {
        $durations = [42.0];
        $this->assertEquals(0.0, Statistics::standardDeviation($durations));
    }

    public function testStandardDeviationWithTwoElements(): void
    {
        $durations = [1.0, 3.0];
        $expected = sqrt(2.0);
        $this->assertApproximately($expected, Statistics::standardDeviation($durations), 1e-9);
    }

    public function testStandardDeviationThrowsOnEmptyArray(): void
    {
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => Statistics::standardDeviation([])
        );
    }

    public function testStandardDeviationUsesSampleVariance(): void
    {
        $durations = [10.0, 20.0];
        $result = Statistics::standardDeviation($durations);

        $sampleSD = sqrt(50.0);
        $this->assertApproximately($sampleSD, $result, 1e-9);
    }

    public function testP95WithSmallDatasets(): void
    {
        $result1 = Statistics::p95([50.0]);
        $this->assertEquals(50.0, $result1);

        $result2 = Statistics::p95([10.0, 20.0]);
        $this->assertEquals(20.0, $result2);

        $result3 = Statistics::p95([10.0, 20.0, 30.0]);
        $this->assertEquals(30.0, $result3);
    }
}

$statisticsTest = new StatisticsTest();
$statisticsTest->run();
