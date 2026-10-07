# Actual k6 arrival boundary correction — 2026-10-08

## Result and limits

The real pinned k6 executor now offers exactly the nominal slots in controlled
non-network component tests. The original executor offered 432,006 slots when
the virtual 90-second cancellation was delivered 1.1 ms late; the patched
executor offers 432,000. This demonstrates and fixes a terminal overshoot
mechanism, not the established cause of PR #95's actual run.

The old qualification remains **INCONCLUSIVE**. No HTTP load, private-data
access, application comparison, production migration or capacity qualification
ran. Default Compose still uses its original pinned k6 image. The patch/binary
is an isolated fixture correction, not an activated runtime replacement.

## TDD evidence

The previously agreed seam is actual `ConstantArrivalRate.Run` behavior, observed
through accepted runner work plus actual dropped-iteration metrics. External
clock, timer delivery and context cancellation are controlled by a Go overlay;
the scheduler, striped indices, VU pool, MiniRunner and drop accounting are
upstream code. No second scheduler algorithm or HTTP endpoint is substituted.

| Controlled case | Original | Corrected | Required behavior |
| --- | ---: | ---: | --- |
| 4,800/s, 1 second, ordinary deadline | 4,801 | 4,800 | No nominal terminal slot |
| 4,800/s, 1 second, 1.1-ms late cancellation | 4,806 | 4,800 | Late cancellation cannot extend schedule |
| 4,800/s, 90 seconds, 1.1-ms late cancellation | 432,006 | 432,000 | Full nominal count remains bounded |
| Cancellation after timer selection at 300 ms, 10/s | 4 in first RED; 5 in final baseline | 3 | Cancelled branch does not dispatch |
| Fractional schedule: 3/s for 500 ms | 2 | 2 | Last eligible slot retained |
| Each half of global 10-slot striped schedule | First half 6 under late cancellation | 5 each | Global bound preserves partition |
| One VU blocked after first actual runner entry | Visible drops | Completed work and visible drops | Busy work cannot disappear |

Original cancellation counts can vary because both select branches become
ready; the corrected assertion is deterministic. Offered slots are completed
work plus drops, so virtual-time pool loss is not mistaken for capacity or
concealed to make totals pass. Independent review found a weak busy-VU test;
the fixture now waits for first real runner entry before advancing, then
requires both positive completed work and positive drops. Failure to enter
the runner fails the test explicitly.

Seven boundary tests and two segment subcases passed locally. Uninstrumented
upstream constant-arrival tests passed (five main tests and three segment
subcases); the upstream Windows timing test skipped itself. Local Go was
1.27.1/windows-amd64; the corrected runner also passed the same RED/GREEN,
upstream and uninstrumented build sequence locally with Go 1.23.7. The final
local binary provenance records Go 1.23.7; initial Go 1.27.1 proof is retained
separately. CI additionally uses Go 1.23.7/Linux and race detection.
The [retained evidence](evidence/2026-10-08-k6-arrival-boundary-fix/) includes
RED/GREEN logs and the uninstrumented binary's provenance. Final exact-head
CI and independent review gate delivery; local proof is not hosted proof.

## Minimal correction and provenance

[Pinned upstream source](https://github.com/grafana/k6/blob/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb/lib/executor/constant_arrival_rate.go#L317-L368)
has an unbounded global slot loop and no cancellation recheck in its timer
branch. The package-owned [patch](../../tests/Experiments/Swoole/K6Boundary/arrival-slot-boundary.patch)
adds exact integer `ceil(rate × duration / timeUnit)` admission of global slots
and checks regular cancellation after timer selection. No timer targets,
striped offsets, VU budgeting or dropped-iteration accounting change.
Oversized counts fail explicitly; an overflowing global index cannot reopen
the loop. The count bound is an upper bound, not a completeness guarantee.
Cancellation can still race between its check and dispatch; no atomic deadline
guarantee is claimed. Missing work, drops and errors must still fail qualification.

The source revision is `5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb`.
The verification script requires a clean checkout, applies the patch to an
owned copy, and uses separate overlays for controlled tests and the unchanged
clock binary/upstream checks. Original upstream files remain unchanged.
Go 1.23.7 initially failed vet on a nonexistent overlaid test file, before RED
execution. The runner now materializes only its previously absent test file and
removes it in a finally block before upstream checks/build. Vet remains enabled;
this compatibility failure is not behavioral RED or a passing CI result.
Local runtime source SHA-256 is
`e5cbf62b0eebd7088df5090046adf83d1793fed47279810d3300546cc724ccce`;
the binary digest and actual toolchain are in `binary-provenance.json`.
The upstream version banner alone is not patch provenance. Source citation
anchors in the previous analysis were corrected against actual Git line
numbers; its facts and historical outcome did not change.

## Next boundary and resources

Integrate a reviewed Linux image built from this exact patched source, identify
it separately from the original image, and update installed-source/image
provenance before proposing one new frozen qualification attempt. Preserve
4,800 RPS, 1,024 VUs, 30/60-second windows, 15-second drain, CPU/memory budgets,
±1 admission, zero-loss/correctness and actual-window throughput/latency gates.
Do not silently return from extra script iterations, substitute a closed load
model, widen guards, or reinterpret old cells as PASS. This component correction
does not authorize fresh timing.

No Docker operation, stack, image, volume or worktree was created or removed.
All existing Docker resources were retained. Owned ignored upstream checkout,
overlays and local binary remain under `.build/k6-terminal-fix` for review;
durable patch/tests/evidence are tracked outside `.build`.
