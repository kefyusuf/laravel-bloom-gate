# Generator window boundary audit — 2026-10-07

Outcome: **DIAGNOSTIC_ONLY**. The new observation explains its count discrepancy
through cohort crossings. It does not qualify capacity, retrospectively explain
PR92's individual requests, or establish clock resolution as the root cause.

Measured source: `ddffa1d9d3ec9ba901255d48b5e2e294149d2449`, PR #93.
See the [frozen plan](../plans/2026-10-07-generator-window-boundary-audit.md).
The actual-time window and GeneratorReport's zero-loss/±1 guards are unchanged.
New aggregate diagnostics retain three exclusive index cohorts and crossings
using one captured actual start time. Index interpretation is conditional on
zero drops and matching positive custom/builtin completed totals, and applies
only to the collector's single local executor. It never controls admission or
selects latency observations. No per-request logs or private data were collected.

## One observed short run

The reviewed clean source ran once: 4,800 RPS, 25 ms, 1,024 VUs, 5 seconds warmup,
15 seconds measurement, unchanged generator 4 CPUs/4 GiB and receiver 2 CPUs/
256 MiB budgets. k6 started paused until observation readiness. No rerun occurred.

| Counter | Observed |
| --- | --- |
| All started/completed, builtin iterations, HTTP requests, receiver started/completed | 96,001 each |
| Actual-window started/completed/responses | 72,003 each |
| Nominal measurement index cohort started/completed | 72,000 each |
| Warmup index cohort entering actual window | 6 |
| Measurement index cohort outside actual window | 3 late, 0 early |
| Terminal extra index cohort entering actual window | 0 |
| Final-summary drops, HTTP errors, parity failures, false negatives, unknowns, early responses | 0 each |

Partition reconciliation: **72,000 + 6 + 0 − 3 = 72,003**.
Crossing offsets range from 0 ms to 15,000 ms relative to measurement start;
these are boundary observations, not proof of a clock fault. The pinned executor
dispatches slots to available VUs while the public scenario timestamp truncates
to milliseconds. Both scheduling delay and clock precision can affect actual
window membership. [Executor source](https://github.com/grafana/k6/blob/v1.3.0/lib/executor/constant_arrival_rate.go#L289-L335),
[timestamp source](https://github.com/grafana/k6/blob/v1.3.0/internal/js/modules/k6/execution/execution.go#L93-L116).
The prior +2 result has no retained per-request cohort information, so its
individual crossings cannot be recovered from this new +3 observation.

Minimum measured receiver duration was 25.001927 ms; measured HTTP p99 was
27.84317082 ms. Elapsed including drain was 15.025 seconds. Four stable PIDs
12/13/14/15 handled 23,999/24,003/23,999/24,000 requests. Three measured resource
samples showed generator CPU up to 178.84%, receiver up to 74.13%; memory up to
10.82% and 12.11% of their respective limits. No OOM/restart occurred and k6
exited 0. These sparse samples do not establish capacity or comparative overhead.
Coverage returned OBSERVED for epoch milliseconds 1791397661842–1791397676867.
REST trends are cumulative; absent REST drop metrics are not sampled zeros.

The normal report still rejects the short duration envelope as INCONCLUSIVE.
The actual-window +3 also exceeds the unchanged ±1 nominal-count guard. No
claim that this cell would pass full qualification is valid.

## Verification and next boundary

Four actual-load-script Node VM tests pass after recorded RED failures for
missing crossing observations and unsupported index interpretation. The tests
control external k6 metadata, clock and HTTP seams; they do not prove the real
scheduler's timing. Pinned k6 1.3.0 script inspection passed with explicit script
environment arguments. Initial inspection omitted k6's explicit environment
flags and failed with NaN; correcting the invocation required no source change.
Frozen-head hosted quality passed Pint/PHPStan, 946 package tests/7,692
assertions (one optional skip), 67 measurement tests/76 assertions, and all
four Node tests. Final documentation-head CI and independent review remain
separate merge gates.

Before full qualification, explicitly define the distinction between scheduled
cohort completeness and actual-time-window throughput/latency. Simply widening
the tolerance or changing runtime is not justified. Adjacent scenarios with
graceful drain would overlap VU reservations; avoiding overlap creates a load
pause. Neither preserves the current continuous envelope transparently.
Keep existing admission until a separately reviewed methodology contract is
implemented. Full four-profile/three-repetition qualification, SQL calibration,
application comparison and long-run stability remain unrun.

[Artifact manifest](evidence/2026-10-07-generator-window-boundary-audit/artifact-manifest.json)
retains 14 UTF-8/LF files with exact-content SHA-256 digests, including raw metrics,
timeline, resource/cgroup observations and RED/GREEN output.
Current-task `lbg-generator-20261007-boundary` containers/network and built
`lbg-generator-php:20261007-boundary` image were removed. Temporary inspect
containers used `--rm`; no named volumes or worktrees were created. Existing
pinned/runtime/dependency images and unrelated resources were retained.
