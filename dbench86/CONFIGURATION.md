# DBench86 configuration

## Application connections

Application factories read process environment variables directly and pass them
to driver constructors. Canonical usernames are `DBENCH86_MARIADB_USERNAME` and
`DBENCH86_PGSQL_USERNAME`; both must be explicitly configured and nonempty.
No implicit superuser or operating-system username is used.

| Suffix | `DBENCH86_MARIADB_` | `DBENCH86_PGSQL_` |
|---|---|---|
| `USERNAME` | Required, nonempty | Required, nonempty |
| `PASSWORD` | Default empty | Default empty |
| `HOST` | Default `127.0.0.1` | Default `127.0.0.1` |
| `PORT` | Default `3306` | Default `5432` |
| `DATABASE` | Default `dbench86` | Default `dbench86` |

Set private passwords locally, never in committed files or CLI arguments.
SQLite uses `:memory:` in Application and needs no credentials.
SqliteDriver supports file paths when used directly, but this CLI does not read
`DBENCH86_SQLITE_PATH` or expose a file-path switch.

Bootstrap optionally loads `.env` from this directory using literal `KEY=VALUE`
lines. It skips comments/empty lines and does not remove quotes or interpolate
values. Its existing `if (!getenv($key))` condition permits a file value to replace
an empty or `0` environment value. Keep this file private and ignored by Git.

## Canonical tests

Run `php tests/run.php`. DatabaseCredentials uses the same canonical username
names and process environment as production, with `.dbench86-test-config.php`
as a test-only fallback. The local PHP file returns an associative array of
canonical variable names; it does not configure Application. Missing or empty
usernames remain empty, and fixture-based server tests explicitly skip before
attempting a connection. Empty process values take precedence over the fallback.
Host/port/database/password defaults match production. MariaDB fixture tests also
support `DBENCH86_MARIADB_SOCKET`, default `/run/mysqld/mysqld.sock`.

Connection smoke tests use the MariaDB Unix socket and PostgreSQL's `postgres`
database. Driver connection tests use the configured fixture database. Optional
population/comparison tests use `$_ENV`; range-consumption tests use Application's
`getenv()` factories. Exported variables reach `getenv()`; visibility in `$_ENV`
depends on PHP's existing variables_order. Bootstrap's `.env` loader populates both.
These transport/configuration paths are distinct, but none provide an implicit
username. Configure disposable databases: population tests reset their schema.

Absent optional configuration omits server population/comparison branches;
connection tests report skips on caught connection failures. Some omissions are
printed directly and are not counted by TestRunner. Configured population or
range-consumption connection failures can fail/terminate the suite. SQLite tests
run without external servers. Retain raw test output rather than totals alone.

## CLI

`php dbench86.php --help` lists the supported options. `run` is the optional
positional command. Unknown benchmark options/commands return invalid-argument exit code 2.

| Option | Actual behavior | Default |
|---|---|---|
| `--database` | Choose all, mariadb, postgresql or sqlite | all |
| `--rows` | User-table scale for deterministic generation | 100000 |
| `--runs` | Requested measured executions per workload | 30 |
| `--warmup` | Untimed-in-statistics warmup executions | 5 |
| `--batch-size` | Requested product batch rows; effective size clamped to actual driver policy | 100 |
| `--timeout` | Positive whole seconds, checked after execution | 30 |
| `--sort` | Order reported rows by successful min, median, p95 or max | median |
| `--direction` | Ascending/descending reporting via SortDirection | asc |
| `--debug` | Additional prepared-memory reporting and unexpected-error details | false |
| `--help`, `-h` | Print help without running benchmark | — |
| `--build-monolith` | Generate `dist/dbench86-monolith.php`; must be used alone | — |

Run `php dbench86.php --build-monolith` from this directory. Build mode does not
run a benchmark. The application builder generates the executable monolith from
the canonical multi-file `src/` tree; rebuild after source changes, never edit the
artifact manually. The deterministic artifact is intended for inclusion in the
submission. It runs standalone without `src/` or `bootstrap.php`, with the same
PHP 8.6 and selected-engine PDO extension requirements as the normal application.
No credentials are embedded: runtime configuration comes from process environment
variables or a local `.env` beside the monolith. See [README](README.md#standalone-monolith)
for execution and checker commands.

There is no output-file option or JSON writer. Sorting changes reporting only,
not execution order. No-success rows appear last in either direction.
Batch requests retain native clamp() and driver operational policy; the default
100-row request preserves the pre-cleanup effective workload.

Rows/runs/batch-size must be positive whole decimal integers; warmups may be zero.
Fractions, exponent notation and values above PHP_INT_MAX are rejected. Combined execution
count must fit an integer. Timeout accepts positive whole decimal seconds within
PHP_INT_MAX; fractions, signs and zero are rejected. Duration::compare() checks
it after completion without interrupting SQL. No PHP ini or server tuning occurs.

## Outcomes and statistics

Each workload reports requested, performed, successful, failed and timed-out
measured runs. Only successes contribute min/median/p95/max samples. An execution
that throws is classified as failed; a completed execution beyond timeout is
classified as timed out. No replacement duration or timeout-threshold sample is
inserted. With zero successful samples, all latency statistics are N/A.

Warmup attempts/failures/timeouts are reported separately and never enter measured
statistics. Preparation failure preserves requested runs with performed zero;
cleanup errors preserve observed measured counts and samples. Incomplete requests,
failed/timed-out runs, unsuccessful warmups and setup/cleanup errors make the
benchmark return exit code 6. Other workloads still run where possible.
