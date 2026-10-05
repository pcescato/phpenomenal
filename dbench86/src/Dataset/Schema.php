<?php
declare(strict_types=1);

namespace DBench86\Dataset;

/**
 * Logical schema definition for the DBench86 benchmark dataset.
 *
 * This class defines the abstract structure of all benchmark tables, independent
 * of SQL dialect. Each driver (MariaDB, PostgreSQL, SQLite) translates this model
 * into its own SQL and data type choices.
 *
 * Tables are designed to exercise realistic query patterns:
 * - Primary key lookups (users.id, products.id, etc.)
 * - Secondary index lookups (users.email, products.sku)
 * - Range queries (orders.created_at BETWEEN ...)
 * - Foreign key joins (orders → users, order_items → orders/products)
 * - Aggregations (COUNT, SUM on order_items)
 */
final class Schema
{
    /**
     * Schema version: increment whenever structural changes occur that would
     * affect data generation semantics for the same seed value.
     *
     * This ensures reproducibility across DBench86 versions and enables
     * backward compatibility checks.
     */
    public const int SCHEMA_VERSION = 1;

    /**
     * Logical definition of the 'users' table.
     *
     * Columns:
     *   id:         Numeric identifier, primary key
     *   email:      Text, unique index (for secondary key lookup)
     *   name:       Text
     *   created_at: Timestamp, indexed (for range queries)
     *
     * Workloads exercised:
     *   - Lookup by primary key (id)
     *   - Lookup by unique secondary key (email)
     *   - Range query (created_at BETWEEN t1 AND t2)
     *   - Join source (orders.user_id → users.id)
     *
     * Bound parameters per INSERT row (C): 4
     *   INSERT INTO users (email, name, created_at, ...) VALUES (?, ?, ?, ...)
     */
    public const string TABLE_USERS = 'users';
    public const array COLUMNS_USERS = [
        'id',
        'email',
        'name',
        'created_at',
    ];
    public const int BOUND_PARAMETERS_USERS = 4;

    /**
     * Logical definition of the 'products' table.
     *
     * Columns:
     *   id:    Numeric identifier, primary key
     *   sku:   Text, unique index (for secondary key lookup)
     *   name:  Text
     *   price: Numeric (decimal/float), used in aggregations
     *
     * Workloads exercised:
     *   - Lookup by primary key (id)
     *   - Lookup by unique secondary key (sku)
     *   - Join source (order_items.product_id → products.id)
     *   - Aggregation (SUM(quantity * unit_price))
     *
     * Bound parameters per INSERT row (C): 4
     *   INSERT INTO products (sku, name, price, ...) VALUES (?, ?, ?, ...)
     */
    public const string TABLE_PRODUCTS = 'products';
    public const array COLUMNS_PRODUCTS = [
        'id',
        'sku',
        'name',
        'price',
    ];
    public const int BOUND_PARAMETERS_PRODUCTS = 4;

    /**
     * Logical definition of the 'orders' table.
     *
     * Columns:
     *   id:         Numeric identifier, primary key
     *   user_id:    Foreign key to users.id, indexed (for range lookups, joins)
     *   status:     Short text (pending/completed/cancelled), for filtering
     *   created_at: Timestamp, indexed (for range queries)
     *
     * Workloads exercised:
     *   - Lookup by primary key (id)
     *   - Lookup by foreign key (user_id)
     *   - Range query (created_at BETWEEN t1 AND t2)
     *   - Join source (order_items.order_id → orders.id)
     *   - Join target (orders.user_id → users.id)
     *   - Filtering by status
     *
     * Bound parameters per INSERT row (C): 4
     *   INSERT INTO orders (user_id, status, created_at, ...) VALUES (?, ?, ?, ...)
     */
    public const string TABLE_ORDERS = 'orders';
    public const array COLUMNS_ORDERS = [
        'id',
        'user_id',
        'status',
        'created_at',
    ];
    public const int BOUND_PARAMETERS_ORDERS = 4;

    /**
     * Logical definition of the 'order_items' table.
     *
     * Columns:
     *   id:          Numeric identifier, primary key
     *   order_id:    Foreign key to orders.id, indexed (for lookups, joins)
     *   product_id:  Foreign key to products.id, indexed (for joins)
     *   quantity:    Integer, used in aggregations
     *   unit_price:  Numeric, used in aggregations
     *
     * Workloads exercised:
     *   - Lookup by primary key (id)
     *   - Lookup by foreign key (order_id, product_id)
     *   - Join with orders (order_items.order_id → orders.id)
     *   - Join with products (order_items.product_id → products.id)
     *   - Aggregation (SUM(quantity), SUM(quantity * unit_price))
     *
     * Bound parameters per INSERT row (C): 5
     *   INSERT INTO order_items (order_id, product_id, quantity, unit_price, ...)
     *   VALUES (?, ?, ?, ?, ...)
     *
     * Note: order_items has more bound parameters per row than users/products/orders.
     * This means BatchLimitCalculator will calculate a more restrictive batch size
     * for this table (fewer rows per batch) if the driver's parameter limit is reached.
     */
    public const string TABLE_ORDER_ITEMS = 'order_items';
    public const array COLUMNS_ORDER_ITEMS = [
        'id',
        'order_id',
        'product_id',
        'quantity',
        'unit_price',
    ];
    public const int BOUND_PARAMETERS_ORDER_ITEMS = 5;

