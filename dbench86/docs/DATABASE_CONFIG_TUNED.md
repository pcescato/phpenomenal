# External database tuning: reference profile

Submission adaptation of the development observation dated 2026-10-04T21:26:02.172452+00:00.
The development record reports this profile applied and verified at that time.
No settings were applied or reverified during submission packaging. No final
stock-versus-tuned benchmark was recorded in that capture, and no speedup is claimed.
Raw machine archives and activation logs remain development material and are not
included; the summary is not independently reproducible evidence of past activation.

The reference host had two logical CPUs, about 3.73 GiB RAM, no swap and a 40 GiB
virtual disk with MariaDB 11.8.6 and PostgreSQL 18.6 resident together. Physical NVMe
backing was unverified. Values are operational choices for that experiment, not
engine maxima or general recommended settings.

## Recorded explicit changes

| Engine | Parameter | Stock | Tuned (requested) | Effective | Purpose |
|---|---|---|---|---|---|
| MariaDB | innodb_buffer_pool_size | 128 MiB | 768M | 768 MiB | cache |
| MariaDB | innodb_log_file_size | 96 MiB | 256M | 256 MiB | redo capacity |
| MariaDB | key_buffer_size | 128 MiB | 16M | 16 MiB | memory budget |
| MariaDB | max_connections | 151 | 32 | 32 | connection budget |
| PostgreSQL | shared_buffers | 128 MiB | 512MB | 512 MiB | cache |
| PostgreSQL | effective_cache_size | 4 GiB | 1536MB | 1536 MiB | planner estimate |
| PostgreSQL | maintenance_work_mem | 64 MiB | 128MB | 128 MiB | maintenance |
| PostgreSQL | autovacuum_work_mem | -1 (inherits 64 MiB) | 64MB | 64 MiB | autovacuum budget |
| PostgreSQL | wal_buffers | 4 MiB (automatic) | 16MB | 16 MiB | WAL buffering |
| PostgreSQL | min_wal_size | 80 MiB | 256MB | 256 MiB | WAL recycling |
| PostgreSQL | max_wal_size | 1 GiB | 2GB | 2 GiB | checkpoint pressure |
| PostgreSQL | checkpoint_timeout | 5 min | 10min | 600 s | checkpoint scheduling |
| PostgreSQL | max_connections | 100 | 32 | 32 | connection budget |
| PostgreSQL | max_parallel_workers | 8 | 2 | 2 | CPU budget |
| PostgreSQL | max_parallel_workers_per_gather | 2 | 1 | 1 | CPU budget |
| PostgreSQL | max_parallel_maintenance_workers | 2 | 1 | 1 | CPU budget |


## Rationale and limits

Larger page caches aim to reduce reads; larger redo/WAL capacity and longer
checkpoint intervals aim to reduce write pressure, with more disk use and potentially
longer recovery. Lower connection/parallel-worker budgets reduce concurrency capacity
and can reduce throughput. effective_cache_size is a planner estimate, not reserved
memory. Explicit autovacuum_work_mem preserves the previous effective worker
allowance after increasing maintenance_work_mem. Shared and per-operation memory
must be budgeted across both services together, including PHP, native result buffers,
SQLite storage and the OS. These expected effects are hypotheses, not measurements.

## Important unchanged settings

MariaDB: flush_log_at_trx_commit=1, doublewrite=ON, binary logging OFF and sync_binlog=0, modern data/log buffering and write-through booleans, I/O capacities 200/2000, read/write I/O threads 4/4, flush_neighbors=1, one-thread-per-connection, query cache OFF, Aria cache 128 MiB, log buffer 16 MiB, temp/heap table limits 16 MiB each, table caches, per-connection buffers, collation, charset, SQL mode, isolation and optimizer switches remain stock. Thread-pool parameters are not tuned because the pool is inactive. Async I/O thread settings are not casually equated with CPU-bound thread counts.

PostgreSQL: work_mem=4 MiB, hash_mem_multiplier=2, temp_buffers=8 MiB, checkpoint_completion_target=0.9, random_page_cost=4, seq_page_cost=1, effective_io_concurrency=16, maintenance_io_concurrency=16, io_method=worker, io_workers=3, max_worker_processes=8, huge_pages=try, JIT and planner enable/cost controls stay stock. Background worker capacity is not reduced merely to mirror two CPUs. Autovacuum remains ON with 3 max workers, worker_slots=16, existing thresholds, naptime and cost settings. No manual VACUUM/ANALYZE is run for only one profile. Stock snapshots document defaults and file sources.

## Frozen campaign contract

Only external server configuration changes between stock and tuned campaigns.
Keep DBench86, PHP/PDO defaults, SQL, schema, generator, seed, rows, workloads,
warmups, measured runs and timeout identical. Compare source fingerprints and
effective settings before/after. There are no application-side optimized code
paths, session tuning, new indexes or special PDO result-delivery modes.
SQLite's in-memory path has no external tuning profile and stays unchanged.

Record automatic consequences of the explicit changes separately, and verify
runtime units, origins and restart requirements rather than relying on successful
file parsing alone. Preserve durability/isolation/optimizer policy; the reference
kept MariaDB flush_log_at_trx_commit=1/doublewrite and PostgreSQL synchronous_commit,
fsync and full_page_writes enabled. Do not treat partially resident post-restart
service memory as loaded-workload peak memory. A restart need not mean an empty
cache; record comparable conditioning and background activity.

Any future stock restoration must be verified against the captured effective
settings. Packaging does not alter server configurations or perform restoration.
See [stock reference](DATABASE_CONFIG_STOCK.md), [README](../README.md) and
[range methodology](RANGE_SELECT_AUDIT.md) for the unchanged application protocol.
