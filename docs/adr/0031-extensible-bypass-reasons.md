# ADR-0031: Bypass reasons are extensible codes

**Status:** ACCEPTED

## Decision

BypassReason is an immutable value object with a validated machine-readable code rather than a closed enum. Core provides named factories for common package reasons while allowing future valid codes.

## Consequences

Operational integrations can add diagnostic reasons without expanding a public enum and breaking exhaustive matches. Configuration and programming errors are not treated as bypass reasons.
