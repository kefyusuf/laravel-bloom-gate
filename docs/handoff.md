# Project handoff — 2026-10-08

## Latest continuation

The isolated pinned-k6 boundary correction now has real executor TDD evidence:
original 4,801/4,806 terminal slots reduce to 4,800, and controlled late
cancellation at the full 90-second count reduces 432,006 to 432,000.
See the [component correction](verification/2026-10-08-k6-arrival-boundary-fix.md)
and `tests/Experiments/Swoole/K6Boundary`. Exact integer global-slot admission
and timer-branch cancellation check preserve striped work, fractional schedules
and visible drops. Seven component tests/two segment subcases and real-clock
upstream checks passed locally; an uninstrumented patched binary built with
recorded provenance. Refresh final PR review/CI/merge before relying on delivery.
Default Compose/k6 image remains unchanged. Next: reviewed Linux image/provenance
integration before one separately frozen, approved qualification; no fresh load
ran and PR #95 remains INCONCLUSIVE. This proves a corrected overshoot mechanism,
not the old run's root cause or capacity. No Docker resource/worktree operation
occurred; all existing resources retained. Older next-step text is historical.

Offline pinned-k6 v1.3.0 analysis follows PR #95's failed qualification. At
4,800 RPS, integer-nanosecond period truncation places slot 432,000 at
89.999856 seconds, before the 90-second deadline. It can explain one extra
eligible slot, not all six observed requests. The executor lacks an explicit
slot cap and its timer/deadline selection permits a terminal race; this is a
source-level mechanism, not a proved cause of the retained run. Existing
aggregate observations do not contain per-request index/time pairs or the
executor's monotonic deadline decisions. See the
[offline analysis](verification/2026-10-08-k6-terminal-start-analysis.md).
Keep PR #95 INCONCLUSIVE and its ±1 guard unchanged. Next: deterministic
executor-boundary reproduction and a reviewed correction plan before fresh
timing; do not hide extra work in a script return or substitute a closed-model
executor. No new load, Docker stack, production change or qualification occurred.
All 29 retained artifact hashes and five existing actual-script tests were
verified. Refresh this documentation PR's review/CI/merge state before relying
on its delivery.

One corrected frozen qualification at source
`9a0d211b2aca0f81782175533fc70e411fe26473` stopped **INCONCLUSIVE** after
2/12 positive cells. Early negative safety passed; block 1 / 0 ms passed;
block 1 / 25 ms had 432,006 all-run completions against 432,000 ±1.
Client/builtin/receiver counts agree, nominal cohort is 288,000 and drops,
errors and early responses are zero. The remaining ten cells did not run.
See the [retained qualification record](verification/2026-10-08-generator-qualification.md).
No tuning, tolerance widening or retry occurred. Next: offline terminal-start
and pinned-executor analysis; root cause remains unknown. SQL/application
comparison remains unqualified. PR #95 includes the TDD early-negative-safety
fix and evidence; refresh its final exact-head review/CI/merge state before
using delivery. Own task Docker stack/images were removed; existing resources
were retained. The older next-step instructions below are historical.

The versioned measurement contract now separates nominal completeness (existing
±1 count tolerance) from actual-window performance (unchanged ≥95% throughput
and latency). Mandatory audit/builtin evidence must reconcile; legacy cells
cannot gain new PASS outcomes. Default qualification requires three complete
blocks × four delays plus a safe deliberately deficient negative control. See
the [contract record](verification/2026-10-07-generator-measurement-contract.md).
Local checks passed 96 measurement tests/110 assertions and five Node tests;
no new timing ran. Refresh the new PR's final review/CI/merge state before using
its source. Next: freeze the reviewed source and run one full qualification
attempt, stopping on first failure. SQL/application comparisons remain separate.

