<?php
declare(strict_types=1);

namespace DBench86\Driver;

use PDO;
use DBench86\Exception\DatabaseConnectionException;
use DBench86\Exception\BenchmarkPreparationException;

/**
 * MariaDB database driver implementation.
 *
 * Provides MariaDB-specific connection, schema management, and version discovery.
 * All connection parameters are provided via constructor; no defaults are hardcoded
 * to avoid surprises (e.g., silent Unix socket fallback when TCP is intended).
 */
class MariaDbDriver extends AbstractDriver
{
    /**
     * Human-readable name of this driver.
     * Never modified after construction.
     */
    public readonly string $name = 'MariaDB';

    /**
     * PDO driver name for DSN construction ("mysql").
     * Never modified after construction.
     */
    public readonly string $pdoDriver = 'mysql';

    /**
     * DBench86 operational constraint: maximum rows per batch insert.
     *
     * IMPORTANT: This is a choice made by DBench86 for this driver, NOT a limit
     * imposed by MariaDB itself. MariaDB supports millions of rows per statement.
     *
     * This value (2,000) is chosen for MariaDB to balance:
     * - Memory usage (larger batches than PostgreSQL due to MariaDB's efficiency)
     * - Consistent timing (avoids extreme variance in large batch execution)
     * - Debugging clarity
     *
     * Users and benchmarking code must not treat this as "MariaDB's limit".
     */
    #[\Override]
    const int MAX_BATCH_SIZE = 2_000;

    /**
     * DBench86 operational constraint: maximum bound parameters per statement.
     *
     * IMPORTANT: This is a choice made by DBench86 for this driver, NOT a limit
     * imposed by MariaDB itself.
     *
     * MariaDB accepts up to 2^16 (65,536) placeholders in a single prepared statement.
     * Reference: https://mariadb.com/kb/en/library/prepare-statement/
     *
     * This value (50,000) is chosen for MariaDB to:
     * - Reflect MariaDB's strong capacity for large parameter sets
     * - Remain conservative for production safety
     * - Balance memory usage in the driver and PDO layer
     *
     * Users and benchmarking code must not treat this as "MariaDB's limit".
     */
    #[\Override]
    const int MAX_BOUND_PARAMETERS = 50_000;

