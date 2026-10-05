<?php
declare(strict_types=1);

namespace DBench86\Driver;

/**
 * Abstract base class for database drivers.
 *
 * Provides a common foundation for all driver implementations (MariaDB, PostgreSQL, SQLite).
 * Defines operational constraints and helper methods used across drivers.
 *
 * Each concrete driver extends this class and implements DatabaseDriver interface,
 * with driver-specific connection logic, schema operations, and version discovery.
 */
abstract class AbstractDriver implements DatabaseDriver
{
    /**
     * Maximum number of rows per batch during data insertion.
     *
     * IMPORTANT: This is an OPERATIONAL CONSTRAINT chosen by DBench86 for safety and
     * predictability, NOT a limit imposed by the database engine itself.
     *
     * - MariaDB supports millions of rows per statement
     * - PostgreSQL supports millions of rows per statement
     * - SQLite supports similar capacity
     *
     * This value (500) is a conservative choice to:
     * - Keep memory usage predictable
     * - Reduce statement recompilation overhead
     * - Ensure consistent timing across runs
     * - Simplify debugging and logging
     *
     * Individual drivers may override this constant with a different value
     * if DBench86-specific benchmarking needs require it. Any override must
     * include similar documentation explaining the choice.
     */
    public const int MAX_BATCH_SIZE = 500;

    /**
     * Maximum number of bound parameters per prepared statement.
     *
     * IMPORTANT: This is an OPERATIONAL CONSTRAINT chosen by DBench86 for safety and
     * predictability, NOT a limit imposed by the database engine itself.
     *
     * Reference limits (not enforced by DBench86 — only for context):
     * - MariaDB: The server accepts up to 2^16 (65536) placeholders in prepared statements.
     *   See: https://mariadb.com/kb/en/library/prepare-statement/
     * - PostgreSQL: Maximum 32,767 positional parameters ($1, $2, ..., $32767) per prepared statement.
     *   See: https://www.postgresql.org/docs/current/sql-prepare.html
     * - SQLite: SQLITE_LIMIT_VARIABLE_NUMBER (default 32766 since SQLite 3.32.0).
     *   See: https://www.sqlite.org/limits.html
     *
     * This value (10,000) is a conservative choice to:
     * - Keep PDO and driver memory usage under control
     * - Ensure reasonable statement preparation times
     * - Reduce the risk of parameter binding errors
     * - Maintain consistent performance profiles
     *
     * Individual drivers may override this constant with a different value
     * if DBench86-specific benchmarking needs require it. Any override must
     * include similar documentation explaining the choice.
     */
    public const int MAX_BOUND_PARAMETERS = 10_000;
}
