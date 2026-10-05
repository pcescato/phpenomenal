<?php
declare(strict_types=1);

namespace DBench86\Workload;

use PDO;

final class AggregationWorkload implements Workload
{
    private \PDOStatement|null $statement = null;
    private int $executionCount = 0;

    public function name(): string
    {
        return 'aggregation';
    }

    public function prepare(PDO $pdo): void
    {
        // Verify that there are orders to aggregate
        $result = $pdo->query('SELECT COUNT(*) FROM orders');
        $count = $result->fetchColumn();

        if ($count === 0 || $count === false) {
            throw new \RuntimeException('No orders found in database for AggregationWorkload');
        }

        // Aggregation query: count items and sum quantities per order status
        // This tests GROUP BY, COUNT, and SUM aggregates
        $this->statement = $pdo->prepare(
            'SELECT o.status,
                    COUNT(DISTINCT o.id) as order_count,
                    COUNT(DISTINCT oi.id) as item_count,
                    SUM(COALESCE(oi.quantity, 0)) as total_quantity,
                    AVG(COALESCE(oi.unit_price, 0)) as avg_price
             FROM orders o
             LEFT JOIN order_items oi ON o.id = oi.order_id
             WHERE o.status IN (?, ?)
             GROUP BY o.status'
        );
        $this->executionCount = 0;
    }

    public function execute(PDO $pdo): int
    {
        if ($this->statement === null) {
            throw new \RuntimeException('Workload not prepared');
        }

        // Cycle through different status combinations on each execution
        $statuses = ['pending', 'completed', 'cancelled'];
        $status1 = $statuses[$this->executionCount % count($statuses)];
        $status2 = $statuses[($this->executionCount + 1) % count($statuses)];
        $this->executionCount++;

        $this->statement->execute([$status1, $status2]);
        $rows = $this->statement->fetchAll(\PDO::FETCH_ASSOC);
        $rowCount = count($rows);


        return $rowCount;
    }

    public function cleanup(PDO $pdo): void
    {
    }

    public function boundParametersPerRow(): ?int
    {
        return null;
    }
}
