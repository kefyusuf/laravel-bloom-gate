# Laravel Redis EVALSHA verification

## Scope and source

Baseline: `865ff5e1b39f622e478e0bf1d97f752383b4af4c` (merged APCu increment).
Measured candidate: `a8bf6f8ee9043942a43087c084cb5c739002463a`.
Published `v0.1.0-rc.2` remains unchanged.

The Laravel adapter sends EVALSHA with the SHA1 of the unchanged Lua source.
Only the canonical operational `NOSCRIPT No matching script. Please use EVAL.`
reply allows one EVAL fallback. Fallback errors cannot recurse. Timeouts,
permission failures, Lua errors, programming errors and malformed replies do
not permit package-level replay. Reply type validation remains strict.

PhpRedis uses its native argument convention through the connection command
method, bypassing Laravel's helper that loads source on every call. Its native
client can return false for NOSCRIPT: the adapter clears the previous error
before dispatch and reads only the current error after a false reply. Predis
uses the positional Redis command convention. Both paths were tested live.
Restricted deployments must allow EVALSHA alongside EVAL and script commands;
permission denial does not trigger fallback. See the README upgrade note.

No Redis schema, Lua source, filter safety contract, cache default or public
query API changed. Script/reflection memoization and batch queries are separate
increments. Redis Cluster and concurrency qualification remain unverified.

## TDD and regression checks

- Executor tests failed before production changes (11 failed / 17 passed),
  then passed. Final focused unit checks: 33 tests / 68 assertions.
- Live Redis integration: six tests / 60 assertions, including both PhpRedis
  and Predis, integer and structured results, SCRIPT FLUSH recovery, warm
  command counts with no SCRIPT LOAD, and a write followed by a user-defined
  NOSCRIPT Lua error without duplicated mutation.
- Adding real Predis exposed an old unit helper that instantiated the abstract
  PredisException. Three full-suite failures were corrected by constructing
  its concrete ServerException and using autoload-aware class detection.
- Full PHP 8.4 / Laravel 13 suite: 1,147 passed / 8,535 assertions. The one
  disabled-APCu scenario skipped in this enabled run passed separately: one
  test / five assertions. Existing redundant-global-import warnings remain.
- Complete PHPStan 2.3, Composer validation and dependency audit passed.
- Complete Composer check passed in a self-contained Linux checkout of the
  exact commit: Pint 358 files, PHPStan, and 934 non-Redis tests / 7,659
  assertions. Thirteen optional-extension cases were skipped in this CLI run;
  the enabled native-extension full suite above covers them.
- A production ZIP omits development configuration/tests by design and cannot
  serve as the complete Composer check checkout. A vendor symlink also broke
  Pest's test-directory matching. The final check uses a full committed Linux
  checkout with copied dependencies and regenerated autoload paths.
- Independent source review found no blocking issue; the required EVALSHA ACL
  permission was added to upgrade documentation.

## Production-only FPM evidence

Both sources were installed from unique commit ZIP URLs. Before each run, 182
installed src/config PHP files matched the archive byte for byte, the installed
Composer reference matched the commit, and Testbench was absent. Updating the
candidate changed only this package; framework/dependency versions remained
fixed. PHP 8.4.26 / Laravel 13.34.0 / MySQL 8.4.11 / PostgreSQL 17.11 were used.

Archive SHA-256 values:

- Baseline: `f204d4612bd13fd1aad6421de6376306ac377c3c60ffff44d4ac86bdc0bd51bc`.
- Candidate: `72571317ebf28b164ee758b70078012128ffc842c9eafe84c0b6a60adabf00de`.

Provenance: [baseline](evidence/2026-10-06-evalsha-source-baseline.json),
[candidate](evidence/2026-10-06-evalsha-source-candidate.json).

The same updated HTTP fixtures measured both sources on an idle task-owned
Redis server. Commandstats reads occur outside the query timer. Logical
executor calls, source size and actual EVAL/EVALSHA calls are separate fields;
`logical_script_bytes` is not physical wire traffic.

The baseline failed the new transport guard before timings: warm EVAL expected
zero, observed one. The candidate passed all lifecycle, metadata, health and
live-bitmap guards on both database engines. Warm 1,000 lookups issued 1,000
EVALSHA and zero EVAL commands, versus 1,000 EVAL at baseline. Uncached lookups
issued 3,000 EVALSHA. All gate paths retained 108 SQL fallbacks for 100 positives
and eight false positives; direct SQL issued 1,000 queries.

After SCRIPT FLUSH, one logical warm probe issued exactly one failed EVALSHA
and one successful EVAL, with no SQL. The next HTTP request issued one EVALSHA
and zero EVAL. Write tests separately prove that a cache miss never executes
the mutation twice. Redis cache loss adds one request to the affected cold
script; there is no universal latency improvement claim.

Five measured passes follow one warmup, with alternating path order. Query-loop
medians in milliseconds for 1,000 lookups:

| Engine/source | Direct SQL | SQL-only gate | Uncached gate | APCu cold | APCu warm |
|---|---:|---:|---:|---:|---:|
| MySQL baseline | 183.29 | 261.17 | 935.88 | 317.82 | 310.44 |
| MySQL candidate | 148.54 | 181.02 | 721.54 | 284.30 | 276.66 |
| PostgreSQL baseline | 463.37 | 487.46 | 674.26 | 306.45 | 306.39 |
| PostgreSQL candidate | 407.47 | 434.07 | 630.67 | 292.74 | 301.40 |

Direct SQL controls also improved about 19% on MySQL and 12% on PostgreSQL
between source runs, while single-query timings varied. These sequential local
measurements do not isolate EVALSHA's causal latency effect. The demonstrated
benefit is digest reuse instead of sending Lua source on every warm operation,
with bounded recovery and unchanged authoritative correctness. Candidate warm
MySQL remains slower than its inexpensive direct SQL control.

Full samples: [baseline MySQL](evidence/2026-10-06-evalsha-baseline-mysql.json),
[baseline PostgreSQL](evidence/2026-10-06-evalsha-baseline-pgsql.json),
[candidate MySQL](evidence/2026-10-06-evalsha-candidate-mysql.json),
[candidate PostgreSQL](evidence/2026-10-06-evalsha-candidate-pgsql.json).

The task uses Compose project `lbg-evalsha-20261006`. Remove its containers,
volumes/network and task-built FPM image after finishing; preserve shared base
images and unrelated Docker resources.
