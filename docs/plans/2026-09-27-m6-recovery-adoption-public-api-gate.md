# M6 — Recovery, Brownfield Adoption, and Public API Gate

**Status:** DRAFT — design-only sub-gate  
**Baseline:** main@09aa228a1121f6c5faf8a6e8923513737e113391  
**Implementation authorized:** NO

This document continues the approved M6 online-rebuild design after the landed
control↔sync fencing gate.

It resolves only the remaining recovery/product boundary:

> coordinated abort/discard recovery + brownfield adoption workflow + public command/API boundary.

It also closes one correctness gap discovered while designing authoritative-outcome
recovery: the previous binary ACTIVE/RELEASED lease record cannot durably prove that
all lease-bound Bloom pre-adds completed before an authoritative commit. Because no
M6 implementation exists yet, this gate refines that draft storage model before code
is authorized.

## 1. Baseline constraints

This gate preserves all previously locked M6 requirements:

- M5 `preadd-v1` membership semantics remain unchanged;
- coordinated mode is explicit opt-in;
- writer leases span authoritative completion;
- legacy M5 `add/addMany` cannot participate in coordinated mode;
- query uncertainty remains fail-open to the authoritative lookup;
- coordinated mutation uncertainty fails closed;
- no correctness lease TTL or automatic force release exists;
- released tokens remain terminal and are retained in M6 v1;
- `control-v1` remains strict and unchanged;
- mutable `sync-v1` remains separate from the control plane;
- immutable `:sync:owner` is the one-way durable lifecycle-ownership fence;
- coordinated control and sync mutations are opposite-revision fenced;
- promotion remains control-first, then sync target contraction;
- M6 supports at most one admission-closed draining epoch at a time;
- the package never claims to discover every raw authoritative database writer.

## 2. Gate scope

This gate resolves:

1. safe candidate abort/discard before and after publication;
2. crash-safe abort recovery states;
3. the exact one-time brownfield adoption handoff;
4. adoption crash recovery;
5. coordinated-mode disable/de-adoption policy for M6 v1;
6. authoritative-outcome recovery for stuck writer leases;
7. the public Application-layer management boundary;
8. the Laravel Artisan command surface;
9. restart/resume and drain-wait behavior;
10. coordination status and diagnostics representation.

This gate does **not** authorize:

- runtime implementation;
- Redis/Memory driver changes;
- automatic background rebuild scheduling;
- automatic lease expiry;
- force release;
- automatic transaction-outcome discovery;
- physical generation garbage collection;
- Redis Sentinel/Cluster runtime support;
- CDC/outbox adapters;
- accepted ADR synchronization.

## 3. M6-D055 — durable PREPARED proof is required before authoritative visibility

The previous writer-lease gate used two durable lease record states:

~~~text
A = ACTIVE
R = RELEASED
~~~

That is insufficient for safe operator recovery.

An ACTIVE token proves only that acquisition succeeded and that the writer is counted.
It does **not** prove that every lease-bound Bloom target was successfully pre-added.

If an operator later learns that an authoritative transaction committed, blindly
releasing such a token could allow cutover even though one required target was never
pre-added.

M6 v1 therefore refines the lease protocol to three durable states:

~~~text
A|<epoch>|<targets>   ACQUIRED / PREPARING
P|<epoch>|<targets>   PREPARED
R|<epoch>|<targets>   RELEASED
~~~

`P` means:

> all Bloom pre-adds for the exact persisted lease targets completed successfully,
> and that completion was itself durably acknowledged before the caller was allowed
> to make authoritative membership visible.

This refinement supersedes the binary lease-record shape proposed by M6-D029 before
implementation begins.

### Preparation sequence

The canonical write preparation becomes:

~~~text
stable token created
-> acquire token as A
-> pre-add every persisted lease target
-> atomically mark token P
-> return prepared write to caller
-> only then may authoritative membership become visible
~~~

