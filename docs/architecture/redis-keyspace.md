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

M6 implements coordinated online rebuild using a separate synchronization plane;
automated old-generation retention/purge remains deferred.

## M6 coordination keys

All coordination keys share the logical filter's existing hash tag:

```text
<prefix>:{<filter-name>}:sync:owner
<prefix>:{<filter-name>}:sync
<prefix>:{<filter-name>}:sync:staging
<prefix>:{<filter-name>}:sync:leases
<prefix>:{<filter-name>}:sync:counts
```

The immutable owner STRING is `coordinated-v1`. Its presence permanently fences
ordinary control mutation. The `sync-v1` HASH has required fields `format`,
`revision`, `phase`, `current_epoch`, and `current_targets`; `candidate_version`
and `draining_epoch` are absent when null. Unknown fields are corruption.
Strict `control-v1` remains unchanged.

Leases and per-epoch counts are separate HASHes. Lease bindings retain their
original token/epoch/targets; terminal released tombstones are retained. Both
acquired and prepared leases count until release. Correctness keys have no TTL.
Staging residue is never current correctness state.

Atomic scripts fence each control mutation on both revisions, and each sync
mutation on both revisions or required control absence. Acquire linearizes with
epoch rotation; retries preserve bindings and never increment twice. Drivers
validate representation and atomicity; Application owns phase legality.
Same-slot key design continues to make no Redis Cluster runtime-support claim.
