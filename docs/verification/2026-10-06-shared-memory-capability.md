# Shared-memory capability gate — 2026-10-06

## Scope and provenance

Approved plan PR #86 merged at
`ad6fc04e5319d009bbda2b0e06600342afef57ac`. Task branch:
`test/swoole-shared-memory-capability`. Only this package, official dependencies
and a blank synthetic Laravel application were used. No private application was
inspected or tested. No production source, backend default or release changed.

Fixture: `tests/Experiments/Swoole/`; immutable dependency lock and Docker base
digests are tracked. Tested PHP 8.4.26, Laravel 13.34.0, Octane 2.20.0,
OpenSwoole 26.2.0, PhpRedis 6.3.0, APCu 5.1.28, MySQL 8.4. Root Octane is a
dev-only dependency for the existing PHPStan max gate, including its actual
non-autoloaded WorkerState definition. Production dependencies are unchanged.

## TDD and native evidence

RED: uninitialized parent state failed `workers_share_parent_table` on its empty
incarnation and `worker_restart_preserves_parent_table` on its missing sentinel.
An empty dataset failed `consumer_cannot_mutate_sealed_dataset` on 0 versus
1,000,000 rows. Startup/test-harness failures were repaired before these behavior
checks were accepted as RED evidence.

GREEN: four native tests, 42 assertions, no warning/risky tests. Actual four HTTP
worker PIDs observed a marker written by another worker through a native table.
Reload replaced worker PIDs outside the complete old PID set while preserving
parent incarnation and contents. Explicit Octane shutdown released the socket;
new parent startup produced a different 128-bit incarnation and unpublished
control state. No task workers were enabled.

Preflight: native Octane/OpenSwoole aliases available; exactly 4096 binary bytes
round-tripped. A fork start barrier and 100,000 paired whole-row writes produced
12,807 coherent reads, including 7,961 intermediate revision reads. Zero mixed
revision/mirror/bytes observations. Sampling is bounded runtime evidence, not a
formal concurrency proof or multi-row transaction guarantee.

Factory: 100 batches of 10,000 unique exact indexed membership keys. Setup:
49.39 seconds. Expected sequence and independently streamed ordered SQL contents
matched SHA-256 `9ee8cb5c216392aacf242289162d07bc7292f5c06ffa373a51d4f7d1e10fae00`.
Seeder disconnected/exited, its account was dropped, global read_only and
super_read_only were set to 1. HTTP credentials have only USAGE and SELECT on
`demo.members`. INSERT/UPDATE/DELETE were rejected; only MySQL permission error
1142 or explicit read-only error 1290 is accepted. Cardinality and endpoints
remain exact after the attempted writes.

Local Composer validation and full `composer check` passed: Pint, PHPStan max,
946 tests / 7,692 assertions; one unrelated optional skip. The verification copy
used Git-style LF normalization for existing Windows CRLF files. No production
files were reformatted. Exact-commit hosted CI and fresh scripted reproduction
remain separate required gates.

The final standard-PHP check without native extensions also passed Pint,
PHPStan max and the fast suite: 929 tests / 7,643 assertions, 18 optional extension
skips. Older Pest uses the existing `it()` test factory; native PHP entropy
return types are documented precisely for minimum PHPStan compatibility in the
fixture and three existing test-only files. No production logic changed.

Fresh CI caught an incorrect Octane manifest pin: the installed/tested lock was
2.20.0, while the first manifest/report incorrectly named 2.17.0. The manifest,
lock metadata and report now agree on 2.20.0. Standard CI also caught formatter
differences between native aliases and extension-free PHP; the preflight uses
an explicit dynamic native class check and stable Swoole type imports.

## Review rulings and limits

Independent review rejected a constant `trusted_negative=false` as query proof.
The fixture now reports actual shared unpublished control state; real QueryGate
Bypassed plus authoritative SQL call assertions are required in Task 2. Review
also required observed intermediate writes, now enforced by native preflight.
The Windows skill ledger uses native file tools because a configured helper
Python/Git Bash runtime was unavailable; tracked reports retain continuation.

The pinned image required sockets to load before OpenSwoole and `procps` for
Octane's official shutdown command (`pgrep`). These are fixture corrections.
Task 1 does not implement the bitmap query backend, establish a Redis-removal
speedup, qualify mutable datasets or enable production support.

Task-owned Docker project `lbg-shared-memory-20261006`, its two containers,
network, two volumes and `lbg-shared-memory-php:20261006` image were removed.
Subsequent temporary formatting/lock/static-check containers used `--rm` and
left no task volumes or images. Final exact-head native/compatibility/lowest CI
and independent review status must be confirmed before this gate closes.
Base images and all unrelated Docker resources are retained. No worktree created.

The reproducible script now generates fresh SQL administrator/seeder passwords
outside HTTP workers, deletes the seeder account after setup and verifies that
HTTP environments contain no writer credentials. This strengthened setup requires
the final fresh native CI run; initial local observations precede this change.
