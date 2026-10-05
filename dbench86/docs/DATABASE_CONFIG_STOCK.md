# Stock database configuration: reference methodology

Submission adaptation of the development capture dated 2026-10-04T21:16:20.900032+00:00.
These are historical reference observations, not a fresh audit of the reviewer's
host or minimum requirements. Machine-specific configuration archives and raw
logs are intentionally not distributed; this summary does not replace raw evidence.
No stock-versus-tuned speedup is established by a configuration capture.

The reference used two logical CPUs, about 3.73 GiB RAM without swap, and a
40 GiB virtual disk. Physical NVMe backing was unverified. Both database services
were resident. PHP was 8.6.0-dev with mysqlnd 8.6.0-dev, libpq 18.6 and SQLite 3.46.1.
Observed memory_limit was 128M and was not changed. MariaDB was 11.8.6 and
PostgreSQL 18.6. These contextual observations are not portable tuning advice.

## Effective stock settings

### MariaDB

| Parameter | Effective stock value | Origin |
|---|---|---|
| innodb_buffer_pool_size | 134217728 | COMPILE-TIME |
| innodb_buffer_pool_chunk_size | 0 | COMPILE-TIME |
| innodb_log_file_size | 100663296 | COMPILE-TIME |
| innodb_log_buffer_size | 16777216 | COMPILE-TIME |
| innodb_flush_log_at_trx_commit | 1 | COMPILE-TIME |
| innodb_doublewrite | ON | COMPILE-TIME |
| innodb_flush_method | O_DIRECT | COMPILE-TIME |
| innodb_data_file_buffering | OFF | COMPILE-TIME |
| innodb_data_file_write_through | OFF | COMPILE-TIME |
| innodb_log_file_buffering | OFF | COMPILE-TIME |
| innodb_log_file_write_through | OFF | COMPILE-TIME |
| innodb_read_io_threads | 4 | COMPILE-TIME |
| innodb_write_io_threads | 4 | COMPILE-TIME |
| innodb_io_capacity | 200 | COMPILE-TIME |
| innodb_io_capacity_max | 2000 | COMPILE-TIME |
| innodb_flush_neighbors | 1 | COMPILE-TIME |
| thread_handling | one-thread-per-connection | COMPILE-TIME |
| thread_pool_size | 2 | AUTO |
| max_connections | 151 | COMPILE-TIME |
| tmp_table_size | 16777216 | COMPILE-TIME |
| max_heap_table_size | 16777216 | COMPILE-TIME |
| table_open_cache | 2000 | COMPILE-TIME |
| table_definition_cache | 400 | AUTO |
| open_files_limit | 32184 | AUTO |
| query_cache_type | OFF | COMPILE-TIME |
| query_cache_size | 1048576 | COMPILE-TIME |
| key_buffer_size | 134217728 | COMPILE-TIME |
| aria_pagecache_buffer_size | 134217728 | COMPILE-TIME |
| sort_buffer_size | 2097152 | COMPILE-TIME |
| join_buffer_size | 262144 | COMPILE-TIME |
| read_buffer_size | 131072 | COMPILE-TIME |
| read_rnd_buffer_size | 262144 | COMPILE-TIME |
| thread_stack | 299008 | COMPILE-TIME |
| binlog_cache_size | 32768 | COMPILE-TIME |
| net_buffer_length | 16384 | COMPILE-TIME |
| log_bin | OFF | COMPILE-TIME |
| sync_binlog | 0 | COMPILE-TIME |
| character_set_server | utf8mb4 | COMPILE-TIME |
| collation_server | utf8mb4_uca1400_ai_ci | COMPILE-TIME |
| transaction_isolation | REPEATABLE-READ | COMPILE-TIME |
| sql_mode | STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION | COMPILE-TIME |
| performance_schema | OFF | COMPILE-TIME |


### PostgreSQL

