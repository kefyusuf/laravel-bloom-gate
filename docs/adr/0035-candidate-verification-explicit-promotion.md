# ADR-0035: Candidate verification and explicit promotion

**Status:** ACCEPTED

## Context

A Bloom generation must not become active merely because it has been built. Activation requires evidence that all supplied authoritative-present values are represented by the candidate without an operational false negative.

Verification and activation also need to remain separate so evidence can be inspected and promotion can enforce its own current-state preconditions.

## Decision

M4 verification accepts only a streaming `iterable<NormalizedValue>`.

The verifier:

- uses the existing Core probe protocol and `BloomDriver`;
- does not accept raw values;
- does not perform normalization;
- fails on the first authoritative-present value that probes false;
- may short-circuit after that first false negative;
- propagates Bloom operational failures and storage corruption;
- records the exact checked count;
- binds passed evidence to `FilterName + FilterVersion`;
- does not expose raw failed values.

Verification evidence can move only the same current candidate:

```text
SHADOW -> VERIFIED
```

A health-only change does not invalidate otherwise matching version-bound evidence.

Verification proves coverage only of the supplied complete/reconciled authoritative-present stream. Completeness and reconciliation are caller responsibilities. Sampling is not valid activation evidence.

Promotion is a separate explicit operation and requires the current candidate to be:

```text
VERIFIED + HEALTHY
```

Promotion atomically changes the control snapshot:

- prior ACTIVE generation -> RETIRED, when present;
- candidate -> ACTIVE;
- active pointer -> candidate;
- candidate pointer -> null.

Promotion does not provision, destroy, copy, move, or rewrite Bloom data.

The M4 active-generation policy returns a probe-eligible version only for the current:

```text
ACTIVE + HEALTHY
```

generation.

That eligibility is not final query-skip authorization. Package-facing authoritative-query orchestration remains outside M4.

Runtime normalization identity/fingerprint compatibility is a mandatory M5 design blocker.

## Consequences

- verification cannot silently activate a candidate;
- a false negative prevents verification;
- stale version-bound evidence cannot verify a different candidate;
- health and verification evidence remain separate concerns;
- promotion remains control-plane-only;
- application/query orchestration must still decide whether Bloom probing is safe and whether an authoritative lookup may be skipped;
- M5 must solve runtime normalization identity before negative-result query skipping can be exposed safely.
