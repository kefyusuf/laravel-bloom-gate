# Generator deadline and diagnostics increment

The user approved implementation of the proposed recovery plan and current
official PHP runtime research. Start with exact delay control and bounded
lost-work diagnosis. Production code and private applications remain outside
scope. Preserve PR91's INCONCLUSIVE evidence unchanged.

Approved seams: synthetic receiver HTTP responses/counters, the external
monotonic-clock/timer boundary controlling response release, actual k6 output
and report admission. Use TDD, dedicated branch/commit, PR, independent review,
exact-head CI, then merge. No new framework or backend migration is required.

1. Reproduce early timer callback behavior with a controlled external clock,
   then recheck an absolute monotonic deadline before releasing the response.
   Verify the actual native receiver, with no blocking sleep or busy wait.
2. Retain bounded timestamped k6 counter/VU/connection/iteration observations,
   receiver counters and cgroup CPU throttling observations. Classify measured
   requests by actual start time rather than successful iteration index; drops
   must remain invalidating in every phase. Diagnosis does not itself qualify
   capacity. Do not stream millions of per-request metrics to disk.
3. Run at most one frozen short diagnostic of the previous 4800-RPS/25-ms case
   after reviewed clean source, using the same budgets. Preserve zero-loss/
   exact-delay guards. Select any subsequent correction from observed evidence;
   freeze and document it before a new qualification run. Do not retry until PASS.
4. Bounded generator qualification requires all four delay profiles and three
   successful repetitions before a fresh SQL calibration/comparison. Application
   speed and long-run stability remain separate evidence; no release is included.

Keep own Docker resources task-named and remove them on completion; retain only
synthetic sanitized evidence outside .build with exact tracked-content digests.
