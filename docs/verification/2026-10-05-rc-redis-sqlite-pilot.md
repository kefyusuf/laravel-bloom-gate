# Isolated RC Redis/SQLite application pilot — 2026-10-05

## Target

The first pilot is a minimal isolated Laravel application in Docker, not an
existing business application or production deployment. It installs the
published `v0.1.0-rc.1` ZIP through Composer dist with the exact
`0.1.0-rc.1` requirement and only production dependencies. The annotated tag
must still resolve to `77401a64b76ea7ceb47a6254fd1f38b3a0d09aca`.

Unlike the earlier Memory bootstrap consumer, this pilot registers a real
SQLite-backed authoritative set and uses live Redis coordinated persistence.
Every run gets a fresh on-disk SQLite file and random Redis key prefix.
Only this application participates in the pilot's writer protocol.

## Local observations

- The existing isolated Redis reported standalone primary/master, AOF enabled,
  `appendfsync=always` and `noeviction`. No shared Redis settings were changed.
- Without the explicit trusted-negative profile, the pilot failed the assertion
  requiring a negative to skip SQL; it did not falsely authorize an optimization.
- With the profile asserted, adoption and initial rebuild completed; an absent
  value skipped the SQL lookup, while a present value used the authoritative SQL.
- A prepared write followed by SQL transaction commit and known-outcome
  acknowledgement became visible through authoritative-correct queries.
- An unacknowledged prepared lease remained present. SQL rollback followed by
  aborted acknowledgement released it. Its pre-added Bloom positive still
  consulted SQL and returned false.
- A subsequent rebuild retained committed membership. Disabling optimization
  made even an absent value perform the authoritative SQL lookup.
- Installed metadata confirmed ZIP/dist at the immutable release commit;
  bootstrap confirmed Testbench and PHPUnit were absent.

## Failure-exit correction

The first pilot failure exposed Laravel's uncaught exception handler returning
process status zero after bootstrap. The pilot and existing standalone smoke
now catch failures explicitly and exit 1. The corrected missing-profile check
returned 1, and the configured pilot returned 0. A deliberate smoke failure
after bootstrap is also checked independently, so a failing consumer assertion
cannot be reported as passing CI. This changes verification fixtures, not the
published package runtime or immutable tag.

## Reproduction and limits

The manually dispatched `.github/workflows/rc-pilot.yml` pins the published
candidate and its peeled SHA, installs an isolated consumer, starts its own Redis
with the declared AOF profile, executes negative/positive checks and removes
that CI-only container afterward. It also runs on PRs changing its workflow or
direct consumer fixtures; it does not become a required gate for every PR.

Local quality passed Pint, maximum PHPStan and 883 tests / 7,365 assertions.
The pilot workflow must pass on the candidate fixture commit before merge.
SQL lookup counts prove execution paths; they are not latency measurements.
This is a synchronous small dataset with one writer and standalone Redis. It does
not prove process-crash durability, external history completeness, a real business
application integration, PostgreSQL/MySQL behavior, load capacity or Cluster.
