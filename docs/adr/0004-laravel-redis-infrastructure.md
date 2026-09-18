# ADR-0004: Use Laravel Redis infrastructure

**Status:** ACCEPTED

## Decision

Laravel integration uses the application's configured Laravel Redis connection rather than owning a Redis client configuration.

## Consequences

Existing connection policy and credentials stay under application control.
