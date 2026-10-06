# Query gate latency investigation

## Question and scope

Investigate why the published rc.2 avoided authoritative SQL queries but
increased elapsed latency. Source baseline: main
`6f8cb138bf80aea2a5e13e41c74a17fde203f0d9`; package runtime matches published
rc.2 `e82584c62425146b43082147284c88d9411e9bd7`.
This investigation changes verification fixtures only. No production query
semantics, safety checks, release tags or support boundaries change.

The workflow is reproduce, isolate component cost, then select a bounded
optimization with separate regression evidence. No timing threshold is a unit
test: repeatable counts, query answers and readonly replay equality are checked.

## Reproduction and component isolation

Isolated PHP 8.5.11 / Laravel 13.34.0, Redis 8.10.2 with AOF,
`appendfsync always`, `noeviction`, MySQL 8.4.11 and PostgreSQL 17.11.
Each workload uses 1,000 indexed rows, 1,000 requests, one warmup and five
measured passes. Order alternates. Separate database servers share the task
network. No artificial delay or concurrent test load is injected.

The original uninstrumented MySQL fixture reproduced the regression:
164.32 ms authoritative versus 648.80 ms gate for all-absent requests.
Gate issued eight SQL queries and 3,000 EVAL calls. Redis EVAL CPU median was
94.54 ms. Thus the original result was not an isolated earlier-run anomaly.

The opt-in executor decorator wraps the real production executor: it never
replaces Redis responses or skips safety checks. Membership assertions and
membership diagnostic counters are outside the timed loop. The additional
`authoritative_bypass` path uses the package's global optimization switch and
must issue one SQL query per request with zero executor calls.

| Database | Present / 1,000 | Direct SQL median ms | Package SQL-only median ms | Gate median ms | Executor median ms | Redis EVAL CPU median ms |
|---|---:|---:|---:|---:|---:|---:|
| MySQL | 0 | 162.20 | 180.81 | 690.88 | 579.69 | 100.03 |
| MySQL | 100 | 137.62 | 276.20 | 750.69 | 578.54 | 111.46 |
| MySQL | 1,000 | 168.90 | 183.51 | 839.68 | 561.39 | 102.50 |
| PostgreSQL | 0 | 464.28 | 483.11 | 656.41 | 543.72 | 92.78 |
| PostgreSQL | 100 | 468.27 | 484.15 | 697.16 | 541.44 | 91.75 |
| PostgreSQL | 1,000 | 465.84 | 493.87 | 1,150.86 | 559.51 | 102.71 |

Each column is an independently calculated median, not an additive decomposition
of one exact sample. The MySQL mixed-path SQL-only variation is a reminder that
these sequential local runs do not control host scheduling or establish
population confidence intervals. Instrumentation adds some fixture overhead.
Nevertheless the dominant executor cost repeats across both databases.

For all-absent requests, executor group medians were:

| Group | MySQL ms | PostgreSQL ms | Calls |
|---|---:|---:|---:|
| Active snapshot | 172.61 | 159.42 | 1,000 |
| Generation contract | 187.33 | 175.81 | 1,000 |
| Authorized probe | 219.75 | 207.56 | 1,000 |

The three scripts send a combined 11,237,000 Lua source bytes per 1,000
requests, excluding RESP framing, keys, arguments and replies.

## Controlled transport experiment

Replay one captured set of the actual three readonly production query scripts
through raw phpredis. Keep script contents, keys, arguments and server state
the same; change only EVAL versus preloaded EVALSHA. Load scripts before timing.
Run one warmup and five measured passes in alternating order, with 1,000
sequential three-script replays per pass. Check first/last replies and equality
across modes outside the timer. This is a transport isolation experiment, not
an implementation of a faster production query path.

| Control | Median wall ms | Median Redis script CPU ms |
|---|---:|---:|
| 3,000 EVAL calls | 552.30 | 97.29 |
| 3,000 EVALSHA calls | 470.79 | 94.54 |
| 3,000 sequential PING calls | 372.11 | Not measured |

