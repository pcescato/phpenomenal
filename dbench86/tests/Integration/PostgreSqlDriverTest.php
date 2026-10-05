<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use Tests\DatabaseCredentials;
use DBench86\Driver\PostgreSqlDriver;

class PostgreSqlDriverTest extends TestCase
{
    public function testPostgreSqlDriverConstantsAreSpecialized(): void
    {
        // Verify that PostgreSqlDriver specializes the base constants
        $this->assertEquals(
            5_000,
            PostgreSqlDriver::MAX_BATCH_SIZE,
            "PostgreSqlDriver MAX_BATCH_SIZE must be 5000"
        );

        $this->assertEquals(
            32_000,
            PostgreSqlDriver::MAX_BOUND_PARAMETERS,
            "PostgreSqlDriver MAX_BOUND_PARAMETERS must be 32000"
        );
    }

    public function testPostgreSqlDriverConnect(): void
    {
        if (DatabaseCredentials::getInstance()->postgresqlUser === '') {
            $this->skip('Explicit database username unavailable');
            return;
        }
        $credentials = DatabaseCredentials::getInstance();

        $driver = new PostgreSqlDriver(
            host: $credentials->postgresqlHost,
            port: $credentials->postgresqlPort,
            database: $credentials->postgresqlDatabase,
            username: $credentials->postgresqlUser,
            password: $credentials->postgresqlPassword,
        );

        $this->assertEquals('PostgreSQL', $driver->name, "Driver name must be 'PostgreSQL'");
        $this->assertEquals('pgsql', $driver->pdoDriver, "PDO driver must be 'pgsql'");

        try {
            $pdo = $driver->connect();
            $this->assertTrue(
                $pdo !== null,
                "connect() must return a PDO instance"
            );
        } catch (\Exception $e) {
            $this->runner->fail("PostgreSQL connection failed: " . $e->getMessage());
        }
    }

    public function testPostgreSqlDriverDiscoverVersion(): void
    {
        if (DatabaseCredentials::getInstance()->postgresqlUser === '') {
            $this->skip('Explicit database username unavailable');
            return;
        }
        $credentials = DatabaseCredentials::getInstance();

        $driver = new PostgreSqlDriver(
            host: $credentials->postgresqlHost,
            port: $credentials->postgresqlPort,
            database: $credentials->postgresqlDatabase,
            username: $credentials->postgresqlUser,
            password: $credentials->postgresqlPassword,
        );

        try {
            $pdo = $driver->connect();
            $version = $driver->discoverEngineVersion($pdo);

            $this->assertTrue(
                !empty($version),
                "discoverEngineVersion() must return non-empty string"
            );

            $this->assertTrue(
                strlen($version) > 0,
                "Version string must have length > 0"
            );

            // Check that it looks like a version (contains digits and dots)
            $this->assertTrue(
                preg_match('/\d+\.\d+/', $version) === 1,
                "Version string should contain version numbers (e.g., '14.1'): got '$version'"
            );
        } catch (\Exception $e) {
            $this->runner->fail("PostgreSQL version discovery failed: " . $e->getMessage());
        }
    }

    public function run(): void
    {
        echo "\nPostgreSqlDriverTest\n";
        echo "────────────────────\n";
        $this->testPostgreSqlDriverConstantsAreSpecialized();
        $this->testPostgreSqlDriverConnect();
        $this->testPostgreSqlDriverDiscoverVersion();
    }
}

$test = new PostgreSqlDriverTest();
$test->run();
