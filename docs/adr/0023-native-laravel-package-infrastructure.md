# ADR-0023: Native Laravel package infrastructure

**Status:** ACCEPTED

## Decision

Use native ServiceProvider, package discovery, config publishing, and Testbench rather than a package-framework abstraction.

## Consequences

Runtime dependencies and bootstrap indirection stay minimal.
