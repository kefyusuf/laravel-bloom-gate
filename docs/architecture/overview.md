# Architecture Overview

Laravel Bloom Gate is Laravel-first at the package edge and framework-independent in its correctness-critical internal layers.

The enforced dependency direction is:

```text
Core        -> PHP/SPL only
Contracts   -> Core
Lifecycle   -> Core + Contracts
Drivers     -> Core + Contracts
Application -> Core + Contracts + Lifecycle
Laravel     -> internal package layers + Illuminate
```

Only the `Laravel` namespace may depend on Illuminate.

## Core and data plane

Core owns deterministic normalization-independent Bloom probe semantics:

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
BloomDriver / BulkBloomDriver
```

Drivers never receive raw application values and never define business normalization.

The production Redis driver uses stock Redis bitmap primitives and Lua/EVAL. The Memory driver remains the deterministic reference implementation.

## Control plane

The M4 control plane remains the owner of current logical-filter lifecycle state:

- revision;
- monotonic generation allocation;
- active/candidate pointers;
- generation lifecycle;
- generation health.

`FilterControlStore` uses compare-and-swap semantics. Lifecycle policy remains outside persistence drivers.

Lifecycle and health remain independent axes.

## M5 semantic contract

M5 adds generation-scoped semantic compatibility on top of the M3 data plane and M4 control plane.

A managed generation binds:

```text
normalization fingerprint
authoritative-set fingerprint
consistency fingerprint
```

The semantic contract is immutable for one `FilterName + FilterVersion`.

Bindings are write-once:

- same-value rebind is idempotent;
- different-value rebind is a conflict.

The fingerprint inputs are explicit stable semantic identities. They are not derived from PHP class names, closures, object hashes, source files, or arbitrary Laravel config serialization.

Existing provisioned M3 generations with none of the M5 semantic fields remain valid low-level Bloom generations. They are treated as **unbound** and cannot authorize M5 query skipping.

## Explicit filter definitions

Laravel configuration resolves a framework-neutral `FilterDefinition`.

A definition exposes:

- one `ValueNormalizer`;
- one `AuthoritativeSet`;
- one `ConsistencyContract`.

Managed configuration supplies capacity and target false-positive rate. `OptimalBloomSizingV1` derives the managed layout deterministically.

## Managed lifecycle workflow

The M5 build path is explicit:

```text
allocate candidate
    ->
BUILDING
    ->
provision exact derived layout
    ->
bind semantic contract
    ->
stream authoritative present values
    ->
normalize + managed bulk add
    ->
HEALTHY + SHADOW
```

Build does not verify or activate.

Normal managed verification requires the current `SHADOW + HEALTHY` candidate. Passed evidence transitions the same candidate to `VERIFIED`; a detected false negative prevents promotion.

Activation always performs **fresh verification** before promotion.

For `preadd-v1`, activation additionally requires an explicit quiescent membership-entry window and performs full reconciliation before that fresh verification.

Promotion remains control-plane-only.

## Query safety

The package-facing query path is:

```text
raw value
   |
   v
FilterDefinition
   |
   +--> normalize exactly once
   |
   +--> derive runtime semantic contract
   |
   v
QuerySafetyDescriptorResolver
   |
   +--> bypass -> authoritative lookup
   |
   v
revision/layout/semantic-pinned descriptor
   |
   v
AuthorizedProbe
   |
   +--> DEFINITELY_ABSENT -> exists = false
   |
   +--> MAYBE_PRESENT    -> authoritative lookup
   |
   +--> BYPASSED         -> authoritative lookup
```

`QueryGate::exists()` is authoritative-correct.

A Bloom positive is never truth. A bypass is not an error result; it means the optimization was not safe to use.

## ACTIVE + HEALTHY is not enough

M4 `ACTIVE + HEALTHY` means the control plane has an eligible active generation.

M5 additionally requires:

- exact active revision/version state;
- exact persisted layout;
- bound generation semantics;
- exact normalization fingerprint equality;
- exact authoritative-set fingerprint equality;
- exact consistency fingerprint equality;
- safe backend profile requirements;
- an authorized probe that observes a stable correctness snapshot.

Only after all of those conditions can a Bloom negative authorize skipping the authoritative lookup.

## Redis authorized probe

Redis query authorization is one atomic Lua/EVAL operation over same-filter keys.

The script validates the pinned control revision, active version/lifecycle/health, generation storage/layout, semantic fingerprints, and managed bitmap state before reading Bloom bits.

If any observed state differs from the descriptor that the Application layer prepared, the result is BYPASS rather than a trusted negative.

The query hot path does not call `bloom:doctor` or Redis admin diagnostics.

## Laravel layer

Laravel provides thin adapters:

- config-backed registry and definition resolution;
- Redis connection adapters;
- `BloomGateManager` and `BloomGate` facade;
- `BloomUnique` / `BloomExists` validation rules;
- lifecycle/status/doctor Artisan commands.

Correctness logic stays in Core/Contracts/Lifecycle/Application/Drivers.

## Production diagnostics

`bloom:doctor` is a read-only preflight.

For the M5 Redis trusted-negative profile it can inspect:

- Redis reachability;
- version;
- standalone topology;
- primary/master role;
- AOF;
- appendfsync;
- maxmemory policy;
- registered filter/runtime generation safety.

A successful doctor run is point-in-time evidence, not a daemon or lease. The operator remains responsible for keeping the declared production profile true.

## M5 boundary

M5 intentionally does not implement or claim:

- Redis Sentinel runtime support;
- Redis Cluster runtime support;
- online dual-write rebuild;
- writer barriers;
- CDC/outbox-based synchronization;
- background continuous profile attestation;
- observer-based trusted-negative authority.

Those concerns require separate later scope.
