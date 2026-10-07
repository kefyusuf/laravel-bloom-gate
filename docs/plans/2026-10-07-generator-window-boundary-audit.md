# Generator window boundary audit

Continue PR92 at merged source `5bbf54f4bd67113226f68b0e1dc8a61b328e584b`.
The short diagnostic had 72,002 actual-window requests versus nominal 72,000.
Existing retained observations cannot establish which requests crossed its
boundaries. Millisecond resolution remains a hypothesis, not a finding.

Preserve actual-start-time latency selection, zero-loss admission and the
existing qualification count tolerance unchanged. Do not fit a tolerance to
the observed result. Add bounded aggregate audit counters for scheduled-index
cohorts and their actual clock-window crossings. Index interpretation is
diagnostic only, for the single local executor with zero drops; it must not
replace actual-time selection or admit any lost work.

Use the already approved seams: actual load script output and external clock,
k6 execution metadata and synthetic HTTP responses. Execute the real script
with controlled boundary observations for RED/GREEN, not a copied algorithm.
Record warmup entering measurement, measured cohort starting outside the window,
and terminal extra iterations entering the window. Reconcile the counters and
retain actual-start extrema. No per-request logs or private data are needed.

After independent review of frozen clean sources, run at most one same-budget
4800-RPS/25-ms/1024-VU/5-second warmup/15-second measurement diagnostic. Its
outcome remains DIAGNOSTIC_ONLY. Stop after observation, preserve raw evidence,
and select any methodology correction separately before full qualification.
No full load matrix, SQL calibration, application benchmark or runtime change
belongs to this increment.

Official pinned k6 v1.3.0 executor source schedules slots by a monotonic clock
but starts JavaScript through the available VU pool; public startTime is truncated
to milliseconds. These are separate boundaries:
[executor](https://github.com/grafana/k6/blob/v1.3.0/lib/executor/constant_arrival_rate.go#L289-L335),
[execution metadata](https://github.com/grafana/k6/blob/v1.3.0/internal/js/modules/k6/execution/execution.go#L93-L116).
Two adjacent scenarios with graceful drain would overlap VU reservations;
avoiding overlap introduces a pause. Neither is a transparent fix to the frozen
continuous-load envelope.
