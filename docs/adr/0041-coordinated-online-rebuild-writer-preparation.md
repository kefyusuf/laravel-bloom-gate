# ADR-0041: Coordinated online rebuild and writer preparation protocol

**Status:** ACCEPTED

## Context

ADR-0040 defines the M5 mutable consistency contract `preadd-v1`:

```text
Bloom add
before
authoritative membership becomes visible
```

That ordering makes active-generation trusted negatives safe, but M5 candidate activation still requires an externally established quiescent membership-entry window across reconciliation, fresh verification, and promotion.

Continuously written filters need a zero-planned-pause rebuild path without weakening the existing membership-ordering contract or pretending that the package owns the application's authoritative database transaction.

The package also needs a durable way to know when a writer that started before an epoch transition can no longer make authoritative membership visible.

## Decision

M6 adds an explicit opt-in coordinated rebuild protocol for mutable `preadd-v1` filters.

This protocol does **not** replace or reinterpret `preadd-v1`. M5 quiescent activation remains valid and supported.

Coordination is a synchronization protocol, not a new generation membership semantic. Enabling coordinated rebuild does not by itself change the `preadd-v1` consistency identity/fingerprint.

### Explicit prepared writer lifetime

A coordinated membership-entry write uses a stable writer token created before the first remote coordination operation.

The canonical correctness sequence is:

```text
create stable token
-> acquire lease pinned to one synchronization epoch and exact target generations
-> Bloom pre-add every persisted target
-> durably mark the lease PREPARED
-> authoritative membership may become visible
-> authoritative commit or rollback/abort becomes definitively known
-> release lease
```

The package does not begin, commit, roll back, or infer the application's authoritative transaction.

Legacy M5 `add()` / `addMany()` are not coordination-aware writer APIs because their lifetime ends before authoritative completion is known. They must not silently participate after coordinated ownership is enabled.

### Durable lease states

A writer lease retains its original epoch and target set and has exactly three durable states:

```text
A = ACQUIRED / PREPARING
P = PREPARED
R = RELEASED
```

The persisted logical record is equivalent to:

```text
A|<epoch>|<targets>
P|<epoch>|<targets>
R|<epoch>|<targets>
```

Rules:

- acquisition atomically binds token + current epoch + exact current targets;
- retry of an existing A or P token reuses its original binding;
- A -> P is retry-safe and does not change the active-writer count;
- A -> P succeeds only while immutable coordinated ownership, strict current `sync-v1`, and a positive count for the lease's original epoch remain valid;
- missing, malformed, unavailable, or contradictory coordination state leaves the write pre-authoritative and fails closed;
- P proves every lease-bound Bloom pre-add completed before authoritative visibility was permitted;
- A and P both remain active writers until terminal release;
- R is terminal and cannot regain write authority;
- released-token tombstones are retained in M6 v1;
- tokens are not rebound or reused.

A failed or uncertain preparation acknowledgement prevents the caller from beginning the authoritative mutation.

### Authoritative outcome semantics

Lease release is permitted only after authoritative completion is positively known.

```text
known committed   -> release prepared lease
known rolled back -> release acquired or prepared lease
unknown outcome   -> keep lease active
```

A transport/database exception by itself is not proof of rollback.

If authoritative commit is known but release acknowledgement is uncertain, the authoritative write remains committed and cleanup uncertainty is reported separately. Callers must not be encouraged to replay the authoritative mutation blindly.

There is no correctness TTL, destructor release, process-shutdown release, or automatic lease expiry.

### Epoch-pinned writer targets

Persistent synchronization state defines a current epoch and exact ordered generation targets.

Each acquire linearizes atomically against epoch rotation:

```text
acquire before rotation -> old epoch / old targets
acquire after rotation  -> new epoch / new targets
```

Once an epoch rotation succeeds, the prior epoch is admission-closed. Its persisted active-writer count can only stay equal or decrease.

Drain completion is established only when the persisted active count for that admission-closed epoch reaches zero.

### Online rebuild ordering

For an existing active generation A and candidate C:

```text
provision and semantically bind C
-> build initial C contents
-> publish C as a coordinated target and rotate epoch
-> drain all pre-publication writers
-> reconcile the authoritative-present set into C
-> fresh verification
-> promote the verified candidate
-> rotate synchronization to steady C-only targets
-> drain the prior dual-write epoch
```

After publication, new writers pre-add both A and C.

For first activation, the same model applies with no prior active generation and an initial empty target set.

Candidate publication occurs only after C is provisioned, bound, and eligible for coordinated publication.

### Semantic compatibility

M6 online rebuild is not a semantic migration mechanism.

When A already exists, A and C must have identical:

- normalization fingerprint;
- authoritative-set fingerprint;
- consistency fingerprint.

Their Bloom layout may differ.

Changing those semantics requires a separate safe migration path.

### Failure behavior

Query uncertainty retains ADR-0007 fail-open behavior:

```text
uncertainty -> authoritative query
```

Coordinated write uncertainty fails closed:

```text
coordination or required-target write uncertainty
-> authoritative membership must not be committed
```

A stuck or outcome-uncertain lease may block rebuild progress. Correctness is preferred over cutover availability.

## Consequences

- continuously written `preadd-v1` filters can rebuild without a planned global write pause after coordinated adoption;
- writer lifetime becomes explicit and spans authoritative completion;
- every coordinated writer is pinned to a durable epoch/target set;
- prior-epoch drain is a persisted barrier rather than a timing assumption;
- PREPARED evidence makes post-crash committed-transaction recovery distinguishable from merely acquired work;
- stale/unknown leases can conservatively block cutover rather than weaken trusted-negative correctness;
- M5 quiescent activation remains supported for unadopted filters;
- coordinated rebuild does not add Bloom delete semantics, transaction ownership, CDC/outbox integration, or semantic migration.
