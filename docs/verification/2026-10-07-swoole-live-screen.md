# Immutable shared-memory live screen — 2026-10-07

**Verdict: INCONCLUSIVE. Stop this runtime attempt.** The experiment does not
establish SQL capacity, a Redis-removal speedup, or production stability.
PR [#90](https://github.com/kefyusuf/laravel-bloom-gate/pull/90) contains the
fixture, collector, hardening and retained evidence. Refresh its exact-head
checks and merge state before relying on this record.

## Scope and provenance

Only the package's blank Laravel fixture and deterministic factory records were
used. No private application source or business data was accessed or transferred.
Production code, configuration defaults and the published candidate are unchanged.

Measured source: `7ec3490e3009b1bc6efc453567755c2af25098e2`.
The collector required a clean checkout, installed the tracked fixture lock,
compared installed fixture text hashes, checked running container image IDs
against the pinned inputs, and observed runtime metadata before loading.
The retained cell identities record PHP 8.4.26, OpenSwoole 26.2.0, Laravel
13.34.0 and Octane 2.20.0. The four HTTP workers share an immutable native bitmap
and each reuses its own SQL connection. Redis uses the actual package QueryGate,
APCu descriptor hints and live authorization, standalone AOF with
`appendfsync always` and `noeviction`.

There are exactly 1,000,000 factory keys. The observed seed digest is
`9ee8cb5c216392aacf242289162d07bc7292f5c06ffa373a51d4f7d1e10fae00`.
Seeding took 29.316 seconds in the rerun. SQL was sealed read-only and worker
credentials were SELECT-only. PHP, MySQL, Redis and k6 each had a 2-CPU/1-GiB
container limit. The generator used a fixed 128 VUs.

See [outcome.json](evidence/2026-10-07-swoole-live-screen/outcome.json),
[raw abort](evidence/2026-10-07-swoole-live-screen/20261007-rerun-aborted.json)
and [artifact hashes](evidence/2026-10-07-swoole-live-screen/artifact-manifest.json).
Raw calibration cells retain counters, HTTP/query quantiles and CPU/RSS samples.
Digests cover UTF-8 text normalized to LF. These tracked files survive deletion
of `.build`; local transient build/install/server output remains supplementary.

## Observations and stop decision

The direct-SQL calibration used ten seconds of warmup and twenty seconds of
measurement, with 90% absent inputs. These short cells are calibration
observations, not the required paired performance screen.

| Offered RPS | Completed responses | HTTP p99 ms | HTTP errors | Dropped iterations |
| ---: | ---: | ---: | ---: | ---: |
| 100 | 2,001 | 3.851 | 0 | 0 |
| 200 | 4,001 | 2.509 | 0 | 0 |
| 400 | 8,000 | 27.760 | 0 | 0 |
| 800 | 16,001 | 65.579 | 0 | 0 |
| 1,200 | 24,000 | 106.631 | 0 | 1 |

At 1,200 RPS, k6 reported insufficient VUs and the cell recorded
`generator_saturated=true`. Its maximum sampled generator CPU was 76.28% and
RSS 413 MiB/1 GiB; neither proves OOM or CPU exhaustion. The fixed VU limit and
dropped work prevent identifying this boundary as SQL capacity.

The measured collector originally continued at 640 RPS, based on the preceding
800-RPS rate. Independent review rejected this inference. One SQL control cell
completed: 38,401 responses, p99 54.487 ms, no errors or drops. The next direct
cell was deliberately stopped by killing only the current task's load container
(exit 137); the collector then removed its stack. The raw abort reason describes
that deliberate termination. The causal invalidation is generator-limited
calibration, as recorded separately in outcome.json.

Across the five completed calibration cells and that one control, 92,404 HTTP
responses had zero observed HTTP errors, parity failures and false negatives.
This establishes those bounded observations only. No paired shared/Redis timing
cell completed, no before/after drift comparison exists, and no GO/NO-GO speed
decision is possible. The full matrix, PostgreSQL confirmation and soak were
not entered. High-cardinality URL warnings are retained as a collector risk;
the timings do not establish that this caused the latency increase.

## TDD, review and checks

- Four equal real HTTP paths first failed with 404, then passed worker/parity
  and exact SQL/Redis call assertions. The final metadata-inclusive native
  admission/HTTP run passed 4 tests / 395 assertions.
- Independent review found unverified bitmap transfer into Redis. Corrupt,
  truncated and missing chunks produced RED evidence; verified parent export
  fixed admission, with three passing fault cases.
- The first collector attempt stopped before calibration because native
  `worker_num` was string `"4"`. The metadata endpoint test failed with HTTP 500;
  an isolated native type probe confirmed the string. Positive integer
  normalization fixed it. This was the single targeted measurement correction;
  the rerun above stopped without a third performance attempt.
- A real error-collector smoke recorded all ten deliberately failing responses.
  Those ten errors are excluded from the 92,404 valid-response observations.
  The post-stop tag configuration was accepted by a second negative network
  smoke, recording all eleven failed responses. It exercised no SQL/runtime
  stack and is also excluded from performance observations.
- Generator-limited calibration could incorrectly coexist with a unit-fixture
  GO. Two regression tests reproduced RED; the report now rejects this evidence
  and the collector throws before starting screen cells. GREEN: 53 report tests /
  55 assertions. The new collector uses stable name tags and excludes URL tags;
  these post-stop changes have no new live timing result.
- Local package checks passed Pint, maximum PHPStan and 934 tests / 7,659
  assertions with 13 optional skips in the extension-free PHP 8.5 environment.
  Original native safety/query/HTTP checks passed 4/43, 22/93 and 2/139.
  Post-stop report checks and PHPStan also passed; JS and PowerShell syntax checks
  passed. Exact-head hosted checks remain a separate merge gate.

The runtime/collector and stop decision received independent review. Automated
CodeRabbit draft skips are not counted as approval. Final artifact review and
exact-head hosted gates must be recorded before merge.

## Cleanup and continuation

The current-task stacks `lbg-shared-memory-20261007-screen`,
`lbg-shared-memory-20261007-proof`, `lbg-shared-memory-20261007-metadata-red` and
`lbg-shared-memory-20261007-rerun` were removed with their containers, networks,
experiment/MySQL/Redis volumes and task-built PHP images. The isolated
`20261007-worker-red` image and temporary verification containers were removed.
No worktree was created. Official dependency images (including the newly pulled
pinned k6 image), the existing package verification image and unrelated resources
were retained; no global prune or main-development volume removal was performed.

The approved attempt ends INCONCLUSIVE. Keep the proven backend and its current
support boundary. A future measurement proposal would need an independently
qualified generator and fresh authorization; this record does not authorize
another runtime, tuning until a win, a release or deployment.
