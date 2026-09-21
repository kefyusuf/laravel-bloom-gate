# M5 — Safe Laravel Query Integration Implementation Plan

**Status:** DRAFT — scope/design gate passed; implementation must not start until this plan is separately reviewed and approved  
**Design gate:** PASSED  
**Base:** `main@f1fd47e8e8318b5b9e40c3e5b9d38b4353aa398a`  
**Planned implementation branch:** `feat/m5-safe-laravel-query-integration`  
**Plan branch:** `docs/m5-safe-laravel-query-integration-plan`

This plan implements the accepted M5 product/scope consolidation for safe Laravel existence/uniqueness acceleration. It deliberately does **not** turn Laravel Bloom Gate into a general distributed transaction, CDC, or online-rebuild coordinator.

---

## 1. Product goal

M5 turns the existing M2/M3/M4 Bloom data plane and lifecycle/control plane into a package-facing Laravel query gate.

The milestone must make this end-to-end behavior executable:

```text
application lookup
      |
      v
registered FilterDefinition
      |
      v
normalize exactly once
      |
      v
safe active generation?
      |
      +---- no / uncertain ----> authoritative lookup
      |
      v
atomic Bloom authorization + membership probe
      |
      +---- MAYBE -------------> authoritative lookup
      |
      +---- BYPASS ------------> authoritative lookup
      |
      `---- DEFINITELY ABSENT -> skip authoritative lookup
```

The caller-facing contract is correctness-preserving:

- `exists(...)` returns an authoritative-correct boolean;
- a Bloom positive never becomes authoritative truth;
- a Bloom negative may skip the authoritative lookup only after all M5 safety preconditions are satisfied;
- infrastructure uncertainty fails open to the authoritative source;
- configuration/programming errors do not silently become bypasses.

---

## 2. Product positioning

M5 targets explicit, high-value membership/existence use cases such as:

- registered email existence;
- username/slug availability;
- immutable/external identifiers;
- idempotency keys;
- known hashes;
- append-oriented membership sets;
- validation-backed `unique` / `exists` acceleration.

M5 is **not** a general SQL optimizer.

The first public query primitive is canonical equality-style membership:

```text
one application value
      ->
one canonical NormalizedValue
      ->
one logical authoritative membership predicate
```

No M5 API may claim safe support for arbitrary:

- LIKE / ILIKE;
- range predicates;
- full-text predicates;
- regex/fuzzy predicates;
- JSON containment;
- arbitrary Builder closures;
- arbitrary SQL expressions.

---

## 3. Explicit non-goals

M5 must not implement:

- global Eloquent interception;
- global Query Builder interception;
- model traits as correctness authority;
- Eloquent observers as correctness authority;
- automatic query rewriting;
- package-owned database transactions;
- `BloomGate::mutate(...)` transaction wrappers;
- online dual-write rebuild coordination;
- writer tickets or drain barriers;
- a revisioned `sync-v1` coordinator;
- CDC or outbox integrations;
- Redis Sentinel production-support claims;
- Redis Cluster runtime-support claims;
- replica reads for trusted negatives;
- failover/backend epoch attestation;
- automatic rebuild scheduling;
- retention/purge automation;
- rollback automation;
- `first()`, `find()`, `get()`, `count()` query APIs;
- arbitrary custom consistency protocols;
- composite-key canonical encoding;
- native RedisBloom driver replacement of the M3 bitmap driver.

The following remain M6+ concerns:

```text
zero-downtime online rebuild
dual-write candidate coordination
writer barriers
sync-v1
Sentinel / Cluster hardening
backend epoch/failover attestation
CDC/outbox adapters
retention/purge automation
background topology monitoring
```

---

## 4. Locked design inputs

M5 implementation must preserve all previously accepted behavior, including:

- ADR-0005 — side-effect-free installation;
- ADR-0006 — explicit integration over magic;
- ADR-0007 — fail open;
- ADR-0008 — explicit filter lifecycle;
- ADR-0009 — versioned rebuild;
- ADR-0010 — synchronization is a contract;
- ADR-0011 — no delete semantics in v1;
- ADR-0012 — semantic membership results;
- ADR-0018 — lifecycle/health separation;
- ADR-0020 — stable normalization contract;
- ADR-0021 — Cluster-aware keyspace;
- ADR-0030 — byte-exact normalized values;
- ADR-0031 — extensible bypass reasons;
- ADR-0032 — stock Redis bitmap data plane;
- ADR-0033 — atomic Redis driver scripts;
- ADR-0034 — revisioned lifecycle control plane;
- ADR-0035 — candidate verification and explicit promotion;
- ADR-0036 — Redis control-plane persistence.

No accepted M0–M4 ADR is superseded by M5 unless a separate explicit ADR says so.

Planned M5 ADRs:

- ADR-0037 — Generation-scoped semantic compatibility fingerprints;
- ADR-0038 — Explicit filter definitions and managed Bloom sizing;
- ADR-0039 — Safe query gate and atomic authorized Redis probe;
- ADR-0040 — M5 consistency contracts and quiescent activation boundary.

---

## 5. Locked M5 safety model

A trusted negative requires all of the following:

```text
global/filter optimization enabled
ACTIVE + HEALTHY current active generation
generation layout valid
normalization fingerprint match
authoritative-set fingerprint match
consistency-contract fingerprint match
supported Redis production profile
valid generation storage
Bloom probe = definitely absent
atomic authorization/probe semantics
```

Any known operational uncertainty becomes:

```text
Membership::Bypassed
+
BypassReason
+
authoritative lookup
```

Unknown filters, invalid definitions, invalid configuration, type errors, and programming errors must not be converted into `Bypassed`.

---

## 6. Supported M5 production Redis profile

M5's initial production-support claim is intentionally narrow:

```text
Redis 8 standalone
authoritative primary
PhpRedis verified path
no replica reads
maxmemory-policy = noeviction
AOF enabled
appendfsync = always
package-owned keyspace
```

This is a **support-claim boundary**, not a Redis configuration manager.

M5 must provide diagnostics/preflight evidence for this profile, but it must not:

- rewrite Redis configuration;
- claim Sentinel support;
- claim Cluster runtime support;
- claim replica-read safety;
- treat `WAIT` / `WAITAOF` as a complete correctness proof.

If production preconditions cannot be established, package-facing query optimization must not claim trusted-negative safety.

---

## 7. Public M5 surface

Canonical framework-neutral application services:

```text
QueryGate
MembershipAdder
ManagedFilterBuilder
ManagedFilterVerifier
ManagedFilterActivator
CandidateDiscarder
```

Laravel-facing convenience:

```text
BloomGate facade
BloomUnique validation rule
BloomExists validation rule

