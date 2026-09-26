# M6 — Coordinated Online Rebuild: Scope and Design Gate

**Status:** PROPOSED — scope/design only  
**Baseline:** `main@333040e1fc9a5e30d0c6f547d069604ce66c481e`  
**Implementation authorized:** **NO**

M5 is complete and merged. M6 must not become a bucket for every capability intentionally deferred by M5.

This gate narrows M6 to one product problem:

> remove the long quiescent membership-entry window required by M5 `preadd-v1` rebuild activation, while preserving authoritative-correct trusted negatives.

The design must remain safe for Laravel applications with concurrent package-coordinated membership-entry writes. It must not claim safety for writers that do not participate in the coordination protocol.

---

## 1. Why M6 cannot simply dual-write the current `preadd-v1`

M5 `preadd-v1` guarantees only:

```text
Bloom add
before
authoritative membership becomes visible
```

The existing `MembershipAdder` returns after the Bloom mutation. The package does not know when the caller's authoritative transaction later commits or aborts.

That creates a critical enrollment race:

```text
writer W:
    Bloom add to ACTIVE only
    ... authoritative transaction still open ...

rebuild:
    enroll CANDIDATE for dual write
    begin authoritative baseline scan

writer W:
    authoritative commit
```

If the candidate baseline scan already passed the relevant membership and W's Bloom add happened before candidate enrollment, the candidate can miss a value that later becomes authoritative-present.

Therefore M6 must not silently reinterpret existing `preadd-v1` as online-rebuild-safe.

M5 `preadd-v1` remains source-compatible and keeps its quiescent activation contract.

---

## 2. M6 product scope

### In scope

M6 is **Coordinated Online Rebuild** for additive membership.

It may introduce:

1. a new explicit coordinated pre-add consistency contract;
2. a framework-neutral writer-session / writer-ticket protocol;
3. a framework-neutral online-rebuild coordination state model;
4. an additive coordination persistence port;
5. an additive Redis `sync-v1` coordination keyspace;
6. Memory reference behavior for the same protocol;
7. candidate enrollment for live synchronization;
8. a drain barrier for writers that started before candidate enrollment;
9. managed writes that route to the current active generation and an enrolled candidate;
10. candidate-only routing during first-generation bootstrap;
11. online baseline build after pre-enrollment writers drain;
12. online verification while coordinated writes continue;
13. fail-safe promotion while synchronization remains active;
14. post-promotion synchronization cleanup;
15. status/diagnostic visibility for coordination state.

### Out of scope

The following remain M7+ or separately gated work:

- Redis Sentinel runtime support;
- Redis Cluster runtime support;
- replica trusted negatives;
- backend/failover epoch attestation;
- CDC adapters;
- outbox adapters;
- arbitrary external-writer coordination;
- cross-service writer discovery;
- automatic retention/purge;
- automatic rollback;
- delete/removal synchronization;
- package-owned database transactions;
- transaction-wrapper convenience APIs;
- background rebuild scheduling;
- topology monitoring;
- native RedisBloom replacement;
- arbitrary custom consistency protocols.

M6 must not claim that external writers are safe merely because package-managed writers are coordinated.

---

## 3. Locked compatibility boundary

M6 must preserve all existing M0–M5 behavior.

In particular:

- M2/M3 low-level `BloomDriver` remains source-compatible;
- `BulkBloomDriver` remains additive;
- M3 generation metadata remains valid;
- M4 strict `control-v1` remains unchanged;
- M4 lifecycle/health remain separate axes;
- M5 semantic fingerprint rules remain unchanged;
- M5 `immutable-v1` remains unchanged;
- M5 `preadd-v1` remains unchanged;
- M5 `QueryGate` trusted-negative rules remain unchanged;
- M5 Redis authorized probe remains revision/version/semantic/layout pinned;
- package discovery remains side-effect free.

A new M6 capability must be additive.

---

## 4. Decision M6-D001 — M6 is not Sentinel/Cluster hardening

