<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class TestRunnerTest extends TestCase
{
    public function run(): void
    {
        $directory = sys_get_temp_dir() . '/dbench86-runner-' . bin2hex(random_bytes(6));
        mkdir($directory);
        mkdir($directory . '/Unit');
        mkdir($directory . '/Integration');
        copy(__DIR__ . '/../TestRunner.php', $directory . '/TestRunner.php');
        file_put_contents($directory . '/run.php', '<?php require __DIR__ . "/TestRunner.php"; exit(Tests\test_runner()->run());');
        try {
            foreach ([
                ['Tests\test_runner()->assertTrue(false, "controlled assertion");', 'controlled assertion'],
                ['throw new RuntimeException("controlled exception");', 'controlled exception'],
                ['Tests\test_runner()->assertThrows(LogicException::class, static function () {});', 'no exception was thrown'],
            ] as [$fixture, $message]) {
                file_put_contents($directory . '/Unit/ControlledTest.php', '<?php ' . $fixture);
                $process = proc_open([PHP_BINARY, '-d', 'zend.assertions=-1', $directory . '/run.php'],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertEquals(1, proc_close($process), 'A controlled failure must exit nonzero even with PHP assertions disabled');
                $this->assertEquals('', $error);
                $this->assertTrue(str_contains($output, '✗ Failed:      1') && str_contains($output, $message));
            }
        } finally {
            unlink($directory . '/Unit/ControlledTest.php');
            unlink($directory . '/run.php');
            unlink($directory . '/TestRunner.php');
            rmdir($directory . '/Unit');
            rmdir($directory . '/Integration');
            rmdir($directory);
        }
    }
}

(new TestRunnerTest())->run();
