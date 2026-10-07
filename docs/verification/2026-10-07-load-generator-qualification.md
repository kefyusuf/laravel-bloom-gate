# Fixed-budget load generator qualification — 2026-10-07

**Verdict: INCONCLUSIVE. The proposed generator envelope is not qualified.**
The first delayed positive profile lost scheduled work and also returned some
responses before the requested delay. Stop after this failure; do not infer SQL
capacity, application speed, Redis-removal benefit or production stability.
PR [#91](https://github.com/kefyusuf/laravel-bloom-gate/pull/91) retains the
qualification harness and evidence. Its final review and exact-head CI are
separate delivery gates.

## Scope and frozen provenance

The user approved independent generator qualification before another application
comparison. This increment used a standalone synthetic HTTP receiver, without
Laravel, SQL, Redis, private application source or business data. It changes only
verification fixtures and documentation. The package's production code,
configuration defaults and published candidate remain unchanged.

The [frozen plan](../plans/2026-10-07-load-generator-qualification.md) used a
4-CPU/4-GiB k6 generator with 1,024 fixed VUs and a 2-CPU/256-MiB receiver with
four native OpenSwoole workers. Positive profiles offered 4,800 RPS for 30 seconds
of warmup plus 60 seconds of measurement, at controlled delays 0/25/100/150 ms.
The negative control intentionally used only 128 VUs at 1,200 RPS/150 ms for
two seconds of warmup and five seconds of measurement.

Measured source: `21f37ba88cd6b064a6bf7f8ea6ae7d02211ba793`. The collector
required a clean checkout, checked LF-normalized mounted source digests against
the host, observed actual image IDs and Docker budgets, and captured worker
PIDs before and after each profile. Runtime: PHP 8.4.26/OpenSwoole 26.2.0,
with the pinned k6 1.3.0 manifest
`sha256:3ddc8b1a33a2c3d8edc6e99b6a762ae36cba08788463458f5e6a7703e14eb77d`.
No source or settings changed during the run; no tuning or repeated attempt was
performed. Subsequent report commits do not represent another timing run.

See [outcome](evidence/2026-10-07-load-generator-qualification/20261007-qualify-outcome.json),
[artifact manifest](evidence/2026-10-07-load-generator-qualification/artifact-manifest.json)
and [collector log](evidence/2026-10-07-load-generator-qualification/live-run.txt).
Tracked raw summaries, counters, sampled resources and logs survive deletion of
`.build`. Digests cover exact UTF-8 text normalized to LF; retained logs omit
ANSI decoration and trailing whitespace without changing observations.

## Observations

| Profile | Offered RPS / VUs | Measured responses | Total responses | Drops | Delay mismatches, all iterations | HTTP p99 ms | Verdict |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| Negative, 150 ms | 1,200 / 128 | 3,453 | 5,853 | 2,548 | 124 | 152.932 | INCONCLUSIVE, expected rejection |
| Positive, 0 ms | 4,800 / 1,024 | 288,000 | 432,000 | 0 | 0 | 20.528 | PASS, this cell only |
| Positive, 25 ms | 4,800 / 1,024 | 286,897 | 430,897 | 1,104 | 4,929 | 61.955 | INCONCLUSIVE |

For every completed profile, all-iteration client started/completed counts equal
the independent receiver started/completed deltas, including warmup and drain.
Receiver failed sends, observed HTTP errors, parity failures and false negatives
were zero. All four workers received requests, their PIDs remained stable, no
container OOM/restart was observed, and k6 exited zero. These bounded checks do
not erase lost scheduled work or delay violations.

The 25-ms profile reached the fixed 1,024-VU ceiling, as its retained
[k6 log](evidence/2026-10-07-load-generator-qualification/20261007-qualify-delay-25.txt)
records. Its measured effective rate was 4,779.944 RPS including drain. Being
above 95% of offered RPS is insufficient when requests were dropped. Its
independent total counts agree at 430,897, but that cannot count work the
generator never dispatched.

The receiver's native timer with a one-millisecond allowance did not guarantee
the controlled delay lower bound under this load: the measured receiver
`query_ms` minimum was 24.021 ms for a requested 25 ms. The collector counted
4,929 such violations across warmup and measurement. This is a second independent
qualification failure. The report evaluator stops at the first reason
(`dropped_iterations must be zero.`); removing only that field's violation for
independent review also exposes delay rejection. No edited cell is performance
evidence, and no correction/rerun is hidden in this record.

| Positive delay | Resource samples per container | Generator CPU max | Receiver CPU max | Generator memory max, % of limit | Receiver memory max, % of limit |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 0 ms | 11 | 270.36% | 125.16% | 12.18% | 9.79% |
| 25 ms | 11 | 246.71% | 113.95% | 11.81% | 11.97% |

These sampled values were below the frozen guards; they do not identify the
cause of the VU deficit or exclude transient scheduling/resource stalls. CPU is
Docker's aggregate percentage (four CPUs permit 400%). The negative control had
only one measured resource sample and is not a positive qualification profile.

The collector stopped automatically after the 25-ms failure. **100-ms and
150-ms positive profiles were not run.** The passing 0-ms cell cannot qualify
the planned envelope or longer application responses. No application benchmark,
SQL ladder, paired four-path comparison, matrix, PostgreSQL confirmation or
soak ran in this increment.

## TDD, independent review and checks

Public-seam RED/GREEN evidence is retained for the missing HTTP receiver,
initial report admission, lost scheduled work, missing resource observations and
impossible percentile ordering. The independent reviewer found the last case:
a fabricated `p50 > p99` initially returned PASS; the new test failed, then the
ordering guard made it INCONCLUSIVE. These unit timings are invented fixtures,
not performance measurements.

- Native receiver test passed **1 test / 61 assertions**, proving actual four-PID
  HTTP handling, membership parity and counters.
- Local Pint and maximum PHPStan passed. Package checks passed **934 tests /
  7,659 assertions with 13 optional skips** in the extension-free PHP 8.5 image;
  skipped runtime tests are not live-Redis evidence.
- Final measurement/report suites passed **57 tests / 59 assertions**, including
  the four generator report tests. JavaScript and PowerShell syntax checks passed.
- Independent pre-live review approved the frozen source. Post-run review
  independently confirmed the cell verdicts, both failure causes and stop scope.
  It did not independently inspect Docker cleanup.
- Hosted frozen-source [Quality](https://github.com/kefyusuf/laravel-bloom-gate/actions/runs/37639436028)
  passed Pint/PHPStan, **946 package tests / 7,692 assertions with one skip**,
  and **57 report tests / 59 assertions**. The
  [native job](https://github.com/kefyusuf/laravel-bloom-gate/actions/runs/37639436015)
  passed the existing suites (4/43, 22/93, 2/139, 4/395) and the new receiver
  suite (1/61). Both compatibility anchors, consumer and Redis integration also
  passed on that source. Hosted CI exercises behavior, not the live rate envelope.

Final evidence review and exact-head hosted checks must pass before merge.
CodeRabbit draft skips are not independent approval or test evidence.

## Cleanup and continuation

The current-task project `lbg-generator-20261007-qualify` was removed with its
receiver/load containers and default network. It created no named volumes.
Its task-built `lbg-generator-php:20261007-qualify` image was removed. Temporary
native/quality containers used `--rm`; no worktree was created. Read-back found
no matching project containers/network/task image. The pinned dependency k6
image, existing `lbg-release-php:8.5` verification image, dependency/build-cache
layers and unrelated resources were retained. No global prune or main-stack
volume removal was performed.

Keep the proven production backend. The approved attempt ends INCONCLUSIVE.
A next proposal must address both exact delay control and generator scheduling,
with a separately frozen profile and authorization before new timings. The
current result does not authorize tuning until PASS or application reruns. Only
after independent bounded generator qualification can fresh SQL calibration and
the equal four-path comparison be considered; no production backend/release
decision follows from this increment.
