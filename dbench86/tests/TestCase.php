<?php
declare(strict_types=1);

namespace Tests;

abstract class TestCase
{
    protected TestRunner $runner;

    public function __construct()
    {
        $this->runner = TestRunner::getInstance();
    }

    abstract public function run(): void;

    protected function assertTrue(bool $condition, string $message = ''): void
    {
        $this->runner->assertTrue($condition, $message);
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->runner->assertEquals($expected, $actual, $message);
    }

    protected function assertApproximately(float $expected, float $actual, float $tolerance, string $message = ''): void
    {
        $this->runner->assertApproximately($expected, $actual, $tolerance, $message);
    }

    protected function assertThrows(string $expectedExceptionClass, callable $callable, string $message = ''): void
    {
        $this->runner->assertThrows($expectedExceptionClass, $callable, $message);
    }

    protected function skip(string $reason): void
    {
        $this->runner->skip($reason);
    }
}
