# Frozen generator qualification — 2026-10-08

## Outcome

**INCONCLUSIVE.** One invocation stopped at the first failing positive cell,
block 1 / 25 ms. Two of twelve positive cells ran; the remaining ten did not.
No tolerance change, source tuning or retry occurred. This does not qualify
generator capacity, production stability, SQL performance or application speed.

| Profile | Actual-window responses | All-run completions | Drops | HTTP p99 ms | Result |
| --- | ---: | ---: | ---: | ---: | --- |
| Negative, 150 ms / 1,200 RPS / 128 VUs | 4,207 | 5,888 | 2,512 | 152.069 | VALID_NEGATIVE_CONTROL |
| Block 1, 0 ms / 4,800 RPS / 1,024 VUs | 287,990 | 432,001 | 0 | 0.590 | PASS |
| Block 1, 25 ms / 4,800 RPS / 1,024 VUs | 288,000 | 432,006 | 0 | 27.330 | INCONCLUSIVE |

The negative cell intentionally fails ordinary admission for dropped work.
Its independent safety validation passed: 5,888 completed + 2,512 dropped =
8,400 offered, client/builtin/HTTP/receiver counts agree, receiver failures and
all error/parity/false-negative/unknown/delay-violation counters are zero.

The 25-ms cell fails `Scheduled warmup/measurement counts must match.`:
432,006 exceeds the unchanged 432,000 ±1 all-run guard. All client, builtin,
HTTP and independent receiver totals agree at 432,006. The nominal measurement
cohort is exactly 288,000. Actual-window reconciliation is
288,000 + 0 warmup crossings + 6 terminal entries − 6 early starts = 288,000.
Early starts are scheduling-window observations, not early receiver responses.
Minimum receiver duration was 25.002203 ms. Drops, unfinished work, errors,
parity failures, false negatives, unknown membership and delay violations are
zero; observation coverage is OBSERVED. The 0-ms reconciliation is
288,000 + 0 + 1 − 11 = 287,990 and its nominal cohort remains 288,000.

The six extra total starts are an observed count failure, not an established
clock fault or executor root cause. The next bounded step is offline analysis
of retained terminal-start evidence and the pinned k6 executor's duration
termination behavior. No new timing or relaxed guard follows from this result.

## Frozen provenance and execution

Measured source: `9a0d211b2aca0f81782175533fc70e411fe26473`.
Task: `20261008-qualification-v1`. Eleven source digests, image identities,
four worker PIDs, receiver counters, timeline coverage and resource observations
are retained in [evidence](evidence/2026-10-08-generator-qualification/).
The [manifest](evidence/2026-10-08-generator-qualification/artifact-manifest.json)
hashes UTF-8/LF tracked artifacts; logs omit ANSI and trailing whitespace only.

Runtime was PHP 8.4.26 / OpenSwoole 26.2.0. Fixed budgets: generator 4 CPUs /
4 GiB; receiver 2 CPUs / 256 MiB; four workers. Docker host readback was 12 CPUs
and 16,440,238,080 bytes RAM. Positive cells used 30-second warmup and 60-second
measurement. Neither cell had OOM, receiver restart or generator exit failure.
At 25 ms observed generator CPU max was 215.53% (four-CPU limit), receiver
85.72% (two-CPU limit); memory maxima were 13.04% and 20.96% of their budgets.
These observations do not establish the cause of the count failure.

## Early safety correction and verification

Independent preflight found that negative-control safety previously ran only
after all positives. The previous-source preflight was withdrawn before any
load; its unused image was removed. The actual CLI RED test failed, then the
small correction reused final aggregate validation before positive collection.
Healthy deficient controls return VALID_NEGATIVE_CONTROL; contaminated controls
stop collection. Production package code and qualification thresholds did not
change. Local Pint/PHPStan and 97 measurement tests / 116 assertions passed.

Frozen-head hosted Quality passed 946 package tests / 7,692 assertions (one
optional skip), 97 measurement tests / 116 assertions and five Node tests.
Compatibility, Distribution and native Shared Memory workflows passed. Native
suites passed 4/43, 22/93, 2/139, 4/395 and 1/78 tests/assertions. Independent
read-only review confirmed source digests, negative safety, both cell outcomes,
resource bounds and stop-on-first-failure behavior. Final documentation delivery
must also pass exact-head review and CI before PR #95 merges.

## Cleanup and boundaries

Removed the task's unused `lbg-generator-php:20261008-qualification` image.
The actual collector removed its own Compose project
`lbg-generator-20261008-qualification-v1` containers/default network and image
`lbg-generator-php:20261008-qualification-v1`. No named volume or worktree was
created. Existing `lbg-release-php85`, release/dependency/pinned-k6 images and
unrelated Docker resources were retained; no global prune ran.

Only synthetic package fixtures ran. No private application or business data,
SQL/application comparison, production migration, full three-block qualification
or soak was performed. Earlier INCONCLUSIVE and DIAGNOSTIC_ONLY evidence retains
its original meaning.
