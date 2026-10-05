<?php
declare(strict_types=1);

namespace Tests;

/**
 * Test fixture: Database connection credentials.
 *
 * INTERNAL TO TESTS ONLY. Provides read-only access to database credentials
 * for integration tests, loaded from environment variables or local config file.
 *
 * Uses the same environment variable convention as Phase C drivers for consistency.
 * NOT a part of the application (src/) — the Phase C drivers will receive
 * connection parameters via their constructors, fed by BenchmarkConfiguration.
 */
class DatabaseCredentials
{
    private static ?self $instance = null;

    public readonly string $mariadbHost;
    public readonly int $mariadbPort;
    public readonly string $mariadbDatabase;
    public readonly string $mariadbSocket;
    public readonly string $mariadbUser;
    public readonly string $mariadbPassword;

    public readonly string $postgresqlHost;
    public readonly int $postgresqlPort;
    public readonly string $postgresqlDatabase;
    public readonly string $postgresqlUser;
    public readonly string $postgresqlPassword;

    private function __construct()
    {
        // Load from local config file first (if exists)
        $config = $this->loadConfigFile();

        // MariaDB configuration (environment variables override file)
        $this->mariadbHost = $this->configuredValue('DBENCH86_MARIADB_HOST', $config) ?? '127.0.0.1';
        $this->mariadbPort = (int)($this->configuredValue('DBENCH86_MARIADB_PORT', $config) ?? '3306');
        $this->mariadbDatabase = $this->configuredValue('DBENCH86_MARIADB_DATABASE', $config) ?? 'dbench86';
        $this->mariadbSocket = $this->configuredValue('DBENCH86_MARIADB_SOCKET', $config) ?? '/run/mysqld/mysqld.sock';
        $this->mariadbUser = $this->configuredValue('DBENCH86_MARIADB_USERNAME', $config) ?? '';
        $this->mariadbPassword = $this->configuredValue('DBENCH86_MARIADB_PASSWORD', $config) ?? '';

        // PostgreSQL configuration (environment variables override file)
        $this->postgresqlHost = $this->configuredValue('DBENCH86_PGSQL_HOST', $config) ?? '127.0.0.1';
        $this->postgresqlPort = (int)($this->configuredValue('DBENCH86_PGSQL_PORT', $config) ?? '5432');
        $this->postgresqlDatabase = $this->configuredValue('DBENCH86_PGSQL_DATABASE', $config) ?? 'dbench86';
        $this->postgresqlUser = $this->configuredValue('DBENCH86_PGSQL_USERNAME', $config) ?? '';
        $this->postgresqlPassword = $this->configuredValue('DBENCH86_PGSQL_PASSWORD', $config) ?? '';
    }

    /** Process environment has the same precedence as the production factories. */
    private function configuredValue(string $name, array $config): mixed
    {
        $value = getenv($name);
        return $value === false ? ($config[$name] ?? null) : $value;
    }

    private function loadConfigFile(): array
    {
        $configFile = __DIR__ . '/../.dbench86-test-config.php';
        if (file_exists($configFile)) {
            return require $configFile;
        }
        return [];
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getMariaDBConnectionString(): string
    {
        return "mysql:unix_socket={$this->mariadbSocket};charset=utf8mb4";
    }

    public function getPostgreSQLConnectionString(): string
    {
        return "pgsql:host={$this->postgresqlHost};port={$this->postgresqlPort};dbname=postgres";
    }

    public function getSQLiteConnectionString(string $path = ':memory:'): string
    {
        return "sqlite:{$path}";
    }
}
