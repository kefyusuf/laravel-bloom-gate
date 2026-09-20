# Redis Keyspace

Redis keys are deterministic and Cluster-aware by construction from v1.

Redis Cluster runtime support is **not** an official package claim until dedicated Cluster integration evidence exists.

## Logical-filter hash tag

All keys for one logical filter use the exact case-sensitive `FilterName` inside the same Redis hash tag:

```text
{<filter-name>}
```

Example:

```text
{products.sku}
```

Filter names exclude braces, so application-controlled filter identity cannot alter hash-tag boundaries.

## M3 generation data-plane keys

Each `FilterName + FilterVersion` generation owns:

```text
<prefix>:{<filter-name>}:v:<version>:meta
<prefix>:{<filter-name>}:v:<version>:bf
```

Example:

```text
lbg:{products.sku}:v:1:meta
lbg:{products.sku}:v:1:bf
```

The version is canonical unpadded base-10. `v:1` is valid; `v:000001` is not canonical.

### Generation metadata

The `:meta` HASH is the canonical provision marker:

```text
format           redis-bitmap-v1
bit_count        <canonical positive decimal>
hash_count       <canonical positive decimal>
probe_algorithm  <non-empty algorithm identifier>
```

A valid metadata key with no `:bf` key is a provisioned empty generation.

Unknown additive generation-metadata HASH fields are ignored. An unknown generation storage `format` is corruption. A structurally valid but different probe algorithm is a layout incompatibility, not corruption.

### Generation bitmap

The `:bf` key is a Redis STRING used with `SETBIT` / `GETBIT`.

Provision does not eagerly allocate the complete bitmap. No TTL is assigned to generation keys.

### Generation corruption boundary

Examples classified as Bloom storage corruption:

- bitmap exists while metadata is missing;
- metadata key has a non-HASH type;
- bitmap key has a non-STRING type;
- required metadata fields are missing;
- numeric metadata is non-canonical or outside protocol limits;
- generation storage format is unknown.

Corruption is never interpreted as membership absence and is never repaired silently.

The raw M3 recovery primitive remains:

```text
destroy -> provision
```

That low-level primitive does not authorize destructive in-place rebuild of an M4-managed generation. M4 does not implement rebuild scheduling/orchestration; any managed replacement must use a newly allocated generation version.

## M4 control-plane keys

Each logical filter owns one **durable current correctness-state** key:

```text
<prefix>:{<filter-name>}:state
```

Example:

```text
lbg:{products.sku}:state
```

Redis CAS also uses one implementation-only staging key while materializing a replacement snapshot:

```text
<prefix>:{<filter-name>}:state:staging
```

The staging key is not a second source of truth. It exists only during a CAS replacement, is written completely before the durable state is replaced, and is consumed by `RENAME` on success.

Both control keys share the same logical-filter hash tag as the generation keys:

```text
lbg:{products.sku}:state
lbg:{products.sku}:state:staging
lbg:{products.sku}:v:1:meta
lbg:{products.sku}:v:1:bf
```

No separate `:active` or `:candidate` Redis key exists in M4. Those pointers remain fields inside the single durable revisioned control HASH.

## control-v1

The `:state` HASH uses strict `control-v1` persistence.

Required top-level fields:

```text
format                  control-v1
revision                <canonical positive decimal>
last_allocated_version  <canonical positive decimal>
```

Optional pointers are absent when null:

```text
active_version
candidate_version
```

Tracked generation fields use:

```text
g:<version>:lifecycle
g:<version>:health
```

Lifecycle tokens:

```text
configured
building
shadow
verified
active
retired
```

Health tokens:

```text
healthy
degraded
stale
unavailable
```

Unlike M3 generation metadata, `control-v1` is strict: unknown control fields are corruption rather than ignored forward-compatible metadata.

The codec also rejects:

- duplicate fields;
- malformed generation field names;
- incomplete lifecycle/health pairs;
- non-canonical or out-of-range integers;
- unknown lifecycle/health tokens;
- decoded snapshots that violate Core control-state invariants.

The durable control state has no TTL. The package also assigns no TTL to the transient staging key; successful CAS consumes it with `RENAME`, while handled staging-write/rename failures explicitly delete it.

## Prefix grammar

The key prefix accepted by `RedisKeyspace` is 1–64 ASCII bytes:

```text
[A-Za-z0-9][A-Za-z0-9._-]{0,63}
```

Braces, colons, whitespace, Unicode, and leading punctuation are rejected.

## Control state is not history

The `:state` HASH represents current correctness state.

It is not an audit log and does not retain every historical snapshot.

M4 keeps retired generation records in the current snapshot. Future pruning may remove retired records, but `last_allocated_version` must remain monotonic so version numbers are never reused.