M6's primary goal is application-write coordination for online rebuild.

Redis Sentinel, Redis Cluster, replica reads, and backend epoch/failover attestation solve a different problem: whether backend identity/topology remains safe for trusted negatives across failover.

**Decision:** M6 excludes Sentinel/Cluster/failover support.

The M5 Redis production profile remains the runtime baseline while M6 online-rebuild coordination is designed.

---

## 5. Decision M6-D002 — existing `preadd-v1` is not upgraded in place

Existing applications may already rely on the exact M5 contract.

Changing that semantic contract in place would make an existing consistency fingerprint mean something new.

**Decision:** online coordination uses a new explicit consistency identity.

The exact token/name is intentionally not locked by this scope gate.

Conceptually it means:

> every membership-entry mutation participating in trusted-negative online-rebuild safety is represented by a package-coordinated writer session whose lifetime includes the authoritative commit/abort boundary.

A filter cannot claim M6 online-rebuild safety while some relevant writers continue using uncoordinated M5 `preadd-v1` semantics.

---

## 6. Decision M6-D003 — coordination is separate from strict `control-v1`

M4 `control-v1` is intentionally strict.

Adding M6 fields such as writer epoch, synchronization mode, target version, or outstanding writer state directly to `control-v1` would make old strict readers treat the state as corruption and would conflate lifecycle ownership with writer coordination.

**Decision:** M6 coordination uses a separate framework-neutral state model and persistence port.

Redis persistence will conceptually use a same-filter key such as:

```text
<prefix>:{<filter-name>}:sync
```

with a versioned format:

```text
sync-v1
```

The exact schema is not yet approved.

Consequences:

- M4 `FilterControlStore` remains unchanged.
- `control-v1` remains strict.
- M6 coordination evolves independently.
- Same-filter keys remain same-slot by construction.
- Cluster-aware key construction still does not imply Cluster runtime support.

---

## 7. Decision M6-D004 — writer coordination spans authoritative completion

Tracking only the Bloom write is insufficient.

A pre-enrollment writer can mutate the old active Bloom generation and commit its authoritative transaction after candidate enrollment.

The coordination protocol therefore needs a logical writer session:

```text
acquire coordinated writer session
    ->
perform required Bloom routing
    ->
perform caller-owned authoritative transaction
    ->
authoritative commit OR abort
    ->
release coordinated writer session
```

The package still does **not** own the authoritative transaction.

The application remains responsible for ensuring Bloom synchronization precedes authoritative membership commit, but M6 gains enough coordination visibility to know when all writers from an earlier synchronization epoch have completed.

**Safety rule:** a writer session must not be considered complete before the authoritative transaction has committed or aborted.

---

## 8. Decision M6-D005 — candidate enrollment precedes baseline scan

The safe online rebuild order is:

```text
allocate candidate
    ->
provision candidate layout
    ->
bind candidate semantic contract
    ->
publish candidate as coordinated synchronization target
    ->
advance writer epoch
    ->
drain writers from the pre-enrollment epoch
    ->
begin complete authoritative baseline scan
    ->
continue coordinated writes during scan
    ->
finish candidate build
```

The drain is mandatory because writers created before enrollment may have synchronized only the old active generation while their authoritative transactions are still open.

After the pre-enrollment epoch drains, every membership entry that can become newly authoritative-visible is either:

1. already present before the baseline scan begins; or
2. written through the coordinated target path.

This relies on the existing v1 rule that delete/removal semantics are not supported.

---

## 9. Decision M6-D006 — enrollment requires provisioned and semantically bound storage

Candidate routing cannot begin before the candidate has:

- an allocated version;
- valid storage;
- exact layout metadata;
- bound semantic fingerprints.

Only then may coordination publish the candidate as a writer target.

This preserves the M5 invariant that managed generation semantics are established before correctness-sensitive use.

---

## 10. Decision M6-D007 — promotion occurs while synchronization remains active

M6 must not create a cutover gap by disabling synchronized writes immediately before promotion.

