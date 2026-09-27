# M6 — control-v1 ↔ sync-v1 Atomic Fencing / Lifecycle Ownership Gate

**Status:** DRAFT — design-only sub-gate  
**Baseline:** main@816ab46cb131b70459557615ed3256ea14907a04  
**Implementation authorized:** NO

This document continues the approved M6 online-rebuild design after the landed writer-lease / sync-v1 persistence gate.

It resolves the next correctness boundary:

> how lifecycle control mutations and synchronization mutations are fenced against each other without changing strict control-v1 or permitting stale legacy lifecycle writes.

The design deliberately avoids a generic "write both planes at once" transaction primitive. Instead, each durable operation mutates one correctness plane only while atomically fencing against the expected revision of the other plane.

That gives a serializable cross-plane protocol while preserving interruption-safe intermediate states.

## 1. Existing constraints

The following are already locked by prior M6 gates:

- control-v1 remains strict and schema-compatible;
- sync-v1 is a separate persistent coordination plane;
- writer acquisition is linearizable with sync epoch rotation;
- sync ownership cannot be bypassed by runtime configuration drift;
- legacy M5 lifecycle actions cannot silently mutate an open coordinated session;
- candidate publication happens only after provisioning + semantic binding;
- prior writer epoch drains before reconciliation;
- fresh verification remains mandatory;
- promotion is version-pinned;
- promotion before dual-write shutdown is the safe ordering;
- Redis scripts enforce persistence atomicity, not application policy;
- M5 query authorization remains unchanged;
- coordinated write uncertainty fails closed.

## 2. Problem

Today M5 lifecycle services mutate control-v1 through:

~~~text
FilterControlStore::read()
FilterControlStore::compareAndSwap()
~~~

Examples include candidate allocation, lifecycle and health transitions, verification evidence, promotion, discard, and active deactivation.

A client-side pattern such as:

~~~text
read sync
check safe
control compareAndSwap
~~~

is insufficient because another process can advance sync-v1 between the check and the control CAS.

The inverse is equally unsafe:

~~~text
read control
check candidate C
sync compareAndSwap
~~~

because control may change before sync publication.

The package therefore needs one per-filter serialization boundary covering ordinary control CAS, coordinated control CAS, sync CAS, and writer acquisition/epoch rotation.

## 3. M6-D036 — valid sync-v1 presence is durable lifecycle ownership

For M6 v1, a valid persisted sync-v1 snapshot is the durable ownership marker for coordinated lifecycle mutation.

~~~text
sync-v1 absent
-> unadopted M5 lifecycle ownership
-> ordinary FilterControlStore CAS allowed

sync-v1 present
-> coordinated lifecycle ownership
-> ordinary FilterControlStore CAS fenced
~~~

This also applies while the phase is STEADY.

A coordinated filter does not temporarily become a legacy M5 filter merely because no rebuild is currently open.

Removing coordinated ownership requires a future explicit de-adoption protocol. M6 v1 defines no implicit de-adoption.

## 4. M6-D037 — ordinary reads remain valid; ordinary control CAS is fenced

Existing read APIs remain valid for query-safety resolution, diagnostics, status, and non-mutating inspection.

Ordinary FilterControlStore::compareAndSwap keeps its M5 semantics only while sync-v1 is absent.

Once valid sync-v1 exists, ordinary control CAS must fail loudly with a coordination-fence conflict.

It must not mutate control first and notice sync later, ignore malformed sync storage, allow a local configuration escape hatch, or silently downgrade to M5 semantics.

### Redis requirement

The ordinary Redis control CAS script atomically inspects:

~~~text
:state
:state:staging
:sync
~~~

before durable control mutation.

Outcomes:

~~~text
sync key absent
-> existing M5 CAS behavior

valid sync-v1 present
-> COORDINATION_FENCED

sync wrong type / malformed
-> fail closed as coordination corruption
~~~

Therefore a stale legacy operation that read control before adoption is still stopped if sync ownership was published before its CAS linearizes.

### Memory requirement

The Memory control store provides the same fence semantics against the same per-filter synchronization state.

A Memory implementation that cannot observe coordinated ownership at control-CAS linearization time is not contract-equivalent.

## 5. M6-D038 — dedicated framework-neutral coordinated lifecycle store

M6 introduces a separate port for coordinated cross-plane mutation.

Conceptually:

~~~php
interface CoordinatedLifecycleStore
{
    public function read(
        FilterName $name,
    ): CoordinatedLifecycleSnapshot;

