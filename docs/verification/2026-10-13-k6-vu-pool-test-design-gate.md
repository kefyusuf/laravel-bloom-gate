# Pinned k6 VU pool — deterministic component-test design gate (2026-10-13)

## Decision

**DESIGN ONLY — implementation not authorized by this gate.** Follow the
[merged warmup-loss triage](2026-10-13-warmup-loss-offline-triage.md)
without reopening the native run. The retained outcome stays
**INCONCLUSIVE** (431,549 completed + 451 dropped = 432,000 scheduled).
The objective is to characterize a *possible* no-receiver scheduler
interleaving, not to explain the 451 real-run drops, establish throughput,
patch k6 runtime behavior, or change zero-drop admission.

The source anchor is upstream k6 v1.3.0 commit
`5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb` with the existing
[half-open arrival patch](../../tests/Experiments/Swoole/K6Boundary/arrival-slot-boundary.patch).
The actual
[`ConstantArrivalRate.Run`](https://github.com/grafana/k6/blob/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb/lib/executor/constant_arrival_rate.go#L195-L369)
uses `vusPool.TryRunIteration()` and records a `DroppedIterations` sample
if dispatch fails. The shared real
[`activeVUPool`](https://github.com/grafana/k6/blob/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb/lib/executor/ramping_arrival_rate.go#L498-L559)
uses an **unbuffered** `iterations` channel and a **nonblocking** send.
Two configured/activated VUs alone do not guarantee that either receiver
is parked on that channel at each dispatch.

## Minimum implementation seam (proposal, not code)

Only these package-owned files may need test-harness changes:

1. `tests/Experiments/Swoole/K6Boundary/arrival_boundary_test.go` —
   introduce **one** focused `TestBoundary...` characterization and
   deterministic, channel-driven gate/metric-drain control. Reuse
   `setupExecutorTest`, upstream `MiniRunner` and the real executor.
2. `tests/Experiments/Swoole/K6Boundary/verify.ps1` — in the
   `go test -overlay` path **only**, add a tightly anchored test overlay
   replacement for the upstream
   `lib/executor/ramping_arrival_rate.go` pool file. Reject source
   mismatch or non-unique hook anchor before invoking Go. The existing
   `constant_arrival_rate.go` patch/clock overlay stays unchanged.

The pool overlay can introduce a *test-only* receive gate immediately
**after** the `close(ch)` activation handshake in `AddVU`, **before**
`for range p.iterations`. The injected hook must report entry and wait
on a test-owned release channel. Do not replace `TryRunIteration()`,
change `iterations` buffering, intercept the dropped metric, change
activation accounting, or copy the pool/scheduler into the repository.
The hook's coordination variable may live in the overlay file itself;
the ephemeral `_test.go` file reads/sets it while testing the same Go
package. Neither variable nor hook may be present in `runtimeOverlay`
used for upstream regressions or the binary build.

**Pre-dispatch readiness:** `AddVU` returning after `close(ch)`
does **not** guarantee that its newly started goroutine has entered the
test-only receive gate. Use the **existing test clock seam**
`boundaryNewTimer` (invoked by the real `ConstantArrivalRate.Run`
*after* planned-VU activation and *before* the first timer reset) to
wait for **two distinct gate-entry acknowledgements** before offering
slot zero. The acknowledgements must be generated only from inside
the actual pool goroutines after entering the injected gate; use a
bounded, synchronized channel, not sleeps or sampled VU gauges.
On missing/duplicate acknowledgement or watchdog expiration, fail
closed and call the common release function so the executor can exit.
Do not change the real executor's slot loop, `TryRunIteration()`, or
drop metric to enforce the barrier. The normal boundary tests must
continue to run with this readiness wait disabled.

**Lifecycle:** keep the existing clean-checkout + exact-SHA verification
and temporary `lbg_arrival_boundary_test.go` creation/removal in a
`finally` block. Test overlays must stay under task-local `.build/`;
original pinned sources remain untouched. A test failure must also
unblock all receiver gates (including on watchdog expiry), preventing
a hanging `vusPool.Close()`. Run no network/HTTP workload.

**Single-owner release contract:** the success threshold, finite watchdog,
and every error/cleanup exit **must** invoke one shared idempotent
`releaseAllReceivers()` operation. Use a `sync.Once`-guarded closure of
**one broadcast release channel** (or an equivalent single coordinator).
Never independently close per-receiver channels from competing paths;
the same release must unblock *every* gated goroutine, including if
the watchdog and third-drop observation race. Keep release/gate state
test-only, with no hook in the original runtime overlay or built binary.
Watchdog expiration is a test failure, **not** an alternate success path.

## Deterministic proof and limit

Use a tiny virtual-time scenario with `rate=3/s`, `duration=1s`,
`preAllocatedVUs=maxVUs=2`. The actual bounded half-open schedule
offers exactly **3** slots. Hold **both** activated receiver goroutines
before their channel receive; observe the genuine executor's metric
channel, then release the gate **only after** all three drop samples are
observed. Assert:

- Both **distinct** test-only hook entries were acknowledged
  *before* the first timer dispatch; no activated receiver could read
  from the pool during any of the three offered slots.
- Actual completed runner work **0**, actual `DroppedIterations` **3**,
  and completed + dropped **3**. The original dropped-sample path and
  schedule count must remain unchanged.
- Release and shutdown complete with an explicit finite *watchdog*
  (timeout for deadlock protection, **never** `time.Sleep` for ordering).
  Cleanup leaves no goroutines waiting on the test gate.

A fixture-specific **RED** gate must prove the new test fails closed
when its receive-gate seam is omitted or not reached (e.g. missing
hook-entry acknowledgment). This is a harness readiness check, **not**
evidence of an upstream functional defect. **GREEN** means the test-only
hook actually suspends both receiver goroutines and the original k6
executor reports exactly the expected dropped samples. If the RED case
cannot be made deterministic without monkey-patching the scheduler's
decision or manufacturing a metric, **stop**, and do not claim RED→GREEN.

Re-run the unchanged
[`TestBoundaryUnavailableVUsRemainDroppedWork`](../../tests/Experiments/Swoole/K6Boundary/arrival_boundary_test.go)
for the distinct **busy-VU** condition and the terminal-slot and
striped-schedule cases. Upstream
[`TestConstantArrivalRate*`](https://github.com/grafana/k6/blob/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb/lib/executor/constant_arrival_rate_test.go)
provides the existing non-synthetic executor regressions; do **not**
create a fragile new "always ready VU" assertion against the virtual
clock. If possible, run `-Race` on a supported host after the baseline
suite passes. Build/check the original non-instrumented binary via
`-UpstreamChecks -BuildBinary` and verify no hook in provenance/runtime
source or image.

## Work sequence and acceptance

| Order | Verification artifact | Acceptance / stop |
| --- | --- | --- |
| W0: exact-source check | Git SHA, clean pinned checkout, original source and patched-boundary hashes | STOP on drift |
| W1: test seam proposal/review | Unique source anchor, test-only overlay map, safe release and cleanup design | STOP if production binary is touched |
| W2: fixture RED | Controlled missing/disabled-gate failure (no synthetic dropped counter) | STOP on flaky or non-discriminating RED |
| W3: real executor GREEN | Two held receivers, three real drops, zero completed, exact total | STOP on timeout, hidden work or mismatched counts |
| W4: regressions | Existing boundary and upstream tests, supported race run, hook-free binary provenance | STOP on regression; no qualification claims |
| W5: independent review | Exact-head evidence and one narrowly scoped test-only PR | No merge until review and CI gates |

## Self-review

- **Scope:** bounded to a **test-only characterization** of existing
  pinned k6 behavior; zero new native attempts, production features,
  budget changes, provider abstraction or HTTP traffic.
- **Causality:** synthetic receiver suspension can demonstrate one
  scheduler mechanism only. It cannot attribute the frozen 451 drops
  to CPU throttling, the receiver, or an actual no-receiver instant.
- **Invariants:** 432,000 terminal-slot accounting, no hidden drops,
  zero-drop qualification, historical INCONCLUSIVE and negative-control
  verdicts remain frozen.
- **Dependency:** only the Go fixture/verification harness may change in
  later implementation; no Laravel, Core, Driver or release modification.
- **Evidence:** feasibility read from pinned upstream and committed
  fixture; **no tests or build executed by this design review**.
- **Final gate:** proposal is ready for independent review; **STOP
  before implementation** if test-only isolation or a deterministic
  failure/cleanup proof cannot be maintained.
