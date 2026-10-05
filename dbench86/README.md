# DBench86

DBench86 is a dependency-free PHP 8.6 CLI database benchmark for MariaDB,
PostgreSQL and SQLite. It uses Core/PDO, its own autoloader and test runner,
with no Composer or third-party packages. The seven mandatory PHPenomenal features
are used in actual application behavior, rather than isolated demonstrations.

## What it benchmarks

Each selected engine runs the same ten workloads against deterministic users,
products, orders and order items. `--rows` sets the number of users; related
records are generated from that scale.

### Workloads

| Workload | Operation |
|---|---|
| `pk_select` | User lookup by primary key |
| `indexed_select` | User lookup by unique email |
| `range_select` | Users within an inclusive timestamp range, consumed through EOF |
| `join` | Order/user/item join grouped for one order |
| `aggregation` | Counts, quantities and average price grouped by order status |
| `single_insert` | Insert one product |
| `batch_insert` | Insert multiple products in one statement |
| `transactional_insert` | Product inserts within a transaction |
| `update_by_pk` | Update a user's name |
| `delete_by_pk` | Delete a product and its dependent order items |

## Requirements

Use a PHP 8.6 CLI build implementing the challenge features, including
`Time\Duration`, partial function application and parameter DocComments reflection.
Enable PDO and the extensions for your selected engines: `pdo_sqlite`,
`pdo_mysql` for MariaDB, and `pdo_pgsql` for PostgreSQL. Server benchmarks need
reachable servers, existing disposable databases and permissions to create/reset
benchmark tables and insert/update/delete data.

## Quick start

From the `dbench86/` directory:

```sh
php dbench86.php --help
php dbench86.php --database=sqlite --rows=1000 --runs=3 --warmup=1 --timeout=30
```

SQLite uses a fresh `:memory:` database, with no server configuration,
credentials or persistent database file.

## Full benchmark

Use **disposable databases**. Population truncates all base tables in the selected
MariaDB database, or all tables in PostgreSQL's `public` schema with `CASCADE`,
and resets their generated IDs. This can erase unrelated data in those locations.

Export the connection variables or create a local `.env` beside `dbench86.php`.
These values are placeholders; create the databases and configure local
users/passwords before running:

```dotenv
DBENCH86_MARIADB_HOST=127.0.0.1
DBENCH86_MARIADB_PORT=3306
DBENCH86_MARIADB_DATABASE=dbench86
DBENCH86_MARIADB_USERNAME=dbench86
DBENCH86_MARIADB_PASSWORD=change-me

DBENCH86_PGSQL_HOST=127.0.0.1
DBENCH86_PGSQL_PORT=5432
DBENCH86_PGSQL_DATABASE=dbench86
DBENCH86_PGSQL_USERNAME=dbench86
DBENCH86_PGSQL_PASSWORD=change-me
```

Both `USERNAME` variables must be explicitly set and nonempty; the application
has no implicit username. `.env` is Git-ignored. Its loader reads literal
`KEY=VALUE` lines, skips comments/empty lines and does not remove quotes or
interpolate values. Nonempty process values normally take precedence; the
loader can replace empty or `0` values. Keep credentials local.
See [configuration](CONFIGURATION.md) for defaults and test configuration.

From `dbench86/`, run all three engines:

```sh
php dbench86.php \
    --database=all \
    --rows=10000 \
    --runs=30 \
    --warmup=5 \
    --timeout=30
```

## CLI

`run` is an optional positional command. Value options use `--name=value`.

| Option | Meaning | Default |
|---|---|---|
| `--database=all\|mariadb\|postgresql\|sqlite` | Engines to run | `all` |
| `--rows=N` | User-table dataset scale, positive | `100000` |
| `--runs=N` | Requested measured executions per workload, positive | `30` |
| `--warmup=N` | Warmup executions per workload, zero allowed | `5` |
| `--batch-size=N` | Requested product rows per batch insert, positive | `100` |
| `--timeout=N` | Positive whole seconds per execution | `30` |
| `--sort=min\|median\|p95\|max` | Successful latency metric used to sort reported rows | `median` |
| `--direction=asc\|desc` | Report ordering | `asc` |
| `--debug` | Extra prepared-memory reports and unexpected-error details | Off |
| `--help`, `-h` | Show help without running a benchmark | — |
| `--build-monolith` | Generate the standalone build; must be used alone | — |

