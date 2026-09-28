# M6 — Implementation Task Breakdown + Verification Matrix

**Status:** DRAFT — planning/design gate only  
**Baseline:** `main@5f5e5f18ecd05c6a44d520ec62ecc18ee12ee1a0`  
**Accepted architecture inputs:** ADR-0041, ADR-0042, ADR-0043  
**Implementation authorized:** **NO**

This document converts the accepted M6 architecture into dependency-ordered,
executable implementation work units and locks the verification evidence required
before M6 can be considered complete.

It intentionally does **not** implement M6.

No `src/`, `tests/`, configuration, runtime, or command behavior is changed by this
planning gate.

---

## 1. Purpose

M6 now has accepted architecture for:

- coordinated writer preparation and durable A/P/R lease lifetime;
- synchronization epochs and exact target binding;
- control-v1 ↔ sync-v1 opposite-revision fencing;
- immutable coordinated lifecycle ownership;
- online rebuild publication, drain, reconciliation, verification, promotion, and
  post-promotion drain;
- coordinated abort;
- brownfield adoption;
- evidence-bound lease recovery;
- Application-owned public management;
- Laravel command adapters and diagnostics.

The remaining risk is implementation sequencing.

A wrong sequence could make individually reasonable components impossible to verify
in isolation, especially around:

- stale M5 control CAS after adoption;
- Redis atomicity;
- Memory/Redis behavioral drift;
- writer acquisition vs epoch rotation;
- PREPARED evidence;
- promotion vs abort races;
- crash recovery between control and sync mutations;
- Laravel adapters accidentally owning correctness policy.

This gate therefore defines:

1. work-unit dependency order;
2. RED → GREEN test order;
3. Memory/Redis parity evidence;
4. race verification;
5. crash/restart verification;
6. Laravel adapter timing;
7. completion gates.

---

## 2. Locked architecture constraints

Implementation planning must preserve all of the following.

### 2.1 Final lease model is A/P/R

ADR-0041 is authoritative.

The durable writer states are:

```text
A = ACQUIRED / PREPARING
P = PREPARED
R = RELEASED
```

Older design text that described only ACTIVE/RELEASED is superseded where it conflicts
with ADR-0041.

The active-writer count includes both A and P.

A -> P does not change the count.

The first authorized A/P -> R decrements exactly once.

### 2.2 control-v1 remains strict and unchanged

M6 does not add coordination fields to strict M4 `control-v1`.

The synchronization plane remains separate.

### 2.3 sync-v1 is strict

The exhaustive logical fields remain:

Required:

```text
format = sync-v1
revision
phase
current_epoch
current_targets
```

Optional by absence:

```text
candidate_version
draining_epoch
```

Unknown fields are corruption.

### 2.4 Durable ownership is one-way

`:sync:owner = coordinated-v1` is immutable in M6 v1.

Owner presence permanently fences ordinary M5 lifecycle mutation for that persisted
filter history.

Owner present + sync missing is recovery-required, not de-adoption.

Owner absent + sync present is invalid/corrupt coordination.

### 2.5 No generic dual-plane durable write

M6 workflows use:

```text
atomic pair read
+ guarded control-only CAS
+ guarded sync-only CAS
+ opposite-revision fencing
+ interruption-safe intermediate states
```

No implementation task may add a generic "write control and sync together" product
primitive.

### 2.6 Persistence does atomicity, Application does policy

Redis and Memory enforce:

- atomic persisted semantics;
- strict representation;
- revision fencing;
- corruption detection;
- exactly-once lease/count arithmetic.

Application/Lifecycle owns:

- legal adoption;
- legal rebuild transitions;
- legal abort;
- authoritative-outcome interpretation;
- recovery policy.

### 2.7 Query behavior is not redesigned by M6

M5 trusted-negative/query authorization behavior remains unchanged.

Query uncertainty remains fail-open to the authoritative query.

M6 write/lifecycle uncertainty fails closed.

### 2.8 No lease TTL or force recovery

No correctness TTL, implicit destructor release, process-shutdown release, token
deletion, force flag, "assume committed", or "assume rolled back" is introduced.

