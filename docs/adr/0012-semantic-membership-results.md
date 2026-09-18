# ADR-0012: Semantic membership results

**Status:** ACCEPTED

## Decision

The public core uses DefinitelyAbsent, MaybePresent, and Bypassed semantics instead of exposing raw booleans.

## Consequences

Callers cannot easily confuse probabilistic positives with authoritative existence.
