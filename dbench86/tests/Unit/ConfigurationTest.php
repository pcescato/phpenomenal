<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use DBench86\Cli\ArgumentParser;
use DBench86\Configuration\BenchmarkConfiguration;
use SortDirection;

class ArgumentParserTest extends TestCase
{
    private ArgumentParser $parser;

    public function __construct()
    {
        parent::__construct();
        $this->parser = new ArgumentParser();
    }

    public function testParseDefaultValues(): void
    {
        $parsed = $this->parser->parse(['dbench86.php', 'run']);
        $this->assertEquals('all', $parsed['database'], 'Default database should be all');
        $this->assertEquals(100_000, $parsed['rows'], 'Default rows should be 100000');
        $this->assertEquals(30, $parsed['runs'], 'Default runs should be 30');
        $this->assertEquals(5, $parsed['warmup'], 'Default warmup should be 5');
        $this->assertEquals(100, $parsed['batch_size'], 'Default batch_size preserves the actual 100-row workload');
        $this->assertEquals(30, $parsed['timeout'], 'Default timeout should be 30');
        $this->assertEquals('median', $parsed['sort'], 'Default sort should be median');
        $this->assertEquals('asc', $parsed['direction'], 'Default direction should be asc');
        $this->assertEquals(false, $parsed['debug'], 'Default debug should be false');
    }

    public function testParseExplicitValues(): void
    {
        $argv = [
            'dbench86.php',
            'run',
            '--database=postgresql',
            '--rows=50000',
            '--runs=10',
            '--warmup=3',
            '--batch-size=1000',
            '--timeout=60',
            '--sort=p95',
            '--direction=desc',
            '--debug',
        ];

        $parsed = $this->parser->parse($argv);

        $this->assertEquals('postgresql', $parsed['database'], 'Database should be postgresql');
        $this->assertEquals('50000', $parsed['rows'], 'Rows should be "50000"');
        $this->assertEquals('10', $parsed['runs'], 'Runs should be "10"');
    }

    public function testParseInvalidOption(): void
    {
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => $this->parser->parse(['dbench86.php', '--invalid-option=value']),
            'Should throw InvalidArgumentException for unknown option'
        );
    }

    public function run(): void
    {
        echo "\nArgumentParserTest\n";
        echo "───────────────────\n";
        $this->testParseDefaultValues();
        $this->testParseExplicitValues();
        $this->testParseInvalidOption();
    }
}

class ConfigurationTest extends TestCase
{
    public function testConfigurationCreatedFromValidParsed(): void
    {
        $parsed = [
            'database' => 'mariadb',
            'rows' => '50000',
            'runs' => '20',
            'warmup' => '5',
            'batch_size' => '500',
            'timeout' => '60',
            'sort' => 'median',
            'direction' => 'asc',
            'debug' => true,
        ];

        $config = new BenchmarkConfiguration($parsed);

        $this->assertEquals('mariadb', $config->database, 'Database should be mariadb');
        $this->assertEquals(50000, $config->rows, 'Rows should be 50000');
        $this->assertEquals(SortDirection::Ascending, $config->direction, 'Direction should be Ascending');
    }

    public function testConfigurationInvalidDatabase(): void
    {
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => new BenchmarkConfiguration([
                'database' => 'invalid_db',
                'rows' => '1000',
                'runs' => '5',
                'warmup' => '2',
                'batch_size' => '100',
                'timeout' => '30',
                'sort' => 'median',
                'direction' => 'asc',
                'debug' => false,
            ]),
            'Should reject invalid database'
        );
    }

    public function testConfigurationNegativeRows(): void
    {
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => new BenchmarkConfiguration([
                'database' => 'sqlite',
                'rows' => '-1000',
                'runs' => '5',
                'warmup' => '2',
                'batch_size' => '100',
                'timeout' => '30',
                'sort' => 'median',
                'direction' => 'asc',
                'debug' => false,
            ]),
            'Should reject negative rows'
        );
    }

    public function testTimeoutRejectsFractionWithoutTruncation(): void
    {
        // Previously accepted silently as the integer 1.
        $this->assertTimeoutRejected(
            '1.001',
            "--timeout doit être un nombre entier de secondes, valeur reçue : '1.001'"
        );
    }

    public function testTimeoutRejectsInvalidIntegerSyntax(): void
    {
        foreach (['0.5', '0.001', 'abc', '1.0', '3e1', '+1', '-1', '', "1\n2"] as $value) {
            $this->assertTimeoutRejected(
                $value,
                "--timeout doit être un nombre entier de secondes, valeur reçue : '{$value}'"
            );
        }
    }

    public function testTimeoutAcceptsPositiveWholeSeconds(): void
    {
        $parser = new ArgumentParser();
        foreach (['1' => 1, '30' => 30, ' 30 ' => 30, '0030' => 30, (string) PHP_INT_MAX => PHP_INT_MAX] as $value => $expected) {
            $config = new BenchmarkConfiguration($parser->parse(['dbench86.php', 'run', "--timeout={$value}"]));
            $this->assertEquals($expected, $config->timeout, 'Timeout must preserve whole seconds');
        }
        $config = new BenchmarkConfiguration($parser->parse(['dbench86.php', 'run']));
        $this->assertEquals(30, $config->timeout, 'Default timeout remains 30 seconds');
    }

    public function testTimeoutRejectsZeroAndOverflow(): void
    {
        $this->assertTimeoutRejected('0', '--timeout doit être positif (> 0), reçu : 0');
        $value = (string) PHP_INT_MAX . '0';
        $this->assertTimeoutRejected(
            $value,
            "--timeout dépasse le nombre maximal de secondes (" . PHP_INT_MAX . "), valeur reçue : '{$value}'"
        );
    }

    private function assertTimeoutRejected(string $value, string $expectedMessage): void
    {
        $parser = new ArgumentParser();
        $parsed = $parser->parse(['dbench86.php', 'run', "--timeout={$value}"]);
        try {
            new BenchmarkConfiguration($parsed);
            $this->assertTrue(false, "--timeout={$value} must be rejected before conversion");
        } catch (\InvalidArgumentException $e) {
            $this->assertEquals($expectedMessage, $e->getMessage(), 'Timeout validation message');
        }
    }

    public function run(): void
    {
        echo "\nConfigurationTest\n";
        echo "──────────────────\n";
        $this->testConfigurationCreatedFromValidParsed();
        $this->testConfigurationInvalidDatabase();
        $this->testConfigurationNegativeRows();
        $this->testTimeoutRejectsFractionWithoutTruncation();
        $this->testTimeoutRejectsInvalidIntegerSyntax();
        $this->testTimeoutAcceptsPositiveWholeSeconds();
        $this->testTimeoutRejectsZeroAndOverflow();
    }
}

$parserTest = new ArgumentParserTest();
$parserTest->run();

$configTest = new ConfigurationTest();
$configTest->run();
