# Laravel Bloom Gate

> **Status:** evaluation prerelease — [v0.1.0-rc.1](https://github.com/kefyusuf/laravel-bloom-gate/releases/tag/v0.1.0-rc.1) is published; M6 is implementation-complete.

The first evaluation candidate's preparation is documented in the
[release readiness record](docs/verification/2026-10-05-release-readiness.md).
See the [publication record](docs/verification/2026-10-05-published-candidate.md)
for the immutable tag and verified source revision.

M6 includes coordinated persistence, prepared writers, explicit adoption/recovery,
online rebuild/abort, coordination diagnostics, and Laravel adapters/commands.
See the [final verification report](docs/verification/2026-10-02-m6-wu13.md),
[full local release matrix](docs/verification/2026-10-02-release-matrix.md),
[final code review and post-fix checks](docs/verification/2026-10-02-m6-final-review.md),
[Laravel coordinated operation](docs/architecture/laravel-coordination.md),
[coordination diagnostics](docs/architecture/coordination-diagnostics.md)
and the [M6 work-unit plan](docs/plans/2026-09-28-m6-implementation-task-breakdown-verification-matrix.md).

Laravel Bloom Gate is a production-safe probabilistic query gate for Laravel applications.

It can skip an authoritative existence lookup only when the runtime proves that a Bloom negative is safe to trust. A Bloom positive is never authoritative.

## Why

A Bloom filter can answer:

- **definitely absent**
- **maybe present**

Laravel Bloom Gate preserves the authoritative datastore as the source of truth:

- **definitely absent** may skip the authoritative lookup only after every M5 safety precondition passes;
- **maybe present** always performs the authoritative lookup;
- any safety uncertainty bypasses the optimization and performs the authoritative lookup.

The public `exists()` result is therefore authoritative-correct.

## Design goals

- Laravel 12 and 13.
- PHP 8.3+.
- Greenfield and existing applications as equal use cases.
- Redis-backed production operation.
- Side-effect-free Composer/package discovery.
- Explicit versioned lifecycle: build, shadow, verify, activate, retire.
- Fail-open query optimization.
- Framework-independent Core, Contracts, Lifecycle, Drivers, and Application layers.
- No hidden database interception or observer assumptions.

## Install and first filter

PHP 8.3+ and Laravel 12/13 are required. The following installation command
targets the upcoming `v0.1.0-rc.2` candidate; use it after publication and registry
indexing are verified. This remains an evaluation prerelease.

```sh
composer require kefyusuf/laravel-bloom-gate:0.1.0-rc.2
php artisan vendor:publish --tag=bloom-gate-config
```

Create `app/Bloom/CountryCodeFilter.php`:

```php
<?php

namespace App\Bloom;

use Kefyusuf\BloomGate\Contracts\{AuthoritativeSet, FilterDefinition, ValueNormalizer};
use Kefyusuf\BloomGate\Core\{AuthoritativeSetIdentity, ConsistencyContract, NormalizationIdentity, NormalizedValue};

final class CountryCodeFilter implements FilterDefinition
{
    public function normalizer(): ValueNormalizer
    {
        return new class implements ValueNormalizer {
            public function identity(): NormalizationIdentity
            {
                return NormalizationIdentity::fromString('country-code-uppercase@1');
            }

            public function normalize(string|int $value): NormalizedValue
            {
                return NormalizedValue::fromBytes(strtoupper(trim((string) $value)));
            }
        };
    }

    public function authoritativeSet(): AuthoritativeSet
    {
        return new class implements AuthoritativeSet {
            public function identity(): AuthoritativeSetIdentity
            {
                return AuthoritativeSetIdentity::fromString('demo-country-codes@1');
            }

            public function values(): iterable
            {
                yield from ['TR', 'DE', 'US'];
            }

            public function exists(NormalizedValue $value): bool
            {
                return in_array($value->bytes(), ['TR', 'DE', 'US'], true);
            }
        };
    }

    public function consistency(): ConsistencyContract
    {
        return ConsistencyContract::ImmutableV1;
    }
}
```

Add this entry to `filters` in `config/bloom-gate.php`:

```php
'demo.country_codes' => [
    'enabled' => true,
    'definition' => App\Bloom\CountryCodeFilter::class,
    'capacity' => 100,
    'false_positive_rate' => 0.01,
],
```

Keep the Redis driver and configure Laravel's Redis connection. Run:

```sh
php artisan bloom:doctor
php artisan bloom:build demo.country_codes
php artisan bloom:verify demo.country_codes
php artisan bloom:activate demo.country_codes
php artisan bloom:status demo.country_codes
```

Application code can now call:

```php
use Kefyusuf\BloomGate\Laravel\Facades\BloomGate;

BloomGate::exists('demo.country_codes', 'tr'); // true
BloomGate::exists('demo.country_codes', 'ZZ'); // false
```

Without a qualified Redis trusted-negative profile, lookups still use the
authoritative source. Declare `BLOOM_GATE_REDIS_TRUSTED_NEGATIVE_PROFILE` only
after verifying the [Redis deployment contract](docs/architecture/redis-foundation.md).
The three-value immutable example demonstrates the lifecycle, not a performance
benefit. Change semantic identities when their behavior changes. For mutable
data, follow [coordinated writing and recovery](docs/architecture/laravel-coordination.md)
and include every membership-entry writer.

For SQL definitions, normalization must match the authoritative query's equality
and collation rules. An exact-byte normalizer with a case-insensitive SQL lookup
is unsafe: a differently cased value can exist in SQL while its Bloom bits are
absent. Normalize both enumeration and query inputs consistently, or use an
appropriate exact-comparison column/query. The package cannot infer this contract.

## Current milestone

**M6 — Online Rebuild and Write Coordination — implementation complete**

The package is evaluating the published `v0.1.0-rc.1` candidate. M6 adds
coordinated prepared writes, explicit adoption, resumable online rebuild/abort,
evidence-bound lease recovery, and read-only coordination diagnostics. Laravel
exposes these capabilities through the facade and Artisan commands.

The [Redis/SQLite consumer pilot](docs/verification/2026-10-05-rc-redis-sqlite-pilot.md)
verifies the published candidate's query and known commit/rollback paths. It does
not establish production performance or deployment acceptance. No M7 feature
scope is currently defined.

## Query integration foundation

**M5 — Safe Laravel Query Integration**

M5 adds the application and Laravel integration required to turn the M4 lifecycle/control plane into a safe query gate:

- explicit framework-neutral `FilterDefinition` contracts;
- deterministic semantic identities and SHA-256 fingerprints;
- deterministic Bloom sizing from capacity + false-positive-rate targets;
- generation-scoped semantic binding;
- managed candidate build, verification, activation, discard, and status workflows;
- two explicit consistency contracts: `immutable-v1` and `preadd-v1`;
- public authoritative-correct `QueryGate::exists()`;
- Laravel `BloomGate` facade;
- `BloomUnique` and `BloomExists` validation adapters;
- revision-pinned atomic Redis authorized probing;
- fail-open authoritative fallback;
- explicit managed membership synchronization for `preadd-v1`;
- `bloom:build`, `bloom:verify`, `bloom:activate`, `bloom:discard`, `bloom:status`;
- `bloom:doctor` production-safety diagnostics;
- executable M5 architecture boundaries.

## Query-skip safety boundary

`ACTIVE + HEALTHY` is necessary but **not sufficient** to skip an authoritative lookup.

A trusted negative also requires, at minimum:

1. query optimization enabled globally and for the filter;
2. the current active generation to be the revision-pinned `ACTIVE + HEALTHY` generation;
3. valid generation storage and the exact persisted layout;
4. bound generation semantics;
5. exact runtime/persisted equality for:
   - normalization fingerprint;
   - authoritative-set fingerprint;
   - consistency fingerprint;
6. a safe authorized probe result;
7. for Redis trusted negatives, explicit declaration of the supported production profile.

If any prerequisite is absent, stale, mismatched, corrupt, unavailable, or changed during the authorized probe, the optimization is bypassed and the authoritative source is queried.

Existing M3 generations without M5 semantic fingerprints are **not corrupt**. They remain valid low-level Bloom storage, but they are query-skip-ineligible until rebuilt/bound through the M5 managed workflow.

## Explicit filter definitions

Each registered filter resolves a framework-neutral `FilterDefinition`:

```php
interface FilterDefinition
{
    public function normalizer(): ValueNormalizer;

    public function authoritativeSet(): AuthoritativeSet;

    public function consistency(): ConsistencyContract;
}
```

Managed sizing is configured with:

- capacity;
- false-positive rate.

The package derives the Bloom layout deterministically with the current M5 sizing policy rather than accepting an arbitrary runtime layout for managed builds.

## Consistency contracts

### `immutable-v1`

Use when membership does not change while the generation is active.

Managed synchronization writes are rejected.

### `preadd-v1`

Use when the application can guarantee this ordering for every membership-entry write:

```text
Bloom add first
then authoritative write
```

This avoids a committed authoritative-present row appearing before its Bloom bits.

Activation requires an explicit quiescent membership-entry window. During activation the package performs full candidate reconciliation, then fresh verification, then promotion.

Eloquent observers are not considered trusted-negative authority because they do not cover every possible write path.

## Laravel-facing API

The facade delegates to the canonical Application services:

```php
BloomGate::exists('users.email', $email);
BloomGate::existsResult('users.email', $email);

BloomGate::add('users.email', $email);
BloomGate::addMany('users.email', $emails);
```

`add()` / `addMany()` are legacy `preadd-v1` synchronization operations.
Adopted coordinated filters fence those operations and use `BloomGate::prepare()`
before the authoritative write, followed by an explicit commit/abort acknowledgement.

Validation adapters:

```php
new BloomUnique('users.email');
new BloomExists('users.email');
```

They delegate to `QueryGate`. They do not reimplement query correctness and do not claim Laravel native rule features such as `ignore()`, arbitrary `where()`, `withoutTrashed()`, or `onlyTrashed()`.

## Managed lifecycle commands

```text
php artisan bloom:build <filter>
php artisan bloom:verify <filter>
php artisan bloom:activate <filter> [--quiescent]
php artisan bloom:discard <filter>
php artisan bloom:status [filter]
php artisan bloom:doctor
```

`bloom:build` creates a candidate only; it does not silently verify or activate.

`bloom:activate` always performs fresh verification before promotion. `preadd-v1` additionally requires `--quiescent` and performs reconciliation under that window.

`bloom:doctor` is read-only preflight/diagnostics. It does not repair Redis configuration or mutate filter lifecycle.

## Redis production support

M5 trusted-negative Redis support is intentionally narrow.

The recognized profile declaration is:

```text
standalone-primary-durable-v1
```

The production-safety contract requires the configured connection to remain:

- Redis 8;
- standalone;
- authoritative primary/master;
- AOF enabled;
- `appendfsync = always`;
- `maxmemory-policy = noeviction`.

`bloom:doctor` can verify these observable prerequisites at a point in time. It does not make them continuously true. Keeping the declared profile valid remains an operator responsibility.

Redis Sentinel and Redis Cluster runtime support are **not** M5 support claims. The keyspace remains same-slot/Cluster-aware by construction, but runtime support requires separate evidence.

## Safety principles

1. The authoritative datastore remains the source of truth.
2. Bloom positives never authorize existence.
3. `ACTIVE + HEALTHY` alone never authorizes query skipping.
4. Semantic compatibility is generation-scoped and exact.
5. Infrastructure uncertainty fails open to the authoritative lookup.
6. Installation and command discovery do not contact Redis or scan authoritative data.
7. Missing/corrupt managed Redis storage is never interpreted as a definite negative.
8. Old unbound M3 generations are query-skip-ineligible, not automatically corrupt.
9. Observer-based eventual synchronization is not trusted-negative authority.
10. Coordinated writes require explicit adoption and durable PREPARED evidence before authoritative visibility; unknown outcomes retain active leases.
11. CDC/outbox synchronization, Redis Sentinel, and Redis Cluster runtime support remain outside the implemented scope.

## Architecture

See:

- [Architecture overview](docs/architecture/overview.md)
- [Core semantics](docs/architecture/core-semantics.md)
- [Bloom probe and driver contract](docs/architecture/bloom-probe-and-driver-contract.md)
- [Normalization](docs/architecture/normalization.md)
- [Consistency](docs/architecture/consistency.md)
- [Lifecycle](docs/architecture/lifecycle.md)
- [Redis foundation](docs/architecture/redis-foundation.md)
- [Redis keyspace](docs/architecture/redis-keyspace.md)
- [Laravel coordinated operation](docs/architecture/laravel-coordination.md)
- [Coordination diagnostics](docs/architecture/coordination-diagnostics.md)
- [ADRs](docs/adr)

## Requirements

Current verified anchors:

- PHP 8.3+;
- Laravel 12 or 13;
- Redis 8 standalone integration;
- PhpRedis-backed Laravel/Testbench execution;
- deterministic Memory reference implementations;
- real Redis generation/control/query-safety integration;
- real Redis production-diagnostics reads.

Predis operational-exception normalization is unit-tested, but real Predis runtime execution is not an official support claim.

## Roadmap

- **M0:** repository/package bootstrap — complete
- **M1:** core semantic value objects — complete
- **M2:** memory reference driver and contract suite — complete
- **M3:** Redis foundation — complete
- **M4:** lifecycle and verification — complete
- **M5:** safe Laravel query integration — implementation complete
- **M6:** online rebuild/write coordination — implementation complete; published evaluation candidate

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

See [SECURITY.md](SECURITY.md).

## License

MIT.
