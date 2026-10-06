# Shared-memory immutable query safety — 2026-10-06

## Scope and source

Task 2 of the approved [evaluation plan](../plans/2026-10-06-single-host-shared-memory-evaluation.md).
Baseline: `4fea698662074cd4ab7ba891fdf3a1b5e1e97bfa` (capability PR #87 merged).
Branch: `test/swoole-coherent-query-gate`. Local safety gate passed; exact-head
hosted CI/PR closure must be refreshed before Task 3. This report does not grant
production support or release permission, and does not claim a performance win.
Production `src/` and default backend configuration are unchanged.

Only package-owned fixtures and synthetic factory data were used. No private
business-application source, data or environment was accessed. Runtime/image/lock
and canonical source hashes are in the [provenance record](evidence/2026-10-06-shared-memory-provenance.json).

## Implementation

Parent-created Tables provide existing snapshot, contract, BloomDriver and
AuthorizedProbe ports. The real package QueryGate and descriptor resolver decide
membership; no query algorithm is replaced. Packed chunks are at most 4096 bytes.
Every touched chunk, including those after the first unset bit, is validated for
exact length and SHA-256. The last partial chunk uses its actual expected length.

The parent verifies ordered dataset count/digest, writes the complete bitmap,
reads it back and verifies its build digest, then seals a manifest binding data,
layout, semantics, version and incarnation. A whole-row publication binds active
version, revision, health, incarnation and manifest digest. A probe compares the
whole control tuple before/after chunk reads and checks manifest identity again
at completion. Unsafe metadata, corruption or drift bypasses to SQL; layout
protocol violations remain explicit errors.

The filter name includes a fresh 128-bit incarnation. Only the allocating parent
may publish. At most two slots are allocated; failed slots are never reused and
both sealed generations are retained until shutdown. Mutation/bind/destroy through
the ports are rejected. Native Tables are writable primitives for fault injection;
the HTTP fixture exposes no publication or generation mutation endpoint.

Full SQL publication uses the SELECT-only account, requires both global SQL seal
flags, and validates exactly 1,000,000 rows with digest
`9ee8cb5c216392aacf242289162d07bc7292f5c06ffa373a51d4f7d1e10fae00`.
The seeder account is dropped, and no administrator/seeder credentials or open
publication PDO connection is inherited by workers. Small fault tests use the
same single-row scope in SQL and bitmap, with a scope-specific set fingerprint.

## TDD and independent review

- Initial native RED: 11 failing assertions because the shared domain was absent.
  HTTP RED: query endpoint returned 404 before runtime wiring.
- Native capacity admission throws an exception in this pinned runtime, rather
  than merely returning false. Both forms prevent publication; tests exhaust
  actual Table capacity and preserve existing active state on replacement failure.
- Independent review found the small fixture SQL/snapshot scope mismatch.
  `small_fixture_sql_scope_matches_published_values` reproduced it as RED; scoped
  SQL and its semantic identity made it GREEN.
- Review requested later-chunk and publication metadata regression coverage.
  Missing/corrupt later chunks, wrong count/digest, unhealthy control, revision
  mismatch and manifest/layout corruption now have native behavioral assertions.
- The new layout-change-during-probe test failed with `MaybePresent` instead of
  bypass. A final manifest identity check made it GREEN.
- Two controlled mutations in the isolated runtime proved regression sensitivity:
  returning at the first unset bit caused both later-chunk tests to fail; omitting
  dataset count/digest validation caused both publication-proof tests to fail.
  The test source was restored immediately; workspace source was never mutated.

## Local verification

PHP 8.4.26, OpenSwoole 26.2.0, Laravel 13.34.0 and Octane 2.20.0.
Native suites run with warning/risky failures enabled, without required skips:

- Capability: 4 tests, 43 assertions passed, including SELECT-only credentials.
- Query safety: 21 tests/86 assertions passed. Exhaustive probes of all
  1,000,000 seeded keys returned
  `MaybePresent`, with zero false negatives and no SQL membership calls during
  the exhaustive probe phase. Build/enumeration SQL is setup work, not included
  in the membership lookup counter. Fault scenarios validate exact SQL fallback.
- HTTP: 2 tests, 139 assertions passed. Four distinct worker PIDs returned correct
  membership and exact per-request SQL counts for sampled present/absent keys.
  Worker restart retained authorization; parent restart changed the namespace
  and used SQL for both present and absent lookups until explicit publication.
- Package `composer check` and `composer test` passed: Pint, max-level PHPStan,
  946 passing tests/7692 assertions, one optional skip in the standard runtime.
  The dedicated native gate is separate from optional package test discovery.
- `git diff --check` passed. Fixture dependencies resolve from the tracked lock;
  an ignored stale root lock was discarded only inside the isolated quality copy.

Tables reserve 8,749,104 bytes for chunks, 29,744 for control and 546,864 for
manifests: **9,325,712 bytes** total, fixed before workers start. These are actual
native allocation sizes, not worker RSS or process memory qualification.

Reproduce all three native suites with the [fixture script](../../tests/Experiments/Swoole/run.sh)
using a fresh unique `SHARED_MEMORY_TASK`. The script installs the lock, seeds,
seals, runs the required suites and cleans up its own stack/image on exit.

## Limits and cleanup

No equal-load benchmark, tail latency claim, RSS soak or mutable/multi-host
qualification has been performed. No Redis call is made by the shared path, but
same-runtime Redis comparison is still required in Task 3. Exhaustive membership
is direct authorized probing; HTTP parity and SQL counts cover selected samples,
not a million SQL queries. Whole-row observations do not prove multi-row atomicity.

Task-owned local stack `lbg-shared-memory-20261006-query` was removed: PHP/MySQL
containers, network, experiment/MySQL volumes and built image
`lbg-shared-memory-php:20261006-query`. Reused base images and
all unrelated resources remain untouched; no worktree was created.
