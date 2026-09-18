# ADR-0011: No delete semantics in v1

**Status:** ACCEPTED

## Decision

Standard Bloom filters are not mutated to remove deleted values in v1.

## Consequences

Deletes can increase false positives until rebuild but do not create trusted false negatives.
