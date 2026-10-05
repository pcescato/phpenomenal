<?php
declare(strict_types=1);

namespace DBench86\Driver;

use PDO;

/**
 * Contract for database driver implementations.
 *
 * Each driver (MariaDB, PostgreSQL, SQLite) must implement this interface
 * to provide consistent access to database-specific operations required
 * by the benchmarking framework.
 */
interface DatabaseDriver
{
    /**
     * Human-readable name of this driver (e.g., "MariaDB", "PostgreSQL", "SQLite").
     *
     * Implemented by concrete drivers as a readonly public property with a default value.
     */
    public string $name { get; }

    /**
     * PDO driver name used in DSN construction (e.g., "mysql", "pgsql", "sqlite").
     *
     * Implemented by concrete drivers as a readonly public property with a default value.
     */
    public string $pdoDriver { get; }

    /**
     * Establish a PDO connection to the target database.
     *
     * Connection parameters (host, port, username, password, database) are
     * provided to the constructor, not to this method.
     *
     * @return PDO An active, ready-to-use PDO instance
     * @throws \DBench86\Exception\DatabaseConnectionException If connection fails
     */
    public function connect(): PDO;

    /**
     * Drop all tables and reset the schema to a clean state.
     *
     * This is called before each benchmark run to ensure a predictable starting point.
     * The database connection already exists; this method must not create or drop the database itself.
     *
     * @param PDO $pdo An active database connection
     * @throws \DBench86\Exception\BenchmarkPreparationException If schema reset fails
     */
    public function resetSchema(PDO $pdo): void;

    /**
     * Create the benchmark schema (tables, indexes, constraints).
     *
     * This is called after resetSchema() to prepare for data loading and benchmarking.
     * The schema design is DBench86-specific and may differ slightly per driver
     * (e.g., data type choices, index strategies).
     *
     * @param PDO $pdo An active database connection
     * @throws \DBench86\Exception\BenchmarkPreparationException If schema creation fails
     */
    public function createSchema(PDO $pdo): void;

    /**
     * Discover and return the engine version string.
     *
     * Used for logging and validation. The format may vary by driver
     * (e.g., "MariaDB 10.6.4", "PostgreSQL 14.1", "3.40.0").
     *
     * @param PDO $pdo An active database connection
     * @return string Version string
     * @throws \DBench86\Exception\DatabaseConnectionException If version query fails
     */
    public function discoverEngineVersion(PDO $pdo): string;
}
