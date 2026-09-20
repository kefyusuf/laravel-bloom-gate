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
- M3 stock-Redis bitmap data plane with Cluster-aware generation keys and versioned metadata.
- Atomic Redis Lua/EVAL protocol for provision, add, membership checks, and destroy.
- Typed Bloom storage-corruption and Redis operational-failure boundaries.
- `RedisBloomDriver` conforming to the shared driver contract against real Redis.
- Live Redis corruption fixtures covering orphan/wrong-type/malformed generation state and explicit destroy recovery.
- Framework-neutral `RedisCommandExecutor` contract plus Laravel-only `LaravelRedisCommandExecutor`.
- Real Testbench + PhpRedis + Redis integer EVAL integration evidence.
- Dedicated Redis test group and cost-aware Redis integration workflow.
- M4 revisioned `FilterControlState` with generation-scoped lifecycle/health, active/candidate pointers, and monotonic `lastAllocatedVersion`.
- Framework-neutral `FilterControlStore` contract with Memory reference and atomic Redis implementations.
- Typed control-store conflict, operational-failure, and corruption boundaries.
- Explicit lifecycle transition policy with verification-only `SHADOW -> VERIFIED` and promotion-only `VERIFIED -> ACTIVE`.
- Streaming activation verification over `iterable<NormalizedValue>` with version-bound evidence and false-negative detection.
- Explicit candidate allocation, promotion, deactivation, and `ACTIVE + HEALTHY` probe-eligibility policy.
- Additive `RedisStructuredCommandExecutor` contract while preserving the original integer Redis executor API.
- Strict `control-v1` Redis control-state codec and same-filter `:state` keyspace.
- Atomic Redis control-state Lua CAS with conflict/corruption/invalid-revision separation.
- Live Redis shared control-store contract, two-writer winner-preservation evidence, corruption fixtures, and no-TTL evidence.
- Executable M4 architecture boundaries covering layer direction, Illuminate isolation, and Core persistence agnosticism.
- ADR-0034 revisioned lifecycle control plane, ADR-0035 candidate verification/explicit promotion, and ADR-0036 Redis control-plane persistence.

### Fixed

- Redis control-store CAS precedence now resolves missing/stale expected-revision conflicts before proposed revision-progression validation, matching the Memory reference contract.
- Redis control-state replacement now stages bounded HASH writes and swaps them into place only after full materialization, preventing large-snapshot Lua `unpack` failures from deleting the previous correctness snapshot.
