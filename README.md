# Laravel Bloom Gate

> **Status:** pre-release — M4 lifecycle and verification is complete.

Laravel Bloom Gate is being built as a production-safe probabilistic query gate for Laravel applications. Its eventual purpose is to let applications skip authoritative lookups only when every required runtime invariant establishes that a Bloom negative is safe to use.

## Why

A Bloom filter can answer:

- **definitely absent**
- **maybe present**

A positive result is never authoritative. Database constraints, caches, idempotency stores, and business rules remain authoritative.

## Design goals

- Laravel 12 and 13.
- PHP 8.3+.
- Greenfield and existing applications as equal use cases.
- Redis-backed production operation.
- Side-effect-free Composer installation.
- Explicit versioned lifecycle: build, shadow, verify, activate, rebuild.
- Fail-open behavior when Bloom infrastructure is unavailable or unsafe.
- Framework-independent core boundaries inside a Laravel-first package.

## Current milestone

**M4 — lifecycle and verification**

M4 adds a framework-neutral lifecycle/control plane around the M2/M3 Bloom data plane:

- immutable revisioned logical-filter control state;
- monotonic generation allocation with no version reuse;
- shared `FilterControlStore` contract with Memory and Redis implementations;
- explicit lifecycle transition policy;
- streaming activation verification over `NormalizedValue`;
- version-bound verification evidence;
- explicit `SHADOW -> VERIFIED` evidence application;
- explicit `VERIFIED + HEALTHY -> ACTIVE` promotion;
- explicit active-generation deactivation;
- control-plane probe eligibility for only the current `ACTIVE + HEALTHY` generation;
- strict Redis `control-v1` persistence;
- atomic Redis compare-and-swap with live two-writer concurrency evidence;
- additive structured Redis EVAL support;
- executable M4 architecture boundaries.

The M3 stock-Redis bitmap data plane remains unchanged in purpose. Core still owns probe generation; Redis receives validated layouts/positions and does not normalize or hash application values.

The raw M3 `destroy -> provision` driver primitive remains available for low-level recovery. M4-managed generations are versioned and must not be destructively rebuilt/reused in place.

## Important safety boundary

`ACTIVE + HEALTHY` in M4 means **control-plane probe eligibility**.

It does **not** mean the package has implemented final authorization to skip an authoritative lookup.

M4 does not yet provide package-facing query interception, Eloquent orchestration, or authoritative-query bypass APIs.

Runtime normalization identity/fingerprint compatibility remains a mandatory M5 design blocker before safe negative-result query skipping can be exposed.

## Safety principles

1. The authoritative datastore remains the source of truth.
2. A positive Bloom result always requires authoritative verification.
3. Only the current `ACTIVE + HEALTHY` generation is probe-eligible in the M4 control plane.
4. Probe eligibility alone does not authorize skipping an authoritative lookup.
5. Infrastructure failure must bypass the optimization rather than change application correctness.
6. Installing the package must not scan a database, contact Redis, activate filters, or intercept queries.
7. Missing or corrupt Redis storage is never interpreted as a definite negative.
8. Redis operational failures, control-state conflicts, and storage corruption remain distinct.

## Architecture

See:

- [Architecture overview](docs/architecture/overview.md)
- [Core semantics](docs/architecture/core-semantics.md)
- [Bloom probe and driver contract](docs/architecture/bloom-probe-and-driver-contract.md)
- [Lifecycle](docs/architecture/lifecycle.md)
- [Redis foundation](docs/architecture/redis-foundation.md)
- [Redis keyspace](docs/architecture/redis-keyspace.md)
- [ADRs](docs/adr)

## Requirements

Current verified anchors:

- PHP 8.3+;
- Laravel 12 or 13;
- Redis 8 standalone integration anchor;
- PhpRedis-backed Laravel/Testbench integer and structured EVAL execution;
- memory-backed deterministic Bloom and control-state reference stores.

Redis Cluster keyspace compatibility is designed in, but Redis Cluster runtime support is not yet an official claim.

Predis operational-exception normalization is unit-tested, but real Predis runtime execution is not yet an official support claim.

Support claims are considered official only after automated compatibility evidence exists.

## Roadmap

- **M0:** repository/package bootstrap — complete
- **M1:** core semantic value objects — complete
- **M2:** memory reference driver and contract suite — complete
- **M3:** Redis foundation — complete
- **M4:** lifecycle and verification — complete
- **M5:** Laravel/Eloquent integration — requires a separate scope/design gate
- **M6:** production hardening

M5 must not start automatically after M4. Its scope/design gate must begin with runtime normalization-identity compatibility.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

See [SECURITY.md](SECURITY.md).

## License

MIT.