If the `A -> P` acknowledgement is uncertain, the caller must not begin the
authoritative mutation.

Retry uses the same token.

- existing `A`: repeat idempotent pre-add work, then retry mark-prepared;
- existing `P`: return the original prepared binding;
- existing `R`: fail as terminal.

A partial or repeated Bloom pre-add is safe because it creates at worst false positives.

## 4. M6-D056 — markPrepared is a dedicated retry-safe storage operation

The synchronization storage boundary gains one semantic operation:

~~~php
public function markPrepared(
    FilterName $name,
    WriterLeaseToken $token,
): WriterLease;
~~~

Exact PHP naming may be refined during implementation planning, but the semantic
operation is mandatory.

Storage semantics:

~~~text
A -> P   success
P -> P   idempotent success
R        terminal-token failure
missing  unknown-token failure
malformed record -> corruption
~~~

`markPrepared` does not change the epoch writer count.

Both `A` and `P` remain active writers until terminal release, so they both keep
their original epoch count non-zero.

Before A -> P, the atomic storage operation also requires:

- a valid immutable `:sync:owner` marker;
- a valid strict current `sync-v1` snapshot;
- the token's original epoch count to remain positive.

The current sync epoch may already have rotated; that does not rebind the token. The
original persisted epoch/targets remain authoritative.

If owner/sync state is missing, malformed, or unavailable at preparation confirmation,
the write remains pre-authoritative and the caller must not commit authoritative
membership. Coordination damage is never interpreted as permission to proceed.

## 5. M6-D057 — manual lease recovery never means force release

M6 v1 exposes recovery only when the authoritative outcome is positively known.

Allowed operator/application recovery:

~~~text
known COMMITTED + durable P -> release
known ABORTED/ROLLED_BACK + durable A or P -> release
unknown authoritative outcome -> keep lease active
~~~

A known committed outcome against an `A` record is **not** releasable.

That state means the package lacks durable proof that preparation completed before the
authoritative commit. Releasing it could permit a false negative. It is therefore a
protocol violation / insufficient-evidence condition and remains blocked.

There is no:

- `--force`;
- TTL override;
- "assume committed";
- "assume rolled back";
- delete-the-token recovery path.

The recovery action records no new correctness claim beyond the terminal `R` state.
The supplied authoritative outcome exists to decide whether release is permitted; it
does not rewrite Bloom membership.

## 6. M6-D058 — published candidate abort is a coordinated workflow, not ordinary discard

Before coordinated adoption, existing M5 `CandidateDiscarder` remains valid.

After adoption, ordinary `bloom:discard` is coordination-fenced.

For an adopted filter, a candidate that has ever been published as a writer target
cannot be directly retired merely because an operator wants to cancel the rebuild.

The safe requirement is:

> stop admitting new writers that target C, drain every old lease that may still
> target C, then retire/discard C.

Candidate abort therefore belongs to the M6 coordinator.

## 7. M6-D059 — unpublished coordinated candidates can be discarded control-only

A coordinated filter may own an unpublished candidate while sync remains:

~~~text
phase   = STEADY
targets = [A] or []
~~~

If C has never been published in `current_targets`, no coordinated writer lease can
depend on C.

Abort/discard is then a coordinated **control-only** mutation under the current sync
revision:

~~~text
candidate C -> RETIRED
candidate pointer -> none
sync remains STEADY
~~~

No epoch rotation is required.

This is the only adopted-filter discard case that does not require a writer drain.

## 8. M6-D060 — published abort adds ABORT_REQUESTED and DRAINING_ABORT phases

The final M6 v1 synchronization phases become:

~~~text
STEADY
DRAINING_PRE_RECONCILE
RECONCILING
READY_TO_PROMOTE
DRAINING_POST_PROMOTION
ABORT_REQUESTED
DRAINING_ABORT
~~~

`ABORT_REQUESTED` is a durable cancellation intent.

