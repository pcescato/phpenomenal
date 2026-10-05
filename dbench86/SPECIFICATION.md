# DBench86 specification

DBench86 is a PHP 8.6 CLI application measuring ten deterministic database
workloads through PDO on MariaDB, PostgreSQL and SQLite. It is a PHPenomenal
submission with no third-party runtime dependencies. The [README](README.md)
maps all seven mandatory features to their actual production methods/properties.

## Measurement and output

`Application` creates a driver, resets its schema, populates the dataset, prepares
each workload once, executes warmups, collects measured runs, and cleans up.
`WorkloadExecutor` binds a timer and connection using partial function application;
`WorkloadTimer::run()` times the complete `Workload::execute()` call with monotonic
`hrtime(true)`. Preparation, cleanup and statistics are outside timing. This
includes PHP execution within the workload, PDO overhead, server communication,
result delivery and consumption. SQLite runs in process and has no server network
round trip; results describe these deployment paths rather than a universal ranking.

Measured durations are converted from nanoseconds to milliseconds. The CLI prints minimum, median, nearest-rank p95 and maximum from successful
measured samples, ordered by the configured metric and SortDirection. Warmups are
excluded. Statistics also exposes mean and sample standard deviation.
Timeouts are detected after execution with `Time\Duration`, not by cancellation.
The timer records failures/timeouts in the shared const-held metrics object.
Application records requested, performed, successful, failed and timed-out
measured executions in WorkloadResult, retains only successful durations, and
reports warmups separately. Zero successes produce N/A latency statistics.
Preparation and cleanup errors remain visible without erasing measured counts.
Any unsuccessful or incomplete workload produces benchmark exit code 6.

## Deterministic data and workloads

The application seeds `DeterministicGenerator` with 42,000 and populates incrementally
via `DatasetGenerator` / `DatasetPopulator`. Users equal requested rows; products
and orders are approximately 15% and 30% (minimum one); each order has 1–5 items.
The logical tables are users, products, orders and order_items. Driver-specific
DDL represents the same logical fields, keys and relations.

The ten real operations are listed in the [README workload table](README.md#workloads).
Key-based preparation uses deterministic bounded samples spanning contiguous
generated IDs. Range preparation selects sorted timestamp ranks in SQL; execution
consumes the unchanged inclusive SELECT through EOF. See the separate
[memory contract](MEMORY_SCALABILITY.md) and [range methodology](docs/RANGE_SELECT_AUDIT.md).

## Integrity and current scope

Driver batch/parameter constants are DBench86 operational policies. The batch
workload consults its actual driver's limits and uses native `clamp()`; no claim
about an engine's true maximum is implied. The CLI defaults to a 100-row batch request and honors --batch-size. Reporting
honors --sort and --direction; no output-file feature is exposed. See
[configuration](CONFIGURATION.md). No automatic server tuning is performed.

Stock/tuned campaigns change external engine settings while preserving source,
PHP/PDO, SQL, schema, rows, seed, workloads, warmups, runs and timeout. Record
restart/cache state, engine versions, hardware and background activity as well.
The [stock](docs/DATABASE_CONFIG_STOCK.md) and [tuned](docs/DATABASE_CONFIG_TUNED.md)
documents preserve reference observations, without asserting a measured speedup.

Canonical tests use `php tests/run.php`. They cover contracts, configuration,
autoloading, deterministic generation, bounded preparation, real SQLite behavior,
optional server integrations and the official token checker. The nested Example
class is retained because `AutoloaderTest` needs it, not as an additional workload.
