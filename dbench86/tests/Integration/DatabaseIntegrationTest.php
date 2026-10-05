<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use Tests\DatabaseCredentials;
use PDO;

class DatabaseIntegrationTest extends TestCase
{
    private DatabaseCredentials $credentials;

    public function __construct()
    {
        parent::__construct();
        $this->credentials = DatabaseCredentials::getInstance();
    }

    public function testMariaDBConnection(): void
    {
        if (DatabaseCredentials::getInstance()->mariadbUser === '') {
            $this->skip('Explicit database username unavailable');
            return;
        }
        try {
            $pdo = new PDO(
                $this->credentials->getMariaDBConnectionString(),
                $this->credentials->mariadbUser,
                $this->credentials->mariadbPassword ?? ""
            );
            $value = $pdo->query("SELECT 1")->fetchColumn();
            $this->assertEquals(1, (int)$value, "MariaDB SELECT 1 should return 1");
        } catch (\PDOException $e) {
            $this->skip("MariaDB not available: " . $e->getMessage());
        }
    }

    public function testPostgreSQLConnection(): void
    {
        if (DatabaseCredentials::getInstance()->postgresqlUser === '') {
            $this->skip('Explicit database username unavailable');
            return;
        }
        try {
            $pdo = new PDO(
                $this->credentials->getPostgreSQLConnectionString(),
                $this->credentials->postgresqlUser,
                $this->credentials->postgresqlPassword,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $value = $pdo->query("SELECT 1")->fetchColumn();
            $this->assertEquals(1, (int)$value, "PostgreSQL SELECT 1 should return 1");
        } catch (\PDOException $e) {
            $this->skip("PostgreSQL not available: " . $e->getMessage());
        }
    }

    public function testSQLiteConnection(): void
    {
        try {
            $tmpdb = tempnam(sys_get_temp_dir(), 'test_');
            $pdo = new PDO($this->credentials->getSQLiteConnectionString($tmpdb));
            $value = $pdo->query("SELECT 1")->fetchColumn();
            @unlink($tmpdb);
            $this->assertEquals(1, (int)$value, "SQLite SELECT 1 should return 1");
        } catch (\PDOException $e) {
            $this->skip("SQLite not available: " . $e->getMessage());
        }
    }

    public function run(): void
    {
        echo "\nDatabaseIntegrationTest\n";
        echo "───────────────────────\n";
        $this->testMariaDBConnection();
        $this->testPostgreSQLConnection();
        $this->testSQLiteConnection();
    }
}

$test = new DatabaseIntegrationTest();
$test->run();
