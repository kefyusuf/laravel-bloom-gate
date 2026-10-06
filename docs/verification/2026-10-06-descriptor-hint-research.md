# Bloom query placement and descriptor hint experiment

## Scope and sources

Baseline main: `3456d58c1df6a719171463fbc7c191585f598b8c`.
Research only: production source, service bindings, Redis schema, writer
protocol and published rc.2 remain unchanged. The experiment is opt-in in
distribution verification fixtures, not a supported package configuration.

Google's [Bigtable paper, section 6](https://static.usenix.org/event/osdi06/tech/chang/chang_html/)
describes keeping Bloom filters in tablet-server memory to avoid disk reads of
SSTables. The filter is close to the expensive operation being avoided.
[RocksDB's Bloom filter documentation](https://github.com/facebook/rocksdb/wiki/RocksDB-Bloom-Filter)
similarly places filters in the storage engine. These designs do not imply that
adding remote Redis checks before a cheap indexed SQL query is always faster.
[Redis's pipelining documentation](https://redis.io/docs/latest/manual/pipelining/)
explains the cost of synchronous round trips. These sources support reducing
extra I/O; they do not certify this package's workload or operating profile.

Our measured regression came from three synchronous Redis calls. Investigate
whether the two descriptor preparation reads can be reused while retaining
the existing live atomic probe on **every lookup**.

## Source-level safety comparison

`QuerySafetyDescriptorResolver` reads active state and managed generation
metadata. The final `RedisQuerySafetyScripts::authorizedProbe()` rechecks
control format/revision/version, active lifecycle and healthy state, metadata
and bitmap types, format/layout/algorithm, all three semantic fingerprints,
managed bitmap marker and current bits. It includes the earlier reads' safety
conditions and adds the marker check.

The fixture wraps the original readers with local metadata hints, then constructs
an isolated `QueryGate` using the original resolver, registry, fingerprint
calculator, probe generator and authorized probe. Every call recomputes current
application semantics. No authoritative source, input normalization, enabled
decision, membership answer or negative result is cached. Any bypass clears
both hint stores; the next query resolves normally. The fixture never replaces
global production service bindings with the experiment.

This is source-level justification plus a narrow runtime experiment, not full
production qualification. A stale hint can change a public bypass reason:
for example, the final probe can return `control_state_changed` where the
fresh resolver would report `health_not_healthy`. Boolean correctness alone
does not establish diagnostic API compatibility.

## Experiment

Production-only Laravel 13.34.0 / PHP 8.5.11, Redis 8.10.2 durable standalone
primary, MySQL 8.4.11 and PostgreSQL 17.11. Same disposable fixture scheme:
1,000 indexed rows, 1,000 queries, one warmup and five measured passes,
alternating path order. All answers match deterministic ground truth.
No artificial SQL delay or extra cache backend is introduced.

- Existing path: three Redis operations per query.
- Cold hint: clear metadata before every query; three operations per query.
- Warm hint: start each measured pass cold; first query uses three operations,
  remaining queries use one live probe. Total is 1,002 operations.
- SQL-only package control: same registered filter, global optimization disabled.

| Database | Present / 1,000 | Direct SQL ms | SQL-only package ms | Existing gate ms | Cold hint ms | Warm hint ms |
|---|---:|---:|---:|---:|---:|---:|
| MySQL | 0 | 133.67 | 145.85 | 624.31 | 589.23 | 227.12 |
| MySQL | 100 | 123.71 | 133.04 | 513.39 | 516.87 | 202.95 |
| MySQL | 1,000 | 185.25 | 170.16 | 746.03 | 806.90 | 397.47 |
| PostgreSQL | 0 | 370.78 | 420.36 | 486.13 | 499.85 | 190.67 |
| PostgreSQL | 100 | 383.36 | 421.02 | 621.80 | 630.55 | 278.48 |
| PostgreSQL | 1,000 | 718.56 | 632.32 | 1,207.47 | 1,502.98 | 948.62 |

Times are independent medians in milliseconds for 1,000 sequential queries.
Host scheduling is not controlled; cross-engine timings are not universal
engine rankings. A later MySQL repeat with added untimed mutation checks still
showed the same result: direct 163.38 ms, existing gate 688.99 ms, cold hint
672.68 ms and warm hint 260.32 ms for all-absent requests. Adding assertions
after the workload does not change which operations its timer measures.
The final MySQL run after the drain-label correction passed at 160.84 ms direct,
645.60 ms existing, 651.91 ms cold hint and 263.22 ms warm hint for all-absent
requests. Reduced calls improve the gate repeatedly, but do not beat MySQL here.

Both hint paths preserve SQL counts: 8, 108 and 1,000 for the three workloads.
Direct and SQL-only paths issue 1,000 SQL calls. Existing/cold paths execute
3,000 Redis operations; warm executes exactly 1,002, with one snapshot read,
one contract read and 1,000 live probes. Counts and answer equality are asserted;
elapsed-time thresholds are not tests.

## What the experiment establishes

Metadata reuse materially reduces repeated-query overhead. In these warm,
absent-heavy PostgreSQL runs it also beats direct SQL. It still loses to cheap
local MySQL lookups, and all-present requests keep all SQL work plus the gate.
Cold one-query lifetimes show no command-count benefit.

A rough cost condition is:

`bloom_only_overhead < absent_fraction * (1 - false_positive_rate) * sql_cost`.

Here `bloom_only_overhead` excludes the SQL lookups performed after a maybe result.

This model ignores workload interactions and different costs of present/absent
queries, so use it to choose a measurement, not promise a speedup. Increasing
table size alone does not prove the indexed lookup becomes expensive enough.

## Runtime safety evidence

- Canonical metadata fingerprint mutation after warming forces authoritative
  fallback through one live probe; restoring metadata causes three preparation
  calls on the next query, demonstrating eviction of both hints.
- Prepared pre-add followed by known abort changes live bitmap membership from
  definitely absent to maybe present. The same warmed hint observes the change
  through one probe and performs authoritative SQL, proving no negative-answer cache.
- Changing live generation health to degraded forces SQL fallback through one
  probe. Control fields are restored in `finally`.
- Existing query/resolver and live-Redis authorized-probe regressions passed:
  35 tests, 144 assertions, including revision drift, semantic/layout changes,
  missing storage and written-marker corruption.

These are meaningful narrow guards, not complete hint qualification for every
tenant, activation, application lifetime, crash, recovery or deployment path.
Default mode and flag/error paths must remain verified; hints require profile
mode so reduced operation counts cannot silently go unobserved.

Pint passed both changed fixture files and maximum-level PHPStan passed.
Hint mode without profiling was rejected with exit 1. Independent review found
one setup issue: the added prepared/abort verification initially ran before
drain timing, shifting actual retained lease counts by one. A live HLEN check
against the reported target failed on the old setup (RED); moving mutations
after drain and keeping that check passed the final complete MySQL run (GREEN).
The earlier query timing rows are unaffected by this untimed setup correction;
the affected drain numbers are not used as evidence in this report.

## Runtime lifetime and next decision

The fixture's cold path clears hints, preserving a long-lived PHP runtime;
it approximates preparation-call cost, not a full PHP-FPM HTTP benchmark.
Ordinary Laravel requests generally create a new application service graph.
One lookup per request therefore begins cold. Repeated lookups within a request,
batch jobs or a long-lived application can reuse the hint. Octane lifecycle and
tenant boundaries have not been tested and are not claimed as supported.

For a first production change, consider a bounded, opt-in descriptor hint only
for demonstrated repeated-query lifetimes, preserving the final live probe,
fresh semantic identities and eviction on bypass. Explicitly decide diagnostic
compatibility and test activation, tenant/source changes, disabled optimization,
Redis failure and prepared commit/abort against warmed hints.

For ordinary one-query requests, continue researching a cold-path design. Fusing
snapshot/contract reads or computing current-layout positions atomically in Lua
requires handling generation-specific keys and possibly changing the probe
boundary. A one-call cold query may preserve the existing hash algorithm by
passing its two SHA256 seeds, but parity vectors and explicit key-access design
are necessary. This larger redesign is not implemented or proven here.
EVALSHA remains a separate modest improvement; a local cached bitmap requires
a new update/validity protocol for mutable data and is not a safe shortcut.

## Reproduction and resources

Copy all `tests/Distribution/benchmark*.php` files into the disposable consumer
directory alongside `vendor`, set the existing PDO and qualified Redis profile
variables, and run:

```sh
BENCHMARK_PROFILE=1 BENCHMARK_DESCRIPTOR_HINT=1 php benchmark.php > hint.json
```

The fixture resets its disposable `users` table. Hint mutations run after query
and writer-drain measurement, so their released writer lease cannot shift the
drain labels. Actual retained counts are checked outside drain timing.
Task Compose project `lbg-hint-research-20261006` and task-built image
`lbg-hint-research-php:20261006` are removed on completion; reused images and
unrelated resources are retained. No worktree or new release is created.
