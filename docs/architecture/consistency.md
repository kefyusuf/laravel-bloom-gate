# Consistency Model

The authoritative datastore remains the source of truth.

Bloom state is an optimization structure. It must never become a second authority for positive membership.

M5 supports exactly two managed consistency contracts.

## `immutable-v1`

Use `immutable-v1` when authoritative membership does not change while the generation is active.

Properties:

- build from the authoritative-present set;
- fresh verification before activation;
- no managed membership synchronization writes after activation;
- `BloomGate::add()` / `addMany()` are rejected for the active immutable generation.

This is the simplest trusted-negative consistency model.

## `preadd-v1`

Use `preadd-v1` only when every membership-entry write can obey this ordering:

```text
1. add the value to the active Bloom generation
2. commit/create the authoritative membership
```

The critical invariant is that an authoritative-present value must not become visible before the Bloom add that protects the negative query gate.

A retry-safe Bloom add before the authoritative write may create a false positive if the authoritative write later fails. That is safe: false positives cause authoritative lookups.

The unsafe inverse ordering is:

```text
authoritative write
then Bloom add
```

because a concurrent query could observe an authoritative-present value while the Bloom filter still says absent.

## Explicit managed writes

M5 exposes managed synchronization through the Application layer:

```text
MembershipAdder::add()
MembershipAdder::addMany()
```

The Laravel facade delegates to those operations.

Managed writes:

- resolve the current active generation;
- require its persisted semantic contract to match the runtime definition;
- normalize with the exact runtime normalizer;
- use the persisted active layout;
- use the bounded managed bulk-write path.

They are not generic database observers.

## Why observers are not trusted-negative authority

Eloquent observers/model events cannot prove that every authoritative write is covered.

They can miss, among other paths:

- raw SQL;
- Query Builder writes that bypass model events;
- imports/ETL;
- maintenance scripts;
- another service;
- external writers.

Observers may be useful application plumbing, but M5 does not treat observer-based eventual synchronization as sufficient authority for trusted negatives.

## Candidate build and concurrent writes

A candidate is built from an authoritative-present stream.

For `preadd-v1`, writes can race with the build. Therefore activation requires an explicit **quiescent membership-entry window**.

Under that window M5 performs:

```text
full candidate reconciliation
    ->
fresh full verification
    ->
promotion
```

The quiescent window must cover reconciliation, verification, and promotion.

The package does not implement a distributed writer barrier in M5. The caller/operator is responsible for establishing the quiescent condition before using `--quiescent`.

## Fresh verification

Activation never relies solely on an earlier verification result.

It performs fresh verification against the authoritative-present stream immediately before promotion.

A detected false negative blocks promotion.

## Fail-open query semantics

Any uncertainty that prevents proving the consistency contract causes the query optimization to bypass.

That includes:

- missing semantic binding;
- consistency fingerprint mismatch;
- unavailable/corrupt generation storage;
- changed active revision/version;
- unsafe backend state.

Bypass means the authoritative source is queried.

## Deferred consistency mechanisms

M5 does not claim:

- CDC/outbox synchronization;
- online dual-write rebuild;
- writer barriers;
- cross-service membership coordination;
- background reconciliation workers;
- observer-only trusted-negative authority.

Those require separate later architecture.
