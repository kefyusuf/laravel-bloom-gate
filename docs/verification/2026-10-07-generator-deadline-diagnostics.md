# Generator deadline diagnostic — 2026-10-07

Outcome: **DIAGNOSTIC_ONLY**. One frozen short run completed without observed
loss or early responses. This is not generator qualification or an application
speed comparison. Preserve PR91's INCONCLUSIVE record unchanged.

## Source and method

Measured source: `072e9b8373d8dcbd651cc50915349d5cdf5ffeb2`, PR #92.
The synthetic native OpenSwoole receiver now rechecks an absolute `hrtime`
deadline after every timer wake. Early wakes rearm without blocking the request
loop. Static callback construction avoids retaining response state in a closure
cycle. Requests enter the measured window by scenario time rather than successful
iteration index. No production code or private application data was involved.

The reviewed collector ran once at 4,800 RPS, 1,024 preallocated VUs, 25 ms delay,
5 seconds warmup and 15 seconds measurement. Receiver budget: 4 CPUs/4 GiB;
generator: 2 CPUs/256 MiB. Paused k6 started after the independent observer was
ready. Its REST API remained inside the task network with no host ports.

See the [plan](../plans/2026-10-07-generator-deadline-diagnostics.md) and
[current official runtime assessment](../architecture/2026-10-07-measurement-runtime-assessment.md).
OpenSwoole remains the fixture runtime; alternatives require separately equivalent
fixtures and evidence before any comparison or migration.

## Observed result

| Observation | Result |
| --- | --- |
| Total completed requests | 96,001 |
| Measured requests | 72,002 |
| Final-summary dropped iterations | 0 |
| Errors, parity failures, false negatives, unknowns, early responses | 0 |
| Independent receiver started/completed | 96,001 / 96,001 |
| Receiver failures / timer allocation failures | 0 / 0 |
| Timer rearms | 46,316 |
| Minimum measured receiver duration | 25.001918 ms |
| Measured HTTP p50 / p99 | 25.820936 / 27.37018891 ms |
| Measured elapsed / effective rate including drain | 15.025 s / 4,792.146 RPS |
| Observation coverage | OBSERVED |

Four stable receiver PIDs handled 24,003/24,000/23,999/23,999 requests.
Three measured resource samples recorded generator CPU up to 193.12% and
receiver CPU up to 76.29%; memory reached 10.29% and 12.04% of their respective
limits. No OOM or restart occurred; k6 exited 0 and the receiver remained running.
These sparse resource samples do not establish saturation or explain past loss.

The timeline contains 21 observed snapshots and one terminal unavailable poll.
Measurement spans epoch milliseconds 1791387658538–1791387673563; the last
observed snapshot is 1791387673107 and terminal unavailability starts at
1791387674107, after the window. REST snapshots omit `dropped_iterations` when
zero: absence is not a sampled zero. The zero above comes from the final summary.
REST trend statistics are cumulative, not interval quantiles. Generator cgroup
timestamps have one-second resolution; raw throttling counters are retained.

## Limits and next gate

The measured count is two above the nominal 72,000. Millisecond clock resolution
is a hypothesis, not a demonstrated explanation. Assess the time/count boundary
before full qualification; do not relax the existing ±1 guard to fit this result.
The normal report correctly returns INCONCLUSIVE for this short cell because
its fixed qualification envelope requires 30/60 seconds. The outer outcome is
DIAGNOSTIC_ONLY, never PASS.

Multiple collector changes and the short duration prevent causal attribution.
The 0/25/100/150-ms qualification profiles with three successful repetitions
remain unrun for this source. SQL calibration, paired application comparison,
long-run stability and production performance remain unverified.

## Verification and retained evidence

RED/GREEN evidence covers early wakes, callback lifetime, window admission,
unavailable observations and failed polls overlapping the window. Local Pint
and maximum-level PHPStan passed. Package checks passed 934 tests/7,659 assertions
with 13 optional skips; measurement/report checks passed 67/76; actual native
HTTP verification passed 1/78. The independent reviewer inspected source, raw
results and coverage timestamps. Its attempted standalone coverage CLI rerun
was blocked by automatic review due to possible artifact overwrite; it used
read-only retained evidence instead and made no independent recomputation claim.

[Retained artifacts](evidence/2026-10-07-generator-deadline-diagnostics/artifact-manifest.json)
identify the measured source and SHA-256 digests of 22 normalized UTF-8/LF files.
Only ANSI/trailing whitespace were removed from logs; observations were retained.
Final documentation-head hosted CI and review are merge gates, separate from
the measured implementation revision.

The task's `lbg-generator-20261007-deadline` containers/network and its built
`lbg-generator-php:20261007-deadline` image were removed. No named volumes or
worktrees were created. Existing pinned k6/runtime images and unrelated Docker
resources were retained; no global prune or main-stack volume operation ran.
