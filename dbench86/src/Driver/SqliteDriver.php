<?php
declare(strict_types=1);

namespace DBench86\Driver;

use DBench86\Exception\DatabaseConnectionException;
use DBench86\Exception\BenchmarkPreparationException;
use PDO;

/**
 * SQLite database driver for DBench86.
 *
 * SQLite differs fundamentally from client-server databases (MariaDB, PostgreSQL):
 * - Single-process, file-based (or in-memory)
 * - No authentication, no host/port
 * - Automatic file creation if path doesn't exist
 *
 * This driver simplifies the construction signature to accept only a file path,
 * and handles the in-process nature appropriately.
 */
final class SqliteDriver extends AbstractDriver
{
    /**
     * Human-readable name of this driver.
     */
    public readonly string $name = 'SQLite';

    /**
     * PDO driver identifier for SQLite.
     */
    public readonly string $pdoDriver = 'sqlite';

    /**
     * DBench86 operational constraint: maximum rows per batch insert.
     *
     * IMPORTANT: This is a choice made by DBench86 for this driver, NOT a limit
     * imposed by SQLite itself. SQLite supports far larger batches; this value is
     * conservative to account for SQLite's in-process nature and single-file I/O.
     *
     * This value (500) is chosen for SQLite to:
     * - Match the cautious default from AbstractDriver (single-process safety)
     * - Avoid extreme disk I/O spikes during bulk operations
     * - Keep memory footprint predictable for embedded scenarios
     *
     * Users and benchmarking code must not treat this as "SQLite's limit".
     */
    #[\Override]
    const int MAX_BATCH_SIZE = 500;

    /**
     * DBench86 operational constraint: maximum bound parameters per statement.
     *
     * IMPORTANT: This is a choice made by DBench86 for this driver, NOT a limit
     * imposed by SQLite itself.
     *
     * SQLite defines SQLITE_LIMIT_VARIABLE_NUMBER, which defaults to 32,766
     * (allowing up to 32,767 query parameters) as of SQLite 3.32.0.
     * Reference: https://www.sqlite.org/limits.html
     *
     * Note: This limit varies depending on SQLite's compile-time configuration
     * and runtime settings. The actual limit on any given system should be verified
     * if approaching this boundary.
     *
     * This value (900) is chosen for DBench86 to:
     * - Remain safely below any documented or practical limit
     * - Match realistic in-process testing scenarios
     * - Avoid edge cases with SQLite's memory and statement compilation
     *
     * Users and benchmarking code must not treat this as "SQLite's effective limit"—
     * it is a DBench86 choice to remain conservative.
     */
    #[\Override]
    const int MAX_BOUND_PARAMETERS = 900;

    /**
     * Construct a SQLite driver instance.
     *
     * @param string $path Filesystem path to SQLite database file.
     *                      Can be an absolute or relative path.
     *                      Special value ":memory:" creates an in-memory database.
     *                      If the file does not exist, SQLite will create it automatically
     *                      upon first connection.
     *
     * Example paths:
     *   "/var/lib/dbench86/benchmark.db"
     *   "./benchmark.db"
     *   ":memory:" (in-memory, not persisted)
     */
    public function __construct(
        private readonly string $path,
    ) {}

