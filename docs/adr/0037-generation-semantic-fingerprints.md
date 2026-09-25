# ADR-0037: Generation semantic fingerprints

**Status:** ACCEPTED

## Context

A Bloom negative is only meaningful if the runtime interprets values exactly as they were interpreted when the generation was built.

M4 could identify the active healthy generation but did not prove runtime normalization compatibility. M5 also needs to know that the same authoritative-set semantics and consistency contract are in force.

Deriving compatibility from implementation artifacts such as class names, closures, object hashes, source files, or arbitrary framework configuration would be unstable and unsafe.

Older M3 generations also exist without semantic metadata and must remain distinguishable from corrupt storage.

## Decision

M5 requires explicit stable semantic identities for:

- normalization;
- authoritative set;
- consistency contract.

The package derives deterministic SHA-256 fingerprints with domain-separated canonical encoding:

```text
sha256:<64 lowercase hex>
```

A generation binds exactly three semantic fingerprints:

```text
normalization_fingerprint
authoritative_set_fingerprint
consistency_fingerprint
```

The binding belongs to the exact `FilterName + FilterVersion` generation metadata.

It is write-once:

- first valid bind succeeds;
- identical rebind is idempotent;
- different rebind produces a typed conflict.

Every output-affecting semantic/configuration change must change the relevant explicit identity.

Identity must not be derived from PHP class names, closures, object hashes, source paths, or arbitrary Laravel config serialization.

At query time the runtime derives the expected semantic contract again and requires exact persisted/runtime equality before a negative can authorize skipping.

A generation with no semantic fields is a valid **unbound** legacy state when its low-level M3 storage is otherwise valid. It is query-skip-ineligible and fails open.

A partial semantic binding is corruption.

## Consequences

- semantic compatibility is explicit and deterministic;
- a runtime code/config change cannot silently reuse old Bloom bits unless its declared semantics are intentionally unchanged;
- old M3 generations remain readable as low-level storage without being trusted by M5;
- missing/mismatched semantic metadata causes authoritative fallback;
- semantic metadata stays generation-scoped rather than being placed in strict M4 `control-v1`;
- rebuild/new generation is required when semantics change.
