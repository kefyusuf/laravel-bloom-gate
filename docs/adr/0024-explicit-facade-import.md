# ADR-0024: Explicit facade import

**Status:** ACCEPTED

## Decision

Do not register a global Bloom alias; users import the package facade explicitly when it exists.

## Consequences

Existing applications avoid global alias collisions.
