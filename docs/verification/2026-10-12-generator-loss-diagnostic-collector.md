# Bounded loss-diagnostic collector — 2026-10-12

## Scope

Implement the [reviewed plan](../plans/2026-10-08-generator-loss-diagnostic.md)
as a separate collector mode. This increment changes orchestration only; no
native generator qualification or diagnostic HTTP load is executed here.
Previous INCONCLUSIVE results and the cause of lost scheduled work remain open.

The new path must validate the existing safety negative before one 4,800 RPS /
25 ms / 1,024 VU cell, with 30-second warmup and 60-second measurement. Fixed
budgets, source/image readback, worker identity, observation coverage, resource
headroom and admission remain mandatory. The existing short diagnostic and
twelve-cell qualification retain their behavior.

`-LossDiagnostic` is mutually exclusive with `-Diagnostic`, before the first
Docker command. The long mode reuses `VALID_NEGATIVE_CONTROL` and retains the
attempt reference before starting its one positive profile. The optional profile
attempt record is populated with the collected cell, coverage and evaluation
before each related failure can escape. Evaluation remains null when coverage
prevents it; referenced paths alone do not assert that files exist.

The successful long outcome is `DIAGNOSTIC_ONLY` with `NOT_REPRODUCED`; cell-level
admission does not become aggregate qualification. Coverage/admission failure
retains `INCONCLUSIVE`, its reason and the attempted profile. Observer termination
still does not immediately cancel k6; the collector waits for its bounded load
and drain. No retry, tolerance change or extra cell is added.

## Verification boundary

Tests invoke the real PowerShell collector subprocess in an owned synthetic
Git checkout. They control only the external Docker command responses; the
collector and image/provenance helper scripts remain real. Synthetic command
responses establish orchestration behavior, not installed runtime identity,
actual CPU quotas, native timing, capacity or evaluator correctness. Existing
package tests and configured CI supply their separate regression coverage.

The test checkout has a real clean Git identity so the collector's source guard
can run. No business application or private database is accessed. Tests clean
their own temporary checkout/process resources; no real Docker resource is
created, started or removed.

The orchestration test entry is
`pwsh -NoProfile -File tests/Experiments/Swoole/test-collector.ps1`, requiring
PowerShell 7, Git and Python 3 standard library. The Quality workflow runs the
same subprocess checks. Default qualification and short diagnostics use no
attempt parameter and preserve their existing outcomes.

## Local evidence and open verification

The interrupted continuation retained genuine mode/profile RED/GREEN evidence.
Completion added coverage and admission RED/GREEN at the same real subprocess
seam. Seven final isolated cases passed with 97 assertions using the earlier
capture harness; default qualification completion is still unverified locally.
Syntax checks passed for PowerShell and the Python boundary fixture.

Combined Windows invocations hit the 180-second subprocess bound. A targeted
probe recorded an exited process and incomplete asynchronous output readers,
after all task cleanup commands. Direct file capture also exposed an output-file
handle still in use after process exit. These observations do not identify a
collector/runtime root cause. The candidate harness now uses a bounded Python
launcher with file handles owned by its subprocess context, rather than parent
PowerShell asynchronous output readers. It forwards the actual PowerShell CLI
and keeps per-child environment overrides. Independent review rejected its
initial inner timeout: exit 124 could be accepted as an expected failure while
descendants survived. The parent is now the only deadline owner (180 seconds),
killing/waiting the full process tree and always rejecting an ordinary timed-out
case. A controlled cleanup-stage stall reproduces the leaked output handle RED;
the corrected deadline kills the recorded descendant PID and passes two checks.
That regression uses a shorter 30-second parent deadline and never changes the
collector/runtime budgets. Process handles are disposed in finally.

The first candidate's Linux Quality CI passed all eight orchestration cases;
that does not validate this later review correction. Final source now has nine
cases and requires refreshed complete combined CI and independent review.
Local combined Windows completion remains unverified.

Retained [evidence](evidence/2026-10-12-generator-loss-diagnostic-collector/)
distinguishes successful slices from failed combined runs. Do not treat a file
whose name includes `green` as proof without its result. Exact-head configured CI
and independent final review must complete before merge or a native source freeze.

## Next boundary

After subprocess RED/GREEN, independent exact-head review and configured CI,
freeze one unique task label, final merged source and exact executable command
in a separate run record. Do not change tracked sources during collection or
retry to obtain a passing result. One isolated cell does not reproduce the old
five-cell history; an absent failure is NOT_REPRODUCED, not a fix.