bloom:build
bloom:verify
bloom:activate
bloom:discard
bloom:status
bloom:doctor
```

Primary query methods:

```php
exists(string $filter, string|int $value): bool

existsResult(string $filter, string|int $value): ExistenceResult
```

Primary mutation methods:

```php
add(string $filter, string|int $value): void

addMany(string $filter, iterable $values): void
```

No public API may expose a raw Bloom boolean as authoritative existence.

---

## 8. FilterDefinition contract

M5 uses explicit semantic definitions.

Conceptually:

```php
interface FilterDefinition
{
    public function normalizer(): ValueNormalizer;

    public function authoritativeSet(): AuthoritativeSet;

    public function consistency(): ConsistencyContract;
}
```

Exact method names may receive naming-only refinement during Task 1, but responsibilities must not merge or expand.

### ValueNormalizer

```php
interface ValueNormalizer
{
    public function identity(): NormalizationIdentity;

    public function normalize(string|int $value): NormalizedValue;
}
```

Requirements:

- identity is explicit and stable;
- identity describes output semantics, not PHP implementation details;
- class names, object hashes, source hashes, closures, and arbitrary Laravel config serialization are not semantic identity;
- every output-affecting semantic/config change must change the identity;
- raw values are normalized exactly once per application operation.

### AuthoritativeSet

```php
interface AuthoritativeSet
{
    public function identity(): AuthoritativeSetIdentity;

    public function exists(NormalizedValue $value): bool;

    /** @return iterable<string|int> */
    public function values(): iterable;
}
```

Requirements:

- `exists()` is authoritative;
- `values()` provides a fresh, complete, streaming traversal for build/verification;
- `values()` may be a safe superset of `exists()` semantics;
- if `exists(K)` may return true, build/verification coverage must include the same canonical membership key;
- `values()` must not require array materialization;
- Laravel implementations may use `cursor()`, `lazyById()`, or equivalent streaming access.

### ConsistencyContract

M5 supports only:

```text
immutable-v1
preadd-v1
```

`immutable-v1`:

- no new membership may enter the authoritative set while the generation is relied upon for trusted negatives.

`preadd-v1`:

- every membership-entering authoritative commit must be preceded by a successful `BloomGate::add(...)` for the canonical value;
- DB rollback after Bloom add is acceptable because it creates only extra bits / false positives;
- DB commit followed by later Bloom add violates the contract.

Arbitrary custom consistency protocols are deferred.

---

## 9. Generation semantic compatibility

Each managed `FilterName + FilterVersion` generation has a query/build descriptor composed from the already-provisioned `BloomLayout` plus immutable fingerprints for:

```text
BloomLayout
normalization semantics
authoritative-set semantics
consistency-contract semantics
```

The layout remains owned by the existing M3 generation metadata; M5 must not persist a second divergent copy. M5 descriptor reads reconstruct the layout from the canonical M3 metadata and combine it with the bound semantic fingerprints.

Recommended metadata field names:

```text
normalization_fingerprint
authoritative_set_fingerprint
consistency_fingerprint
```

Fingerprint algorithm:

```text
sha256(domain-separated canonical identity)
```

Requirements:

- fingerprints are generation-scoped;
- binding is write-once;
- rebinding the same value is idempotent;
- rebinding a different value is a typed conflict;
- fingerprints are additive M3 generation metadata;
- they do not belong in strict `control-v1`;
- old M3 generations without fingerprints remain valid low-level Bloom storage but are M5 query-skip-ineligible;
- changing any semantic identity requires a new managed generation.

---

## 10. Managed sizing contract

Laravel users configure:

```php
'capacity' => 1_000_000,
'false_positive_rate' => 0.001,
```

They do not configure `bitCount`, `hashCount`, or `ProbeAlgorithm` in M5.

Inputs:

```text
n = positive capacity
p = finite target false-positive probability where 0 < p < 1
```

Sizing policy identifier:

```text
optimal-v1
```

Base optimal estimate:

```text
m* = -(n ln p) / (ln 2)^2
k* = (m* / n) ln 2
```

M5 chooses an integer `k` deterministically, then recalculates the minimum integer `m` for that chosen `k` so the configured target FPR is not silently weakened:

```text
m = ceil(
    -k n / ln(1 - p^(1/k))
)
```

The resulting layout must pass existing M2 invariants:

```text
1 <= hashCount <= 64
hashCount <= bitCount
bitCount <= 2,147,483,647 for sha256-double-hash-v1
```

No silent clamping is allowed.

If the requested sizing exceeds protocol limits, return a typed configuration/sizing error.

`capacity` is an expected distinct-membership design target, not a hard correctness limit. Runtime/build count exceeding it must not create false negatives by itself.

---

## 11. Managed lifecycle workflow

### Build

```text
allocate candidate
CONFIGURED + UNAVAILABLE
        |
        v
CONFIGURED -> BUILDING
        |
        v
provision Bloom layout
        |
        v
bind immutable semantic fingerprints
        |
        v
stream AuthoritativeSet::values()
        |
        v
normalize + bounded bulk add
        |
        v
health -> HEALTHY
        |
        v
BUILDING -> SHADOW
```

Important ordering:

- candidate is not query-active during provisioning/binding;
- generation semantic metadata is bound before authoritative values are considered a completed build;
- build does not auto-verify;
- build does not auto-promote.

### Verify

```text
SHADOW + HEALTHY
        |
        v
resolve current definition
        |
        v
verify semantic fingerprints still match
        |
        v
fresh complete AuthoritativeSet::values()
        |
        v
normalize with same normalizer
        |
        v
M4 ActivationVerifier
        |
        +-- pass --> SHADOW -> VERIFIED
        |
        `-- false negative --> candidate remains non-promotable; mark STALE
```

Sampling is not activation evidence.

### Activate

`bloom:activate` performs **fresh complete verification immediately before promotion**.

For `immutable-v1`:

```text
fresh verify
    ->
VERIFIED + HEALTHY
    ->
promote
```

For `preadd-v1`:

```text
operator establishes quiescent membership-entry window
    ->
fresh verify
    ->
promote in same command invocation
    ->
release quiescent window
```

M5 does not implement the quiescence mechanism. It requires an explicit operator acknowledgment such as `--quiescent`.

A previously VERIFIED candidate does not replace fresh activation verification for `preadd-v1`.

### Discard

`bloom:discard`:

- applies only to the current non-active candidate;
- retires the candidate;
- clears the candidate pointer;
- never modifies the active generation;
- does not require immediate physical Redis deletion.

Retention/purge remains M6.

---

## 12. Required implementation order

The task order below is mandatory.

Do not start a later task until:

1. current task RED tests exist;
2. implementation turns them GREEN;
3. focused verification is green;
4. explicit self-review passes;
5. commit is made with the planned ownership boundary intact.

No opportunistic cross-task implementation.

---

# Task 0 — implementation branch and baseline gate

Only after this plan is separately approved:

1. fetch latest `main`;
2. confirm M4 remains complete and compatible with this plan;
3. create `feat/m5-safe-laravel-query-integration` from the exact verified main head;
4. run baseline verification before modifying source.

Required baseline:

```bash
composer validate --strict
composer check
composer test:redis
```

If `main` changed in a way that invalidates this plan, stop and reopen M5 design review.

**No M5 source file may be created before Task 0 is green.**

Commit: none.

Self-review:

- exact base recorded;
- no unreviewed M4 drift;
- existing fast + Redis suites green.

---

# Task 1 — semantic identity value objects and fingerprint protocol

## Goal

Create the smallest framework-neutral vocabulary for M5 semantic compatibility.

## Expected ownership

Create focused value objects under `src/Core/**`, for example:

```text
NormalizationIdentity
AuthoritativeSetIdentity
ConsistencyContract
SemanticFingerprint
```

A single generic fingerprint value object is acceptable only if the API prevents domain confusion at call sites. Otherwise use domain-specific fingerprint wrappers.

Create a framework-neutral fingerprint calculator under Core/Application only if it has no framework/infrastructure knowledge.

## RED first

Tests must prove:

- valid non-empty stable identities;
- deterministic equality;
- identity length/grammar constraints are explicit and bounded;
- normalization, authoritative-set, and consistency identities use domain-separated hashing;
- identical identity -> identical fingerprint;
- different identity/domain -> different fingerprint;
- fingerprint format is canonical;
- fingerprint calculation does not use class/object/source identity;
- `immutable-v1` and `preadd-v1` are the only built-in M5 consistency contracts.

## GREEN

Implement only explicit identities and deterministic SHA-256 fingerprinting.

Do not add Laravel config parsing here.

## Gate

```bash
vendor/bin/pest tests/Unit/Core
composer analyse
composer lint
```

## Commit

```text
feat(core): add m5 semantic compatibility identities
```

## Self-review

Confirm:

- PHP/SPL only;
- no Illuminate;
- no Redis fields in Core;
- no config serialization as identity;
- no arbitrary custom consistency strategy surface.

---

# Task 2 — FilterDefinition contracts

## Goal

Lock application-owned semantic sources before orchestration depends on them.

## Expected source

Create under `src/Contracts/**`:

```text
FilterDefinition
ValueNormalizer
AuthoritativeSet
```

Use existing Core `NormalizedValue`.

## RED first

Contract-focused tests must prove:

- normalizer accepts only `string|int`;
- normalizer returns `NormalizedValue`;
- normalizer exposes explicit semantic identity;
- authoritative `exists()` accepts `NormalizedValue`, not raw value;
- authoritative `values()` is iterable/streaming;
- definition exposes exactly normalizer + authoritative set + consistency contract;
- definition does not expose FilterName, Redis, driver, layout, capacity, FPR, or Laravel types.

No callable/closure contract may stand in for authoritative semantics.

## Commit

```text
feat(contracts): add explicit filter definition contracts
```

## Self-review

Confirm:

- filter semantics are explicit and testable;
- no Eloquent/Illuminate import;
- no arbitrary Builder closure;
- no duplicated FilterName inside definition.

---

# Task 3 — managed Bloom sizing policy

## Goal

Translate product-facing capacity/FPR into an existing M2 `BloomLayout`.

## Expected source

Create a pure framework-neutral sizing service, preferably under `src/Application/**` unless review shows a stronger Core ownership argument.

Suggested responsibility:

```text
OptimalBloomSizingV1
```

## RED first

Test:

- positive capacity required;
- finite `0 < p < 1` required;
- known deterministic vectors;
- `capacity=1_000_000, p=0.001` produces approximately the locked v1 layout result and exact executable value chosen by the implementation;
- target FPR is not weakened by integer hash-count rounding;
- result always passes `BloomLayout::create`;
- requests requiring `hashCount > 64` fail explicitly;
- requests requiring `bitCount > 2,147,483,647` fail explicitly;
- no silent clamp;
- only `Sha256DoubleHashV1` is selected in M5.

Add cross-PHP compatibility evidence for PHP 8.3 through supported latest anchor so floating-point rounding does not drift across the current support matrix.

## Gate

```bash
vendor/bin/pest <focused sizing tests>
composer analyse
composer lint
```

## Commit

```text
feat(application): add managed bloom sizing policy
```

## Self-review

Confirm:

- sizing is operational generation policy, not business semantic identity;
- capacity change implies new generation/rebuild, not new FilterDefinition semantics;
- no database count query occurs.

---

# Task 4 — generation semantic contract store

## Goal

Bind M5 semantic compatibility immutably to `FilterName + FilterVersion` without breaking `BloomDriver`.

## Contract

Create an additive framework-neutral port such as:

```text
GenerationContractStore
```

Required behavior:

```text
read(name, version) -> managed generation descriptor or unbound
bind(name, version, expected layout, semantic contract)
```

The returned managed generation descriptor contains:

```text
BloomLayout reconstructed/validated from canonical generation storage
normalization fingerprint
authoritative-set fingerprint
consistency fingerprint
```

The bind operation receives the expected layout only to prove it matches the already-provisioned M3 generation metadata; it must not persist a second layout representation.

## Memory reference implementation

Create a deterministic Memory implementation and shared contract suite.

## RED first

Prove:

- missing binding returns explicit absence;
- first bind succeeds only for an existing/provisioned managed generation;
- bind rejects an expected layout that differs from the provisioned M3 layout;
- read reconstructs the exact provisioned layout together with semantic fingerprints;
- repeated equal bind is idempotent;
- different rebind conflicts;
- sibling versions remain independent;
- no bind mutates lifecycle/control state;
- no raw identity strings need be persisted after fingerprint derivation.

## Commit

```text
feat(contracts): add generation semantic contract store
```

## Self-review

Confirm:

- `BloomDriver` source contract unchanged;
- semantic contract is generation-scoped;
- no `control-v1` schema change.

---

# Task 5 — Redis generation semantic metadata

## Goal

Persist generation semantic fingerprints as additive M3 generation metadata.

## Required metadata fields

```text
normalization_fingerprint
authoritative_set_fingerprint
consistency_fingerprint
```

Existing fields remain unchanged:

```text
format
bit_count
hash_count
probe_algorithm
```

## RED first

Tests must prove:

- existing M3 metadata without new fields remains valid low-level storage;
- M5 contract read reports unbound rather than corrupt for old generations;
- bind requires valid generation metadata;
- same-value bind is idempotent;
- different-value bind produces typed semantic-contract conflict;
- wrong Redis type/corrupt M3 metadata remains corruption;
- unknown additive M3 fields remain ignored by the M3 driver;
- no TTL is introduced;
- sibling versions isolated;
- binding never clears bitmap bits.

Add real Redis evidence.

## Commit

```text
feat(redis): bind generation semantic compatibility metadata
```

## Self-review

Confirm:

- M3 Bloom driver behavior remains source-compatible;
- control-v1 stays strict and unchanged;
- old M3 generations remain usable through low-level driver APIs but query-skip-ineligible in M5.

---

# Task 6 — bounded bulk Bloom driver capability

## Goal

Make million-row managed builds viable without one Redis round-trip per value.

## Contract

Add an additive child capability, conceptually:

```php
interface BulkBloomDriver extends BloomDriver
{
    /** @param list<BitPositions> $items */
    public function addMany(
        FilterName $name,
        FilterVersion $version,
        array $items,
    ): void;
}
```

Exact naming may receive naming-only review.

## RED first

Shared capability tests:

- empty batch behavior explicit;
- bounded non-empty batch;
- all positions validated against equivalent provisioned layout before mutation;
- layout mismatch causes no partial mutation;
- missing storage fails;
- corruption fails;
- repeated batch is retry-safe;
- duplicates are harmless;
- Memory and Redis implementations produce equivalent membership results.

Redis script tests must prove validation-before-mutation and bounded argument handling.

Real Redis evidence must cover a representative configured chunk size.

## Commit

```text
feat(driver): add bounded bulk bloom writes
```

## Self-review

Confirm:

- original `BloomDriver` unchanged;
- batch size remains application-controlled/bounded;
- no pipeline-specific semantics leak into Core.

---

# Task 7 — persisted lifecycle transitioner + explicit generation health updater

## Goal

Provide the missing framework-neutral M4-compatible state-mutation primitives needed by managed build/verification.

M4 already owns `LifecycleTransitionPolicy`, but it intentionally does not persist a legal generic transition. M5 must not duplicate snapshot reconstruction/CAS logic inside every Application workflow.

## Expected responsibilities

Create focused Lifecycle services such as:

```text
GenerationLifecycleTransitioner
GenerationHealthUpdater
```

### GenerationLifecycleTransitioner

Behavior:

- read current control state;
- find exact tracked generation;
- delegate legality to the existing `LifecycleTransitionPolicy`;
- replace only that generation lifecycle;
- preserve health;
- preserve active/candidate pointers unless the requested generic M4 transition itself is incompatible with current control invariants;
- revision +1;
- one CAS attempt;
- surface CAS conflict;
- no hidden retry loop.

It must **not** expose `SHADOW -> VERIFIED` or `VERIFIED -> ACTIVE`; those remain evidence/promotion-only workflows.

### GenerationHealthUpdater

Behavior:

- read current control state;
- find exact tracked generation;
- replace health only;
- preserve lifecycle;
- preserve active/candidate pointers;
- revision +1;
- one CAS attempt;
- surface CAS conflict;
- no hidden retry loop.

## RED first

Prove for both services:

- exact generation required;
- legal generic lifecycle transitions use the existing policy;
- illegal/evidence-only/promotion-only lifecycle transitions are rejected;
- lifecycle-only update preserves health;
- health-only update preserves lifecycle;
- sibling generations preserved;
- pointers preserved;
- revision increments exactly once;
- stale CAS conflicts;
- no driver method is called;
- neither service silently retries.

## Commit

```text
feat(lifecycle): add persisted lifecycle and health updates
```

## Self-review

Confirm ADR-0018 orthogonality and M4 evidence/promotion exclusivity remain intact.

---

# Task 8 — Laravel filter registry and lazy definition resolution

## Goal

Turn `config/bloom-gate.php` named filters into explicit, validated runtime definitions without boot-time side effects.

## Config shape

At minimum:

```php
'filters' => [
    'users.email' => [
        'enabled' => true,
        'definition' => App\Bloom\UsersEmailFilter::class,
        'capacity' => 1_000_000,
        'false_positive_rate' => 0.001,
    ],
],
```

## Expected Laravel responsibilities

Create under `src/Laravel/**`:

```text
ConfigFilterRegistry
FilterDefinitionResolver
validated filter configuration DTO/value
```

## RED first

Feature/unit tests:

- exact case-sensitive FilterName registry key;
- missing filter throws typed unknown-filter/config exception;
- invalid class throws;
- class not implementing FilterDefinition throws;
- invalid capacity/FPR throws;
- per-filter enabled flag resolved;
- global enabled flag resolved separately;
- definition is lazily resolved through Laravel container;
- package boot does not instantiate definitions;
- package boot does not contact DB or Redis;
- config-cache-compatible representation; no closures required.

## Commit

```text
feat(laravel): add explicit m5 filter registry
```

## Self-review

Confirm:

- ADR-0005 and ADR-0006 preserved;
- invalid config is not converted to bypass;
- semantic identity remains in definition objects, not duplicated in config.

---

# Task 9 — managed candidate builder

## Goal

Implement `build` orchestration over existing M4 lifecycle and M2/M3 data plane.

## Expected Application responsibility

Create a focused service such as:

```text
ManagedFilterBuilder
```

## Required flow

