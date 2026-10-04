# GitHub ZIP / Composer dist consumer — 2026-10-04

## Scope and gap

The source archive check in PR #70 installed the extracted package through a
Composer path repository. This increment also downloads GitHub's ZIP for the
exact candidate commit and installs that ZIP through Composer's dist installer.
The path-backed check remains separate.

## Verification

- The new installed-metadata assertion rejected the previous path consumer,
  because it had not installed a ZIP with the expected commit reference.
- A real GitHub ZIP for merged `8568cd0f90cfd1c88b9ee3f2c16548cbfafda2bb`
  passed the existing runtime/documentation and development-file exclusion checks.
- A fresh PHP 8.4.26/Laravel 13.34.0 consumer installed production dependencies
  from a package repository generated from the archived package's own metadata.
  Its dist URL was the local downloaded ZIP; no source fallback was supplied.
- Composer installed metadata recorded `installation-source=dist`,
  `dist.type=zip`, and the exact commit reference. Installed package contents,
  automatic provider discovery, coordination services and status command
  execution passed. Testbench/PHPUnit remained absent from the consumer.
- Pint, maximum-level PHPStan and 883 fast tests / 7,365 assertions passed.
  Docker PHP copies were normalized to Git-equivalent LF before quality checks.

## Continuous verification and boundaries

The Distribution workflow downloads the PR head commit from its head repository
or the dispatched commit from the current repository. It inspects the ZIP,
generates an isolated package-repository manifest, installs without development
dependencies and verifies installed metadata, package contents and Laravel boot.
The candidate PR's actual CI result must pass before merge.

`dev-main` is a fixture version; no package version field or release tag is added.
The ZIP is obtained from GitHub over HTTPS, then Composer reads the same local
file. This proves provider archive content and Composer ZIP installation, not
Packagist indexing, Composer's remote transport, a published version, or Redis
production operation. The consumer still covers PHP 8.4/Laravel 13 and Memory
bootstrap. No package runtime behavior or dependency constraint changed.
