<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use DBench86\ExitCode;
use DBench86\Exception\InvalidConfigurationException;
use DBench86\Exception\RuntimeRequirementException;
use DBench86\Exception\DatabaseConnectionException;
use DBench86\Exception\BenchmarkPreparationException;
use DBench86\Exception\BenchmarkExecutionException;
use DBench86\Application;

class ExceptionHierarchyTest extends TestCase
{
    public function testInvalidConfigurationExceptionExitCode(): void
    {
        $e = new InvalidConfigurationException('Test invalid config');
        $this->assertEquals(ExitCode::InvalidArguments, $e->exitCode(), 'Should return InvalidArguments exit code');
        $this->assertEquals(2, $e->exitCode()->value, 'Should return exit code 2');
    }

    public function testRuntimeRequirementExceptionExitCode(): void
    {
        $e = new RuntimeRequirementException('Test runtime requirement');
        $this->assertEquals(ExitCode::RuntimePrerequisitesFailed, $e->exitCode(), 'Should return RuntimePrerequisitesFailed exit code');
        $this->assertEquals(3, $e->exitCode()->value, 'Should return exit code 3');
    }

    public function testDatabaseConnectionExceptionExitCode(): void
    {
        $e = new DatabaseConnectionException('Test database connection');
        $this->assertEquals(ExitCode::DatabaseConnectionFailed, $e->exitCode(), 'Should return DatabaseConnectionFailed exit code');
        $this->assertEquals(4, $e->exitCode()->value, 'Should return exit code 4');
    }

    public function testBenchmarkPreparationExceptionExitCode(): void
    {
        $e = new BenchmarkPreparationException('Test benchmark preparation');
        $this->assertEquals(ExitCode::BenchmarkPreparationFailed, $e->exitCode(), 'Should return BenchmarkPreparationFailed exit code');
        $this->assertEquals(5, $e->exitCode()->value, 'Should return exit code 5');
    }

    public function testBenchmarkExecutionExceptionExitCode(): void
    {
        $e = new BenchmarkExecutionException('Test benchmark execution');
        $this->assertEquals(ExitCode::BenchmarkExecutionFailed, $e->exitCode(), 'Should return BenchmarkExecutionFailed exit code');
        $this->assertEquals(6, $e->exitCode()->value, 'Should return exit code 6');
    }

    public function testDBench86ExceptionHandledCorrectly(): void
    {
        $app = new Application();
        $argv = ['dbench86.php', 'run', '--database=invalid'];

        $exitCode = $app->run($argv);
        $this->assertEquals(2, $exitCode, 'Should return exit code 2 for invalid configuration');
    }

    public function run(): void
    {
        echo "\nExceptionHierarchyTest\n";
        echo "──────────────────────\n";
        $this->testInvalidConfigurationExceptionExitCode();
        $this->testRuntimeRequirementExceptionExitCode();
        $this->testDatabaseConnectionExceptionExitCode();
        $this->testBenchmarkPreparationExceptionExitCode();
        $this->testBenchmarkExecutionExceptionExitCode();
        $this->testDBench86ExceptionHandledCorrectly();
    }
}

$exceptionTest = new ExceptionHierarchyTest();
$exceptionTest->run();
