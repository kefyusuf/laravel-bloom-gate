# M6 — Coordinated Online Rebuild Scope / Design Gate

**Status:** DRAFT — scope/design gate only  
**Baseline:** main@333040e1fc9a5e30d0c6f547d069604ce66c481e  
**Implementation authorized:** NO

M6 is not a catch-all hardening milestone.

## 1. Problem

M5 safely supports mutable preadd-v1 filters, but candidate activation requires an externally established quiescent membership-entry window:

~~~text
quiesce writers
-> reconcile candidate
-> fresh verification
-> promote
~~~

That is correct but operationally expensive for continuously written sets.

M5 also deferred Sentinel/Cluster runtime support, CDC/outbox adapters, backend failover attestation, retention/purge automation, and background profile monitoring. Those are independent concerns and must not be pulled into M6 merely because they were deferred.

## 2. M6 scope

M6 will focus only on:

> zero-downtime managed rebuild for mutable preadd-v1 filters through explicit writer coordination and active+candidate dual-write.

Target outcome:

~~~text
membership-entry writes continue
while
candidate is built + reconciled + verified + promoted
without
an operator-provided global quiescent window
~~~

Here, "zero-downtime" means **no planned global membership-write pause is required for a healthy coordinated workflow**. It is not a liveness guarantee: a leaked writer lease may deliberately block cutover rather than weaken correctness.

This capability is opt-in. Existing M5 behavior remains valid.

## 3. Explicit non-goals

M6 does not include:

- Redis Sentinel runtime support;
- Redis Cluster runtime support;
- replica trusted negatives;
- backend epoch/failover attestation;
- CDC/outbox adapters;
- automatic rebuild scheduling;
- retired-generation retention/purge automation;
- automatic storage garbage collection;
- background topology/profile monitoring;
- Bloom delete semantics;
- counting Bloom filters;
- RedisBloom replacement;
- generic DB transaction ownership;
- automatic discovery of application database writers.

## 4. Locked scope decisions

### M6-D001 — online rebuild is the only M6 product capability

M6 is complete when one mutable filter can move from active generation A to candidate C while membership-entry writes continue, and C can be verified/promoted without the M5 quiescent cutover.

### M6-D002 — keep preadd-v1; add a separate synchronization protocol

Rebuild coordination does not change membership meaning.

The existing semantic rule remains:

~~~text
Bloom pre-add
before
authoritative membership becomes visible
~~~

M6 therefore does not create a new generation consistency fingerprint just for coordination.

Conceptually:

~~~text
quiescent-v1   = current M5 activation model
coordinated-v1 = M6 writer leases + online candidate dual-write
~~~

Exact naming is deferred to naming review.

Consequences:

- existing preadd-v1 generations remain semantic-compatible;
- M5 stays the default;
- coordinated rebuild is explicit opt-in;
- changing rebuild coordination does not reinterpret existing Bloom bits.

### M6-D003 — current add/addMany cannot prove cutover safety

M5 MembershipAdder returns before the package knows whether the caller's authoritative transaction committed or rolled back.

Therefore it cannot prove that a writer which started before candidate publication has fully completed its authoritative mutation.

M6 needs a new explicit write-lifetime boundary:

~~~text
acquire writer lease
-> pre-add every required Bloom target
-> perform authoritative mutation / transaction
-> authoritative commit or rollback completes
-> release writer lease
~~~

The lease must span authoritative completion.

Exact public API shape is a next-gate decision:

- explicit lease/ticket;
- scoped callback;
- or both.

Any API that releases coordination before authoritative completion is invalid.

### M6-D004 — M5 add/addMany are invalid as coordinated writer APIs

When coordinated-v1 is enabled for a filter, the existing M5 add/addMany API cannot be treated as participation in the writer barrier.

Those calls end before authoritative commit completion is known.

Therefore coordinated mode must fail loudly if callers attempt to use the legacy managed-write surface as though it were coordination-aware.

A future convenience API may internally perform the same Bloom pre-add work, but it must keep a writer lease open across the authoritative mutation lifetime.

This prevents a dangerous "looks synchronized but is not barrier-safe" migration path.

### M6-D005 — the package still does not own DB transactions

Laravel Bloom Gate may own coordination lifetime, but the application remains responsible for authoritative transaction semantics.

No hidden Eloquent observer or implicit model-event integration is allowed.

### M6-D006 — coordination must be persistent and framework-neutral

Coordination cannot live only in one PHP process.

M6 requires a framework-neutral coordination boundary representing at least:

- synchronization epoch;
- current write targets;
- active writer leases;
- epoch rotation;
- previous-epoch drain observation.

