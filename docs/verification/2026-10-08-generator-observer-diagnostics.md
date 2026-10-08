# Generator observer failure diagnostics

## Problem and scope

The frozen patched-generator qualification stopped with an unavailable
observation and lost scheduled work. Its generic observer message could not
identify the failing endpoint or distinguish transport/read, oversized JSON,
decoding/schema and cgroup failures. Successful earlier stages were discarded.

This change improves the actual observer CLI evidence only. It does not fix
generator loss, identify the old run's root cause or qualify capacity. No new
qualification or load profile runs; both retained qualification results remain
INCONCLUSIVE. Counts, fixed budgets, zero-loss and coverage admission are intact.

## Recorded behavior

- HTTP endpoint operations retain `request_start_epoch_ms`,
  `request_end_epoch_ms` and monotonic `elapsed_ms` under `requests` keyed by
  `k6_metrics` / `receiver_info`. Elapsed time includes read/decode/schema work;
  it is not server latency or a pure network measurement.
- Unavailable rows identify `failure_stage` and `failure_kind`. Known kinds are
  `transport_or_read_unavailable`, `oversized_body`, `invalid_json`,
  `invalid_schema` and `cgroup_read_unavailable`. CPU and memory read failures
  are distinguished as `receiver_cpu_stat` / `receiver_memory`.
- Successfully decoded earlier endpoint results and collected cgroup data stay
  in the unavailable row. Unattempted/failed data remains null. URLs, raw invalid
  response bodies and transport warning strings are not diagnostic fields.
- The first unavailable sample after a successful sample still stops polling.
  It remains unavailable even with partial data; the coverage evaluator rejects
  an unavailable interval overlapping measurement.

The original HTTP stream `timeout=0.5`, 524,288-byte body limit, one-second
cadence and configured duration ceiling of 180 seconds remain. PHP documents this
option as a [read timeout](https://www.php.net/manual/en/context.http.php), not
a proven end-to-end deadline. A measured delay alone cannot identify timeout or
which transport failure occurred. The delayed-peer test retains that uncertainty.

Optional CLI arguments 4 and 5 select the receiver URL and cgroup directory for
controlled verification. Defaults remain `http://127.0.0.1:8000/generator-info`
and `/sys/fs/cgroup`; the frozen collector supplies neither override. The cgroup
filenames remain fixed. Synthetic fixtures contain no business application data.

## Verification

The seam is the real `generator-observe.php` subprocess and its JSONL output,
with a bounded local PHP endpoint fixture and owned synthetic cgroup files.
Positive unit cases do not depend on host cgroup version or operating system.
Production defaults still read the actual receiver cgroup. No scheduler or clock is copied or
mocked. Vertical RED/GREEN evidence covers missing stage/timing/kind, discarded
partial metrics, invalid JSON/schema, oversized response and lost partial CPU.
Regression checks cover the exact size boundary, delayed transport, successful
sample followed by failure, retained cadence and unchanged coverage rejection.

Final local checks passed ten actual CLI tests / 121 assertions, the complete
measurement suite (114 tests / 245 assertions), Pint and maximum-level PHPStan
for all three changed PHP files. Independent review found a portability issue
in the original positive test fixtures; synthetic owned cgroup inputs replace
the host dependency, with its own RED proof and final GREEN verification.
Configured exact-head CI/review results must be refreshed before merge. Logs are in
[evidence](evidence/2026-10-08-generator-observer-diagnostics/), with a hash
manifest of normalized tracked content.

No new qualification image, Compose stack, volume, network or worktree was
created. The owned PHP quality container is removed at task closure; its existing
base image and all unrelated Docker resources remain. Test servers and temporary
files are scoped to the tests and cleaned in their finally blocks.

## Next boundary

Use these diagnostics only in a separately reviewed frozen timing plan. The
prior loss began before observer failure; better endpoint attribution alone
cannot satisfy zero loss. Any next capacity attempt must retain resource
headroom, safe negative control and complete observation coverage, and stop
on its first failed cell.
