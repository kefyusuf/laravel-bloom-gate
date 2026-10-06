# Single-host shared-memory evaluation design

**Status:** proposed experiment, not production support.
**Baseline:** `3b31156b5b38786b19765d64104cf37077a7eebf`.
**Purpose:** decide whether removing Redis from the membership query path gives
a repeatable latency benefit without weakening authoritative correctness.

## Existing decisions

[ADR-0003](../adr/0003-redis-production-backend.md) still defines Redis as the v1
production backend. This experiment does not supersede it.
[ADR-0039](../adr/0039-safe-query-gate-atomic-authorized-probe.md) requires coherent
authorization of the descriptor and bitmap. A fast unqualified bitmap is not
a replacement for `AuthorizedProbe`.
[ADR-0043](../adr/0043-coordinated-adoption-recovery-boundary.md) forbids treating
lost coordination evidence as permission to resume ordinary mutation.

The previous [FPM experiment](../verification/2026-10-06-redis-evalsha.md) found
that the optimized Redis path still lost to inexpensive direct MySQL queries.
It did not measure concurrent HTTP tail latency. The new experiment must compare
paths in the same runtime, rather than attributing Octane's bootstrap saving to
the Bloom backend.

## First scope

- One Linux Docker host, one OpenSwoole parent/server and four HTTP workers.
- PHP 8.4, Laravel 13, Octane and OpenSwoole. Record and pin the exact versions
  that pass the capability gate; no extension is required by the package.
- A blank package-owned application with deterministic Eloquent factory data.
- MySQL with 1,000,000 unique synthetic membership keys, then a PostgreSQL repeat
  only if the first performance gate passes.
- `immutable-v1`: seed first, exclude every membership-entry writer, then seal
  the SQL dataset and construct the filter. Consumer credentials have SELECT
  permission only; setup credentials are not supplied to HTTP workers.
- No business-application access, source copies, customer data or paid services.
- No mutable `preadd-v1`, multi-server sharing, production migration, public
  driver/config addition, release or automatic recovery in this experiment.

This can answer whether a single-host immutable backend is worth pursuing.
It cannot qualify Redis replacement for a live mutable application.

## Experimental storage and authorization

All experimental classes live under `tests/Experiments/Swoole/`. Core, hashing,
`QueryGate`, published drivers and default configuration remain unchanged.
Provide existing `BloomDriver`, `ActiveGenerationSnapshotReader`,
`GenerationContractStore` and `AuthorizedProbe` ports through fixture bindings.
Do not copy the current sparse Memory driver and call it production storage.

Create shared tables in the parent before workers start. Use a packed bitmap,
not one PHP array entry or table row per bit. Store 4,096-byte chunks with exact
length and SHA-256 integrity fields. Integrity checks for touched chunks are
part of the measured query path, not omitted benchmark setup.

The sealed generation manifest binds layout, semantic fingerprints, dataset
digest, row count, build digest and runtime incarnation. Verify the complete
manifest before publication. The HTTP runtime cannot modify a sealed generation.
Managed probe inputs must still use the existing normalizer and hash/layout.

Use a fresh 128-bit runtime incarnation. Fixture filter names include it:
`demo.<32 lowercase hex characters>.members`. This puts incarnation identity
inside the existing descriptor's filter name; do not rely on an unshared PHP
map or silently extend the public descriptor. Reject a previous-incarnation
descriptor before inspecting membership.

A single fixture publisher owns the control row. One whole-row update publishes
active version, monotonic revision, health and manifest identity together. A
reader obtains that row, validates the descriptor, reads only immutable chunks,
then reads the whole control row again. A changed tuple cannot authorize a
negative. Do not infer multi-row transactions from Table's per-row locking.
The capability gate must verify the actual pinned runtime's row operations.

Permit two sealed generations only and retain both until runtime shutdown.
The first publication and one replacement are enough for this experiment.
A third publication is rejected before changing active state. This bounds
memory and avoids inventing reader-reclamation machinery before a benefit exists.
Missing rows, failed table allocation/set, malformed metadata, integrity failure,
unhealthy state or revision drift must bypass to SQL or raise an explicit
programming/protocol error; they must never become a trusted negative.