### 2.9 Memory is a correctness reference, not a weaker fake

Memory must reproduce the same observable per-filter serialization semantics as Redis.

### 2.10 Redis Cluster support is not claimed

M6 keys remain same-hash-tag / same-slot compatible.

That remains design preparation, not a Redis Cluster runtime-support claim.

---

## 3. Implementation strategy

The implementation must proceed bottom-up.

```text
Core vocabulary
    ↓
Contracts + executable contract suites
    ↓
Memory atomic reference backend
    ↓
Redis atomic backend
    ↓
Memory/Redis parity gate
    ↓
runtime coordination requirement + legacy fencing
    ↓
prepared writer Application protocol
    ↓
adoption + evidence-bound lease recovery
    ↓
online rebuild happy path
    ↓
abort + interrupted workflow recovery
    ↓
status / doctor
    ↓
Laravel bindings + commands
    ↓
race/crash closure matrix
    ↓
full M6 verification / docs closure
```

No higher layer should be implemented to compensate for missing lower-layer
correctness.

---

# 4. Work units

## WU-00 — Core coordination vocabulary and invariants

### Goal

Introduce the framework-neutral immutable vocabulary required by ADR-0041/0042/0043.

Expected responsibilities include the equivalent of:

- synchronization revision;
- synchronization epoch;
- synchronization phase;
- canonical ordered target set;
- writer lease token;
- writer lease state A/P/R;
- immutable writer lease binding;
- ownership state/interpretation values where useful;
- authoritative outcome;
- typed progress/result values needed by later Application services.

Exact type names may be refined, but responsibility boundaries are fixed.

### RED first

Write Core unit tests before implementation for:

- positive revision/epoch requirements;
- canonical target ordering and uniqueness;
- empty target representation;
- valid 32-lowercase-hex token;
- invalid/uppercase/short/long token rejection;
- A/P/R state exhaustiveness;
- immutable epoch/targets once a token is bound;
- synchronization phase exhaustiveness, including abort phases;
- illegal nullable/required field relations rejected by the logical model where that
  relation belongs in Core.

### GREEN

Implement only enough Core types to satisfy the tests.

### Must not include

- Redis;
- Memory storage;
- Laravel;
- workflow orchestration;
- public commands.

### Exit evidence

- Core tests green;
- architecture/dependency tests prove no Illuminate/persistence dependency.

---

## WU-01 — Persistence ports, exceptions, and reusable contract suites

### Goal

Define the minimum framework-neutral storage capabilities needed by M6.

Conceptually, responsibilities include:

### Coordinated lifecycle persistence

- atomic control + ownership + sync observation;
- immutable ownership claim;
- coordinated control-only CAS guarded by expected sync revision;
- coordinated sync-only CAS guarded by expected control revision/required absence.

### Writer synchronization persistence

- acquire token against the current epoch/targets;
- mark A -> P;
- release A/P -> R;
- read active writer count for an epoch;
- diagnostically enumerate active A/P lease records if needed by status;
- strict synchronization snapshot read.

The implementation may use one interface or narrowly separated interfaces if that
improves dependency direction. It must not expose raw persistence primitives as the
normal public management API.

### Required exception/result distinctions

At minimum, the contract surface must preserve distinctions equivalent to:

- optimistic/revision conflict;
- coordination fenced;
- storage corruption;
- operational/unavailable failure;
- terminal released token;
- unknown token;
- invalid transition/programming error where appropriate.

### RED first

Create reusable contract test cases that express observable behavior without
backend-specific assumptions.

The first concrete execution of those contracts occurs against Memory in WU-02.

### Contract rules to encode

- nullable expected revision means required absence;
- staging state is never current correctness state;
- owner/sync contradictory shapes fail closed;
- ordinary M5-compatible absence remains distinguishable from coordinated
  missing-evidence recovery;
- acquire is not modeled as caller-side read + CAS;
- A -> P is idempotent and count-neutral only while immutable coordinated ownership is valid, strict current `sync-v1` exists, and the lease's original epoch has a positive active-writer count;
- A -> P fails closed without changing lease/count state when coordinated ownership is invalid, strict current `sync-v1` is absent/invalid, or the original epoch count is not positive;
- first A/P -> R decrements once;
- R retry is idempotent;
- count underflow is corruption.

