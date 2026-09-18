# Security Policy

## Supported versions

The project is pre-release. No stable security-support window is promised until a first public release exists.

## Reporting

Do not open a public issue for a vulnerability that could cause unsafe lookup bypass, cross-filter key collisions, tenant isolation failures, unsafe lifecycle promotion, or other exploitable correctness failures.

Use GitHub's private vulnerability reporting mechanism when available.

## Correctness is security-relevant

For this package, a defect that can turn an existing authoritative value into a trusted "definitely absent" result is treated as a high-severity correctness issue. Infrastructure failures must fail open to the authoritative datastore.
