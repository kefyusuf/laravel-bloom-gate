# Frozen generator qualification with early negative-control safety

The user authorized continuation to the full qualification prepared by PR94.
Its clean merged source `eff6e863fe8d65f195a6803025822726e56cfb6f` failed
independent preflight before any load: negative-control safety was checked only
after all positive profiles, contrary to stopping on the first failure.
The unused task image was removed; no timing/outcome exists for that source.

First use the approved report/actual CLI seams with TDD to expose negative
validation separately. Invoke it before the positive loop and reuse it in final
aggregate validation. Retain all existing safety, count, resource and latency
guards. Preserve RED/GREEN evidence, reviewed source commit and exact-head CI.

Then freeze the corrected clean source for one collector invocation, task
`20261008-qualification-v1`:

- Negative:1200 RPS,150 ms,128 VUs,2-second warmup/5-second measurement.
- Positive:three blocks of0/25/100/150 ms,4800 RPS,1024 VUs,30-second warmup/
  60-second measurement,15-second graceful drain maximum per cell.
- Generator4CPUs/4GiB; receiver2CPUs/256MiB; four stable workers; pinned native
  OpenSwoole/k6 images and installed-source digests/readbacks.
- Require VALID_NEGATIVE_CONTROL before any positive load. Require zero positive
  loss/correctness failure, versioned completeness/actual-window reconciliation,
  throughput/latency, OBSERVED coverage and resource safety for every positive.
- Aggregate PASS needs all12 distinct cells at the same identity. Stop first
  failure; preserve partial evidence and INCONCLUSIVE. No tuning or rerun to PASS.

Do not change source, branches or tracked documents during collection. After
completion retain normalized raw evidence/digests outside .build, update report/
handoff, obtain independent final review and exact-head CI before merge.
No SQL calibration, application/private-data access, runtime migration or
production speed claim belongs to this attempt. Remove only current-task stack/
network/built image; preserve unrelated Docker resources. No worktree is needed.