### Exit evidence

- ports compile independently of Drivers/Laravel;
- contract suites exist and are backend-neutral.

---

## WU-02 — Memory atomic coordination reference backend

### Goal

Make Memory the executable reference for M6 persistence semantics.

Memory control and synchronization operations must share one per-filter serialization
domain.

A design where Memory control CAS and synchronization mutation cannot observe each
other at their linearization point is invalid.

### RED sequence

Run the WU-01 contracts against Memory and add Memory-specific deterministic race
fixtures for:

1. never-adopted owner-absent + sync-absent ordinary control CAS;
2. ownership claim fencing a stale ordinary control CAS;
3. owner present + sync missing;
4. owner absent + sync present;
5. atomic pair observation;
6. guarded control CAS;
7. guarded sync CAS;
8. opposite-revision conflict;
9. acquire new A token;
10. acquire retry A;
11. acquire retry P;
12. A -> P succeeds only with valid immutable ownership, strict current `sync-v1`, and a positive count for the lease's original epoch;
13. A -> P rejects invalid coordinated ownership without changing lease/count state;
14. A -> P rejects absent/invalid strict current `sync-v1` without changing lease/count state;
15. A -> P rejects a missing/zero/non-positive original-epoch count without changing lease/count state;
16. P retry is idempotent and count-neutral;
17. release A;
18. release P;
19. release R retry;
20. epoch rotation vs acquire;
21. persisted active count and drain proof;
22. strict malformed state rejection.

### GREEN

Implement the Memory backend and adapt `MemoryFilterControlStore` so ordinary CAS is
atomically fenced by the same coordination state.

### Required Memory concurrency evidence

Use deterministic barriers/Fibers/test seams rather than sleep-based timing.

The evidence must show that each interleaving has one legal serialization outcome.

### Exit evidence

- all reusable contracts green on Memory;
- deterministic race tests green;
- existing M4/M5 Memory behavior remains green for genuinely unadopted filters.

---

## WU-03 — Redis coordination representation and atomic persistence

### Goal

Implement Redis persistence with semantics equivalent to WU-02.

### Redis keyspace

Same-filter keys equivalent to:

```text
:sync:owner
:sync
:sync:staging
:sync:leases
:sync:counts
```

All must preserve the existing filter hash tag.

### RED sequence A — representation

Before mutation scripts:

- strict sync-v1 codec tests;
- canonical target encoding tests;
- canonical A/P/R lease-record tests;
- strict count encoding tests;
- wrong Redis type tests;
- unknown sync field tests;
- malformed owner value tests;
- staging-not-current tests;
- no-TTL expectations.

### RED sequence B — ordinary control fence

Extend Redis control CAS tests so that the CAS atomically observes:

- `:state`;
- `:state:staging`;
- `:sync:owner`;
- `:sync`.

Required outcomes:

- owner absent + sync absent -> existing M5 storage path eligible;
- owner present + valid sync -> coordination fenced;
- contradictory/malformed coordination -> corruption/fail closed.

### RED sequence C — coordinated lifecycle operations

Live Redis tests for:

- atomic pair read;
- owner-first claim;
- idempotent/retry-safe adoption pending initialization semantics;
- guarded control-only CAS;
- guarded sync-only CAS;
- opposite-revision conflicts;
- required-absence semantics.

### RED sequence D — writer operations

Live Redis tests for:

- acquire and exact binding;
- acquire retry does not increment twice;
- A -> P succeeds only with valid immutable ownership, strict current `sync-v1`, and a positive original-epoch count;
- A -> P rejects invalid coordinated ownership without mutation;
- A -> P rejects absent/invalid strict current `sync-v1` without mutation;
- A -> P rejects missing/zero/non-positive original-epoch count without mutation;
- P retry idempotent and count-neutral;
- release decrements once;
- R retry does not decrement;
- unknown token;
- terminal token;
- malformed record/counter;
- count underflow;
- epoch rotation linearizable with acquire.