PR #93 adds diagnostic-only cohort crossings with admission unchanged. Frozen
source `ddffa1d9d3ec9ba901255d48b5e2e294149d2449` ran one short same-budget cell:
96,001 total, zero drops/early responses, 72,003 actual-window requests versus
72,000 nominal cohort. Six warmup crossings minus three late measurement
starts explain this new net +3. Outcome remains **DIAGNOSTIC_ONLY**; PR92's +2
cannot be retrospectively decomposed. See the
[boundary audit](verification/2026-10-07-generator-window-boundary-audit.md).
Next, explicitly separate scheduled completeness from actual-window performance
in a reviewed measurement contract before any full qualification. Do not widen
the current ±1 guard or infer a clock fault. Refresh PR #93's final review/CI/
merge state before relying on delivery. No full matrix/application run occurred.

PR #92 implements monotonic response deadlines, actual-time measurement windows
and bounded observer coverage. One frozen short diagnostic at measured source
`072e9b8373d8dcbd651cc50915349d5cdf5ffeb2` completed 96,001 requests with
zero final-summary drops and zero early responses; minimum receiver duration
was 25.001918 ms. Outcome is **DIAGNOSTIC_ONLY**, not capacity PASS. See the
[diagnostic record](verification/2026-10-07-generator-deadline-diagnostics.md).
The measured count was 72,002 against nominal 72,000: assess clock/window
boundaries before full qualification without weakening the existing ±1 guard.
The four full delay profiles and three repetitions remain unrun. No application
comparison or production migration occurred. Refresh PR #92's review/CI/merge
state before relying on delivery. PR91's evidence below remains historical.

