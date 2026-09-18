# Core Semantics

**Milestone:** M1  
**Status:** implemented pending M1 merge

M1 introduces only the smallest framework-independent vocabulary required by later drivers and application use cases.


## Scope

M1 contains exactly these domain types:

- `FilterName`
- `FilterVersion`
- `Membership`
- `LifecycleState`
- `HealthState`
- `BypassReason`
- `NormalizedValue`

M1 does **not** contain:

- `BloomDriver`
- `RedisCommandExecutor`
- `BloomManager`
- membership lookup services
- lifecycle transition policies
- runtime state aggregates
- Redis key formatting
- Redis commands
- Eloquent integration
- filter synchronization

Those belong to later milestones.

---

## M1-D01 — Keep M1 semantic-only

The milestone defines vocabulary and invariants, not orchestration.

### Self-review

- Scope alignment: PASS — matches the M1 boundary established after M0.
- ADR consistency: PASS — no accepted ADR is superseded.
- Dependency direction: PASS — all types live under `Core`.
- Complexity: PASS — no service, repository, policy, or framework abstraction is introduced.
- Greenfield/brownfield safety: PASS — value types have no runtime side effects.
- Verification: PASS — every type receives direct unit tests.

**Decision:** ACCEPTED.

---

## M1-D02 — Immutable value objects, explicit factories

`FilterName`, `FilterVersion`, `BypassReason`, and `NormalizedValue` are immutable final readonly value objects.

Factories are explicit:

```php
FilterName::fromString(...)
FilterVersion::fromInt(...)
BypassReason::fromCode(...)
NormalizedValue::fromBytes(...)
```

The underlying scalar is exposed only through explicit accessors.

No M1 value object implements magic string conversion.

Invalid construction uses SPL exceptions:

- `InvalidArgumentException`
- `OverflowException`

A package-specific exception hierarchy is intentionally deferred until a real cross-use-case need appears.

### Self-review

- Scope alignment: PASS.
- Invariant consistency: PASS — construction is the single invariant boundary.
- Dependency direction: PASS — SPL/PHP only.
- Complexity: PASS — avoids premature exception taxonomy and serialization interfaces.
- Backward compatibility: PASS — scalar access remains explicit rather than relying on implicit coercion.
- Verification: PASS — invalid boundary datasets and equality tests are required.

**Decision:** ACCEPTED.

---

## M1-D03 — FilterName is exact, case-sensitive identity

A filter name is not normalized by Core.

Valid names:

- contain 1 to 128 ASCII bytes;
- begin and end with an ASCII alphanumeric character;
- may contain ASCII letters, digits, dot, underscore, and hyphen internally.

Canonical validation expression:

```text
\A[A-Za-z0-9](?:[A-Za-z0-9._-]{0,126}[A-Za-z0-9])?\z
```

Examples:

```text
products.sku
users_email
tenant-42.orders.external_id
A1
```

Rejected examples include:

```text
.products
products.
products:sku
products/{sku}
products sku
ürün.sku
```

Identity is case-sensitive:

```text
products.sku != Products.Sku
```

The package does not lowercase names implicitly.

### Self-review

- Scope alignment: PASS.
- Redis keyspace ADR consistency: PASS — braces and colon are excluded and the value remains safe inside a hash tag.
- Unnecessary restriction: PASS — case is preserved rather than silently rewritten.
- Brownfield safety: PASS — existing configuration identifiers are never mutated.
- Future compatibility: PASS — 128 bytes provides a bounded operational identifier while supporting realistic namespacing.
- Verification: PASS — boundary lengths, separators, forbidden characters, Unicode, and case sensitivity are unit tested.

**Decision:** ACCEPTED.

---

## M1-D04 — FilterVersion is a positive monotonic generation number

A filter generation version is represented by a positive PHP integer:

```text
1 <= version <= PHP_INT_MAX
```

Version `0` and negative values are invalid.

`next()` returns a new immutable version and throws `OverflowException` when called at `PHP_INT_MAX`.

The value object does **not** allocate versions and does not solve concurrent rebuild allocation. Allocation belongs to the later control-plane/state-store layer.

Versions are not timestamps and not UUIDs.

### Self-review

