<?php
declare(strict_types=1);

namespace Tests;

class TestRunner
{
    private int $passCount = 0;
    private int $failCount = 0;
    private int $skipCount = 0;
    private array $failures = [];
    private array $skipped = [];
    private string $currentTest = '';
    private string $currentSuite = '';

    public function run(): int
    {
        $unitTests = $this->discoverTests(__DIR__ . '/Unit');
        $integrationTests = $this->discoverTests(__DIR__ . '/Integration');

        echo "\n╔════════════════════════════════════════════╗\n";
        echo "║         UNIT TESTS                         ║\n";
        echo "╚════════════════════════════════════════════╝\n\n";

        $this->runTestFiles($unitTests, 'Unit');

        echo "\n╔════════════════════════════════════════════╗\n";
        echo "║         INTEGRATION TESTS                  ║\n";
        echo "╚════════════════════════════════════════════╝\n\n";

        $this->runTestFiles($integrationTests, 'Integration');

        $this->printSummary();

        return $this->failCount > 0 ? 1 : 0;
    }

    private function discoverTests(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $tests = [];
        $files = scandir($directory);

        foreach ($files as $file) {
            if (substr($file, -8) === 'Test.php') {
                $tests[] = $directory . '/' . $file;
            }
        }

        return $tests;
    }

    private function runTestFiles(array $testFiles, string $suiteType): void
    {
        foreach ($testFiles as $testFile) {
            $this->runTestFile($testFile, $suiteType);
        }
    }

    private function runTestFile(string $testFile, string $suiteType): void
    {
        $this->currentSuite = $suiteType;
        $this->currentTest = basename($testFile, '.php');

        try {
            require_once $testFile;
        } catch (\Throwable $e) {
            $this->fail(get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }

    public function assertTrue(bool $condition, string $message = ''): void
    {
        if ($condition) {
            $this->pass();
        } else {
            $this->fail("Assertion failed: {$message}");
        }
    }

    public function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected === $actual) {
            $this->pass();
        } else {
            $detail = $message ? "{$message}\n" : '';
            $detail .= "Expected: " . var_export($expected, true) . "\n";
            $detail .= "Actual: " . var_export($actual, true);
            $this->fail($detail);
        }
    }

    public function assertApproximately(float $expected, float $actual, float $tolerance, string $message = ''): void
    {
        if (abs($expected - $actual) <= $tolerance) {
            $this->pass();
        } else {
            $detail = $message ? "{$message}\n" : '';
            $detail .= "Expected (±{$tolerance}): {$expected}\n";
            $detail .= "Actual: {$actual}\n";
            $detail .= "Difference: " . abs($expected - $actual);
            $this->fail($detail);
        }
    }

    public function assertThrows(string $expectedExceptionClass, callable $callable, string $message = ''): void
    {
        try {
            $callable();
            $this->fail("Expected {$expectedExceptionClass} to be thrown, but no exception was thrown. {$message}");
        } catch (\Throwable $e) {
            if ($e instanceof $expectedExceptionClass) {
                $this->pass();
            } else {
                $this->fail("Expected {$expectedExceptionClass}, but got " . get_class($e) . ". {$message}");
            }
        }
    }

    public function skip(string $reason): void
    {
        $this->skipCount++;
        $this->skipped[] = [
            'suite' => $this->currentSuite,
            'test' => $this->currentTest,
            'reason' => $reason,
        ];
        echo "⊘ SKIP: {$reason}\n";
    }

    private function pass(): void
    {
        $this->passCount++;
        echo "✓ PASS\n";
    }

    public function fail(string $message): void
    {
        $this->failCount++;
        echo "✗ FAIL: {$message}\n";
        $this->failures[] = [
            'suite' => $this->currentSuite,
            'test' => $this->currentTest,
            'message' => $message,
        ];
    }

    private function printSummary(): void
    {
        echo "\n╔════════════════════════════════════════════╗\n";
        echo "║         TEST SUMMARY                       ║\n";
        echo "╚════════════════════════════════════════════╝\n\n";

        $total = $this->passCount + $this->failCount + $this->skipCount;

        printf("Total Tests:   %d\n", $total);
        printf("✓ Passed:      %d\n", $this->passCount);
        printf("✗ Failed:      %d\n", $this->failCount);
        printf("⊘ Skipped:     %d\n\n", $this->skipCount);

        if (!empty($this->failures)) {
            echo "FAILURES:\n";
            echo "─────────────────────────────────────────────\n";
            foreach ($this->failures as $idx => $failure) {
                echo ($idx + 1) . ". [{$failure['suite']}] {$failure['test']}\n";
                echo "   {$failure['message']}\n\n";
            }
        }

        if (!empty($this->skipped)) {
            echo "SKIPPED:\n";
            echo "─────────────────────────────────────────────\n";
            foreach ($this->skipped as $idx => $skip) {
                echo ($idx + 1) . ". [{$skip['suite']}] {$skip['test']}\n";
                echo "   Reason: {$skip['reason']}\n\n";
            }
        }

        if ($this->failCount === 0) {
            echo "✅ All tests passed!\n";
        } else {
            echo "❌ Some tests failed.\n";
        }
    }

    public static function getInstance(): self
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new self();
        }
        return $instance;
    }
}

// Global helper function
function test_runner(): TestRunner
{
    return TestRunner::getInstance();
}
