<?php
declare(strict_types=1);

namespace Tests\Unit;

use DBench86\Dataset\DatasetGenerator;
use DBench86\Dataset\DeterministicGenerator;
use Tests\TestCase;

/**
 * Unit tests for DatasetGenerator.
 *
 * Verifies:
 * - Determinism: same seed → identical data sequences
 * - Divergence: different seeds → different data
 * - Referential integrity: all foreign keys reference existing records
 * - Cardinality: realistic distribution across tables
 * - Generator semantics: proper use of PHP iterables (yield)
 */
final class DatasetGeneratorTest extends TestCase
{
    public function run(): void
    {
        // Determinism and reproducibility tests
        $this->testSameSeedProducesSameUsers();
        $this->testSameSeedProducesSameProducts();
        $this->testSameSeedProducesSameOrders();
        $this->testSameSeedProducesSameOrderItems();
        $this->testSameSeedProducesIdenticalFullDatasets();

        // Divergence tests
        $this->testDifferentSeedsProduceDifferentUsers();
        $this->testDifferentSeedsProduceDifferentProducts();

        // Referential integrity tests
        $this->testAllOrderUserIdsExist();
        $this->testAllOrderItemOrderIdsExist();
        $this->testAllOrderItemProductIdsExist();

        // Cardinality and structure tests
        $this->testUserCountMatchesRequestedRows();
        $this->testProductCountIsScaledByProductScaleFactor();
        $this->testOrderCountIsScaledByOrderScaleFactor();
        $this->testOrderItemsHaveRealisticCardinality();
        $this->testUserEmailsAreUnique();
        $this->testProductSkusAreUnique();

        // Edge cases and error handling
        $this->testThrowsOnZeroRequestedRows();
        $this->testThrowsOnNegativeRequestedRows();
        $this->testOrdersThrowsOnEmptyUserIds();
        $this->testOrderItemsThrowsOnEmptyOrderIds();
        $this->testOrderItemsThrowsOnEmptyProductIds();

        // Iterator behavior tests
        $this->testGeneratorsAreReusableIterables();
        $this->testGeneratorsYieldConsistentKeyOrdering();
    }

    private function testSameSeedProducesSameUsers(): void
    {
        $seed = 1001;
        $rows = 100;

        $gen1 = new DeterministicGenerator($seed);
        $gen2 = new DeterministicGenerator($seed);
        $dataset1 = new DatasetGenerator($gen1, $rows);
        $dataset2 = new DatasetGenerator($gen2, $rows);

        $users1 = iterator_to_array($dataset1->generateUsers());
        $users2 = iterator_to_array($dataset2->generateUsers());

        $this->assertTrue(
            $users1 === $users2,
            'Same seed must produce identical users array'
        );
    }

    private function testSameSeedProducesSameProducts(): void
    {
        $seed = 2002;
        $rows = 100;

        $gen1 = new DeterministicGenerator($seed);
        $gen2 = new DeterministicGenerator($seed);
        $dataset1 = new DatasetGenerator($gen1, $rows);
        $dataset2 = new DatasetGenerator($gen2, $rows);

        $products1 = iterator_to_array($dataset1->generateProducts());
        $products2 = iterator_to_array($dataset2->generateProducts());

        $this->assertTrue(
            $products1 === $products2,
            'Same seed must produce identical products array'
        );
    }

    private function testSameSeedProducesSameOrders(): void
    {
        $seed = 3003;
        $rows = 100;

        $gen1 = new DeterministicGenerator($seed);
        $gen2 = new DeterministicGenerator($seed);
        $dataset1 = new DatasetGenerator($gen1, $rows);
        $dataset2 = new DatasetGenerator($gen2, $rows);

        // Generate users first to get valid user IDs
        $users = iterator_to_array($dataset1->generateUsers());
        $userIds = array_keys($users);

        // Now generate orders with the same seed state
        $gen1 = new DeterministicGenerator($seed);
        $gen2 = new DeterministicGenerator($seed);
        $dataset1 = new DatasetGenerator($gen1, $rows);
        $dataset2 = new DatasetGenerator($gen2, $rows);

        iterator_to_array($dataset1->generateUsers());
        iterator_to_array($dataset2->generateUsers());

        $orders1 = iterator_to_array($dataset1->generateOrders($userIds));
        $orders2 = iterator_to_array($dataset2->generateOrders($userIds));

        $this->assertTrue(
            $orders1 === $orders2,
            'Same seed must produce identical orders array'
        );
    }

