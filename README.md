# Laravel Bloom Gate

> **Status:** pre-release — M5 safe Laravel query integration is complete.

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

## Current milestone

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

`add()` / `addMany()` are managed synchronization operations and are valid only for the supported mutable consistency contract.

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
10. M5 does not claim online dual-write rebuild, CDC/outbox synchronization, Redis Sentinel, or Redis Cluster runtime support.

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
- **M6:** deferred hardening such as online rebuild/dual-write coordination and broader runtime profiles

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

See [SECURITY.md](SECURITY.md).

## License

MIT.
