# ADR-0040: M5 consistency contracts

**Status:** ACCEPTED

## Context

Trusted negatives require a statement about how authoritative membership changes while a Bloom generation is active.

Generic eventual synchronization is insufficient: a false-negative window can occur if the authoritative write becomes visible before the Bloom add.

Different applications have different write models, so M5 needs a small explicit supported set rather than pretending to solve arbitrary distributed consistency.

## Decision

M5 supports exactly two consistency contracts.

### `immutable-v1`

Membership is immutable while the generation is active.

Managed synchronization writes are rejected.

### `preadd-v1`

Every membership-entry write must obey:

```text
Bloom add
before
authoritative membership becomes visible
```

The Bloom add is allowed to precede an authoritative write that later fails because that produces only a safe false positive.

M5 exposes explicit managed `add()` / `addMany()` operations for this contract.

Observer/model-event coverage is not considered sufficient trusted-negative authority because it cannot prove coverage of every writer.

Candidate activation for `preadd-v1` requires an explicit quiescent membership-entry window.

Inside that window M5 performs:

1. full candidate reconciliation from the authoritative-present set;
2. fresh activation verification;
3. promotion.

The quiescent condition must remain true across all three steps.

M5 does not implement the external writer barrier itself.

The consistency contract is included in the generation semantic fingerprint set. Runtime/persisted mismatch makes the generation query-skip-ineligible.

## Consequences

- the supported mutable model has an explicit ordering invariant;
- reverse ordering is not supported for trusted negatives;
- eventual observer synchronization is not a substitute for `preadd-v1`;
- activation cannot silently assume concurrent writers are safe;
- applications unable to guarantee either supported contract must not use M5 trusted negatives for that filter;
- CDC/outbox coordination, distributed writer barriers, and online dual-write rebuild remain deferred.