Preferred fail-safe ordering:

```text
candidate remains enrolled
    ->
fresh online verification
    ->
promote candidate
    ->
disable/retire synchronization route
```

A failure after promotion but before synchronization cleanup should prefer redundant writes over missed writes.

The exact Redis atomicity and transition protocol remains a later design blocker.

---

## 11. First-generation bootstrap

The same coordination model should support first activation where no active generation exists.

Conceptually:

```text
candidate provisioned/bound
    ->
candidate enrolled as synchronization target
    ->
pre-enrollment writer epoch drains
    ->
baseline scan
    ->
new coordinated writers write candidate
    ->
verify
    ->
promote first active generation
```

During this process queries continue to use authoritative fallback because no active Bloom generation exists.

---

## 12. Failure posture

### Query path

No change to M5:

> uncertainty means authoritative fallback.

### Membership-write path

A coordinated writer must not silently proceed toward authoritative commit if required Bloom routing is unknown or failed.

Coordination uncertainty on membership entry is therefore a loud write-side failure, not a query bypass.

### Rebuild path

If coordination cannot prove that pre-enrollment writers drained, the baseline scan must not be considered online-safe.

If synchronization state becomes unavailable or corrupt during an online build, promotion must be blocked.

### Stuck writers

M6 must not use a TTL that can silently declare an unknown authoritative transaction complete.

An abandoned writer session should block the relevant drain and become observable.

The exact recovery mechanism remains unresolved.

---

## 13. Blocker A + B resolution — coordinated consistency identity and writer-session contract

### Decision M6-D008 — exact consistency identity is `coordinated-preadd-v1`

M6 introduces one new built-in consistency identity:

```text
coordinated-preadd-v1
```

Its semantic meaning is:

> every authoritative membership-entry mutation that may overlap an online rebuild participates in the M6 coordinated writer-session protocol, and required Bloom routing completes before that authoritative mutation is allowed to commit.

This is intentionally distinct from M5 `preadd-v1`.

Consequences:

- `preadd-v1` keeps its M5 meaning and quiescent activation requirement;
- `coordinated-preadd-v1` gets a distinct consistency fingerprint;
- changing a filter between these contracts requires a new managed generation;
- M6 online-rebuild safety cannot be claimed while relevant writers still use the old uncoordinated write path;
- the new contract remains additive-membership-only.

The token describes the safety semantics, not a Redis implementation detail.

### Decision M6-D009 — coordinated writes use prepare -> authoritative outcome

The framework-neutral application surface is conceptually:

```php
interface CoordinatedMembershipWriter
{
    /**
     * @param iterable<string|int> $values
     */
    public function prepare(
        FilterName $name,
        iterable $values,
    ): PreparedMembershipWrite;
}

interface PreparedMembershipWrite
{
    public function authoritativeCommitted(): void;

    public function authoritativeAborted(): void;
}
```

Exact class/interface ownership may receive naming-only review, but the protocol is locked by this gate.

The lifecycle is:

```text
caller asks package to prepare coordinated membership write
    ->
package joins the current writer epoch
    ->
package resolves the exact required Bloom routing targets
    ->
package completes required Bloom writes to every target
    ->
prepare() returns PreparedMembershipWrite
    ->
caller performs/finishes its own authoritative transaction
    ->
caller reports authoritativeCommitted()
        OR authoritativeAborted()
    ->
writer session becomes drain-complete
```

### Preparation boundary

`prepare()` must not return until every Bloom mutation required by the captured coordination route has succeeded.

The caller must not perform the authoritative membership mutation before `prepare()` succeeds.

Therefore a preparation failure before the handle is returned is still on the package-controlled side of the authoritative boundary.

The package may safely attempt to mark that not-yet-returned session aborted/complete because the contract guarantees that the caller has not begun the authoritative membership mutation.

If cleanup of that failed preparation cannot itself be proven, the writer remains outstanding and observable rather than being silently forgotten.

### Returned session boundary