### GREEN

Implement Redis codecs, scripts, store(s), and existing control-CAS fencing.

Lua/EVAL owns persistence atomicity only; it must not decide rebuild/adoption policy.

### Exit evidence

- reusable WU-01 contracts green on Redis;
- live Redis integration evidence green;
- existing M4/M5 Redis tests remain green for unadopted filters;
- no correctness key has TTL.

---

## WU-04 — Memory/Redis parity gate

### Goal

Stop Application implementation until both persistence backends prove the same
observable protocol.

### Method

Define backend-neutral scenario traces.

Each trace runs against:

- Memory;
- Redis.

Compare normalized observable results, not implementation details.

### Mandatory parity scenarios

| ID | Scenario | Required parity |
|---|---|---|
| P01 | owner absent + sync absent, uncoordinated | ordinary M5 CAS eligible |
| P02 | owner present + valid sync | ordinary M5 CAS fenced |
| P03 | owner present + sync absent | recovery/corruption, no mutation |
| P04 | owner absent + sync present | invalid coordination, no mutation |
| P05 | owner claim | one-way immutable ownership |
| P06 | atomic pair read | one control/sync observation |
| P07 | control CAS C10/S7 vs sync CAS C10/S7 | one wins, loser conflicts |
| P08 | nullable opposite revision | means required absence |
| P09 | new acquire | A bound to exact epoch/targets, count +1 |
| P10 | acquire retry A | same binding, no new count |
| P11 | prepare A -> P | same binding, count unchanged |
| P11a | prepare with invalid coordinated ownership | rejected, lease/count unchanged |
| P11b | prepare with absent/invalid strict current sync-v1 | rejected, lease/count unchanged |
| P11c | prepare with missing/zero/non-positive original-epoch count | rejected, lease/count unchanged |
| P12 | acquire/prepare retry P | same binding, no new count |
| P13 | release A | R, original count -1 once |
| P14 | release P | R, original count -1 once |
| P15 | release retry R | terminal/idempotent, no second decrement |
| P16 | epoch rotation vs acquire | lease is entirely old or entirely new epoch |
| P17 | malformed sync/lease/count | corruption classification matches |
| P18 | staging residue | never treated as current correctness state |
| P19 | active count zero after admission close | valid drain evidence |
| P20 | active count unavailable/corrupt | drain not proven |

### Gate rule

Application orchestration does not begin until P01-P20 are green on both backends.

---

## WU-05 — Runtime coordination requirement and legacy M5 mutation fencing

### Goal

Implement the Application/runtime rule that persisted ownership outranks local mode,
while coordinated runtime expectation may make the fence stricter.

This is distinct from the lower-level storage fence.

### Required behavior

```text
owner absent + sync absent + runtime does not require coordinated-v1
-> ordinary M5 path remains eligible

owner present + runtime says uncoordinated
-> coordinated ownership still wins

owner absent + runtime requires coordinated-v1
-> recovery required
-> ordinary M5 mutation blocked

owner/sync malformed or contradictory
-> mutation blocked
```

The runtime coordination requirement must not silently alter the M5 semantic
consistency fingerprint unless a separate accepted architecture decision explicitly
requires it. ADR-0041 says coordination itself is not a new generation membership
semantic.

### RED first

Application tests around every existing lifecycle mutator:

- allocation;
- candidate lifecycle/health mutation;
- verification mutation;
- discard;
- promotion;
- active deactivation;
- managed M5 add/addMany where coordinated ownership makes the legacy API invalid.

### GREEN

Introduce the smallest framework-neutral resolution mechanism needed by Application.

Laravel configuration mapping is deferred to WU-11.

### Exit evidence

- all legacy mutation paths are either valid M5 or loudly fenced;
- read-only inspection remains available.

---

## WU-06 — Coordinated prepared-writer Application protocol

### Goal

Implement the canonical explicit writer lifetime from ADR-0041.

Conceptual sequence:

```text
stable token exists
-> acquire A bound to exact epoch/targets
-> validate target semantics
-> Bloom pre-add every target
-> mark P
-> only then authoritative membership may become visible
-> known authoritative outcome
-> release to R
```

