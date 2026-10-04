# M6 PR reconciliation — 2026-10-04

## Source and decision

PR #67 (`feat/m6-coordination-completion`) completes M6 WU-10 through WU-13.
PR #66 (`feat/m6-wu10-coordination-diagnostics`, inspected at `e390811`) contains
an alternative WU-10 API and additional atomic count/lease diagnostic integrity.
The branches are based on WU-09 `d2f9587`; neither branch was overwritten.

Retain #67's API and Laravel/operator integration, and port the additional
integrity behavior from #66. The adopted vocabulary and opt-in enumeration match
the canonical M6 documentation. Independent comparison found no other mandatory
safety behavior to port. Its alternate doctor classification/vocabulary remains
preserved on its branch rather than being introduced as a parallel API.

## TDD and behavior

Test commit `16585b6` adds four mismatch regressions and two valid cases. Before
the correction, four failed and two passed. After the correction all six passed
across Memory and Redis. Three additional Redis tests cover wrong count-key type,
noncanonical epoch and noncanonical count, asserting unchanged persisted content.

The inspector derives active A/P counts per original epoch and compares them with
persisted counts in the same observation. Redis uses two same-filter keys in one
Lua call and canonical string integer helpers. Memory compares within the same
uninterrupted method. Missing/excess counts fail as corruption. Released
tombstones remain excluded and preserved; zero counts without leases are valid.
No read repairs state, and diagnostics never authorize draining or mutation.
Ordinary status/doctor enumeration behavior is unchanged.

## Verification

- Focused live-Redis/Memory checks: 11 tests / 55 assertions passed.
- PHP 8.4/Laravel 13 quality: Pint, maximum PHPStan, 883 fast tests / 7,365 assertions.
- PHP 8.4/Laravel 13 full suite: 1,064 tests / 8,304 assertions passed.
- PHP 8.3/Laravel 12 minimum dependencies: the same quality gate and all 1,064
  tests / 8,304 assertions passed; logs in `.build/reconciliation-lowest-*`.
- Independent focused reviewer: no must-fix findings, approved for CI and merge
  after the final gates pass. The reviewer did not repeat test execution.

Tests use the isolated default-profile Redis fixture at port 6380. Local logs are
under `.build/lease-count-parity-*` and `.build/reconciliation-*`.
The earlier seven-set 1,055-test matrix remains historical evidence, not a claim
that all seven sets were rerun for this correction. Remote CI must pass on the
current PR head before merging.

## Integration boundary

Merge #67 after its latest-head quality, compatibility and Redis checks pass.
Then close #66 as superseded, retaining its remote branch and commit history.
No release or tag is part of this step. Read live PR state to verify completion;
this document describes the reconciliation and its acceptance criteria.
