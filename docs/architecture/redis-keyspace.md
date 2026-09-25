# Redis Keyspace

Redis keys are deterministic and use a same-filter hash tag from v1.

Redis Cluster runtime support is **not** an M5 support claim.

## Logical-filter hash tag

All keys for one logical filter use the exact case-sensitive `FilterName` inside:

```text
{<filter-name>}
```

Example:

```text
{products.sku}
```

Filter names exclude braces, so application-controlled identity cannot change hash-tag boundaries.

## Generation keys

Each `FilterName + FilterVersion` owns:

```text
<prefix>:{<filter-name>}:v:<version>:meta
<prefix>:{<filter-name>}:v:<version>:bf
```

Example:

```text
lbg:{products.sku}:v:1:meta
lbg:{products.sku}:v:1:bf
```

The version is canonical unpadded base-10.

## `:meta` generation HASH

The canonical provision/layout fields remain:

```text
format           redis-bitmap-v1
bit_count        <canonical positive decimal>
hash_count       <canonical positive decimal>
probe_algorithm  <algorithm identifier>
```

M5 may additionally bind:

```text
normalization_fingerprint
authoritative_set_fingerprint
consistency_fingerprint
```

Each fingerprint uses:

```text
sha256:<64 lowercase hex>
```

The three semantic fields are an all-or-none generation contract.

### Legacy/unbound generation

If none of the semantic fields exists, the generation can still be valid M3 low-level Bloom storage.

M5 treats it as unbound and query-skip-ineligible.

This state is not automatically corruption.

### Bound generation

If all three semantic fields exist and are canonical, they are immutable for that generation.

Binding the same values again is idempotent.

Attempting to bind different semantic fingerprints conflicts.

A partial semantic binding is corruption rather than a valid migration state.

## Managed bitmap marker

Managed non-empty bulk writes set:

```text
managed_bitmap_written  1
```

The marker is optional before any managed bitmap write.

If it is present it must equal `1`.

If it equals `1` but the `:bf` key is missing, managed storage is unsafe/corrupt and cannot authorize a negative.

The marker prevents data loss from masquerading as an empty Bloom generation.

## Bitmap key

The `:bf` key is a Redis STRING used with `SETBIT` / `GETBIT`.

Provision does not eagerly allocate a full bitmap.

For a genuinely empty/never-written valid generation, the bitmap may be absent.

No TTL is assigned by the package to managed generation keys.

## Generation corruption boundary

Examples include:

- bitmap exists while metadata is missing;
- metadata is not a HASH;
- bitmap is not a STRING;
- required layout fields are missing/malformed;
- storage format is unknown;
- partial/invalid M5 semantic fields;
- invalid `managed_bitmap_written`;
- `managed_bitmap_written=1` while bitmap storage is missing.

Corruption is never interpreted as membership absence.

## Control-plane keys

Each logical filter owns one durable current correctness-state key:

```text
<prefix>:{<filter-name>}:state
```

CAS replacement uses:

```text
<prefix>:{<filter-name>}:state:staging
```

The staging key is implementation-only and is not read as correctness state.

Control and generation keys share the same filter hash tag:

```text
lbg:{products.sku}:state
lbg:{products.sku}:state:staging
lbg:{products.sku}:v:1:meta
lbg:{products.sku}:v:1:bf
```

## `control-v1`

Required top-level fields:

```text
format                  control-v1
revision
last_allocated_version
```

Optional pointers are absent when null:

```text
active_version
candidate_version
```

Tracked generations:

```text
g:<version>:lifecycle
g:<version>:health
```

Unlike additive generation metadata, `control-v1` is strict. Unknown fields are corruption.

M5 semantic fingerprints are deliberately **not** stored in `control-v1`; they are immutable properties of a concrete generation and therefore live in generation `:meta`.

## Prefix grammar

Accepted prefix:

```text
[A-Za-z0-9][A-Za-z0-9._-]{0,63}
```

Braces, colons, whitespace, Unicode, and leading punctuation are rejected.

## No TTL correctness model

The package does not use TTL expiration as a lifecycle mechanism for:

- generation metadata;
- managed bitmap storage;
- durable control state.

Managed version ownership changes through explicit lifecycle operations.

## Recovery versus managed rebuild

The raw M3 driver still exposes low-level:

```text
destroy -> provision
```

That is not the M5 managed rebuild model.

A managed replacement uses a new generation version.

Online dual-write rebuild and automated old-generation retention/purge are deferred beyond M5.
