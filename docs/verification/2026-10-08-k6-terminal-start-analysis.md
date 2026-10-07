# Pinned k6 terminal-start analysis — 2026-10-08

**INCONCLUSIVE remains unchanged.** This offline source review identifies mechanisms to investigate; it does not establish why the retained 25-ms cell completed six extra iterations. No load, Docker operation, runtime source modification, retry or tolerance change was performed.

## Retained observations

**Implementation detail (artifact observation); measured source `9a0d211b2aca0f81782175533fc70e411fe26473`; confidence high.** The [25-ms cell](evidence/2026-10-08-generator-qualification/20261008-qualification-v1-block-1-delay-25-cell.json) reports 432,006 all-run starts/completions, builtin iterations/HTTP requests and receiver starts/completions. This exceeds 432,000 ±1. Nominal and actual-window cohorts both contain 288,000; reconciliation is 288,000 + 0 warmup entries + 6 terminal entries − 6 early starts. Drops/errors/delay mismatches are zero. Receiver minimum duration is 25.002203 ms. Scheduling-window early starts do not mean early receiver responses. No conflicting totals were found.

## Verified algorithm and limits

The [official v1.3.0 release](https://github.com/grafana/k6/releases/tag/v1.3.0) resolves to [commit `5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb`](https://github.com/grafana/k6/commit/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb) (documented provenance; high confidence). The retained image manifest is `sha256:3ddc8b1a33a2c3d8edc6e99b6a762ae36cba08788463458f5e6a7703e14eb77d`. Source review alone does not prove binary equivalence.

| Claim | Category/version | Exact primary source | Confidence/limits |
| --- | --- | --- | --- |
| Scenario origin is captured before planned VU activation; the same origin becomes ScenarioState.StartTime. | Implementation detail; k6 v1.3.0 | [executor lines 215–313](https://github.com/grafana/k6/blob/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb/lib/executor/constant_arrival_rate.go#L215-L313) | High; initialization does not reset the origin. |
| Regular deadline is origin + duration; outer deadline adds gracefulStop. | Implementation detail; k6 v1.3.0 | [helpers 168–179](https://github.com/grafana/k6/blob/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb/lib/executor/helpers.go#L168-L179) | High; 15-second grace does not intentionally extend arrival scheduling. |
| scenario.startTime exposes origin as integer Unix milliseconds. | Implementation detail; k6 v1.3.0 | [execution 99–106](https://github.com/grafana/k6/blob/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb/internal/js/modules/k6/execution/execution.go#L99-L106) | High; submillisecond origin precision is discarded. |
| Scheduling uses integer nanosecond period × gi minus time.Since(origin), then selects timer or regular-context cancellation. There is no explicit count bound, target-time duration bound, or cancellation recheck in the timer branch. | Implementation detail; k6 v1.3.0 | [executor 317–368](https://github.com/grafana/k6/blob/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb/lib/executor/constant_arrival_rate.go#L317-L368) | High; this is a structural observation, not run-level causation. |
| Full local segment produces start 0 and repeating offset 1. Actual iteration counters use a separate synchronized SegmentedIndex, subtracting 1. | Implementation detail; k6 v1.3.0 | [striping 490–600](https://github.com/grafana/k6/blob/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb/lib/execution_segment.go#L490-L600), [index 742–787](https://github.com/grafana/k6/blob/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb/lib/execution_segment.go#L742-L787); [base 46–51](https://github.com/grafana/k6/blob/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb/lib/executor/base_executor.go#L46-L51) | High; zero drops supports cohort counts, not a retained per-VU dispatch mapping. |
| Dispatch sends an unlabelled pool token; VUs run under outer context. | Implementation detail; k6 v1.3.0 | [pool 518–557](https://github.com/grafana/k6/blob/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb/lib/executor/ramping_arrival_rate.go#L518-L557) | High; gi is not passed to the script. |
| Multiple ready select cases have no cancellation priority. | Documented; current Go specification, language semantics used by k6 v1.3.0 | [Select statements](https://go.dev/ref/spec#Select_statements), execution step 2 | High; installed binary Go build version unverified. |

**Implementation detail (derived arithmetic); k6 v1.3.0; high confidence.** [helpers 233–242](https://github.com/grafana/k6/blob/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb/lib/executor/helpers.go#L233-L242) truncates 1,000,000,000/4,800 to 208,333 ns. Target gi 432,000 is 89,999,856,000 ns (144 µs before 90 seconds); gi 432,005 is 90,000,897,665 ns. Truncation permits one nominal terminal target before the deadline; it alone cannot explain six.

Source anchors above were refreshed from the exact Git checkout in the
[component correction](2026-10-08-k6-arrival-boundary-fix.md); the earlier raw-page
renderer compressed source line numbers. The original qualification remains
INCONCLUSIVE. Controlled component reproduction now demonstrates terminal
overshoot and a bounded correction without establishing the old run's cause.

**Assumptions; retained v1.3.0 run; confidence low as causes.** Delayed cancellation delivery or selection of a ready timer despite cancellation could permit additional dispatch. Integer wall-time observations also differ from the scheduler's elapsed-time basis: [Go time documentation, Monotonic Clocks](https://pkg.go.dev/time#hdr-Monotonic_Clocks) documents time.Since versus Unix timestamps (documented; current Go, high confidence). Neither a clock fault nor a cancellation race is proven. Aggregate Trends omit paired index/timestamp and executor event traces; terminal maximum 59,996 ms cannot recover them. No source conflict establishes a cause; version-specific documentation was unavailable, so current executor documentation is not used as pinned behavioral proof.

## Bounded next-step proposal

Prepare focused, non-network component tests of the pinned executor with controlled timer/deadline event order and separate dispatch/iteration counters. A standalone scheduler model may illustrate the hypotheses, but cannot establish actual executor behavior. Test truncation alone, delayed cancellation and both-ready selection, without claiming reproduction of the retained run. Preserve 4,800/s, 90 seconds, 30/60-second windows, 1,024 VUs, 15-second grace, all count/correctness guards and actual-window latency selection. Do not suppress extra requests, shorten duration, widen tolerance or retry until green. Any later instrumented load attempt requires a separately approved, frozen diagnostic plan with a fixed single-attempt bound; this proposal authorizes none.
