<?php
declare(strict_types=1);

namespace Tests\Unit;

use DBench86\Benchmark\BatchLimitCalculator;
use DBench86\Driver\MariaDbDriver;
use DBench86\Driver\PostgreSqlDriver;
use DBench86\Driver\SqliteDriver;
use Tests\TestCase;

/**
 * Unit tests for BatchLimitCalculator.
 *
 * Verifies the batch limit calculation algorithm across different scenarios:
 * - Multiple drivers with different constraints
 * - Variable row-level parameter counts
 * - Requested batch sizes above/below limits
 * - Error conditions
 */
final class BatchLimitCalculatorTest extends TestCase
{
    /**
     * Test the canonical case: 3 drivers with different limits.
     *
     * This test instantiates mock drivers and computes their individual limits,
     * then the common limit, confirming that:
     * - MariaDB specializes to higher limits
     * - PostgreSQL falls in the middle
     * - SQLite is most conservative
     * - Common limit reflects the minimum
     */
    public function testCanonicalThreeDrivers(): void
    {
        // Instantiate three drivers (with minimal configuration)
        $mariadb = new MariaDbDriver(
            host: '127.0.0.1',
            port: 3306,
            database: 'test',
            username: 'root',
            password: '',
        );
        $postgres = new PostgreSqlDriver(
            host: '127.0.0.1',
            port: 5432,
            database: 'test',
            username: 'test',
            password: '',
        );
        $sqlite = new SqliteDriver(':memory:');

        // Verify constants (sanity check)
        $this->assertEquals(2_000, MariaDbDriver::MAX_BATCH_SIZE, 'MariaDB MAX_BATCH_SIZE');
        $this->assertEquals(5_000, PostgreSqlDriver::MAX_BATCH_SIZE, 'PostgreSQL MAX_BATCH_SIZE');
        $this->assertEquals(500, SqliteDriver::MAX_BATCH_SIZE, 'SQLite MAX_BATCH_SIZE');

        $this->assertEquals(50_000, MariaDbDriver::MAX_BOUND_PARAMETERS, 'MariaDB MAX_BOUND_PARAMETERS');
        $this->assertEquals(32_000, PostgreSqlDriver::MAX_BOUND_PARAMETERS, 'PostgreSQL MAX_BOUND_PARAMETERS');
        $this->assertEquals(900, SqliteDriver::MAX_BOUND_PARAMETERS, 'SQLite MAX_BOUND_PARAMETERS');
    }

    /**
     * Test batch limit variation based on boundParametersPerRow.
     *
     * The same driver can have very different effective limits depending on
     * the row parameter count. This test verifies:
     * - C=5: one limit calculated
     * - C=4: different limit calculated (higher, since fewer parameters per row)
     * - Common limit changes as C changes
     */
    public function testLimitVariationByParametersPerRow(): void
    {
        $sqlite = new SqliteDriver(':memory:');

        // SQLite: MAX_BOUND_PARAMETERS = 900, MAX_BATCH_SIZE = 500

        // Case 1: C=5 (e.g., table with 5 bindable columns for INSERT)
        $limit_c5 = BatchLimitCalculator::driverLimit($sqlite, 5);
        // parameterLimit = floor(900 / 5) = 180
        // result = min(500, 180) = 180
        $this->assertEquals(180, $limit_c5, 'SQLite with C=5 should be 180');

        // Case 2: C=4 (e.g., different table with 4 bindable columns)
        $limit_c4 = BatchLimitCalculator::driverLimit($sqlite, 4);
        // parameterLimit = floor(900 / 4) = 225
        // result = min(500, 225) = 225
        $this->assertEquals(225, $limit_c4, 'SQLite with C=4 should be 225');

        // Confirm they differ
        $this->assertTrue(
            $limit_c4 !== $limit_c5,
            'Same driver should have different limits for different C values'
        );
    }

