# M6 final review and post-fix verification — 2026-10-02

## Scope and outcome

An independent read-only review covered the accumulated uncommitted WU-10 through
WU-13 implementation, compatibility changes, tests and documentation against
`main@d2f95878f43af1dca5f7e61acf6d1fdb1afd9954` and ADR-0041/0042/0043.
Both substantive findings were fixed. A second scoped review found no new
substantive regression and accepted the reviewed fixes for integration.
The reviewer did not independently rerun the test suite; the executions below
were performed by the primary agent.

## Findings resolved

1. Invalid runtime configuration was caught as generic diagnostic unavailability.
   `CoordinationStatusReader` now reports `Invalid/invalid_configuration` and
   retains unknown fields. A focused regression failed before the fix and passed
   afterward.
2. Control or generation corruption could prevent `bloom:status` from showing
   coordination evidence. The command now attempts an independent coordination
   read on managed-status failure, prints the available evidence, preserves the
   original error and returns failure. If that service cannot be constructed,
   it reports `Unavailable/diagnostics_unavailable`.

Five new regression tests cover invalid configuration classification, status and
doctor presentation, corrupt control, corrupt generation with an existing
prepared lease, and unavailable service construction. They assert failure exits
where appropriate and preserve lease/count state without lifecycle mutation.

## Post-fix verification

Every row passed `composer check` (Pint, maximum-level PHPStan, fast suite) and
`composer test:all` against the synchronized final PHP source. Docker source copies
used LF endings and the isolated Redis 8.10.2 service.

| PHP | Laravel | Testbench | Quality / full suite |
|---|---|---|---|
| 8.3.35 | 12.69.3 | 10.12.0 | Passed |
| 8.3.35 | 13.34.0 | 11.3.0 | Passed |
| 8.4.26 | 12.69.3 | 10.12.0 | Passed |
| 8.4.26 | 13.34.0 | 11.3.0 | Passed |
| 8.5.11 | 12.69.3 | 10.12.0 | Passed |
| 8.5.11 | 13.34.0 | 11.3.0 | Passed |
| 8.3.35, `prefer-lowest` | 12.69.0 | 10.2.0 | Passed |

Each environment passed 883 fast tests / 7,365 assertions and 1,055 full tests /
8,265 assertions, including 172 live-Redis tests. Logs are retained under ignored
`.build/final-review-*`. The final whitespace diff check passed.

Audit and package discovery passed in the preceding
[release matrix](2026-10-02-release-matrix.md). No dependencies changed for these
fixes, so those checks were not repeated.

## Limits and continuation

The review did not independently re-audit the WU-00 through WU-09 baseline beyond
its integration with the changed scope. Deterministic interruption tests do not
prove hardware power-loss durability or actual operating-system termination.
The accepted C20 external-history boundary remains unchanged.

M6 implementation and local verification are complete; the package remains
pre-release. Changes remain uncommitted. No remote CI, commit, pull request, tag
or publication was created. See the [project handoff](../handoff.md) before
continuing from another session.
