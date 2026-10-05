<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use DBench86\Build\MonolithBuilder;

final class Php86ComplianceTest extends TestCase
{
    public function run(): void
    {
        $builder = new MonolithBuilder(dirname(__DIR__, 2));
        $path = tempnam(sys_get_temp_dir(), 'dbench86-monolith-');
        try {
            $builder->write($path);
            $process = proc_open([PHP_BINARY, __DIR__ . '/../../../scripts/check-php86-features.php', $path], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertTrue(is_resource($process), 'Official checker starts');
            if (is_resource($process)) {
                echo stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fwrite(STDERR, stream_get_contents($pipes[2]));
                fclose($pipes[2]);
                $this->assertEquals(0, proc_close($process), 'All seven genuine production features pass the official checker');
            }
        } finally {
            unlink($path);
        }
    }
}
(new Php86ComplianceTest())->run();