Once `prepare()` returns, the package must assume that an authoritative transaction may be in progress or may already have committed.

The returned session therefore exposes only explicit authoritative outcomes:

```text
authoritativeCommitted()
authoritativeAborted()
```

It must not expose correctness-significant shortcuts such as:

```text
release()
close()
done()
detach()
```

There is no destructor/finalizer auto-completion.

Losing a session handle or crashing after `prepare()` must not cause the coordination layer to infer completion.

### Completion failure

If the authoritative transaction commits successfully but `authoritativeCommitted()` cannot persist completion:

- the authoritative commit remains the application's fact;
- the writer session remains outstanding;
- future drains remain blocked;
- completion must be retryable/recoverable under the later identity/idempotency design;
- the package must not guess completion from elapsed time.

This intentionally prefers a stuck rebuild over a false-negative window.

### Abort semantics

`authoritativeAborted()` closes the writer from the coordination perspective.

Bloom bits written during preparation are not removed. They are safe false positives and remain consistent with the existing no-delete v1 model.

### Old M5 write API boundary

The existing M5 managed write surface:

```text
MembershipAdder::add()
MembershipAdder::addMany()
BloomGate::add()
BloomGate::addMany()
```

must not silently operate a `coordinated-preadd-v1` filter.

For that consistency contract, the old path must fail loudly and direct callers to the coordinated writer-session surface.

This prevents an application from accidentally claiming coordinated online-rebuild safety while still using a write API whose lifetime ends before authoritative commit/abort.

### Writer-session state machine

At the semantic level:

```text
PREPARING
    |
    +-- preparation fails before return
    |       -> ABORTED when cleanup is proven
    |       -> otherwise remains outstanding/unsafe
    |
    v
PREPARED
    |
    +-- authoritativeCommitted() -> COMMITTED
    |
    +-- authoritativeAborted()   -> ABORTED
```

Only `COMMITTED` and `ABORTED` are drain-complete.

The exact persisted representation of these states is deferred to `sync-v1` design.

### API self-review

This contract deliberately does **not**:

- own or open a database transaction;
- accept an arbitrary transaction callback;
- auto-release a writer;
- infer commit from a successful Bloom write;
- infer abort from timeout;
- reuse M5 `preadd-v1` identity;
- make Laravel/Eloquent part of the correctness core.

---

## 13. Required design blockers before implementation

No M6 implementation branch may be created until these are resolved.

### Blocker A — consistency identity — RESOLVED

Locked by M6-D008 as:

```text
coordinated-preadd-v1
```

### Blocker B — writer-session API — RESOLVED

Locked by M6-D009 as the explicit:

```text
prepare -> authoritativeCommitted | authoritativeAborted
```

protocol. The old M5 add/addMany path must reject this consistency contract.

### Blocker C — writer identity and idempotency

Define unique session identity, retries, duplicate begin/complete behavior, and crash semantics.

### Blocker D — `sync-v1` state model

Define the minimum coordination state: revision, writer epoch, mode, optional source active version, target candidate version, and outstanding writer information.

Do not duplicate M4 lifecycle state unnecessarily.

### Blocker E — persistence and atomic operations

Define Memory and Redis conformance semantics.

Redis design must account for Lua non-rollback behavior, same-slot keys, validation-before-mutation, bounded reads, safe begin/complete, epoch advance/drain, and stale post-promotion synchronization.

### Blocker F — race proof

Prove behavior when:

- a writer begins before enrollment and commits after enrollment;
- a writer begins during baseline scan;
- a writer overlaps verification;
- a writer overlaps promotion;
- promotion succeeds but sync cleanup fails;
- candidate discard overlaps coordination;
- a replacement candidate is allocated;
- a process crashes in each coordination phase.

### Blocker G — stuck writer recovery

Define operator-visible behavior for a session that never completes.

Safety wins over convenience. Automatic expiry is not acceptable without proof that the authoritative transaction can no longer commit.

### Blocker H — operational surface

