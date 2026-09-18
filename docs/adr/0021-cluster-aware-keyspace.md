# ADR-0021: Cluster-aware Redis keyspace

**Status:** ACCEPTED

## Decision

Keys for a logical filter share a Redis Cluster hash tag from v1.

## Consequences

Later cluster support does not require a breaking key migration.