    /**
     * Establish a PDO connection to the SQLite database.
     *
     * If $path points to a file that does not exist, SQLite will create it
     * automatically. This is intentional and often desired; however, it means
     * that typos in file paths may silently create unexpected database files
     * rather than failing immediately.
     *
     * Special case ":memory:" creates an in-memory database that is isolated
     * to this connection and is destroyed when the connection closes.
     *
     * @return PDO A connected PDO instance with exception-throwing error mode.
     *
     * @throws DatabaseConnectionException if PDO initialization fails.
     */
    public function connect(): PDO
    {
        try {
            $dsn = "sqlite:{$this->path}";
            $pdo = new PDO(
                $dsn,
                username: null,
                password: null,
                options: [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]
            );
            return $pdo;
        } catch (\PDOException $e) {
            throw new DatabaseConnectionException(
                "Failed to connect to SQLite database at '{$this->path}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Reset the SQLite database schema by truncating all tables.
     *
     * Strategy: Uses DELETE FROM (equivalent to TRUNCATE for SQLite) to clear
     * all rows from all tables without dropping schema structure.
     *
     * Flow:
     * 1. Query sqlite_master to find all tables (excluding sqlite_* internal tables)
     * 2. DELETE FROM each table to clear all rows
     * 3. VACUUM to reclaim disk space (not strictly necessary but helpful for cleanup)
     *
     * Note: SQLite does not have a TRUNCATE command. DELETE is the standard way
     * to clear tables. Sequences (AUTOINCREMENT) are not reset by DELETE, so we
     * must manually update sqlite_sequence to reset auto-increment counters.
     *
     * Why not DROP TABLE? Like other drivers, we preserve schema structure for
     * performance (no need to recreate it via createSchema()).
     *
     * @param PDO $pdo Active database connection
     * @throws BenchmarkPreparationException If schema reset fails
     */
    public function resetSchema(PDO $pdo): void
    {
        try {
            // Query sqlite_master to find all user-defined tables
            $stmt = $pdo->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
            );
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // DELETE FROM each table to clear all rows
            foreach ($tables as $table) {
                $pdo->exec(sprintf('DELETE FROM "%s"', addcslashes($table, '"')));
            }

            // Reset AUTOINCREMENT sequences by clearing sqlite_sequence
            // sqlite_sequence tracks the highest ROWID for each table with AUTOINCREMENT
            $pdo->exec('DELETE FROM sqlite_sequence');

            // VACUUM to reclaim disk space
            $pdo->exec('VACUUM');
        } catch (\PDOException $e) {
            throw new BenchmarkPreparationException(
                'Failed to reset SQLite schema: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Create the complete benchmark schema (tables, indexes, constraints).
     *
     * Translates the logical Schema model (src/Dataset/Schema.php) into SQLite SQL:
     * - Auto-increment columns use INTEGER PRIMARY KEY AUTOINCREMENT
     * - Numeric columns use INTEGER (for IDs, quantities) or REAL/NUMERIC (prices)
     * - Text columns use TEXT
     * - Timestamps use TEXT (SQLite has no native timestamp type; strings in ISO format)
     * - Foreign keys are defined inline with ON DELETE RESTRICT
     * - Indexes are created via separate CREATE UNIQUE INDEX statements
     *
     * Table creation order respects foreign key dependencies:
     * 1. users (no dependencies)
     * 2. products (no dependencies)
     * 3. orders (depends on users via FK)
     * 4. order_items (depends on orders and products via FKs)
     *
     * Important SQLite-specific notes:
     * - SQLite's AUTOINCREMENT requires INTEGER PRIMARY KEY (not BIGINT)
     * - Foreign key constraints are not enabled by default; this code enables them
     * - CREATE TABLE IF NOT EXISTS is idempotent
     *
     * @param PDO $pdo Active database connection
     * @throws BenchmarkPreparationException If schema creation fails
     */
    public function createSchema(PDO $pdo): void
    {
        try {
            // Enable foreign key constraints for this session
            // SQLite disables them by default for backward compatibility
            $pdo->exec('PRAGMA foreign_keys = ON');

            // Create users table
            $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    email TEXT NOT NULL,
                    name TEXT NOT NULL,
                    created_at TEXT NOT NULL
                )
            SQL);

            // Create unique index on email
            $pdo->exec(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email ON users(email)
            SQL);

            // Create index on created_at for range queries
            $pdo->exec(<<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_users_created_at ON users(created_at)
            SQL);

            // Create products table
            $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS products (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    sku TEXT NOT NULL,
                    name TEXT NOT NULL,
                    price REAL NOT NULL
                )
            SQL);

            // Create unique index on sku
            $pdo->exec(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS idx_products_sku ON products(sku)
            SQL);

            // Create orders table (depends on users via FK)
            $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS orders (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                    status TEXT NOT NULL,
                    created_at TEXT NOT NULL
                )
            SQL);

            // Create index on user_id for lookups
            $pdo->exec(<<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_orders_user_id ON orders(user_id)
            SQL);

            // Create index on created_at for range queries
            $pdo->exec(<<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_orders_created_at ON orders(created_at)
            SQL);

            // Create order_items table (depends on orders and products via FKs)
            $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS order_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
                    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
                    quantity INTEGER NOT NULL,
                    unit_price REAL NOT NULL
                )
            SQL);

            // Create index on order_id for lookups
            $pdo->exec(<<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_order_items_order_id ON order_items(order_id)
            SQL);

            // Create index on product_id for lookups
            $pdo->exec(<<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_order_items_product_id ON order_items(product_id)
            SQL);
        } catch (\PDOException $e) {
            throw new BenchmarkPreparationException(
                'Failed to create SQLite schema: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Discover the SQLite engine version.
     *
     * Executes SELECT sqlite_version() which returns a string such as "3.39.0"
     * or "3.40.1". This is the canonical way to detect SQLite version at runtime.
     *
     * @param PDO $pdo Connected database instance.
     *
     * @return string Version string (e.g., "3.39.0").
     *
     * @throws DatabaseConnectionException if version query fails.
     */
    public function discoverEngineVersion(PDO $pdo): string
    {
        try {
            $version = $pdo->query('SELECT sqlite_version()')->fetchColumn();
            if ($version === false || $version === null) {
                throw new DatabaseConnectionException(
                    'SQLite SELECT sqlite_version() returned no result'
                );
            }
            return (string)$version;
        } catch (\PDOException $e) {
            throw new DatabaseConnectionException(
                'Failed to discover SQLite version: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get the human-readable name of this driver.
     *
     * @return string "SQLite"
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the PDO driver identifier for this driver.
     *
     * @return string "sqlite"
     */
    public function getPdoDriver(): string
    {
        return $this->pdoDriver;
    }
}
