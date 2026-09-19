# ADR-0033: Atomic Redis driver scripts

**Status:** ACCEPTED

## Context

A Redis `BloomDriver` operation may need to validate generation metadata and then read or mutate multiple bitmap positions. Client-side command loops or pipelines do not provide one atomic semantic operation.

## Decision

M3 executes provision, add, membership check, and destroy as small application-owned Lua scripts using Redis `EVAL`.

Generation metadata and bitmap keys share one Redis Cluster hash tag so each script's keys can be colocated when Cluster support is added.

Scripts validate storage type, metadata structure, and layout before bitmap mutation. Private integer status codes are mapped by `RedisBloomDriver` to backend-neutral results and typed exceptions.

M3 uses direct `EVAL`; it does not require Redis Functions, script installation, or persistent script-cache management.

## Consequences

- each driver operation has atomic Redis execution semantics;
- add cannot partially mutate before layout/storage validation;
- install/package discovery remains side-effect-free;
- there is no Redis function deployment/bootstrap step;
- `EVALSHA`/script caching can be introduced later only as a measured optimization;
- Redis Cluster runtime support still requires dedicated integration evidence.