- Scope alignment: PASS.
- Versioned-rebuild ADR consistency: PASS.
- Distributed-systems concern: PASS — allocation/concurrency is explicitly not hidden inside the value object.
- Complexity: PASS — compact monotonic generations are sufficient for v1.
- Verification: PASS — zero, negative, normal increment, equality, and overflow are covered.

**Decision:** ACCEPTED.

---

## M1-D05 — Membership remains a closed semantic enum

`Membership` is a pure enum with exactly:

```text
DefinitelyAbsent
MaybePresent
Bypassed
```

It is intentionally **not** a backed enum.

Persistence, Redis encoding, logs, and metrics must not make the Core enum's storage representation part of the domain contract.

The enum does not expose helpers such as `canSkipAuthoritativeLookup()`.

The effective skip decision still requires lifecycle, health, active-version, and driver safety checks at a later application-layer gate.

### Self-review

- ADR-0012 consistency: PASS.
- Safety: PASS — membership alone cannot claim that a lookup may be skipped.
- Layering: PASS — storage encoding stays outside Core.
- Extensibility: PASS — no Redis/metrics representation leaks into the type.
- Verification: PASS — the exact closed case set is tested.

**Decision:** ACCEPTED.

---

## M1-D06 — LifecycleState carries no transition policy

`LifecycleState` is a pure enum with:

```text
Configured
Building
Shadow
Verified
Active
Retired
```

The enum describes state only.

It does not decide:

- which transitions are legal;
- whether a generation may be activated;
- whether a state is terminal;
- whether a query may be short-circuited.

Those rules belong to the later Lifecycle policy layer.

### Self-review

- ADR-0008 consistency: PASS.
- Dependency direction: PASS.
- Separation of concerns: PASS — data is not mixed with policy.
- Complexity: PASS.
- Verification: PASS — exact cases are tested; transition tests wait for the policy milestone.

**Decision:** ACCEPTED.

---

## M1-D07 — HealthState is independent and policy-free

`HealthState` is a pure enum with:

```text
Healthy
Degraded
Stale
Unavailable
```

The enum does not provide `isSafe()` or equivalent behavior.

A healthy state alone is insufficient for short-circuiting because the lifecycle and active-version conditions must also be satisfied.

### Self-review

- ADR-0018 consistency: PASS.
- Safety: PASS — prevents health from becoming an accidental one-axis authorization gate.
- Separation of concerns: PASS.
- Verification: PASS — exact cases and lifecycle/health independence are documented and unit tested.

**Decision:** ACCEPTED.

---

## M1-D08 — BypassReason is extensible, not a closed enum

Bypass reasons are operational vocabulary and may grow as integrations evolve.

Therefore `BypassReason` is an immutable value object rather than an enum.

Its code:

- is 1 to 64 ASCII characters;
- starts with a lowercase ASCII letter;
- may contain lowercase letters, digits, dot, underscore, and hyphen.

Canonical grammar:

```text
\A[a-z][a-z0-9._-]{0,63}\z
```

Core defines named factories for the initial package reasons:

```text
optimization_disabled
lifecycle_not_active
health_not_healthy
active_version_unavailable
backend_unavailable
operation_failed
```

`fromCode()` remains available so future adapters can report a stable reason without adding a Core enum case.

`operation_failed` is reserved for a **known, recoverable Bloom/backend operation failure that has been classified at the infrastructure/application boundary**. It must never mean “catch any `Throwable` and continue”.

An unknown filter/configuration typo, invariant violation, type error, or unexpected programming exception is **not** a bypass reason. Configuration/programming errors should not silently become optimization bypasses.

### Self-review

- Scope alignment: PASS.
- Fail-open ADR consistency: PASS — known operational failures can explain fallback.
- API evolution: PASS — adding a new operational reason does not add a breaking enum case.
- Error masking: PASS — programming/configuration errors are explicitly excluded from the taxonomy.
- Observability: PASS — stable machine-readable codes are available.
- Verification: PASS — grammar, built-ins, custom reason, equality, and invalid codes are tested.

**Decision:** ACCEPTED.

---

## M1-D09 — NormalizedValue represents exact bytes

`NormalizedValue` stores the exact byte sequence emitted by a normalizer.

It performs no:

- trim;
- lowercase/uppercase conversion;
- Unicode normalization;
- integer conversion;
- encoding conversion.

The factory is:

```php
NormalizedValue::fromBytes(string $bytes)
```