Timeout detection occurs after execution; it does not cancel SQL. Sorting changes
reporting only, never workload execution order. Rows with no successful samples
appear last in either direction. Batch size is clamped using the selected driver's
DBench86 operational policy; these policies are not engine maxima. There is no
output-file option or JSON writer.

## Output

The compact result table has this header:

```text
Workload               Success  Min (ms) Median (ms)  P95 (ms)  Max (ms)
```

`Success` means **successful measured runs / requested measured runs**. Only
successful measured samples contribute to minimum, median, nearest-rank p95 and
maximum latencies in milliseconds. Failed and timed-out attempts are excluded;
zero successful samples display `N/A` for every latency statistic.

Exceptional measured rows show `!` and successful/failed/timed-out details. If
performed differs from requested, both counts are explicitly printed. Preparation
and cleanup errors remain visible without erasing observed counts or samples.
Warmups never enter latency statistics: one summary covers successful warmups,
otherwise only workloads with warmup issues are listed separately. Zero warmups
are reported as none requested.

Batch reporting is `Batch size: 100 rows` when requested equals effective, or
`Batch size: 500 requested -> 300 effective` for a clamped SQLite request.
PHP memory reports include the configured limit, allocated usage and allocated
peak at startup, after population, after each engine and at completion. `--debug`
also reports memory after workload preparation.

Successful execution returns exit code 0. Incomplete measured runs, failures,
timeouts, unsuccessful warmups or workload preparation/cleanup errors make a
completed benchmark return 6. Invalid benchmark arguments return 2.

## Standalone monolith

The multi-file `src/` tree is the canonical application source.
`DBench86\Build\MonolithBuilder` generates an executable standalone representation
of that same application. From `dbench86/`:

```sh
php dbench86.php --build-monolith
php dist/dbench86-monolith.php --help
php dist/dbench86-monolith.php \
    --database=sqlite \
    --rows=1000 \
    --runs=3 \
    --warmup=1 \
    --timeout=30
```

Build mode requires the canonical source checkout and does not execute a benchmark.
The generated `dist/dbench86-monolith.php` can be copied alone to another directory;
it requires neither `src/` nor `bootstrap.php` at runtime. The same PHP/PDO
requirements apply. A standalone copy loads an optional `.env` beside itself or
uses process environment variables; no credentials are embedded in the artifact.
The deterministic generated artifact is intended for inclusion in the submission
for reviewer execution and verification; rebuild it after application source
changes and never edit it directly.

## PHPenomenal PHP 8.6 features

All references below point to current application code, where the features
serve actual application behavior.

| Feature | Real DBench86 purpose | Source file/class/function |
|---|---|---|
| Partial Function Application | Bind timer and PDO connection, leaving workload as the argument | [WorkloadExecutor.php](src/Benchmark/WorkloadExecutor.php), `WorkloadExecutor::execute()`: `$this->runMeasuredWorkload($timer, ?, $pdo)` |
| `Time\Duration` | Convert monotonic nanoseconds and compare elapsed duration with timeout | [WorkloadTimer.php](src/Benchmark/WorkloadTimer.php), `WorkloadTimer::run()`, `Duration::fromNanoseconds()` / `Duration::compare()`; [Application.php](src/Application.php), `runBenchmark()` converts seconds |
| `clamp()` | Bound requested insert batch size to an operational driver limit | [BatchLimitCalculator.php](src/Benchmark/BatchLimitCalculator.php), `BatchLimitCalculator::effectiveBatchSize()` |
| `#[\Override]` on a constant | Declare deliberate specialization of driver batch/parameter policy | [MariaDbDriver.php](src/Driver/MariaDbDriver.php), `MariaDbDriver::MAX_BATCH_SIZE` / `MAX_BOUND_PARAMETERS`; also PostgreSQL and SQLite drivers |
| Writes on const-held objects | Count workload attempts, completions, failures and timeouts on the shared metrics object | [WorkloadTimer.php](src/Benchmark/WorkloadTimer.php), `WorkloadTimer::run()`: `EXECUTION_METRICS->queries += 1`; constant defined in [ExecutionMetrics.php](src/Benchmark/ExecutionMetrics.php) |
| Readonly property defaults | Immutable driver identity without constructor assignment | [MariaDbDriver.php](src/Driver/MariaDbDriver.php), `public readonly string $name = 'MariaDB';` and `$pdoDriver`; also PostgreSQL and SQLite drivers |
| `SortDirection` enum | Validate typed CLI direction and order reported latency rows | [BenchmarkConfiguration.php](src/Configuration/BenchmarkConfiguration.php), `validateDirection()`: `SortDirection::Ascending` / `Descending`; [Application.php](src/Application.php), `displayResults()` applies the enum |


