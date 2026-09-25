# Filter Lifecycle

M4 established the framework-neutral lifecycle/control plane.

M5 keeps that state machine and adds managed build, semantic binding, verification, activation, discard, status, and query-safety orchestration around it.

Lifecycle and operational health remain independent generation-scoped axes.

## Lifecycle states

```text
CONFIGURED
BUILDING
SHADOW
VERIFIED
ACTIVE
RETIRED
```

The generic lifecycle transition policy exposes:

```text
CONFIGURED -> BUILDING
CONFIGURED -> RETIRED

BUILDING   -> SHADOW
BUILDING   -> RETIRED

SHADOW     -> RETIRED

VERIFIED   -> RETIRED

ACTIVE     -> RETIRED
```

These two transitions remain privileged:

```text
SHADOW   -> VERIFIED   verification evidence only
VERIFIED -> ACTIVE     explicit promotion only
```

`RETIRED` has no outgoing transition.

## Operational health

```text
HEALTHY
DEGRADED
STALE
UNAVAILABLE
```

Lifecycle mutation does not implicitly rewrite health.

## Logical-filter control state

One logical filter owns a revisioned current snapshot containing:

- exact `FilterName`;
- monotonic `FilterStateRevision`;
- `lastAllocatedVersion`;
- optional active version;
- optional candidate version;
- tracked generation lifecycle + health.

Generation versions are never reused.

The control snapshot is current correctness state, not an audit log.

## M5 managed build

`bloom:build <filter>` invokes the managed build workflow.

The workflow:

1. resolves the registered `FilterDefinition`;
2. derives a deterministic layout from configured capacity/FPR;
3. allocates a new candidate version;
4. moves the candidate to `BUILDING`;
5. provisions exactly that layout;
6. binds normalization/authoritative-set/consistency fingerprints;
7. streams the authoritative-present set;
8. normalizes each value with the bound runtime normalizer;
9. writes Bloom positions through bounded managed bulk writes;
10. marks the candidate `HEALTHY`;
11. moves it to `SHADOW`.

Build does **not** auto-verify or auto-activate.

If an operational build fails after allocation, the candidate remains observable rather than being silently erased.

## Managed verification

`bloom:verify <filter>` requires the current candidate to be:

```text
SHADOW + HEALTHY
```

It requires the persisted generation semantic contract to match the current runtime definition.

Verification streams the complete authoritative-present set and checks that every value probes maybe-present.

A false negative:

- stops verification;
- prevents `SHADOW -> VERIFIED`;
- marks the candidate stale through verification evidence handling.

Passed evidence is bound to the exact `FilterName + FilterVersion`.

Sampling is not activation evidence.

## Managed activation

`bloom:activate <filter>` always performs fresh verification before promotion.

### `immutable-v1`

No quiescent flag is required.

The package freshly verifies the candidate and promotes only after a pass.

### `preadd-v1`

Activation requires:

```text
--quiescent
```

The acknowledgement means the caller/operator has established a quiescent membership-entry window.

Within that window M5 performs:

```text
full candidate reconciliation
    ->
fresh verification
    ->
promotion
```

The package does not implement the external writer barrier itself.

## Promotion

Promotion requires the current candidate to satisfy the activation workflow and be `VERIFIED + HEALTHY`.

On success:

- previous active generation -> `RETIRED`, when present;
- candidate -> `ACTIVE`;
- active pointer -> candidate;
- candidate pointer -> null.

Promotion changes control-plane ownership only. It does not move or copy Bloom storage.

## Discard

`bloom:discard <filter>` retires only the current candidate and clears candidate ownership.

It never retires or rewrites the active generation.

## Status

`bloom:status [filter]` is read-only.

It reports, when available:

- registered/enabled state;
- active/candidate versions;
- lifecycle;
- health;
- layout;
- semantic binding presence;
- semantic match state;
- consistency contract.

It does not print authoritative membership values.

## Query eligibility after activation

An active generation being:

```text
ACTIVE + HEALTHY
```

remains only one prerequisite.

M5 trusted-negative authorization additionally requires exact semantic fingerprints, valid generation storage/layout, a stable control revision/version, and the backend-specific authorized-probe safety checks.

Therefore activation never turns the Bloom filter into a positive source of truth and never makes lifecycle state alone sufficient to skip the authoritative source.

## Rebuild boundary

M5 managed replacement always uses a new generation version.

The raw M3 `destroy -> provision` primitive remains available to low-level driver users, but it is not the managed rebuild workflow.

M5 does not implement online dual-write rebuild or automatic candidate rollover. Those are deferred.
