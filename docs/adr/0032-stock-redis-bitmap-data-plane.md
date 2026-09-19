# ADR-0032: Stock Redis bitmap data plane

**Status:** ACCEPTED

## Context

Core already owns normalization, Bloom layout, and deterministic probe generation. A production Redis backend must preserve that ownership and the backend-neutral `BloomDriver` semantics.

Delegating membership behavior to RedisBloom would introduce a second hashing/probe implementation and would make the Redis backend semantically different from the memory reference driver.

## Decision

M3 uses stock Redis primitives for the Bloom data plane.

Each `FilterName + FilterVersion` generation has:

- one metadata HASH;
- one optional STRING bitmap.

Core supplies `BitPositions`; Redis stores and reads those positions with bitmap commands. RedisBloom module commands are not part of the implementation.

Metadata existence is the canonical provision marker. A valid metadata key with no bitmap is a provisioned empty generation. A bitmap without metadata, wrong Redis types, malformed required metadata, or unknown storage format is corruption.

Generation keys have no TTL and provision does not eagerly allocate the entire bitmap.

## Consequences

- Core remains the single owner of Bloom algorithm semantics.
- Memory and Redis drivers can share the same conformance contract.
- Empty provision does not require large eager Redis allocation.
- Generation disappearance or corruption cannot silently become a membership negative.
- RedisBloom availability is not a package requirement.
- Lifecycle/control-plane state remains separate from generation storage.
