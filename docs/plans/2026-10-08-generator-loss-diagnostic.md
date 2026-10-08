# Bounded generator loss diagnostic

## Status and question

Design for the next collector increment; not an executable freeze or a new
qualification result. PR100 is merged at
`cce340a4b9b58cf0caabcba5371ab490407d1ae1`, with observer stage/kind/timing and
partial evidence. Its diagnostics do not fix lost scheduled work.

Determine whether a single full-duration 25 ms cell records lost work and/or
observer failure, and locate those events relative to endpoint operations and
generator cgroup deltas. Correlation does not establish causality.

The [previous qualification](../verification/2026-10-08-generator-qualification-patched.md)
lost work during warmup, about 44 seconds before an unavailable observation.
It failed in the sixth cell after five admitted cells. A fresh isolated cell
does not reproduce that preceding sequence or its host conditions.

The existing `qualify-generator.ps1 -Diagnostic` schedules only five seconds of
warmup and fifteen seconds of measurement. It also skips the negative control.
It cannot cover the previous roughly 23-second first-loss and 67-second
observer-failure points. Do not reinterpret a successful short run as a fix.

## Fixed collection envelope

Add a separate loss-diagnostic collector mode; keep existing short diagnostics
and the twelve-cell qualification unchanged. The new mode must be mutually
exclusive with the short diagnostic. No new invocation is ready until the
implementation has subprocess RED/GREEN proof, independent review and exact-head
CI, and its final clean merged source is frozen in a separate run record.

One invocation, in this order:

| Stage | Rate | Delay | Fixed VUs | Warmup | Measurement |
| --- | ---: | ---: | ---: | ---: | ---: |
| Safety negative control | 1,200 RPS | 150 ms | 128 | 2 s | 5 s |
| One loss-diagnostic cell | 4,800 RPS | 25 ms | 1,024 | 30 s | 60 s |

The negative must satisfy the existing `VALID_NEGATIVE_CONTROL` evaluator before
the diagnostic cell starts. Its expected lost work is deliberate; all existing
safety/accounting requirements still apply. The diagnostic schedules 432,000
iterations, including 288,000 measurement slots, with existing 15-second maximum
graceful drain. Collector readiness, build and cleanup time are outside these
load durations. No additional cells, automatic retry, rate sweep or tuning.

Keep generator four CPUs / 4 GiB, receiver two CPUs / 256 MiB, four distinct
stable receiver workers, patched pinned k6 provenance and installed-source
readbacks. Keep zero-loss, actual-window reconciliation, latency, headroom,
two-second observation coverage, stream timeout, body bound and polling cadence.
Retain host CPU/memory readback and the current task container/image identity.
Do not stop unrelated host workloads or modify Docker resource allocation.

## Stop behavior and evidence

Reuse the real profile collector and existing evaluators. An unavailable sample
after prior success stops the observer; the current collector waits for the
bounded load and drain before rejecting coverage. Preserve that distinction.
Do not claim immediate cancellation on the first dropped iteration or missing
sample. Start no further load after a failed control or diagnostic cell.

Persist the attempted diagnostic artifact reference before coverage/admission
can throw. Retain raw summary/cell, observer JSONL with partial rows, generator
cgroup log, resource samples, receiver deltas, worker accounting, image/source
identity, coverage and cell evaluation when available, and failure reason.
Missing evaluation after a coverage failure must be explicit, not fabricated.
Cleanup must preserve these artifacts and run on each failure path.

A successful diagnostic cell may retain its existing cell-level `PASS`, but the
run outcome must be `DIAGNOSTIC_ONLY`, never aggregate qualification `PASS`.
A failed or incompletely observed run is `INCONCLUSIVE`. Successful collection
means only that this one cell supplied admissible evidence; an absent failure is
`NOT_REPRODUCED`, not proof of a fix. Neither prior INCONCLUSIVE result changes.

Analyze first cumulative-drop increase, last successful observation, first
unavailable stage/kind, per-endpoint epoch bounds and monotonic duration, and
adjacent cgroup deltas. Use actual timestamps, distinguish warmup/measurement,
and record gaps. Do not infer exact iteration-loss times between snapshots,
interval p99, a transport timeout from elapsed time alone, or CPU causality from
Docker-stats peaks. If no useful failure occurs, retain that limit and stop.

## Implementation acceptance before freezing

- Real collector subprocess tests must prove that an invalid negative prevents
  the positive cell, that a valid negative schedules exactly one 30/60-second
  25 ms profile, and that incompatible modes fail before Docker/load begins.
  A controlled Docker command seam may verify orchestration without generating
  HTTP load; it does not qualify native timing or capacity.
- Exercise coverage failure and admission failure after artifact creation:
  retain the attempted cell reference/reason, produce INCONCLUSIVE, start no
  additional profile, and clean only task-owned resources. Exercise success:
  retain DIAGNOSTIC_ONLY and prohibit aggregate PASS.
- Preserve existing default qualification and short diagnostic behavior. Run
  the narrow orchestration checks and required package/CI checks, then obtain
  independent review of the exact commit. No timing while sources are dirty.

After those conditions pass, freeze one unique task label and exact source SHA
with the final executable command, image/provenance and budgets. Never overwrite
an earlier task result. During collection change no source or tracked files.
Retain normalized evidence with Git-blob hashes in a follow-up evidence PR.
Remove only current-task containers, Compose network and built images; retain
existing bases/cache/unrelated resources. No private application/database data,
SQL calibration, runtime migration or production speed claim is in scope.
