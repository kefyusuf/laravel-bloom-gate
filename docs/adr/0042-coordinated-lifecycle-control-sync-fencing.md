# ADR-0042: Coordinated lifecycle ownership and control-sync fencing

**Status:** ACCEPTED

## Context

M6 coordination introduces correctness state that is different from both:

- generation bitmap/metadata storage; and
- the M4 revisioned `control-v1` lifecycle snapshot.

A naïve client-side sequence that reads one plane and later mutates the other leaves a TOCTOU window. Candidate publication, promotion, abort, writer admission, and legacy lifecycle mutation must have one serializable ordering per logical filter.

The design must preserve strict `control-v1`, keep lifecycle policy outside Redis, and remain framework-neutral across Memory and Redis backends.

## Decision

M6 introduces a separate revisioned `sync-v1` synchronization plane plus an immutable coordinated-ownership fence.

### Durable ownership

Each adopted logical filter has an immutable ownership marker equivalent to:

```text
<prefix>:{<filter-name>}:sync:owner
```

Once this marker exists, ordinary M5 control mutation is permanently fenced for that persisted filter history.

Missing mutable `:sync` state after ownership claim is damaged or incomplete coordination, not de-adoption.

The ownership marker is not stored inside strict `control-v1`.

### Separate synchronization plane

The logical `sync-v1` snapshot contains at least:

```text
format = sync-v1
revision
phase
current_epoch
current_targets
candidate_version   optional
draining_epoch      optional
```

The final M6 v1 synchronization phases are:

```text
STEADY
DRAINING_PRE_RECONCILE
RECONCILING
READY_TO_PROMOTE
DRAINING_POST_PROMOTION
ABORT_REQUESTED
DRAINING_ABORT
```

Phase transitions are Application-owned workflow policy. Persistence drivers validate durable shape, revisions, and atomic storage semantics only.

### Framework-neutral coordinated lifecycle store

M6 uses a dedicated framework-neutral coordinated lifecycle persistence boundary capable of:

- atomically reading the current control + ownership + sync relation;
- claiming coordinated ownership;
- guarded control-only mutation;
- guarded sync-only mutation.

A coordinated decision is based on one atomic pair observation rather than independent client-side reads.

### Opposite-revision fencing

After adoption:

- coordinated control mutation requires the expected control revision **and** expected sync revision, then mutates control only;
- coordinated sync mutation requires the expected sync revision **and** expected control revision/required absence, then mutates sync only.

This produces cross-plane serializability.

If concurrent control and sync operations both observed C10/S7:

```text
control wins -> C11/S7 -> stale sync mutation conflicts
sync wins    -> C10/S8 -> stale control mutation conflicts
```

Nullable expected revisions mean required durable absence, never "do not care".

Malformed, contradictory, unavailable, or unexpected opposite-plane state fails closed for lifecycle/write mutation.

### No generic dual-plane durable write

M6 v1 does not introduce a generic primitive that durably rewrites both `:state` and `:sync` in one operation.

Cross-plane workflows use:

```text
guarded single-plane CAS
+ opposite-revision fencing
+ interruption-safe intermediate states
```

This avoids depending on rollback semantics for a multi-key Redis script after a partial durable replacement.

### Exclusive lifecycle ownership

Once adopted, every lifecycle mutation for the filter belongs to the coordinated path.

Ordinary M5 lifecycle mutators and equivalent commands cannot silently modify:

- candidate allocation;
- candidate lifecycle/health;
- verification state;
- candidate discard;
- promotion;
- active-generation deactivation.

Read-only inspection remains allowed.

### Candidate publication and promotion

Candidate publication is a sync-only fenced transition pinned to the control revision that still owns candidate C.

Promotion preserves interruption-safe ordering:

```text
1. control: C becomes ACTIVE, prior A becomes RETIRED, candidate pointer clears
2. sync: rotate A+C targets to C-only targets
```

The reverse ordering is forbidden.

`READY_TO_PROMOTE` explicitly permits both:

```text
pre-promotion:
  control candidate = C
  sync candidate = C

promotion committed / sync rotation pending:
  control active = C, candidate = none
  sync still candidate = C and targets include C
```

A crash between those steps is recoverable and only prolongs safe A+C write amplification.

Another rebuild cannot begin until the post-promotion dual-write epoch drains to zero and sync returns to STEADY.

### Coordinated abort

An unpublished candidate may be retired by coordinated control-only mutation while sync remains STEADY.

A published candidate is not ordinary discard.

Abort is durable and interruption-safe:

```text
request abort
-> ABORT_REQUESTED when an older draining epoch prevents immediate rotation
-> remove C from new writer admission by epoch rotation
-> DRAINING_ABORT
-> drain every old lease that may target C
-> retire C and clear control candidate
-> finalize sync back to STEADY
```

Candidate C is never retired while a conforming active lease may still require it.

Abort and promotion race through opposite-revision fencing.

Once control promotion commits, abort is too late; recovery must finish promotion. M6 does not implicitly roll C back to A.

### Redis persistence

M6 Redis coordination state uses same-filter, same-hash-tag keys equivalent to:

```text
:sync:owner
:sync
:sync:staging
:sync:leases
:sync:counts
```

Properties:

- `:sync` is strict current `sync-v1` state;
- `:sync:staging` is implementation-only and never correctness state;
- `:sync:leases` stores durable A/P/R token bindings;
- `:sync:counts` stores non-negative per-epoch active-writer counts;
- correctness keys have no TTL;
- unknown fields, malformed canonical values, impossible relations, and wrong Redis types are corruption.

Atomic Redis semantic operations use Lua/EVAL and share one logical mutation order per filter.

The Memory reference backend must reproduce the same observable per-filter serialization semantics.

Same-slot construction remains design preparation only; Redis Cluster runtime support is not implied.

## Consequences

- strict `control-v1` remains schema-compatible and separate from coordination;
- stale legacy lifecycle writes cannot bypass adopted ownership;
- control/sync races resolve through revision conflicts instead of timing assumptions;
- every multi-step cross-plane workflow has an explicit safe restart shape;
- promotion cannot contract writer targets before C is durably active;
- abort cannot retire C while old C-targeting writers remain active;
- Memory and Redis share the same correctness ordering model;
- Redis persistence owns atomicity/corruption semantics, not rebuild policy;
- no distributed lock or generic two-plane transaction is required.
