# Full local release matrix verification — 2026-10-02

## Decision and source state

The subsequent [final review report](2026-10-02-m6-final-review.md) records two
diagnostic fixes and the repeated quality/full-test matrix with 1,055 tests.
The counts below describe the earlier matrix snapshot.

All six PHP/Laravel combinations declared in `.github/workflows/release-gate.yml`
now pass locally, along with the separate PHP 8.3/Laravel 12 `prefer-lowest` set.
The package remains pre-release. This verifies the current uncommitted workspace;
it does not create a release or establish that GitHub Actions ran on a commit.

Source baseline: `main@d2f95878f43af1dca5f7e61acf6d1fdb1afd9954`, plus the
WU-10 through WU-13 changes and the previously verified minimum-dependency fixes.
No production source or dependency constraints changed during this matrix run.
Each target used a separate Docker source copy and independent manifest,
lockfile and vendor tree. The host checkout was mounted read-only.

## Results

All rows ran `composer check`, `composer test:all`, `composer audit`, and
`vendor/bin/testbench package:discover`. Earlier completed rows use the final
post-fix results in the [compatibility report](2026-10-02-release-compatibility.md);
the four missing combinations were completed in this run.

| PHP | Laravel | Testbench | Quality / full tests / audit / discovery |
|---|---|---|---|
| 8.3.35 | 12.69.3 | 10.12.0 | Passed |
| 8.3.35 | 13.34.0 | 11.3.0 | Passed |
| 8.4.26 | 12.69.3 | 10.12.0 | Passed |
| 8.4.26 | 13.34.0 | 11.3.0 | Passed |
| 8.5.11 | 12.69.3 | 10.12.0 | Passed |
| 8.5.11 | 13.34.0 | 11.3.0 | Passed |
| 8.3.35, `prefer-lowest` | 12.69.0 | 10.2.0 | Passed |

Every environment passed:

- Pint and maximum-level PHPStan;
- 878 fast tests / 7,332 assertions;
- 1,050 full tests / 8,232 assertions, including 172 live-Redis tests;
- dependency audit with no security vulnerability advisories reported;
- Laravel package discovery.

Redis was the isolated Redis 8.10.2 service already used for M6 verification.
PHP 8.5 used PhpRedis 6.3.0. Linux copies had Git-equivalent LF source endings.
The final documentation/source diff check passed.

## PHP 8.5 environment correction

The first local image build tried to recompile DOM and failed on missing Lexbor
headers. Inspecting `php -m` showed DOM/XML and SQLite already available in the
official PHP 8.5 CLI image. The corrected image reused those modules and added
only the missing BCMath, ZIP and Redis extensions. This was a test environment
correction, with no package source change. DOM is enabled by default according to
the [PHP installation manual](https://www.php.net/manual/en/dom.installation.php).

The local test recipe and build log remain under `.build/release-php85/` and
`.build/release-php85-build.log`. Composer 2.10.3 resolved dependencies without
platform-requirement bypasses or disabling security checks.

## Reproduction and records

For each PHP version, copy the source into an isolated working directory and pin
the Laravel/Testbench target exactly as the existing release workflow does:

```sh
composer require --dev "laravel/framework:^12.0" "orchestra/testbench:^10.0" --no-update --no-interaction
composer update --with-all-dependencies --prefer-dist --no-interaction --no-progress
composer check
composer test:all
composer audit
vendor/bin/testbench package:discover
```

For Laravel 13, use `^13.0` and Testbench `^11.0`. For the minimum-dependency set,
add `--prefer-lowest` to the update command. Keep Redis reachable for the full
suite. The resolved minimum is subject to active Composer security policy.

New matrix logs are saved locally as:

- `.build/release-matrix-php83-l13-*`;
- `.build/release-matrix-php84-l12-*`;
- `.build/release-matrix-php85-l12-*`;
- `.build/release-matrix-php85-l13-*`.

The other rows retain `.build/release-php83-l12-*`,
`.build/release-current-*`, and `.build/release-lowest-*` records.

The [M6 closure report](2026-10-02-m6-wu13.md) remains the invariant and
race/crash evidence index. Its external-history and Redis durability boundaries
are unchanged. Publication, tagging and remote CI execution are outside this run.
