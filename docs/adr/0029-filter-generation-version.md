# ADR-0029: Filter generation version

**Status:** ACCEPTED

## Decision

A filter generation version is an immutable positive integer starting at 1. The value object may compute its next value but does not allocate versions or solve concurrent generation assignment.

## Consequences

Version identity remains compact and deterministic while concurrency responsibility stays in the future control-plane state store.