Memory remains the reference implementation. Redis is the production implementation.

### M6-D007 — use a separate sync-v1 coordination plane

M6 must not change strict M4 control-v1.

Coordination state stays separate from:

- generation meta/bitmap data plane;
- lifecycle control state.

Conceptually, Redis may own same-filter keys such as:

~~~text
<prefix>:{<filter-name>}:sync
<prefix>:{<filter-name>}:sync:leases
~~~

Exact encoding is not locked yet.

All coordination keys must preserve the existing logical-filter Redis hash tag.

### M6-D008 — coordinated writers use epoch-pinned target sets

Each coordinated membership-entry operation obtains a unique writer lease bound to one synchronization epoch and explicit generation targets.

Possible target sets:

~~~text
first activation: candidate C
steady state: active A
online rebuild: active A + candidate C
post-promotion old lease: retired A + active C
~~~

Writing an old retired generation is harmless.

Missing the required new active/candidate target is not harmless.

A writer must successfully pre-add all targets in its lease before authoritative membership may become visible.

Partial Bloom writes remain safe because they create at worst false positives.

### M6-D009 — candidate is published before the authoritative rebuild scan

Safe online rebuild ordering:

~~~text
allocate C
-> provision layout
-> bind semantic contract
-> publish C as coordinated target + rotate epoch
-> drain all prior-epoch writers
-> reconcile complete authoritative-present set into C
-> fresh verification
-> promote pinned C
-> rotate synchronization to steady active=C
~~~

C must not become a write target before provision + semantic binding succeeds.

Once C is published:

- new writers pre-add C;
- prior writers remain represented by the previous epoch;
- reconciliation does not begin until every prior-epoch lease has completed its authoritative mutation.

This is the barrier that replaces M5's quiescent window.

### M6-D010 — lease release means authoritative completion

A writer lease means:

> this operation may still make authoritative membership visible.

It may be released only after the authoritative mutation has committed or definitively rolled back/aborted.

Releasing merely because Bloom pre-add completed is invalid.

### M6-D011 — no automatic lease expiry

Correctness is preferred over cutover availability.

Automatically expiring a lease could let cutover proceed while its authoritative mutation can still commit later.

Therefore M6 v1 does not silently expire writer leases.

A leaked lease may block rebuild progress. That is fail-safe:

~~~text
stuck rebuild
>
incorrect trusted negative
~~~

Automatic TTL, force release, and fencing for crashed external transactions are later design topics.

### M6-D012 — query fail-open and coordinated-write fail-closed are distinct

Query uncertainty remains:

~~~text
uncertainty -> authoritative query
~~~

Coordinated write uncertainty must be:

~~~text
coordination or target-write failure
-> throw/fail synchronization
-> caller must not commit authoritative membership
~~~

Coordinated mode must never silently fall back to active-only writes while an online candidate may exist.

### M6-D013 — promotion ordering must remain interruption-safe

Before promotion, active+candidate dual-write stays enabled.

Promotion ordering:

~~~text
control: C becomes ACTIVE
then
sync plane rotates A+C -> steady C
~~~

If the process fails after promotion but before sync rotation:

- writers may continue writing A+C;
- C is still included;
- correctness is preserved;
- only extra write amplification remains.

The reverse ordering is unsafe and forbidden.

The coordinator must therefore be persistent/idempotent enough to resume after interruption.

### M6-D014 — M5 remains fully supported

Existing:

~~~text
preadd-v1 + manual --quiescent activation
~~~

continues to work unchanged.

Coordinated mode must be explicit.

Applications must migrate all membership-entry writers that participate in the filter to the M6 coordination-aware API before claiming online rebuild safety.

Raw database writers remain outside package guarantees under ADR-0010.

### M6-D015 — deletes remain unchanged

M6 coordinates membership-entry writes only.

Deletes still do not clear standard Bloom bits and may only increase false positives until rebuild.

ADR-0011 remains unchanged.

## 5. Conceptual epoch model

### Steady

~~~text
epoch E
targets = [active A]
writers(E) may exist
~~~

### Open rebuild

After C is provisioned and bound:

~~~text
rotate E -> E+1

old E:
  draining

new E+1:
  targets = [A, C]
~~~

New writers acquire E+1 leases.

The rebuild waits until:

~~~text
writers(E) == 0
~~~

Only then does it establish C's authoritative reconciliation baseline.

### Promote

After reconciliation and fresh verification:

~~~text
control:
  A -> RETIRED
  C -> ACTIVE
~~~

Then:

~~~text
rotate E+1 -> E+2