    /**
     * Get all table definitions in insertion order (respecting foreign key dependencies).
     *
     * Order is critical: tables must be created (and data inserted) in dependency order.
     * - users (no dependencies)
     * - products (no dependencies)
     * - orders (depends on users)
     * - order_items (depends on orders and products)
     *
     * @return array<array{name: string, columns: array<string>, boundParameters: int}>
     */
    public static function getTables(): array
    {
        return [
            [
                'name' => self::TABLE_USERS,
                'columns' => self::COLUMNS_USERS,
                'boundParameters' => self::BOUND_PARAMETERS_USERS,
            ],
            [
                'name' => self::TABLE_PRODUCTS,
                'columns' => self::COLUMNS_PRODUCTS,
                'boundParameters' => self::BOUND_PARAMETERS_PRODUCTS,
            ],
            [
                'name' => self::TABLE_ORDERS,
                'columns' => self::COLUMNS_ORDERS,
                'boundParameters' => self::BOUND_PARAMETERS_ORDERS,
            ],
            [
                'name' => self::TABLE_ORDER_ITEMS,
                'columns' => self::COLUMNS_ORDER_ITEMS,
                'boundParameters' => self::BOUND_PARAMETERS_ORDER_ITEMS,
            ],
        ];
    }

    /**
     * Get the list of foreign key relationships in the schema.
     *
     * Each relationship is represented as:
     *   [source_table, source_column] → [target_table, target_column]
     *
     * @return array<array{source: string, sourceColumn: string, target: string, targetColumn: string}>
     */
    public static function getForeignKeys(): array
    {
        return [
            [
                'source' => self::TABLE_ORDERS,
                'sourceColumn' => 'user_id',
                'target' => self::TABLE_USERS,
                'targetColumn' => 'id',
            ],
            [
                'source' => self::TABLE_ORDER_ITEMS,
                'sourceColumn' => 'order_id',
                'target' => self::TABLE_ORDERS,
                'targetColumn' => 'id',
            ],
            [
                'source' => self::TABLE_ORDER_ITEMS,
                'sourceColumn' => 'product_id',
                'target' => self::TABLE_PRODUCTS,
                'targetColumn' => 'id',
            ],
        ];
    }

    /**
     * Get the list of indexed columns (beyond primary keys).
     *
     * These represent columns on which secondary lookups, range queries, or
     * foreign key joins are performed.
     *
     * @return array<array{table: string, column: string, unique: bool}>
     */
    public static function getIndexes(): array
    {
        return [
            // users table
            ['table' => self::TABLE_USERS, 'column' => 'email', 'unique' => true],
            ['table' => self::TABLE_USERS, 'column' => 'created_at', 'unique' => false],

            // products table
            ['table' => self::TABLE_PRODUCTS, 'column' => 'sku', 'unique' => true],

            // orders table
            ['table' => self::TABLE_ORDERS, 'column' => 'user_id', 'unique' => false],
            ['table' => self::TABLE_ORDERS, 'column' => 'created_at', 'unique' => false],

            // order_items table
            ['table' => self::TABLE_ORDER_ITEMS, 'column' => 'order_id', 'unique' => false],
            ['table' => self::TABLE_ORDER_ITEMS, 'column' => 'product_id', 'unique' => false],
        ];
    }

    /**
     * Validate schema integrity.
     *
     * Checks:
     * 1. Every table has a defined structure (columns + bound parameters)
     * 2. Every foreign key references an existing table and column
     * 3. Every indexed column exists in its table
     * 4. All bound parameter counts are positive integers
     *
     * @throws \InvalidArgumentException If validation fails
     */
    public static function validate(): void
    {
        $tableNames = array_map(fn(array $t) => $t['name'], self::getTables());
        $tablesByName = array_combine($tableNames, self::getTables());

        // Check foreign keys
        foreach (self::getForeignKeys() as $fk) {
            $source = $fk['source'];
            $target = $fk['target'];

            if (!isset($tablesByName[$source])) {
                throw new \InvalidArgumentException(
                    "Foreign key source table '{$source}' does not exist"
                );
            }

            if (!isset($tablesByName[$target])) {
                throw new \InvalidArgumentException(
                    "Foreign key target table '{$target}' does not exist"
                );
            }

            // Check source column exists
            if (!in_array($fk['sourceColumn'], $tablesByName[$source]['columns'], true)) {
                throw new \InvalidArgumentException(
                    "Foreign key source column '{$source}.{$fk['sourceColumn']}' does not exist"
                );
            }

            // Check target column exists
            if (!in_array($fk['targetColumn'], $tablesByName[$target]['columns'], true)) {
                throw new \InvalidArgumentException(
                    "Foreign key target column '{$target}.{$fk['targetColumn']}' does not exist"
                );
            }
        }

        // Check indexes
        foreach (self::getIndexes() as $index) {
            $table = $index['table'];

            if (!isset($tablesByName[$table])) {
                throw new \InvalidArgumentException(
                    "Index table '{$table}' does not exist"
                );
            }

            if (!in_array($index['column'], $tablesByName[$table]['columns'], true)) {
                throw new \InvalidArgumentException(
                    "Indexed column '{$table}.{$index['column']}' does not exist"
                );
            }
        }

        // Check bound parameters are positive
        foreach (self::getTables() as $table) {
            if ($table['boundParameters'] <= 0) {
                throw new \InvalidArgumentException(
                    "Table '{$table['name']}' has invalid bound parameter count: {$table['boundParameters']}"
                );
            }
        }
    }
}