    public function compareAndSwapControl(
        FilterName $name,
        FilterControlState $nextControl,
        ?FilterStateRevision $expectedControlRevision,
        SynchronizationRevision $expectedSyncRevision,
    ): CoordinatedLifecycleSnapshot;

    public function compareAndSwapSynchronization(
        FilterName $name,
        SynchronizationState $nextSynchronization,
        ?SynchronizationRevision $expectedSyncRevision,
        ?FilterStateRevision $expectedControlRevision,
    ): CoordinatedLifecycleSnapshot;
}
~~~

Exact PHP names may be refined during implementation planning.

The semantic boundary is locked:

- read observes control + sync as one atomic pair;
- coordinated control CAS mutates only control and verifies the expected sync revision;
- coordinated sync CAS mutates only sync and verifies the expected control revision;
- neither method falls back to ordinary M5 CAS;
- revision mismatch on either plane is a write conflict requiring reread/re-evaluation.

The port remains framework-neutral.

## 6. M6-D039 — opposite-revision guards provide cross-plane serializability

Each coordinated mutation carries the expected revision of the plane being mutated plus the expected revision of the opposite plane.

Starting state:

~~~text
control revision = C10
sync revision    = S7
~~~

Two concurrent operations start from this pair.

Operation A:

~~~text
control CAS:
  expect C10 + S7
  write C11
~~~

Operation B:

~~~text
sync CAS:
  expect S7 + C10
  write S8
~~~

Only one succeeds first.

If A linearizes first, B sees stale control revision C10 and fails.

If B linearizes first, A sees stale sync revision S7 and fails.

The pair therefore behaves as a serializable per-filter state machine without rewriting both durable snapshots in one operation.

## 7. M6-D040 — coordinated read is an atomic pair read

Application policy does not derive coordinated transitions from independently fetched snapshots.

The coordinated store returns one atomic observation:

~~~text
control snapshot or absent
sync snapshot or absent
~~~

This pair is the basis for ownership checks, revision expectations, phase policy, candidate/active relation checks, and recovery decisions.

Redis performs this read in one Lua/EVAL operation over :state and :sync and strictly validates whichever snapshots exist.

Memory returns the pair from the same per-filter critical section used by coordinated mutation.

## 8. M6-D041 — adoption is sync-only creation under a control fence

Adoption does not rewrite control-v1.

Instead:

~~~text
atomic operation:
  require sync-v1 absent
  require control revision still expected
  create initial sync-v1
~~~

Initial coordinated state is conceptually:

~~~text
phase = STEADY
current_epoch = 1
candidate_version = absent
draining_epoch = absent

current_targets =
  [active] if control has an active generation
  []       if control is absent / has no active generation
~~~

Application policy also requires no existing candidate when adoption is finalized.

The already-approved brownfield one-time quiescent migration fence remains mandatory for an existing running M5 deployment. This storage operation does not waive it.

### Adoption race with stale legacy CAS

If legacy control CAS linearizes first, control revision advances and adoption's expected control revision fails.

If adoption linearizes first, sync-v1 exists and the legacy CAS is coordination-fenced.

There is no ordering in which both stale operations succeed.

## 9. M6-D042 — coordinated control mutations are control-only under sync fence

After adoption, every M6-owned control mutation uses:

~~~text
expected control revision
expected sync revision
next control snapshot
~~~

The Redis operation:

1. validates current strict control-v1;
2. validates current strict sync-v1;
3. requires both expected revisions;
4. validates complete proposed next control-v1;
5. materializes :state:staging;
6. atomically replaces only :state.

It does not modify :sync.

Application/Lifecycle policy decides whether the transition is legal in the current sync phase.

Examples include candidate allocation, candidate lifecycle changes, generation health changes, verification evidence, promotion, and pre-publication candidate discard.

Existing M5 mutation services cannot be treated as coordinated merely because they compute an equivalent next snapshot.

## 10. M6-D043 — coordinated sync mutations are sync-only under control fence

Every M6 sync transition uses:

~~~text
expected sync revision
expected control revision
next sync snapshot
~~~

The Redis operation:

1. validates current strict sync-v1;
2. validates current strict control-v1 or required absence;
3. requires both expected revisions;
4. validates complete proposed next sync-v1;
5. materializes :sync:staging;
6. atomically replaces only :sync.

It does not modify :state.

Examples include candidate publication + epoch rotation, drain phase progression, READY_TO_PROMOTE, post-promotion epoch rotation, and return to STEADY.

