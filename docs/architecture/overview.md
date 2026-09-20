# Architecture Overview

Laravel Bloom Gate is a Laravel-first Composer package with a framework-independent internal core.

The current internal dependency direction is:

```text
Core        -> PHP/SPL only
Contracts   -> Core
Lifecycle   -> Core + Contracts
Drivers     -> Core + Contracts
Application -> Core + Contracts + Lifecycle
Laravel     -> internal package layers + Illuminate
```

Only the `Laravel` namespace may depend on Illuminate.

## Data plane

Core owns deterministic probe generation.

Drivers never receive raw application values and do not perform normalization or hashing. They receive a `BloomLayout` at provision time and layout-bound `BitPositions` for add/check operations.

The Bloom data-plane implementations are:

```text
                    BloomDriver
                    /         \
                   /           \
        MemoryBloomDriver   RedisBloomDriver
                                  |
                                  v
                       RedisCommandExecutor
                           ^             ^
                           |             |
                 test RESP executor   Laravel adapter
                                         |
                                         v
                           Illuminate Redis Connection
```

The memory driver is deterministic process-local reference storage.

The Redis driver uses stock Redis bitmap primitives and atomic Lua/EVAL scripts. It remains framework-neutral.

The raw M3 driver primitive:

```text
destroy -> provision
```

remains available for low-level storage recovery and direct driver use.

For M4-managed generations, however, rebuilds are versioned. A managed generation is not destructively rebuilt or reused in place.

## Control plane

M4 adds a separate logical-filter control plane.

```text
                         FilterControlStore
                         /                \
                        /                  \
       MemoryFilterControlStore      RedisFilterControlStore
                                             |
                                             v
                              RedisStructuredCommandExecutor
                                      ^              ^
                                      |              |
                            test RESP executor   Laravel adapter
```

The control plane tracks:

- current state revision;
- `lastAllocatedVersion`;
- optional active generation;
- optional candidate generation;
- generation lifecycle;
- generation health.

The current snapshot is correctness state, not an audit log.

Control-plane writes use compare-and-swap semantics. Memory and Redis implementations conform to the same `FilterControlStore` contract.

## Lifecycle and verification

M4 lifecycle and health are independent axes.

The generic lifecycle policy intentionally excludes:

```text
SHADOW   -> VERIFIED
VERIFIED -> ACTIVE
```

Those transitions belong exclusively to:

- version-bound activation verification evidence;
- explicit candidate promotion.

Verification accepts `iterable<NormalizedValue>`, detects the first operational false negative, and never normalizes raw values.

Promotion requires the current candidate to be `VERIFIED + HEALTHY` and changes only the control plane. It does not move or rewrite Bloom data.

## Probe eligibility versus query-skip authorization

The M4 `ActiveGenerationPolicy` returns a version only when the current active pointer resolves to:

```text
ACTIVE + HEALTHY
```

That result means **control-plane probe eligibility only**.

M4 does not implement package-facing authoritative-query orchestration or final authorization to skip an authoritative lookup.

The package may safely expose negative-result query skipping only after later orchestration also establishes every required runtime invariant.

Runtime normalization identity/fingerprint compatibility remains a mandatory M5 design blocker.

## Redis boundary

The Redis generation data plane uses the original integer `RedisCommandExecutor`.

M4 adds the additive `RedisStructuredCommandExecutor` child contract for structured Lua replies required by control-plane persistence. The original integer executor contract remains source-compatible.

Laravel supplies an already-resolved Illuminate Redis connection through `LaravelRedisCommandExecutor`. Connection credentials and selection remain application-owned.

## Architecture enforcement

Executable tests enforce:

- Core has no framework/infrastructure knowledge;
- Drivers do not depend on Lifecycle;
- Lifecycle does not depend on Drivers;
- non-Laravel layers do not import Illuminate;
- Core lifecycle/control-state objects do not contain Redis or persistence tokens.