```text
resolve registered definition
validate sizing
allocate candidate
CONFIGURED -> BUILDING through persisted Lifecycle transitioner
provision layout
bind semantic contract
stream authoritative values
normalize
bounded bulk add
health -> HEALTHY through explicit health updater
BUILDING -> SHADOW through persisted Lifecycle transitioner
```

Important correction:

Because M5 semantic fields live in the Redis generation `:meta` HASH, provision must create valid generation metadata **before** semantic binding. Candidate is not active during this intermediate state, so this does not authorize unsafe queries.

## RED first

Prove:

- no existing candidate -> allocates next version;
- existing candidate -> refuses new build;
- sizing errors occur before data build;
- lifecycle reaches BUILDING before managed data population;
- provisioned layout is exactly sizing result;
- semantic contract binds before completed build;
- authoritative values are streamed, not collected;
- same normalizer is used for every value;
- configured chunk_size bounds batch writes;
- empty authoritative set builds valid empty generation;
- successful build -> HEALTHY then SHADOW;
- build never auto-verifies;
- build never auto-promotes;
- operational failure leaves candidate visible/non-active;
- programming/normalizer error propagates and does not silently retire candidate;
- active generation remains untouched.

## Commit

```text
feat(application): add managed candidate build workflow
```

## Self-review

Check:

- no destructive rebuild;
- no DB transaction ownership;
- no hidden recovery;
- candidate failure remains observable.

---

# Task 10 — managed verification, activation, and discard workflows

## Goal

Compose existing M4 evidence/promotion semantics into safe package-facing workflows.

## Managed verification

Tests must prove:

- only current SHADOW + HEALTHY candidate is verifiable;
- current FilterDefinition resolves;
- persisted fingerprints must exactly match runtime definition fingerprints;
- fresh authoritative stream is used;
- same normalizer is used;
- M4 ActivationVerifier remains the false-negative authority;
- Passed -> evidence applied -> VERIFIED;
- FalseNegativeDetected -> candidate health becomes STALE and promotion is blocked;
- driver/storage operational failures remain typed failures;
- no sampling API.

## Managed activation

Canonical `activate` performs fresh complete verification immediately before promotion.

For `immutable-v1`:

- no quiescent assertion required.

For `preadd-v1`:

- explicit quiescent acknowledgment required;
- missing acknowledgment rejects activation before verification/promotion;
- fresh verification and promotion occur in one command/service invocation;
- no package-owned lock or write pause mechanism is implied.

Tests must prove a previously VERIFIED candidate is not accepted as fresh evidence for `preadd-v1` without the activation-time verification pass.

## Candidate discard

Tests:

- only current non-active candidate can be discarded;
- candidate -> RETIRED;
- candidate pointer cleared;
- active generation unchanged;
- no immediate data-plane destroy required;
- no retention/purge implementation.

## Commit

```text
feat(application): add managed verify activate and discard workflows
```

## Self-review

Confirm:

- M4 verification and promotion remain separate primitives;
- Application composes them without weakening either;
- quiescence is an explicit operational contract, not a hidden lock.

---

# Task 11 — query safety descriptor resolution + authorized probe contract

## Goal

Separate package-facing query authorization from raw `BloomDriver::mightContain` while preserving Core ownership of probe generation.

A query cannot generate `BitPositions` until it knows the exact active generation `BloomLayout`. That layout is generation-scoped and must not be inferred from current config because config may have changed before a rebuild.

## Framework-neutral query preparation

Create a focused resolver/value such as:

```text
QuerySafetyDescriptorResolver
QuerySafetyDescriptor
```

The resolver composes existing ports:

```text
FilterControlStore
GenerationContractStore
ActiveGenerationPolicy
```

A descriptor contains at minimum:

```text
FilterName
pinned FilterStateRevision
expected active FilterVersion
BloomLayout
normalization fingerprint
authoritative-set fingerprint
consistency fingerprint
```

Descriptor resolution must fail open when active/control/generation metadata is missing or unsafe.

## Authorized probe contract

Create a framework-neutral port/result such as:

```text
AuthorizedProbe
AuthorizedProbeResult
```

The probe request receives:

- pinned control revision;
- expected active version;
- exact managed generation descriptor/layout;
- layout-bound `BitPositions`;
- expected semantic fingerprints.

It must not receive raw application values.

Semantic outcomes:

```text
DefinitelyAbsent
MaybePresent
Bypassed + BypassReason
```

## Memory/reference implementation

Implement deterministic reference behavior using current control store, generation-contract store, and Memory Bloom driver.

The reference path may use optimistic read/probe/revalidation semantics; it exists to pin correctness behavior, not Redis command count.

## RED first

Prove:

- missing control state -> bypass;
- no active version -> bypass;
- non-ACTIVE/non-HEALTHY generation -> bypass;
- missing semantic binding -> bypass;
- descriptor layout comes from the managed generation, never current sizing config;
- fingerprint mismatch -> bypass;
- control revision/active version change between preparation and final probe -> bypass;
- missing/corrupt Bloom storage -> never becomes absent;
- MaybePresent remains MaybePresent;
- safe negative becomes DefinitelyAbsent;
- configuration/programming failures are not swallowed.

## Commit

```text
feat(application): add query safety descriptors and authorized probe contract
```

## Self-review

Confirm:

- Membership alone still does not authorize lookup skipping outside this gate;
- raw `BloomDriver` remains low-level and unchanged;
- query position generation continues to use Core `BloomProbeGenerator`.

---

# Task 12 — revision-pinned atomic Redis authorized probe

## Goal

Provide the M5 production decision point without dynamic/undeclared Redis key access.

Redis scripts must receive every key they access through `KEYS[]`. The active version is stored inside `control-v1`, so M5 must **not** construct/access a generation key dynamically inside Lua after discovering the version. That would undermine the Cluster-aware key discipline reserved since ADR-0021.

Therefore the production flow is:

```text
resolve QuerySafetyDescriptor
    -> pinned revision R, active version V, layout L
generate BitPositions in PHP/Core
    ->
one atomic Redis authorization+probe EVAL using:
    state key
    meta(V) key
    bitmap(V) key
```

The EVAL is the final trusted-negative serialization point. No post-probe revalidation is required because it revalidates the pinned state and probes membership atomically.

