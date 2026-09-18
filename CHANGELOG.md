# Changelog

All notable changes to this project will be documented in this file.

The format follows Keep a Changelog principles and the project uses Semantic Versioning.

## [Unreleased]

### Added

- Initial repository and package bootstrap.
- Laravel package discovery and safe default configuration.
- Testbench, static analysis, formatting, and architecture-test foundation.
- Architecture decision record baseline.
- M1 core semantics: FilterName, FilterVersion, Membership, LifecycleState, HealthState, BypassReason, and NormalizedValue.
- Unit-suite coverage in the fast CI gate and stricter Core dependency-boundary verification.
- M2 Bloom probe protocol with immutable layouts, ordered bit positions, and deterministic golden vectors.
- Backend-neutral `BloomDriver` contract with typed missing-storage and layout failures.
- Reusable driver contract suite included in the fast quality gate.
- Process-local sparse memory reference driver conforming to the shared driver contract.
