# Published evaluation candidate v0.1.0-rc.2

## Scope and immutable identity

The user selected publication of rc.2 and direct Packagist installation
verification. This closes the four-step release preparation for an evaluation
candidate; it does not declare stable v0.1.0 or production deployment acceptance.

- Source commit: `e82584c62425146b43082147284c88d9411e9bd7`.
- Annotated tag: `v0.1.0-rc.2`, object `4bd127dacbd0158c0b9b59db49e8716f1a3fa602`.
- [GitHub prerelease](https://github.com/kefyusuf/laravel-bloom-gate/releases/tag/v0.1.0-rc.2): published 2026-10-06 09:56:07 UTC; draft false, prerelease true.
- [Packagist package](https://packagist.org/packages/kefyusuf/laravel-bloom-gate): rc.2 indexed, source reference matches the commit above; last update observed 09:57:18 UTC with auto-updates enabled.
- The existing rc.1 tag remains at `77401a64b76ea7ceb47a6254fd1f38b3a0d09aca`.

PRs [78](https://github.com/kefyusuf/laravel-bloom-gate/pull/78) and
[79](https://github.com/kefyusuf/laravel-bloom-gate/pull/79) merged after successful
checks. The first merged preparation commit failed minimum-dependency analysis
in five fixture token-generation calls. Reusing the existing typed
`WriterLeaseToken` factory fixed that compatibility issue; the full final gate
was rerun on the source commit above before publication. Package runtime source
was not changed by those release-fixture corrections.

## Exact-source CI evidence

All runs below selected `e82584c62425146b43082147284c88d9411e9bd7` and succeeded.

| Check | Evidence | Result |
|---|---|---|
| Release Gate | [37446062282](https://github.com/kefyusuf/laravel-bloom-gate/actions/runs/37446062282) | Six PHP 8.3/8.4/8.5 and Laravel 12/13 pairs plus minimum dependencies; audits, discovery and live-Redis full suite passed; 1,096 tests / 8,380 assertions per target |
| Distribution | [37446066481](https://github.com/kefyusuf/laravel-bloom-gate/actions/runs/37446066481) | Commit ZIP, export contents and production-only consumer passed |
| Quality | [37446059850](https://github.com/kefyusuf/laravel-bloom-gate/actions/runs/37446059850) | Required quality checks passed |
| RC Pilot | [37446070778](https://github.com/kefyusuf/laravel-bloom-gate/actions/runs/37446070778) | MySQL, PostgreSQL and SQLite pilots and bounded measurements passed |
| Published-tag RC Pilot | [37446542419](https://github.com/kefyusuf/laravel-bloom-gate/actions/runs/37446542419) | Dispatch at rc.2, peeled tag verification and all three consumer targets passed; benchmark JSON artifacts retained |

## Direct registry consumer

A new production-only consumer required
`kefyusuf/laravel-bloom-gate:0.1.0-rc.2` and Laravel 13 through default Packagist.
The manifest contained no VCS, path or custom repository entry. Composer
installed the package as a ZIP dist at the exact published source commit.

Passed locally with PHP 8.5.11, Laravel 13.34.0 and isolated durable Redis 8.10.2:

- Installed dist identity and exported runtime/operator documentation.
- Laravel package discovery, service resolution and command execution without development dependencies.
- MySQL 8.4.11 and PostgreSQL 17.11 authoritative queries, trusted negatives,
  SQL fallback, known commit/rollback acknowledgement and coordinated rebuild.
- SQLite 3.40.1 as a reference consumer with the same assertions.

An initial local invocation used an unsupported Redis profile spelling and
failed closed. All pilots then passed using the documented
`standalone-primary-durable-v1` profile against the qualified isolated stack.
No production database or deployment was used.

## Performance and remaining limits

The [preparation and measurement record](2026-10-06-rc2-closure.md) contains
bounded local results. Gate SQL calls dropped from 1,000 to 8 for all-absent
requests and to 108 for 10% present requests, but elapsed latency increased in
every measured local workload. Retained writer history also raises drain cost.
These observations support query avoidance, not a general speedup claim.

MySQL and PostgreSQL are actual server-backed verification targets. SQLite is a
fast reference. The fixtures enforce exact-byte equality aligned with their
normalizer; applications must qualify their own equality/collation contract.
MongoDB, Redis Cluster, unobserved production workloads, failover durability
and application-wide writer adoption remain outside this verified boundary.
The package is ready for evaluation through the published candidate. Stable
promotion requires evidence from the intended application's workload and
operating profile.

## Resource lifecycle

The isolated Compose project is `lbg-rc2-closure-20261006`. Its task containers,
database volumes and network are removed after verification, together with the
task-built `lbg-rc2-closure-php:20261006` image. Reused base/database/Redis images
and unrelated resources are retained. No repository worktree was created.
This publication record is a follow-up commit; the published tag is immutable.