This is **not** a promise that the entire cold query path is one Redis round-trip. Correctness takes priority over dynamic undeclared keys. Future measured descriptor caching may reduce steady-state metadata reads only if the final EVAL continues to revalidate every cached revision/version/descriptor assumption.

## Required Redis keys

All keys are known before EVAL and share the logical-filter hash tag:

```text
<prefix>:{<filter-name>}:state
<prefix>:{<filter-name>}:v:<expected-version>:meta
<prefix>:{<filter-name>}:v:<expected-version>:bf
```

## Required arguments

The operation receives expected:

```text
FilterStateRevision
active FilterVersion
normalization fingerprint
authoritative-set fingerprint
consistency fingerprint
layout/probe arguments
bit positions
```

## Required atomic checks

Within one Lua operation:

1. strictly validate current `control-v1`;
2. require current revision == expected pinned revision;
3. require current active version == expected version;
4. require tracked expected version lifecycle ACTIVE;
5. require tracked expected version health HEALTHY;
6. validate the supplied generation metadata key is the expected version's canonical M3 storage;
7. validate M3 storage format/layout;
8. require all three M5 semantic fingerprints;
9. compare expected fingerprints exactly;
10. validate bitmap type/presence semantics;
11. read all requested bits;
12. return one structured semantic result.

Result protocol:

```text
ABSENT
MAYBE
BYPASS:<stable reason>
```

Use the existing structured Redis executor where appropriate.

Do not create a second lax `control-v1` parser. Extract/reuse strict validation fragments if necessary so control semantics cannot drift between CAS and query scripts.

Do not access Redis keys that were not supplied through `KEYS[]`.

## RED first

Unit/script tests must prove:

- all accessed keys are explicit script keys;
- pinned revision mismatch -> bypass;
- pinned active-version mismatch -> bypass;
- validation-before-membership decision;
- ACTIVE + HEALTHY only;
- old unbound generation -> bypass;
- each fingerprint mismatch -> distinct stable bypass reason;
- corrupt control state -> never absent;
- corrupt generation metadata -> never absent;
- missing generation storage -> never absent;
- wrong types -> never absent;
- layout mismatch -> never absent;
- valid empty provisioned bitmap semantics remain correct;
- maybe result only when all bits set;
- absent only after all authorization checks pass;
- script mutates no key.

Real Redis tests must verify the final authorization+membership decision is one EVAL invocation after descriptor preparation.

## Commit

```text
feat(redis): add revision-pinned authorized bloom probe
```

## Self-review

Confirm:

- no dynamic/undeclared generation key access;
- no INFO/ROLE/admin calls on the hot path;
- same-slot construction preserved;
- no Redis Cluster runtime-support claim introduced;
- any future descriptor cache can only affect performance, never final EVAL validation.

---

# Task 13 — QueryGate and authoritative fallback

## Goal

Expose authoritative-correct existence behavior.

## Result object

Create:

```text
ExistenceResult
```

Required behavior:

```text
exists(): bool
membership(): Membership
bypassReason(): ?BypassReason
```

Invariants:

```text
DefinitelyAbsent -> exists=false, bypassReason=null
MaybePresent     -> authoritative lookup executed, bypassReason=null
Bypassed         -> authoritative lookup executed, bypassReason!=null
```

## QueryGate RED first

Prove:

- raw `string|int` normalized exactly once;
- QuerySafetyDescriptor supplies the generation layout used for Core probe generation;
- current filter sizing config is never substituted for an already-active generation layout;
- global disabled -> bypass + authoritative lookup;
- filter disabled -> bypass + authoritative lookup;
- safe negative -> authoritative lookup not called;
- maybe -> authoritative lookup called exactly once;
- bypass -> authoritative lookup called exactly once;
- authoritative true/false preserved exactly;
- DB/authoritative exceptions propagate;
- unknown filter throws, not bypasses;
- invalid definition/config throws;
- normalizer programming errors propagate;
- no catch-all `Throwable -> Bypassed`.

`exists()` delegates to `existsResult()->exists()` semantics.

## Commit

```text
feat(application): add safe query gate
```

## Self-review

Confirm the caller never needs to understand probabilistic semantics to obtain a correct boolean.

---

# Task 14 — explicit membership add/addMany workflow

## Goal

Provide the M5 write-side primitive without taking ownership of DB transactions.

## Semantics

For a registered filter:

- query optimization enablement and active-generation synchronization are separate concerns;
- `bloom-gate.enabled=false` or a per-filter query-optimization disable must **not** silently stop synchronization of an already-active managed generation;
- if no active generation exists, `add/addMany` are explicit no-op/not-required operations because no trusted-negative active generation can be served; the void API remains unambiguous because success means "no synchronization failure requiring caller rollback";
- if an active managed generation exists, M5 resolves that generation's managed descriptor/layout and requires current definition fingerprints to match before mutation;
- the current active generation may still be synchronized while query optimization is bypassed for health/enablement reasons; synchronization itself must never mark it healthy or query-safe;
- for active managed `preadd-v1`, `add/addMany` must succeed before caller commits authoritative membership entry;
- semantic mismatch is a hard synchronization/configuration error for write-side `preadd-v1`; it must not silently no-op because a later config rollback could otherwise resurrect an unsafe old generation;
- operational write failure must propagate so caller can abort its DB transaction;
- no silent deferred synchronization;
- no Eloquent observer requirement;
- M5 never dual-writes a candidate generation.

Semantic-definition changes for a mutable pre-add filter therefore require an explicit deployment/rebuild/quiescent cutover rather than opportunistic rolling write behavior.

## RED first

Prove:

- same normalizer as query/build;
- no active generation -> successful no-op with no Bloom mutation;
- global query optimization disabled + active generation -> synchronization still occurs;
- per-filter query optimization disabled + active generation -> synchronization still occurs;
- active generation descriptor supplies the exact layout;
- semantic mismatch prevents write and propagates before caller DB commit;
- non-healthy active generation synchronization does not implicitly change health;
- add success is retry-safe;
- addMany uses bounded bulk capability;
- active Bloom operation failure propagates;
- filter config/programming failures propagate;
- no DB transaction opened by package;
- no candidate dual-write in M5.

## Commit

```text
feat(application): add explicit membership synchronization writes
```

## Self-review

Confirm:

- pre-add contract remains caller-owned ordering;
- M6 online rebuild coordination is not smuggled into M5.

---

# Task 15 — Laravel facade and service-provider wiring

## Goal