EVALSHA reduced this replay's wall time by about 14.8%, while Lua execution
cost changed much less. Even trivial synchronous PINGs cost 372 ms in this
environment. The observation isolates a substantial client/transport and
scheduling cost; it is not proof that all non-server time is physical network
latency. Client serialization, reply handling and scheduling also contribute.
The replay uses one fixed probe and does not reproduce the full key distribution.
The final standalone transport fixture repeated the control at 521.15 ms EVAL,
465.20 ms EVALSHA and 348.90 ms PING; the script CPU medians were 92.14 ms and
96.13 ms respectively. Across these two runs EVALSHA reduced replay wall time
by approximately 11–15%, confirming that script-source transport is a secondary
cost rather than a complete explanation or solution.
After adding successful-state preflight validation, the final replay passed at
413.35 ms EVAL, 354.17 ms EVALSHA and 330.27 ms PING, with script CPU medians
64.35 ms and 61.61 ms. Absolute values varied with the host; the third run
retained the same three-round-trip cost pattern and approximately 14% EVALSHA
replay reduction. These repeats do not establish controlled statistical bounds.

## Root cause and limits

The package replaces one cheap indexed SQL lookup with **three sequential
Redis operations**, plus registry/normalization/contract/probe work in PHP.
The measured Redis executor cost alone exceeds the direct SQL baseline in
both all-absent workloads. This explains the observed elapsed regression even
though the SQL count falls from 1,000 to eight.

The code path is `QueryGate::existsResult` ->
`QuerySafetyDescriptorResolver::resolve` -> active snapshot and generation
contract reads -> `RedisAuthorizedProbe::probe`. The final atomic probe repeats
revision, generation, lifecycle, health, layout and semantic checks before
reading bits, protecting against state changes between the earlier reads.
Those checks cannot simply be removed.

Normal queries do **not** scan retained writer leases. The drain-integrity
correction is not the direct source of query latency. Hashing, config validation,
VO decoding, Lua string preparation and reflection remain secondary candidates;
this investigation does not individually attribute their CPU time.

The benchmark directly compares PDO lookup with the complete package path.
The SQL-only package control exposes some framework overhead; moving assertions
outside timing did not eliminate the regression. This is not a query-answer
correctness failure. It is an unfavorable cost tradeoff for the measured workload.
Reducing SQL load can still be useful under different production conditions, but
that benefit has not been demonstrated here.

## Next bounded optimization

First evaluate reducing synchronous metadata round trips while retaining the
final atomic authorized probe and all fallback behavior. Any descriptor reuse
must still detect revision/semantic changes, missing metadata, unhealthy state
and unavailable Redis; stale data must never authorize a false negative.
EVALSHA with correct NOSCRIPT/restart handling is a separate smaller transport
optimization and is insufficient on its own in this experiment. Do not combine
these changes before measuring each independently.

Require existing corruption/race/activation/coordination regressions, deterministic
command-count evidence and equivalent benchmark conditions before claiming an
improvement. Keep rc.2 immutable; no new release is created by this investigation.

## Reproduce and resource lifecycle

Use a disposable production-only consumer and dedicated database/Redis instances.
Copy `benchmark.php`, `benchmark-profile.php` and `benchmark-transport.php` from
`tests/Distribution` to the consumer directory beside its `vendor` directory.
Set the existing pilot Redis profile and PDO fixture variables, then run:

```sh
BENCHMARK_PROFILE=1 php benchmark.php > profile.json
php benchmark-transport.php profile.json > transport.json
```

The benchmark resets its disposable `users` table. Captured replay data contains
fixture keys and should not be captured from an application production instance.
Profile mode checks calls and correctness without elapsed-time assertions.
Default benchmark mode requires neither helper at runtime and remains supported.

Pint passed all three fixture files and PHPStan maximum-level analysis passed.
Profile correctness/count invariants passed on the final MySQL fixture. The
transport fixture initially accepted identical missing-key/bypass replies:
an absent-key replay returned exit 0, failing the expected exit-1 check (RED).
It now requires a successful active snapshot, generation contract and authorized
probe before timing; the absent-key replay returns exit 1 (GREEN), while the
valid live replay still passes. Forced failure after Laravel bootstrap also
returns exit 1. Independent source review covers the fixture safety boundary.

Task Compose project: `lbg-query-profile-20261006`; task-built image:
`lbg-query-profile-php:20261006`. At completion remove task containers, volumes,
network and that image, preserving reused images and unrelated resources.
No worktree was created.
