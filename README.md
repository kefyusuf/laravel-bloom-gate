# Laravel Bloom Gate

> **Status:** pre-release — repository bootstrap is in progress. No production Bloom Filter lookup API is shipped yet.

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

**M0 — package bootstrap**

M0 establishes package metadata, Laravel package discovery, configuration, Testbench, quality gates, architecture rules, documentation, and CI. It intentionally does not implement Bloom membership operations.

## Safety principles

1. The authoritative datastore remains the source of truth.
2. A positive Bloom result always requires authoritative verification.
3. Only a healthy ACTIVE filter may short-circuit a negative lookup.
4. Infrastructure failure bypasses the optimization instead of changing application correctness.
5. Installing the package must not scan a database, contact Redis, activate filters, or intercept queries.

## Architecture

See [docs/architecture/overview.md](docs/architecture/overview.md) and the accepted decisions in [docs/adr](docs/adr).

## Requirements

Planned v1 baseline:

- PHP 8.3+
- Laravel 12 or 13
- Redis-backed production driver
- Memory-backed test/development reference driver

Support claims are considered official only after automated compatibility evidence exists.

## Roadmap

- **M0:** repository/package bootstrap
- **M1:** core semantic value objects
- **M2:** memory reference driver and contract suite
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