    /**
     * Test common limit calculation across multiple drivers with same C.
     *
     * Calculates the individual limit for each driver given C=8, then
     * finds the common limit (minimum).
     */
    public function testCommonLimitMultipleDrivers(): void
    {
        $mariadb = new MariaDbDriver(
            host: '127.0.0.1',
            port: 3306,
            database: 'test',
            username: 'root',
            password: '',
        );
        $postgres = new PostgreSqlDriver(
            host: '127.0.0.1',
            port: 5432,
            database: 'test',
            username: 'test',
            password: '',
        );
        $sqlite = new SqliteDriver(':memory:');

        $c = 8;

        $mariadb_limit = BatchLimitCalculator::driverLimit($mariadb, $c);
        $postgres_limit = BatchLimitCalculator::driverLimit($postgres, $c);
        $sqlite_limit = BatchLimitCalculator::driverLimit($sqlite, $c);

        // Expected calculations:
        // MariaDB:  floor(50000 / 8) = 6250, min(2000, 6250) = 2000
        // PostgreSQL: floor(32000 / 8) = 4000, min(5000, 4000) = 4000
        // SQLite: floor(900 / 8) = 112, min(500, 112) = 112

        $this->assertEquals(2_000, $mariadb_limit, 'MariaDB batch limit with C=8');
        $this->assertEquals(4_000, $postgres_limit, 'PostgreSQL batch limit with C=8');
        $this->assertEquals(112, $sqlite_limit, 'SQLite batch limit with C=8');

        $common = BatchLimitCalculator::commonLimit([
            $mariadb_limit,
            $postgres_limit,
            $sqlite_limit,
        ]);

        // Common limit is the minimum
        $this->assertEquals(112, $common, 'Common limit should be minimum across all drivers');
    }

    /**
     * Test that requested batch size below the common limit is unchanged.
     */
    public function testEffectiveBatchSizeBelowLimit(): void
    {
        $commonLimit = 100;
        $requested = 50;

        $effective = BatchLimitCalculator::effectiveBatchSize($requested, $commonLimit);

        $this->assertEquals(50, $effective, 'Batch size below limit should be unchanged');
    }

    /**
     * Test that requested batch size above the common limit is clamped.
     */
    public function testEffectiveBatchSizeAboveLimit(): void
    {
        $commonLimit = 100;
        $requested = 250;

        $effective = BatchLimitCalculator::effectiveBatchSize($requested, $commonLimit);

        $this->assertEquals(100, $effective, 'Batch size above limit should be clamped to limit');
    }

    /**
     * Test that requested batch size exactly at the limit is unchanged.
     */
    public function testEffectiveBatchSizeAtLimit(): void
    {
        $commonLimit = 100;
        $requested = 100;

        $effective = BatchLimitCalculator::effectiveBatchSize($requested, $commonLimit);

        $this->assertEquals(100, $effective, 'Batch size at limit should be unchanged');
    }

    /**
     * Test that requested batch size of 0 is clamped to 1 (minimum).
     */
    public function testEffectiveBatchSizeZero(): void
    {
        $commonLimit = 100;
        $requested = 0;

        $effective = BatchLimitCalculator::effectiveBatchSize($requested, $commonLimit);

        $this->assertEquals(1, $effective, 'Batch size 0 should be clamped to minimum 1');
    }

    /**
     * Test that negative requested batch size is clamped to 1 (minimum).
     */
    public function testEffectiveBatchSizeNegative(): void
    {
        $commonLimit = 100;
        $requested = -50;

        $effective = BatchLimitCalculator::effectiveBatchSize($requested, $commonLimit);

        $this->assertEquals(1, $effective, 'Negative batch size should be clamped to minimum 1');
    }

    /**
     * Test that boundParametersPerRow = 0 raises an exception.
     */
    public function testDriverLimitZeroParameters(): void
    {
        $sqlite = new SqliteDriver(':memory:');

        $this->assertThrows(
            'InvalidArgumentException',
            function () use ($sqlite) {
                BatchLimitCalculator::driverLimit($sqlite, 0);
            },
            'driverLimit should throw InvalidArgumentException for C=0'
        );
    }

    /**
     * Test that boundParametersPerRow < 0 raises an exception.
     */
    public function testDriverLimitNegativeParameters(): void
    {
        $sqlite = new SqliteDriver(':memory:');

        $this->assertThrows(
            'InvalidArgumentException',
            function () use ($sqlite) {
                BatchLimitCalculator::driverLimit($sqlite, -5);
            },
            'driverLimit should throw InvalidArgumentException for C < 0'
        );
    }