| Parameter | Setting | Unit | Source |
|---|---:|---|---|
| shared_buffers | 16384 | 8kB | configuration file |
| effective_cache_size | 524288 | 8kB | default |
| work_mem | 4096 | kB | default |
| hash_mem_multiplier | 2 | — | default |
| maintenance_work_mem | 65536 | kB | default |
| autovacuum_work_mem | -1 | kB | default |
| temp_buffers | 1024 | 8kB | default |
| wal_buffers | 512 | 8kB | default |
| min_wal_size | 80 | MB | configuration file |
| max_wal_size | 1024 | MB | configuration file |
| checkpoint_timeout | 300 | s | default |
| checkpoint_completion_target | 0.9 | — | default |
| random_page_cost | 4 | — | default |
| seq_page_cost | 1 | — | default |
| effective_io_concurrency | 16 | — | default |
| maintenance_io_concurrency | 16 | — | default |
| io_method | worker | — | default |
| io_workers | 3 | — | default |
| max_connections | 100 | — | configuration file |
| huge_pages | try | — | default |
| huge_pages_status | off | — | default |
| synchronous_commit | on | — | default |
| fsync | on | — | default |
| full_page_writes | on | — | default |
| wal_compression | off | — | default |
| autovacuum | on | — | default |
| autovacuum_max_workers | 3 | — | default |
| autovacuum_worker_slots | 16 | — | configuration file |
| autovacuum_naptime | 60 | s | default |
| autovacuum_vacuum_scale_factor | 0.2 | — | default |
| autovacuum_analyze_scale_factor | 0.1 | — | default |
| autovacuum_vacuum_cost_delay | 2 | ms | default |
| autovacuum_vacuum_cost_limit | -1 | — | default |
| cpu_tuple_cost | 0.01 | — | default |
| cpu_index_tuple_cost | 0.005 | — | default |
| cpu_operator_cost | 0.0025 | — | default |
| parallel_setup_cost | 1000 | — | default |
| parallel_tuple_cost | 0.1 | — | default |
| max_worker_processes | 8 | — | default |
| max_parallel_workers | 8 | — | default |
| max_parallel_workers_per_gather | 2 | — | default |
| max_parallel_maintenance_workers | 2 | — | default |
| jit | on | — | default |

### SQLite

| PRAGMA / behavior | Observed value / interpretation |
|---|---|
| cache_size | -2000: approximate 2,000 KiB suggested page cache; not a cap on an in-memory database's total storage |
| default_cache_size | -2000; legacy/deprecated PRAGMA, observation only |
| journal_mode | memory |
| synchronous | 2 (FULL); in-memory database is inherently volatile |
| temp_store | 0 (DEFAULT); compile TEMP_STORE=1 permits file-backed temporary storage |
| mmap_size | No result row on this in-memory connection; not an observed numeric 0 |
| page_size | 4096 bytes |
| page_count | 0 on fresh empty connection, not a claim about populated benchmark storage |
| foreign_keys | 0 immediately after connect; existing createSchema() executes foreign_keys=ON before workloads; unchanged and not executed during capture |
| locking_mode | normal |
| busy_timeout | 60000 ms (observed PDO default behavior) |
| auto_vacuum | 0 |
| cache_spill | 483 on this fresh connection; derived threshold can depend on database/cache state |
| threads | 0; DEFAULT_WORKER_THREADS=0, MAX_WORKER_THREADS=8 |
| wal_autocheckpoint | 1000; WAL is not active in journal_mode=memory |
| read_uncommitted | 0 |


## Reproduce a comparison

Capture effective engine settings and their sources, versions, PHP/PDO settings
and a source fingerprint before each campaign. Record hardware and service state,
restart/cache conditioning, engine order and background activity. Keep application
source, SQL, schema, deterministic generator, seed 42,000, rows, workloads, warmups,
measured runs and timeout identical. Capture runtime values rather than assuming
configuration files or vendor defaults establish effective settings. Never include
authentication files, passwords or private keys in published observations.

SQLite in this application is a private `:memory:` connection. There is no external
server configuration profile or persistent benchmark file. A separate CLI PRAGMA
changes a different connection. SQLite remains an unchanged reference. Native
database storage is separate from bounded PHP harness preparation memory.

See [tuned reference](DATABASE_CONFIG_TUNED.md) and [range methodology](RANGE_SELECT_AUDIT.md).
