<?php
declare(strict_types=1);

namespace DBench86\Driver;

use PDO;
use DBench86\Exception\DatabaseConnectionException;
use DBench86\Exception\BenchmarkPreparationException;

/**
 * PostgreSQL database driver implementation.
 *
 * Provides PostgreSQL-specific connection, schema management, and version discovery.
 * All connection parameters are provided via constructor; no defaults are hardcoded.
 * Unlike MariaDB, PostgreSQL does not use Unix sockets in this implementation—
 * connections are always TCP-based to match the production-like configuration
 * (remote PostgreSQL instances are always accessed via TCP).
 */
class PostgreSqlDriver extends AbstractDriver
{
    /**
     * Human-readable name of this driver.
     * Never modified after construction.
     */
    public readonly string $name = 'PostgreSQL';

    /**
     * PDO driver name for DSN construction ("pgsql").
     * Never modified after construction.
     */
    public readonly string $pdoDriver = 'pgsql';

    /**
     * DBench86 operational constraint: maximum rows per batch insert.
     *
     * IMPORTANT: This is a choice made by DBench86 for this driver, NOT a limit
     * imposed by PostgreSQL itself. PostgreSQL supports millions of rows per statement.
     *
     * This value (5,000) is chosen for PostgreSQL to balance:
     * - Memory usage (larger than base default due to PostgreSQL's efficiency)
     * - Consistent timing (avoids extreme variance in large batch execution)
     * - Practical testing of realistic workloads
     *
     * Users and benchmarking code must not treat this as "PostgreSQL's limit".
     */
    #[\Override]
    const int MAX_BATCH_SIZE = 5_000;

    /**
     * DBench86 operational constraint: maximum bound parameters per statement.
     *
     * IMPORTANT: This is a choice made by DBench86 for this driver, NOT a limit
     * imposed by PostgreSQL itself.
     *
     * PostgreSQL supports a maximum of 32,767 positional parameters ($1, $2, ..., $32767)
     * in a single prepared statement. This is a documented hard limit.
     * Reference: https://www.postgresql.org/docs/current/sql-prepare.html
     *
     * This value (32,000) is chosen for PostgreSQL to:
     * - Reflect PostgreSQL's documented limit (32,767)
     * - Stay safely below the limit for predictable behavior
     * - Match realistic workload scenarios
     *
     * Users and benchmarking code must not treat this as "PostgreSQL's effective limit"—
     * it is a DBench86 choice to remain conservative and production-safe.
     */
    #[\Override]
    const int MAX_BOUND_PARAMETERS = 32_000;

    /**
     * Construct a PostgreSQL driver instance.
     *
     * All connection parameters are required and provided explicitly here.
     * PostgreSQL connections are always TCP-based; there is no Unix socket option
     * in this driver to ensure consistent behavior across environments.
     *
     * @param string $host Server hostname (e.g., "localhost", "127.0.0.1", "db.example.com")
     * @param int $port Server port (e.g., 5432)
     * @param string $database Database name (e.g., "benchmark")
     * @param string $username Database user (e.g., "postgres", "dbench86")
     * @param string $password User password (empty string is valid)
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password,
    ) {}

    /**
     * Establish a PDO connection to PostgreSQL.
     *
     * Connection is always TCP-based via host:port. PostgreSQL does not fall back
     * to Unix sockets; this is intentional to match production scenarios.
     *
     * Error handling is set to throw exceptions immediately.
     *
     * @return PDO Active PostgreSQL connection
     * @throws DatabaseConnectionException If connection fails
     */
    public function connect(): PDO
    {
        try {
            // PostgreSQL DSN: always TCP via host:port
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $this->host,
                $this->port,
                $this->database
            );

            $pdo = new PDO(
                $dsn,
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]
            );

