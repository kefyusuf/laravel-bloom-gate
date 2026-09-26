# Value Normalization and Semantic Identity

A Bloom filter operates on bytes.

Every managed path that represents the same business membership must therefore use the same normalization semantics:

- build;
- verification;
- activation reconciliation;
- managed add/addMany;
- query probing.

The package does not silently lowercase, trim, cast, or otherwise reinterpret business values.

## Explicit normalizer contract

A `FilterDefinition` supplies a framework-neutral `ValueNormalizer`.

The normalizer exposes:

1. a stable semantic `NormalizationIdentity`;
2. normalization from `string|int` to exact `NormalizedValue` bytes.

The identity describes the normalization semantics, not the PHP implementation artifact.

It must not be derived from:

- class name;
- closure identity;
- object hash;
- source filename;
- container binding identity;
- arbitrary serialized Laravel config.

If an output-affecting normalization rule changes, its semantic identity must change.

## Normalization fingerprint

M5 derives a deterministic SHA-256 fingerprint from the explicit identity.

Canonical encoding:

```text
sha256:<64 lowercase hex>
```

The fingerprint is bound to the generation semantic contract.

One managed generation therefore has an immutable relationship:

```text
FilterName
+ FilterVersion
+ NormalizationFingerprint
```

## Runtime comparison

At query time the package derives the expected fingerprint from the currently resolved normalizer identity.

A trusted Bloom negative requires exact equality:

```text
persisted normalization fingerprint
==
runtime normalization fingerprint
```

A missing or mismatched fingerprint does not authorize a negative.

The query path fails open to the authoritative lookup.

## Old M3 generations

M3 generation metadata predates M5 semantic binding.

A provisioned M3 generation with no semantic fingerprints is not automatically corrupt.

It remains valid for the low-level Bloom storage contract, but M5 treats it as **query-skip-ineligible** because the runtime cannot prove that the stored bits were built with the same normalization semantics.

A managed M5 rebuild/new generation is required before that generation can participate in trusted-negative query skipping.

Partial semantic metadata is not a valid unbound state. The semantic fields are all-or-none.

## Related semantic fingerprints

Normalization is only one axis.

M5 also binds:

- authoritative-set fingerprint;
- consistency fingerprint.

All three must match before the generation can become query-skip-eligible.

Changing any output-affecting semantic/configuration input requires a new semantic identity and, for a safely managed active set, a new generation/rebuild workflow.
