# ADR-0038: Explicit filter definitions and managed sizing

**Status:** ACCEPTED

## Context

Package-facing query safety needs one canonical place to define how a logical filter interprets values, what authoritative membership means, and which consistency contract is promised.

Managed builds also need deterministic layout selection. Allowing arbitrary runtime layout choices across build/query/synchronization paths would create unnecessary compatibility risk.

The definition must remain framework-neutral even though Laravel configuration resolves it.

## Decision

M5 introduces the framework-neutral `FilterDefinition` contract:

```php
interface FilterDefinition
{
    public function normalizer(): ValueNormalizer;

    public function authoritativeSet(): AuthoritativeSet;

    public function consistency(): ConsistencyContract;
}
```

Laravel configuration registers:

- filter name;
- enabled flag;
- definition class;
- capacity;
- false-positive rate.

The Laravel registry resolves the definition lazily through the container.

Closures are not accepted as definition configuration.

Managed Bloom layout is derived deterministically by `OptimalBloomSizingV1` from:

```text
capacity
false-positive rate
```

The current policy uses the Core `Sha256DoubleHashV1` probe algorithm and validates the resulting layout against Core protocol limits.

Managed build persists and later reuses the exact generation layout. Query probing never recalculates the active layout from current capacity/FPR configuration.

## Consequences

- normalization, authoritative membership, and consistency semantics have one explicit definition boundary;
- framework-neutral correctness code does not depend on Eloquent or Illuminate;
- Laravel config remains cache-compatible;
- managed sizing is reproducible;
- active query probes use persisted generation layout, not potentially changed current sizing config;
- changing capacity/FPR affects future managed generations rather than mutating the meaning of an existing generation;
- arbitrary per-request layout selection is not part of the M5 managed API.