    private function testSameSeedProducesSameOrderItems(): void
    {
        $seed = 4004;
        $rows = 100;

        $gen1 = new DeterministicGenerator($seed);
        $gen2 = new DeterministicGenerator($seed);
        $dataset1 = new DatasetGenerator($gen1, $rows);
        $dataset2 = new DatasetGenerator($gen2, $rows);

        // Generate all prerequisite data
        $users1 = iterator_to_array($dataset1->generateUsers());
        $products1 = iterator_to_array($dataset1->generateProducts());
        $orders1 = iterator_to_array($dataset1->generateOrders(array_keys($users1)));

        $users2 = iterator_to_array($dataset2->generateUsers());
        $products2 = iterator_to_array($dataset2->generateProducts());
        $orders2 = iterator_to_array($dataset2->generateOrders(array_keys($users2)));

        // Now test order items
        $items1 = iterator_to_array($dataset1->generateOrderItems(array_keys($orders1), array_keys($products1)));
        $items2 = iterator_to_array($dataset2->generateOrderItems(array_keys($orders2), array_keys($products2)));

        $this->assertTrue(
            $items1 === $items2,
            'Same seed must produce identical order items array'
        );
    }

    private function testSameSeedProducesIdenticalFullDatasets(): void
    {
        $seed = 5555;
        $rows = 50;

        // First execution
        $gen1 = new DeterministicGenerator($seed);
        $dataset1 = new DatasetGenerator($gen1, $rows);
        $users1 = iterator_to_array($dataset1->generateUsers());
        $products1 = iterator_to_array($dataset1->generateProducts());
        $orders1 = iterator_to_array($dataset1->generateOrders(array_keys($users1)));
        $items1 = iterator_to_array($dataset1->generateOrderItems(array_keys($orders1), array_keys($products1)));

        // Second execution with same seed
        $gen2 = new DeterministicGenerator($seed);
        $dataset2 = new DatasetGenerator($gen2, $rows);
        $users2 = iterator_to_array($dataset2->generateUsers());
        $products2 = iterator_to_array($dataset2->generateProducts());
        $orders2 = iterator_to_array($dataset2->generateOrders(array_keys($users2)));
        $items2 = iterator_to_array($dataset2->generateOrderItems(array_keys($orders2), array_keys($products2)));

        $this->assertTrue(
            $users1 === $users2 && $products1 === $products2 && $orders1 === $orders2 && $items1 === $items2,
            'Same seed must produce identical full dataset across all tables'
        );
    }

    private function testDifferentSeedsProduceDifferentUsers(): void
    {
        $rows = 50;

        $gen1 = new DeterministicGenerator(100);
        $gen2 = new DeterministicGenerator(200);
        $dataset1 = new DatasetGenerator($gen1, $rows);
        $dataset2 = new DatasetGenerator($gen2, $rows);

        $users1 = iterator_to_array($dataset1->generateUsers());
        $users2 = iterator_to_array($dataset2->generateUsers());

        // Count differences
        $differences = 0;
        foreach ($users1 as $id => $user1) {
            $user2 = $users2[$id];
            if ($user1 !== $user2) {
                $differences++;
            }
        }

        $this->assertTrue(
            $differences > 40,  // Expect >80% difference
            "Different seeds should produce significantly different users, got {$differences} differences"
        );
    }

    private function testDifferentSeedsProduceDifferentProducts(): void
    {
        $rows = 50;

        $gen1 = new DeterministicGenerator(300);
        $gen2 = new DeterministicGenerator(400);
        $dataset1 = new DatasetGenerator($gen1, $rows);
        $dataset2 = new DatasetGenerator($gen2, $rows);

        $products1 = iterator_to_array($dataset1->generateProducts());
        $products2 = iterator_to_array($dataset2->generateProducts());

        // Count differences
        $differences = 0;
        foreach ($products1 as $id => $product1) {
            if (!isset($products2[$id]) || $product1 !== $products2[$id]) {
                $differences++;
            }
        }

        $this->assertTrue(
            $differences > 0,
            'Different seeds should produce different products'
        );
    }

