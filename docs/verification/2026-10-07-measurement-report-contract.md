# Shared-memory measurement report contract — 2026-10-07

## Scope

The first Task 3 increment in the approved
[evaluation plan](../plans/2026-10-06-single-host-shared-memory-evaluation.md)
adds a fixture-only evidence validator. Baseline is Task 2 merge
`bbccf82dfb7bdc09e6eeb0b9812e64a284f741a9`. Production sources, backend defaults,
native query paths and published release tags are unchanged.
Delivered in [PR #89](https://github.com/kefyusuf/laravel-bloom-gate/pull/89),
implementation source `9898be1efb8f68dbded019947e31910add7fc0cb`.

No live performance screen was run. Unit fixtures contain invented timings and
digests solely to test decision rules; their `GO` is not an experiment verdict.
The load generator, real equal-load HTTP comparison, SQL calibration, resource
observations and conditional PostgreSQL/matrix/soak qualification remain pending.

## Contract

`MeasurementReport::evaluate()` accepts an evidence object and returns a verdict
and reasons. Malformed or contradictory evidence returns `INCONCLUSIVE`.
Complete comparable evidence returns `NO-GO` unless shared HTTP p99 is at least
20% below both SQL and configured Redis, and RPS is at least 95% of each, in
every one of the three repetitions. `GO` remains conditional on independently
verified source, calibration and raw measurement provenance.

The object contains `identity`, positive integer `sql_capacity`, and eighteen
ordered `cells`. Each repetition has direct-SQL controls before and after four
paths. Their declared rotation is:

| Block | Path order between controls |
| --- | --- |
| 1 | direct, bypass, redis, shared |
| 2 | redis, shared, direct, bypass |
| 3 | shared, direct, bypass, redis |

Identity binds source revision/hash, lock hash, dataset hash, runtime versions,
PHP/MySQL/Redis/k6 image digests and observed runtime policy. All cells must carry
the same identity, four workers, 90% absent input, and an integer offered rate
`floor(sql_capacity × 0.8)`. The selected pinned runtime requires observed APCu
descriptor hints, EVALSHA, the supported standalone durable Redis authorization
profile, worker-local reused SQL connections, sealed data and enabled integrity
checks. An unavailable required runtime capability is `INCONCLUSIVE`; it must
be reported before attempting a differently configured comparison.

All cells share nominal warmup and measured arrival windows: at least 30 and 60
seconds respectively. Each has at least 10,000 responses. Started iterations
must match rate × measured window within one boundary iteration. Completed
iterations must equal responses; started must equal completed plus unfinished;
unfinished, drops, errors, unknown memberships, false negatives and parity
failures must all be zero. Present/absent input counts account for all responses
and the deterministic 90% absent mix, allowing one boundary response.

`elapsed_seconds` includes the whole measured arrival window and completion
drain. Reported RPS is positive and agrees with responses / observed elapsed
time within 0.01 RPS rounding. The throughput decision uses counts / elapsed
directly, so rounding cannot turn a value below the 95% boundary into a pass.
Direct SQL and its controls must actually sustain at least 95% of the offered
screen rate; otherwise the claimed calibration is not comparable evidence.
Do not discard slow responses that finish after the arrival window, include
warmup responses in measured counts, or divide measured counts by k6's entire
warmup-plus-measurement runtime. HTTP p50/p95/p99 must be finite, positive and
ordered. More than 10% control p99 drift or reported generator saturation
invalidates the screen.

This validator checks the supplied report's internal consistency. It does not
authenticate hashes, prove generator health, verify SQL capacity calibration,
derive unknown-membership counts from raw HTTP bodies, or substitute for the
independent evidence review. The future collector must observe these fields;
it must not fill them with assumed passing values.

## Verification

- Initial RED: 32 failures because the validator class did not exist.
- Additional RED: unequal windows and excessive response counts were accepted;
  these now return `INCONCLUSIVE`.
- Independent review found that a high declared offered rate could coexist with
  very few actual iterations and falsely return `GO`. Its reproducer and missing,
  unfinished or inconsistent iteration accounting failed before the fix.
- Independent review also reproduced false acceptance caused by rounded RPS.
  Zero RPS, SQL underload and a rounded value just below the 95% threshold failed
  before the fix; the decision now uses observed counts and elapsed time.
- GREEN: 51 report tests / 51 assertions. The suite covers all three repetitions,
  SQL-only wins, throughput failure, malformed input and exact control/latency
  boundaries. `composer test:measurement` runs it with warning/risky failures.
- The normal `composer check` includes this deterministic suite; hosted CI does
  not enforce performance timing thresholds.

- `composer check` passed in an extension-free PHP 8.5 isolated package copy,
  with formatting normalized for the Windows checkout: Pint 377 files, PHPStan
  maximum level without exclusions, 934 fast tests / 7,659 assertions and 13
  optional skips, followed by the 51 report tests. No native safety rerun was
  inferred from these deterministic tests; native paths are unchanged.
- The existing independent reviewer rechecked both fixes and returned PASS,
  including exact 95% throughput and 10% drift boundaries. The review covers
  this contract only.

Hosted checks passed on implementation source
`9898be1efb8f68dbded019947e31910add7fc0cb`: quality `37586217258`
(Pint 377 files, maximum PHPStan, 946 fast tests / 7,692 assertions with one
optional skip, plus 51 report tests), native `37586218372` (capability 4/43,
query 22/93, HTTP 2/139), anchors `37586217164` (PHP 8.3/Laravel 12 and
PHP 8.5/Laravel 13), consumer `37586217092` and Redis `37586217107`.
CodeRabbit skipped review; it is not counted as independent review evidence.
Final documentation updates must also have green exact-head checks before merge.
These are package-quality results, not measured
latency, throughput or stability qualification.

## Resources and privacy

Verification used only this package's isolated source copy and invented report
data. Temporary Docker containers use `--rm` and an existing package PHP image;
no Compose stack, network, volume, task-built image or worktree was created.
The hosted native task stack removed its two containers, network, two volumes
and task-built image successfully; those are separate from local resources.
Reused images and unrelated resources are retained. The ignored
`.build/swoole-performance-quality` copy can be regenerated; continuation state
and acceptance criteria live in these tracked documents.
