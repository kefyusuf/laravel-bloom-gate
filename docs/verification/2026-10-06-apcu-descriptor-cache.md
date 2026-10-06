# APCu descriptor cache production verification

## Scope

Baseline: `93d30a09fe49095a96ce0c8fb62fe11a3b5ddd0e`.
This increment adds opt-in APCu descriptor hints and one bounded refresh of a
cached control-state change. Every lookup retains the existing atomic Redis
authorization. Published `v0.1.0-rc.2` remains unchanged.

The cache uses versioned primitive payloads, validates every field and matches
current application semantics. Its namespace combines an explicit application/
environment/backend identifier, application base path, Redis connection name and
Bloom prefix. TTL is 1–86400 seconds after successful resolution; warm reads do
not extend it. Missing/disabled APCu or malformed/cache-failure entries are misses.
Memory-backed filters use the no-op cache. No Redis schema or Lua changes.

## TDD and local checks

- QueryGate cache tests failed before implementation; focused cache/gate checks
  then passed. A second RED caught unnecessary warm-hit writes; the fix retains
  the original expiration instead of extending it on every lookup.
- Configuration RED rejected nothing before implementation; GREEN rejects an
  unsupported driver, missing namespace and invalid expiration settings.
- Native APCu payload/isolation tests failed before the adapter existed, then
  passed with the real extension. Disabled APCu fallback passed separately.
- Production-only baseline ZIP consumer under real PHP-FPM failed the independent
  second-request assertion: expected one Redis operation, observed three. Its
  setup and cold three-operation assertion passed first.
- Full local PHP 8.4 / Laravel 13 suite with native APCu and live Redis:
  1,127 passed, 8,620 assertions. One skipped disabled-APCu scenario passed in a
  separate disabled-extension run (one test / five assertions).
- Full PHPStan passed; Composer validation and dependency audit passed.
- The first full Redis run had one fixture-configuration failure:
  `LaravelRedisRuntimeDiagnosticsIntegrationTest` expects Redis default AOF off /
  fsync everysec; the FPM fixture needs AOF on / fsync always. Separate task-owned
  Redis services resolved this without modifying production or existing tests.
- Existing PHP warnings about redundant global imports remain in the full suite.

## FPM verification

The task uses a production-only archive-backed consumer, two static PHP-FPM
workers, native APCu, nginx, MySQL and PostgreSQL. Each HTTP request constructs
a fresh Laravel application and captures actual Redis script and SQL counts.
The executable driver checks separate-request sharing, real rebuild recovery,
metadata and health fallback, and live bitmap changes before timed measurements.
Candidate runtime source: `d0504fb40e02466912d8911e690e37e3c2be1f4f`.
Installed src/config files match the exact commit ZIP byte for byte; the
[provenance manifest](evidence/2026-10-06-apcu-source-provenance.json) preserves
archive and installed-file SHA-256 values. The consumer has no Testbench.
PHP 8.4.26 / Laravel 13.34.0 / MySQL 8.4.11 / PostgreSQL 17.11 were used.
The local verification package uses a fixture-only `dev-apcu-proof` version.

Both database drivers passed all guards. Separate cold/warm requests were
handled by different FPM workers (PIDs 8 and 7): three Redis operations became
one. A real rebuild caused exactly four operations (two probes, one snapshot,
one contract), no SQL, and the next request returned to one probe. Metadata
corruption bypassed with one probe and one SQL lookup. Unhealthy state bypassed
with one probe plus one snapshot and one SQL lookup. After restoring either
condition, the evicted hint was resolved normally. A rollback pre-add changed
the bits: the same warm hint produced MaybePresent and an authoritative false,
demonstrating that membership answers are never cached.

Five measured passes follow one warmup with alternating path order. Median
query-loop wall times for 1,000 lookups, 100 known-present inputs:

| Engine | Direct SQL | SQL-only gate | Uncached gate | APCu cold request | APCu warm request |
|---|---:|---:|---:|---:|---:|
| MySQL | 158.58 ms | 171.78 ms | 618.16 ms | 269.20 ms | 259.77 ms |
| PostgreSQL | 442.14 ms | 474.11 ms | 633.74 ms | 286.39 ms | 279.59 ms |

All gate paths executed 108 SQL lookups (100 positives plus eight false
positives). Redis operations were 3,000 uncached, 1,002 for a cold APCu request,
and 1,000 warm. Warm APCu reduced gate time about 58% on MySQL and 56% on
PostgreSQL. It beat direct SQL by about 37% on PostgreSQL; MySQL direct SQL
remained faster. A single cold lookup still costs three Redis operations.

Single-lookup query-loop medians were MySQL direct 0.310 ms / uncached 1.213 ms /
warm 0.813 ms and PostgreSQL direct 3.950 ms / uncached 1.434 ms / warm 0.762 ms.
These include lazy gate service resolution. End-to-end HTTP measurements include
bootstrap and transfer (for example, MySQL direct 8.90 ms / warm 8.99 ms).
The small single-query samples are not a claim of universal speedup.

Complete samples and guard replies: [MySQL](evidence/2026-10-06-apcu-mysql.json),
[PostgreSQL](evidence/2026-10-06-apcu-pgsql.json).

The first candidate installation reused a local ZIP URL. Composer metadata
advanced but reinstall still used the old installed package's archive URL. The
cross-request guard caught three operations instead of one; these failed runs
are excluded from measurements. A unique commit-specific ZIP URL/checksum and
fixture version forced the intended archive installation. Byte comparisons,
not Composer reference metadata alone, established provenance before GREEN.

Run the fixture only against disposable, task-owned databases and an isolated
FPM cache: setup resets its `users` table and fixture controls clear APCu.
`tests/Distribution/Fpm/nginx.conf` is a complete nginx configuration; mount it
at `/etc/nginx/nginx.conf`. Mount `pool.conf` as the FPM `www.conf`. Copy
`fpm-fixture.php`, `fpm-benchmark-driver.php`, and `benchmark-profile.php` beside
the consumer's production-only `vendor` directory and make its bootstrap/storage
directories writable by the FPM worker. Required environment: `REDIS_HOST`,
`PILOT_PROFILE=standalone-primary-durable-v1`, `FPM_MYSQL_DSN`, `FPM_PGSQL_DSN`,
`PILOT_DB_USER`, and `PILOT_DB_PASSWORD`.

```sh
php fpm-benchmark-driver.php http://nginx/fpm-fixture.php mysql
php fpm-benchmark-driver.php http://nginx/fpm-fixture.php pgsql
```

No timing threshold or universal speedup is claimed. Tests use 1,000 indexed rows
and sequential requests on Windows-hosted Linux containers; concurrency, remote
database cost and production acceptance are outside this evidence.

## Review and resource ownership

Independent source review found no blocking correctness/security issue. Its
warm-write observation was addressed with a failing test and a narrow fix.
Required local `composer check` passed (lint, max PHPStan, fast suite). Native
APCu cases were additionally exercised in the full FPM-image CLI suite and the
HTTP checks, and Quality CI now enables APCu for its fast suite.

Task Compose project: `lbg-apcu-20261006`; task-built PHP image:
`lbg-apcu-fpm:20261006`. Resource cleanup is recorded after verification.