It exists because an abort may be requested while
`DRAINING_PRE_RECONCILE` already owns the single allowed `draining_epoch`.
M6 v1 cannot rotate again until that older drain reaches zero.

In `ABORT_REQUESTED`:

- control still owns candidate C;
- C is not ACTIVE;
- sync still names C as candidate;
- current targets still include C;
- an already-existing draining epoch may remain;
- new writers may temporarily continue A+C (or C-only for first activation) writes.

That continued write amplification is safe and preferable to creating two concurrent
draining epochs.

## 9. M6-D061 — abort target contraction happens before candidate discard

Once any pre-existing draining epoch reaches zero, abort rotates the current published
epoch away from C:

~~~text
before:
  phase = ABORT_REQUESTED
  current_epoch = E
  current_targets = [A,C] or [C]

atomic sync transition:
  current_epoch = E+1
  current_targets = [A] or []
  draining_epoch = E
  candidate_version = C
  phase = DRAINING_ABORT
~~~

After this transition:

- no new writer lease may include C;
- old epoch-E leases may still include C;
- C remains allocated and non-active until epoch E drains.

Only after:

~~~text
activeWriterCount(E) == 0
~~~

may coordinated control CAS retire C and clear the control candidate pointer.

Physical bitmap/meta deletion is not part of abort. C becomes RETIRED; storage cleanup
remains outside M6.

## 10. M6-D062 — DRAINING_ABORT has one explicit post-discard recovery shape

`DRAINING_ABORT` permits exactly two cross-plane shapes.

### Before candidate retirement

~~~text
control:
  active = A or none
  candidate = C
  C is not ACTIVE

sync:
  phase = DRAINING_ABORT
  targets = [A] or []
  candidate = C
  draining_epoch = old published epoch
~~~

### Candidate retired / sync finalization pending

~~~text
control:
  active = A or none
  candidate = none
  C = RETIRED

sync:
  phase = DRAINING_ABORT
  targets = [A] or []
  candidate = C
  draining_epoch = old published epoch
~~~

The second shape is valid only after the draining epoch count is zero.

Final sync CAS then clears:

~~~text
candidate_version
draining_epoch
~~~

and returns to:

~~~text
phase = STEADY
targets = current control active generation or []
~~~

A crash after control retirement but before final sync CAS is therefore recoverable
without resurrecting C.

## 11. M6-D063 — abort and promotion race through existing opposite-revision fencing

At `READY_TO_PROMOTE`, abort is legal only while control still has C as the candidate.

Abort begins with a sync mutation.
Promotion begins with a control mutation.

Both are opposite-revision fenced.

~~~text
abort wins first
-> sync revision changes
-> stale promotion conflicts

promotion wins first
-> control revision changes
-> stale abort conflicts
~~~

After reread:

- if C is still candidate, abort may proceed;
- if C is already ACTIVE and control candidate is cleared, abort is too late.

Once control promotion committed, the only legal recovery is to finish promotion
rotation and post-promotion drain.

M6 abort never performs an implicit rollback from newly ACTIVE C back to A.

A later rollback, if ever desired, is a separate versioned rebuild concern.

## 12. M6-D064 — brownfield adoption requires one explicit quiescent handoff

An existing unadopted M5 filter may have writers that never acquired M6 leases.

The package cannot retroactively observe them.

Therefore any adoption from an existing control state is brownfield and requires one
operator/application-owned quiescent handoff.

The exact rollout sequence is:

~~~text
1. deploy coordination-capable application code while still using M5
2. pause/queue membership-entry writes outside Bloom Gate
3. wait until every old uncoordinated membership-entry write is definitively complete
4. while the pause is still held, switch all participating writers to coordinated-v1
5. verify there is no current control candidate
6. run coordinated adoption
7. verify owner + valid STEADY sync-v1
8. resume membership-entry writes
~~~

The package does not own steps 2-4 and cannot prove that raw database writers obeyed
them.

The public adoption API/command therefore requires an explicit quiescent-handoff
acknowledgement for every existing control state.