    /**
     * Construct a MariaDB driver instance.
     *
     * All connection parameters are required and provided explicitly here.
     * No defaults are inferred to avoid silent fallbacks (e.g., Unix socket when
     * TCP is intended).
     *
     * @param string $host Server hostname (e.g., "localhost", "127.0.0.1", "db.example.com")
     * @param int $port Server port (e.g., 3306)
     * @param string $database Database name (e.g., "benchmark")
     * @param string $username Database user (e.g., "root")
     * @param string $password User password (empty string is valid)
     * @param ?string $socket Unix socket path (e.g., "/run/mysqld/mysqld.sock")
     *                        If provided, takes precedence over host/port for connection.
     *                        If null, TCP via host/port is used explicitly.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password,
        private readonly ?string $socket = null,
    ) {}

    /**
     * Establish a PDO connection to MariaDB.
     *
     * Connection method:
     * - If $socket is provided, uses Unix socket connection
     * - Otherwise, uses explicit TCP via host:port (never relies on PDO's "localhost" heuristic)
     *
     * Error handling is set to throw exceptions immediately.
     *
     * @return PDO Active MariaDB connection
     * @throws DatabaseConnectionException If connection fails
     */
    public function connect(): PDO
    {
        try {
            // Determine DSN based on connection method
            if ($this->socket !== null) {
                // Unix socket connection
                $dsn = sprintf(
                    'mysql:unix_socket=%s;dbname=%s;charset=utf8mb4',
                    $this->socket,
                    $this->database
                );
            } else {
                // Explicit TCP connection (host:port)
                // This avoids PDO's implicit Unix socket fallback when host="localhost"
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $this->host,
                    $this->port,
                    $this->database
                );
            }

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
                    'Failed to connect to MariaDB at %s: %s',
                    $this->socket ?? sprintf('%s:%d', $this->host, $this->port),
                    $e->getMessage()
                ),
                0,
                $e
            );
        }
    }

    /**
     * Reset the database schema by truncating all tables.
     *
     * Strategy: Uses TRUNCATE (fast, resets AUTO_INCREMENT counters) rather than
     * DROP TABLE, preserving the schema structure for performance.
     *
     * Flow:
     * 1. Disable foreign key constraint checking (SET FOREIGN_KEY_CHECKS = 0)
     * 2. Query INFORMATION_SCHEMA for all base tables in the database
     * 3. TRUNCATE each table (fast, resets auto_increment to 1)
     * 4. Re-enable foreign key checks
     *
     * Why TRUNCATE? MariaDB's TRUNCATE is faster than DELETE for large tables
     * and automatically resets AUTO_INCREMENT to 1, making it ideal for benchmark
     * resets. DROP TABLE would require re-creating the schema via createSchema().
     *
     * @param PDO $pdo Active database connection
     * @throws BenchmarkPreparationException If schema reset fails
     */
    public function resetSchema(PDO $pdo): void
    {
        try {
            // Disable foreign key checks to allow TRUNCATE of tables with FK relationships
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            // Query INFORMATION_SCHEMA to get all tables in the current database
            $stmt = $pdo->query(
                'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE"'
            );
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // TRUNCATE each table (fast, resets auto_increment)
            foreach ($tables as $table) {
                $pdo->exec(sprintf('TRUNCATE TABLE `%s`', addcslashes($table, '`')));
            }

            // Re-enable foreign key checks
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (\PDOException $e) {
            throw new BenchmarkPreparationException(
                'Failed to reset MariaDB schema: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Create the complete benchmark schema (tables, indexes, constraints).
     *
     * Translates the logical Schema model (src/Dataset/Schema.php) into MariaDB SQL:
     * - Auto-increment columns use AUTO_INCREMENT with PRIMARY KEY
     * - Numeric columns use BIGINT (IDs, quantities) or DECIMAL (prices, money)
     * - Text columns use VARCHAR or TEXT as appropriate
     * - Timestamps use DATETIME with default CURRENT_TIMESTAMP
     * - Foreign keys are defined inline with ON DELETE RESTRICT (preserves integrity)
     * - Indexes are created separately (unique constraints use UNIQUE KEY)
     *
     * Table creation order respects foreign key dependencies:
     * 1. users (no dependencies)
     * 2. products (no dependencies)
     * 3. orders (depends on users via FK)
     * 4. order_items (depends on orders and products via FKs)
     *
     * @param PDO $pdo Active database connection
     * @throws BenchmarkPreparationException If schema creation fails
     */
    public function createSchema(PDO $pdo): void
    {
        try {
            // Disable foreign key checks during creation (all tables will exist before data)
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            // Create users table
            $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `users` (
                    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                    `email` VARCHAR(255) NOT NULL,
                    `name` VARCHAR(255) NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `idx_users_email` (`email`),
                    KEY `idx_users_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

            // Create products table
            $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `products` (
                    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                    `sku` VARCHAR(255) NOT NULL,
                    `name` VARCHAR(255) NOT NULL,
                    `price` DECIMAL(10, 2) NOT NULL,
                    UNIQUE KEY `idx_products_sku` (`sku`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

            // Create orders table (depends on users)
            $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `orders` (
                    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                    `user_id` BIGINT NOT NULL,
                    `status` VARCHAR(50) NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY `idx_orders_user_id` (`user_id`),
                    KEY `idx_orders_created_at` (`created_at`),
                    CONSTRAINT `fk_orders_user_id` FOREIGN KEY (`user_id`)
                        REFERENCES `users` (`id`) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

            // Create order_items table (depends on orders and products)
            $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `order_items` (
                    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                    `order_id` BIGINT NOT NULL,
                    `product_id` BIGINT NOT NULL,
                    `quantity` INT NOT NULL,
                    `unit_price` DECIMAL(10, 2) NOT NULL,
                    KEY `idx_order_items_order_id` (`order_id`),
                    KEY `idx_order_items_product_id` (`product_id`),
                    CONSTRAINT `fk_order_items_order_id` FOREIGN KEY (`order_id`)
                        REFERENCES `orders` (`id`) ON DELETE RESTRICT,
                    CONSTRAINT `fk_order_items_product_id` FOREIGN KEY (`product_id`)
                        REFERENCES `products` (`id`) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

            // Re-enable foreign key checks
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (\PDOException $e) {
            throw new BenchmarkPreparationException(
                'Failed to create MariaDB schema: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Discover the MariaDB engine version.
     *
     * Executes SELECT VERSION() and returns the raw version string from the server.
     * This is the authoritative version as reported by the engine itself, not a
     * version derived from the PDO client library.
     *
     * Example returns:
     * - "10.6.4-MariaDB"
     * - "10.5.10-MariaDB"
     * - "8.0.26-0ubuntu0.20.04.1"
     *
     * @param PDO $pdo Active database connection
     * @return string Version string from SELECT VERSION()
     * @throws DatabaseConnectionException If version query fails
     */
    public function discoverEngineVersion(PDO $pdo): string
    {
        try {
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            if ($version === false || $version === null) {
                throw new DatabaseConnectionException(
                    'MariaDB SELECT VERSION() returned no result'
                );
            }
            return (string)$version;
        } catch (\PDOException $e) {
            throw new DatabaseConnectionException(
                'Failed to discover MariaDB version: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get the human-readable name of this driver.
     *
     * @return string "MariaDB"
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the PDO driver name for DSN construction.
     *
     * @return string "mysql"
     */
    public function getPdoDriver(): string
    {
        return $this->pdoDriver;
    }
}
