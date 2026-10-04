# Project handoff — 2026-10-04

## Verify before continuing

Workspace: `E:\projects\laravel-bloom-gate`.
Delivered branch: `main`.
M6 completion HEAD: `e89c44cf5d97a178256210a708e046e585e0d09d` (PR #67).
WU-10 through WU-13, compatibility fixes, final diagnostic corrections and atomic
count/lease reconciliation are merged. Earlier feature commits are historical
evidence; do not assume they remain separate commits after the squash merge.
Later documentation or implementation commits may advance `main`.

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

The package remains pre-release. Earlier verification steps did not commit or
publish the result; the current authorized workflow is tests, branch/commit,
pull-request checks, then merge according to the results. No tag or package
publication is included.

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
treated as independent approval. The package remains pre-release, with no tag
or publication created. No M7 feature scope is defined in the accepted roadmap.

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
