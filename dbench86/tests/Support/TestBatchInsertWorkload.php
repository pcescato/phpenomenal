<?php
declare(strict_types=1);

namespace Tests\Support;

use DBench86\Driver\AbstractDriver;
use DBench86\Driver\DatabaseDriver;
use DBench86\Workload\BatchInsertWorkload;

/**
 * Test-only factory for BatchInsertWorkload with fallback driver.
 *
 * In production, BatchInsertWorkload requires an actual database driver instance.
 * For unit tests that don't need driver-specific limits, this factory provides
 * a minimal driver wrapper that uses AbstractDriver's shared constants.
 *
 * This is explicitly opt-in: tests must call this factory, they cannot accidentally
 * omit the driver parameter.
 */
final class TestBatchInsertWorkload
{
    /**
     * Create a BatchInsertWorkload for testing without a real database driver.
     *
     * Uses AbstractDriver's shared MAX_BATCH_SIZE and MAX_BOUND_PARAMETERS constants,
     * which serve as the baseline for cross-driver compatibility.
     *
     * For driver-specific testing, pass a real driver to BatchInsertWorkload directly.
     *
     * @param int $requestedBatchSize Batch size to request (will be clamped to limit)
     * @return BatchInsertWorkload Workload configured with baseline driver constants
     */
    public static function createForTesting(int $requestedBatchSize = 100): BatchInsertWorkload
    {
        return new BatchInsertWorkload(new TestDriver(), $requestedBatchSize);
    }
}

/**
 * Minimal driver implementation for testing.
 *
 * Provides only the interface and constants needed for batch limit calculations.
 * Not suitable for actual database operations.
 */
final class TestDriver implements DatabaseDriver
{
    public const int MAX_BATCH_SIZE = AbstractDriver::MAX_BATCH_SIZE;
    public const int MAX_BOUND_PARAMETERS = AbstractDriver::MAX_BOUND_PARAMETERS;

    public readonly string $name = 'TestDriver';
    public readonly string $pdoDriver = 'test';

    #[\Override]
    public function connect(): \PDO
    {
        throw new \RuntimeException('TestDriver cannot connect to a database');
    }

    #[\Override]
    public function resetSchema(\PDO $pdo): void
    {
        throw new \RuntimeException('TestDriver cannot reset schema');
    }

    #[\Override]
    public function createSchema(\PDO $pdo): void
    {
        throw new \RuntimeException('TestDriver cannot create schema');
    }

    #[\Override]
    public function discoverEngineVersion(\PDO $pdo): string
    {
        throw new \RuntimeException('TestDriver cannot discover engine version');
    }
}
