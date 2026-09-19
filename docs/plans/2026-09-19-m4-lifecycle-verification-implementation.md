# M4 — Lifecycle & Verification Implementation Plan

**Status:** PROPOSED — implementation must not start until this plan is explicitly approved  
**Design gate:** PASSED  
**Base:** `main@a8cd04b08e81d08fdb97644667e8696039ecac2f`  
**Planned implementation branch:** `feat/m4-lifecycle-verification`  
**Plan branch:** `docs/m4-lifecycle-verification-plan`

This plan implements the accepted M4 lifecycle/control-plane design without moving Eloquent integration, authoritative-query orchestration, runtime normalization identity, retention, or rebuild scheduling into M4.

---

## 1. Goal

M4 adds a framework-neutral lifecycle/control plane around the already implemented M2/M3 Bloom data plane.

The milestone must make these rules executable:

- lifecycle and health are generation-scoped and independent;
- one logical filter has at most one active generation and one candidate generation;
- generation versions are allocated monotonically and never reused;
- control-plane writes use revisioned compare-and-swap semantics;
- lifecycle transition policy lives outside persistence drivers;
- candidate verification detects operational false negatives against a complete/reconciled authoritative-present stream;
- verification and activation remain separate explicit operations;
- promotion atomically switches control-plane ownership without moving or rewriting Bloom data;
- only an ACTIVE + HEALTHY current active generation is eligible to be probed by later application orchestration;
- control-plane uncertainty or corruption never becomes a safe negative.

---

## 2. Explicit non-goals

M4 must not implement:

- Eloquent observers, traits, scopes, model hooks, or query interception;
- authoritative database lookup orchestration;
- package-facing query-gate/facade behavior;
- runtime normalizer/config fingerprint compatibility;
- automatic rebuild scheduling;
- Artisan rebuild/sync commands;
- retention or purge policy;
- rollback workflow;
- Bloom sizing/FPR tuning;
- background health monitoring;
- Redis Cluster runtime-support claims;
- sampled verification as activation evidence;
- destructive in-place rebuild of a managed generation.

Runtime normalization identity remains a mandatory M5 design blocker.

---

## 3. Locked design inputs

M4 implementation must preserve:

- ADR-0007 — fail open;
- ADR-0008 — explicit filter lifecycle;
- ADR-0009 — versioned rebuild;
- ADR-0010 — synchronization is a contract;
- ADR-0018 — lifecycle/health separation;
- ADR-0020 — stable normalization contract;
- ADR-0021 — Cluster-aware keyspace;
- ADR-0032 — stock Redis bitmap data plane;
- ADR-0033 — atomic Redis driver scripts.

New ADRs planned during M4 documentation sync:

- ADR-0034 — Revisioned lifecycle control plane;
- ADR-0035 — Candidate verification and explicit promotion;
- ADR-0036 — Redis control-plane persistence.

No accepted ADR is superseded by M4.

---

## 4. Required implementation order

The order below is mandatory. Do not start a later task while the current task's RED → GREEN evidence and self-review are incomplete.

### Task 0 — implementation branch and baseline gate

Only after this plan is separately approved:

1. fetch latest `main`;
2. confirm it still contains the accepted M4 design baseline;
3. create `feat/m4-lifecycle-verification` from that exact verified main head;
4. run the existing baseline gates before changing source.

Baseline commands:

```bash
composer validate --strict
composer check
```

When Redis is available:

```bash
composer test:redis
```

If the base has changed in a way that alters M4 assumptions, stop and reopen the design gate instead of adapting silently.

**No M4 source file is created before Task 0 is green.**

---

# Task 1 — Core control-state primitives

## Owned source files

Create:

```text
src/Core/FilterStateRevision.php
src/Core/GenerationControlState.php
src/Core/FilterControlState.php
```

Create tests under:

```text
tests/Unit/Core/
```

Do not modify:

```text
src/Core/LifecycleState.php
src/Core/HealthState.php
src/Core/FilterVersion.php
src/Core/FilterName.php
```

except if a previously unknown defect is demonstrated by a failing regression test and separately reviewed.

## RED first

Tests must cover at minimum:

### FilterStateRevision

- revision 1 valid;
- zero rejected;
- negative rejected;
- equality;
- next revision;
- overflow at PHP_INT_MAX.

### GenerationControlState

- exact version/lifecycle/health retention;
- immutable state;
- no hidden lifecycle or health policy methods.

### FilterControlState

- exact filter identity;
- revision retained;
- last allocated version retained;
- no active/candidate is valid;
- one active generation valid;
- one candidate generation valid;
- active + candidate simultaneously valid when versions differ;
- duplicate tracked versions rejected;
- active pointer to missing generation rejected;
- candidate pointer to missing generation rejected;
- active pointer to non-ACTIVE generation rejected;
- candidate pointer to ACTIVE rejected;
- candidate pointer to RETIRED rejected;
- active and candidate same version rejected;
- tracked version greater than last allocated rejected;
- gaps below lastAllocatedVersion allowed;
- no requirement to retain every retired generation forever.

## GREEN

Implement only the invariants required by the tests. No transition logic belongs in Core.

## Gate

```bash
vendor/bin/pest tests/Unit/Core
composer analyse
composer lint
```

## Commit

```text
feat(core): add lifecycle control state primitives
```

## Self-review

Must explicitly check:

- M1 policy-free enum rule;
- Core standard-library-only boundary;
- no persistence encoding in Core;
- no lifecycle transition methods;
- no audit-log assumption;
- future retired-generation pruning remains possible.

---

# Task 2 — FilterControlStore contract + Memory reference store

Task 2 locks persistence semantics before lifecycle orchestration depends on them.

## Owned source files

Create:

```text
src/Contracts/FilterControlStore.php

src/Contracts/Exception/FilterControlWriteConflict.php
src/Contracts/Exception/FilterControlStoreOperationFailed.php
src/Contracts/Exception/FilterControlStateCorrupt.php

src/Drivers/Memory/MemoryFilterControlStore.php
```

Create shared contract tests:

```text
tests/Contract/FilterControlStoreContractTestCase.php
tests/Contract/MemoryFilterControlStoreTest.php
```

## Contract

Conceptually:

```php
interface FilterControlStore
{
    public function read(FilterName $name): ?FilterControlState;

    public function compareAndSwap(
        FilterControlState $next,
        ?FilterStateRevision $expectedRevision,
    ): void;
}
```

Do not add semantic persistence operations such as:

```text
promote()
markVerified()
reserveCandidate()
retireActive()
```

Those would leak lifecycle policy into drivers.

## RED first

The shared contract must prove:

- missing state returns null;
- create requires expected revision null;
- create persists revision 1;
- second create conflicts;
- successful update requires exact current revision;
- update requires next revision = current + 1;
- stale expected revision conflicts;
- skipped revision is rejected;
- state from another FilterName cannot replace the current key;
- failed CAS leaves previous state unchanged;
- returned state is semantically equivalent to stored state.

The Memory driver becomes the executable reference for this contract.

## Gate

```bash
vendor/bin/pest tests/Contract/MemoryFilterControlStoreTest.php
composer analyse
composer lint
```

## Commit

```text
feat(control): add revisioned control store contract
```

## Self-review

Check:

- storage owns CAS, not lifecycle legality;
- no silent last-write-wins;
- no distributed lock introduced;
- exceptions distinguish conflict, operation failure, and corruption.

---

# Task 3 — Lifecycle transition policy

## Owned source files

Create under:

```text
src/Lifecycle/
tests/Unit/Lifecycle/
```

Suggested focused type:

```text
LifecycleTransitionPolicy
```

Exact class naming may be adjusted only if the same responsibility remains explicit and singular.

## RED first — exact matrix

Allowed:

```text
Configured -> Building
Configured -> Retired

Building   -> Shadow
Building   -> Retired

Shadow     -> Verified
Shadow     -> Retired

Verified   -> Active   [not through generic transition API]
Verified   -> Retired

Active     -> Retired
```

Generic transition policy must reject:

- every unlisted transition;
- all outgoing transitions from Retired;
- direct generic transition to Active.

`Shadow -> Verified` must not be exposed as an evidence-free generic state mutation. The implementation should reserve that transition for the verification workflow introduced in Task 4.

## Gate

```bash
vendor/bin/pest tests/Unit/Lifecycle
composer analyse
composer lint
```

## Commit

```text
feat(lifecycle): add explicit generation transition policy
```

## Self-review

Confirm:

- lifecycle mutation never changes health implicitly;
- no Redis/Illuminate dependency;
- activation remains a separate operation;
- Retired is terminal only in M4 policy, not encoded in the Core enum.

---

# Task 4 — Activation verification

## Owned source files

Create under:

```text
src/Lifecycle/
tests/Unit/Lifecycle/
```

Expected responsibilities:

```text
ActivationVerifier
ActivationVerificationResult
ActivationVerificationStatus
```

Use the smallest public surface that preserves the locked semantics.

## Input boundary

The verifier accepts a streaming:

```text
iterable<NormalizedValue>
```

It must not accept raw application/database values and must not perform normalization.

It uses existing:

```text
BloomProbeGenerator
BloomDriver
BloomLayout
FilterName
FilterVersion
```

## RED first

Tests must prove:

- every authoritative-present item returning true => Passed;
- first false => FalseNegativeDetected;
- empty authoritative-present stream may pass with checkedCount 0;
- checkedCount is exact;
- result is bound to FilterName + FilterVersion;
- no raw failed value is exposed in result;
- BloomDriver operational failures propagate;
- storage corruption propagates;
- no Inconclusive result masks typed failures;
- no sample-size API can authorize verification.

## Applying evidence

Separate tests must prove a passed result can move only the same current candidate:

```text
SHADOW -> VERIFIED
```

Evidence must be rejected when:

- FilterName differs;
- FilterVersion differs;
- candidate changed;
- candidate no longer tracked;
- candidate no longer Shadow.

A health change alone must not invalidate valid version-bound evidence.

## Gate

```bash
vendor/bin/pest tests/Unit/Lifecycle
composer analyse
composer lint
```

## Commit

```text
feat(lifecycle): add candidate activation verification
```

## Self-review

Confirm:

- verification proves coverage of the supplied complete/reconciled stream only;
- completeness/reconciliation is caller responsibility;
- false positives are not treated as verification failure;
- sampling is not activation evidence;
- failure taxonomy is preserved.

---

# Task 5 — Candidate allocation, promotion, deactivation, and active-generation policy

## Owned files

Only `src/Lifecycle/**` and `tests/Unit/Lifecycle/**`.

Do not modify persistence drivers in this task.

## RED first

### Candidate allocation

Prove:

- empty state allocates v1;
- later allocation uses lastAllocatedVersion->next();
- candidate starts Configured + Unavailable;
- existing candidate blocks a second candidate;
- retired/failed version is never reused;
- allocation computes a new immutable snapshot with revision +1;
- CAS conflict is surfaced by the store boundary and requires reload/retry by the caller; no hidden infinite retry loop.

### Promotion

Prove:

- candidate must exist;
- requested candidate version must match;
- candidate lifecycle must be Verified;
- candidate health must be Healthy;
- prior active, if any, becomes Retired;
- candidate becomes Active;
- activeVersion changes to candidate;
- candidateVersion becomes null;
- state revision increments exactly once;
- data-plane driver methods are not called during pure control-plane promotion.

### Explicit deactivation

Prove:

- current active may be retired explicitly;
- activeVersion becomes null;
- no candidate is implicitly promoted.

### Active-generation policy

Prove:

```text
ACTIVE + HEALTHY current active -> eligible version
ACTIVE + DEGRADED              -> not eligible
ACTIVE + STALE                 -> not eligible
ACTIVE + UNAVAILABLE           -> not eligible
VERIFIED + HEALTHY             -> not eligible
SHADOW + HEALTHY               -> not eligible
missing active pointer         -> not eligible
```

The policy must not claim that the authoritative lookup may be skipped. It only selects a control-plane-safe active generation for later probing.

## Commit

```text
feat(lifecycle): add candidate promotion and active generation policy
```

## Self-review

Check:

- no Membership result is produced here;
- no authoritative query occurs;
- normalization identity is not falsely claimed to be verified;
- promotion does not call BloomDriver destroy/provision/copy operations.

---

# Task 6 — Structured Redis executor contract

M3's existing `RedisCommandExecutor::evaluate(): int` must remain source-compatible.

## Owned files

Create:

```text
src/Contracts/Redis/RedisStructuredCommandExecutor.php
```

Update only the existing Laravel Redis adapter as required to implement the additive child contract.

Add focused tests under existing Redis/Laravel adapter test areas.

## RED first

Prove:

- existing integer EVAL behavior is unchanged;
- structured EVAL can return a strict list of strings;
- unexpected reply shape is rejected;
- Redis transport/client failures normalize exactly as M3 requires;
- programming/configuration errors are not swallowed.

Do not replace the existing Redis command port with a broad `mixed command(...)` abstraction.

## Commit

```text
feat(redis): add structured eval command port
```

## Self-review

Confirm:

- M3 Bloom driver contract is unchanged;
- non-Laravel layers do not import Illuminate;
- PhpRedis/Predis exception normalization stays at the adapter boundary.

---

# Task 7 — Redis control keyspace and strict codec

## Owned files

Extend/create only Redis control-plane files under:

```text
src/Drivers/Redis/
tests/Unit/Drivers/Redis/
```

The existing generation keys must remain byte-for-byte unchanged.

Add:

```text
<prefix>:{<filter-name>}:state
```

## Locked format

```text
format = control-v1
```

Fields:

```text
format
revision
last_allocated_version
active_version?        [absent when null]
candidate_version?     [absent when null]

g:<version>:lifecycle
g:<version>:health
```

## RED first

Prove:

- deterministic state key;
- same logical-filter hash tag as M3 data-plane keys;
- strict canonical decimal encoding;
- exact lifecycle codec;
- exact health codec;
- nullable pointer field absence;
- unknown format => corruption;
- unknown top-level field => corruption;
- malformed generation field => corruption;
- incomplete lifecycle/health pair => corruption;
- unknown lifecycle/health token => corruption;
- duplicate semantic version representation impossible/rejected;
- decoded snapshot revalidates Core invariants.

No TTL is part of the representation.

## Commit

```text
feat(redis): add strict control state format
```

## Self-review

Confirm:

- no Core enum becomes backed;
- no schema field is silently ignored;
- M3 :meta/:bf format is unchanged;
- the codec owns persistence strings, not Core.

---

# Task 8 — Atomic Redis control store

## Owned files

Create focused Redis control-store/script classes under `src/Drivers/Redis/**` plus tests.

Use the existing framework-neutral executor boundary.

## Required semantics

Read:

- missing key => null;
- wrong Redis type => FilterControlStateCorrupt;
- malformed strict schema => FilterControlStateCorrupt;
- valid state => decoded FilterControlState.

CAS:

1. validate Redis key type and existing schema before mutation;
2. validate expected revision;
3. validate new snapshot payload;
4. atomically replace the control HASH;
5. never assign TTL.

Suggested private script statuses:

```text
100 OK
200 REVISION_CONFLICT
201 STORAGE_CORRUPT
```

Private codes are not public API.

## RED first

Unit/script protocol tests must prove validation-before-mutation ordering and exact status mapping.

Do not add lifecycle transition knowledge to Lua.

## Gate

