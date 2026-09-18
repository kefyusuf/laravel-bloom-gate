# Filter Lifecycle

Lifecycle and operational health are independent axes.

Planned lifecycle:

```text
CONFIGURED -> BUILDING -> SHADOW -> VERIFIED -> ACTIVE -> RETIRED
```

Health:

```text
HEALTHY | DEGRADED | STALE | UNAVAILABLE
```

An ACTIVE filter may still be STALE or UNAVAILABLE. In that state it cannot short-circuit an authoritative lookup.

Activation is always explicit.
