# Architecture Overview

Laravel Bloom Gate is a Laravel-first Composer package with a framework-independent internal core.

```text
Laravel adapters
      |
Application
      |
Contracts / Lifecycle
      |
Core

Drivers -> Contracts
```

Only the `Laravel` namespace may depend on Illuminate.

Core owns deterministic probe generation. Drivers never receive raw application values and do not perform normalization or hashing; they receive a `BloomLayout` at provision time and layout-bound `BitPositions` for add/check operations.

The current storage implementations are:

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

The memory driver is deterministic process-local reference storage. The Redis driver is the production data-plane implementation and uses stock Redis bitmap primitives through atomic Lua/EVAL scripts.

The Redis driver remains framework-neutral. Laravel integration supplies an already-resolved Illuminate Redis connection through `LaravelRedisCommandExecutor`; connection credentials and selection remain under application control.

Bloom generation storage is a data plane. Active version, candidate generation, lifecycle state, health, verification status, rebuild coordination, and fail-open orchestration form a separate control plane and are not implemented by M3.

A negative may short-circuit the authoritative lookup only when later application/lifecycle orchestration establishes that the filter is ACTIVE, HEALTHY, available, and resolved to the current active version.
