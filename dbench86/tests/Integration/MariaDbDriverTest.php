<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use Tests\DatabaseCredentials;
use DBench86\Driver\MariaDbDriver;

class MariaDbDriverTest extends TestCase
{
    public function testMariaDbDriverConnect(): void
    {
        if (DatabaseCredentials::getInstance()->mariadbUser === '') {
            $this->skip('Explicit database username unavailable');
            return;
        }
        $credentials = DatabaseCredentials::getInstance();

        $driver = new MariaDbDriver(
            host: $credentials->mariadbHost,
            port: $credentials->mariadbPort,
            database: $credentials->mariadbDatabase,
            username: $credentials->mariadbUser,
            password: $credentials->mariadbPassword,
            socket: $credentials->mariadbSocket
        );

        $this->assertEquals('MariaDB', $driver->name, "Driver name must be 'MariaDB'");
        $this->assertEquals('mysql', $driver->pdoDriver, "PDO driver must be 'mysql'");

        try {
            $pdo = $driver->connect();
            $this->assertTrue(
                $pdo !== null,
                "connect() must return a PDO instance"
            );
        } catch (\Exception $e) {
            $this->runner->fail("MariaDB connection failed: " . $e->getMessage());
        }
    }

    public function testMariaDbDriverDiscoverVersion(): void
    {
        if (DatabaseCredentials::getInstance()->mariadbUser === '') {
            $this->skip('Explicit database username unavailable');
            return;
        }
        $credentials = DatabaseCredentials::getInstance();

        $driver = new MariaDbDriver(
            host: $credentials->mariadbHost,
            port: $credentials->mariadbPort,
            database: $credentials->mariadbDatabase,
            username: $credentials->mariadbUser,
            password: $credentials->mariadbPassword,
            socket: $credentials->mariadbSocket
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

            // Check that it looks like a version (contains digits)
            $this->assertTrue(
                preg_match('/\d+\.\d+/', $version) === 1,
                "Version string should contain version numbers (e.g., '10.6'): got '$version'"
            );
        } catch (\Exception $e) {
            $this->runner->fail("MariaDB version discovery failed: " . $e->getMessage());
        }
    }

    public function testMariaDbDriverConstantsAreSpecialized(): void
    {
        // Verify that MariaDbDriver specializes the base constants
        $this->assertEquals(
            2_000,
            MariaDbDriver::MAX_BATCH_SIZE,
            "MariaDbDriver MAX_BATCH_SIZE must be 2000"
        );

        $this->assertEquals(
            50_000,
            MariaDbDriver::MAX_BOUND_PARAMETERS,
            "MariaDbDriver MAX_BOUND_PARAMETERS must be 50000"
        );
    }

    public function run(): void
    {
        echo "\nMariaDbDriverTest\n";
        echo "──────────────────\n";
        $this->testMariaDbDriverConstantsAreSpecialized();
        $this->testMariaDbDriverConnect();
        $this->testMariaDbDriverDiscoverVersion();
    }
}

$test = new MariaDbDriverTest();
$test->run();
