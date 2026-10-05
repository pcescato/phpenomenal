<?php
declare(strict_types=1);

namespace Tests\Integration;

use DBench86\Driver\SqliteDriver;
use Tests\TestCase;

/**
 * Integration tests for the SQLite database driver.
 *
 * SQLite tests are unique: they use a temporary file path, so they should
 * never require external configuration or skip due to server unavailability.
 * The only skip reason would be an unexpected environment issue.
 */
final class SqliteDriverTest extends TestCase
{
    private string $tmpFile = '';

    /**
     * Set up a temporary SQLite database file for testing.
     */
    protected function setUp(): void
    {
        $tmpdir = sys_get_temp_dir();
        $this->tmpFile = tempnam($tmpdir, 'dbench86_sqlite_');
        if ($this->tmpFile && file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    /**
     * Clean up the temporary database file after each test.
     */
    protected function tearDown(): void
    {
        if ($this->tmpFile && file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    /**
     * Test that SqliteDriver constants are properly specialized.
     */
    public function testSqliteDriverConstantsAreSpecialized(): void
    {
        $this->assertEquals(500, SqliteDriver::MAX_BATCH_SIZE, 'MAX_BATCH_SIZE should be 500');
        $this->assertEquals(900, SqliteDriver::MAX_BOUND_PARAMETERS, 'MAX_BOUND_PARAMETERS should be 900');
    }

    /**
     * Test that SqliteDriver can connect to a temporary file database.
     *
     * This test verifies:
     *   - Constructor accepts a file path
     *   - name and pdoDriver properties return correct identifiers
     *   - connect() creates the database file if it doesn't exist
     *   - PDO connection is established and functional
     */
    public function testSqliteDriverConnect(): void
    {
        $driver = new SqliteDriver($this->tmpFile);

        $this->assertEquals('SQLite', $driver->name, 'Driver name should be "SQLite"');
        $this->assertEquals('sqlite', $driver->pdoDriver, 'PDO driver should be "sqlite"');

        $pdo = $driver->connect();
        $this->assertTrue(
            $pdo instanceof \PDO,
            'connect() should return a PDO instance'
        );

        // Verify the database file was created
        $this->assertTrue(
            file_exists($this->tmpFile),
            "SQLite should create the database file at {$this->tmpFile}"
        );
    }

    /**
     * Test that SqliteDriver discovers SQLite version correctly.
     *
     * This test verifies:
     *   - discoverEngineVersion() executes SELECT sqlite_version()
     *   - Returns a non-empty string
     *   - The string matches expected SQLite version format (e.g., "3.39.0")
     */
    public function testSqliteDriverDiscoverVersion(): void
    {
        $driver = new SqliteDriver($this->tmpFile);
        $pdo = $driver->connect();

        $version = $driver->discoverEngineVersion($pdo);

        $this->assertTrue(
            is_string($version) && !empty($version),
            'discoverEngineVersion() should return a non-empty version string'
        );

        // SQLite version format is typically "3.X.Y" or "3.X.Y.Z"
        $this->assertTrue(
            preg_match('/^\d+\.\d+/', $version) === 1,
            "SQLite version '{$version}' should match pattern \\d+\\.\\d+"
        );
    }

    /**
     * Test that SqliteDriver handles in-memory databases.
     *
     * This test verifies:
     *   - Special path ":memory:" is accepted
     *   - In-memory database connects successfully
     *   - Version discovery works on in-memory databases
     */
    public function testSqliteDriverInMemoryDatabase(): void
    {
        $driver = new SqliteDriver(':memory:');

        $this->assertEquals('SQLite', $driver->name, 'In-memory driver name should be "SQLite"');

        $pdo = $driver->connect();
        $this->assertTrue(
            $pdo instanceof \PDO,
            'In-memory connect() should return PDO'
        );

        $version = $driver->discoverEngineVersion($pdo);
        $this->assertTrue(
            is_string($version) && !empty($version),
            'In-memory database should report version'
        );
    }

    /**
     * Test that SqliteDriver constants are inherited correctly from AbstractDriver.
     *
     * This ensures that specialization via #[\Override] works and doesn't accidentally
     * revert to parent values.
     */
    public function testSqliteDriverConstantsNotInherited(): void
    {
        // AbstractDriver default is 500 and 10_000
        // SqliteDriver overrides to 500 and 900

        $this->assertTrue(
            900 !== 10_000,  // Default MAX_BOUND_PARAMETERS from AbstractDriver
            'SqliteDriver should specialize MAX_BOUND_PARAMETERS from 10000 to 900'
        );
    }

    /**
     * Run all tests in this suite.
     *
     * Each test is wrapped with setUp() before and tearDown() after
     * to handle temporary database file creation and cleanup.
     */
    public function run(): void
    {
        echo "\nSqliteDriverTest\n";
        echo "────────────────\n";

        // Test 1: Constants are specialized
        $this->setUp();
        $this->testSqliteDriverConstantsAreSpecialized();
        $this->tearDown();

        // Test 2: Connect to temporary file
        $this->setUp();
        $this->testSqliteDriverConnect();
        $this->tearDown();

        // Test 3: Discover version
        $this->setUp();
        $this->testSqliteDriverDiscoverVersion();
        $this->tearDown();

        // Test 4: In-memory database
        $this->setUp();
        $this->testSqliteDriverInMemoryDatabase();
        $this->tearDown();

        // Test 5: Constants not inherited
        $this->setUp();
        $this->testSqliteDriverConstantsNotInherited();
        $this->tearDown();
    }
}

$test = new SqliteDriverTest();
$test->run();
