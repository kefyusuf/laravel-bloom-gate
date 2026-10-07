# Single-Host Shared-Memory Evaluation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Preserve the existing TDD -> branch/commit -> PR -> green review/CI -> merge workflow; use the existing independent reviewer at each decision gate.

**Status:** approved by the user; Task 1 merged in PR #87 at `4fea698` after
independent review and exact-commit CI. Task 2 safety implementation and local
proof complete; its PR and exact-commit hosted gate remain pending.
**Goal:** obtain a bounded GO/NO-GO/INCONCLUSIVE verdict for an immutable,
single-host Redis-free Bloom query path.
**Architecture:** a fixture-only OpenSwoole shared-memory domain provides the
existing query ports. SQL data and bitmap generations are sealed; coherent
control validation surrounds immutable bitmap reads. Production Redis and
Core remain unchanged.
**Tech Stack:** PHP 8.4, Laravel 13/Octane, OpenSwoole, MySQL, Redis and k6 in
task-owned Docker services; conditional PostgreSQL confirmation.
**Spec:** [evaluation design](../architecture/single-host-shared-memory-evaluation.md).
**Baseline:** main `3b31156b5b38786b19765d64104cf37077a7eebf`.

## Global constraints

- One host, one parent/server, four workers; immutable-v1 only.
- Exactly 1,000,000 deterministic factory-generated membership keys.
- All membership-entry writers excluded; HTTP consumers have SELECT only.
- No private application access, source reuse, new production driver or release.
- A fresh 128-bit incarnation is encoded in fixture filter names.
- Packed bitmap chunks are 4,096 bytes; touched-chunk integrity is checked.
- At most two sealed generations, retained until shutdown; monotonic revisions.
- Missing, corrupt, stale or uncertain state cannot authorize a negative.
- Parent restart starts SQL-only; no automatic adoption or force recovery.
- Same-runtime, same-load comparisons; no performance threshold in normal CI.
- Compose project `lbg-shared-memory-<task>`; task images carry that task tag.
- Track fixtures/accepted reports; only generated output belongs under `.build`.
- Create worktrees only if needed, under `.worktrees/`, locally excluded.

## Files and responsibilities

Create only under `tests/Experiments/Swoole/` during evaluation:

| File | Responsibility |
|---|---|
| `Dockerfile`, `compose.yaml`, `composer.json` | Reproducible isolated runtime and services |
| `preflight.php` | Fail explicitly if required extensions/primitives are absent |
| `runtime.php` | Construct parent-owned tables and connect them to Octane worker startup |
| `DemoMember.php`, `DemoMemberFactory.php`, `seed.php` | Synthetic schema/data and sealed dataset manifest |
| `SharedMemoryDomain.php` | Packed storage, manifests and single-publisher control row |
| `SharedMemoryPorts.php` | Existing BloomDriver/snapshot/contract/probe fixture adapters |
| `SharedMemorySafetyTest.php` | Real multi-process storage and authorization scenarios |
| `application.php` | Blank Laravel boot, fixture bindings and one-lookup HTTP route |
| `load.js`, `measure.php` | Equal-load sampling, count/parity validation and report aggregation |
| `README.md` | Exact commands, limitations and resource cleanup |

`SharedMemoryPorts.php` may contain the four short fixture adapters; split only
if they cease to be readable together. No classes are added to `src/`.
Use `BloomDriver`/`ActiveGenerationSnapshotReader`/`GenerationContractStore`/
`AuthorizedProbe` signatures exactly as declared in `src/Contracts`.
The normal package's FilterRegistry/QueryGate resolve the fixture definition;
do not replace the package query algorithm with a benchmark-specific shortcut.

Record successful images/dependencies and installed source in a tracked
`docs/verification/evidence/<date>-shared-memory-provenance.json`; dependency
resolution must be reproducible from its recorded lock/digests. Record the
verdict in `docs/verification/<date>-shared-memory-evaluation.md`.

