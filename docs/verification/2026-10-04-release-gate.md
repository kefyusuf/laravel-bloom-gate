# Deep release gate verification — 2026-10-04

## Gap and change

At `main@6d50ef6`, Release Gate resolved six PHP/Laravel combinations and the
PHP 8.3/Laravel 12 minimum dependency set, but ran only `composer check`.
That command deliberately excludes Redis tests. Earlier full-matrix evidence
was local Docker evidence, not an execution of the committed deep CI gate.

The manual Release Gate now gives each job its own health-checked Redis 8
service and PhpRedis extension. All seven jobs run the full test suite and
Laravel package discovery after the existing quality checks. The minimum set
also receives the dependency audit already present in the six regular jobs.
This implements ADR-0027's deeper release boundary without expanding the fast
required PR gate or changing package runtime behavior.

## Local verification

- PHP 8.4.26/Laravel 13.34.0 against the isolated default-profile Redis fixture
  on port 6380: `composer test:all` passed 1,064 tests / 8,304 assertions.
- `composer audit` reported no vulnerability advisories; package discovery passed.
- An independent negative control selected the existing Laravel Redis executor
  integration test with unreachable port 1. It failed rather than skipping,
  confirming that an unavailable Redis dependency cannot produce a passing
  full-test gate. No Redis configuration was changed.
- The prior main Quality run passed. No production source, test expectations,
  dependency constraints, tag or publication changed in this increment.

## CI acceptance

Dispatch `release-gate.yml` on the candidate branch, and verify that its
`headSha` matches the candidate commit. Before merge, all six compatibility
jobs and the minimum dependency job must pass audit, quality, full tests and
package discovery. Independently check the required PR Quality result and
review the workflow diff. Local success does not substitute for these CI results.

This gate verifies the tested dependency resolutions and stock Redis test
profile. It does not establish production AOF durability, external authoritative
history completeness, provider ZIP/Packagist installation or deployment recovery.
