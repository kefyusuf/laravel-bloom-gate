# M6 — Writer Lease API + sync-v1 Persistence Gate

**Status:** DRAFT — design-only sub-gate  
**Baseline:** main@35b3d8fc27f2980bbe99c07d92388151e4b6befa  
**Implementation authorized:** NO

This document continues the approved M6 scope without starting runtime implementation.

It resolves the first cohesive subset of the next M6 design gate:

> explicit writer lifetime + retry-safe lease tokens + framework-neutral synchronization storage + Redis `sync-v1` lease atomicity.

It deliberately does **not** yet authorize coordinated rebuild implementation. Cross-plane control/sync fencing remains a hard blocker.

## 1. Baseline constraints

The landed M6 scope already requires:

- existing `preadd-v1` membership semantics remain unchanged;
- coordinated mode is opt-in;
- a writer lease spans authoritative commit/rollback completion;
- legacy M5 `add/addMany` cannot masquerade as coordinated writes;
- synchronization state is persistent and framework-neutral;
- lease acquisition atomically captures token + epoch + exact targets;
- RELEASED lease tokens are terminal;
- active leases do not auto-expire;
- coordinated-write uncertainty fails closed;
- post-commit lease-release uncertainty is not an authoritative-write failure;
- `control-v1` remains strict and schema-compatible;
- M5 query safety remains unchanged.

## 2. Gate scope

This sub-gate resolves:

1. canonical public coordinated-writer primitive;
2. token ownership and retry identity;
3. prepared-write lifetime semantics;
4. framework-neutral synchronization storage boundary;
5. minimum `sync-v1` persisted state;
6. Redis lease registry and per-epoch writer counts;
7. atomic acquire/release semantics;
8. released-token persistence policy for M6 v1;
9. drain observation semantics.

This sub-gate does **not** yet resolve:

- exact atomic control↔sync fencing for candidate publication;
- coordinated promotion persistence;
- coordinated candidate abort/discard;
- brownfield adoption command/API;
- online rebuild command surface;
- coordinated-mode disable/de-adoption;
- implementation task breakdown.

Those remain required before implementation.

## 3. M6-D023 — explicit prepared write is the canonical public primitive

M6 v1 uses an explicit prepared-write lifetime.

Conceptually:

~~~php
$token = WriterLeaseToken::generate();

$prepared = $coordinatedWrites->prepare(
    filter: 'users.email',
    token: $token,
    values: [$email],
);

try {
    // Application-owned authoritative transaction.
    // The package does not begin or commit it.
    $authoritativeTransaction();
} catch (Throwable $failure) {
    $prepared->authoritativeAborted();

    throw $failure;
}

// Reached only after the application knows the authoritative commit completed.
$completion = $prepared->authoritativeCommitted();
~~~

The exact Laravel convenience surface may wrap this later, but the correctness primitive is explicit.

M6 v1 does **not** make a callback API canonical.

Reason:

- callback scope can be mistaken for transaction ownership;
- an application may commit outside the callback boundary;
- implicit auto-release would recreate the exact lifetime ambiguity M6 is intended to remove.

A future callback helper is allowed only if it is a thin convenience over the same prepared-write protocol and cannot release before authoritative completion.

### Prepared-write guarantees

`prepare(...)` returns only after:

1. a writer token is atomically bound to one synchronization epoch and exact target set;
2. runtime filter semantics are validated for those targets;
3. every required target has been Bloom-pre-added successfully.

Only then may the caller make authoritative membership visible.

If preparation cannot prove those steps, it fails before authoritative commit and the caller must not commit the membership entry.

Partial Bloom writes are acceptable because they create false positives only.

### Failed preparation and explicit abandonment

A failed `prepare(...)` must preserve the caller's original token in its typed failure/result context.

If acquisition may already have succeeded, the package must not invent a new token for retry.

The caller has two correctness-safe choices:

~~~text
retry prepare with the same token
or
explicitly abandon the pre-authoritative attempt
~~~

Retrying `prepare(...)` with the same ACTIVE token reuses the original epoch/targets and may safely repeat idempotent Bloom pre-add work.

An explicit abandonment operation is allowed only while the caller knows no authoritative membership mutation became visible. It releases the token through the same terminal ACTIVE -> RELEASED transition.

Conceptually:

~~~php
$coordinatedWrites->abandon(
    filter: 'users.email',
    token: $token,
);
~~~

If abandonment cleanup is uncertain, the lease remains conservatively active and may block cutover. The package must not treat uncertainty as proof that cleanup happened.

A failed preparation must never auto-release in a destructor or silently rotate to a replacement token.

## 4. M6-D024 — token exists before the first remote operation

The caller/package must possess the stable lease token before acquisition is attempted.

The canonical token representation for M6 v1 is:

~~~text
32 lowercase hexadecimal characters
~~~

representing 128 random bits.

Properties:

- opaque;
- immutable;
- safe ASCII;
- generated before the first Redis/Memory coordinator call;
- reused unchanged for acquisition retry;
- never rebound after RELEASED;
- collision with incompatible persisted state is a loud error.

The package may provide a token factory, but the token itself is a Core value and is not a Redis/Laravel identifier.

A transport exception must never force generation of a replacement token for the same logical prepare attempt.

## 5. M6-D025 — no destructor/TTL/implicit completion

A prepared write is not completed by:

- object destruction;
- request shutdown;
- PHP process exit;
- lease TTL;
- garbage collection;
- timeout.

Only an explicit authoritative outcome may close it:

~~~text
authoritativeCommitted()
authoritativeAborted()
~~~

A leaked lease remains visible and may block cutover.

This is intentional fail-safe behavior.

## 6. M6-D026 — committed and aborted completion share lease release, but not result meaning

Both authoritative outcomes eventually request the same synchronization transition:

~~~text
ACTIVE lease token
-> RELEASED terminal token
~~~

But caller-facing meaning differs.

### After authoritative abort/rollback

If release is uncertain, no authoritative membership was committed.

The result may report cleanup uncertainty, and the stale active lease may conservatively block future cutover.

### After authoritative commit

If release is uncertain:

- the authoritative write remains committed;
- the API must not report or imply that the database write failed;
- callers must not be encouraged to replay the authoritative mutation blindly;
- cleanup uncertainty must be surfaced separately.

Conceptually:

~~~text
CompletionResult::Released
CompletionResult::CleanupUncertain
~~~

This distinction is mandatory even if the implementation later chooses typed result objects rather than an enum.

## 7. M6-D027 — one framework-neutral synchronization storage boundary

The storage boundary is conceptually:

~~~php
interface SynchronizationStore
{
    public function read(FilterName $name): ?SynchronizationState;

    public function acquire(
        FilterName $name,
        WriterLeaseToken $token,
    ): WriterLease;

    public function release(
        FilterName $name,
        WriterLeaseToken $token,
    ): LeaseReleaseResult;

    public function compareAndSwap(
        FilterName $name,
        SynchronizationState $next,
        ?SynchronizationRevision $expectedRevision,
    ): void;

    public function activeWriterCount(
        FilterName $name,
        SynchronizationEpoch $epoch,
    ): int;
}
~~~

Names may be refined during implementation planning, but the ownership boundary is locked:

- Core owns immutable synchronization value objects;
- Contracts owns the storage port;
- Memory is the reference implementation;
- Redis is the production implementation;
- Application owns legal workflow/state-machine policy;
- Drivers do not decide lifecycle legality;
- Laravel remains an adapter.

`acquire()` is not implemented as `read() + compareAndSwap()` in callers.

It is a dedicated atomic store operation because token registration and epoch/target capture are one correctness action.

## 8. M6-D028 — minimum synchronization state

The persisted logical synchronization snapshot contains:

~~~text
format                sync-v1
revision              positive monotonic integer
phase
current_epoch         positive monotonic integer
current_targets
candidate_version     optional
draining_epoch        optional
~~~

M6 v1 phases required by the online-rebuild protocol are:

~~~text
STEADY
DRAINING_PRE_RECONCILE
RECONCILING
READY_TO_PROMOTE
DRAINING_POST_PROMOTION
~~~

The state machine may later add explicit abort states before implementation is authorized.

No implementation may infer phase solely from active/candidate lifecycle pointers.

### Target encoding

A target set is an ordered unique set of generation versions.

Canonical textual encoding:

~~~text
-       no targets
1       one target
1,2     multiple targets
~~~

Rules:

- positive canonical base-10 integers only;
- strictly ascending;
- no duplicates;
- no whitespace;
- no leading zeroes;
- `-` is the only empty-set representation.