### RED first

Use a controllable Bloom driver and controllable synchronization store.

Required tests:

1. token exists before first remote operation;
2. acquire failure prevents Bloom writes;
3. target semantic mismatch prevents authoritative permission;
4. required target Bloom write failure prevents P;
5. partial target Bloom writes are tolerated only as safe false positives;
6. retry with same A token reuses original target set;
7. all target writes complete before A -> P;
8. P retry does not repeat count arithmetic;
9. only P returned as prepared authorization;
10. known commit releases P;
11. known rollback may release A or P;
12. unknown authoritative outcome never releases;
13. known commit + uncertain release reports cleanup uncertainty, not DB failure;
14. failed prepare preserves the original token;
15. explicit pre-authoritative abandonment uses the same terminal release path;
16. no destructor/TTL/implicit release behavior.

### GREEN

Implement the framework-neutral coordinated writer service.

The package must not own or infer the authoritative transaction.

### Exit evidence

- writer service tests green against Memory;
- the same service tests green against Redis-backed persistence where integration
  evidence is required;
- no Laravel convenience API yet.

---

## WU-07 — Coordinated adoption and evidence-bound lease recovery

### Goal

Implement ADR-0043 management workflows before online rebuild orchestration.

### Adoption RED sequence

#### Fresh filter

- a fresh filter with no prior control state may adopt without a brownfield quiescent handoff assertion;
- raw external writers remain outside package guarantees under ADR-0010;
- claim owner first;
- initialize STEADY sync-v1 revision 1 / epoch 1 / empty current targets;
- retry is idempotent.

#### Brownfield filter

- explicit quiescent handoff acknowledgement required;
- current control candidate rejects adoption;
- ownership claim requires expected control relation;
- crash after owner claim yields ADOPTION_PENDING;
- ordinary M5 mutation stays fenced while pending;
- coordinated writer acquire stays blocked while pending;
- adoption retry initializes valid sync;
- handoff is not considered complete before valid sync exists.

### Lease recovery RED sequence

- known COMMITTED: P -> R;
- known COMMITTED: A blocked because preparation proof is absent;
- known ABORTED/ROLLED_BACK: A -> R;
- known ABORTED/ROLLED_BACK: P -> R;
- unknown outcome: no release;
- R is terminal/idempotent;
- no force/delete/TTL path exists.

### GREEN

Implement Application-owned adoption and lease recovery services.

### Exit evidence

- adoption is owner-first and restart-safe;
- recovery requires explicit authoritative evidence.

---

## WU-08 — Online rebuild coordinator: happy path and promotion recovery

### Goal

Implement deterministic, resumable online rebuild progress without abort yet.

### Workflow

For active A and candidate C:

```text
provision + semantic bind C
-> initial build C
-> publish C as target and rotate epoch
-> drain pre-publication epoch
-> reconcile authoritative-present set into C
-> fresh verify C
-> READY_TO_PROMOTE
-> control promotion C ACTIVE / A RETIRED
-> sync rotate A+C -> C-only
-> drain prior dual-write epoch
-> STEADY
```

First activation follows the same model with no prior active generation and an initial
empty target set.

### RED first

State-machine tests must cover each durable phase:

- STEADY start eligibility;
- publication only after provisioning/binding;
- semantic fingerprint equality with A;
- authoritative-set fingerprint equality;
- consistency fingerprint equality;
- layout may differ;
- publication uses guarded sync-only CAS pinned to candidate-owning control revision;
- drain uses persisted count only;
- reconciliation is restartable/idempotent;
- verification is fresh and version-pinned;
- promotion control mutation occurs before target contraction;
- crash after control promotion but before sync rotation resumes safely from
  READY_TO_PROMOTE-compatible shape;
- another rebuild blocked during post-promotion drain;
- completion only at STEADY after drain.

### Result semantics

`advance()` must distinguish at least:

- advanced;
- blocked;
- completed;
- recovery required.

No sleep belongs in the framework-neutral coordinator.

### GREEN

