# ADR-0013: No package database tables

**Status:** ACCEPTED

## Decision

V1 introduces no application migrations or package-owned SQL tables.

## Consequences

Installation remains lightweight and control-plane state is not forced into the host schema.
