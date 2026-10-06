# Blank Laravel factory demo

## Scope

This fixture bootstraps a new Laravel application with its own dependency
installation. All rows are deterministic factory-generated demo data. It uses
no other application's code, database or records. Runtime package source is the
local checkout at baseline `6b070b4a7887a8cb828aacf1eae7002d4fffc7cc`;
this increment changes verification fixtures only, not published runtime.

## Local verification

- PHP 8.4.26, Laravel 13.34.0, Faker 1.24.1, SQLite 3.53.4 and live isolated Redis.
- RED: before factory seeding, the 100-record assertion failed with exit 1.
- GREEN: Eloquent Factory creates exactly 100 unique demo records.
- A 1,000-value workload contains 100 present and 900 absent values. All Bloom
  results equal direct SQL results. Direct SQL made 1,000 reads; gate SQL made
  101 in the observed run. False positives may vary and always consult SQL.
- Trusted negative, positive SQL fallback, exact-byte case semantics, prepared
  commit/rollback, retained unacknowledged lease, rebuild and disabled bypass
  checks passed. With the trusted profile unset, the fixture fails with exit 1
  at the negative SQL-skipping assertion.
- Independent source review found no blocking issue; it did not rerun Docker.
- Composer check passed: Pint, max PHPStan and 934 fast tests / 7,659
  assertions. Thirteen optional-extension cases skipped. Windows CRLF was
  normalized only in the Linux check copy; package source was not reformatted.

The factory is an Eloquent Factory subclass with a deterministic Sequence.
SQLite is always in memory. Redis has AOF enabled, appendfsync always and
noeviction in its dedicated Compose project. No shared Redis configuration is
changed. This demonstrates behavior and query counts; it does not measure HTTP,
FPM concurrency, production latency or an immutable release artifact.

## Reproduction and retention

See [fixture instructions](../../tests/Distribution/FactoryDemo/README.md).
Reusable code, Compose configuration and this report are tracked files;
generated dependencies and temporary logs are disposable. The dedicated Factory
Demo CI workflow verifies both missing-profile failure and configured success.
Hosted CI results must be inspected independently of these local observations.

Task project: `lbg-factory-demo-20261006`. Cleanup removes its two containers,
network and two volumes. No image was built. Reused PHP/Redis images and all
pre-existing Docker resources are retained. No worktree is created.
