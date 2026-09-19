# Redis Foundation

**Milestone:** M3  
**Status:** implemented, reviewed, and merged

M3 adds the production Redis data plane without moving probe generation or lifecycle policy into the backend.

## Boundary

```text
NormalizedValue
      |
      v
BloomProbeGenerator + BloomLayout
      |
      v
BitPositions
      |
      v
RedisBloomDriver
      |
      v
RedisCommandExecutor
      |
      +--> LaravelRedisCommandExecutor --> Illuminate Redis Connection
      |
      +--> test-only RESP executor
      |
      v
stock Redis
```

`RedisBloomDriver` never receives raw values and never performs normalization or hashing.

## Stock Redis, not RedisBloom

The implementation uses Redis STRING bitmaps and Lua/EVAL. It intentionally does not use RedisBloom commands such as `BF.ADD` or `BF.EXISTS`.

Core remains the single owner of Bloom probe generation. Redis only stores and tests the positions supplied by Core.

## Generation storage

Each generation owns:

- a metadata HASH;
- an optional STRING bitmap.

See [redis-keyspace.md](redis-keyspace.md) for exact key and metadata formats.

Metadata existence is the provision marker. A missing bitmap with valid metadata is a valid empty generation; a bitmap without metadata is corruption.

## Atomic operations

Each `BloomDriver` operation executes as one Redis Lua script through `EVAL`:

### provision

- rejects orphan or wrong-type storage as corruption;
- creates metadata when the generation is absent;
- treats equivalent reprovision as success without clearing bits;
- reports a valid different layout as `BloomLayoutConflict`.

### add

- validates storage shape and layout before mutation;
- then sets all supplied positions with `SETBIT`;
- is monotonic and retry-safe.

### mightContain

- validates storage shape and layout before reading membership;
- returns absent if the generation is valid but the bitmap is not yet allocated;
- returns maybe-present only when every supplied position is set.

### destroy

- atomically deletes metadata and bitmap;
- is retry-safe;
- also serves as the explicit recovery primitive for corrupt generation state.

Validation happens before mutation. M3 does not rely on Redis transaction rollback semantics.

## Internal script protocol

The Lua scripts use private integer status codes:

| Code | Internal meaning | Driver mapping |
| ---: | --- | --- |
| 100 | OK | success |
| 101 | membership absent | `false` |
| 102 | membership maybe-present | `true` |
| 200 | not provisioned | `BloomFilterNotProvisioned` |
| 201 | layout conflict | `BloomLayoutConflict` |
| 202 | layout mismatch | `BloomLayoutMismatch` |
| 203 | storage corrupt | `BloomStorageCorrupt` |

These codes are implementation protocol, not public package API.

Unexpected script status values are treated as programming/protocol errors rather than operational Redis failures.

## Failure taxonomy

```text
Redis client/transport operational failure
      |
      v
RedisCommandFailed
      |
      v
BloomDriverOperationFailed

valid Redis response + corrupt generation state
      |
      v
BloomStorageCorrupt

programming/configuration/type error
      |
      v
propagates as programming error
```

Missing storage, layout mismatch, storage corruption, and Redis availability are intentionally distinct.

## Framework-neutral Redis port

`RedisCommandExecutor` exposes one operation:

```php
evaluate(string $script, array $keys, array $arguments): int
```

The Redis driver depends only on this port and Core/Contracts. It does not depend on Illuminate, PhpRedis, or Predis.

## Laravel adapter

`LaravelRedisCommandExecutor` receives an already-resolved `Illuminate\Redis\Connections\Connection`.

It:

- forwards EVAL with exact key count, key order, and argument order;
- accepts only integer EVAL replies;
- normalizes `RedisException` and `Predis\PredisException` to `RedisCommandFailed`;
- preserves non-Redis programming/configuration failures.

The adapter does not select a Laravel Redis connection or read package configuration. Runtime driver selection and service-container orchestration remain deferred.

## Verification evidence

M3 has executable evidence for:

- deterministic Redis key generation;
- Lua protocol constants and validation-before-mutation ordering;
- driver status/argument mapping with a recording executor;
- shared `BloomDriverContractTestCase` against real Redis;
- live corruption fixtures;
- real Testbench → Laravel RedisManager → PhpRedis connection → Redis EVAL;
- Laravel 12 / PHP 8.3 and Laravel 13 / PHP 8.5 compatibility anchors;
- framework dependency boundaries.

The Redis integration suite is isolated behind the `redis` Pest group so the canonical fast quality gate does not require a running Redis server.

## Support-claim boundary

Verified:

- standalone Redis 8 integration anchor;
- PhpRedis-backed Laravel/Testbench EVAL path.

Designed but not runtime-verified:

- Redis Cluster;
- Predis execution.

Predis operational-exception normalization is unit-tested without adding Predis as a production dependency.

## Out of scope

M3 does not implement:

- active/candidate generation resolution;
- lifecycle transitions;
- health-state management;
- fail-open application orchestration;
- rebuild coordination or retention;
- TTL policy;
- Bloom sizing or memory budgeting;
- Eloquent synchronization;
- Redis Cluster runtime verification.
