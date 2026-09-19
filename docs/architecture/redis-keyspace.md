# Redis Keyspace

Redis generation keys are deterministic and Cluster-aware from v1.

## M3 data-plane keys

Each `FilterName + FilterVersion` generation owns two keys:

```text
<prefix>:{<filter-name>}:v:<version>:meta
<prefix>:{<filter-name>}:v:<version>:bf
```

Example:

```text
lbg:{products.sku}:v:1:meta
lbg:{products.sku}:v:1:bf
```

The version is canonical unpadded base-10. `v:1` is valid; `v:000001` is not the canonical M3 encoding.

Both keys use the exact case-sensitive `FilterName` inside the same Redis Cluster hash tag, so one generation's metadata and bitmap are colocated when Redis Cluster is introduced.

The key prefix accepted by `RedisKeyspace` is 1–64 ASCII bytes with this grammar:

```text
[A-Za-z0-9][A-Za-z0-9._-]{0,63}
```

Braces, colons, whitespace, Unicode, and leading punctuation are rejected. Filter names already exclude braces, so application-controlled filter identity cannot alter slot selection.

## Metadata

The `:meta` HASH is the canonical provision marker for a generation:

```text
format           redis-bitmap-v1
bit_count        <canonical positive decimal>
hash_count       <canonical positive decimal>
probe_algorithm  <non-empty algorithm identifier>
```

A provisioned empty generation may have a metadata key while the `:bf` bitmap key does not yet exist. Missing bitmap bytes therefore read as zero only when valid generation metadata exists.

Unknown additive HASH fields are ignored. An unknown storage `format` is corruption. A structurally valid but different probe algorithm is a layout incompatibility, not corruption.

## Bitmap

The `:bf` key is a Redis STRING used with `SETBIT` / `GETBIT`.

Provision does not eagerly extend the bitmap to `bit_count`; allocation grows only as bits are written. No TTL is assigned to generation keys.

## Corruption boundary

Examples classified as storage corruption:

- bitmap exists while metadata is missing;
- metadata key has a non-HASH type;
- bitmap key has a non-STRING type;
- required metadata fields are missing;
- numeric metadata is non-canonical or outside the protocol limits;
- storage format is unknown.

Corruption is never interpreted as membership absence and is never repaired silently. Explicit `destroy → provision` is the current recovery primitive.

## Future control plane

Later milestones may add keys such as:

```text
lbg:{products.sku}:state
lbg:{products.sku}:active
lbg:{products.sku}:candidate
```

Those are control-plane concerns and are not part of M3.

The hash-tag strategy is fixed from v1 so later control-plane keys can share the same logical-filter slot.

Cluster-aware key design does not become an official Redis Cluster runtime-support claim until dedicated Cluster integration tests exist.
