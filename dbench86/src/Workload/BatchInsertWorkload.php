<?php
declare(strict_types=1);

namespace DBench86\Workload;

use DBench86\Benchmark\BatchLimitCalculator;
use DBench86\Dataset\DeterministicGenerator;
use DBench86\Driver\DatabaseDriver;
use PDO;

final class BatchInsertWorkload implements Workload
{
    private \PDOStatement|null $statement = null;
    private DeterministicGenerator|null $generator = null;
    private int $executionCount = 0;
    private int $effectiveBatchSize = 100;
    private int $parametersPerRow = 3;
    private int $requestedBatchSize = 100;
    private readonly ?DatabaseDriver $driver;

    /**
     * @param DatabaseDriver|null $driver Database driver for calculating batch limits.
     *        If null, this instance can only be used to retrieve its name() for listing.
     *        Calling prepare() or execute() without a driver will raise an exception.
     * @param int $requestedBatchSize Desired batch size (will be clamped to driver limits)
     */
    public function __construct(?DatabaseDriver $driver = null, int $requestedBatchSize = 100)
    {
        $this->requestedBatchSize = $requestedBatchSize;
        $this->driver = $driver;
    }

    public function name(): string
    {
        return 'batch_insert';
    }

    public function prepare(PDO $pdo): void
    {
        if ($this->driver === null) {
            throw new \RuntimeException(
                'BatchInsertWorkload requires a DatabaseDriver to execute. '
                . 'This workload was instantiated in listing mode (driver=null). '
                . 'Use getAllWorkloads($driver) to get executable workloads.'
            );
        }

        $this->generator = new DeterministicGenerator(88888);
        $this->executionCount = 0;

        // For INSERT INTO products (sku, name, price): exactly 3 parameters per row
        $this->parametersPerRow = 3;

        // Calculate effective batch size using the driver instance (always available)
        $driverLimit = BatchLimitCalculator::driverLimit(
            $this->driver,
            $this->parametersPerRow
        );

        // Clamp requested batch size to calculated limit
        $this->effectiveBatchSize = BatchLimitCalculator::effectiveBatchSize(
            $this->requestedBatchSize,
            $driverLimit
        );

        // Prepare placeholder for batch insert
        $placeholders = implode(', ', array_fill(0, $this->effectiveBatchSize, '(?, ?, ?)'));

        $sql = 'INSERT INTO products (sku, name, price) VALUES ' . $placeholders;
        $this->statement = $pdo->prepare($sql);
    }

    public function execute(PDO $pdo): int
    {
        if ($this->statement === null || $this->generator === null) {
            throw new \RuntimeException('Workload not prepared');
        }

        $params = [];
        for ($i = 0; $i < $this->effectiveBatchSize; $i++) {
            $params[] = $this->generator->nextString(16);
            $params[] = $this->generator->nextString(50);
            $params[] = round($this->generator->nextFloat(1.0, 999.99), 2);
        }

        $this->statement->execute($params);
        $this->executionCount += $this->effectiveBatchSize;


        return $this->effectiveBatchSize;
    }

    public function cleanup(PDO $pdo): void
    {
        if ($this->executionCount > 0) {
            // Get the IDs of inserted products
            $lastInsertedIds = $pdo->query(
                'SELECT id FROM products ORDER BY id DESC LIMIT ' . $this->executionCount
            )->fetchAll(\PDO::FETCH_COLUMN);

            if (!empty($lastInsertedIds)) {
                $idList = implode(',', $lastInsertedIds);
                // First delete order_items that reference these products (FK constraint)
                $pdo->exec("DELETE FROM order_items WHERE product_id IN ({$idList})");
                // Then delete the products themselves
                $pdo->exec("DELETE FROM products WHERE id IN ({$idList})");
            }
        }
    }

    public function boundParametersPerRow(): ?int
    {
        return $this->parametersPerRow;
    }
}
