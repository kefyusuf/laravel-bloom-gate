# Laravel Bloom Gate

> **Status:** pre-release — M3 Redis foundation is implemented on the current development branch and is undergoing final review.

Laravel Bloom Gate is being built as a production-safe probabilistic query gate for Laravel applications. Its purpose is to let applications skip authoritative lookups only when a healthy, active Bloom filter can prove that a value is definitely absent.

## Why

A Bloom filter can answer:

- **definitely absent**
- **maybe present**

A positive result is never authoritative. Database constraints, caches, idempotency stores, and business rules remain authoritative.

## Design goals

- Laravel 12 and 13.
- PHP 8.3+.
- Greenfield and existing applications are equal use cases.
- Redis-backed production operation.
- Side-effect-free Composer installation.
- Explicit lifecycle: build, shadow, verify, activate, rebuild.
- Fail-open behavior when Bloom infrastructure is unavailable or unsafe.
- Framework-independent core boundaries inside a Laravel-first package.

## Current milestone

**M3 — Redis foundation**

M3 provides the production data-plane foundation while preserving the M2 probe and driver contracts:

- stock Redis bitmap storage using generation-scoped metadata + bitmap keys;
- deterministic Cluster-aware key generation with shared hash tags;
- atomic Lua/EVAL operations for provision, add, membership checks, and destroy;
- typed separation between missing storage, layout conflict/mismatch, storage corruption, and Redis operational failure;
- a framework-neutral `RedisCommandExecutor` port;
- `RedisBloomDriver` conforming to the reusable `BloomDriver` contract;
- real Redis contract and corruption integration evidence;
- a Laravel-only Redis connection adapter verified with Testbench + PhpRedis + real Redis EVAL.

Probe generation remains in Core. Redis receives only validated `BloomLayout` and `BitPositions`; it does not normalize or hash application values.

M3 intentionally does not implement active-version resolution, lifecycle/health orchestration, fail-open application policy, rebuild workflows, Eloquent synchronization, or Bloom sizing policy.

## Safety principles

1. The authoritative datastore remains the source of truth.
2. A positive Bloom result always requires authoritative verification.
3. Only a healthy ACTIVE filter may short-circuit a negative lookup.
4. Infrastructure failure bypasses the optimization instead of changing application correctness.
5. Installing the package must not scan a database, contact Redis, activate filters, or intercept queries.
6. Missing or corrupt Redis storage is never interpreted as a definite negative.
7. Redis operational failures remain distinct from programming and configuration errors.

## Architecture

See [docs/architecture/overview.md](docs/architecture/overview.md), [docs/architecture/core-semantics.md](docs/architecture/core-semantics.md), [docs/architecture/bloom-probe-and-driver-contract.md](docs/architecture/bloom-probe-and-driver-contract.md), [docs/architecture/redis-foundation.md](docs/architecture/redis-foundation.md), [docs/architecture/redis-keyspace.md](docs/architecture/redis-keyspace.md), and the accepted decisions in [docs/adr](docs/adr).

## Requirements

Current verified anchors:

- PHP 8.3+;
- Laravel 12 or 13;
- Redis 8 standalone integration anchor;
- PhpRedis-backed Laravel/Testbench EVAL integration;
- memory-backed deterministic reference driver.

Redis Cluster keyspace compatibility is designed in, but Redis Cluster runtime support is not yet an official claim. Predis operational-exception normalization is unit-tested, but real Predis runtime execution is not yet an official support claim.

Support claims are considered official only after automated compatibility evidence exists.

## Roadmap

- **M0:** repository/package bootstrap — complete
- **M1:** core semantic value objects — complete
- **M2:** memory reference driver and contract suite — complete
- **M3:** Redis foundation — implementation complete, final review in progress
- **M4:** lifecycle and verification
- **M5:** Laravel/Eloquent integration
- **M6:** production hardening

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

See [SECURITY.md](SECURITY.md).

## License

MIT.
