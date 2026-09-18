# ADR-0009: Versioned rebuild

**Status:** ACCEPTED

## Decision

Rebuild creates a candidate generation and promotes it instead of destructively replacing the active generation.

## Consequences

Safe rollback and blue/green promotion remain possible.