    private function testAllOrderUserIdsExist(): void
    {
        $seed = 6666;
        $rows = 100;

        $gen = new DeterministicGenerator($seed);
        $dataset = new DatasetGenerator($gen, $rows);

        $users = iterator_to_array($dataset->generateUsers());
        $userIds = array_keys($users);
        $orders = iterator_to_array($dataset->generateOrders($userIds));

        foreach ($orders as $order) {
            $this->assertTrue(
                isset($users[$order['user_id']]),
                "Order {$order['id']} references non-existent user {$order['user_id']}"
            );
        }
    }

    private function testAllOrderItemOrderIdsExist(): void
    {
        $seed = 7777;
        $rows = 100;

        $gen = new DeterministicGenerator($seed);
        $dataset = new DatasetGenerator($gen, $rows);

        $users = iterator_to_array($dataset->generateUsers());
        $products = iterator_to_array($dataset->generateProducts());
        $orders = iterator_to_array($dataset->generateOrders(array_keys($users)));
        $items = iterator_to_array($dataset->generateOrderItems(array_keys($orders), array_keys($products)));

        foreach ($items as $item) {
            $this->assertTrue(
                isset($orders[$item['order_id']]),
                "Order item {$item['id']} references non-existent order {$item['order_id']}"
            );
        }
    }

    private function testAllOrderItemProductIdsExist(): void
    {
        $seed = 8888;
        $rows = 100;

        $gen = new DeterministicGenerator($seed);
        $dataset = new DatasetGenerator($gen, $rows);

        $users = iterator_to_array($dataset->generateUsers());
        $products = iterator_to_array($dataset->generateProducts());
        $orders = iterator_to_array($dataset->generateOrders(array_keys($users)));
        $items = iterator_to_array($dataset->generateOrderItems(array_keys($orders), array_keys($products)));

        foreach ($items as $item) {
            $this->assertTrue(
                isset($products[$item['product_id']]),
                "Order item {$item['id']} references non-existent product {$item['product_id']}"
            );
        }
    }

    private function testUserCountMatchesRequestedRows(): void
    {
        for ($rows = 10; $rows <= 100; $rows += 30) {
            $gen = new DeterministicGenerator(9999);
            $dataset = new DatasetGenerator($gen, $rows);
            $users = iterator_to_array($dataset->generateUsers());

            $this->assertTrue(
                count($users) === $rows,
                "User count must match requested rows: expected {$rows}, got " . count($users)
            );
        }
    }

    private function testProductCountIsScaledByProductScaleFactor(): void
    {
        $rows = 1000;
        $gen = new DeterministicGenerator(10001);
        $dataset = new DatasetGenerator($gen, $rows);
        $products = iterator_to_array($dataset->generateProducts());

        // Expected: ~15% of requested rows
        $expected = (int)($rows * 0.15);
        $actual = count($products);

        $this->assertTrue(
            $actual > 0 && $actual <= $expected * 1.1,  // Allow 10% variance
            "Product count should be ~15% of rows (expected ~{$expected}, got {$actual})"
        );
    }

    private function testOrderCountIsScaledByOrderScaleFactor(): void
    {
        $rows = 1000;
        $gen = new DeterministicGenerator(10002);
        $dataset = new DatasetGenerator($gen, $rows);
        $users = iterator_to_array($dataset->generateUsers());
        $orders = iterator_to_array($dataset->generateOrders(array_keys($users)));

        // Expected: ~30% of requested rows
        $expected = (int)($rows * 0.30);
        $actual = count($orders);

        $this->assertTrue(
            $actual > 0 && $actual <= $expected * 1.1,  // Allow 10% variance
            "Order count should be ~30% of rows (expected ~{$expected}, got {$actual})"
        );
    }