Expose Laravel-native ergonomics over the already-tested Application services.

## Required behavior

Register/bind:

- registry;
- definitions;
- QueryGate;
- MembershipAdder;
- managed lifecycle services;
- Redis driver/query ports;
- Facade accessor.

Facade surface at minimum:

```text
exists
existsResult
add
addMany
```

## RED first

Feature tests:

- dependency injection works;
- Facade delegates to same Application service;
- package boot remains side-effect-free;
- no filter definition is resolved at boot;
- no DB/Redis access at boot;
- config publishing remains stable.

## Commit

```text
feat(laravel): expose bloom gate facade and services
```

## Self-review

Confirm Facade is convenience only; Application service remains canonical.

---

# Task 16 — Laravel validation adapters

## Goal

Provide high-value Laravel-native `unique` / `exists` integration without duplicating query logic.

## Create

```text
BloomUnique
BloomExists
```

Rules must delegate exclusively to QueryGate.

## RED first

Prove:

- `BloomUnique(filter)` passes when authoritative-correct exists=false;
- fails when exists=true;
- `BloomExists(filter)` is the inverse;
- bypass behavior remains transparent because QueryGate performs authoritative fallback;
- no direct BloomDriver access from validation rules;
- custom Laravel `unique()->ignore()`, arbitrary where clauses, soft-delete variants are not silently claimed in M5.

## Commit

```text
feat(laravel): add bloom existence validation rules
```

## Self-review

Confirm rules are thin adapters, not a second correctness implementation.

---

# Task 17 — Artisan build/verify/activate/discard/status commands

## Goal

Expose managed lifecycle operations explicitly.

## Commands

```text
bloom:build <filter>
bloom:verify <filter>
bloom:activate <filter> [--quiescent]
bloom:discard <filter>
bloom:status [filter]
```

## RED first

Feature tests must prove:

### build

- unknown filter fails loudly;
- creates only candidate;
- no auto-verify/promote;
- command output reports candidate version/layout/processed count without leaking values;
- interrupted/failing build leaves candidate observable.

### verify

- requires current SHADOW + HEALTHY candidate;
- reports checked count/status;
- false negative does not promote.

### activate

- performs fresh verification;
- `immutable-v1` activates without quiescent flag;
- `preadd-v1` requires explicit quiescent acknowledgment;
- promotion occurs only after fresh pass;
- failure leaves existing active generation unchanged.

### discard

- never touches active generation;
- clears candidate ownership through retirement.

### status

Read-only output includes at minimum:

- filter enabled/registered status;
- active/candidate versions;
- lifecycle/health;
- layout;
- semantic-binding presence/match status;
- consistency contract;
- no raw membership values.

## Commit

```text
feat(console): add managed bloom lifecycle commands
```

## Self-review

Confirm no command hides lifecycle transitions or silently activates on build.

---

# Task 18 — bloom:doctor production-safety diagnostics

## Goal

Make M5's intentionally narrow production support profile observable before users trust negative query skipping.

## Required checks

At minimum:

```text
package config valid
Redis reachable
Redis version compatible with support claim
standalone mode / unsupported topology detection
authoritative primary connection
AOF enabled
appendfsync = always
maxmemory-policy = noeviction
keyspace prefix valid
registered FilterDefinition resolvable
capacity/FPR valid
active generation storage/layout valid when present
semantic bindings present/matching when active
control state valid
```

Doctor is diagnostic/preflight. It must not rewrite Redis configuration or mutate filter lifecycle.

If necessary, add a dedicated Laravel/infrastructure diagnostics port rather than broadening `RedisCommandExecutor` into an untyped general-purpose client.

## RED first

Prove:

- PASS/WARN/FAIL classifications deterministic;
- unsupported topology is visible;
- missing ACL permission to inspect a prerequisite is not reported as PASS;
- doctor itself does not activate/build/repair;
- no raw values/secrets printed.

## Commit

```text
feat(console): add bloom production safety diagnostics
```

## Self-review

Confirm hot query path does not call admin diagnostics.

---

# Task 19 — architecture enforcement

Extend executable architecture tests for M5.

Required direction:

```text
Core        -> PHP/SPL only
Contracts   -> Core
Lifecycle   -> Core + Contracts
Drivers     -> Core + Contracts
Application -> Core + Contracts + Lifecycle
Laravel     -> internal layers + Illuminate
```

Prove:

- Application does not import Illuminate;
- Contracts do not import Illuminate;
- Drivers do not depend on Application or Lifecycle;
- Laravel validation/facade/console may depend inward;
- Core identity/sizing values contain no Redis/Laravel persistence tokens;
- QueryGate depends on query-safety/authorized-probe ports, not concrete Redis classes;
- FilterDefinition contracts are framework-neutral.

## Commit

```text
test(arch): enforce m5 application boundaries
```

## Self-review

No documented dependency rule should remain unenforced where Pest architecture tests can reasonably enforce it.

---

# Task 20 — canonical M5 documentation and ADR sync

Only after executable behavior is green.

## Update

```text
README.md
CHANGELOG.md
docs/architecture/overview.md
docs/architecture/normalization.md
docs/architecture/consistency.md
docs/architecture/lifecycle.md
docs/architecture/redis-foundation.md
docs/architecture/redis-keyspace.md
```

Create:

```text
docs/adr/0037-generation-semantic-fingerprints.md
docs/adr/0038-explicit-filter-definitions-and-sizing.md
docs/adr/0039-safe-query-gate-atomic-authorized-probe.md
docs/adr/0040-m5-consistency-contracts.md
```

Documentation must explicitly state:

- active+healthy alone does not authorize query skip;
- M5 semantic fingerprint requirements;
- old unbound M3 generations are not corrupt but are query-skip-ineligible;
- authoritative source remains source of truth;
- observer-based eventual synchronization is not M5 trusted-negative authority;
- `preadd-v1` ordering contract;
- quiescent activation requirement;
- narrow supported Redis production profile;
- no Sentinel/Cluster runtime-support claim;
- no online dual-write rebuild in M5;
- validation rules are QueryGate adapters;
- `exists()` is authoritative-correct.

## Commit

```text
docs: document m5 safe laravel query integration
```

## Self-review

Confirm docs describe only executable behavior and do not pre-claim M6 support.

---

# Task 21 — full verification matrix and external-review handoff

Run the complete project matrix on the exact M5 head.

