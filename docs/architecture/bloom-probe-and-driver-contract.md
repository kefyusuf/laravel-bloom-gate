# Bloom Probe and Driver Contract

**Milestone:** M2  
**Status:** implemented

M2 separates deterministic Bloom probe generation from backend storage behavior.

## Boundary

The flow is:

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
BloomDriver
      |
      +--> MemoryBloomDriver
      |
      +--> future production drivers
```

Core owns probe generation. A driver does not receive a raw application value, does not normalize values, and does not choose a hash algorithm.

## BloomLayout

A `BloomLayout` contains:

- `bitCount` (`m`);
- `hashCount` (`k`);
- `ProbeAlgorithm`.

The implemented invariants are:

- `bitCount >= 1`;
- `hashCount >= 1`;
- `hashCount <= bitCount`;
- `hashCount <= 64`;
- `sha256-double-hash-v1` requires `bitCount <= 2,147,483,647`.

The v1 bit-count ceiling is a probe-protocol limit, not a Redis limit. It keeps the bit space within the range that the algorithm's positive 31-bit seeds can address uniformly. A future probe algorithm identifier may define a larger supported range.

A layout is part of the filter generation contract. A layout or probe-algorithm change requires a new filter version and rebuild rather than mutating an existing generation in place.

## Probe algorithm v1

The first algorithm identifier is:

```text
sha256-double-hash-v1
```

For a `NormalizedValue`, Core hashes these exact bytes:

```text
"laravel-bloom-gate\0probe\0v1\0" || normalized-value-bytes
```

using SHA-256 with raw binary output.

The first two 32-bit big-endian words are read from digest bytes `0..3` and `4..7`. Their high bits are masked so both seeds are positive 31-bit integers.

Probe generation dispatches explicitly by `ProbeAlgorithm`; adding a future algorithm identity requires an explicit generator implementation rather than silently reusing v1 behavior.

For `m > 1`:

```text
start = h1 mod m
step  = 1 + (h2 mod (m - 1))

p0 = start
pi = (p(i-1) + step) mod m
```

The modular addition is implemented without integer overflow. For the valid single-bit layout `m=1, k=1`, the only position is `0`.

Positions are ordered and duplicates are preserved.

## Golden vectors

The protocol is pinned by executable golden tests for `m=1024` and `k=7`:

| Input bytes | Positions |
| --- | --- |
| empty | `[966, 363, 784, 181, 602, 1023, 420]` |
| `ABC-001` | `[52, 476, 900, 300, 724, 124, 548]` |
| UTF-8 `ürün-ç` | `[336, 51, 790, 505, 220, 959, 674]` |
| `abc\0def` | `[218, 933, 624, 315, 6, 721, 412]` |

These vectors make byte order, domain separation, seed extraction, and probe ordering observable protocol behavior.

## BitPositions

`BitPositions` is immutable and bound to the `BloomLayout` used to produce it.

It verifies:

- ordered list input;
- exactly `k` positions;
- integer positions only;
- every position is in `[0, m)`.

A storage driver must reject positions generated for a layout different from the provisioned layout.

## BloomDriver

The backend-neutral contract is:

```php
provision(FilterName $name, FilterVersion $version, BloomLayout $layout): void
add(FilterName $name, FilterVersion $version, BitPositions $positions): void
mightContain(FilterName $name, FilterVersion $version, BitPositions $positions): bool
destroy(FilterName $name, FilterVersion $version): void
```

Storage identity is the pair `FilterName + FilterVersion`.

### Required semantics

- `provision` creates empty storage.
- Repeating `provision` with the same layout is safe and must not clear existing bits.
- Re-provisioning the same identity with a different layout fails with `BloomLayoutConflict`.
- `add` monotonically sets all supplied positions.
- Repeating the same `add` is safe.
- `mightContain` returns `true` only when every supplied position is set.
- Missing storage is not interpreted as absence; `add` and `mightContain` fail with `BloomFilterNotProvisioned`.
- Layout mismatch fails with `BloomLayoutMismatch`.
- `destroy` removes the generation and is retry-safe.

The shared contract suite is the executable definition of these backend semantics.

## Memory reference driver

`MemoryBloomDriver` is process-local, ephemeral reference storage.

It uses a sparse logical bitset:

```text
FilterName
  -> FilterVersion
      -> {
           layout,
           bits: array<int, true>
         }
```

It intentionally does not allocate an array of size `bitCount`, emulate Redis commands, provide persistence, or provide cross-process sharing.

Its role is to provide deterministic development behavior and to prove the reusable driver contract before a production Redis driver exists.

## Out of scope

M2 does not implement:

- Redis commands or keyspace execution;
- lifecycle state transitions;
- active-version resolution;
- health policy;
- fail-open application orchestration;
- Eloquent synchronization;
- Bloom sizing policy.
