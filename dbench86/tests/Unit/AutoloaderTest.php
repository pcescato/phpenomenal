<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use DBench86\Application;
use DBench86\Driver\Nested\Example;

class AutoloaderTest extends TestCase
{
    public function testSingleLevelNamespace(): void
    {
        $app = new Application();
        $this->assertEquals('DBench86', $app->getName(), 'Single-level namespace');
    }

    public function testNestedNamespace(): void
    {
        $example = new Example();
        $this->assertEquals(42, $example->getValue(), 'Nested namespace (Driver\Nested\Example)');
    }

    public function run(): void
    {
        echo "\nAutoloaderTest\n";
        echo "──────────────\n";
        $this->testSingleLevelNamespace();
        $this->testNestedNamespace();
    }
}

$test = new AutoloaderTest();
$test->run();
