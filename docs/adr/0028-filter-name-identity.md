# ADR-0028: Filter name identity

**Status:** ACCEPTED

## Decision

Filter names are exact, case-sensitive Core identities. They use 1 to 128 ASCII bytes, start and end with an alphanumeric character, and allow only ASCII letters, digits, dot, underscore, and hyphen internally. Core performs no implicit normalization.

## Consequences

Filter names remain safe for the planned Redis Cluster hash-tag keyspace and configuration typos are not silently rewritten. Case differences represent different identities.