A local flag is an operator assertion, not distributed writer discovery.

## 13. M6-D065 — brownfield adoption requires a stable no-candidate control shape

Brownfield adoption is rejected if the unadopted M5 control state has a current
candidate.

The operator must first finish or discard that candidate using valid M5 lifecycle
semantics while the filter is still unadopted.

Adoption may initialize from:

~~~text
active = A, candidate = none
or
active = none, candidate = none
~~~

Health does not have to be HEALTHY for adoption. Lifecycle and health remain separate
under ADR-0018.

The initialized sync target set is:

~~~text
[A] when control active = A
[]  when no active generation exists
~~~

A fresh filter with **no control state at all** may initialize coordinated ownership
without a brownfield quiescent acknowledgement, because there is no prior package
control history to hand off. Raw authoritative writers remain outside the package's
ADR-0010 guarantees.

## 14. M6-D066 — adoption remains owner-claim first, sync initialization second

The previously approved durable ordering is retained.

### Step 1 — ownership claim

Atomically require:

~~~text
expected control revision or required control absence
:sync:owner absent
:sync absent
~~~

then create immutable `:sync:owner`.

After this point ordinary M5 control mutation is permanently fenced for that logical
filter.

### Step 2 — initialize sync-v1

Under the still-required control revision/absence and valid owner marker, initialize:

~~~text
format = sync-v1
revision = 1
phase = STEADY
current_epoch = 1
current_targets = current active generation or []
candidate_version = absent
draining_epoch = absent
~~~

The one-time quiescent handoff must remain held until this step succeeds for
brownfield adoption.

## 15. M6-D067 — ADOPTION_PENDING is resumable and never reopens M5 mutation

Crash shape:

~~~text
:sync:owner exists
:sync missing
~~~

means:

> ADOPTION_PENDING

It never means "not adopted".

During ADOPTION_PENDING:

- ordinary M5 lifecycle mutation remains fenced;
- coordinated writer acquisition fails closed;
- coordinated rebuild start is blocked;
- the adoption initialization operation may be retried idempotently;
- brownfield operators must continue holding the external write handoff until valid
  sync-v1 exists.

If owner + valid STEADY sync already exist when an adoption retry arrives, adoption
returns idempotent already-adopted success after validating the cross-plane relation.

Malformed owner/sync state fails closed.

## 16. M6-D068 — coordinated adoption is one-way in M6 v1

M6 v1 has no dynamic de-adoption.

There is no supported transition:

~~~text
ADOPTED -> ordinary mutable M5 ownership
~~~

Reasons:

- `:sync:owner` is intentionally immutable and one-way;
- old unleased writers cannot be proven absent merely by changing runtime config;
- reopening ordinary M5 CAS would weaken the lifecycle-ownership fence;
- safe reverse migration would require its own externally fenced protocol.

Consequences:

- turning local coordinated configuration off does not re-enable M5 mutation;
- `bloom:build`, `bloom:verify`, `bloom:activate`, and `bloom:discard` remain
  fenced for adopted filters;
- query optimization may still be independently disabled/fail-open;
- runtime configuration that contradicts durable adoption is a status/doctor failure;
- there is no `bloom:coordinate:disable` command in M6 v1.

An external restore that erases both owner and sync history is outside the protocol.
Before coordinated guarantees are claimed again, a new explicit adoption handoff is
required.

## 17. M6-D069 — public management API is Application-owned, not store-owned

Correctness-sensitive storage primitives are not the public product API.

The public framework-neutral Application boundary consists conceptually of:

~~~php
CoordinatedFilterAdopter::adopt(
    FilterName $name,
    AdoptionHandoff $handoff,
): AdoptionResult;

OnlineRebuildCoordinator::advance(
    FilterName $name,
): RebuildProgress;

OnlineRebuildCoordinator::abort(
    FilterName $name,
): RebuildProgress;