Startup is SQL-only. Full parent restart creates a new incarnation and stays
SQL-only until a newly sealed dataset has been explicitly verified/published.
A worker restart may reuse the parent's verified tables; test that separately.
There is no force unlock, TTL-based safety claim or automatic adoption.

## Fair measurements

Measure these paths through the same one-lookup Laravel HTTP endpoint, with the
same worker count, database connections, normalized inputs and response format:

1. direct indexed SQL;
2. SQL-only QueryGate bypass, as an overhead control;
3. Redis with descriptor hints and EVALSHA, retaining live authorization;
4. the experimental shared-memory authorized probe, with no Redis lookup.

Record the actual Redis path when APCu is unavailable in the chosen runtime;
do not label uncached Redis as the best existing Redis configuration. Keep the
earlier FPM result as a separate runtime reference, not a causal comparison.

Use 50%, 90% and 99% absent inputs, all from the same deterministic sequence.
Check every response against expected membership from the sealed dataset.
Exhaustively probe all 1,000,000 seeded keys before timing: zero false negatives.
Positives and false positives always consult SQL. Record SQL fallback counts.

Use k6 constant-arrival-rate HTTP traffic. Calibrate direct-SQL sustainable
capacity first, then offer identical rates at 20%, 50%, 80% and 100% of that
capacity to every path. Use a 200-VU ceiling, report actual active concurrency
and dropped iterations. A closed-loop client count is not a substitute for an
equal offered-load comparison. Keep the load generator in its own container.

Warm up for 30 seconds; collect at least 10,000 responses over at least 60
seconds per measured cell. Repeat three times with rotated path order. Record
client HTTP p50/p95/p99, achieved RPS, failures/drops, package query duration,
SQL/Redis counts, CPU, RSS, shared-table memory and seed/build/startup time.
Controls run before and after each block. More than 10% direct-SQL p99 drift
makes that block inconclusive. Limit the initial screen to 90% misses at 80%
SQL capacity; do not run the full matrix if it fails the decision gate.

Pin CPU/memory budgets, image digests, dependency lock, schema/data digest and
installed package source. Use idle task-owned services and record host contention;
do not stop unrelated services. Timings include the real integrity/safety checks.
Performance thresholds belong to this manual evaluation, not flaky timing CI.

## Decision gates

**Safety gate:** every membership result equals the sealed SQL dataset; zero
false negatives, unexpected errors and uncontrolled writes. Publication races,
corrupt/missing generation, wrong semantics, worker restart and parent restart
pass deterministic assertions. A failed safety gate stops timing work.

**Performance gate (proposed):** all three paired initial-screen runs show at
least 20% lower HTTP p99 than both direct SQL and the configured same-runtime
Redis path, with no dropped work, no unexpected errors and at least 95% of each
comparison path's achieved RPS at the same offered rate. The benefit
must remain measurable with integrity checks enabled and stable controls.

If the screen passes, run the full absence/load matrix, a PostgreSQL repeat and
a ten-minute steady-load soak. After a two-minute soak warmup, RSS growth must
remain within 10%; shared tables must remain within their fixed capacity. Report
the actual memory budget, not only a percentage. A benefit limited to a particular
database or negative ratio is a limited-profile result, not a general speed claim.
If the configured Redis path lacks a supported cache/connection optimization,
state that limitation; it is not evidence of beating every Redis configuration.

**Verdict:** GO for another bounded design step, NO-GO for this storage/runtime
combination, or INCONCLUSIVE with the precise missing evidence. Do not switch
runtimes or tune endlessly after a failure. At most one identified measurement
defect may be corrected and rerun before recording the verdict.

A GO authorizes a recommendation, not production use. Mutable coordination,
SQL outcomes surviving process loss, recovery and multi-server deployment need
a separately reviewed design and a proposed ADR before any production backend.

## Sources checked on 2026-10-06

- [Laravel Octane](https://laravel.com/framework/docs/13.x/octane): supported
  worker runtimes and Swoole tables; server restart loses table contents.
- [OpenSwoole Table](https://openswoole.com/docs/modules/swoole-table): shared
  memory and row operations. Vendor throughput claims are not our benchmark.
