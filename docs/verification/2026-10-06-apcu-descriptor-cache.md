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
Candidate-source results are recorded after the immutable archive run.

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
Task Compose project: `lbg-apcu-20261006`; task-built PHP image:
`lbg-apcu-fpm:20261006`. Resource cleanup is recorded after verification.
