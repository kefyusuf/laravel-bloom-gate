# ADR-0034: Revisioned lifecycle control plane

**Status:** ACCEPTED

## Context

The Bloom data plane is generation-scoped and versioned, but safe lifecycle management also needs a current logical-filter state: which generation is active, which generation is the candidate, the lifecycle and health of tracked generations, and protection against lost concurrent updates.

This state must remain separate from Bloom bitmap storage and must not turn persistence drivers into lifecycle-policy engines.

## Decision

M4 introduces a revisioned logical-filter control snapshot represented by `FilterControlState`.

The snapshot contains:

- exact `FilterName`;
- `FilterStateRevision`;
- `lastAllocatedVersion`;
- optional active generation pointer;
- optional candidate generation pointer;
- tracked generation lifecycle and health state.

A logical filter may have at most one active pointer and one candidate pointer. Active and candidate versions must differ.

Generation versions are allocated monotonically from `lastAllocatedVersion` and are never reused.

Persistence is exposed through the framework-neutral `FilterControlStore` contract:

```php
read(FilterName $name): ?FilterControlState

compareAndSwap(
    FilterName $name,
    FilterControlState $next,
    ?FilterStateRevision $expectedRevision,
): void
```

Writes use compare-and-swap semantics. A stale or otherwise mismatched expected revision produces `FilterControlWriteConflict`; persistence corruption and operational storage failure remain separate typed failures.

Lifecycle transition legality remains outside persistence drivers.

The control snapshot is current correctness state, not an audit log or event-sourced history.

M4 retains retired generation records. A future pruning policy may remove retired records, but it must preserve `lastAllocatedVersion` so old generation numbers can never be reused.

## Consequences

- concurrent control-plane writers cannot silently overwrite each other;
- version allocation has a durable monotonic waterline;
- Memory and Redis stores share one conformance contract;
- lifecycle and health remain independent axes;
- persistence drivers own storage/CAS semantics, not lifecycle workflow policy;
- no distributed lock is required for M4;
- historical audit/event sourcing is not implied by the control snapshot;
- future retired-record pruning remains possible without weakening version uniqueness.