Independent fixed-budget generator qualification in
[PR #91](https://github.com/kefyusuf/laravel-bloom-gate/pull/91) stopped
**INCONCLUSIVE** at the first delayed positive profile. At 4,800 RPS/1,024 VUs,
the 0-ms cell passed, but the 25-ms cell dropped 1,104 scheduled iterations and
had 4,929 controlled-delay violations. The 100/150-ms positives were not run.
All-iteration client totals matched independent receiver counts. See the
[qualification record](verification/2026-10-07-load-generator-qualification.md)
and tracked evidence, measured source `21f37ba88cd6b064a6bf7f8ea6ae7d02211ba793`.
No source/settings tuning or rerun occurred. The user authorized this separate
generator attempt; its failure does not authorize application reruns. A next
proposal must address exact delay control and scheduling, freeze a new profile,
and obtain authorization before fresh timing. Retain the production backend.
Refresh PR #91's exact-head review/CI/merge state before treating it as delivered.

Task 3 now has real equal HTTP paths and a manual collector in
[PR #90](https://github.com/kefyusuf/laravel-bloom-gate/pull/90). Its actual
MySQL attempt is **INCONCLUSIVE**: the fixed 128-VU generator dropped one
iteration during 1,200-RPS SQL calibration. The previous passing rate cannot
establish SQL capacity. See the [live screen record](verification/2026-10-07-swoole-live-screen.md)
and tracked raw artifacts. Measured source is `7ec3490e3009b1bc6efc453567755c2af25098e2`;
later collector hardening has no new timing result. One targeted metadata
compatibility correction was used; no third performance attempt, conditional
matrix, PostgreSQL confirmation or soak is authorized by this outcome.
92,404 completed calibration/control responses had no observed parity failure,
false negative or HTTP error, but no paired speed comparison completed.
Stop this runtime attempt and retain the proven backend. PR #90 merged at
`0a3f86301edb59f342b12b9a6dfd1d0603045832` after independent review and
exact-head hosted checks. The separate generator attempt above does not change
that screen's outcome.

The user approved the Redis-free single-host evaluation; plan PR #86 merged at
`ad6fc04e5319d009bbda2b0e06600342afef57ac`. See
the [implementation plan](plans/2026-10-06-single-host-shared-memory-evaluation.md) and
[experiment design](architecture/single-host-shared-memory-evaluation.md).
Task 1's capability fixture merged in PR #87 at
`4fea698662074cd4ab7ba891fdf3a1b5e1e97bfa` after independent review and all
exact-head checks (native CI `37481211118`, 4 tests/43 assertions).
See the [capability record](verification/2026-10-06-shared-memory-capability.md).
Task 2 merged in PR #88 at `bbccf82dfb7bdc09e6eeb0b9812e64a284f741a9`; see the
[query safety record](verification/2026-10-06-shared-memory-query-safety.md).
Tested head `3846d5a539b9baa1926ce8505e9fc887251d2f33` passed native CI
`37489630317` (capability 4/43, query 22/93, HTTP 2/139), package quality,
consumer, Redis and every release-validation matrix job. Task 3's first increment
adds a fail-closed [measurement report contract](verification/2026-10-07-measurement-report-contract.md)
in [PR #89](https://github.com/kefyusuf/laravel-bloom-gate/pull/89), implementation
source `9898be1efb8f68dbded019947e31910add7fc0cb`. Its independent review and
hosted quality/native/consumer/Redis/anchor checks passed. Refresh the final
PR/main state before continuing. It validates comparable evidence and the
three-run decision; invented test timings are not a performance result. The
real equal HTTP paths and collector were implemented in PR #90, and the
attempt stopped INCONCLUSIVE as recorded above. This is an immutable synthetic-data experiment,
not a change to ADR-0003 production support or an authorization to publish a
new backend. Task 2 proves actual QueryGate behavior and selected SQL call counts,
with exhaustive authorized probes of the million seeded keys. Concurrent equal
offered-load comparative performance remains unqualified; Task 3 stopped
INCONCLUSIVE as recorded above.

For synthetic application checks, use the [blank Laravel factory demo](verification/2026-10-06-factory-demo.md).
It owns its demo data, dependency installation and Docker stack. Do not inspect
or reuse private business-application source or data for package verification.

The latest published evaluation candidate is `v0.1.0-rc.2`, source
`e82584c62425146b43082147284c88d9411e9bd7`. See the
[rc.2 publication record](verification/2026-10-06-published-rc2.md).
The immutable tag does not include the later performance work.

[PR #83](https://github.com/kefyusuf/laravel-bloom-gate/pull/83) adds opt-in APCu
descriptor hints with live Redis authorization and one bounded stale refresh.
Final measured runtime source: `130ff1eb51c63eb67f07df005c273780dea494c1`.
Full local tests, PHPStan 2.3, archive-backed MySQL/PostgreSQL FPM checks and
exact-source PR checks passed. Refresh live PR/main state before continuing.
See the [verification record](verification/2026-10-06-apcu-descriptor-cache.md)
and README configuration; default descriptor caching remains disabled.

The next increment implements EVALSHA with one canonical NOSCRIPT fallback,
tested with live PhpRedis and Predis and archive-backed MySQL/PostgreSQL FPM
consumers. Measured source: `a8bf6f8ee9043942a43087c084cb5c739002463a`.
See the [verification record](verification/2026-10-06-redis-evalsha.md).
Warm commands use digest reuse; timing controls varied, so no isolated latency
gain is claimed. Redis ACLs must permit EVALSHA as documented in the README.
Refresh live PR/main state before continuing.

Further performance increments are immutable script/reflection reuse and
separately scoped batching. No local bitmap cache,
Redis Cluster qualification, concurrency qualification or new release is
included. Use failing test -> narrow fix -> commit/branch -> green PR merge.

The sections below retain the M6/first-candidate historical handoff.

## Verify before continuing

Workspace: `E:\projects\laravel-bloom-gate`.
Delivered branch: `main`.
M6 completion HEAD: `e89c44cf5d97a178256210a708e046e585e0d09d` (PR #67).
WU-10 through WU-13, compatibility fixes, final diagnostic corrections and atomic
count/lease reconciliation are merged. Earlier feature commits are historical
evidence; do not assume they remain separate commits after the squash merge.
Later documentation or implementation commits may advance `main`.

Published evaluation candidate: `v0.1.0-rc.1`, targeting
`77401a64b76ea7ceb47a6254fd1f38b3a0d09aca`. The tag is immutable; later release
record updates on `main` do not move it. Consult the publication record below.

Before relying on this record, verify the directory, branch, HEAD, and status:

```powershell
Get-Location
git branch --show-current
git rev-parse HEAD
git status --short
```

If they differ, inspect the changes and update the continuation assumptions.
Do not reset the checkout to reproduce this handoff.

## Completed scope

- Core/contracts, Memory/Redis atomic persistence, prepared writing,
  ownership/adoption and rebuild/abort were delivered in WU-00 through WU-09.
- WU-10 adds read-only coordination status, doctor integration and optional
  acquired/prepared lease enumeration.
- WU-11 adds Laravel configuration/service wiring, facade preparation,
  adoption, rebuild, abort and evidence-bound lease resolution commands.
- WU-12 closes the race/crash evidence matrix with deterministic barriers and
  same-scenario Memory/live-Redis execution.
- WU-13 closes canonical architecture/operation documentation.
- Compatibility fixes preserve the token generator's native non-empty-string
  result and remove version-sensitive Mockery typing from drain polling evidence.

The package remains an evaluation prerelease. Earlier verification steps did not
publish the result. Development uses tests, branch/commit, pull-request checks,
then merge according to the results. The first candidate publication received
separate explicit user approval; see the publication record for its exact scope.

## Verification and architecture references

- [M6 closure](verification/2026-10-02-m6-wu13.md)
- [Race/crash evidence index](verification/2026-10-02-m6-wu12.md)
- [Full local release matrix](verification/2026-10-02-release-matrix.md)
- [Final review and post-fix verification](verification/2026-10-02-m6-final-review.md)
- [Integration preparation and PR draft](plans/2026-10-04-m6-integration.md)
- [PR reconciliation evidence](verification/2026-10-04-m6-pr-reconciliation.md)
- [Production-only consumer installation](verification/2026-10-04-consumer-installation.md)
- [Source distribution consumer](verification/2026-10-04-distribution-consumer.md)
- [Deep release gate](verification/2026-10-04-release-gate.md)
- [GitHub ZIP / Composer dist consumer](verification/2026-10-04-provider-dist-consumer.md)
- [First release candidate readiness](verification/2026-10-05-release-readiness.md)
- [Published evaluation candidate](verification/2026-10-05-published-candidate.md)
- [Isolated Redis/SQLite RC application pilot](verification/2026-10-05-rc-redis-sqlite-pilot.md)
- [Repository administration baseline](verification/2026-10-04-repository-administration.md)
- [Laravel coordination and recovery](architecture/laravel-coordination.md)
- [Coordination diagnostics](architecture/coordination-diagnostics.md)
- [M6 work-unit plan](plans/2026-09-28-m6-implementation-task-breakdown-verification-matrix.md)

The final local matrix passed PHP 8.3/8.4/8.5 with Laravel 12/13 plus the
PHP 8.3/Laravel 12 `prefer-lowest` set. Each environment passed `composer check`,
1,055 full tests / 8,265 assertions (including live Redis) after the final review
fixes. Audit and package discovery passed in the preceding dependency-identical
matrix run; they were not repeated for these diagnostic changes.
Logs are under ignored `.build/`; they are local evidence, not shipped artifacts.
These results apply to the tested source state; later code changes require
appropriate verification before reusing the conclusion.

Accepted ADR-0041/0042/0043 remain authoritative. Keep framework dependencies in
Laravel, lifecycle legality in Application, and atomic storage rules in Drivers.
Writes/lifecycle fail closed; query uncertainty falls back to the authoritative
source. Unknown authoritative outcomes retain leases. No correctness TTL,
automatic release, force recovery or dynamic de-adoption is implemented.

## Local Docker verification environment

Docker CLI on this host:
`$env:LOCALAPPDATA\Programs\DockerDesktop\resources\bin\docker.exe`.
The host PHP/Composer tools were not used for verification. Composer resolution
and platform pinning occurred in isolated container source copies, not the checkout.

| Container | Working copy / purpose |
|---|---|
| `lbg-wu10-redis` | Isolated Redis 8.10.2 test service |
| `lbg-m6-integration-fixture` | Redis test defaults on port 6380 in the shared network |
| `lbg-wu10-php` | PHP 8.4; `/app` Laravel 13, `/app-matrix-l12` Laravel 12 |
| `lbg-release-php83` | PHP 8.3; `/app` Laravel 12, `/app-lowest` minimum set, `/app-matrix-l13` Laravel 13 |
| `lbg-release-php85` | PHP 8.5; `/app-l12` and `/app-l13` |

PHP containers share the isolated Redis container's network. The repository is
mounted read-only at `/workspace`. Container copies and dependencies persist
while those containers exist; verify their presence before using them.
After editing host source, sync the affected files into the intended copy.
Normalize container PHP copies to Git-equivalent LF endings before checks;
Windows CRLF source can invalidate source-marker assertions in Linux tests.

Restarting `lbg-wu10-redis` restores its configured AOF production profile
(`appendonly=yes`, `appendfsync=always`). The runtime-diagnostics integration test
expects the default test profile instead. Use `REDIS_PORT=6380` for full tests
against `lbg-m6-integration-fixture`, which declares `appendonly=no`,
`appendfsync=everysec`, and `noeviction` explicitly. Verify it is running first;
its network depends on `lbg-wu10-redis`.

Canonical commands inside a synchronized copy:

```sh
composer check
composer test:all
composer audit
vendor/bin/testbench package:discover
```

Use focused tests first for a concrete fix. Do not rerun the full matrix without
a relevant source/dependency change or unresolved risk.

## Current continuation boundary

M6 is merged through [#67](https://github.com/kefyusuf/laravel-bloom-gate/pull/67).
PR #66 is closed as superseded; its remote branch and `e390811` history remain.
The retained API matches the complete M6 operator/Laravel documentation and adds
the alternative branch's atomic count/lease integrity behavior.

Independent review approved the reconciliation. Current-head Quality,
Compatibility (two anchors), and Redis Integration checks passed before merge;
the post-merge main Quality check also passed. The merged source tree matched
the verified PR head. Post-merge full verification passed 1,064 tests / 8,304
assertions. PHP 8.3/Laravel 12 minimum dependencies passed the same full suite;
the earlier seven-set matrix remains separate historical evidence.

CodeRabbit skipped review and Qodo reviews were paused; their status was not
treated as independent approval. The package remains an evaluation prerelease;
the later publication record documents its first candidate tag. No M7 feature
scope is defined in the accepted roadmap.

A separate production-only PHP 8.4/Laravel 13 consumer check passed package
auto-discovery, service resolution and command execution without Testbench or
PHPUnit. This verifies a minimal Memory-backed consumer, not every deployment
profile or a published Composer distribution. See its verification report.

Issue #2's repository administration baseline has been applied and read back:
main requires PRs and the GitHub Actions `quality` check, including administrators;
force pushes and main deletion are disabled. There is no external approval quorum.
Metadata/topics and private vulnerability reporting are configured. Automatic
branch deletion stays disabled. Read the administration report and live PR state
for its functional merge verification; refresh settings before relying on them.

First-release preparation adds source-archive export exclusions and a dedicated
Distribution PR check. The archive-backed production-only consumer remains
limited to PHP 8.4/Laravel 13 and Memory bootstrap; consult the verification
report and current PR checks. No runtime behavior, release version, tag or
publication is introduced by this increment.

The manual Release Gate now includes full live-Redis tests, dependency audits and
Laravel package discovery across all six PHP/Laravel combinations and the minimum
dependency set. Its candidate-commit CI results must be inspected before relying
on the new workflow as release evidence; consult the deep release gate report.

Distribution verification now also installs a real GitHub candidate-commit ZIP
through Composer dist and checks its installed commit/type, file exclusions and
production-only Laravel consumer. The ZIP is downloaded first and consumed as a
local file. This does not prove Packagist indexing or Composer remote transport;
the consumer remains PHP 8.4/Laravel 13 with Memory bootstrap.

First-release preparation completed through PR #73. After explicit user approval,
`v0.1.0-rc.1` was published as a GitHub prerelease on the exact selected main
revision. Its seven-target Release Gate, Distribution and Quality passed before
publication. No Packagist submission was performed. This does not certify an
unobserved production deployment or authorize M7 feature expansion.

RC evaluation now has an isolated Laravel/Redis/SQLite consumer pilot installed
from the immutable published tag. It verifies actual SQL lookup paths and known
commit/rollback writer outcomes, not a business deployment or performance target.
The manual RC Pilot workflow records this bounded scope. Verification fixtures
explicitly return failure after Laravel bootstrap; consult the pilot report and
current workflow result before continuing. No second release is introduced.
