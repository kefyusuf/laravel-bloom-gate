# Redis Foundation

**M3 data plane:** implemented  
**M4 control plane:** implemented  
**M5 safe query integration:** implemented for the narrow supported Redis profile

Redis storage remains separated into generation data-plane keys and logical-filter control-plane state.

## Data plane

```text
NormalizedValue
      |
      v
BloomProbeGenerator + persisted BloomLayout
      |
      v
BitPositions
      |
      v
RedisBloomDriver / BulkBloomDriver
      |
      v
RedisCommandExecutor / RedisStructuredCommandExecutor
      |
      v
LaravelRedisCommandExecutor
      |
      v
Illuminate Redis Connection
```

The Redis driver uses stock Redis STRING bitmaps and Lua/EVAL.

It does not use RedisBloom and does not normalize application values.

## Generation metadata

Each generation has:

- one `:meta` HASH;
- an optional `:bf` STRING bitmap.

The canonical M3 layout fields remain:

```text
format
bit_count
hash_count
probe_algorithm
```

M5 adds generation semantic fields:

```text
normalization_fingerprint
authoritative_set_fingerprint
consistency_fingerprint
```

Those fields are additive to `redis-bitmap-v1`; M5 does not introduce a new storage format token.

The three semantic fields are all-or-none:

- none -> valid unbound legacy generation;
- all three valid -> bound managed generation;
- partial/invalid semantic metadata -> corruption.

Binding is write-once and layout-checked.

## Managed bitmap loss marker

The managed bulk-write path sets:

```text
managed_bitmap_written = 1
```

before setting the managed batch bits.

This marker distinguishes:

- a valid never-written/empty generation whose bitmap may not exist;
- a managed generation that previously had a non-empty managed write.

If `managed_bitmap_written=1` but the bitmap key is later missing, M5 treats the generation as corrupt/unsafe and bypasses. It is never interpreted as a valid empty Bloom filter.

This is one reason the supported production profile requires `maxmemory-policy=noeviction`.

## Control plane

M4 `RedisFilterControlStore` persists one strict durable:

```text
<prefix>:{<filter-name>}:state
```

and uses a transient same-slot staging key during CAS replacement.

The schema remains strict `control-v1`.

M5 does not place semantic fingerprints in `control-v1`. They belong to generation-scoped data-plane metadata because they describe the exact generation that was built.

## Atomic authorized probe

M5 Redis trusted-negative authorization uses a dedicated atomic structured EVAL.

The Application layer first prepares a query-safety descriptor containing:

- filter name;
- pinned control revision;
- pinned active version;
- exact persisted layout;
- expected semantic contract.

The Redis script then atomically re-checks the current control and generation state before reading bits.

It validates, among other invariants:

- control key shape;
- control revision unchanged;
- active version unchanged;
- active lifecycle is active;
- active health is healthy;
- generation metadata/storage shape;
- exact storage format/layout;
- exact normalization fingerprint;
- exact authoritative-set fingerprint;
- exact consistency fingerprint;
- `managed_bitmap_written` integrity;
- bitmap bits.

Possible semantic outcomes are:

```text
ABSENT
MAYBE
BYPASS <reason>
```

A changed/unsafe snapshot returns BYPASS rather than a trusted negative.

The query path never uses `bloom:doctor` to authorize an individual request.

## Failure model

Infrastructure uncertainty fails open.

Examples that bypass trusted-negative optimization include:

- Redis transport failure;
- changed control revision;
- changed active version;
- missing/unbound semantic contract;
- semantic fingerprint mismatch;
- missing/corrupt generation storage;
- managed bitmap loss;
- unsupported/unasserted trusted-negative profile.

Programming/protocol errors remain loud rather than being silently converted into trusted negatives.

## Supported M5 Redis profile

Trusted Redis negatives require explicit declaration:

```text
standalone-primary-durable-v1
```

The operational profile requires:

- Redis 8;
- standalone topology;
- authoritative primary/master connection;
- AOF enabled;
- `appendfsync = always`;
- `maxmemory-policy = noeviction`.

The hot query path checks the explicit profile declaration and performs correctness-safe atomic probing.

It does **not** execute Redis admin diagnostics on every request.

## `bloom:doctor`

`bloom:doctor` is a separate read-only diagnostics/preflight path.

It observes:

- `INFO server`;
- `INFO replication`;
- `CONFIG GET appendonly`;
- `CONFIG GET appendfsync`;
- `CONFIG GET maxmemory-policy`;
- filter/runtime generation safety.

Missing ACL permission to inspect a required prerequisite is reported as failure, not pass.

Doctor does not change Redis configuration.

A passing doctor report is point-in-time verification. Continuous adherence to the declared profile is an operational contract.

## Support boundary

Verified M5 Redis support:

- Redis 8 standalone;
- primary/master query path;
- PhpRedis-backed Laravel adapter;
- revision-pinned authorized EVAL;
- production diagnostics reads;
- `noeviction` profile expectation.

Not claimed in M5:

- Redis Sentinel runtime support;
- Redis Cluster runtime support;
- replica trusted negatives;
- online dual-write rebuild;
- automatic writer barriers.

Same-filter keys remain Cluster hash-tag compatible by construction, but that is not a Cluster runtime-support claim.