```bash
vendor/bin/pest tests/Unit/Drivers/Redis
composer analyse
composer lint
```

## Commit

```text
feat(redis): add atomic lifecycle control store
```

## Self-review

Confirm:

- Lua knows CAS/storage shape only;
- lifecycle legality remains in Lifecycle;
- failed CAS leaves state unchanged;
- corrupt state is never treated as missing;
- no destructive interaction with generation :meta/:bf keys.

---

# Task 9 — Live Redis contract, corruption, and concurrency evidence

## Owned tests

Add Redis-group tests only.

Run the same shared:

```text
FilterControlStoreContractTestCase
```

against `RedisFilterControlStore`.

Required live evidence:

- create/read round trip;
- exact update;
- stale CAS conflict;
- two-writer conflict;
- failed writer does not overwrite winner;
- wrong Redis type;
- malformed control-v1;
- unknown field;
- unknown format;
- no TTL after create/update;
- same filter keys remain same-slot-compatible by construction;
- existing BloomDriver Redis contract still passes.

Where practical, use the existing test-only Redis/RESP infrastructure rather than creating a second ad-hoc Redis client abstraction.

## Gate

```bash
composer test:redis
composer check
```

## Commit

```text
test(redis): verify lifecycle control store against real redis
```

## Self-review

Confirm:

- tests prove real atomic behavior, not only mocked command mapping;
- no Redis Cluster support claim is introduced without Cluster evidence;
- live Redis failures remain separated from storage corruption.

---

# Task 10 — Architecture enforcement

Extend executable architecture tests.

Required rules:

```text
Core        -> PHP/SPL only
Contracts   -> Core
Lifecycle   -> Core + Contracts
Drivers     -> Core + Contracts
Laravel     -> internal layers + Illuminate
```

Explicitly prove:

- Drivers do not depend on Lifecycle;
- Lifecycle does not depend on Drivers;
- non-Laravel M4 files do not import Illuminate;
- Core state objects do not contain persistence tokens/Redis knowledge.

## Commit

```text
test(arch): enforce m4 lifecycle boundaries
```

## Self-review

No documented dependency rule may exist without executable coverage where the current Pest architecture setup can enforce it.

---

# Task 11 — Canonical documentation and ADR sync

Only after source behavior is green, update documentation to describe what is now executable.

## Owned docs

Update:

```text
docs/architecture/lifecycle.md
docs/architecture/overview.md
docs/architecture/redis-keyspace.md
docs/architecture/redis-foundation.md
README.md
CHANGELOG.md
```

Create:

```text
docs/adr/0034-revisioned-lifecycle-control-plane.md
docs/adr/0035-candidate-verification-explicit-promotion.md
docs/adr/0036-redis-control-plane-persistence.md
```

Documentation must explicitly record:

- raw M3 `destroy -> provision` remains a driver primitive;
- M4-managed generations may not be destructively rebuilt/reused in place;
- active/healthy means control-plane probe eligibility, not final query-skip authorization;
- runtime normalization identity remains an M5 design blocker;
- control-v1 is strict;
- control state is current correctness state, not an audit log;
- retired records are retained in M4 but future pruning must preserve lastAllocatedVersion.

Do not advertise M5 APIs.

## Commit

```text
docs: record m4 lifecycle and verification contracts
```

## Self-review

Verify documentation matches executable behavior exactly. No aspirational behavior may be described as implemented.

---

# Task 12 — Full internal verification gate

Before external review:

```bash
composer validate --strict
composer lint
composer analyse
composer test:fast
composer check
composer test:redis
composer test:all
```

Required evidence:

- all existing M1/M2/M3 regression suites green;
- new Core unit suite green;
- lifecycle policy matrix green;
- verification suite green;
- shared Memory control-store contract green;
- shared Redis control-store contract green;
- Redis corruption/concurrency suite green;
- Laravel Redis adapter structured-response tests green;
- architecture boundary tests green;
- Laravel 12 / PHP 8.3 compatibility anchor green;
- Laravel 13 / PHP 8.5 compatibility anchor green where CI capacity permits the repository's established compatibility workflow.

