# Frozen generator warmup loss — offline evidence triage (2026-10-13)

## Decision and scope

**Outcome remains INCONCLUSIVE.** This is an offline comparison of already
retained evidence from one `-LossDiagnostic` attempt, not another load
invocation, an evaluator modification, a capacity qualification or a root-cause
claim. The source was frozen at
`5ae2688d9f3d4485b06c66f52cac7791a2c286cc`; the evidence was merged by
[PR #103](https://github.com/kefyusuf/laravel-bloom-gate/pull/103)
at `55376ffb406862c82be0b5cd30702542dfe50ee0`.
See the [immutable result](2026-10-13-generator-loss-diagnostic.md) and its
[retained artifacts](evidence/2026-10-13-generator-loss-diagnostic/).
This review reads those records; it does not independently replay the load,
verify image execution, or rehash every artifact.

The only question in this increment: **what do the retained counters and
scheduler source establish about lost work during warmup, and what do they
not establish?** No production/application, SQL, Redis or release work is
included.

## Read-only evidence reconciliation

| Fact | Retained observation | Interpretation boundary |
| --- | --- | --- |
| Scheduled work | 432,000 total slots = 431,549 completed + 451 dropped | Nominal total reconciles; zero-loss admission fails |
| Measurement | 287,991 actual-window responses; HTTP p99 32.292 ms | Fast successful requests cannot compensate for 451 missing iterations |
| First drop visibility | Snapshot starting `1791860082952` does not include the drop metric; next at `1791860083952` reports 451 | First *observed* nonzero value, not the precise instant or shape of the loss |
| Warmup boundary | Nominal start `1791860075371`; measurement starts `1791860105371` | Drops were visible by approximately 8.6 s into the 30 s warmup, about 21.4 s before measurement |
| Later snapshots | Drop counter remains 451 through the last successful observer row | No *additional observed* increases; unavailable metric samples must not be rewritten as measured zero |
| VUs | Fixed `preAllocatedVUs=maxVUs=1024`; k6 summary `vus.max=416`, `vus_max.max=1024` | No sampled active-VU peak at 1024; does not prove that an idle VU was available at every scheduled dispatch |
| cgroup | At epoch seconds 1791860082 → 1791860084, `nr_throttled` 9 → 21; `throttled_usec` 1,908,862 → 4,977,893; `usage_usec` 15,633,135 → 23,491,521 | Deltas: +12 throttle events, +3,069,031 throttled µs, +7,858,386 CPU-used µs. Sampling/clock granularity precludes attributing individual drops |
| Warmup Docker stats | Generator CPU `466.78%` at `1791860083405`; later warmup samples include `234.52%` and `295.70%` | This is a point-in-time collector observation under a configured 4-CPU budget, not proof of a budget bypass or exact quota interval |
| Measurement Docker stats | Generator maximum `237.83%` for the existing measurement-window sample selection | The previous warmup spike is *not* part of the admission resource maximum; does not establish warmup headroom |
| Observer | 91 observed rows; one unavailable `k6_metrics/transport_or_read_unavailable` row starting `1791860165976` | Unavailable row begins 586 ms **after** measurement ends, and cannot explain the earlier warmup drop |
| Coverage | Actual measurement interval `[1791860105371,1791860165390)` is OBSERVED | Coverage validity is distinct from the failed zero-drop admission |

The deliberately deficient *negative control* is separate (2,512 expected
drops); its valid safety result does not make any positive-cell drop acceptable.
The later source record also shows no final parity, unknown-membership,
false-negative, HTTP-error, receiver-failure or unfinished-work counter; this
does not certify scheduler completeness.

## Mechanism check against the frozen generator

[The collector's `load.js`](../../tests/Experiments/Swoole/load.js) uses
`constant-arrival-rate` with exactly 1,024 preallocated and maximum VUs,
30/60-second windows and fixed 4,800 RPS. The package-owned
[arrival-slot patch](../../tests/Experiments/Swoole/K6Boundary/arrival-slot-boundary.patch)
bounds global terminal slots and checks cancellation after timer selection.
It **does not change** VU pool admission, VU activation, iteration duration,
drop accounting or CPU quotas.

In the pinned upstream k6 source
[`constant_arrival_rate.go`](https://github.com/grafana/k6/blob/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb/lib/executor/constant_arrival_rate.go#L315-L367),
a scheduled timer dispatch tries `vusPool.TryRunIteration()`. Failure to
obtain an available VU at that dispatch records a dropped iteration; it
cannot be recovered later. That is the scheduler-level mechanism for the
counter. These artifacts do not reveal *why* a free VU was unavailable at
each lost dispatch (busy VUs, scheduling delay, CPU throttling, initialization
timing, or another interaction). A sampled `vus` gauge is not a per-slot
pool-availability trace.

## Exact active-VU pool behavior and test feasibility

Read-only inspection of the **same pinned upstream revision**, rather than a
new implementation, locates `activeVUPool` in
[`lib/executor/ramping_arrival_rate.go` lines 498–559](https://github.com/grafana/k6/blob/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb/lib/executor/ramping_arrival_rate.go#L498-L559).
The pool shared with `ConstantArrivalRate.Run` creates **an unbuffered
`chan struct{}`** and uses a **nonblocking** `select` send in
`TryRunIteration()`; the default branch returns `false` if no receiver
is ready at that instant. `AddVU()` runs a goroutine that receives from
that channel, runs the actual iteration, then returns to receive again.
The metric `vus` represents running/active work, not an atomic proof that
a receiver is parked at the very moment of a dispatch. Therefore
`vus.max=416` versus `vus_max=1024` **does not establish** that a
`TryRunIteration()` call could not fail. That mechanism is possible,
not an attribution of the observed 451 losses.

The existing
[boundary component fixture](../../tests/Experiments/Swoole/K6Boundary/arrival_boundary_test.go)
already calls **the real** `ConstantArrivalRate.Run` plus upstream
`activeVUPool`, `MiniRunner` and original drop metric; it controls
external time/context using a Go overlay. Its busy-VU case produces
visible dropped work, but **does not** distinguish receiver-not-ready
from genuinely occupied VUs in the frozen real run. The
[pinned upstream tests](https://github.com/grafana/k6/blob/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb/lib/executor/constant_arrival_rate_test.go#L286-L397)
already cover saturated VUs, aggregate dropped work and active VU
bounds; none provides per-dispatch warmup availability evidence.

**Feasibility verdict: LIMITED / DESIGN ONLY.** The current test
architecture plausibly supports a separately reviewed, deterministic
non-HTTP reproduction through a *test-only* readiness barrier in the
actual pinned pool and controlled executor timer. Such a barrier would
demonstrate the scheduler's response to an artificial no-receiver
interleaving; it would **not** demonstrate that the same interleaving,
CPU throttling or a host contention event caused the real run.
Do not introduce that overlay into the production binary or silently
alter k6's drop accounting.

| Proposed deterministic case | Required discriminating assertion | Explicit limit |
| --- | --- | --- |
| Ready receiver before a timer dispatch | The real pool accepts a slot; no artificial drop | Demonstrates fixture control, not 4,800 RPS capacity |
| All available workers intentionally occupied | The real executor records a drop when a slot is offered | Already partially covered by the existing busy-VU fixture |
| Temporarily no receiver parked at dispatch, even with VUs configured | The real nonblocking channel path rejects a slot and reports an actual drop; release eventually permits subsequent work | Artificial scheduling interleaving, not proof of warmup or quota root cause |
| Terminal bound and account reconciliation | Accepted work plus drops equals the exact bounded offered slots under each synthetic case | Do not weaken the 432,000-slot and zero-loss admission rules |

If a barrier needs a copied scheduler/pool or unbounded native timing to
work, **reject the design** rather than adding a second implementation.
Before implementation, a focused proposal must specify exact overlay
source paths, cleanup of the ephemeral test file, synchronization,
timeouts, RED before GREEN, and the existing `-race` and
unmodified-binary regression checks. This review authorizes no such
code, no new diagnostic, and no load replay.

## Hypotheses, not findings

| Candidate | Why inspect it | What evidence is missing |
| --- | --- | --- |
| H1: warmup CPU quota/throttling interaction | Throttle deltas and a warmup CPU sample overlap the interval in which drops first appear | Per-slot timestamps, queue/pool state and aligned CPU quota intervals; co-occurrence alone does not establish causality |
| H2: transient VU availability or scheduler timing | Real executor explicitly drops on `TryRunIteration()` failure despite a configured 1,024-VU ceiling | Deterministic runner/pool observations at dispatch time; aggregated/sampled VU counts are insufficient |
| H3: receiver-side latency/concurrency contributes to VU occupancy | Occupancy depends on actual iteration lifetime | Synchronized per-slot VU availability and receiver queue/iteration lifetime around the loss, not only whole-run latency percentiles |
| H4: terminal observer failure causes the drops | **Contradicted by ordering as an explanation of the warmup drop** | The observer failure begins after measurement; it remains a distinct reliability issue without demonstrated impact on this loss |

The older [patched qualification](2026-10-08-generator-qualification-patched.md)
lost 1,102 slots and stopped in its sixth positive cell with an unavailable
measurement observation. The new isolated run lost 451 slots early in warmup
but retained measurement coverage. Different surrounding cell sequence, host
readback and failure timing mean the latter cannot establish the former's
root cause or reproduce the same failure mechanism.

## Boundary for the next work unit

1. **Offline only:** inspect the pinned, installed-source-matched k6 executor
   and actual `activeVUPool`/VU-activation path, identifying exactly which
   events and metrics exist at `TryRunIteration()` failure. Inventory the
   existing deterministic executor tests before proposing instrumentation.
2. **Separate design gate:** if retained evidence cannot distinguish H1–H3,
   specify the smallest deterministic **non-HTTP component RED → GREEN
   reproduction** using the actual pinned executor/pool; require a proof that
   failed dispatch remains visible as a drop and does not mutate the corrected
   terminal-slot contract. Avoid a second model/scheduler implementation.
3. **Independent review before execution:** assess whether such a fixture is
   viable without widening production/package scope, modifying the frozen
   evidence or changing acceptance limits. If not viable, record the information
   gap and stop. Instrumentation or a new native run is **not authorized**
   by this document.
4. Any future native timing would require another independently reviewed
   frozen-source plan and explicit authorization. Preserve four CPUs / 4 GiB,
   two CPUs / 256 MiB, 1,024 VUs, 4,800 RPS, 30/60 s, zero drops, source/image
   provenance, negative-control safety, observer coverage, resource headroom
   and task-scoped cleanup. No retries to PASS.

## Self-review / stop gate

- **Scope:** PASS — historical records and pinned upstream source only; no
  feature, Docker, network load, new backend or benchmark claim.
- **Invariant preservation:** PASS — zero-loss criterion and both historical
  INCONCLUSIVE outcomes remain unchanged.
- **Causal integrity:** PASS — snapshot absence is not a measured zero;
  temporal co-occurrence and sampled resource statistics are not causation.
- **Dependency direction:** NOT APPLICABLE — documentation only; no Core,
  Driver, Laravel or release changes.
- **Verification evidence:** READ-ONLY — existing committed summary,
  cell, timeline, cgroup, resource data, load script and pinned executor
  were inspected. No new test, raw reproduction or full artifact-hash audit.
- **Decision:** STOP before implementation or another load. The next gate
  is a narrow offline executor/pool feasibility review, not qualification.
