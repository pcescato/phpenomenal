<?php
declare(strict_types=1);

namespace Tests\Integration;

// Verify full timed consumption while preserving each driver's delivery defaults.

use DBench86\Application;
use DBench86\Benchmark\WorkloadTimer;
use DBench86\Workload\{DeterministicSamples, RangeSelectWorkload};
use PDO;
use Pdo\Mysql;
use Tests\TestCase;
use Time\Duration;

final class RangeConsumptionStatement extends \PDOStatement
{
    protected function __construct(private \stdClass $consumption) {}

    public function execute(?array $params = null): bool
    {
        if ($this->consumption->active) {
            $this->consumption->executions++;
            $this->consumption->sql = $this->queryString;
        }
        return parent::execute($params);
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        if ($this->consumption->active) {
            if ($row === false) {
                $this->consumption->eof++;
            } else {
                $this->consumption->rows++;
            }
        }
        return $row;
    }
}

final class RangeConsumptionTest extends TestCase
{
    public function run(): void
    {
        $app = new Application();
        foreach (['mariadb', 'postgresql', 'sqlite'] as $engine) {
            if ($engine !== 'sqlite' && !getenv($engine === 'mariadb' ? 'DBENCH86_MARIADB_USERNAME' : 'DBENCH86_PGSQL_USERNAME')) {
                $this->skip("{$engine} credentials unavailable for range consumption test");
                continue;
            }
            $driver = (new \ReflectionMethod($app, 'createDriver'))->invoke($app, $engine);
            $pdo = (new \ReflectionMethod($app, 'populateDataset'))->invoke($app, $driver, 2048);
            $times = $pdo->query('SELECT created_at FROM users ORDER BY created_at')->fetchAll(PDO::FETCH_COLUMN);
            $consumption = (object)['active' => false, 'executions' => 0, 'rows' => 0, 'eof' => 0, 'sql' => ''];
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RangeConsumptionStatement::class, [$consumption]]);
            $buffered = $engine === 'mariadb' ? $pdo->getAttribute(Mysql::ATTR_USE_BUFFERED_QUERY) : null;
            $range = new RangeSelectWorkload(new DeterministicSamples(35));
            try {
                $range->prepare($pdo);
                if ($engine === 'mariadb') {
                    $this->assertEquals($buffered, $pdo->getAttribute(Mysql::ATTR_USE_BUFFERED_QUERY), 'Range preparation leaves MariaDB buffering unchanged');
                }
                for ($i = 0; $i < 35; $i++) {
                    $count = $pdo->prepare('SELECT COUNT(*) FROM users WHERE created_at BETWEEN ? AND ?');
                    $count->execute([$times[$i], $times[$i + 204]]);
                    $expected = (int)$count->fetchColumn();
                    $count->closeCursor();
                    $this->assertEquals($expected, $range->execute($pdo), "{$engine} consumes exactly the original range result");
                }
                foreach ([0, 1, 511, 512, 513, 1023, 1024, 1025] as $target) {
                    $window = $target === 0 ? ['1900-01-01 00:00:00', '1900-01-01 00:00:00'] : [$times[0], $times[$target - 1]];
                    $count->execute($window);
                    $expected = (int)$count->fetchColumn();
                    $count->closeCursor();
                    (new \ReflectionProperty($range, 'windows'))->setValue($range, [$window]);
                    $consumption->executions = $consumption->rows = $consumption->eof = 0;
                    $consumption->active = true;
                    $measurement = (new WorkloadTimer(Duration::fromSeconds(30)))->run($range, $pdo);
                    $consumption->active = false;
                    $this->assertTrue(!$measurement->failed && !$measurement->timedOut, 'Range timing succeeds');
                    $this->assertEquals($expected, $measurement->rowsAffected, 'Timer encloses the complete result');
                    $this->assertEquals($expected, $consumption->rows, 'Every returned row is consumed');
                    $this->assertEquals(1, $consumption->eof, 'EOF reached before timer returns, including empty and batch boundary cases');
                    $this->assertEquals(1, $consumption->executions, 'Exactly one SELECT execution per measured range');
                    $this->assertEquals('SELECT id, email, name, created_at FROM users WHERE created_at BETWEEN ? AND ?', $consumption->sql, 'Plain SELECT is identical on every engine');
                }
                $this->assertTrue(!$pdo->inTransaction(), 'Range leaves no open transaction');
            } finally {
                $range->cleanup($pdo);
            }
            if ($engine === 'mariadb') {
                $this->assertEquals($buffered, $pdo->getAttribute(Mysql::ATTR_USE_BUFFERED_QUERY), 'Execution and cleanup leave MariaDB buffering unchanged');
            }
            $this->assertEquals(1, (int)$pdo->query('SELECT 1')->fetchColumn(), 'Connection remains usable');
        }
    }
}
(new RangeConsumptionTest())->run();
