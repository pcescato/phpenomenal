<?php
declare(strict_types=1);

namespace DBench86\Workload;

use PDO;

final class JoinWorkload implements Workload
{
    private array $orderIds = [];
    private int $currentIndex = 0;
    private \PDOStatement|null $statement = null;

    public function __construct(private readonly DeterministicSamples $samples = new DeterministicSamples()) {}

    public function name(): string
    {
        return 'join';
    }

    public function prepare(PDO $pdo): void
    {
        $this->orderIds = $this->samples->tableIds($pdo, 'orders', $this->name());

        if (empty($this->orderIds)) {
            throw new \RuntimeException('No orders found in database for JoinWorkload');
        }

        // Join orders, users, order_items, and products to test multi-table joins
        $this->statement = $pdo->prepare(
            'SELECT o.id as order_id, u.id as user_id, u.email, o.created_at,
                    COUNT(oi.id) as item_count, SUM(oi.quantity) as total_quantity
             FROM orders o
             JOIN users u ON o.user_id = u.id
             LEFT JOIN order_items oi ON o.id = oi.order_id
             WHERE o.id = ?
             GROUP BY o.id, u.id, u.email, o.created_at'
        );
        $this->currentIndex = 0;
    }

    public function execute(PDO $pdo): int
    {
        if ($this->statement === null) {
            throw new \RuntimeException('Workload not prepared');
        }

        $orderId = $this->orderIds[$this->currentIndex % count($this->orderIds)];
        $this->currentIndex++;

        $this->statement->execute([$orderId]);
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
