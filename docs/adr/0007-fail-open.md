# ADR-0007: Fail open

**Status:** ACCEPTED

## Decision

Infrastructure uncertainty bypasses Bloom optimization and executes the authoritative lookup.

## Consequences

Performance can degrade; correctness must not.
