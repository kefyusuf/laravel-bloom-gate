# Redis Foundation

**M3 data plane:** implemented and retained  
**M4 control plane:** implemented

The Redis architecture keeps Bloom data-plane storage separate from logical-filter lifecycle/control state.

## Data-plane boundary

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

### Stock Redis, not RedisBloom

The implementation uses Redis STRING bitmaps and Lua/EVAL. It intentionally does not use RedisBloom commands such as `BF.ADD` or `BF.EXISTS`.

Core remains the single owner of Bloom probe generation.

### Generation storage

Each generation owns:

- a metadata HASH;
- an optional STRING bitmap.

See [redis-keyspace.md](redis-keyspace.md) for exact formats.

Metadata existence is the provision marker. A missing bitmap with valid metadata is a valid empty generation; a bitmap without metadata is corruption.

### Atomic Bloom operations

Each Bloom driver operation executes as one Redis Lua script:

#### provision

- rejects orphan or wrong-type storage as corruption;
- creates metadata when absent;
- treats equivalent reprovision as success without clearing bits;
- reports a valid different layout as `BloomLayoutConflict`.

#### add

- validates storage and layout before mutation;
- sets supplied positions with `SETBIT`;
- is monotonic and retry-safe.

#### mightContain

- validates storage and layout before membership reads;
- returns absent for a valid empty generation;
- returns maybe-present only when every supplied position is set.

#### destroy

- atomically deletes generation metadata and bitmap;
- is retry-safe;
- remains the raw explicit recovery primitive for low-level generation storage.

The raw `destroy -> provision` capability remains part of the M3 driver surface.

M4-managed generations must not use that primitive to destructively rebuild/reuse a managed version in place. M4 does not implement rebuild scheduling/orchestration; any managed replacement must use a newly allocated generation version.

## M3 private script protocol

The Bloom data-plane scripts use private integer status codes:

| Code | Internal meaning | Driver mapping |
| ---: | --- | --- |
| 100 | OK | success |
| 101 | membership absent | `false` |
| 102 | membership maybe-present | `true` |
| 200 | not provisioned | `BloomFilterNotProvisioned` |
| 201 | layout conflict | `BloomLayoutConflict` |
| 202 | layout mismatch | `BloomLayoutMismatch` |
| 203 | storage corrupt | `BloomStorageCorrupt` |

These are implementation protocol, not public API.

## Redis command ports

The original data-plane port remains:

```php
RedisCommandExecutor::evaluate(
    string $script,
    array $keys,
    array $arguments,
): int
```

M4 adds an additive child contract:

```php
RedisStructuredCommandExecutor::evaluateStructured(
    string $script,
    array $keys,
    array $arguments,
): array
```

Its semantic return type is a strict `list<string>`.

The original integer executor contract is unchanged.

`LaravelRedisCommandExecutor` implements both behaviors and keeps Redis client/transport exception normalization at the Laravel adapter boundary.

## M4 Redis control plane

`RedisFilterControlStore` implements the same `FilterControlStore` contract as the Memory reference store.

It stores one strict durable `control-v1` HASH per logical filter:

```text
<prefix>:{<filter-name>}:state
```

CAS replacement additionally uses a transient same-slot staging HASH:

```text
<prefix>:{<filter-name>}:state:staging
```

### Atomic control CAS

Control reads and compare-and-swap writes execute through Lua/EVAL.

CAS validates, in order:

1. Redis key type;
2. existing strict `control-v1` state;
3. expected revision/conflict;
4. proposed strict `control-v1` state;
5. proposed revision progression;
6. clears only the transient staging key;
7. materializes the complete replacement into staging using bounded HSET chunks;
8. atomically replaces the durable state with `RENAME staging -> state`.

Semantic conflict/corruption/revision failures occur before staging mutation.

The current durable `:state` key is never deleted before a complete replacement exists. Staging HSET/RENAME errors are handled through Redis `pcall`; staging is cleaned and the previous durable correctness snapshot remains intact.

Private control-script statuses:

| Code | Internal meaning | Store mapping |
| ---: | --- | --- |
| 100 | OK | success |
| 200 | revision conflict | `FilterControlWriteConflict` |
| 201 | storage corrupt | `FilterControlStateCorrupt` |
| 202 | invalid revision progression | `InvalidArgumentException` |

These codes are also private implementation protocol.

The control scripts know storage shape and CAS semantics only. They do not encode lifecycle transition legality.

They touch only the control-plane `:state` and `:state:staging` keys and never mutate generation `:meta` / `:bf` keys.

No TTL is assigned to the durable control HASH. The staging key is transient and is consumed on success or explicitly cleaned on handled write/rename failure.

## Failure taxonomy

```text
Redis client/transport operational failure
      |
      v
RedisCommandFailed
      |
      +--> BloomDriverOperationFailed
      |
      +--> FilterControlStoreOperationFailed

valid Redis response + corrupt generation state
      |
      v
BloomStorageCorrupt

valid Redis response + corrupt control state
      |
      v
FilterControlStateCorrupt

stale control writer
      |
      v
FilterControlWriteConflict
```

Missing state, storage corruption, revision conflict, and Redis availability remain distinct.

## Verification evidence

Executable evidence includes:

- deterministic generation and control key construction;
- Bloom Lua validation-before-mutation ordering;
- strict `control-v1` codec tests;
- control Lua validation-before-mutation ordering;
- real Redis `BloomDriverContractTestCase`;
- real Redis `FilterControlStoreContractTestCase`;
- real two-writer CAS conflict proving the loser cannot overwrite the winner;
- live wrong-type/malformed/unknown-field/unknown-format control corruption;
- live 5,000-generation replacement proving bounded staged writes avoid the prior unbounded-unpack failure mode;
- no-TTL checks after control create/update;
- real Testbench + PhpRedis structured/integer EVAL execution;
- Laravel 12 / PHP 8.3 and Laravel 13 / PHP 8.5 compatibility anchors;
- executable architecture boundaries.

The Redis integration suite remains isolated behind the `redis` Pest group.

## Support-claim boundary

Verified:

- standalone Redis 8 integration anchor;
- PhpRedis-backed Laravel/Testbench EVAL path;
- real control-store concurrency and corruption semantics.

Designed but not runtime-verified:

- Redis Cluster;
- Predis execution.

Same-filter keys are hash-tag compatible by construction, but that does not constitute a Redis Cluster runtime-support claim.

Predis operational-exception normalization is unit-tested without adding Predis as a production dependency.

## Out of scope

M4 Redis work does not implement:

- package-facing query interception;
- authoritative database lookup orchestration;
- runtime normalization identity/fingerprint verification;
- automatic rebuild scheduling;
- retention/purge policy;
- rollback workflow;
- background health monitoring;
- Redis Cluster runtime verification.
