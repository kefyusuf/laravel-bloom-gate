# ADR-0019: Framework-neutral Redis port

**Status:** ACCEPTED

## Decision

Redis drivers depend on a framework-neutral command-executor contract; Laravel provides the connection adapter.

## Consequences

Redis implementation remains outside the Illuminate dependency boundary.
