# ADR-0030: Normalized values are exact bytes

**Status:** ACCEPTED

## Decision

A NormalizedValue represents the exact byte sequence produced by a normalization policy. Core performs no trimming, casing, Unicode normalization, encoding conversion, or scalar coercion. Empty and binary strings are valid.

## Consequences

Build, add, check, synchronization, and verification can share one byte-exact contract. A normalization change remains a filter-invalidating change as required by ADR-0020.