The order is canonical storage identity, not write priority.

## 9. M6-D029 — Redis keyspace

M6 v1 reserves three same-filter keys:

~~~text
<prefix>:{<filter-name>}:sync
<prefix>:{<filter-name>}:sync:leases
<prefix>:{<filter-name>}:sync:counts
~~~

All preserve the existing exact `{<filter-name>}` hash tag.

This is same-slot-compatible construction only.

It is not a Redis Cluster runtime-support claim.

### `:sync`

Strict Redis HASH containing the current `sync-v1` snapshot.

Unknown fields are corruption.

No TTL.

### `:sync:leases`

Redis HASH:

~~~text
field = <32-lower-hex token>
value = <lease record>
~~~

Canonical lease records:

~~~text
A|<epoch>|<targets>
R|<epoch>|<targets>
~~~

where:

- `A` = ACTIVE;
- `R` = RELEASED terminal;
- epoch and targets preserve the original binding.

The RELEASED record intentionally retains the old epoch/targets so delayed retries can be identified as terminal rather than rebound.

### `:sync:counts`

Redis HASH with canonical fields:

~~~text
e:<epoch> = <active-writer-count>
~~~

Counts are non-negative canonical decimal integers.

Zero count fields may remain.

No TTL.

The count is correctness state used by the drain barrier; it is not a cache.

## 10. M6-D030 — acquire is one Redis atomic operation

Redis acquire uses one application-owned Lua/EVAL operation over:

- `:sync`;
- `:sync:leases`;
- `:sync:counts`.

The script validates key types and the strict current synchronization snapshot before mutation.

### New token

For a token with no record:

1. read the current `current_epoch` and `current_targets`;
2. write `A|epoch|targets` to the lease registry;
3. increment `e:<epoch>`;
4. return the exact captured binding.

No observable state may exist where the token is registered without the exact binding returned to the caller.

### Existing ACTIVE token

Acquire retry returns the original persisted epoch/targets.

It does not increment the writer count again.

It does not bind to the current epoch if the coordinator has since rotated.

### Existing RELEASED token

Acquire fails with a terminal-token result.

It never returns a write-capable lease.

It never increments any count.

It never rebinds the token.

### Missing/uninitialized sync state

Coordinated acquisition fails loudly.

It does not silently fall back to legacy M5 active-only behavior.

## 11. M6-D031 — release is one Redis atomic operation

Redis release uses one Lua/EVAL operation over:

- `:sync:leases`;
- `:sync:counts`.

For ACTIVE:

1. parse the persisted original epoch/targets;
2. require `e:<epoch> > 0`;
3. replace `A|epoch|targets` with `R|epoch|targets`;
4. decrement exactly that epoch count.

For RELEASED:

- return idempotent success;
- do not decrement again.

For an unknown token:

- fail loudly as unknown;
- do not manufacture a RELEASED tombstone;
- do not alter counts.

Count underflow, malformed lease records, wrong Redis types, or malformed counters are corruption.

Transport failure remains distinct from storage corruption.

## 12. M6-D032 — drain completion uses the persisted epoch count

A draining epoch is complete only when:

~~~text
activeWriterCount(filter, drainingEpoch) == 0
~~~

The package does not infer drain by:

- sleeping;
- lease age;
- process-local counters;
- scanning application requests;
- assuming a transaction timeout;
- expiring lease keys.

If count state is unavailable or corrupt, drain is not proven.

The rebuild remains blocked.

## 13. M6-D033 — RELEASED tombstones are not compacted in M6 v1

M6 v1 deliberately keeps released lease records.

There is:

- no TTL;
- no automatic delete;
- no bounded tombstone compaction;
- no token reuse.

Reason:

with token-only idempotency, deleting a RELEASED record would make a sufficiently delayed acquire retry indistinguishable from a new token and could resurrect stale write authority.

A safe bounded compaction protocol would require additional fencing identity beyond the currently locked token contract.

That is a future milestone concern.

This is consistent with the already excluded automatic retention/purge scope.

## 14. M6-D034 — lease storage is not the rebuild state machine

Lease registration answers only:

> Which epoch/targets was this writer bound to, and is it still active?

The `:sync` snapshot answers:

> Which coordination phase/epoch/targets currently govern the filter?

Application orchestration owns transitions between synchronization phases.