    /**
     * Test that commonLimit with empty array raises an exception.
     */
    public function testCommonLimitEmpty(): void
    {
        $this->assertThrows(
            'InvalidArgumentException',
            function () {
                BatchLimitCalculator::commonLimit([]);
            },
            'commonLimit should throw InvalidArgumentException for empty array'
        );
    }

    /**
     * Test driverLimit with C=1 (minimal parameter count).
     */
    public function testDriverLimitMinimalParameters(): void
    {
        $sqlite = new SqliteDriver(':memory:');

        $limit = BatchLimitCalculator::driverLimit($sqlite, 1);

        // SQLite: floor(900 / 1) = 900, min(500, 900) = 500
        $this->assertEquals(500, $limit, 'SQLite with C=1 should be 500 (MAX_BATCH_SIZE)');
    }

    /**
     * Test the complete workflow: calculate limits for all drivers, find common,
     * then apply effective batch size clamping.
     */
    public function testCompleteWorkflow(): void
    {
        // Three drivers
        $mariadb = new MariaDbDriver(
            host: '127.0.0.1',
            port: 3306,
            database: 'test',
            username: 'root',
            password: '',
        );
        $postgres = new PostgreSqlDriver(
            host: '127.0.0.1',
            port: 5432,
            database: 'test',
            username: 'test',
            password: '',
        );
        $sqlite = new SqliteDriver(':memory:');

        // Scenario: INSERT with 10 bound parameters per row
        $boundParametersPerRow = 10;

        // Calculate limits for each driver
        $mariadb_limit = BatchLimitCalculator::driverLimit($mariadb, $boundParametersPerRow);
        $postgres_limit = BatchLimitCalculator::driverLimit($postgres, $boundParametersPerRow);
        $sqlite_limit = BatchLimitCalculator::driverLimit($sqlite, $boundParametersPerRow);

        // Expected:
        // MariaDB:  floor(50000 / 10) = 5000, min(2000, 5000) = 2000
        // PostgreSQL: floor(32000 / 10) = 3200, min(5000, 3200) = 3200
        // SQLite: floor(900 / 10) = 90, min(500, 90) = 90

        $this->assertEquals(2_000, $mariadb_limit, 'MariaDB limit with C=10');
        $this->assertEquals(3_200, $postgres_limit, 'PostgreSQL limit with C=10');
        $this->assertEquals(90, $sqlite_limit, 'SQLite limit with C=10');

        // Find common limit
        $common = BatchLimitCalculator::commonLimit([
            $mariadb_limit,
            $postgres_limit,
            $sqlite_limit,
        ]);

        $this->assertEquals(90, $common, 'Common limit should be 90');

        // User requests 1000, but that exceeds common limit
        $requested = 1000;
        $effective = BatchLimitCalculator::effectiveBatchSize($requested, $common);

        $this->assertEquals(90, $effective, 'Requested 1000 should be clamped to common limit 90');

        // User requests 50, which is below common limit
        $requested = 50;
        $effective = BatchLimitCalculator::effectiveBatchSize($requested, $common);

        $this->assertEquals(50, $effective, 'Requested 50 should be unchanged (below limit)');
    }

    /**
     * Run all tests in this suite.
     */
    public function run(): void
    {
        echo "\nBatchLimitCalculatorTest\n";
        echo "────────────────────────\n";
        $this->testCanonicalThreeDrivers();
        $this->testLimitVariationByParametersPerRow();
        $this->testCommonLimitMultipleDrivers();
        $this->testEffectiveBatchSizeBelowLimit();
        $this->testEffectiveBatchSizeAboveLimit();
        $this->testEffectiveBatchSizeAtLimit();
        $this->testEffectiveBatchSizeZero();
        $this->testEffectiveBatchSizeNegative();
        $this->testDriverLimitZeroParameters();
        $this->testDriverLimitNegativeParameters();
        $this->testCommonLimitEmpty();
        $this->testDriverLimitMinimalParameters();
        $this->testCompleteWorkflow();
    }
}

$test = new BatchLimitCalculatorTest();
$test->run();
