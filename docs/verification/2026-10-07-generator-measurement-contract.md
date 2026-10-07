# Generator measurement contract verification

Outcome: **CONTRACT_VERIFIED_LOCALLY**. No new load timing, generator
qualification, SQL calibration or application benchmark was performed.
Continue from merged PR93 (`b1584276af14e93fce3df3eda0e1ff9b27a41914`).
See the [plan](../plans/2026-10-07-generator-measurement-contract.md).

## Methodology correction

The version `scheduled-completeness-actual-window-v1` explicitly separates
two quantities that the old report compared as if they were identical:

| Gate | Required evidence |
| --- | --- |
| Nominal completeness | 288,000 cohort starts ±1, equal cohort completions; all-run 432,000 ±1 |
| All-phase correctness | Zero drops, unfinished work, errors, parity failures, false negatives, unknowns and early controlled responses |
| Independent completion | Equal positive custom started/completed, builtin iterations/HTTP and receiver started/completed; receiver failed=0 |
| Actual-window integrity | Actual start=completion=response counts; feasible crossings reconcile exactly |
| Actual-window performance | Same actual-time selection, drain-inclusive ≥4,560 RPS and unchanged delay/p99 envelope |
| Full qualification | Same frozen identity/budgets, four delays in each of three blocks, 12 distinct valid cells and raw artifact names |

The ±1 numerical tolerance is unchanged, but now checks nominal completeness.
This is a versioned methodology change, not a new time-window tolerance. Index
cohorts cannot select latency and are eligible only with zero drops, explicit
single-local execution topology and matching builtin evidence. Actual-window
differences must equal cohort + warmup crossings + terminal crossings − outside
cohort starts. Missing evidence, impossible counts or unsupported topology fail
closed. Legacy cells without the version are ineligible; PR91/92/93 observations
and their outcomes were not rewritten or promoted to PASS.

Pinned k6 schedules arrival slots separately from JavaScript execution through
the available VU pool; its drop metric identifies slots it could not start.
[Pinned executor source](https://github.com/grafana/k6/blob/v1.3.0/lib/executor/constant_arrival_rate.go#L289-L335),
[official dropped-iteration documentation](https://grafana.com/docs/k6/latest/using-k6/scenarios/concepts/dropped-iterations/).
This supports the distinction; it does not establish capacity or a clock fault.

## Collector preparation and negative control

Default collection now stops on the first failure while collecting three blocks
of 0/25/100/150-ms profiles. It passes each block into the cell and checks the
aggregate through the actual CLI before PENDING becomes PASS. Distinct raw names
include block and delay. Frozen source/image/budget identities must match across
the whole matrix. The report checks supplied observations; collector hashes,
actual image/budget readbacks and retained artifact provenance remain separate
requirements and are not authenticated by merely trusting JSON labels.

The 1200-RPS/128-VU/150-ms/2-second-warmup/5-second-measurement negative control
must lose work and be rejected for drops. Independent review found that an early
drop rejection previously skipped later safety fields. Separate validation now
requires zero all-phase correctness failures, matching builtin/client/receiver
completions, failed receiver count zero and completed+dropped=8,400 ±1. Missing
or contaminated negative controls invalidate the aggregate.

No dependencies, production backend, runtime selection, private application
data or resource budgets changed. The generator is a standalone synthetic
fixture: its runtime definition and image own its dependencies, rather than an
application/vendor lockfile. All complete identity fields must match between
cells; the collector also retains digests for installed fixture sources.

## Verification and limits

Recorded RED/GREEN covers explained actual-window crossings, explicit version/
builtin output, the three-block matrix, missing negative control, and negative
HTTP/early-response/missing-builtin safety failures. Regression cases reject
legacy or missing audit data, nominal loss, unexplained crossings, impossible
counts, duplicate/partial/mixed/failed matrix evidence and receiver failures.
The actual CLI accepts a synthetic complete matrix and rejects an 11-cell input.
These are behavioral contract fixtures, not fabricated runtime measurements.

Local Pint and maximum PHPStan passed; measurement checks passed **96 tests /
110 assertions** and actual load-script Node checks passed **5 tests**. Pinned
k6 1.3.0 script inspection and PowerShell parsing passed. No new live timing was
invoked. Final independent review and exact-head hosted CI are merge gates.

[Evidence manifest](evidence/2026-10-07-generator-measurement-contract/artifact-manifest.json)
records normalized checked source digests and local RED/GREEN outputs. Git blob
digests must match before delivery. The reviewed frozen source can next be used
for one bounded full qualification attempt; stop on failure without tuning or
retrying to PASS. SQL/application timing remains outside that attempt.

Only temporary current-task verification/inspect containers were created, all
with `--rm`. No Compose stack, volumes, built image or worktree was created.
Existing runtime/pinned k6 images, dependencies and unrelated resources were
retained. Automatic approval rejected copying an unverified build file over a
tracked source; source-to-build verification completed safely instead. No
permission or verification remains blocked by that rejection.
