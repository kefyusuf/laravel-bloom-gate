# Contributing

Laravel Bloom Gate is developed with explicit architectural boundaries and evidence-based changes.

## Workflow

1. Branch from `main`.
2. Use a short-lived branch such as `feat/core-membership` or `fix/stale-filter-bypass`.
3. Keep the change inside one architectural responsibility.
4. Add or update executable verification.
5. Update documentation or an ADR when a public or architectural contract changes.
6. Open a pull request and complete the architecture/risk sections.
7. Merge after checking CI and review results, including any overlapping work.

`main` is protected: integrate through a pull request with the successful
GitHub Actions `quality` check, keeping the branch up to date. The protection
also applies to administrators; force pushes and main deletion are blocked.
The single-maintainer baseline requires no external approval quorum. Use squash
merge by preference and preserve branches until their history is no longer needed.

For behavior changes, follow TDD: establish a failing regression, implement the
smallest correction, and verify the result before committing and opening the PR.
Preserve existing red/green evidence when integrating work already completed;
do not represent a retroactive test-only commit as a newly executed failing run.

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