old E+1 leases:
  still target [A, C]

new E+2 leases:
  target [C]
~~~

M6 v1 needs only one prior draining epoch at a time.

A second online rebuild cannot begin while the previous drain remains unresolved.

## 6. First activation

The same protocol must work with no existing active generation.

Before candidate publication:

~~~text
targets = []
~~~

Writers still hold leases through their authoritative mutation.

After C is provisioned/bound:

~~~text
rotate epoch
new targets = [C]
drain old no-target writers
reconcile
verify
promote C
rotate to steady [C]
~~~

The drain is required because a pre-publication writer may otherwise commit after candidate reconciliation.

## 7. Crash semantics

### Before candidate publication

No writer knows C. Candidate remains non-active.

### After dual-write publication, before promotion

A remains active. Writers may continue A+C writes. C remains non-authoritative. Workflow must be resumable or safely discardable.

### After promotion, before steady rotation

C is active. Writers may still write A+C. This is excess work, not a false-negative risk.

### Leaked lease

Drain remains blocked. No automatic unsafe expiry. Status/diagnostics must make the blocker visible.

## 8. Required invariants

- INV-M6-001: old M5 preadd-v1 + quiescent activation remains valid.
- INV-M6-002: no coordination-aware writer protocol means no online-rebuild safety claim.
- INV-M6-003: writer lease spans authoritative completion.
- INV-M6-003A: legacy M5 add/addMany cannot silently participate in coordinated-v1; misuse fails loudly.
- INV-M6-004: candidate is provisioned and semantically bound before publication.
- INV-M6-005: prior epoch drains before reconciliation baseline.
- INV-M6-006: every new-epoch writer includes C.
- INV-M6-007: fresh verification remains mandatory.
- INV-M6-008: promotion remains pinned to the verified candidate.
- INV-M6-009: control-v1 remains unchanged.
- INV-M6-010: coordinated-write uncertainty fails closed.
- INV-M6-011: M5 query authorization remains the only query safety model.
- INV-M6-012: writer leases do not auto-expire.
- INV-M6-013: C is not removed from required write targets before successful promotion.
- INV-M6-014: Sentinel/Cluster/failover support is not part of M6 scope.

## 9. Next design blockers

No implementation begins until the next design gate resolves:

1. public writer API: lease, callback, or both;
2. framework-neutral coordinator contracts;
3. exact sync-v1 Redis schema;
4. lease token registry and atomic acquire/release;
5. epoch counters/drain semantics;
6. cross-plane ordering and recovery;
7. candidate discard while dual-write is open;
8. command/API surface for online rebuild;
9. drain wait/timeout/status behavior;
10. brownfield rollout sequence from an already-active M5 preadd-v1 filter.

## 10. Alternatives rejected

### Put Sentinel/Cluster in M6

Rejected. It is orthogonal to writer/candidate coordination.

### Use CDC/outbox as M6 core

Rejected. Those are future adapters, not the minimum coordination protocol.

### Auto-expire leases

Rejected. Liveness must not weaken correctness.

### Reuse control-v1

Rejected. Lifecycle correctness state is not a writer registry.

### Put coordination in semantic fingerprints

Rejected. Rebuild coordination does not change active membership semantics.

### Reuse current add/addMany and infer commit completion

Rejected. The package cannot know the caller's authoritative commit lifetime after add/addMany returns. In coordinated mode, allowing this surface to appear barrier-safe would be a correctness footgun, so the coordinated path must reject it.

## 11. Explicit self-review

### Scope alignment — PASS

The proposed M6 directly addresses the M5 quiescent online-rebuild limitation without absorbing unrelated topology, retention, or CDC work.

### ADR/invariant consistency — PASS

Preserves ADR-0007, ADR-0008, ADR-0009, ADR-0010, ADR-0011, ADR-0021, ADR-0034, ADR-0035, ADR-0036, and ADR-0040.

### Dependency direction — PASS

Coordination remains framework-neutral. Laravel transaction convenience stays at the adapter edge.

### Complexity — PASS

The scope introduces only the machinery required for safe online rebuild:

- writer lease;
- epoch;
- explicit target set;
- drain barrier;
- persisted coordinator state.

### Brownfield safety — PASS

M5 stays the default. Existing control/data schemas remain unchanged. Coordinated mode is opt-in.

### Verification evidence — NOT APPLICABLE YET

This is a design-only gate. No runtime claim is made.

## 12. Gate result

**M6 scope candidate: PASS for dedicated design review.**

The next step is not implementation.

The next gate is:

> writer lease API + synchronization state machine + Redis sync-v1 persistence/atomicity.
