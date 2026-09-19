# M3 Redis Foundation Implementation Plan

**Status:** implemented, internally reviewed, externally reviewed, and merged  
**Historical branch:** `feat/m3-redis-foundation`  
**Merged via:** PR #6  
**Main commit:** `80ae223502dacad3e3d4689166d5ab9fa8f97376`

## Goal

Add a production Redis data-plane implementation that conforms to the M2 `BloomDriver` contract without moving normalization, hashing, lifecycle, or fail-open policy into the Redis layer.

## Scope

Implemented:

1. deterministic Cluster-aware Redis generation keyspace;
2. typed Redis command and Bloom storage failure boundaries;
3. private Lua status protocol;
4. atomic provision/add/mightContain/destroy scripts;
5. framework-neutral `RedisBloomDriver`;
6. dedicated live Redis test group and RESP test executor;
7. shared `BloomDriver` contract execution against real Redis;
8. live corruption fixtures;
9. Laravel-only Redis command adapter;
10. real Testbench + PhpRedis + Redis EVAL evidence.

Deferred:

- active/candidate/state control plane;
- lifecycle and health orchestration;
- fail-open application policy;
- rebuild/retention;
- Bloom sizing;
- Eloquent integration;
- Redis Cluster runtime support;
- real Predis runtime support.

## Locked protocol

Storage format:

```text
redis-bitmap-v1
```

Generation keys:

```text
<prefix>:{<filter-name>}:v:<version>:meta
<prefix>:{<filter-name>}:v:<version>:bf
```

Private script status codes:

```text
100 OK
101 MEMBERSHIP_ABSENT
102 MEMBERSHIP_MAYBE_PRESENT
200 NOT_PROVISIONED
201 LAYOUT_CONFLICT
202 LAYOUT_MISMATCH
203 STORAGE_CORRUPT
```

Metadata fields:

```text
format
bit_count
hash_count
probe_algorithm
```

## Execution order used

1. branch from verified M2 main;
2. Redis keyspace RED → GREEN;
3. framework-neutral Redis port + typed failures RED → GREEN;
4. Lua protocol fixtures RED → GREEN;
5. `RedisBloomDriver` unit mapping RED → GREEN;
6. live Redis executor + shared driver contract RED → GREEN;
7. live corruption evidence;
8. Laravel Redis adapter RED → GREEN;
9. documentation + full internal final review;
10. external review;
11. final merge gate.

## Verification model

Fast default gate:

```text
composer check
```

Redis evidence:

```text
composer test:redis
```

The Redis workflow runs the Redis group first and the normal regression gate afterward.

Required final evidence before external review:

- Pint;
- PHPStan max;
- architecture tests;
- normal unit/contract/integration/feature suite;
- Redis contract suite;
- Redis corruption suite;
- Testbench/PhpRedis adapter integration;
- Laravel 12 / PHP 8.3 anchor;
- Laravel 13 / PHP 8.5 anchor.

## Safety invariants

- Redis never receives raw application values.
- Redis never owns normalization or probe generation.
- missing/corrupt storage never maps to `false`.
- operational Redis failures never become programming/configuration errors or vice versa.
- no implicit TTL or silent recovery.
- no Redis contact during installation/package discovery.
- no control-plane or lifecycle behavior in the M3 driver.
- no production support claim without executable evidence.