CoordinatedLeaseRecovery::resolve(
    FilterName $name,
    WriterLeaseToken $token,
    AuthoritativeOutcome $outcome,
): LeaseResolutionResult;
~~~

The already-approved coordinated writer primitive remains explicit
`prepare(...)` + authoritative completion.

Exact class/result names may be refined during implementation planning, but these
ownership boundaries are locked:

- Application decides legal adoption/rebuild/abort/recovery workflow;
- Lifecycle computes lifecycle legality;
- Contracts expose persistence ports;
- Redis/Memory enforce atomic persisted semantics;
- Laravel commands are thin adapters;
- no public API exposes raw revision CAS as the normal operator workflow.

## 18. M6-D070 — Laravel command surface is intentionally small

M6 v1 adds these management commands:

~~~text
bloom:coordinate:adopt <filter> [--quiescent]
bloom:rebuild <filter> [--wait=<seconds>]
bloom:rebuild:abort <filter> [--wait=<seconds>]
bloom:lease:resolve <filter> <token> --outcome=committed|aborted
~~~

Existing commands remain:

~~~text
bloom:status
bloom:doctor
bloom:build
bloom:verify
bloom:activate
bloom:discard
~~~

Rules:

- `bloom:coordinate:adopt` requires `--quiescent` when control state already exists;
- `bloom:rebuild` starts or resumes the same coordinated rebuild;
- there is no separate resume command;
- `bloom:rebuild:abort` starts or resumes abort;
- `bloom:lease:resolve` never accepts `unknown` or `force`;
- M5 mutation commands fail loudly for adopted filters;
- read-only status/doctor remain valid for both adopted and unadopted filters.

The M6 command surface does not expose low-level epoch rotation, candidate publication,
promotion CAS, or owner-marker mutation.

## 19. M6-D071 — rebuild and abort commands are restart-safe and bounded by wait budget

Application orchestration itself does not depend on sleeping until a drain completes.

`advance()` and `abort()` return typed progress such as:

~~~text
ADVANCED
BLOCKED
COMPLETED
RECOVERY_REQUIRED
~~~

Exact enum/type naming may be refined, but blocked vs failed vs completed must remain
distinguishable.

The Laravel commands may poll while a positive `--wait=<seconds>` budget remains.

`--wait=0` means:

> make deterministic progress until the first durable wait condition, report it, and
> return without sleeping.

A wait-budget timeout:

- does not expire leases;
- does not release tokens;
- does not skip drains;
- does not weaken revision fencing;
- leaves durable state resumable.

Command interruption is therefore an availability event, not a correctness event.
Rerunning the same command rereads the durable pair and resumes.

If abort is already durable, `bloom:rebuild` must not silently reverse it.
If promotion already committed, `bloom:rebuild:abort` must report that abort is no
longer legal and promotion recovery must finish.

## 20. M6-D072 — status exposes coordination state without becoming correctness logic

`bloom:status` is extended with a coordination section when relevant.

It must be able to represent at least:

~~~text
ownership:
  UNADOPTED
  ADOPTION_PENDING
  ADOPTED
  INVALID

sync revision
sync phase
current epoch
current targets
candidate version
draining epoch
draining active-writer count
blocker/recovery classification
~~~

For lease recovery, status gains an explicit diagnostic mode:

~~~text
bloom:status <filter> --leases
~~~

It may enumerate active `A` and `P` tokens with their persisted epoch/targets and
preparation state.

Released tombstones are not dumped by default.

Diagnostic enumeration may be bounded/paginated during implementation, but it is
never used to prove a drain. Drain correctness continues to use the persisted
per-epoch active count only.

The original authoritative-outcome-uncertain error/result must continue surfacing the
stable token so the operator does not depend solely on enumeration.

## 21. M6-D073 — doctor treats durable ownership/config disagreement as unsafe operation

`bloom:doctor` remains read-only.

For coordinated filters it must detect at least:

- runtime coordinated-v1 expectation with missing owner/sync;
- owner present with missing sync;
- sync present without a valid owner marker;
- malformed sync or lease/count structures;
- durable adoption while runtime configuration attempts legacy mode;
- impossible control↔sync relation;
- active drain blocked by writer count.

The doctor does not repair these states.

Query fail-open behavior remains separate from lifecycle/write mutation failure.

## 22. M6-D074 — lease recovery command uses durable preparation evidence

`bloom:lease:resolve` semantics are:

### outcome=committed

Allowed only for:

~~~text
P -> R
R -> idempotent already released
~~~

For `A`, resolution is rejected because durable preparation proof is missing.

### outcome=aborted

Allowed for:

~~~text
A -> R
P -> R
R -> idempotent already released
~~~

but only after the application/operator positively establishes that the authoritative
mutation cannot become visible.

### unknown outcome

No resolution operation exists.

The lease remains active.

This command never replays the authoritative mutation and never writes Bloom values.

## 23. Redis/Memory operation refinements required by this gate

No implementation starts here, but the eventual storage contract must support:

### markPrepared

~~~text
keys:
  :sync:owner
  :sync
  :sync:leases
  :sync:counts

conditions:
  valid immutable owner marker
  valid strict sync-v1
  A/P token has a positive original epoch count

A -> P
P -> P
R -> terminal failure
count unchanged
~~~

### diagnostic active-lease read

Read-only inspection may use the lease registry.

It is not part of the drain barrier.

### abort transitions

Abort phase changes use existing coordinated sync CAS under expected control revision.

Candidate retirement uses existing coordinated control CAS under expected sync
revision.

No new generic dual-plane durable write is introduced.

### adoption

Owner claim and sync initialization retain the already-approved two-step
interruption-safe ordering.

## 24. Cross-race proofs

### markPrepared vs epoch rotation

The lease was already acquired and counted in its original epoch.

Therefore rotation may admission-close that epoch while markPrepared is pending, but
the old epoch cannot drain to zero until the same token releases.

The token retains its original targets.

### abort vs old-epoch drain

If abort is requested while `DRAINING_PRE_RECONCILE` already owns a draining epoch,
`ABORT_REQUESTED` records intent without creating a second drain.

Only after the existing drain reaches zero may target contraction rotate the published
epoch into `DRAINING_ABORT`.

### abort vs promotion

Opposite-revision fencing makes one transition win.

No state permits C to be both removed from new writer targets and newly promoted by a
stale concurrent command.

### candidate retirement vs old C-targeting writer

C is retired only after the admission-closed published epoch count reaches zero.

Therefore no conforming active writer lease can still require C when control retires
it.

### legacy M5 mutation vs adoption retry

Once owner claim succeeds, ordinary control CAS remains atomically fenced even if sync
initialization has not completed.

Retry cannot reopen the legacy path.

## 25. Required invariants added/refined by this gate

- INV-M6-055: durable PREPARED state proves all lease-bound Bloom pre-adds completed before authoritative visibility is permitted.
- INV-M6-056: lease records use A/P/R semantics; both A and P count as active writers until terminal release.
- INV-M6-057: markPrepared is token-idempotent, never changes epoch binding or writer count, and fails closed unless durable coordinated ownership plus valid sync-v1 still exist.
- INV-M6-058: a known committed outcome can be manually released only from durable P; A is insufficient evidence.
- INV-M6-059: unknown authoritative outcome is never force-released.
- INV-M6-060: an adopted published candidate cannot be retired before new writer admission drops C and every old C-targeting lease drains.
- INV-M6-061: ABORT_REQUESTED durably records cancellation when an existing draining epoch prevents immediate target contraction.
- INV-M6-062: DRAINING_ABORT removes C from new writer targets before candidate retirement.
- INV-M6-063: DRAINING_ABORT permits the explicit candidate-retired/sync-finalization-pending recovery shape.
- INV-M6-064: once control promotion commits, abort cannot roll C back to A.
- INV-M6-065: any existing control state requires one explicit brownfield quiescent handoff before coordinated adoption.
- INV-M6-066: brownfield adoption requires no current control candidate.
- INV-M6-067: owner claim precedes sync initialization and ADOPTION_PENDING never re-enables M5 mutation.
- INV-M6-068: coordinated ownership is one-way in M6 v1; runtime config drift cannot de-adopt a filter.
- INV-M6-069: public management workflows are Application-owned; low-level revision CAS is not the product API.
- INV-M6-070: rebuild/abort management is durable, resumable, and wait timeout never weakens correctness.
- INV-M6-071: status/lease enumeration is diagnostic only and never substitutes for persisted epoch-count drain proof.
- INV-M6-072: no M6 v1 public command provides force release, force promotion, force abort after promotion, or coordinated disable.