Implement the Application coordinator and any Lifecycle policy collaborators.

### Exit evidence

- deterministic rerun from every happy-path phase;
- no command/UI code.

---

## WU-09 — Coordinated abort and interrupted workflow recovery

### Goal

Add the ADR-0042 coordinated abort state machine and recovery of interrupted workflows.

### RED first

#### Unpublished candidate

- coordinated control-only retire/clear may occur while sync is STEADY.

#### Published candidate

Required progression:

```text
request abort
-> ABORT_REQUESTED when an older drain prevents immediate rotation
-> rotate new admission away from C
-> DRAINING_ABORT
-> drain every old lease that may still target C
-> retire C / clear control candidate
-> finalize sync STEADY
```

### Required race tests at Application level

- abort vs promotion from the same observed pair;
- publication vs candidate replacement/discard;
- abort request while pre-reconcile drain is already in progress;
- abort after control promotion is too late;
- promotion recovery must finish after control promotion commits;
- repeated abort resumes, never reverses durable abort intent;
- repeated advance does not silently cancel abort.

### GREEN

Implement abort/resume policy using only guarded single-plane persistence operations.

### Exit evidence

- no conforming active lease can reference C when C is retired;
- abort and promotion serialize through opposite revisions.

---

## WU-10 — Coordination status and doctor diagnostics

### Goal

Expose read-only coordination state without making diagnostics part of authorization.

### Status model must represent

```text
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
```

Diagnostic lease enumeration may show active A/P tokens with original epoch/targets.

Released tombstones are not dumped by default.

Lease enumeration is never drain proof.

### RED first

- owner/sync/config disagreement classifications;
- impossible control/sync relation;
- malformed state;
- blocked drain;
- adoption pending;
- post-promotion recovery;
- abort pending/draining;
- active A vs P lease diagnostic representation;
- diagnostics unavailable remains non-authorizing.

### GREEN

Extend framework-neutral status/doctor Application services.

### Exit evidence

- diagnostic code is read-only;
- no status result is used as a mutation authorization shortcut.

---

## WU-11 — Laravel adapter and command surface

### Entry condition

WU-00 through WU-10 must be green first.

Laravel must not be used to discover or patch missing correctness semantics.

### Responsibilities

- bind Memory/Redis M6 ports;
- map Laravel filter configuration to the framework-neutral coordination requirement;
- expose the coordinated writer service through an explicit Laravel-facing surface;
- add thin management commands;
- extend status/doctor presentation.

### M6 v1 command surface

```text
bloom:coordinate:adopt <filter> [--quiescent]
bloom:rebuild <filter> [--wait=<seconds>]
bloom:rebuild:abort <filter> [--wait=<seconds>]
bloom:lease:resolve <filter> <token> --outcome=committed|aborted
```

Existing commands remain but adopted filters must be fenced through Application/store
semantics rather than duplicated command-only checks.

### RED first

Laravel integration tests for:

- service container bindings;
- Memory driver wiring;
- Redis driver wiring;
- coordinated runtime requirement mapping;
- adopt requires `--quiescent` for brownfield state;
- rebuild starts/resumes same durable workflow;
- abort starts/resumes same durable abort;
- lease resolve rejects unknown/force;
- `--wait=0` makes progress until the first durable wait condition and returns;
- positive wait budget may poll but timeout changes no correctness state;
- command interruption/re-run resumes;
- status coordination section;
- status `--leases`;
- doctor remains read-only;
- legacy mutation commands fail loudly for adopted filters.

### GREEN

Implement thin command/adaptor code only.

### Exit evidence

- no lifecycle legality embedded in Console classes;
- no Redis protocol embedded in Laravel;
- Application tests remain usable without Laravel.

---

## WU-12 — Race and crash closure matrix

### Goal

Run the complete correctness matrix against the integrated implementation.

This is not a place to invent new behavior. It closes evidence gaps only.

### Race matrix

