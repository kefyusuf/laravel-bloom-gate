# Value Normalization

A Bloom filter operates on bytes. Therefore build, add, bulk add, check, bulk check, synchronization, and shadow verification must use the same normalization contract.

The package does not silently lowercase, trim, or otherwise change business values.

Changing normalization changes filter identity and makes the existing generation stale. Rebuild is required.