Empty bytes are valid because Core must not invent a business rule that Bloom filters themselves do not require.

Binary strings, including NUL bytes, are valid.

Public behavior is intentionally small:

```text
bytes()
length()
equals()
```

There is no `__toString()`; this avoids accidental logging/coercion of binary or sensitive values.

### Self-review

- ADR-0020 consistency: PASS.
- Correctness: PASS — every later operation can use one byte-exact value.
- Business neutrality: PASS — Core does not reject empty values or impose text semantics.
- Security/privacy: PASS — no implicit string conversion encourages accidental output.
- Redis portability: PASS — binary representation remains driver-neutral.
- Verification: PASS — empty, ASCII, UTF-8 bytes, NUL bytes, byte length, and exact equality are tested.

**Decision:** ACCEPTED.

---

## M1-D10 — Do not create FilterRuntimeState yet

Although a later runtime model will combine lifecycle, health, and version metadata, M1 does not create that aggregate.

The required invariants for a runtime aggregate are not fully known until the state-store and lifecycle policy contracts are designed.

### Self-review

- YAGNI: PASS.
- Scope alignment: PASS.
- Architecture consistency: PASS.
- Future compatibility: PASS — avoids freezing an aggregate before its persistence and transition rules exist.

**Decision:** ACCEPTED.

---

## M1-D11 — Core does not encode persistence strings for closed enums

`Membership`, `LifecycleState`, and `HealthState` remain pure enums.

Any later Redis/control-plane representation must be encoded by a dedicated boundary/codec rather than by making persistence values the Core enum backing values.

### Self-review

- Control-plane/data-plane separation: PASS.
- Framework/driver independence: PASS.
- Public contract minimization: PASS.
- Complexity: PASS — no codec is created in M1; only the boundary is reserved.

**Decision:** ACCEPTED.

---

# M1 invariants

## INV-M1-001 — Core values are immutable

A constructed Core value object cannot change its identity.

## INV-M1-002 — Filter names are never silently normalized

Case and exact spelling are part of filter identity.

## INV-M1-003 — Generation zero does not exist

A valid `FilterVersion` starts at 1.

## INV-M1-004 — Membership does not authorize lookup skipping by itself

`DefinitelyAbsent` becomes actionable only after later safety gates have approved the filter state.

## INV-M1-005 — Lifecycle and health remain orthogonal

Neither enum derives or mutates the other.

## INV-M1-006 — Bypass is not a programming-error sink

Unknown filters and invalid configuration are not silently converted to `Bypassed`.

## INV-M1-007 — NormalizedValue is byte-exact

Once constructed, no Core layer may mutate or reinterpret its bytes.

## INV-M1-008 — M1 has zero Illuminate dependencies

All M1 source files live under `Kefyusuf\BloomGate\Core` and use PHP/SPL only.

---

# Public surface planned for M1

The following public surface is implemented by M1.

```php
FilterName::fromString(string $value): FilterName
FilterName::value(): string
FilterName::equals(FilterName $other): bool

FilterVersion::fromInt(int $value): FilterVersion
FilterVersion::value(): int
FilterVersion::next(): FilterVersion
FilterVersion::equals(FilterVersion $other): bool

enum Membership
{
    case DefinitelyAbsent;
    case MaybePresent;
    case Bypassed;
}

enum LifecycleState
{
    case Configured;
    case Building;
    case Shadow;
    case Verified;
    case Active;
    case Retired;
}

enum HealthState
{
    case Healthy;
    case Degraded;
    case Stale;
    case Unavailable;
}

BypassReason::fromCode(string $code): BypassReason
BypassReason::optimizationDisabled(): BypassReason
BypassReason::lifecycleNotActive(): BypassReason
BypassReason::healthNotHealthy(): BypassReason
BypassReason::activeVersionUnavailable(): BypassReason
BypassReason::backendUnavailable(): BypassReason
BypassReason::operationFailed(): BypassReason
BypassReason::code(): string
BypassReason::equals(BypassReason $other): bool

NormalizedValue::fromBytes(string $bytes): NormalizedValue
NormalizedValue::bytes(): string
NormalizedValue::length(): int
NormalizedValue::equals(NormalizedValue $other): bool
```

No additional public methods should be added opportunistically during M1 implementation.