## 11. M6-D044 — no generic dual-plane durable write

M6 v1 deliberately rejects a primitive that rewrites both :state and :sync in one generic atomic pair CAS.

Redis Lua execution is serialized, but Redis does not transactionally roll back earlier script writes when a later command fails.

A design such as:

~~~text
RENAME state:staging -> state
RENAME sync:staging  -> sync
~~~

could theoretically leave one durable plane advanced if the second durable replacement fails.

The package does not need that risk.

Instead M6 uses:

> guarded single-plane CAS + interruption-safe ordering.

Each durable replacement remains independently complete and atomic. Cross-plane correctness comes from opposite-revision fencing and safe intermediate states.

## 12. M6-D045 — lifecycle ownership after adoption is exclusive

Once sync-v1 exists, all control mutations for that filter belong to the M6 coordinated lifecycle path.

That includes seemingly local operations such as marking a generation STALE, BUILDING -> SHADOW, SHADOW -> VERIFIED, retiring a candidate, and promotion.

Therefore existing M5 top-level mutators must not silently mutate an adopted filter through ordinary FilterControlStore CAS.

Affected mutation paths include:

~~~text
ManagedFilterBuilder
ManagedFilterVerifier
ManagedFilterActivator
CandidateDiscarder
CandidateAllocator
GenerationLifecycleTransitioner
GenerationHealthUpdater
CandidatePromoter
ActiveGenerationDeactivator
~~~

Their ordinary mutation path is fenced.

Implementation may later add M6-specific orchestrators, extract reusable pure transition computation, or add coordination-aware variants.

No second independent lifecycle policy is allowed.

## 13. M6-D046 — coordinated candidate build occurs before publication

A coordinated filter in STEADY may own an unpublished control-plane candidate.

Safe sequence:

~~~text
sync:
  STEADY
  targets = [A]

control:
  allocate candidate C
  C CONFIGURED -> BUILDING
  provision C
  bind immutable semantic contract
  populate initial authoritative scan
  C -> SHADOW + HEALTHY
~~~

These control mutations use coordinated control CAS under the STEADY sync revision lineage.

During this phase writers still target only A.

That is safe because C is not yet advertised as synchronized.

If candidate build fails before publication, M6 may retire/discard C through coordinated control CAS while sync remains STEADY.

No online-session abort protocol is needed until C has been published as a writer target.

## 14. M6-D047 — candidate publication is a sync-only fenced transition

After C is provisioned, semantically bound, SHADOW, HEALTHY, and semantic-compatible with active A when A exists, Application policy may publish it.

The atomic sync mutation requires the exact control revision that still owns candidate C.

~~~text
before:
  control active=A candidate=C
  sync STEADY epoch=E targets=[A]

sync CAS under control revision:
  phase = DRAINING_PRE_RECONCILE
  current_epoch = E+1
  current_targets = [A,C]
  candidate_version = C
  draining_epoch = E
~~~

First activation uses old targets [] and new targets [C].

The successful sync CAS is also the writer admission barrier already defined by the previous M6 gate.

If control changed before publication, publication conflicts and is recomputed.

## 15. M6-D048 — reconciliation and verification remain recoverable single-plane steps

After the prior epoch drains:

~~~text
DRAINING_PRE_RECONCILE
-> RECONCILING
~~~

is a sync-only transition under the current control revision.

Reconciliation runs while new writers continue dual-writing the published target set.

Fresh verification remains Application work.

If verification changes control lifecycle/health, it uses coordinated control CAS under the expected sync revision.

A passed candidate then moves sync to READY_TO_PROMOTE through a sync-only fenced CAS.

Failure between operations leaves a persistent pair that can be reread and resumed.

## 16. M6-D049 — promotion remains control-first, then sync rotation

The already-approved safe ordering is preserved.

### Step 1 — control promotion

While sync is:

~~~text
READY_TO_PROMOTE
targets = [A,C]
candidate = C
~~~

coordinated control CAS:

- requires the exact sync revision;
- requires current candidate C;
- promotes C to ACTIVE;
- retires A when present;
- clears the control candidate pointer.

Sync remains unchanged.

After this step:

~~~text
control:
  active = C
  candidate = none

sync:
  READY_TO_PROMOTE
  targets = [A,C]
  candidate = C
~~~

This is an intentional recovery state.

It is safe because every coordinated writer still includes C.

### Step 2 — sync rotation

A sync-only CAS requires the newly promoted control revision and rotates:

