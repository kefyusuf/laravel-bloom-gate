# Architecture Overview

Laravel Bloom Gate is a Laravel-first Composer package with a framework-independent internal core.

```text
Laravel adapter
      |
Application
      |
Contracts / Lifecycle
      |
Core

Drivers -> Contracts
```

Only the `Laravel` namespace may depend on Illuminate. Production v1 is Redis-backed; the memory driver exists for deterministic development and contract testing.

Core owns deterministic probe generation. Drivers never receive raw application values and do not perform normalization or hashing; they receive a `BloomLayout` at provision time and layout-bound `BitPositions` for add/check operations. The memory driver implements the same storage semantics expected from future production drivers without emulating Redis commands.

Bloom data is a data plane. Active version, lifecycle, health, and verification metadata form a separate control plane.

A negative may short-circuit the authoritative lookup only when the filter is ACTIVE, HEALTHY, available, and resolved to the current active version.
