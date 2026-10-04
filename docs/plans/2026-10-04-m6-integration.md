# M6 integration preparation — 2026-10-04

## Verified source boundary

The integration branch is `feat/m6-coordination-completion`, based on
`d2f95878f43af1dca5f7e61acf6d1fdb1afd9954` (WU-09). WU-10 through WU-13,
compatibility fixes and final-review fixes are committed for review in #67.
No new milestone is defined in the current roadmap.

Include both tracked modifications and untracked source, tests and documentation
when preparing integration. `git diff` alone omits the new files. Exclude ignored
`.build/`, vendor trees, local lockfiles and environment files.

Keep this as one cohesive completion of the approved M6 protocol. Do not split
the current mixed working-tree changes by filename into WU commits: diagnostics,
Laravel wiring, compatibility fixes and review corrections share files.
Use the work-unit verification reports to navigate the final combined change.

## Prepared pull request

Suggested title: `feat: complete M6 coordination diagnostics and Laravel workflows`

### Summary

Complete the M6 operator and Laravel surfaces for prepared writes and online
rebuild coordination. Add ownership/synchronization diagnostics, optional active
lease inspection, explicit adoption/rebuild/abort/recovery commands, and
deterministic Memory/live-Redis race and interruption evidence.

### Motivation

The WU-09 baseline implements coordinated mutation and recovery, but applications
and operators need Laravel bindings and commands to invoke those services and
inspect outstanding leases. The new surfaces delegate protocol legality and
recovery evidence to the existing Application services.

### Scope

WU-10 diagnostics, WU-11 Laravel integration, WU-12 race/interruption evidence,
WU-13 canonical documentation, minimum-dependency compatibility and two final
diagnostic review corrections. Invalid configuration is distinguished from
unavailable infrastructure; status preserves coordination evidence when managed
control or generation reads fail.

### Architecture impact

- [x] No accepted ADR is contradicted.
- [x] Dependency direction remains valid.
- [x] No new or superseding ADR is required; ADR-0041/0042/0043 govern the changes.

Laravel dependencies remain in Laravel adapters. Lifecycle legality remains in
Application; atomic persistence remains in Memory/Redis drivers.

### Public API impact

- `BloomGate::prepare()` accepts a caller-owned stable lease token.
- Per-filter `coordination: coordinated-v1` enables the runtime requirement.
- Add explicit adoption, rebuild, abort and evidence-bound lease resolution CLI.
- Extend status/doctor diagnostics; `bloom:status --leases` lists active leases.
- Add the optional `WriterLeaseInspector` diagnostic contract.

### Risk

Operators must retain the brownfield write handoff until adoption completes.
Unknown authoritative outcomes retain leases. Diagnostic observations do not
authorize lifecycle mutation or prove drain completion. No force recovery, lease
TTL, automatic release or de-adoption is introduced.

### Verification

- [x] `composer check`: all seven dependency sets, 883 tests / 7,365 assertions.
- [x] Full integration evidence: 1,055 tests / 8,265 assertions per set, including
  172 live-Redis tests.
- [x] Failure paths: race/interruption parity and five diagnostic review regressions.

PHP 8.3/8.4/8.5 with Laravel 12/13 plus PHP 8.3/Laravel 12 minimum dependencies
passed locally on 2026-10-02. Audit/discovery passed before the dependency-identical
diagnostic corrections. Independent review accepted both corrections. These are
local results, not remote CI results; the suite was not rerun for this document.

### Documentation

README, changelog, canonical architecture/operation documents, work-unit evidence,
[final review](../verification/2026-10-02-m6-final-review.md) and
[handoff](../handoff.md) describe the final result.

### ADR impact

Preserve ADR-0041 prepared-writer protocol, ADR-0042 ownership and atomic fencing,
and ADR-0043 explicit adoption/recovery. External rollback history and hardware
power-loss durability remain the declared operational boundaries.

## Existing CI coverage

The current combined source/config changes match the Redis Integration and
Compatibility pull-request path filters. Quality runs on pull requests.
Release Gate is manually dispatched and tests six compatibility combinations plus
minimum dependencies; its `composer check` excludes live Redis. Redis Integration
provides the separate live-Redis job. No workflow change is needed for this scope.

The tests were committed as `2145e32`, followed by implementation/documentation
as `c0027e6`. [PR #67](https://github.com/kefyusuf/laravel-bloom-gate/pull/67) is open
for review, with the overlapping PR #66 API decision still outstanding. Quality,
Redis integration and both compatibility anchors passed on `4dcaf7f`. CodeRabbit
skipped its review because this repository requires a manual trigger; its success
status is not a completed review. Qodo reported that reviews are paused.
Independent local review is complete. Check the latest head results before merge;
merge and release have not been performed.

## Final synchronized-source check

The current host `src/`, `tests/` and `config/` were copied into the PHP 8.4 /
Laravel 13 Docker test copy with PHP line endings normalized to LF.
`composer check` passed (883 tests / 7,365 assertions).

The first full-suite run exposed a fixture configuration mismatch: restarting
`lbg-wu10-redis` applies its configured `appendonly=yes` and `appendfsync=always`,
while the read-only runtime-diagnostics test expects the default test profile.
This was an environment failure, not a source regression.

A separate `lbg-m6-integration-fixture` Redis container now runs on port 6380 in
the existing shared test network with `appendonly=no`, `appendfsync=everysec`,
and `maxmemory-policy=noeviction`. The original Redis settings were preserved.
The full suite passed with `REDIS_PORT=6380`: 1,055 tests / 8,265 assertions.
No implementation or test assertions changed.

Local logs: `.build/integration-final-quality.log`,
`.build/integration-final-all.log` (the fixture mismatch), and
`.build/integration-final-fixture-all.log` (passed).
The existing seven-set matrix remains the preceding compatibility evidence;
this step reran the integration anchor only. Git integration is awaiting a
pull-request check result on `feat/m6-coordination-completion`, following the
user's explicit tests, branch/commit, PR, then merge workflow.

Remote inspection discovered existing open PR #66 with an alternative WU-10
implementation on `feat/m6-wu10-coordination-diagnostics`. It is preserved rather
than overwritten. Its inspection/status API differs from this completed M6
surface. Disclose the overlap in the completion PR and resolve it before merging
both branches; do not force-push or discard its history.
