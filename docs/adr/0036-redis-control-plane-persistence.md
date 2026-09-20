# ADR-0036: Redis control-plane persistence

**Status:** ACCEPTED

## Context

The M4 control plane needs a production Redis implementation of the same `FilterControlStore` semantics provided by the Memory reference store.

Redis persistence must distinguish missing state, stale-write conflict, corrupt state, and transport failure while keeping lifecycle policy outside the backend.

## Decision

Each logical filter owns one durable Redis control key:

```text
<prefix>:{<filter-name>}:state
```

The durable key is a HASH using strict `control-v1` persistence fields.

Atomic replacement uses one implementation-only same-slot staging key:

```text
<prefix>:{<filter-name>}:state:staging
```

The staging key is not correctness state and is not consulted by reads.

Required top-level fields:

```text
format = control-v1
revision
last_allocated_version
```

Optional pointer fields are absent when null:

```text
active_version
candidate_version
```

Tracked generations use:

```text
g:<version>:lifecycle
g:<version>:health
```

The schema is strict. Unknown fields, malformed/non-canonical numeric values, unknown lifecycle/health tokens, incomplete generation pairs, wrong Redis types, or Core-invariant violations are corruption.

The durable control key has no TTL.

Both durable and staging control keys use the same exact `{<filter-name>}` Redis hash tag as the generation `:meta` and `:bf` keys. This is same-slot-compatible construction, not a Redis Cluster runtime-support claim.

Redis CAS is implemented as one Lua/EVAL operation. The script validates:

1. Redis key type;
2. current `control-v1` state;
3. expected revision/conflict;
4. complete proposed `control-v1` state;
5. proposed revision progression;

before any correctness-state replacement.

After validation, the script:

1. clears the transient staging key;
2. writes the proposed snapshot to staging using bounded HSET chunks;
3. cleans staging and returns the Redis error if a staged write fails;
4. atomically replaces the durable state with `RENAME staging -> state`;
5. cleans staging and returns the Redis error if rename fails.

The durable `:state` snapshot is therefore not deleted before a complete replacement has been materialized.

Private script statuses are:

```text
100 OK
200 REVISION_CONFLICT
201 STORAGE_CORRUPT
202 INVALID_REVISION
```

They are internal protocol, not public package API.

The Redis driver depends on the additive `RedisStructuredCommandExecutor` port, whose structured EVAL result is a strict `list<string>`. The original integer `RedisCommandExecutor::evaluate(): int` contract remains unchanged.

Lua validates persistence shape and CAS semantics only. Lifecycle transition legality remains in the Lifecycle layer.

Redis control scripts touch only `:state` and its transient same-slot `:state:staging` key. They do not mutate generation `:meta` or `:bf` data-plane keys.

## Consequences

- stale writers cannot overwrite a winning control snapshot;
- failed replacement materialization cannot delete the previous durable correctness snapshot;
- large control snapshots avoid unbounded `unpack(nextFields)` by using bounded staged HSET chunks;
- corrupt storage is never interpreted as missing state;
- Redis transport failure remains distinct from storage corruption;
- create/update control state has no expiration;
- one logical filter's control and generation keys are designed for the same Cluster hash slot;
- Redis Cluster runtime support still requires dedicated Cluster integration evidence;
- control persistence does not gain lifecycle workflow knowledge;
- raw M3 generation storage remains independent from M4 control persistence.
