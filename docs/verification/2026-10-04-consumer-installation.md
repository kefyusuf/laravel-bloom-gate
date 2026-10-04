# Production-only consumer installation — 2026-10-04

## Purpose and source

Verify that merged M6 boots outside Testbench and without the package's development
dependencies. Source: `main@e89c44cf5d97a178256210a708e046e585e0d09d`.
This fills a consumer-bootstrap evidence gap; no runtime behavior changed.

## Environment and result

The isolated PHP 8.4 Docker environment installed Laravel 13.34.0 and
`kefyusuf/laravel-bloom-gate:dev-main` using a Composer path repository pointing at
the read-only checkout. The package was mirrored, not symlinked.
`composer install --no-dev --no-interaction --prefer-dist --no-progress` passed.

The consumer used a minimal Laravel Foundation application with standard exception
handling and package auto-discovery. No provider was registered manually.
The checks passed:

- `PackageManifest` discovers `BloomGateServiceProvider`.
- Testbench and PHPUnit classes are absent from the consumer autoloader.
- Memory-backed `CoordinatedWriter`, `CoordinationStatusReader` and
  `OnlineRebuildCoordinator` resolve through the container.
- Status, doctor, adopt, rebuild, abort and lease-resolution commands are registered.
- `bloom:status` executes successfully with the expected empty-filter output.

The package's own `composer validate --strict --no-check-lock` passed. The private
consumer manifest produced warnings for its intentionally exact Laravel version
and missing license; this was not a package metadata failure.

## Initial fixture correction

The first bootstrap attempt omitted Laravel's exception-handler binding. Resolving
the complete Artisan command list then failed while constructing the framework's
queue worker. Adding `withExceptions()` to the minimal application factory fixed
the fixture. No package implementation or test assertions were modified.

## Reproduction

Local inputs are retained at `.build/consumer-smoke/composer.json` and `smoke.php`;
install and execution logs are `.build/consumer-install.log` and
`.build/consumer-smoke.log`. Docker working directory: `/consumer-smoke`.
These artifacts are ignored and are not shipped.

To recreate the check, create a separate Composer project requiring
`laravel/framework:13.34.0` and `kefyusuf/laravel-bloom-gate:dev-main`, with a path
repository for this checkout and `symlink:false`. Install without dev dependencies.
Create writable `bootstrap/cache`, `config` and standard storage directories, then
bootstrap through:

```php
$app = Illuminate\Foundation\Application::configure(basePath: __DIR__)
    ->withExceptions()
    ->create();
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$app['config']->set('bloom-gate.default', 'memory');
```

Assert the discovery/autoload/service/command results listed above. Preserve the
source SHA and resolved lockfile when comparing subsequent results.

## Limits

This is a path-repository consumer of merged source, not a published archive or
Packagist install. It covers PHP 8.4/Laravel 13 and Memory-backed bootstrap only.
It does not repeat the full compatibility matrix, establish Predis support,
exercise Redis durability, or prove authoritative transaction recovery.
Those protocol tests and boundaries remain in the M6 verification reports.