Redis Lua enforces storage atomicity and persisted-shape invariants only.

It must not invent rebuild policy.

## 15. Hard blocker discovered by this gate: control↔sync cross-plane fencing

The current M5 control store and the new synchronization store are separate correctness planes.

A naïve sequence such as:

~~~text
read control revision R
validate candidate C
write sync state publishing C
~~~

is insufficient if a concurrent lifecycle operation can still successfully mutate `control-v1` using R without observing the newly opened sync session.

Similarly, a client-side:

~~~text
check sync
then
control compare-and-swap
~~~

contains a TOCTOU window.

Therefore M6 implementation remains unauthorized until a dedicated next sub-gate defines exact atomic fencing for at least:

- coordinated-session adoption/open;
- candidate publication;
- candidate discard/abort;
- promotion;
- post-promotion steady rotation;
- stale legacy lifecycle operations that began before session publication.

The solution must preserve:

- strict `control-v1` schema;
- lifecycle policy outside Redis;
- framework-neutral Application semantics;
- no silent lifecycle bypass.

Same-filter Redis hash-tag colocation makes an atomic multi-key Lua solution technically possible, but the framework-neutral contract and Memory reference semantics must be designed before code begins.

## 16. Required invariants added by this gate

- INV-M6-023: the canonical coordinated writer primitive exposes explicit authoritative completion.
- INV-M6-024: the lease token exists before acquisition and is reused under retry ambiguity.
- INV-M6-025: prepared writes never auto-release from destructors, process shutdown, timeout, or TTL.
- INV-M6-026: `prepare()` returns only after all lease-bound Bloom targets are pre-added.
- INV-M6-026A: failed preparation preserves the original token; retry reuses it, while explicit abandonment is permitted only before authoritative visibility.
- INV-M6-027: acquire atomically binds token + current epoch + exact target set.
- INV-M6-028: acquire retry for ACTIVE returns the original binding without double-counting.
- INV-M6-029: RELEASED is terminal and cannot reacquire write authority.
- INV-M6-030: release decrements exactly one original epoch count at most once.
- INV-M6-031: drain is proven only by the persisted active count reaching zero.
- INV-M6-032: RELEASED tombstones are retained in M6 v1; no compaction may permit stale-token resurrection.
- INV-M6-033: Redis lease/count/sync keys have no correctness TTL.
- INV-M6-034: Redis scripts enforce atomic storage semantics, not rebuild policy.
- INV-M6-035: independent client-side control/sync checks are insufficient for cross-plane lifecycle fencing.

## 17. Explicit self-review

### Scope alignment — PASS

This sub-gate stays inside the landed M6 capability and addresses the minimum writer-lifetime and persistent lease machinery needed for online rebuild.

### Existing invariant/ADR consistency — PASS

It preserves:

- M5 `preadd-v1`;
- strict `control-v1`;
- no DB transaction ownership;
- application-owned lifecycle policy;
- Redis EVAL for atomic semantic operations;
- no TTL correctness model;
- same-filter hash-tag construction.

### Dependency direction — PASS

The proposed storage boundary remains framework-neutral.

Laravel does not enter Core, Contracts, Lifecycle, Application, or Drivers policy.

### Unnecessary complexity — PASS

M6 v1 chooses:

- one explicit prepared-write primitive;
- one synchronization store;
- three Redis keys;
- one active-count mechanism;
- persistent terminal tombstones.

It rejects callback magic, TTL leases, implicit release, scanning-based drain, and tombstone compaction.

### Brownfield safety — PASS, still gated

Nothing in this document changes existing M5 behavior.

Brownfield adoption remains a separate explicit fence and is not silently inferred.

### Race safety — INCOMPLETE BY DESIGN

Lease acquire/release atomicity is resolved.

Control↔sync lifecycle fencing is not.

That gap blocks implementation rather than being hand-waved.

### Verification evidence — NOT APPLICABLE YET

This is a docs-only design gate.

No runtime capability is claimed.

## 18. Gate result

**Writer lease + sync-v1 persistence foundation: candidate PASS.**

**M6 implementation remains NOT AUTHORIZED.**

The next safe sub-gate is:

> atomic control↔sync fencing + coordinated lifecycle transition ownership.

Only after that gate closes should the project finalize abort/discard recovery, public rebuild commands, and the M6 implementation task sequence.
