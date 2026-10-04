# Source distribution consumer verification — 2026-10-04

## Scope

The previous consumer check mirrored the repository directly. This check first
extracts an actual Git source archive, checks its contents, then installs the
extracted package into an isolated Laravel consumer without development dependencies.
The runtime package source and public API are unchanged.

## Red and green evidence

- An archive of the previous `main` (`0550bc5`) failed the new content assertion:
  `Distribution contains development material: tests`.
- Root `.gitattributes` export exclusions removed tests, CI and development-tool
  configuration. Runtime source, package metadata, license, README, changelog,
  security policy and operator documentation remain available.
- Local pre-commit verification used `git archive --worktree-attributes HEAD`
  to apply the new exclusions to the unchanged runtime source. The content check
  passed. CI uses `git archive HEAD` against the checked-out commit.
- The extracted package was mirrored into a separate production-only consumer
  with `composer install --no-dev --prefer-dist --no-interaction --no-progress`.
  PHP 8.4.26 / Laravel 13.34.0 passed automatic provider discovery, resolution of
  the three coordination services, registration of six coordination/status
  commands and execution of the empty-filter status command. Neither Testbench
  nor PHPUnit was available through the consumer autoloader.
- The package quality gate passed Pint, maximum-level PHPStan and 883 fast tests
  with 7,365 assertions after adding the verification fixtures.

## Continuous verification

`.github/workflows/distribution.yml` runs the archive content and consumer checks
for relevant PR changes and on manual dispatch. `tests/Distribution/verify.php`
checks extracted content; `composer.json` and `smoke.php` in that directory are
the reusable isolated consumer fixture. The tests themselves are excluded from
the shipped archive. CI results must be checked on the actual PR before merge.

This is a local source-archive-backed path installation, not a Packagist install,
registry publication, release tag, or proof of a provider-generated ZIP archive.
The consumer covers PHP 8.4 / Laravel 13 and Memory-backed bootstrap. Redis
deployment behavior and the broader compatibility matrix retain their separate
verification requirements. No version or publication was created.