## Review focus

- Snapshot/SQL divergence: attempted INSERT/UPDATE/DELETE denied to consumers;
  sealed row count and digest checked before publication (Task 1).
- Cross-worker copies and ABA: independent PIDs see identical tables;
  previous-incarnation descriptors rejected (Tasks 1/2).
- Torn publication or retirement: before/after control checks, retained previous
  generation and rejected third publication (Task 2).
- Allocation, missing chunks and corruption: fail to SQL instead of reporting
  absence; touch actual native shared storage in tests (Task 2).
- Benchmark bias and leaks: equal arrival rate, control drift, source identity,
  count assertions and bounded RSS soak (Task 3).

## Task 1: Capability and sealed-dataset gate

Deliverable: a reproducible blank application proves real cross-worker sharing
and a genuinely read-only factory dataset before any speed claim.

- [x] Write RED checks named `workers_share_parent_table`,
  `worker_restart_preserves_parent_table`, `parent_restart_changes_incarnation`
  and `consumer_cannot_mutate_sealed_dataset`. Assert distinct worker PIDs,
  shared worker-written contents, new namespace on parent restart, unpublished
  control state and SQL permission-denied writes. Actual QueryGate SQL-only
  behavior belongs to Task 2; a constant boolean is not evidence for it.
- [x] Run the native preflight and focused test file; missing implementation
  must fail. Missing extension/unsupported startup is BLOCKED, not a passing skip.
- [x] Implement parent-before-workers shared Table construction and the minimum
  Octane startup bridge. Pin the tested versions/image digests in the fixture.
  OpenSwoole is the first candidate; do not silently substitute another runtime.
- [x] Implement deterministic Factory seeding in bounded batches, indexed exact
  membership keys, SELECT-only HTTP credentials and sealed row-count/digest proof.
  No writer-capable connection may remain inside HTTP workers.
- [x] Verify factory cardinality/uniqueness and reject all mutation paths. Capture
  setup time, connection behavior and actual Table whole-row semantics.
- [x] Commit to a task branch and open the first experiment PR. Run project
  Composer checks plus required native checks. Independent review gates Task 2.

Expected native commands, run inside the task runtime:

```sh
php tests/Experiments/Swoole/preflight.php
vendor/bin/pest tests/Experiments/Swoole/SharedMemorySafetyTest.php --group=swoole --filter='workers_share|restart|cannot_mutate'
```

If Octane cannot expose safely parent-created tables without production-package
changes, stop with an explicit capability/design report; do not simulate sharing
with process-local PHP arrays or install an alternative runtime automatically.

## Task 2: Coherent immutable query gate

Deliverable: actual QueryGate returns authoritative-correct results across
workers and publication/failure scenarios, with no Redis call on the local path.

- [x] Write RED tests `present_keys_never_return_definitely_absent`,
  `positive_and_false_positive_use_sql`, `negative_skips_sql_only_when_sealed`,
  `publication_during_probe_bypasses`, `old_incarnation_bypasses`,
  `missing_or_corrupt_chunk_bypasses`, `wrong_semantics_bypasses`,
  `failed_table_write_cannot_publish` and `third_generation_is_rejected`.
- [x] Run and capture intended failures before implementing fixture adapters.
- [x] Implement packed immutable chunks, exact-length/integrity validation and
  sealed manifests in SharedMemoryDomain. Publish one coherent control row with
  monotonic revision after the entire generation has verified successfully.
- [x] Implement the existing ports in SharedMemoryPorts. AuthorizedProbe validates
  descriptor/incarnation/layout/semantics/health, reads required chunks and checks
  the unchanged whole control tuple before permitting DefinitelyAbsent.
  Any drift bypasses; do not add unbounded retries or skip integrity checks.
- [x] Use explicit process barriers to force publication between authorization
  and chunk reading. Retain both generations; assert no negative from mixed state.
