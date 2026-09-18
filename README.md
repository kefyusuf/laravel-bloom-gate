# Laravel Bloom Gate

> **Status:** pre-release — M2 probe semantics, driver contract, and memory reference driver are implemented. The production Redis driver is not shipped yet.

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

**M2 — memory reference driver and contract suite**

M2 adds the framework-independent Bloom probe and storage contract required by later production drivers:

- versioned `sha256-double-hash-v1` probe generation in Core;
- immutable Bloom layout and layout-bound bit positions;
- backend-neutral `BloomDriver` operations and typed contract failures;
- a reusable driver conformance suite;
- a process-local sparse memory reference driver for deterministic development and testing.

Probe generation remains in Core. Drivers receive only validated bit positions and do not own normalization or hashing.

M2 intentionally does not implement Redis Bloom commands, lifecycle orchestration, active-version resolution, or Eloquent synchronization.

## Safety principles

1. The authoritative datastore remains the source of truth.
2. A positive Bloom result always requires authoritative verification.
3. Only a healthy ACTIVE filter may short-circuit a negative lookup.
4. Infrastructure failure bypasses the optimization instead of changing application correctness.
5. Installing the package must not scan a database, contact Redis, activate filters, or intercept queries.

## Architecture

See [docs/architecture/overview.md](docs/architecture/overview.md), [docs/architecture/core-semantics.md](docs/architecture/core-semantics.md), [docs/architecture/bloom-probe-and-driver-contract.md](docs/architecture/bloom-probe-and-driver-contract.md), and the accepted decisions in [docs/adr](docs/adr).

## Requirements

Planned v1 baseline:

- PHP 8.3+
- Laravel 12 or 13
- Redis-backed production driver
- Memory-backed test/development reference driver

Support claims are considered official only after automated compatibility evidence exists.

## Roadmap

- **M0:** repository/package bootstrap — complete
- **M1:** core semantic value objects — implemented
- **M2:** memory reference driver and contract suite — implemented
- **M3:** Redis foundation
- **M4:** lifecycle and verification
- **M5:** Laravel/Eloquent integration
- **M6:** production hardening

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

See [SECURITY.md](SECURITY.md).

## License

MIT.
