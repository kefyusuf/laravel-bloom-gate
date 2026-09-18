# ADR-0005: Side-effect-free installation

**Status:** ACCEPTED

## Decision

Package discovery and Composer installation must not scan data, contact Redis, activate filters, or intercept queries.

## Consequences

Brownfield installation is operationally inert until explicit setup actions are taken.