~~~text
current_epoch E+1 -> E+2
current_targets [A,C] -> [C]
draining_epoch = E+1
phase = DRAINING_POST_PROMOTION
candidate_version = absent
~~~

No writer can observe C-only targets before control already names C active.

### Crash between steps

If the process stops after control promotion but before sync rotation:

- C is ACTIVE;
- writers still target A+C;
- trusted-negative correctness is not weakened;
- write amplification continues;
- recovery rereads the pair and performs pending sync rotation.

This safe intermediate state is preferable to a dual-plane atomic rewrite.

## 17. M6-D050 — READY_TO_PROMOTE has one explicit recovery shape

The cross-plane relation policy for READY_TO_PROMOTE permits exactly two valid shapes.

### Pre-promotion

~~~text
control:
  active = A or none
  candidate = C
  C = VERIFIED + HEALTHY

sync:
  phase = READY_TO_PROMOTE
  candidate = C
  targets include C
~~~

### Promotion committed / rotation pending

~~~text
control:
  active = C
  candidate = none
  C = ACTIVE + HEALTHY

sync:
  phase = READY_TO_PROMOTE
  candidate = C
  targets include C
~~~

Any other relation is a coordination inconsistency and lifecycle mutation fails closed.

This prevents restart recovery from treating an already-promoted C as a foreign or missing candidate.

## 18. M6-D051 — post-promotion drain closes before another rebuild

After sync rotates to C-only:

~~~text
phase = DRAINING_POST_PROMOTION
current_targets = [C]
draining_epoch = prior dual-write epoch
~~~

Old prior-epoch leases may still write A+C, which is safe because C remains included.

A second online rebuild cannot begin until activeWriterCount(draining_epoch) == 0.

Then a sync-only CAS under the current control revision clears draining_epoch and moves phase to STEADY.

At STEADY, current targets equal the current control active generation, or are empty when no active exists.

## 19. M6-D052 — generic lifecycle commands fail loudly after adoption

Existing M5 commands remain valid for unadopted filters.

For an adopted filter, ordinary mutation commands must not pretend to be safe:

~~~text
bloom:build
bloom:verify
bloom:activate
bloom:discard
~~~

Their M5 mutation paths are fenced.

A future M6 command/API surface may expose coordinated rebuild operations, explicit adoption, and safe abort/recovery.

Read-only status/doctor operations may continue inspecting state.

## 20. M6-D053 — malformed or unavailable opposite-plane state fails closed for mutation

For lifecycle mutation, uncertainty is not permission.

Examples include malformed :sync during ordinary control CAS, malformed :state during sync CAS, unavailable Redis during coordinated pair read, unexpected revisions, and impossible cross-plane relations.

Result:

~~~text
mutation blocked
~~~

No mutation may interpret corruption as "sync not enabled", "no active session", "safe to continue legacy", or "candidate absent".

Query-path fail-open semantics remain separate and unchanged.

## 21. M6-D054 — Memory and Redis share one per-filter mutation order

For one logical filter, the Memory reference backend reproduces the same observable ordering as Redis.

The following share one logical serialization boundary:

- ordinary control CAS;
- coordinated control CAS;
- coordinated sync CAS;
- sync acquire;
- sync release/count mutation;
- epoch rotation.

This does not require a public lock API.

Different filters remain independent.

## 22. Redis operation matrix

### Ordinary M5 control CAS

~~~text
keys:
  :state
  :state:staging
  :sync

condition:
  sync must be absent

writes:
  control only
~~~

### Coordinated pair read

~~~text
keys:
  :state
  :sync

writes:
  none
~~~

### Coordinated control CAS

~~~text
keys:
  :state
  :state:staging
  :sync

conditions:
  expected control revision
  expected sync revision

writes:
  control only
~~~

### Coordinated sync CAS

~~~text
keys:
  :sync
  :sync:staging
  :state

conditions:
  expected sync revision
  expected control revision / required control absence

writes:
  sync only
~~~

### Writer acquire

~~~text
keys:
  :sync
  :sync:leases
  :sync:counts

writes:
  lease + epoch count
~~~

All keys preserve the exact logical-filter Redis hash tag.

This remains same-slot-compatible construction, not a Redis Cluster runtime-support claim.

## 23. Cross-plane race proofs

### Legacy CAS vs adoption

~~~text
legacy first
-> control revision changes
-> adoption expected control revision conflicts

adoption first
-> sync exists
-> legacy CAS coordination-fenced
~~~

### Coordinated control CAS vs sync CAS

