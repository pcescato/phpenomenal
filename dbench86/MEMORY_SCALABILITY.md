# Bounded PHP harness memory

Preparation must not retain data proportional to dataset cardinality unless
materialization is explicitly the operation being benchmarked. Ordinary
preparation is bounded by warmup + runs or a documented fixed batch.

Let N denote requested user rows, E denote warmup + measured executions, B denote
an effective insert batch size, and R denote a range query's returned row count.
Products and orders remain approximately 0.15N and 0.30N respectively.

| Code | Original memory pattern | Current PHP memory |
| --- | --- | --- |
| `PrimaryKeySelectWorkload` | Entire user ID column retained, O(N) | O(min(E, N)) deterministic IDs |
| `IndexedSelectWorkload` | Entire email column retained, O(N) | O(min(E, N)) IDs and email values |
| `JoinWorkload` | Entire order ID column retained, O(N) | O(min(E, order count)) IDs |
| `UpdateByPrimaryKeyWorkload` | Full user rows plus ID and original-name arrays, O(N) | O(min(E, N)) IDs and original names |
| `DeleteByPrimaryKeyWorkload` | Full product rows plus ID and restoration arrays, O(N) | O(min(E, product count)) product records plus their dependent order items |
| `RangeSelectWorkload` preparation | Sorted full timestamp column, O(N) | At most 2E timestamp endpoints plus E windows |
| `RangeSelectWorkload` execution | Full result array and client result buffer, O(R), potentially O(N) | One fetched PHP row retained by the harness; default driver buffers may be O(R) |
| `DatasetPopulator::insertUsers/insertProducts` | Unused ID maps accumulated during otherwise streaming insertion, O(N) | O(1), reusable one-row insert statements |
| `DatasetPopulator::insertOrders/insertOrderItems` | Complete FK ID columns materialized, O(N) | Scalar counts and contiguous-ID generation, O(1) |
| `DatasetGenerator` FK inputs | Arrays of every referenced ID supplied by the populator | Contiguous counts, lazy order ID iteration; identical generator draws |
| `SingleInsertWorkload` | One record per execution; cleanup IDs limited by executions | Unchanged, O(E) cleanup |
| `BatchInsertWorkload` | Parameter array sized to effective batch; cleanup sized to rows inserted | Unchanged, O(B) execution / O(E × B) cleanup |
| `TransactionalInsertWorkload` | One inserted record at a time; bounded cleanup IDs | Unchanged, O(1) execution / O(E × transaction size) cleanup |
| `AggregationWorkload` | Fixed status choices and at most two result groups | Unchanged, O(1) |
| Driver schema reset metadata | Lists of table/sequence names | Unchanged, independent of N; depends on schema object count |
| Timing/statistics | Per-run durations and measurements | Unchanged, O(workloads × runs) |

All workloads under `src/Workload/` and dataset/preparation code were inspected.
No complete benchmark row set or indexed column is retained by preparation.
Dataset insertion remains incremental, one row at a time; it does not build a
whole-table row array. Existing effective batch/parameter calculations for the
batch insert workload are unchanged.

## Deterministic preparation

`DeterministicSamples` uses SHA-256 of the benchmark seed (the existing default
42,000), actual table row count, workload identity, and sample index. It divides
contiguous IDs into disjoint strata and derives one valid ID in each stratum.
Thus samples span the dataset rather than using its first E rows. It returns
at most min(E, table row count) distinct IDs, without constructing a full range.
For the requested 5 warmups and 30 runs, normal key workloads retain at most
35 inputs. Workloads constructed directly without configuration default to a
bounded 35-input sample.

Preparation verifies `COUNT(*)`, `MIN(id)`, and `MAX(id)` against the generated
1..N primary-key invariant. Empty or noncontiguous tables produce a clear error
requesting repopulation instead of falling back to a full scan in PHP. An integer
overflow in warmup + runs is diagnosed during configuration validation. No row
ceiling, silent truncation, or fatal-OOM recovery claim is introduced.

Indexed lookup values and update/delete restoration data are fetched using
one prepared primary-key query per sampled ID. All discovery occurs before
`WorkloadTimer` executes the workload.
Delete preparation additionally retains the original order items referencing
sampled products, so cleanup restores their exact values after the products.
This retains only affected dependencies, not a full-dataset snapshot; memory
also depends on the number of items referencing those sampled products.

The range workload preserves its original sorted timestamp ranks: execution i
uses i modulo N and floor(i + N/10) modulo N, ordering the endpoints as before.
A database-side `ROW_NUMBER()` query returns only the ranks required by the E
executions, at most 2E rows. It has no OFFSET queries and does not transfer the
sorted column into PHP. Rank discovery still scans/sorts in the database and
may consume database memory, temporary storage, and time; it is outside timing.
This choice preserves the original range widths and result counts.

## Remaining fetchAll audit

There are eleven production `fetchAll()` calls, all independent of dataset
cardinality:

- Primary-key select: at most one row by primary key.
- Indexed select: at most one row by UNIQUE email.
- Join: at most one grouped row for one primary-key order.
- Aggregation: at most two groups from the two-status predicate.
- Single insert cleanup: LIMIT number of inserted rows, at most E.
- Batch insert cleanup: LIMIT number of inserted rows, at most E × effective batch.
- Transactional insert cleanup: LIMIT inserted rows, at most E × transaction size.
- MariaDB reset: schema table names.
- PostgreSQL reset: schema table names and sequence names (two calls).
- SQLite reset: schema table names.

Schema metadata size is determined by schema object count, not table row count.
The unit suite inventories every production call; adding another call requires
updating its audited bound. Full-table materialization in small test fixtures is
not used by the benchmark harness.

## Observation and validation

The application reports its existing PHP memory_limit, current allocator usage and
peak usage with MiB and raw bytes outside timing. These do not measure total RSS,
server memory, SQLite native storage or all driver-owned result buffers. In-memory
SQLite database storage grows with dataset size. Default PDO buffers may grow with
range result size even though the PHP loop retains one row at a time.

Canonical scalability and range-consumption tests run with `php tests/run.php`.
They check bounded structures, deterministic samples and timed EOF consumption.
Historical streaming-phase timings and memory measurements are deliberately omitted
from this submission because they describe another delivery implementation.
There is no fixed whole-process memory guarantee or automatic memory-limit change.
See [the range audit](docs/RANGE_SELECT_AUDIT.md) for the exact timed protocol.
