# DBench86 technical contracts

## 1. Driver and schema contract

`src/Driver/DatabaseDriver.php` requires readable `name` / `pdoDriver` properties
and `connect`, `resetSchema`, `createSchema`, `discoverEngineVersion` methods.
Concrete drivers extend `AbstractDriver`. Identity properties have explicit readonly
defaults; constructors never assign them. Driver constants use `#[\Override]`
directly before `const`. Production connections receive explicit constructor
arguments from Application; see [configuration](CONFIGURATION.md).

Population resets the configured database schema, creates users, products,
orders and order_items and inserts deterministic records incrementally.
Use disposable databases with appropriate DDL/DML permissions. PDO error mode is
exception; delivery/preparation attributes remain at their usual driver defaults.

## 2. Batch policy

### 2.1 Operational constants

| Driver | MAX_BATCH_SIZE | MAX_BOUND_PARAMETERS |
|---|---:|---:|
| AbstractDriver | 500 | 10,000 |
| MariaDbDriver | 2,000 | 50,000 |
| PostgreSqlDriver | 5,000 | 32,000 |
| SqliteDriver | 500 | 900 |

These are documented DBench86 operational choices, not real engine limits.

### 2.2 Driver-specific limit

`BatchLimitCalculator::driverLimit(driver, parametersPerRow)` returns the minimum
of the actual driver's batch-size policy and integer-divided parameter policy.
Parameters per row must be positive. Products inserted by the batch workload bind
three fields: sku, name and price.

### 2.3 Common limit

`commonLimit(driverLimits)` returns the minimum of a nonempty list of driver limits.
It is a reusable calculator operation; current Application uses each actual
driver policy, not a cross-engine common limit.

### 2.4 Effective size

`effectiveBatchSize(requested, limit)` calls native `clamp(requested, 1, limit)`.
`BatchInsertWorkload` uses the actual driver at preparation and requests the configured --batch-size
when instantiated by Application (default 100). Output reports requested and
effective batch sizes without presenting operational policy as engine maxima. A null driver is supported for workload listing;
preparation requires a real driver.

## 3. Execution lifecycle

`Workload::prepare(PDO)` runs once, followed by configured warmups and measured
`execute(PDO): int` calls, then `cleanup(PDO)` in a finally block. There is no
universal surrounding transaction; transactional behavior belongs to workloads.
Return values represent logical rows/operations, not SQL-statement counts.
The delete workload also removes dependent order items.

Only `execute` is timed. `WorkloadTimer` catches execution throwables, converts
elapsed nanoseconds to `Duration`, compares with timeout, updates metrics, and
returns `WorkloadMeasurement`. `EXECUTION_METRICS->queries` counts attempts, including
failed attempts; it is not a database statement counter. Timeout detection occurs
after completion. Application records outcomes in WorkloadResult and computes latency statistics
from successful measured samples only. Requested/performed/successful/failed/
timed-out counts remain visible. Warmup accounting is separate. Preparation and
cleanup errors preserve existing samples and counts. No-success statistics are N/A;
unsuccessful/incomplete requests or setup/cleanup errors produce exit code 6.
Sorting by min/median/p95/max and native SortDirection changes reporting only.
There is no output-file option.

## 4. Determinism, memory and fairness

Same generator seed and scale produce repeatable logical data; driver DDL differs
as required by each engine. Key samples verify generated contiguous IDs and remain
bounded by execution count. Range endpoints are discovered in SQL and all returned
rows are consumed in the timed operation. See [harness memory](MEMORY_SCALABILITY.md)
and [range audit](docs/RANGE_SELECT_AUDIT.md) for bounds and driver-buffer limitations.

Stock/tuned campaigns use the identical application, SQL, PHP/PDO and dataset.
External configuration is documented separately; no optimized application path
is selected. The app observes memory_limit and memory usage without changing ini.

## 5. Bootstrap, exceptions and tests

`bootstrap.php` maps `DBench86\` to src and `Tests\` to tests, and explicitly loads
ExecutionMetrics so its namespace constant exists. `src/Driver/Nested/Example.php`
is a nested-autoload fixture required by the canonical test suite.
`tests/Support/TestBatchInsertWorkload.php` supplies test-only batch behavior.
Canonical discovery loads `*Test.php` from Unit and Integration; the test
launcher `tests/run.php` requires TestRunner, TestCase and DatabaseCredentials.

### Effective CLI process exit codes

Internal exception categories are not necessarily the effective process exit code.
The launcher exits with the value returned by `Application::run()` for benchmarks,
or by `Cli\Launcher::run()` for build mode.

| Outcome or failure path | Effective CLI exit code |
|---|---:|
| Successful benchmark, help or monolith build | 0 |
| Invalid benchmark options/commands or configuration values | 2 |
| Runtime requirement failure propagated from engine setup, including missing/empty server `USERNAME` | 3 |
| Other engine-level exceptions during driver creation, connection, version discovery or dataset schema creation/population | 6 |
| Workload preparation/execution/cleanup failures, timeouts, unsuccessful warmups or incomplete measured runs | 6 |
| Unexpected throwable reaching the top-level generic handler | 1 |
| Build failure or `--build-monolith` combined with other arguments | 1 |

`DatabaseConnectionException` and `BenchmarkPreparationException` retain internal
`ExitCode` categories 4 and 5. On the current benchmark paths, `benchmarkEngine()`
catches these exceptions, prints the error and returns false; the aggregate engine
result makes `Application::run()` return 6, not 4 or 5. Other engines continue where
possible. Schema-reset exceptions are initially ignored by `DatasetPopulator` so
schema creation can proceed; they do not alone determine the final exit code.

`RuntimeRequirementException` is explicitly rethrown by `benchmarkEngine()` and
handled by the top-level `DBench86Exception` handler, which returns its category 3
and stops the campaign. That handler returns an internal category only when the
exception reaches it. Uncaught throwables outside the DBench86 exception hierarchy
reach the generic handler and return 1. Workload errors are caught locally and preserve requested/performed
counts and observed samples; their effective completed-benchmark result is 6.

The suite uses the unchanged official checker in the parent repository against
an executable monolith generated by the same application builder as
`--build-monolith`. No Composer or third-party autoloader is needed.
