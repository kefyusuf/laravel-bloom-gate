<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Kefyusuf\BloomGate\Application\CoordinatedWriterCompletionResult;
use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Contracts\AuthoritativeSet;
use Kefyusuf\BloomGate\Contracts\FilterDefinition;
use Kefyusuf\BloomGate\Contracts\Redis\RedisCommandExecutor;
use Kefyusuf\BloomGate\Contracts\Redis\RedisStructuredCommandExecutor;
use Kefyusuf\BloomGate\Contracts\ValueNormalizer;
use Kefyusuf\BloomGate\Core\AuthoritativeSetIdentity;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\Membership;
use Kefyusuf\BloomGate\Core\NormalizationIdentity;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Laravel\Console\CoordinateAdoptCommand;
use Kefyusuf\BloomGate\Laravel\Console\RebuildCommand;
use Kefyusuf\BloomGate\Laravel\Facades\BloomGate;
use Orchestra\Testbench\TestCase;

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/benchmark-profile.php';

/** @phpstan-assert true $condition */
function fpmCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

final class FpmFixtureNormalizer implements ValueNormalizer
{
    public function identity(): NormalizationIdentity
    {
        return NormalizationIdentity::fromString('fpm-email-exact@1');
    }

    public function normalize(string|int $value): NormalizedValue
    {
        return NormalizedValue::fromBytes((string) $value);
    }
}

final class FpmFixtureSet implements AuthoritativeSet
{
    public int $lookups = 0;

    public function __construct(private readonly PDO $database, private readonly string $engine) {}

    public function identity(): AuthoritativeSetIdentity
    {
        return AuthoritativeSetIdentity::fromString('fpm-'.$this->engine.'-users-email@1');
    }

    public function exists(NormalizedValue $value): bool
    {
        $this->lookups++;
        $query = $this->database->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        fpmCheck($query !== false, 'Could not prepare authoritative query.');
        $query->execute([$value->bytes()]);

        return $query->fetchColumn() !== false;
    }

    public function values(): iterable
    {
        $query = $this->database->query('SELECT email FROM users ORDER BY email');
        fpmCheck($query !== false, 'Could not enumerate authoritative values.');
        while (($value = $query->fetchColumn()) !== false) {
            fpmCheck(is_string($value), 'Invalid authoritative value.');
            yield $value;
        }
    }
}

final readonly class FpmFixtureDefinition implements FilterDefinition
{
    public function __construct(private FpmFixtureSet $set) {}

    public function normalizer(): ValueNormalizer
    {
        return new FpmFixtureNormalizer;
    }

    public function authoritativeSet(): AuthoritativeSet
    {
        return $this->set;
    }

    public function consistency(): ConsistencyContract
    {
        return ConsistencyContract::PreAddV1;
    }
}

