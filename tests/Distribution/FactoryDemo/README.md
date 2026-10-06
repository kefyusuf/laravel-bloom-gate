# Isolated factory demo

This blank Laravel application uses only deterministic synthetic records from
an Eloquent factory. It has no business-application source or data. SQLite is
always in memory; the fixture does not accept an external database DSN.

Run from the package root in PowerShell. Supply a PHP 8.4+ image with Composer,
PDO SQLite and PhpRedis already installed:

```powershell
$env:FACTORY_DEMO_PHP_IMAGE = '<your-compatible-php-image>'
$demoProject = 'lbg-factory-demo-local'
docker compose -p $demoProject -f tests/Distribution/FactoryDemo/compose.yaml up -d --wait
docker compose -p $demoProject -f tests/Distribution/FactoryDemo/compose.yaml exec -T php sh -c 'cp /package/tests/Distribution/FactoryDemo/composer.json /demo/composer.json && cp /package/tests/Distribution/factory-demo.php /demo/factory-demo.php && composer install --no-interaction --no-progress --prefer-dist'
docker compose -p $demoProject -f tests/Distribution/FactoryDemo/compose.yaml exec -T php php /demo/factory-demo.php
docker compose -p $demoProject -f tests/Distribution/FactoryDemo/compose.yaml down --volumes --remove-orphans
```

The isolated demo volume holds a mirrored local package and its own dependencies.
The package mount is read-only. Dependency versions resolve when installed; this
is not an immutable release-archive qualification.

The factory creates 100 distinct records. A 1,000-value workload contains 100
present and 900 absent values. Assertions compare all results with SQL and check
positive fallback, negative SQL skipping, commit/rollback leases, rebuild and
disabled bypass. Bloom false positives may add SQL reads; an exact fallback count
is not a universal guarantee. Timing, FPM concurrency and production performance
are outside this fixture's scope. Failures explicitly exit with status 1.

The Factory Demo workflow also verifies that a missing trusted-negative profile
fails the SQL-skipping assertion. Keep reusable fixtures here and accepted
findings in `docs/verification`; `.build` output is disposable.