## Required commands

```bash
composer validate --strict
composer lint
composer analyse
composer test:fast
composer check
composer test:redis
composer test:all
```

Compatibility anchors must cover the repository's supported Laravel/PHP matrix.

Add focused live evidence for:

- Redis generation fingerprint binding;
- bulk Redis addMany;
- atomic authorized probe;
- fail-open corruption/missing-state behavior;
- no-TTL guarantees;
- M4 control-store regression;
- original M2/M3 BloomDriver contract regression.

## Regression requirements

Existing suites must remain green, especially:

```text
BloomDriverContractTestCase
FilterControlStoreContractTestCase
M2 probe golden vectors
M3 Redis corruption tests
M4 lifecycle verification tests
M4 control CAS evidence
```

## External review gate

Before merge-ready status:

1. whole-branch self-review;
2. inspect every new public API;
3. inspect Lua validation order and failure taxonomy;
4. inspect backward compatibility;
5. inspect Laravel boot side effects;
6. inspect safety claims vs actual automated evidence;
7. request external review;
8. resolve all actionable findings;
9. rerun exact final-head matrix.

No M6 implementation may begin from the M5 branch.

---

## 13. M5 commit sequence

Expected logical sequence:

```text
feat(core): add m5 semantic compatibility identities
feat(contracts): add explicit filter definition contracts
feat(application): add managed bloom sizing policy
feat(contracts): add generation semantic contract store
feat(redis): bind generation semantic compatibility metadata
feat(driver): add bounded bulk bloom writes
feat(lifecycle): add persisted lifecycle and health updates
feat(laravel): add explicit m5 filter registry
feat(application): add managed candidate build workflow
feat(application): add managed verify activate and discard workflows
feat(application): add query safety descriptors and authorized probe contract
feat(redis): add revision-pinned authorized bloom probe
feat(application): add safe query gate
feat(application): add explicit membership synchronization writes
feat(laravel): expose bloom gate facade and services
feat(laravel): add bloom existence validation rules
feat(console): add managed bloom lifecycle commands
feat(console): add bloom production safety diagnostics
test(arch): enforce m5 application boundaries
docs: document m5 safe laravel query integration
```

Commits may be combined only if review shows two adjacent tasks are mechanically inseparable without weakening the RED -> GREEN evidence trail.

---

## 14. Required self-review after every task

Before advancing, explicitly answer:

### Scope alignment

- Is this still M5?
- Did M6 coordination/failover/online rebuild behavior leak in?

### Invariant / ADR consistency

- Did any change weaken fail-open behavior?
- Can any path turn uncertainty into a trusted negative?
- Are lifecycle and health still independent?
- Is the authoritative datastore still the source of truth?

### Dependency direction

- Any Illuminate outside Laravel?
- Any Application dependency from Drivers/Core?
- Any Redis persistence token leaking into Core?

### Complexity

- Is there a simpler explicit contract?
- Did we add abstraction without a current M5 use case?
- Did a convenience API create a second correctness implementation?

### Brownfield safety

- Do old M2/M3/M4 APIs remain source-compatible?
- Do old generation metadata shapes remain valid at their original layer?
- Does installation remain side-effect-free?

### Verification evidence

- Is behavior proven by focused RED -> GREEN tests?
- Is any Redis claim backed by live Redis evidence?
- Are failure modes tested, not only happy paths?

A failed self-review blocks the next task.

---

## 15. Whole-M5 invariants

### INV-M5-001 — No semantic compatibility, no trusted negative

Runtime and generation normalization, authoritative-set, and consistency fingerprints must match exactly.

### INV-M5-002 — Old unbound generations fail open at M5

Missing M5 fingerprints are not M3 corruption, but they can never authorize query skipping.

### INV-M5-003 — Bloom positive is never authoritative

`MaybePresent` always requires the authoritative lookup.

### INV-M5-004 — Bypass still returns authoritative truth

`Bypassed` changes performance only.

### INV-M5-005 — Configuration errors are not bypasses

Unknown filters and invalid definitions fail loudly.

### INV-M5-006 — M5 normalization occurs exactly once per operation

Build, verify, add, and query use the same explicit normalizer contract.

### INV-M5-007 — Managed build is versioned

No active managed generation is destructively rebuilt in place.

### INV-M5-008 — Build does not activate

Build ends at SHADOW + HEALTHY.

### INV-M5-009 — Activation uses fresh complete verification

Promotion cannot rely solely on old verification evidence for mutable pre-add filters.

### INV-M5-010 — Pre-add ordering is explicit

For `preadd-v1`, Bloom synchronization precedes authoritative membership commit. Query-optimization disablement does not silently suspend synchronization of an already-active managed generation.

### INV-M5-011 — Package does not own application DB transactions

M5 provides membership synchronization primitives, not transaction wrappers.

### INV-M5-012 — Redis final trusted-negative decision is atomically authorized

After resolving a pinned active generation descriptor and generating positions in Core, the Redis final decision validates the pinned control revision/version, semantic metadata, storage/layout, and bit membership in one atomic EVAL operation. Redis scripts never discover an active version and then access undeclared dynamically constructed generation keys.

### INV-M5-013 — Active layout comes from generation storage

Query probes use the exact active generation layout reconstructed from managed generation metadata, never newly calculated current config sizing.

### INV-M5-014 — No replica trusted negatives

M5 production support is primary-only.

### INV-M5-015 — No eviction-based silent bitmap loss

The supported Redis profile requires `noeviction`.

### INV-M5-016 — Public `exists()` is authoritative-correct

Callers never need to interpret Bloom probability to get the correct boolean.

### INV-M5-017 — Laravel adapters remain thin

Facade, validation rules, and commands delegate to Application services rather than duplicating correctness logic.

### INV-M5-018 — M6 concerns stay out

No writer barriers, online dual-write rebuild, Sentinel/Cluster runtime claim, CDC/outbox, or backend epoch machinery appears in M5.

---

## 16. Plan approval gate

This document is the only artifact created by the current step.

Before M5 implementation starts:

1. review this plan as a whole;
2. validate task dependency ordering;
3. challenge public API and Redis Lua scope;
4. confirm M5/M6 boundary;
5. confirm no required safety invariant is missing;
6. mark the plan APPROVED only after review.

**Do not create `feat/m5-safe-laravel-query-integration` or any M5 production source/test file before that approval.**