/** @return array<string, mixed> */
function runFpmFixture(): array
{
    fpmCheck(PHP_SAPI === 'fpm-fcgi', 'This fixture requires real PHP-FPM HTTP requests.');
    fpmCheck(extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled(),
        'APCu must be enabled in the isolated FPM pool.');
    fpmCheck(! class_exists(TestCase::class)
        && ! class_exists(PHPUnit\Framework\TestCase::class), 'A production-only consumer is required.');
    $engine = $_GET['database'] ?? null;
    $run = $_GET['run'] ?? null;
    $action = $_GET['action'] ?? 'query';
    fpmCheck(is_string($engine) && in_array($engine, ['mysql', 'pgsql'], true), 'Invalid database engine.');
    fpmCheck(is_string($run) && preg_match('/\A[a-f0-9]{16}\z/', $run) === 1, 'Invalid fixture run identifier.');
    fpmCheck(is_string($action), 'Invalid fixture action.');
    $dsn = getenv($engine === 'mysql' ? 'FPM_MYSQL_DSN' : 'FPM_PGSQL_DSN');
    fpmCheck(is_string($dsn) && $dsn !== '', 'A dedicated fixture database DSN is required.');
    $database = new PDO($dsn, (string) (getenv('PILOT_DB_USER') ?: ''),
        (string) (getenv('PILOT_DB_PASSWORD') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    fpmCheck($database->getAttribute(PDO::ATTR_DRIVER_NAME) === $engine, 'PDO engine does not match the fixture.');
    $set = new FpmFixtureSet($database, $engine);
    foreach (['bootstrap/cache', 'config', 'storage/framework/cache', 'storage/framework/views', 'storage/logs'] as $directory) {
        if (! is_dir(__DIR__.'/'.$directory)) {
            mkdir(__DIR__.'/'.$directory, 0777, true);
        }
    }
    $app = Application::configure(basePath: __DIR__)->withExceptions()->create();
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $app['config']->set('database.redis', ['client' => 'phpredis', 'default' => [
        'host' => (string) (getenv('REDIS_HOST') ?: 'redis'),
        'port' => (int) (getenv('REDIS_PORT') ?: 6379), 'database' => 0,
    ]]);
    $app['config']->set('bloom-gate.default', 'redis');
    $app['config']->set('bloom-gate.keyspace.prefix', 'lbg_fpm_'.$run.'_'.$engine);
    $app['config']->set('bloom-gate.drivers.redis.trusted_negative_profile', getenv('PILOT_PROFILE') ?: null);
    $cache = ($_GET['cache'] ?? '1') === '1';
    if ($cache) {
        $app['config']->set('bloom-gate.query.descriptor_cache', [
            'driver' => 'apcu', 'namespace' => 'fpm-fixture-'.$run.'-'.$engine, 'ttl' => 60,
        ]);
    }
    $app['config']->set('bloom-gate.filters', ['fpm.email' => [
        'enabled' => true, 'definition' => FpmFixtureDefinition::class,
        'capacity' => 1000, 'false_positive_rate' => 0.01, 'coordination' => 'coordinated-v1',
    ]]);
    $app->instance(FpmFixtureDefinition::class, new FpmFixtureDefinition($set));
    $profile = new BenchmarkProfileExecutor($app->make(RedisStructuredCommandExecutor::class));
    $app->instance(RedisStructuredCommandExecutor::class, $profile);
    $app->instance(RedisCommandExecutor::class, $profile);
    // Package command discovery is console-only; register just the two fixture setup commands under FPM.
    $kernel->registerCommand($app->make(CoordinateAdoptCommand::class));
    $kernel->registerCommand($app->make(RebuildCommand::class));
    $context = ['schema_version' => 1, 'status' => 'passed', 'database' => $engine,
        'database_server_version' => $database->getAttribute(PDO::ATTR_SERVER_VERSION),
        'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'pid' => getmypid(), 'apcu_enabled' => apcu_enabled(),
        'package_reference' => InstalledVersions::getReference('kefyusuf/laravel-bloom-gate'),
        'descriptor_cache_requested' => $cache];

    if ($action === 'setup') {
        // This fixture is for task-owned disposable databases only.
        $database->exec('DROP TABLE IF EXISTS users');
        $database->exec('CREATE TABLE users (email '.($engine === 'mysql' ? 'VARBINARY(255)' : 'VARCHAR(255)').' PRIMARY KEY)');
        $insert = $database->prepare('INSERT INTO users (email) VALUES (?)');
        fpmCheck($insert !== false, 'Could not prepare fixture seed insertion.');
        $database->beginTransaction();
        for ($index = 0; $index < 1000; $index++) {
            $insert->execute(['present-'.$index.'@example.test']);
        }
        $database->commit();
        $exit = $kernel->call('bloom:coordinate:adopt', ['filter' => 'fpm.email']);
        $output = $kernel->output();
        fpmCheck($exit === 0, 'Coordination adoption failed: '.$output);
        $exit = $kernel->call('bloom:rebuild', ['filter' => 'fpm.email']);
        $output = $kernel->output();
        fpmCheck($exit === 0 && str_contains($output, 'Completed'), 'Fixture rebuild failed: '.$output);
        // Find one stable definite miss so cold/warm one-lookup counts cannot be confounded by a false positive.
        $miss = null;
        for ($index = 0; $index < 100; $index++) {
            $candidate = 'control-absent-'.$index.'@example.test';
            if (BloomGate::existsResult('fpm.email', $candidate)->membership() === Membership::DefinitelyAbsent) {
                $miss = $candidate;
                break;
            }
        }
        fpmCheck($miss !== null, 'Could not find a definite fixture miss.');
        apcu_clear_cache();

        return $context + ['seed_rows' => 1000, 'definite_miss' => $miss];
    }
    if ($action === 'clear') {
        fpmCheck(apcu_clear_cache(), 'Could not clear task FPM APCu cache.');

        return $context + ['cleared' => true];
    }
    if ($action === 'rebuild') {
        $exit = $kernel->call('bloom:rebuild', ['filter' => 'fpm.email']);
        $output = $kernel->output();
        fpmCheck($exit === 0 && str_contains($output, 'Completed'), 'Fixture rebuild failed: '.$output);

        return $context + ['rebuilt' => true];
    }
    if ($action === 'preadd') {
        $value = $_GET['value'] ?? null;
        fpmCheck(is_string($value) && $value !== '', 'Pre-add fixture value is required.');
        $prepared = BloomGate::prepare('fpm.email', WriterLeaseToken::generate()->value(), [$value]);
        fpmCheck($prepared->authoritativeAborted() === CoordinatedWriterCompletionResult::Released,
            'Fixture rollback writer did not release.');

        return $context + ['preadded_then_aborted' => true];
    }
    if (in_array($action, ['metadata-break', 'metadata-restore', 'health-break', 'health-restore'], true)) {
        $name = FilterName::fromString('fpm.email');
        $snapshot = $app->make(ActiveGenerationSnapshotReader::class)->readActive($name);
        fpmCheck($snapshot !== null, 'Mutation needs a live active generation.');
        $keyspace = $app->make(RedisKeyspace::class);
        $metadata = str_starts_with($action, 'metadata-');
        $key = $metadata ? $keyspace->metaKey($name, $snapshot->activeVersion()) : $keyspace->stateKey($name);
        $field = $metadata ? 'normalization_fingerprint' : 'g:'.$snapshot->activeVersion()->value().':health';
        $backup = $keyspace->stateKey($name).':fixture-backup';
        $redis = $app['redis']->connection();
        if (str_ends_with($action, '-break')) {
            $original = $redis->command('hget', [$key, $field]);
            fpmCheck(is_string($original) && $original !== '', 'Mutation field is missing.');
            $redis->command('hset', [$backup, $field, $original]);
            $redis->command('hset', [$key, $field, $metadata ? 'sha256:'.str_repeat('0', 64) : 'degraded']);
        } else {
            $original = $redis->command('hget', [$backup, $field]);
            fpmCheck(is_string($original) && $original !== '', 'Mutation restore field is missing.');
            $redis->command('hset', [$key, $field, $original]);
            $redis->command('hdel', [$backup, $field]);
        }

        return $context + ['mutation' => $action];
    }
    fpmCheck($action === 'query', 'Unsupported fixture action.');
    $mode = $_GET['mode'] ?? 'gate';
    fpmCheck(is_string($mode) && in_array($mode, ['direct', 'bypass', 'gate'], true), 'Invalid query path.');
    $requests = filter_var($_GET['requests'] ?? '1', FILTER_VALIDATE_INT);
    $present = filter_var($_GET['present'] ?? '0', FILTER_VALIDATE_INT);
    fpmCheck(is_int($requests) && $requests >= 1 && $requests <= 1000, 'Requests must be between 1 and 1000.');
    fpmCheck(is_int($present) && $present >= 0 && $present <= $requests, 'Invalid present request count.');
    $fixed = $_GET['value'] ?? null;
    fpmCheck($fixed === null || is_string($fixed), 'Invalid fixed query value.');
    $app['config']->set('bloom-gate.enabled', $mode !== 'bypass');
    $profile->reset();
    $answers = [];
    $memberships = [];
    $started = hrtime(true);
    for ($index = 0; $index < $requests; $index++) {
        $value = $fixed ?? (($index < $present ? 'present-' : 'absent-').$index.'@example.test');
        if ($mode === 'direct') {
            $answers[] = $set->exists(NormalizedValue::fromBytes($value));
        } else {
            $result = BloomGate::existsResult('fpm.email', $value);
            $answers[] = $result->exists();
            $memberships[] = $result->membership()->name;
        }
    }
    $elapsed = (hrtime(true) - $started) / 1_000_000;
    $measuredLookups = $set->lookups;
    $executor = $profile->snapshot();
    if ($fixed === null) {
        $expected = [];
        for ($index = 0; $index < $requests; $index++) {
            $expected[] = $index < $present;
        }
    } else {
        $expected = array_fill(0, $requests, $set->exists(NormalizedValue::fromBytes($fixed)));
    }
    fpmCheck($answers === $expected, 'FPM query answers differed from authoritative truth.');

    return $context + ['path' => $mode, 'requests' => $requests, 'present' => $present,
        'correctness_equal' => true, 'answers' => $answers, 'memberships' => $memberships,
        'query_elapsed_ms' => $elapsed, 'authoritative_queries' => $measuredLookups,
        'executor' => $executor, 'limits' => 'One HTTP request; timing excludes bootstrap/PDO connection but includes fixture result collection.'];
}

try {
    header('Content-Type: application/json');
    echo json_encode(runFpmFixture(), JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $failure) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'failed', 'message' => $failure->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL;
}
