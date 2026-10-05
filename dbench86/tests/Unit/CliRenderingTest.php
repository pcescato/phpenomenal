<?php
declare(strict_types=1);

namespace Tests\Unit;

use DBench86\Application;
use DBench86\Benchmark\{WorkloadMeasurement, WorkloadResult};
use SortDirection;
use Tests\TestCase;

final class CliRenderingTest extends TestCase
{
    public function run(): void
    {
        $normal = $this->fixture([1, 3, 5], 3, 2);
        $text = $this->render(['pk_select' => $normal, 'indexed_select' => clone $normal], 3, 2);
        $this->assertTrue(str_contains($text, 'Results: 3 measured runs + 2 warmups per workload'));
        $this->assertTrue(str_contains($text, 'successful measured runs only; warmups excluded.'));
        $this->assertEquals(1, substr_count($text, 'Warmup:'));
        $this->assertTrue(str_contains($text, 'Warmup: all workloads completed successfully (2/2).'));
        $this->assertTrue(!str_contains($text, 'Warmup issues:') && !str_contains($text, 'performed='));
        $this->assertTrue(!str_contains($text, ' !') && !str_contains($text, 'Requested') && !str_contains($text, 'Timed out'));
        $this->assertRow($text, 'pk_select', '3/3', ['1.000', '3.000', '5.000', '5.000']);
        foreach (explode("\n", $text) as $line) {
            $this->assertTrue(strlen($line) <= 80, 'Normal output fits an 80-column terminal');
        }

        $failed = $this->fixture([2, 4], 3);
        $failed->record(new WorkloadMeasurement(99_000_000, 0, false, true));
        $timeout = $this->fixture([2, 4], 3);
        $timeout->record(new WorkloadMeasurement(88_000_000, 1, true));
        // Model an interrupted workload without changing the recorded executions.
        $short = $this->fixture([2, 4], 4);
        $short->record(new WorkloadMeasurement(99_000_000, 0, false, true));
        $empty = $this->fixture([], 2);
        $empty->record(new WorkloadMeasurement(99_000_000, 0, false, true));
        $empty->record(new WorkloadMeasurement(88_000_000, 1, true));
        $text = $this->render(['failure' => $failed, 'timeout' => $timeout, 'interrupted' => $short, 'empty' => $empty], 3);
        $this->assertRow($text, 'failure', '2/3', ['2.000', '3.000', '4.000', '4.000'], true);
        $this->assertRow($text, 'timeout', '2/3', ['2.000', '3.000', '4.000', '4.000'], true);
        $this->assertTrue(str_contains($text, '2 successful | 1 failed | 0 timed out'));
        $this->assertTrue(str_contains($text, '2 successful | 0 failed | 1 timed out'));
        $this->assertTrue(str_contains($text, 'requested=4 | performed=3 | successful=2 | failed=1 | timed out=0'));
        $this->assertRow($text, 'empty', '0/2', ['N/A', 'N/A', 'N/A', 'N/A'], true);
        $this->assertTrue(str_contains($text, '0 successful | 1 failed | 1 timed out'));

        $warmupFailure = $this->fixture([2], 1, 1);
        $warmupFailure->record(new WorkloadMeasurement(99_000_000, 0, false, true), true);
        $warmupTimeout = $this->fixture([2], 1, 1);
        $warmupTimeout->record(new WorkloadMeasurement(88_000_000, 1, true), true);
        $incomplete = $this->fixture([2], 1, 1);
        $normal = $this->fixture([2], 1, 2);
        $text = $this->render(['normal' => $normal, 'warmup_failure' => $warmupFailure, 'warmup_timeout' => $warmupTimeout, 'incomplete' => $incomplete], 1, 2);
        $this->assertEquals(1, substr_count($text, 'Warmup issues:'));
        $this->assertTrue(!str_contains($text, 'all workloads completed successfully') && !str_contains($text, '  normal:'));
        $this->assertTrue(str_contains($text, 'warmup_failure: 1/2 successful | 1 failed | 0 timed out'));
        $this->assertTrue(str_contains($text, 'warmup_timeout: 1/2 successful | 0 failed | 1 timed out'));
        $this->assertTrue(str_contains($text, 'incomplete: 1/2 successful | 0 failed | 0 timed out | requested=2 | performed=1'));
        $this->assertRow($text, 'warmup_timeout', '1/1', ['2.000', '2.000', '2.000', '2.000']);

        $cleanup = $this->fixture([2], 1);
        $cleanup->errors[] = 'cleanup: fixture error';
        $text = $this->render(['cleanup_error' => $cleanup], 1);
        $this->assertRow($text, 'cleanup_error', '1/1', ['2.000', '2.000', '2.000', '2.000'], true);
        $this->assertTrue(str_contains($text, '[FAILED] cleanup: fixture error'));
        $this->assertTrue(str_contains($this->render(['normal' => $this->fixture([2], 1)], 1), 'Warmup: none requested.'));

        $method = new \ReflectionMethod(Application::class, 'displayBatchSize');
        foreach ([[100, 100, "Batch size: 100 rows\n\n"], [100, 64, "Batch size: 100 requested -> 64 effective\n\n"]] as [$requested, $effective, $expected]) {
            ob_start();
            try {
                $method->invoke(new Application(), $requested, $effective);
                $this->assertEquals($expected, ob_get_contents());
            } finally {
                ob_end_clean();
            }
        }
    }

    private function fixture(array $samples, int $requested, int $warmup = 0): WorkloadResult
    {
        $result = new WorkloadResult($requested);
        foreach ($samples as $ms) {
            $result->record(new WorkloadMeasurement($ms * 1_000_000, 1));
        }
        for ($i = 0; $i < $warmup; $i++) {
            $result->record(new WorkloadMeasurement(77_000_000, 1), true);
        }
        return $result;
    }

    private function render(array $results, int $runs, int $warmup = 0): string
    {
        $before = serialize($results);
        ob_start();
        try {
            $failed = (new \ReflectionMethod(Application::class, 'displayResults'))->invoke(
                new Application(), 'sqlite', $results, $runs, 'median', SortDirection::Ascending, $warmup);
            $text = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $expectedFailure = false;
        foreach ($results as $result) {
            $expectedFailure = $expectedFailure || $result->hasFailures();
        }
        $this->assertEquals($expectedFailure, $failed, 'Rendering preserves existing failure status');
        $this->assertEquals($before, serialize($results), 'Rendering leaves all counters and latency samples unchanged');
        return $text;
    }

    private function assertRow(string $text, string $name, string $success, array $latencies, bool $exceptional = false): void
    {
        $expected = $name . ' ' . $success . ' ' . implode(' ', $latencies) . ($exceptional ? ' !' : '');
        $found = false;
        foreach (explode("\n", $text) as $line) {
            if (str_starts_with($line, $name . ' ')) {
                $this->assertEquals($expected, preg_replace('/ +/', ' ', rtrim($line)), 'Exact latency values and success ratio');
                $found = true;
            }
        }
        $this->assertTrue($found, 'Workload row present');
    }
}

(new CliRenderingTest())->run();