            return $pdo;
        } catch (\PDOException $e) {
            throw new DatabaseConnectionException(
                sprintf(
                    'Failed to connect to PostgreSQL at %s:%d: %s',
                    $this->host,
                    $this->port,
                    $e->getMessage()
                ),
                0,
                $e
            );
        }
    }

    /**
     * Reset the PostgreSQL database schema by truncating all tables.
     *
     * Strategy: Uses TRUNCATE CASCADE (fast, resets sequences) rather than
     * DROP TABLE, preserving schema structure for performance.
     *
     * Flow:
     * 1. Query pg_tables to find all tables in the 'public' schema
     * 2. TRUNCATE each table with CASCADE (handles foreign keys automatically)
     * 3. Query pg_sequences to find all sequences in 'public' schema
     * 4. ALTER SEQUENCE ... RESTART WITH 1 to reset auto-increment to initial value
     *
     * Why TRUNCATE CASCADE? PostgreSQL's TRUNCATE CASCADE atomically deletes all
     * rows and respects foreign key relationships through the CASCADE clause.
     * It's faster than DELETE for large tables. The sequence reset ensures that
     * subsequent insertions start from ID 1 again.
     *
     * @param PDO $pdo Active database connection
     * @throws BenchmarkPreparationException If schema reset fails
     */
    public function resetSchema(PDO $pdo): void
    {
        try {
            // Get all tables in the 'public' schema (PostgreSQL default schema)
            $stmt = $pdo->query(
                "SELECT tablename FROM pg_tables WHERE schemaname = 'public'"
            );
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // TRUNCATE each table with CASCADE to handle foreign key relationships
            foreach ($tables as $table) {
                $pdo->exec(sprintf('TRUNCATE TABLE "%s" CASCADE', addcslashes($table, '"')));
            }

            // Reset all sequences to their initial state (1)
            // This ensures that subsequent inserts start from ID 1
            $stmt = $pdo->query(
                "SELECT sequencename FROM pg_sequences WHERE schemaname = 'public'"
            );
            $sequences = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($sequences as $sequence) {
                $pdo->exec(sprintf('ALTER SEQUENCE "%s" RESTART WITH 1', addcslashes($sequence, '"')));
            }
        } catch (\PDOException $e) {
            throw new BenchmarkPreparationException(
                'Failed to reset PostgreSQL schema: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Create the complete benchmark schema (tables, indexes, constraints).
     *
     * Translates the logical Schema model (src/Dataset/Schema.php) into PostgreSQL SQL:
     * - Auto-increment columns use SERIAL (integer) or BIGSERIAL (BIGINT equivalent)
     * - Numeric columns use BIGINT (IDs) or NUMERIC(10,2) (prices)
     * - Text columns use VARCHAR or TEXT as appropriate
     * - Timestamps use TIMESTAMP WITH TIME ZONE
     * - Foreign keys are defined inline with ON DELETE RESTRICT
     * - Indexes are created via separate CREATE UNIQUE INDEX or create_index clauses
     * - Constraints are named for easier debugging
     *
     * Table creation order respects foreign key dependencies:
     * 1. users (no dependencies)
     * 2. products (no dependencies)
     * 3. orders (depends on users via FK)
     * 4. order_items (depends on orders and products via FKs)
     *
     * Note: PostgreSQL supports IF NOT EXISTS to avoid errors on re-creation,
     * allowing resetSchema() + createSchema() to be idempotent.
     *
     * @param PDO $pdo Active database connection
     * @throws BenchmarkPreparationException If schema creation fails
     */
    public function createSchema(PDO $pdo): void
    {
        try {
            // Create users table
            $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS users (
                    id BIGSERIAL PRIMARY KEY,
                    email VARCHAR(255) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
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
                    id BIGSERIAL PRIMARY KEY,
                    sku VARCHAR(255) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    price NUMERIC(10, 2) NOT NULL
                )
            SQL);

            // Create unique index on sku
            $pdo->exec(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS idx_products_sku ON products(sku)
            SQL);

            // Create orders table (depends on users)
            $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS orders (
                    id BIGSERIAL PRIMARY KEY,
                    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                    status VARCHAR(50) NOT NULL,
                    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
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

            // Create order_items table (depends on orders and products)
            $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS order_items (
                    id BIGSERIAL PRIMARY KEY,
                    order_id BIGINT NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
                    product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
                    quantity INT NOT NULL,
                    unit_price NUMERIC(10, 2) NOT NULL
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
                'Failed to create PostgreSQL schema: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Discover the PostgreSQL engine version.
     *
     * Executes SHOW server_version and returns the version string.
     * SHOW server_version returns a direct numeric version string (e.g., "14.1")
     * rather than SELECT version() which includes full descriptive text.
     *
     * This choice makes the version string more predictable and easier to parse
     * for version comparison logic.
     *
     * Example returns:
     * - "14.1"
     * - "13.5"
     * - "12.9"
     *
     * @param PDO $pdo Active database connection
     * @return string Version string from SHOW server_version
     * @throws DatabaseConnectionException If version query fails
     */
    public function discoverEngineVersion(PDO $pdo): string
    {
        try {
            // SHOW server_version returns a clean numeric version string
            // E.g., "14.1" rather than "PostgreSQL 14.1 on ..." from SELECT version()
            $version = $pdo->query('SHOW server_version')->fetchColumn();
            if ($version === false || $version === null) {
                throw new DatabaseConnectionException(
                    'PostgreSQL SHOW server_version returned no result'
                );
            }
            return (string)$version;
        } catch (\PDOException $e) {
            throw new DatabaseConnectionException(
                'Failed to discover PostgreSQL version: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get the human-readable name of this driver.
     *
     * @return string "PostgreSQL"
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the PDO driver name for DSN construction.
     *
     * @return string "pgsql"
     */
    public function getPdoDriver(): string
    {
        return $this->pdoDriver;
    }
}
