# ADR-0003: Redis production backend

**Status:** ACCEPTED

## Decision

Redis is the only production Bloom backend planned for v1. Memory is for tests and development.

## Consequences

The first release avoids solving shared state for PHP workers or multiple app instances.
