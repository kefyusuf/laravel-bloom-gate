# Post-M6 compatibility verification — 2026-10-02

## Scope and decision

The M6 workspace now passes the PHP 8.3/Laravel 12 compatibility anchor and
the release workflow's Laravel 12 `prefer-lowest` dependency strategy.
The final changes also pass the existing PHP 8.4/Laravel 13 environment.
The package remains pre-release. This initial pass covered three environments;
the [subsequent full local matrix report](2026-10-02-release-matrix.md) completes
the remaining combinations.

The source checkout remains `main@d2f95878f43af1dca5f7e61acf6d1fdb1afd9954`
with uncommitted WU-10 through WU-13 changes and the compatibility fixes below.
Composer resolution occurred only inside isolated Docker copies. The checkout's
dependency constraints and CI workflows were not changed.

## Findings and minimal fixes

The initial `prefer-lowest` quality gate failed on two static-analysis findings:

- PHPStan 2.2.14 inferred a mixed return for `random_bytes()` in token generation.
  An explicit `non-empty-string` annotation records the built-in function's
  successful result without changing generation or swallowing entropy failures.
- Older Mockery definitions did not expose the polling test's `andReturnUsing()`
  callback consistently. A small `DrainObservingWriterSynchronizationStore`
  decorator now performs the same second-observation release against the real
  underlying store. This removes version-sensitive mock typing while retaining
  the existing wait/drain behavior assertions.

No production coordination, lifecycle or transaction behavior changed.

## Final results

Every row below ran after the final fixes. Redis 8.10.2 was reachable through the
existing isolated Docker network; PHP source copies used Git-equivalent LF endings.

| Environment | Quality gate | Full suite |
|---|---|---|
| PHP 8.3.35 / Laravel 12.69.3 / Testbench 10.12.0 | `composer check` passed | 1,050 tests / 8,232 assertions passed |
| PHP 8.3.35 / Laravel 12.69.0 / Testbench 10.2.0, `prefer-lowest` | `composer check` passed | 1,050 tests / 8,232 assertions passed |
| PHP 8.4.26 / Laravel 13.34.0, existing dependency set | `composer check` passed | 1,050 tests / 8,232 assertions passed |

Each quality gate includes Pint, maximum-level PHPStan and 878 fast tests /
7,332 assertions. Each full suite includes 172 live-Redis tests. Both Laravel 12
sets also passed `testbench package:discover` and `composer audit`; no security
vulnerability advisories were reported. Composer metadata validation passed.
`git diff --check` passed after the source/documentation updates.

`prefer-lowest` means the lowest resolvable set under current constraints and
Composer's active security policy, not every historical package minimum.
For this run it selected Pest 4.3.2, Mockery 1.6.10 and PHPStan 2.2.14.

## Reproduction

In a separate PHP 8.3 source copy, use the existing release workflow's target:

```sh
composer require --dev "laravel/framework:^12.0" "orchestra/testbench:^10.0" --no-update --no-interaction
composer update --with-all-dependencies --prefer-dist --no-interaction --no-progress
composer check
composer test:all
vendor/bin/testbench package:discover
composer audit
```

In another independent copy, add `--prefer-lowest` to the update command.
Keep Redis reachable before running the full suite. These source copies have
independent manifests, lockfiles and vendor trees; do not run compatibility
pinning against the working checkout.

Logs are saved locally under `.build/release-php83-l12-*`,
`.build/release-lowest-*`, and `.build/release-current-*`.

## Remaining release boundary

PHP 8.5 and the other combinations were unverified at the end of this initial
pass. They subsequently passed in the [full local matrix](2026-10-02-release-matrix.md).
Remote CI and publication remain separate boundaries. This run does not create a commit, tag,
published package or production deployment. The accepted M6 external-history
and Redis durability boundaries remain as documented in the
[M6 closure report](2026-10-02-m6-wu13.md).
