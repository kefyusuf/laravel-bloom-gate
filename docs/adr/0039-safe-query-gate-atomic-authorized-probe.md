# ADR-0039: Safe query gate and atomic authorized probe

**Status:** ACCEPTED

## Context

M4 `ACTIVE + HEALTHY` state proves only control-plane probe eligibility.

It does not prove that:

- the active pointer/revision stayed unchanged between inspection and probing;
- the generation layout matches the runtime probe;
- M5 semantic fingerprints match;
- generation storage is intact;
- backend conditions allow a negative to be trusted.

A package-facing `exists()` API must remain correct even when Bloom infrastructure is stale, unavailable, or racing with lifecycle changes.

## Decision

M5 exposes `QueryGate` as an authoritative-correct equality-membership gate.

For each request it:

1. resolves the registered filter definition;
2. normalizes the input exactly once;
3. falls back immediately if optimization is disabled;
4. derives the expected semantic contract;
5. resolves a query-safety descriptor from the current active snapshot and generation contract;
6. generates positions using the exact persisted active layout;
7. calls the backend-neutral `AuthorizedProbe`.

Outcomes:

```text
DEFINITELY_ABSENT -> return false without authoritative lookup
MAYBE_PRESENT     -> perform authoritative lookup
BYPASSED          -> perform authoritative lookup
```

A Bloom positive never establishes existence.

Redis implements `AuthorizedProbe` with one atomic Lua/EVAL operation.

The descriptor pins:

- filter name;
- control revision;
- active version;
- exact layout;
- expected semantic contract.

The script re-validates the current control state, generation storage/layout, semantic fingerprints, managed bitmap marker, and Bloom bits in the same atomic operation.

Any changed or unsafe state produces BYPASS or a loud protocol/programming failure; it never produces a trusted negative.

For Redis, a trusted negative additionally requires explicit declaration of the supported trusted-negative profile.

The hot query path does not run Redis admin diagnostics.

## Consequences

- `exists()` is authoritative-correct;
- infrastructure uncertainty fails open;
- lifecycle races cannot authorize a negative from a stale descriptor;
- `ACTIVE + HEALTHY` remains necessary but insufficient;
- semantic mismatch cannot be hidden by Bloom bits;
- Redis authorized probing remains backend-specific behind a framework-neutral port;
- production doctor/preflight remains separate from per-request correctness logic.