## 26. Explicit non-goals preserved

This gate does not add:

- active-active coordinator election;
- background worker ownership;
- scheduled rebuilds;
- automatic lease repair;
- database transaction introspection;
- database commit-log inspection;
- a generic rollback-to-previous-active command;
- de-adoption;
- deletion-aware Bloom semantics;
- data-plane garbage collection;
- token tombstone compaction;
- topology/failover attestation.

## 27. Explicit self-review

### Scope alignment — PASS

The gate resolves exactly the remaining M6 recovery, adoption, and public-surface
blockers left by the landed fencing gate.

The PREPARED refinement is necessary only because authoritative-outcome recovery would
otherwise permit an unsafe release with insufficient evidence.

It does not add a new product capability beyond coordinated online rebuild.

### Existing ADR/invariant consistency — PASS

The design preserves:

- ADR-0007 fail-open query behavior;
- ADR-0008 explicit lifecycle;
- ADR-0009 versioned rebuild;
- ADR-0010 synchronization as an explicit contract;
- ADR-0018 lifecycle/health separation;
- strict control-v1;
- M5 query authorization;
- no Bloom delete semantics;
- no package-owned DB transaction;
- no correctness TTL;
- same-filter Redis hash-tag construction;
- application-owned lifecycle policy.

### Dependency direction — PASS

Application owns adoption/rebuild/abort/recovery policy.

Contracts expose atomic persistence capabilities only.

Memory and Redis reproduce the same observable semantics.

Laravel remains an adapter around Application services.

### Unnecessary complexity — PASS

The gate adds only what the unresolved recovery semantics require:

- one PREPARED lease state;
- one mark-prepared operation;
- two abort phases;
- one adoption command;
- one rebuild command;
- one abort command;
- one explicit lease-recovery command;
- extensions to existing status/doctor.

It rejects force paths, background ownership, a second draining epoch, de-adoption,
generic dual-plane writes, and physical cleanup.

### Backward / greenfield / brownfield safety — PASS

Unadopted M5 filters remain unchanged.

Fresh no-control filters may adopt before managed writes begin.

Existing control state requires an explicit quiescent handoff.

Once adoption begins, legacy mutation cannot silently reappear after a crash.

### Interruption and race safety — PASS

All newly defined multi-step workflows have safe durable restart shapes:

- A -> P preparation retry;
- owner-only ADOPTION_PENDING;
- ABORT_REQUESTED;
- DRAINING_ABORT before and after candidate retirement;
- existing READY_TO_PROMOTE post-control-promotion recovery;
- existing DRAINING_POST_PROMOTION.

### Verification evidence — NOT APPLICABLE YET

This is a docs-only design gate.

No runtime capability is claimed and no implementation is authorized.

## 28. Gate result

**Recovery + brownfield adoption + public API boundary: candidate PASS for external design review.**

**M6 implementation remains NOT AUTHORIZED.**

If this gate survives external review, the next safe step is:

> synchronize the accepted M6 architecture into ADR(s), then write the implementation
> task breakdown + verification matrix.

Implementation starts only after those documents close the design phase.
