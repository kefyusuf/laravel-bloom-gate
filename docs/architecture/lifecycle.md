# Filter Lifecycle

M4 implements the framework-neutral lifecycle and verification control plane around the existing Bloom data plane.

Lifecycle and operational health are independent generation-scoped axes.

## Lifecycle states

```text
CONFIGURED
BUILDING
SHADOW
VERIFIED
ACTIVE
RETIRED
```

The generic lifecycle transition policy exposes only:

```text
CONFIGURED -> BUILDING
CONFIGURED -> RETIRED

BUILDING   -> SHADOW
BUILDING   -> RETIRED

SHADOW     -> RETIRED

VERIFIED   -> RETIRED

ACTIVE     -> RETIRED
```

Two transitions are intentionally excluded from the generic transition API:

```text
SHADOW   -> VERIFIED   verification evidence only
VERIFIED -> ACTIVE     explicit promotion only
```

`RETIRED` has no outgoing transition in the M4 policy.

## Operational health

```text
HEALTHY
DEGRADED
STALE
UNAVAILABLE
```

Lifecycle mutation never changes health implicitly. A generation can therefore be ACTIVE while DEGRADED, STALE, or UNAVAILABLE.

## Logical-filter control state

One logical filter has a revisioned current snapshot containing:

- the exact `FilterName`;
- a monotonically increasing `FilterStateRevision`;
- `lastAllocatedVersion`;
- at most one active generation pointer;
- at most one candidate generation pointer;
- tracked generation lifecycle and health state.

Generation versions are allocated monotonically and are never reused. Gaps are valid.

The control snapshot is **current correctness state**, not an event stream or audit log.

M4 retains retired generation records in the current snapshot. Future pruning may remove retired records, but it must preserve `lastAllocatedVersion` so version allocation can never reuse an old generation number.

## Candidate allocation

The first candidate for an empty logical filter is version 1.

Later candidates use:

```text
lastAllocatedVersion + 1
```

A newly allocated candidate starts as:

```text
CONFIGURED + UNAVAILABLE
```

A second candidate cannot be allocated while another candidate exists.

## Activation verification

Activation verification accepts only a streaming:

```text
iterable<NormalizedValue>
```

It does not accept raw application/database values and does not normalize them.

For the supplied authoritative-present stream:

- every item must probe as maybe-present;
- the first false Bloom result is an operational false negative and fails verification;
- the verifier may short-circuit on the first false negative;
- an empty authoritative-present stream may pass with checked count 0;
- Bloom driver operational failures and storage corruption propagate as typed failures.

A passed result is bound to the exact:

```text
FilterName + FilterVersion
```

and can move only the same current candidate:

```text
SHADOW -> VERIFIED
```

A health-only change does not invalidate otherwise matching version-bound verification evidence.

The verifier proves coverage only of the supplied complete/reconciled authoritative-present stream. Stream completeness and reconciliation are caller responsibilities. Sampling is not activation evidence.

## Explicit promotion

Promotion requires the current candidate to be:

```text
VERIFIED + HEALTHY
```

On success:

- the previous ACTIVE generation, when present, becomes RETIRED;
- the candidate becomes ACTIVE;
- `activeVersion` switches to the candidate;
- `candidateVersion` is cleared;
- generation health is preserved;
- the control revision advances exactly once.

Promotion changes only control-plane ownership. It does not copy, move, provision, destroy, or rewrite Bloom data-plane storage.

## Explicit deactivation

Deactivation retires the current ACTIVE generation and clears the active pointer.

It does not implicitly promote a candidate.

## Active-generation policy

M4 exposes a control-plane policy that selects a version only when the active pointer resolves to:

```text
ACTIVE + HEALTHY
```

All other lifecycle/health combinations are ineligible for probing through that policy.

This is **probe eligibility only**. It is not final authorization to skip an authoritative lookup.

Package-facing query orchestration and final fail-open query-skip authorization remain outside M4.

## Versioned rebuild boundary

The M3 `BloomDriver` still exposes raw driver primitives including:

```text
destroy -> provision
```

Those primitives remain valid for low-level storage recovery and driver use.

M4-managed generations, however, are versioned lifecycle entities. They must not be destructively rebuilt or reused in place. M4 does not implement rebuild scheduling/orchestration; any managed replacement under this model must use a newly allocated generation version rather than reusing the old one.

## M5 blocker

Runtime normalization identity/fingerprint compatibility is intentionally not solved by M4.

Before package-facing query orchestration can safely use a negative Bloom result, M5 must define how runtime normalization identity is verified against the identity used to build/synchronize a generation.