| ID | Race | Required outcome |
|---|---|---|
| R01 | stale ordinary M5 control CAS vs ownership claim | exactly one legal ordering; owner-winning ordering fences stale CAS |
| R02 | coordinated control CAS vs sync CAS from C10/S7 | one succeeds, other conflicts |
| R03 | acquire vs epoch rotation | lease binds wholly to old or new epoch; never split |
| R04 | A -> P vs epoch rotation | lease retains original binding; prepare is valid only under ADR-0041 preconditions |
| R05 | release vs release retry | exactly one decrement |
| R06 | acquire retry vs release | terminal R never regains authority |
| R07 | candidate publication vs control candidate change | stale publication conflicts |
| R08 | promotion vs abort | one revision-fenced winner; no mixed illegal state |
| R09 | two coordinators advance same phase | one durable transition; loser re-reads/resumes |
| R10 | two abort coordinators | idempotent durable abort progress |
| R11 | promotion target contraction vs prepared old dual-write lease | contraction may occur only after control promotion; old epoch then drains |
| R12 | status/lease enumeration vs release | diagnostic inconsistency never becomes drain authorization |

### Crash/restart matrix

| ID | Crash point | Required restart behavior |
|---|---|---|
| C01 | owner created before sync initialization | ADOPTION_PENDING; M5 mutation/writer acquire blocked; adoption retry allowed |
| C02 | lease acquired A before any Bloom write | pre-authoritative; retry same token or explicit safe abandonment |
| C03 | partial target Bloom pre-add | only false positives; remain A; retry same token/targets |
| C04 | all pre-adds done, A -> P acknowledgement uncertain | retry prepare with same token; converge to P or fail closed |
| C05 | P exists, authoritative outcome unknown | no release; stable token surfaced |
| C06 | authoritative commit known, release acknowledgement uncertain | committed result preserved; cleanup uncertainty separate |
| C07 | candidate publication committed, process exits | resume from durable draining phase |
| C08 | old epoch count reaches zero before next coordinator step | rerun observes zero and advances |
| C09 | reconciliation partially completed | idempotent Bloom re-add and resume |
| C10 | fresh verification durable, coordinator exits before next sync step | reread evidence/state; no stale verification substitution |
| C11 | control promotion committed before C-only sync rotation | recover by finishing promotion path; continue safe A+C writes |
| C12 | abort intent durable before admission rotation | resume abort; normal rebuild cannot reverse it |
| C13 | abort admission rotation committed before candidate retirement | remain DRAINING_ABORT until every C-targeting old lease drains |
| C14 | process exits during post-promotion drain | next rebuild remains blocked; resume drain |
| C15 | Redis sync staging residue exists after failed materialization | staging ignored as correctness; previous current sync preserved |
| C16 | owner present but sync lost | recovery required; never de-adopt |
| C17 | owner absent but sync survives | invalid/corrupt coordination; no mutation |
| C18 | wait budget expires in Laravel command | no lease expiry, no skipped drain, state remains resumable |
| C19 | command process interrupted | same command re-run resumes from durable state |
| C20 | full historical rollback erases owner/sync and runtime coordination config | outside automatic detection boundary; requires new explicit adoption fence before coordinated guarantees |

### Backend requirement

R01-R06 and C01-C17 must have direct Memory and Redis evidence where persistence
semantics are involved.

R07-R12 and orchestration crash cases run through Application against Memory and at
least the critical atomic subsets against live Redis.

---

## WU-13 — Final M6 verification and documentation closure

### Goal

Close M6 without adding product behavior.

### Verification

Run the repository's full quality command plus explicit M6 suites.

Required evidence categories:

- Core;
- Contract;
- Memory;
- Redis live integration;
- Application;
- Laravel integration;
- architecture/dependency;
- existing M4/M5 regression;
- race matrix;
- crash matrix;
- no-TTL evidence;
- strict corruption fixtures.

### Documentation closure

Update canonical docs only to reflect behavior actually implemented and verified.

Do not document planned behavior as shipped behavior before evidence exists.

### Exit rule

M6 can be declared implementation-complete only when:

1. WU-00 through WU-12 are green;
2. P01-P20 parity is green on Memory and Redis;
3. R01-R12 race evidence is green;
4. C01-C20 crash/restart evidence is green or explicitly classified as the accepted
   external-history boundary;
