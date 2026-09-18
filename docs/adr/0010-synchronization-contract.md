# ADR-0010: Synchronization is a contract

**Status:** ACCEPTED

## Decision

The package never assumes it observes every database write path.

## Consequences

Operators must choose and understand the synchronization strategy.