    private function testOrderItemsHaveRealisticCardinality(): void
    {
        $rows = 200;
        $gen = new DeterministicGenerator(10003);
        $dataset = new DatasetGenerator($gen, $rows);

        $users = iterator_to_array($dataset->generateUsers());
        $products = iterator_to_array($dataset->generateProducts());
        $orders = iterator_to_array($dataset->generateOrders(array_keys($users)));
        $items = iterator_to_array($dataset->generateOrderItems(array_keys($orders), array_keys($products)));

        // Verify: 1-5 items per order
        $itemsPerOrder = [];
        foreach ($items as $item) {
            $itemsPerOrder[$item['order_id']] = ($itemsPerOrder[$item['order_id']] ?? 0) + 1;
        }

        foreach ($itemsPerOrder as $orderId => $count) {
            $this->assertTrue(
                $count >= 1 && $count <= 5,
                "Order {$orderId} has {$count} items, expected 1-5"
            );
        }

        // Also verify: average should be ~3
        $averageItemsPerOrder = array_sum($itemsPerOrder) / count($itemsPerOrder);
        $this->assertTrue(
            $averageItemsPerOrder >= 2.0 && $averageItemsPerOrder <= 4.0,
            "Average items per order should be ~3, got {$averageItemsPerOrder}"
        );
    }

    private function testUserEmailsAreUnique(): void
    {
        $rows = 100;
        $gen = new DeterministicGenerator(10004);
        $dataset = new DatasetGenerator($gen, $rows);
        $users = iterator_to_array($dataset->generateUsers());

        $emails = array_map(fn($u) => $u['email'], $users);
        $uniqueEmails = array_unique($emails);

        $this->assertTrue(
            count($emails) === count($uniqueEmails),
            'All user emails must be unique'
        );
    }

    private function testProductSkusAreUnique(): void
    {
        $rows = 200;
        $gen = new DeterministicGenerator(10005);
        $dataset = new DatasetGenerator($gen, $rows);
        $products = iterator_to_array($dataset->generateProducts());

        $skus = array_map(fn($p) => $p['sku'], $products);
        $uniqueSkus = array_unique($skus);

        $this->assertTrue(
            count($skus) === count($uniqueSkus),
            'All product SKUs must be unique'
        );
    }

    private function testThrowsOnZeroRequestedRows(): void
    {
        $gen = new DeterministicGenerator(1);

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => new DatasetGenerator($gen, 0),
            'DatasetGenerator should throw on zero requested rows'
        );
    }

    private function testThrowsOnNegativeRequestedRows(): void
    {
        $gen = new DeterministicGenerator(1);

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => new DatasetGenerator($gen, -100),
            'DatasetGenerator should throw on negative requested rows'
        );
    }

    private function testOrdersThrowsOnEmptyUserIds(): void
    {
        $gen = new DeterministicGenerator(1);
        $dataset = new DatasetGenerator($gen, 10);

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => iterator_to_array($dataset->generateOrders([])),
            'generateOrders should throw on empty userIds'
        );
    }

    private function testOrderItemsThrowsOnEmptyOrderIds(): void
    {
        $gen = new DeterministicGenerator(1);
        $dataset = new DatasetGenerator($gen, 10);

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => iterator_to_array($dataset->generateOrderItems([], [1, 2, 3])),
            'generateOrderItems should throw on empty orderIds'
        );
    }

    private function testOrderItemsThrowsOnEmptyProductIds(): void
    {
        $gen = new DeterministicGenerator(1);
        $dataset = new DatasetGenerator($gen, 10);

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => iterator_to_array($dataset->generateOrderItems([1, 2, 3], [])),
            'generateOrderItems should throw on empty productIds'
        );
    }

    private function testGeneratorsAreReusableIterables(): void
    {
        $gen = new DeterministicGenerator(11111);
        $dataset = new DatasetGenerator($gen, 50);

        // First iteration
        $users1 = iterator_to_array($dataset->generateUsers());

        // Second iteration (should not repeat data, generators are consumed)
        $users2 = iterator_to_array($dataset->generateUsers());

        // Both should have same structure but be separate arrays
        $this->assertTrue(
            count($users1) === count($users2),
            'Generator should be reusable with consistent counts'
        );
    }

    private function testGeneratorsYieldConsistentKeyOrdering(): void
    {
        $gen = new DeterministicGenerator(12121);
        $dataset = new DatasetGenerator($gen, 100);

        $users1 = iterator_to_array($dataset->generateUsers());
        $keys1 = array_keys($users1);

        $gen = new DeterministicGenerator(12121);
        $dataset = new DatasetGenerator($gen, 100);

        $users2 = iterator_to_array($dataset->generateUsers());
        $keys2 = array_keys($users2);

        $this->assertTrue(
            $keys1 === $keys2,
            'Generator key ordering must be consistent across runs'
        );
    }
}

$test = new DatasetGeneratorTest();
$test->run();