Do not weaken or remove an existing test to make M4 green without a separate explicit review.

---

# Task 13 — Final internal self-review

Review the complete M4 diff against these categories.

## Scope

Must contain only:

- Core control-state primitives;
- control-store contracts/exceptions;
- Memory reference control store;
- Lifecycle policies/workflows;
- activation verification;
- additive structured Redis port;
- Redis control-plane persistence;
- tests;
- M4 documentation/ADRs.

Must not contain M5 integration.

## Invariants

Re-check all M4 invariants, especially:

- one active / one candidate;
- lifecycle and health remain independent;
- version never reused;
- CAS prevents lost updates;
- verification evidence is version-bound;
- false negative prevents verification;
- sampled verification cannot activate;
- promotion is explicit and control-plane-only;
- managed generation is never destructively rebuilt in place;
- corrupt/missing/operational-failure states remain distinct;
- control-v1 is strict;
- no TTL;
- active-generation policy does not claim final query-skip safety.

## Dependency direction

Confirm no reverse dependency was introduced.

## Complexity

Reject:

- distributed locks without demonstrated need;
- event sourcing/audit log;
- generalized workflow engine;
- broad Redis `mixed` command API;
- background workers;
- retry loops with hidden unbounded behavior;
- premature rollback/retention implementations.

## Brownfield safety

Confirm existing M2/M3 public contracts remain source-compatible unless an independently approved breaking change is required.

## Verification evidence

No PR-ready status while any required gate is red.

---

# External-review handoff gate

After Task 13 passes:

1. freeze the candidate head SHA;
2. summarize changed public contracts and safety invariants;
3. attach exact fast + Redis verification evidence;
4. perform external review on that frozen head;
5. resolve findings without scope expansion;
6. rerun affected verification after every fix.

M4 is not merge-ready until external review is complete.

---

# Merge gate

Expected PR:

```text
feat: add M4 lifecycle and verification
```

Preferred merge method:

```text
squash
```

Before merge:

- implementation branch must be based/reconciled with current `main`;
- full required verification must be green on the final head;
- documentation must match the final head;
- no unresolved review finding may remain.

M5 must **not** start automatically after M4 merge.

A separate M5 scope/design gate is mandatory, beginning with the unresolved runtime normalization-identity compatibility problem.

---

# File ownership / parallelism rules

Default execution is serial because M4 touches shared contracts and invariants.

Parallel work is allowed only after shared types/contracts are frozen and only for disjoint ownership.

Safe later parallel candidates may include:

```text
A: Lifecycle unit tests/policies
B: Redis codec/script tests
C: documentation preparation
```

but only if they do not edit the same shared files.

Never assign these shared files to multiple agents simultaneously:

```text
src/Core/FilterControlState.php
src/Contracts/FilterControlStore.php
src/Contracts/Redis/RedisStructuredCommandExecutor.php
docs/architecture/lifecycle.md
docs/adr/0034-*.md
docs/adr/0035-*.md
docs/adr/0036-*.md
```

Every delegated task must specify:

- objective;
- owned files;
- read-only dependencies;
- forbidden files;
- required tests;
- expected evidence;
- self-review checklist.

No agent may opportunistically modify shared contracts outside its ownership.

---

# Stop conditions

Stop implementation and reopen design review if any of the following appears:

- control-state CAS cannot be represented without changing the accepted Core semantics;
- Redis structured-result support requires a breaking M3 public-contract change;
- activation verification requires raw application values in Lifecycle;
- safe promotion requires a cross-plane Redis/data transaction;
- runtime normalization identity becomes necessary to make M4's own claimed semantics correct;
- real Redis evidence contradicts the strict control-v1 model;
- implementation reveals a required lifecycle transition not covered by the accepted matrix.

Do not solve a stop condition by silently widening M4.