5. existing M4/M5 regression is green;
6. Laravel adapters remain thin;
7. no accepted ADR invariant is weakened.

---

# 5. Dependency graph

```text
WU-00 Core vocabulary
  ↓
WU-01 Contracts + contract suites
  ↓
WU-02 Memory atomic reference
  ↓
WU-03 Redis atomic persistence
  ↓
WU-04 Memory/Redis parity gate
  ↓
WU-05 runtime expectation + legacy fence
  ↓
WU-06 prepared writer protocol
  ↓
WU-07 adoption + lease recovery
  ↓
WU-08 rebuild happy path
  ↓
WU-09 abort + interrupted recovery
  ↓
WU-10 status + doctor
  ↓
WU-11 Laravel adapters/commands
  ↓
WU-12 race/crash closure
  ↓
WU-13 final verification/docs
```

Limited parallelism is allowed only after lower-level contracts are stable.

Safe parallel examples after WU-04:

- parts of WU-05 test design and WU-06 test fixture preparation;
- status presentation design after WU-08/WU-09 state shapes are fixed.

Unsafe parallel examples:

- Redis implementation before Core/Contracts settle;
- Laravel command implementation before Application services are green;
- rebuild orchestration before Memory/Redis parity;
- abort implementation before promotion ordering is executable.

---

# 6. RED → GREEN discipline

Every implementation work unit follows:

```text
1. write the smallest failing executable evidence
2. confirm failure is for the intended missing behavior
3. implement the minimal behavior
4. run focused tests
5. run relevant regression slice
6. explicit self-review
7. only then move to the next work unit
```

A test that passes before the intended implementation is not accepted as RED evidence
unless it is specifically a regression-preservation assertion.

For Redis race tests, "RED" may be an observed protocol mismatch/conflict gap rather
than a deterministic data race. Sleep-based tests are not accepted as correctness
proof.

---

# 7. Required self-review after every work unit

Before advancing, explicitly check:

1. **Scope alignment** — did the work unit add only its planned responsibility?
2. **ADR consistency** — does it preserve ADR-0041/0042/0043?
3. **Dependency direction** — did Core/Contracts remain framework-neutral?
4. **Atomicity ownership** — is persistence doing atomicity rather than workflow policy?
5. **Complexity** — was any unnecessary generic abstraction introduced?
6. **Backward compatibility** — do genuinely unadopted M5 filters keep their valid path?
7. **Brownfield safety** — did any code infer handoff completion it cannot prove?
8. **Failure semantics** — is uncertainty fail-closed for writes/lifecycle and fail-open
   only for queries?
9. **Parity** — could Memory and Redis now differ observably?
10. **Evidence** — is the revision tied to reproducible tests?

A failed self-review blocks the next work unit.

---

# 8. Implementation PR slicing

Default execution should keep one correctness boundary per PR.

Suggested slices:

```text
PR-A  WU-00
PR-B  WU-01 + first contract scaffolding
PR-C  WU-02
PR-D  WU-03
PR-E  WU-04
PR-F  WU-05
PR-G  WU-06
PR-H  WU-07
PR-I  WU-08
PR-J  WU-09
PR-K  WU-10
PR-L  WU-11
PR-M  WU-12
PR-N  WU-13
```

A PR may be split further when a change becomes too large for precise review.

Do not combine Redis scripts, Application orchestration, and Laravel commands into one
review unit.

---

# 9. Gate decision

This planning document is complete only when review confirms that:

- no accepted ADR behavior is missing from the work units;
- dependency order makes every correctness layer independently testable;
- Memory/Redis parity is a blocking gate rather than a final afterthought;
- PREPARED state is represented throughout the writer/count/recovery matrix;
- race and crash cases cover ownership, revisions, epoch admission, promotion, abort,
  and lease cleanup;
- Laravel remains the final adapter phase.

Until this gate is reviewed and accepted:

> **M6 implementation remains unauthorized.**

The next safe step after this document lands is not "implement all of M6".

It is:

> open **WU-00 — Core coordination vocabulary and invariants**, write its RED tests,
> and proceed one work unit at a time.
