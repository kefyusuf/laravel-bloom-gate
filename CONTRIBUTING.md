# Contributing

Laravel Bloom Gate is developed with explicit architectural boundaries and evidence-based changes.

## Workflow

1. Branch from `main`.
2. Use a short-lived branch such as `feat/core-membership` or `fix/stale-filter-bypass`.
3. Keep the change inside one architectural responsibility.
4. Add or update executable verification.
5. Update documentation or an ADR when a public or architectural contract changes.
6. Open a pull request and complete the architecture/risk sections.

## Local quality gate

The canonical local gate is:

```bash
composer check
```

Redis integration tests are intentionally separated from the fast gate.

## Commit format

Use Conventional Commits:

```text
<type>(<scope>): <description>
```

Examples:

```text
feat(core): add membership result model
test(arch): enforce framework boundary
fix(lifecycle): bypass stale active filters
```

## Architecture rule

`Core`, `Contracts`, `Lifecycle`, `Application`, and `Drivers` must not depend on Illuminate. Laravel-specific dependencies belong under `Kefyusuf\BloomGate\Laravel`.

Accepted ADRs are not silently reversed. Add a new ADR that supersedes the previous decision.
