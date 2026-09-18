# M1 — Core Semantics Implementation Plan

**Status:** DESIGN GATE  
**Production Bloom operations:** NOT IN SCOPE  
**Base:** M0 merged on `main`

This plan must be implemented only after the M1 design gate is accepted.

## Prerequisite P0 — Fix the fast test gate before Core code

M0's current fast script runs only:

```text
tests/Arch
tests/Feature
```

M1 introduces `tests/Unit`, so the implementation branch must first:

1. add a Unit testsuite to `phpunit.xml.dist`;
2. change `composer test:fast` so Unit tests are executed;
3. prove the updated gate fails on a deliberately broken local/branch test during development, then restore it;
4. run the normal CI gate.

### Self-review

- Scope: PASS — this is required verification infrastructure for M1.
- Safety: PASS — prevents unexecuted unit tests from creating false confidence.
- Complexity: PASS.
- Evidence: executable.

No M1 Core class may be merged before P0 is green.

---

# Implementation branch

Create:

```text
feat/m1-core-semantics
```

from the M1-design-approved `main`.

---

# Step 1 — FilterName

Create:

```text
src/Core/FilterName.php
tests/Unit/Core/FilterNameTest.php
```

Required tests:

- valid 1-byte name;
- valid 128-byte name;
- reject empty;
- reject >128 bytes;
- reject leading/trailing separators;
- reject colon, slash, braces, whitespace, Unicode;
- preserve case;
- equality and inequality;
- no Illuminate dependency.

Verification:

```bash
composer lint
composer analyse
vendor/bin/pest tests/Unit/Core/FilterNameTest.php
```

Commit:

```text
feat(core): add filter name value object
```

Self-review must pass before Step 2.

---

# Step 2 — FilterVersion

Create:

```text
src/Core/FilterVersion.php
tests/Unit/Core/FilterVersionTest.php
```

Required tests:

- version 1 is valid;
- zero rejected;
- negative rejected;
- arbitrary positive version retained;
- `next()` returns a new version;
- original object remains unchanged;
- `PHP_INT_MAX` overflow rejected;
- equality and inequality.

Commit:

```text
feat(core): add filter generation version
```

Self-review must pass before Step 3.

---

# Step 3 — Closed semantic enums

Create:

```text
src/Core/Membership.php
src/Core/LifecycleState.php
src/Core/HealthState.php
tests/Unit/Core/MembershipTest.php
tests/Unit/Core/LifecycleStateTest.php
tests/Unit/Core/HealthStateTest.php
```

Tests verify exact case sets and that enums are unbacked.

No policy helpers are added.

Commit:

```text
feat(core): add membership lifecycle and health states
```

Self-review must confirm lifecycle and health remain independent.

---

# Step 4 — BypassReason

Create:

```text
src/Core/BypassReason.php
tests/Unit/Core/BypassReasonTest.php
```

Tests:

- all six built-in reason factories;
- custom valid reason code;
- 1- and 64-byte boundaries;
- reject empty and >64;
- reject uppercase, whitespace, slash, colon, braces;
- equality and inequality.

Commit:

```text
feat(core): add extensible bypass reason
```

Self-review must confirm no arbitrary exception-catching policy has leaked into Core.

---

# Step 5 — NormalizedValue

Create:

```text
src/Core/NormalizedValue.php
tests/Unit/Core/NormalizedValueTest.php
```

Tests:

- empty bytes preserved;
- ordinary ASCII preserved exactly;
- UTF-8 byte sequence preserved exactly;
- embedded NUL preserved;
- `length()` is byte length;
- exact equality;
- no implicit normalization;
- no magic string conversion.

Commit:

```text
feat(core): add byte-exact normalized value
```

Self-review must confirm there is no trimming, casing, encoding, hashing, or Redis behavior.

---

# Step 6 — Architecture enforcement

Extend architecture verification so every new M1 class is covered by the existing non-Illuminate boundary.

Add an explicit test that M1 Core files do not import:

```text
Illuminate
Redis
Predis
Eloquent
Symfony
```

Do not add third-party architecture packages beyond the existing test stack.

Commit if needed:

```text
test(arch): harden core dependency boundary
```

---

# Step 7 — M1 documentation sync

Update only documentation made true by implementation:

- README milestone status;
- CHANGELOG Unreleased;
- architecture/core-semantics status.

Do not advertise M2/M3 APIs.

Commit:

```text
docs: record M1 core semantics
```

---

# M1 verification gate

Required before PR becomes ready:

```bash
composer validate --strict
composer lint
composer analyse
composer test:fast
composer check
```

CI evidence:

- Quality PASS;
- PHP 8.3 + Laravel 12 anchor PASS;
- PHP 8.5 + Laravel 13 anchor PASS;
- Redis 8 environment bootstrap PASS.

Redis Bloom commands are still not expected in M1.

---

# M1 final self-review

## Scope

Must contain only Core semantics, unit/architecture tests, and documentation.

Must not contain:

```text
BloomDriver
RedisCommandExecutor
RedisBloomDriver
MemoryBloomDriver
FilterStateStore
BloomManager
CheckMembership
TransitionPolicy
Redis key builder
BF.ADD
BF.EXISTS
Eloquent synchronization
Facade behavior
Artisan Bloom commands
```

## Invariants

Verify:

- names are exact/case-sensitive;
- versions start at 1;
- membership has exactly 3 cases;
- lifecycle and health are separate;
- bypass reason is extensible and does not absorb programming errors;
- normalized values are exact bytes;
- no Illuminate dependency exists in Core.

## Evidence

No merge while any fast, compatibility, static-analysis, architecture, or unit-test gate is red.

---

# Merge

PR title:

```text
feat: add M1 core semantics
```

Preferred merge method:

```text
squash
```

M2 does not start automatically after merge. A separate design gate is required.
