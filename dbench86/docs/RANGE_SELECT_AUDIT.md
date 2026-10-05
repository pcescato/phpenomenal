# range_select methodology and implementation audit

`src/Workload/RangeSelectWorkload.php:23–57` retains deterministic bounded endpoint preparation, and line 56 prepares the identical plain SELECT with **no driver options**. Lines 59–72 execute it and consume every associative row with the same count/discard loop through EOF inside the existing timer. Line 77 closes the statement during untimed workload cleanup. There is no driver branch, buffering toggle, prefetch/chunk/cursor option, SQL DECLARE/FETCH, or cursor-management transaction. The standard connection factories retain their existing generic error mode and leave delivery/preparation attributes at driver defaults.

The timing boundary for each engine remains:

```text
Deterministic endpoint discovery and PDO::prepare  OUTSIDE
---------------- START TIMER ----------------
Choose next prepared window; execute one SELECT    INSIDE
Default driver execution/transfer/buffering        INSIDE
Fetch/count/discard every row through EOF          INSIDE
Statement closeCursor(); return count              INSIDE
---------------- STOP TIMER -----------------
Workload cleanup                                  OUTSIDE
```

MariaDB normally uses buffered PDO delivery and emulated preparation. PostgreSQL normally uses native preparation and libpq's full-result buffering; its first server prepare can occur during the first warmup. SQLite steps its in-process statement. Driver-default transfer/buffering performed by execute is included in timing, as is every explicit PHP fetch. Thus all measure query execution + full result delivery + minimal PHP consumption, with **documented intrinsic default-driver differences**. DBench86 does not optimize or harmonize these delivery paths.

**Campaign invariant:** stock-server and tuned-server benchmark campaigns use the exact same DBench86 code and PDO configuration. Tuning is exclusively external server configuration: my.cnf or postgresql.conf. The CLI uses an unchanged private in-memory SQLite connection, with no external SQLite tuning switch. No campaign-specific PDO attributes, result-delivery optimizations or application transport substitutions are permitted.

**Preserved scalability:** preparation retains O(warmup + runs) inputs; deterministic key sampling and bounded indexed-value retrieval, bounded rank endpoints, scalar contiguous-ID/FK generation, incremental dataset insertion, batching limits and memory metadata are unchanged. The measured PHP loop retains O(1) rows and never materializes a result-sized PHP array. However, default driver-owned result buffers may require O(returned rows) storage, and MySQL driver allocations can affect PHP-reported memory. Constant total client/process memory is no longer asserted. No PHP memory limit or database setting was changed to hide that distinction.

## Preparation, counting and verification

For N users and E = warmups + runs, execution i uses sorted timestamp ranks
i modulo N and (i + floor(N/10)) modulo N, with endpoints ordered. An untimed
ROW_NUMBER() query retrieves only the required ranks, at most 2E endpoints,
then stores E windows. Database sorting may still use time, memory and temporary
storage proportional to N. BETWEEN includes endpoints and timestamp ties, so
returned cardinality is not guaranteed to equal exactly ten percent.

The timed operation executes one plain SELECT, fetches associative rows through
false/EOF, counts/discards them and closes the statement. It performs no content
hashing or full result-array materialization. Metrics count workload attempts,
not wire round trips or individual SQL statements. Driver buffering/transfer costs
are part of this measured path and can differ between engines.

[RangeConsumptionTest.php](../tests/Integration/RangeConsumptionTest.php) observes
the actual statement's SQL, execute count, fetched rows and EOF inside the timer.
It compares with an independent count outside timing and checks that MariaDB
buffering remains unchanged. [ScalabilityTest.php](../tests/Unit/ScalabilityTest.php)
checks preparation bounds and range cardinalities. Run `php tests/run.php`.

The historical cursor/streaming experiments and their benchmark/memory results
are omitted because they do not describe current default PDO delivery. No
performance claim for this submitted protocol is inferred from those experiments.
See [harness memory](../MEMORY_SCALABILITY.md) for separate preparation bounds.