~~~text
both read C10/S7

control first -> C11/S7
sync expects C10 -> conflict

sync first -> C10/S8
control expects S7 -> conflict
~~~

### Candidate publication vs candidate replacement

~~~text
publication expects control revision owning C

candidate mutation first
-> control revision changes
-> publication conflicts

publication first
-> sync revision changes / candidate becomes published
-> stale candidate mutation conflicts on sync revision
~~~

### Promotion vs writer acquire

Before sync rotation, writer acquire receives A+C.

During or after control promotion but before sync rotation, acquire still receives A+C.

After sync rotation, acquire receives C.

No acquire receives C-only targets before the sync rotation fenced by the already-promoted control revision.

## 24. Explicitly deferred after this gate

Even if this gate passes, M6 implementation remains blocked on:

1. coordinated abort/discard after candidate publication;
2. exact brownfield adoption operator/API sequence;
3. authoritative-outcome recovery UX for stuck leases;
4. rebuild command / public API surface;
5. diagnostics/status representation for sync phases and blockers;
6. implementation task breakdown and verification matrix;
7. accepted ADR synchronization.

No source implementation begins from this document alone.

## 25. Required invariants added by this gate

- INV-M6-039: valid sync-v1 presence is the durable coordinated lifecycle-ownership marker.
- INV-M6-040: ordinary FilterControlStore CAS is atomically rejected once coordinated ownership exists.
- INV-M6-041: malformed sync state never re-enables legacy control mutation.
- INV-M6-042: coordinated lifecycle decisions derive from one atomic control+sync pair read.
- INV-M6-043: coordinated control CAS checks expected sync revision and mutates control only.
- INV-M6-044: coordinated sync CAS checks expected control revision and mutates sync only.
- INV-M6-045: opposite-revision fencing serializes concurrent cross-plane mutations.
- INV-M6-046: M6 v1 has no generic dual-plane durable write primitive.
- INV-M6-047: adoption creates sync-v1 under an atomic control revision fence.
- INV-M6-048: candidate publication is sync-only and pinned to the control revision owning C.
- INV-M6-049: promotion is control-first; C remains in writer targets until sync rotation completes.
- INV-M6-050: READY_TO_PROMOTE permits the explicit promotion-committed/rotation-pending recovery shape.
- INV-M6-051: another rebuild cannot begin until post-promotion drain reaches zero.
- INV-M6-052: existing M5 lifecycle mutators cannot silently operate on an adopted filter.
- INV-M6-053: coordinated mutation corruption/unavailability fails closed.
- INV-M6-054: Memory and Redis provide the same per-filter mutation ordering.

## 26. Explicit self-review

### Scope alignment — PASS

This gate resolves the exact blocker left by the previous M6 gate: control/sync TOCTOU and lifecycle ownership.

It does not add topology, retention, CDC, delete semantics, or transaction ownership.

### Existing ADR/invariant consistency — PASS

The design preserves strict control-v1, separate sync-v1, M4 revisioned CAS semantics, M5 query safety, the M6 writer admission barrier, application-owned lifecycle policy, same-filter Redis hash-tag construction, and the no-TTL correctness model.

### Dependency direction — PASS

The coordinated store is framework-neutral.

Lifecycle/Application computes legal transitions.

Redis and Memory enforce storage atomicity, revision fencing, and corruption semantics only.

### Unnecessary complexity — PASS

The design avoids distributed locks, generic two-plane transactions, control schema changes, sync fields inside control-v1, callback ownership, and Redis WATCH/MULTI client loops.

The primitive set remains:

~~~text
atomic pair read
guarded control CAS
guarded sync CAS
legacy CAS fence
~~~

### Interruption safety — PASS

Every cross-plane multi-step workflow has a safe durable intermediate state.

Most importantly:

~~~text
control promotion
before
sync target contraction
~~~

remains resumable after crash.

### Brownfield safety — PASS, still separately gated

Adoption storage fencing is defined, but the required one-time quiescent handoff is not waived.

Existing unadopted M5 filters behave unchanged.

### Verification evidence — NOT APPLICABLE YET

This is a docs-only design gate.

No runtime capability is claimed.

## 27. Gate result

**Atomic control↔sync fencing + lifecycle ownership: candidate PASS for external design review.**

**M6 implementation remains NOT AUTHORIZED.**

The next gate after approval must resolve:

> coordinated abort/discard recovery + brownfield adoption workflow + public command/API boundary.

Only after those remaining product/recovery semantics are closed should implementation planning begin.
