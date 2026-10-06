# Writer drain count integrity — 2026-10-06

Base: `main` at `9ae61535a7608edf258f6ffb29aaba2cb1a98ca3`.
Branch: `fix/writer-drain-count-integrity`.

## Scope and behavior

Public `activeWriterCount` reads now validate the same whole-filter lease/count
agreement as lease diagnostics. Inconsistent evidence throws
`CoordinationStateCorrupt` rather than proving a drained epoch. Internal
acquire/release transition helpers retain their existing behavior.

Redis validates both hashes in one read-only Lua evaluation. Memory reuses its
existing diagnostic validation. Empty history, explicit zero counts and
released tombstones remain valid. Neither backend repairs contradictory state.

This addresses partial correctness-state loss or corruption. It does not
establish an unprivileged remote exploit. Operators must still use durable,
non-evicting Redis and coherent same-filter recovery. Complete evidence loss
cannot be distinguished from empty history by this change.

Count validation scans retained leases and epoch fields. Redis skips token
sorting and diagnostic output collection for count reads; Memory retains its
diagnostic sorting. No new index or storage schema is introduced. Drain polling
cost therefore grows with retained history; no performance benchmark was run.

## TDD and verification

Isolated Docker project: `lbg-drain-integrity-20261006`, reusing existing
`lbg-release-php:8.5` and `redis:8-alpine` images. Host source was mounted
read-only; Composer dependencies and lockfile remained inside the container.
No repository `.build` or host dependency tree was created.

Environment: PHP 8.5.11, Laravel 13.34.0, Testbench 11.3.0, Pest 4.7.8,
PHPUnit 12.5.33, live Redis 8 with `noeviction`.

- RED: both writer contract suites filtered by `test_active_writer_count`:
  **30 failed, 2 passed**, 76 assertions. Failures were the missing expected
  corruption exception, before production changes.
- GREEN: the same command: **32 passed**, 76 assertions.
- `composer check`: passed Pint, PHPStan and **899 tests**, 7,403 assertions.
- `composer test:all`: **1,096 passed**, 8,380 assertions, including live Redis.
- An existing parity assertion that read an intentionally inconsistent count
  was changed to expect corruption. Existing diagnostic corruption tests now
  inspect raw storage to prove that reads do not repair it.
- Independent read-only design and diff reviews found no additional actionable
  defect. Reviewers did not independently rerun these test commands.

This is one local dependency target, not a complete release compatibility
matrix or production recovery test. No merge, tag or release is authorized by
this record.