- [x] Verify all 1,000,000 seeded values have no Bloom false negatives, compare
  HTTP results with sealed-dataset expectations and assert exact SQL fallback
  behavior for selected positive/negative/corrupted-state cases.
- [x] Run project checks and live native tests, then commit/PR/review/merge.
  A safety failure stops Task 3 rather than being waived for benchmark speed.

Task 2 closed in PR #88, merge `bbccf82dfb7bdc09e6eeb0b9812e64a284f741a9`.
Its tested head `3846d5a539b9baa1926ce8505e9fc887251d2f33` passed native
capability (4/43), query (22/93), HTTP (2/139) and all required hosted checks.

## Task 3: Performance screen and conditional qualification

Deliverable: a reproducible decision report, not a production driver.

- [x] Write report-validation tests rejecting absent source metadata, mismatched
  offered rates/worker counts, unknown response membership, dropped samples,
  disabled integrity checks and fewer than 10,000 measured responses per cell.

The first Task 3 increment delivers only the tested report contract; see the
[contract record](../verification/2026-10-07-measurement-report-contract.md).
The next increment's real collector and equal paths are in PR #90. The actual
attempt ended INCONCLUSIVE at generator-limited SQL calibration; see the
[live record](../verification/2026-10-07-swoole-live-screen.md). No paired speed
comparison, conditional matrix, PostgreSQL confirmation or soak was completed.
The single targeted metadata correction was used; no third attempt was run.
- [x] Observe those failures; implement load.js/measure.php and the identical
  HTTP direct/bypass/Redis/local paths. Redis retains live authorization; record
  whether APCu descriptor hints are actually available in this runtime.
- [ ] Calibrate direct-SQL capacity and run the initial MySQL screen: 90% absent,
  offered load at 80% SQL capacity, 30-second warmup, at least 60 seconds and
  10,000 samples per path, three paired rotated-order repetitions.
- [ ] Reject as INCONCLUSIVE any block with more than 10% direct-SQL p99 drift,
  load-generator saturation or mismatched dependencies/data. Allow one targeted
  measurement-defect correction; do not tune paths until they happen to win.
- [ ] GO requires zero false negatives/parity failures/unexpected errors/drops,
  at least 20% lower HTTP p99 than both direct SQL and same-runtime Redis in all
  three screen runs, and at least 95% of each path's RPS at equal offered load.
  Record Redis configuration limitations. Otherwise publish NO-GO or INCONCLUSIVE
  and stop; a SQL-only win cannot establish a Redis-removal speedup.
- [ ] Only after a passing screen, run 50/90/99% absence and 20/50/80/100% offered
  SQL-capacity matrix, PostgreSQL confirmation and ten-minute soak. Record p50,
  p95, p99, RPS, counts, CPU/RSS/shared memory and build/startup costs. After
  two-minute soak warmup, RSS must grow no more than 10%; shared capacity is fixed.
- [ ] Pin source/image/lock/data evidence, limits and verdict in tracked reports.
  Existing optional-runtime tests and native required gates remain distinct from
  performance observations; shared hosted CI is not the benchmark authority.
- [ ] Independent reviewer checks the evidence; commit/PR/green merge the result.
  Remove task containers/network/volumes and task-built images; retain all reused
  resources. Remove only task-created merged/abandoned worktrees and report any
  retained unmerged worktree. Record cleanup in the report and activity log.

## After the verdict

GO means propose the next bounded design, not advertise production support.
If mutable workloads matter, the next design must preserve preadd-v1, known vs
unknown SQL outcomes, external writer fencing, ownership and evidence-bound
recovery across parent loss. It requires a separately reviewed ADR and plan;
volatile Table state alone cannot prove those guarantees.

NO-GO means stop this runtime/storage attempt and keep the proven backend.
INCONCLUSIVE means report the missing evidence without claiming speed/stability.
Do not expand to FrankenPHP/RoadRunner/APCu variants within this plan.
