<?php
declare(strict_types=1);

namespace DBench86\Workload;

use PDO;

/**
 * Contract for a benchmark workload.
 *
 * A Workload defines a repeatable sequence of database operations that tests
 * a specific access pattern or query type. Workloads must not:
 *
 * - Format or output any report/results (that's the runner's job)
 * - Contain or manage database connection credentials (passed explicitly)
 * - Modify global benchmark configuration silently (all parameters explicit)
 * Workloads may retain prepared inputs, execution counters and restoration data
 * across execute() calls. Cleanup restores logical dataset changes after the cycle.
 *
 * Workloads are prepared once before execution, then executed repeatedly with
 * consistent parameters, then cleaned up once after all runs.
 */
interface Workload
{
    /**
     * Human-readable name of this workload.
     *
     * Used for logging and result reporting. Must be a short identifier
     * (e.g., "lookup_by_pk", "range_scan", "bulk_insert").
     *
     * @return string
     */
    public function name(): string;

    /**
     * Prepare the workload for execution.
     *
     * Called once before any execute() invocations. May create temporary tables,
     * load reference data, or prepare statements. Runs outside measured timing.
     * The caller does not guarantee an active transaction; workloads manage
     * any transactions they require.
     *
     * @param PDO $pdo Active database connection
     * @return void
     * @throws \Exception If preparation fails
     */
    public function prepare(PDO $pdo): void;

    /**
     * Execute one run of this workload.
     *
     * Performs all database operations that constitute a single benchmark run.
     * Called repeatedly (potentially hundreds of thousands of times) with the same
     * PDO connection and parameters.
     *
     * Must return the total number of **logical operations** performed, not SQL
     * statement count. For example, a bulk insert of 1000 rows returns 1000, not
     * the number of prepared statements. This count is used to calculate throughput.
     *
     * @param PDO $pdo Active database connection
     * @return int Number of logical operations completed
     * @throws \Exception If execution fails
     */
    public function execute(PDO $pdo): int;

    /**
     * Clean up after workload execution.
     *
     * Called once after all execute() invocations complete. May drop temporary
     * tables, restore modified records, or release resources. Restoration failures
     * must propagate so the caller records a cleanup error and unsuccessful benchmark.
     *
     * @param PDO $pdo Active database connection
     * @return void
     */
    public function cleanup(PDO $pdo): void;

    /**
     * Number of bound parameters required per logical row in this workload.
     *
     * Used by BatchLimitCalculator to determine maximum safe batch size.
     * Return null if not applicable (e.g., SELECT-only workloads that don't use
     * parameter binding in ways that scale with row count).
     *
     * For example:
     * - INSERT into 5 columns: return 5
     * - INSERT into 5 columns, 1000 rows per batch: return 5 (parameters per row, not per batch)
     * - SELECT with single WHERE param: return 1
     * - SELECT with no parameters: return null
     *
     * @return int|null
     */
    public function boundParametersPerRow(): ?int;
}
