<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use DBench86\Dataset\Schema;

class SchemaTest extends TestCase
{
    public function testSchemaVersionIsPositive(): void
    {
        $this->assertTrue(
            Schema::SCHEMA_VERSION > 0,
            'Schema version must be a positive integer'
        );
    }

    public function testAllTablesAreDefined(): void
    {
        $tables = Schema::getTables();
        $this->assertTrue(
            count($tables) > 0,
            'At least one table must be defined'
        );

        $tableNames = [
            Schema::TABLE_USERS,
            Schema::TABLE_PRODUCTS,
            Schema::TABLE_ORDERS,
            Schema::TABLE_ORDER_ITEMS,
        ];

        foreach ($tableNames as $expectedName) {
            $found = false;
            foreach ($tables as $table) {
                if ($table['name'] === $expectedName) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue(
                $found,
                "Table '{$expectedName}' must be defined in getTables()"
            );
        }
    }

    public function testTablesHaveConsistentStructure(): void
    {
        $tables = Schema::getTables();

        foreach ($tables as $table) {
            $this->assertTrue(
                isset($table['name']) && is_string($table['name']) && strlen($table['name']) > 0,
                'Every table must have a non-empty name'
            );

            $this->assertTrue(
                isset($table['columns']) && is_array($table['columns']) && count($table['columns']) > 0,
                "Table '{$table['name']}' must define at least one column"
            );

            $this->assertTrue(
                isset($table['boundParameters']) && is_int($table['boundParameters']) && $table['boundParameters'] > 0,
                "Table '{$table['name']}' must have a positive bound parameter count"
            );

            // First column should be 'id' (primary key)
            $this->assertEquals(
                'id',
                $table['columns'][0],
                "Table '{$table['name']}' first column must be 'id' (primary key)"
            );
        }
    }

    public function testBoundParametersCount(): void
    {
        $this->assertEquals(
            4,
            Schema::BOUND_PARAMETERS_USERS,
            'users table requires 4 bound parameters per INSERT'
        );

        $this->assertEquals(
            4,
            Schema::BOUND_PARAMETERS_PRODUCTS,
            'products table requires 4 bound parameters per INSERT'
        );

        $this->assertEquals(
            4,
            Schema::BOUND_PARAMETERS_ORDERS,
            'orders table requires 4 bound parameters per INSERT'
        );

        $this->assertEquals(
            5,
            Schema::BOUND_PARAMETERS_ORDER_ITEMS,
            'order_items table requires 5 bound parameters per INSERT'
        );
    }

    public function testForeignKeysReferenceExistingTables(): void
    {
        $tables = Schema::getTables();
        $tableNames = array_map(fn(array $t) => $t['name'], $tables);
        $tablesByName = array_combine($tableNames, $tables);

        $foreignKeys = Schema::getForeignKeys();
        $this->assertTrue(
            count($foreignKeys) > 0,
            'At least one foreign key must be defined'
        );

        foreach ($foreignKeys as $fk) {
            $this->assertTrue(
                isset($tablesByName[$fk['source']]),
                "Foreign key source table '{$fk['source']}' must exist"
            );

            $this->assertTrue(
                isset($tablesByName[$fk['target']]),
                "Foreign key target table '{$fk['target']}' must exist"
            );
        }
    }

    public function testForeignKeyColumnsExist(): void
    {
        $tables = Schema::getTables();
        $tablesByName = array_combine(
            array_map(fn(array $t) => $t['name'], $tables),
            $tables
        );

        foreach (Schema::getForeignKeys() as $fk) {
            $source = $tablesByName[$fk['source']];
            $target = $tablesByName[$fk['target']];

            $this->assertTrue(
                in_array($fk['sourceColumn'], $source['columns'], true),
                "Foreign key source column '{$fk['source']}.{$fk['sourceColumn']}' must exist"
            );

            $this->assertTrue(
                in_array($fk['targetColumn'], $target['columns'], true),
                "Foreign key target column '{$fk['target']}.{$fk['targetColumn']}' must exist"
            );
        }
    }

    public function testExpectedForeignKeyRelationships(): void
    {
        $fks = Schema::getForeignKeys();
        $fkMap = [];

        foreach ($fks as $fk) {
            $fkMap["{$fk['source']}.{$fk['sourceColumn']}"] = $fk;
        }

        // orders.user_id → users.id
        $this->assertTrue(
            isset($fkMap['orders.user_id']),
            'Foreign key orders.user_id → users.id must exist'
        );
        $this->assertEquals('users', $fkMap['orders.user_id']['target']);
        $this->assertEquals('id', $fkMap['orders.user_id']['targetColumn']);

        // order_items.order_id → orders.id
        $this->assertTrue(
            isset($fkMap['order_items.order_id']),
            'Foreign key order_items.order_id → orders.id must exist'
        );
        $this->assertEquals('orders', $fkMap['order_items.order_id']['target']);

        // order_items.product_id → products.id
        $this->assertTrue(
            isset($fkMap['order_items.product_id']),
            'Foreign key order_items.product_id → products.id must exist'
        );
        $this->assertEquals('products', $fkMap['order_items.product_id']['target']);
    }

    public function testIndexesReferenceExistingColumns(): void
    {
        $tables = Schema::getTables();
        $tablesByName = array_combine(
            array_map(fn(array $t) => $t['name'], $tables),
            $tables
        );

        $indexes = Schema::getIndexes();
        $this->assertTrue(
            count($indexes) > 0,
            'At least one index must be defined'
        );

        foreach ($indexes as $index) {
            $this->assertTrue(
                isset($tablesByName[$index['table']]),
                "Index table '{$index['table']}' must exist"
            );

            $table = $tablesByName[$index['table']];
            $this->assertTrue(
                in_array($index['column'], $table['columns'], true),
                "Indexed column '{$index['table']}.{$index['column']}' must exist"
            );
        }
    }

    public function testUniqueIndexes(): void
    {
        $indexes = Schema::getIndexes();
        $uniqueIndexes = array_filter($indexes, fn(array $idx) => $idx['unique']);

        // users.email and products.sku should be unique
        $uniqueMap = [];
        foreach ($uniqueIndexes as $idx) {
            $uniqueMap["{$idx['table']}.{$idx['column']}"] = true;
        }

        $this->assertTrue(
            isset($uniqueMap['users.email']),
            'users.email must be a unique index'
        );

        $this->assertTrue(
            isset($uniqueMap['products.sku']),
            'products.sku must be a unique index'
        );
    }

    public function testRangeQueryIndexes(): void
    {
        $indexes = Schema::getIndexes();

        // users.created_at and orders.created_at should be indexed for range queries
        $indexMap = array_combine(
            array_map(fn(array $idx) => "{$idx['table']}.{$idx['column']}", $indexes),
            $indexes
        );

        $this->assertTrue(
            isset($indexMap['users.created_at']),
            'users.created_at must be indexed for range queries'
        );

        $this->assertTrue(
            isset($indexMap['orders.created_at']),
            'orders.created_at must be indexed for range queries'
        );
    }

    public function testSchemaValidation(): void
    {
        // Should not throw
        try {
            Schema::validate();
            $this->assertTrue(true, 'Schema validation should pass');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(false, "Schema validation failed: {$e->getMessage()}");
        }
    }

    public function testTableInsertionOrder(): void
    {
        $tables = Schema::getTables();
        $tablesByName = array_combine(
            array_map(fn(array $t) => $t['name'], $tables),
            array_map(fn(array $t) => array_key_first(array_flip([array_search($t, $tables, true)])), $tables)
        );

        // Get actual positions
        $positions = [];
        foreach ($tables as $i => $table) {
            $positions[$table['name']] = $i;
        }

        // users and products should come before orders
        $this->assertTrue(
            $positions[Schema::TABLE_USERS] < $positions[Schema::TABLE_ORDERS],
            'users table must come before orders (dependency order)'
        );

        $this->assertTrue(
            $positions[Schema::TABLE_PRODUCTS] < $positions[Schema::TABLE_ORDER_ITEMS],
            'products table must come before order_items (dependency order)'
        );

        // orders should come before order_items
        $this->assertTrue(
            $positions[Schema::TABLE_ORDERS] < $positions[Schema::TABLE_ORDER_ITEMS],
            'orders table must come before order_items (dependency order)'
        );
    }

    public function run(): void
    {
        echo "\nSchemaTest\n";
        echo "───────────\n";
        $this->testSchemaVersionIsPositive();
        $this->testAllTablesAreDefined();
        $this->testTablesHaveConsistentStructure();
        $this->testBoundParametersCount();
        $this->testForeignKeysReferenceExistingTables();
        $this->testForeignKeyColumnsExist();
        $this->testExpectedForeignKeyRelationships();
        $this->testIndexesReferenceExistingColumns();
        $this->testUniqueIndexes();
        $this->testRangeQueryIndexes();
        $this->testSchemaValidation();
        $this->testTableInsertionOrder();
    }
}

$test = new SchemaTest();
$test->run();