## Verify the PHP 8.6 features

The executable monolith is the canonical single-file input for the unchanged
PHPenomenal checker. From the **parent repository root**:

```sh
php dbench86/dbench86.php --build-monolith
php -l dbench86/dist/dbench86-monolith.php
php scripts/check-php86-features.php \
    dbench86/dist/dbench86-monolith.php
```

Expected checker result:

```text
All 7 PHP 8.6 features are used.
```

## Testing

From `dbench86/`, use the canonical runner, which bootstraps the test classes and
discovers the unit and integration suites:

```sh
php tests/run.php
```

Unit tests cover configuration, statistics, deterministic generation, bounded
preparation, CLI rendering and monolith generation. SQLite integration tests
exercise real database behavior without external servers. Optional MariaDB and
PostgreSQL tests run when configured; missing configuration produces skips.
Configured connection, population and workload errors fail the suite.
Server tests can reset configured databases;
see [test configuration](CONFIGURATION.md#canonical-tests).

`Php86ComplianceTest` uses the same application builder as `--build-monolith`,
then runs the official checker against a temporary executable monolith.
Standalone tests verify isolated help/SQLite execution and semantic equivalence
with the multi-file launcher. Keep the parent `scripts/` directory available.
Run the suite through `tests/run.php`, rather than invoking individual test files.
Retain raw output: totals count runner assertions, caught test exceptions and skips.
All test failures affect the runner's failure total and final process exit status.

## Benchmark methodology

Dataset generation uses fixed seed 42,000. Population is incremental, and
workload preparation retains samples bounded by warmups plus measured runs,
rather than arrays proportional to dataset rows. Each workload is prepared once,
then warmed up and measured, then cleaned up. Only the complete `execute()` call
is timed with monotonic `hrtime(true)`; population, preparation, cleanup and
statistics calculation are outside that boundary.

Stock versus tuned campaigns use the **same DBench86 code, PHP/PDO behavior, SQL,
schema, deterministic dataset, workloads and run parameters**. The stock campaign
uses installed server settings; tuning changes external MariaDB/PostgreSQL server
configuration. DBench86 has no alternate optimized application path and does not
perform server tuning. Record engine versions, hardware and cache/restart state.
SQLite remains the same in-memory reference. See [stock configuration](docs/DATABASE_CONFIG_STOCK.md)
and [tuned configuration](docs/DATABASE_CONFIG_TUNED.md) for reference details.

`range_select` consumes the complete result set through EOF inside the measured
operation. It uses normal/default PDO delivery, without engine-specific
application delivery overrides. Driver buffering and preparation differences
remain part of those normal execution paths. See the current protocol at the top
of the [range audit](docs/RANGE_SELECT_AUDIT.md); historical streaming investigations
are omitted from that document.

Bounded PHP preparation memory does not imply bounded PDO/native client buffers,
database server memory, SQLite engine/database storage or total process RSS.
The displayed PHP allocator metrics do not measure all of these. The
[scalability report](MEMORY_SCALABILITY.md) documents preparation bounds and memory
limitations; historical timings and measurements are omitted. It makes no universal
memory guarantee for current default PDO delivery.
DBench86 observes the PHP memory limit without changing it.

## Documentation

- [Configuration and test credentials](CONFIGURATION.md)
- [Application specification](SPECIFICATION.md)
- [Technical contracts](TECHNICAL_REQUIREMENTS.md)
- [Memory and scalability](MEMORY_SCALABILITY.md)
- [Current range protocol and historical audit](docs/RANGE_SELECT_AUDIT.md)
- [Stock database configuration reference](docs/DATABASE_CONFIG_STOCK.md)
- [Tuned database configuration reference](docs/DATABASE_CONFIG_TUNED.md)