Define status, doctor, build/activate command behavior, cancellation/discard semantics, and recovery diagnostics.

---

## 14. Explicitly rejected shortcuts

### "Just write active + candidate"

Rejected because it does not close the pre-enrollment Bloom-write / authoritative-commit race.

### "Start the baseline scan, then enable dual-write"

Rejected because writes can be missed before enrollment.

### "Enable dual-write and scan immediately without draining old writers"

Rejected because an active-only pre-enrollment writer can commit after the scan passes its value.

### "Reuse `control-v1` for sync fields"

Rejected because `control-v1` is strict and lifecycle ownership is separate from writer coordination.

### "Use Eloquent observers as the writer barrier"

Rejected because observers do not cover every authoritative writer.

### "Expire writer tickets after N seconds"

Rejected as a default correctness mechanism because time passage does not prove that the authoritative transaction cannot later commit.

### "Make the package own every DB transaction"

Rejected from M6 core scope. Coordination remains framework-neutral and explicit.

---

## 15. M6 invariants established by this gate

### INV-M6-001 — Existing M5 contracts keep their meaning

`preadd-v1` is not silently upgraded.

### INV-M6-002 — Online rebuild requires coordinated writers

No coordination participation means no M6 online-rebuild safety claim.

### INV-M6-003 — Writer coordination spans authoritative completion

The package cannot drain an epoch merely because its Bloom mutation finished.

### INV-M6-004 — Enrollment happens before baseline scan

The candidate becomes a synchronization target before the authoritative baseline starts.

### INV-M6-005 — Pre-enrollment writers drain before baseline scan

The baseline cannot be declared online-safe while an earlier writer epoch remains open.

### INV-M6-006 — Coordination is additive

M4 `control-v1` and M5 generation semantic metadata retain their existing contracts.

### INV-M6-007 — Promotion keeps synchronization safe until cutover completes

Cleanup failure may cause redundant writes; it must not cause missed membership.

### INV-M6-008 — Write-side uncertainty is loud

A coordinated writer must not authorize the caller to continue toward authoritative commit when required Bloom routing is unknown or failed.

### INV-M6-009 — Query fail-open semantics remain unchanged

M6 coordination does not weaken M5 `QueryGate` behavior.

### INV-M6-010 — No delete semantics are introduced

M6 online rebuild safety is additive-membership only.

### INV-M6-011 — No time-based correctness assumption

Session expiration alone cannot prove writer completion.

### INV-M6-012 — M6 does not imply Sentinel/Cluster support

Online rebuild coordination and Redis failover/topology support remain separate concerns.

---

## 16. Scope-gate self-review

### Scope alignment — PASS

The gate narrows M6 to one coherent capability:

```text
coordinated online rebuild for additive package-managed writers
```

Unrelated M5 deferrals stay out.

### Invariant / ADR consistency — PASS

- authoritative datastore remains the source of truth;
- M5 fail-open query behavior remains intact;
- lifecycle and health remain independent;
- M4 control state stays strict and unchanged;
- semantic identity changes remain explicit;
- no delete behavior is invented.

### Dependency direction — PASS

The proposed coordination model is framework-neutral. Laravel/Redis remain outer adapters.

### Complexity — PASS with blockers

A separate sync plane is additional complexity, but existing M5 state cannot prove online-rebuild safety.

The exact writer-session and sync persistence design remains deliberately unresolved.

### Brownfield safety — PASS

No existing M2–M5 API or persistence format is redefined by this gate.

### Verification evidence — NOT APPLICABLE YET

This is scope/design only. No M6 production or test implementation has started.

---

## 17. Gate result

**M6 scope is provisionally accepted as Coordinated Online Rebuild.**

Blocker A + Blocker B are now resolved.

This gate authorizes only the next design step:

> resolve **Blocker C + Blocker D** — writer identity/idempotency/crash semantics and the minimum revisioned `sync-v1` state model.

It does **not** authorize implementation, Redis schema creation, Laravel API wiring, or an M6 implementation branch.
