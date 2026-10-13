# Frozen single-cell loss diagnostic — 2026-10-13

## Outcome

**INCONCLUSIVE:** `dropped_iterations must be zero.` One authorized invocation
at clean merged source `5ae2688d9f3d4485b06c66f52cac7791a2c286cc` passed the safety
negative and attempted exactly one long 25 ms diagnostic cell. No retry, source
change, tuning, extra cell or tolerance relaxation occurred. Independent
read-only preflight confirmed the freeze before load.

| Stage | Actual-window responses | All-run completions | Drops | HTTP p99 ms | Result |
| --- | ---: | ---: | ---: | ---: | --- |
| Negative: 1,200 RPS / 150 ms / 128 VUs | 4,207 | 5,888 | 2,512 | 152.397 | VALID_NEGATIVE_CONTROL |
| Diagnostic: 4,800 RPS / 25 ms / 1,024 VUs | 287,991 | 431,549 | 451 | 32.292 | INCONCLUSIVE |

Positive completed + dropped work is exactly 432,000; negative accounting is
exactly 8,400. Positive client/builtin and independent receiver completion
counts agree at 431,549, with no pending/failed receiver work. Error, parity,
false-negative, unknown-membership, early-delay and unfinished counters are
zero. These counters and the low p99 do not override lost scheduled work.

## Loss and observer evidence

The cumulative drop counter changes from zero in the snapshot starting at
`1791860082952` to 451 in the next snapshot starting at `1791860083952`.
The latter is about 8.6 seconds after nominal load start and 21.4 seconds before
measurement starts at `1791860105371`. All 451 final drops are already visible
during warmup; the last successful snapshot still reports 451. Snapshot start
times do not identify individual iteration-loss times or a causal event.

Adjacent generator cgroup records at epoch seconds `1791860082` and
`1791860084` increase `nr_throttled` from 9 to 21 and `throttled_usec` from
1,908,862 to 4,977,893. Their timestamp granularity and sampling intervals differ
from HTTP observations. They establish co-occurring throttling observations,
not why the scheduler dropped work or that quota/host contention caused it.

The positive timeline contains 91 observed rows and one unavailable row.
Coverage evaluates **OBSERVED** for actual measurement
`[1791860105371, 1791860165390)`. The unavailable row starts at
`1791860165976`, 586 ms after that interval ends. It identifies `k6_metrics` /
`transport_or_read_unavailable`; request end is `1791860168984` and monotonic
elapsed time is 3,008.590362 ms. Receiver polling was not attempted in that row.
The observation's overall end is `1791860168985`.

This terminal endpoint failure is outside measurement, around load shutdown;
it does not invalidate the admitted coverage or explain earlier warmup loss.
The failure class cannot distinguish DNS/connect/read mechanisms. A stream read
timeout setting is not a measured end-to-end deadline. Unlike the previous
failed qualification, no unavailable observation overlaps measurement here.
The old sixth-cell history and old endpoint failure have not been reproduced.

Measured Docker-stats maxima are generator 237.83% CPU / 13.08% memory and
receiver 115.01% CPU / 18.54% memory, with 12 resource samples. They stay below
the existing measured-window headroom limits; they do not prove warmup headroom
or absence of short peaks. No OOM, receiver restart or generator exit failure
is reported. `generator_saturated=true` is derived from dropped work in
`load.js`; it is not a separate CPU-saturation measurement.

## Provenance and retained evidence

Task: `20261013-loss-diagnostic-v1`. Command:

```powershell
pwsh -NoProfile -File tests/Experiments/Swoole/qualify-generator.ps1 -Task 20261013-loss-diagnostic-v1 -LossDiagnostic
```

PR102's reviewed head `4993b6607b9849401249484157196cf0c9ca68af`, with six passing
configured CI jobs, has the same Git tree as the frozen merged source. All 16
installed fixture source readbacks match the pre-run freeze. Verified k6 binary
SHA-256 is `80dfd2c0b536ddbb9aa5a09dff9c5aef6fe4b63fea66aad45cb7bad818ab6b3b`;
patched runtime source digest is
`e5cbf62b0eebd7088df5090046adf83d1793fed47279810d3300546cc724ccce`.

Receiver image is `sha256:a59caaa91c9727c8b016a1f831d311be660baa9658dc8f9b6f524caed8a2b36d`;
generator image is `sha256:b9c3c90d274afe0fab87f3aaa93d11e76c7582429bdf4b26f56935d63aa867ae`.
Runtime readback is PHP 8.4.26 / OpenSwoole 26.2.0, stable worker PIDs 12–15.
Generator budget remains four CPUs / 4 GiB; receiver remains two CPUs / 256 MiB.
Host Docker readback is Linux, 12 CPUs / 16,440,242,176 bytes RAM. Unrelated host
workloads were retained; host state differs from the earlier qualification.

[Evidence](evidence/2026-10-13-generator-loss-diagnostic/) retains 23 artifacts,
including freeze, provenance, attempt, raw summaries, timeline/cgroup/resources,
coverage/evaluation and failed outcome. The manifest hashes normalized UTF-8/LF
tracked content; log extensions become `.txt`, ANSI/trailing whitespace is
removed. Unrelated host container identifiers are redacted only in the published
freeze copy; their count is preserved. Result values and source/image identities
are unchanged. Only package-owned synthetic traffic ran; no business app/data
was accessed.

## Cleanup and next boundary

The collector removed its two task containers, unique Compose network and both
task image tags/images. Post-run checks found no remaining task container,
network or image. No named volume or worktree was created. Existing host
containers, bases and build cache remain; no global prune ran.

Retain INCONCLUSIVE and stop. Investigate the warmup scheduling/initialization
loss from this evidence before proposing another bounded hypothesis or TDD fix.
Do not fix only observer coverage, rerun to PASS, increase budgets, lengthen
warmup to hide loss or relax zero-drop admission. This single diagnostic does
not qualify generator capacity, SQL/application speed or production stability.
