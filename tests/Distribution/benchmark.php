<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Redis\Connections\Connection;
use Kefyusuf\BloomGate\Application\CoordinatedWriterCompletionResult;
use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Contracts\AuthoritativeSet;
use Kefyusuf\BloomGate\Contracts\FilterDefinition;
use Kefyusuf\BloomGate\Contracts\Redis\RedisCommandExecutor;
use Kefyusuf\BloomGate\Contracts\Redis\RedisStructuredCommandExecutor;
use Kefyusuf\BloomGate\Contracts\ValueNormalizer;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\AuthoritativeSetIdentity;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\Membership;
use Kefyusuf\BloomGate\Core\NormalizationIdentity;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Laravel\Facades\BloomGate;

require __DIR__.'/vendor/autoload.php';

final class BenchmarkNormalizer implements ValueNormalizer
{
    public function identity(): NormalizationIdentity
    {
        return NormalizationIdentity::fromString('benchmark-email-exact@1');
    }

    public function normalize(string|int $value): NormalizedValue
    {
        return NormalizedValue::fromBytes((string) $value);
    }
}

final class BenchmarkSet implements AuthoritativeSet
{
    public int $lookups = 0;

    public function __construct(private PDO $database) {}

    public function identity(): AuthoritativeSetIdentity
    {
        $driver = $this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
        benchmarkCheck(is_string($driver), 'Invalid PDO driver name.');

        return AuthoritativeSetIdentity::fromString('benchmark-'.$driver.'-users-email@1');
    }

    public function exists(NormalizedValue $value): bool
    {
        $this->lookups++;
        $query = $this->database->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        benchmarkCheck($query !== false, 'Could not prepare authoritative query.');
        $query->execute([$value->bytes()]);

        return $query->fetchColumn() !== false;
    }

    public function values(): iterable
    {
        $query = $this->database->query('SELECT email FROM users ORDER BY email');
        benchmarkCheck($query !== false, 'Could not enumerate authoritative values.');
        while (($value = $query->fetchColumn()) !== false) {
            benchmarkCheck(is_string($value), 'Invalid authoritative value.');
            yield $value;
        }
    }
}

final readonly class BenchmarkDefinition implements FilterDefinition
{
    public function __construct(private BenchmarkNormalizer $normalization, private BenchmarkSet $set) {}

    public function normalizer(): ValueNormalizer
    {
        return $this->normalization;
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

/**
 * @phpstan-assert true $condition
 */
function benchmarkCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param  list<float>  $values
 */
function benchmarkMedian(array $values): float
{
    sort($values, SORT_NUMERIC);

    return $values[intdiv(count($values), 2)];
}

/**
 * @return array{calls: int, cpu_usec: int}
 */
function benchmarkRedisEvalStats(Connection $redis): array
{
    $info = $redis->command('info', ['commandstats']);
    $stats = is_array($info) ? ($info['cmdstat_eval'] ?? null) : null;
    if (! is_string($stats) || preg_match('/calls=(\d+),usec=(\d+),/', $stats, $matches) !== 1) {
        throw new RuntimeException('Redis EVAL command statistics unavailable.');
    }

    return ['calls' => (int) $matches[1], 'cpu_usec' => (int) $matches[2]];
}

/**
 * @param  list<string>  $values
 * @param  list<bool>  $expected
 * @return array<string, mixed>
 */
function benchmarkWorkload(BenchmarkSet $set, Connection $redis, array $values, array $expected,
    ?BenchmarkProfileExecutor $profile = null, ?Application $app = null,
    ?BenchmarkDescriptorHint $hint = null): array
{
    $timings = ['authoritative' => [], 'bloom_gate' => []];
    if ($profile !== null) {
        $timings['authoritative_bypass'] = [];
    }
    if ($hint !== null) {
        $timings['descriptor_hint_cold'] = [];
        $timings['descriptor_hint_warm'] = [];
    }
    $counts = ['authoritative' => [], 'bloom_gate' => []];
    $falsePositives = [];
    $trustedNegatives = [];
    $redisCalls = ['authoritative' => [], 'bloom_gate' => []];
    $redisCpuUsec = ['authoritative' => [], 'bloom_gate' => []];
    $profiles = [];
    // Warm both paths once; alternate measured order to reduce ordering bias.
    for ($repetition = -1; $repetition < 5; $repetition++) {
        $paths = $repetition % 2 === 0 ? ['authoritative', 'bloom_gate'] : ['bloom_gate', 'authoritative'];
        if ($profile !== null) {
            $paths = $repetition % 2 === 0 ? ['authoritative', 'authoritative_bypass', 'bloom_gate'] :
                ['bloom_gate', 'authoritative_bypass', 'authoritative'];
        }
        if ($hint !== null) {
            $paths = ['authoritative', 'authoritative_bypass', 'bloom_gate', 'descriptor_hint_cold', 'descriptor_hint_warm'];
            if ($repetition % 2 !== 0) {
                $paths = array_reverse($paths);
            }
        }
        foreach ($paths as $path) {
            $hint?->clear();
            if ($app !== null) {
                $app['config']->set('bloom-gate.enabled', $path !== 'authoritative_bypass');
            }
            $profile?->reset();
            $before = $set->lookups;
            $answers = [];
            $results = [];
            $falsePositiveCount = 0;
            $trustedNegativeCount = 0;
            $bypassedCount = 0;
            $redisBefore = benchmarkRedisEvalStats($redis);
            $started = hrtime(true);
            foreach ($values as $index => $value) {
                if ($path === 'authoritative') {
                    $answers[] = $set->exists(NormalizedValue::fromBytes($value));
                } else {
                    if ($hint !== null && ($path === 'descriptor_hint_cold' || $path === 'descriptor_hint_warm')) {
                        if ($path === 'descriptor_hint_cold') {
                            $hint->clear();
                        }
                        $result = $hint->existsResult('benchmark.email', $value);
                    } else {
                        $result = BloomGate::existsResult('benchmark.email', $value);
                    }
                    $answers[] = $result->exists();
                    $results[] = $result;
                }
            }
            $elapsed = (hrtime(true) - $started) / 1_000_000;
            foreach ($results as $index => $result) {
                if ($result->membership() === Membership::Bypassed) {
                    $bypassedCount++;
                }
                if (! $expected[$index] && $result->membership() === Membership::MaybePresent) {
                    $falsePositiveCount++;
                }
                if ($result->membership() === Membership::DefinitelyAbsent) {
                    $trustedNegativeCount++;
                }
            }
            if ($app !== null) {
                $app['config']->set('bloom-gate.enabled', true);
            }
            $sampleProfile = $profile?->snapshot();
            $redisAfter = benchmarkRedisEvalStats($redis);
            benchmarkCheck($answers === $expected, 'Bloom/authoritative answers differed from deterministic ground truth.');
            benchmarkCheck($bypassedCount === ($path === 'authoritative_bypass' ? count($values) : 0),
                'Query bypass decisions did not match the measured path.');
            $gated = in_array($path, ['bloom_gate', 'descriptor_hint_cold', 'descriptor_hint_warm'], true);
            if (! $gated) {
                benchmarkCheck($set->lookups - $before === count($values),
                    'An authoritative path did not execute one SQL lookup per request.');
            }
            if ($sampleProfile !== null) {
                $warm = $path === 'descriptor_hint_warm';
                $expectedCalls = $gated ? ($warm ? count($values) + 2 : count($values) * 3) : 0;
                benchmarkCheck($sampleProfile['calls'] === $expectedCalls,
                    'Profiled executor calls did not match the measured path.');
                if ($gated) {
                    foreach (['snapshot', 'contract', 'probe'] as $group) {
                        $expectedGroupCalls = $warm && $group !== 'probe' ? 1 : count($values);
                        benchmarkCheck(($sampleProfile['groups'][$group]['calls'] ?? 0) === $expectedGroupCalls,
                            'Profiled query script group did not match descriptor hint scope: '.$group);
                    }
                    benchmarkCheck($sampleProfile['source_bytes'] > 0,
                        'Profiled gate did not record transmitted Lua source bytes.');
                }
            }
            if ($repetition >= 0) {
                $timings[$path][] = $elapsed;
                $counts[$path][] = $set->lookups - $before;
                $redisCalls[$path][] = $redisAfter['calls'] - $redisBefore['calls'];
                $redisCpuUsec[$path][] = $redisAfter['cpu_usec'] - $redisBefore['cpu_usec'];
                if ($sampleProfile !== null) {
                    $profiles[$path][] = $sampleProfile;
                }
                if ($gated) {
                    benchmarkCheck($set->lookups - $before === count($values) - $trustedNegativeCount,
                        'Authoritative query count did not match Bloom fallback decisions.');
                }
                if ($path === 'bloom_gate') {
                    $falsePositives[] = $falsePositiveCount;
                    $trustedNegatives[] = $trustedNegativeCount;
                }
            }
        }
    }

    return [
        'requests' => count($values), 'present' => count(array_filter($expected)),
        'correctness_equal' => true, 'elapsed_ms' => $timings,
        'median_elapsed_ms' => array_map(benchmarkMedian(...), $timings),
        'authoritative_queries' => $counts, 'false_positives' => $falsePositives,
        'trusted_negatives' => $trustedNegatives,
        'redis_eval_calls' => $redisCalls, 'redis_eval_cpu_usec' => $redisCpuUsec,
        'executor_profile' => $profiles,
    ];
}

function benchmarkHintMutation(Application $app, BenchmarkSet $set, BenchmarkDescriptorHint $hint,
    BenchmarkProfileExecutor $profile): bool
{
    $name = FilterName::fromString('benchmark.email');
    $snapshot = $app->make(ActiveGenerationSnapshotReader::class)->readActive($name);
    benchmarkCheck($snapshot !== null, 'Mutation fixture needs an active generation.');
    $meta = $app->make(RedisKeyspace::class)->metaKey($name, $snapshot->activeVersion());
    $redis = $app['redis']->connection();
    $original = $redis->command('hget', [$meta, 'normalization_fingerprint']);
    benchmarkCheck(is_string($original), 'Mutation fixture needs a persisted normalization fingerprint.');
    $alternate = 'sha256:'.str_repeat('0', 64);
    benchmarkCheck($original !== $alternate, 'Mutation fixture fingerprint must differ.');
    $value = 'hint-absent@example.test';
    $hint->clear();
    $warm = $hint->existsResult('benchmark.email', $value);
    benchmarkCheck(! $warm->exists() && $warm->membership() !== Membership::Bypassed,
        'Mutation fixture did not warm a usable descriptor hint.');
    $before = $set->lookups;
    $profile->reset();
    try {
        $redis->command('hset', [$meta, 'normalization_fingerprint', $alternate]);
        $result = $hint->existsResult('benchmark.email', $value);
        benchmarkCheck(! $result->exists() && $result->membership() === Membership::Bypassed
            && $set->lookups === $before + 1, 'Atomic probe did not reject changed metadata and use SQL.');
        benchmarkCheck($profile->snapshot()['calls'] === 1, 'Warmed hint did not execute the atomic production probe.');
    } finally {
        $redis->command('hset', [$meta, 'normalization_fingerprint', $original]);
    }
    $profile->reset();
    $restored = $hint->existsResult('benchmark.email', $value);
    benchmarkCheck(! $restored->exists() && $restored->membership() !== Membership::Bypassed
        && $profile->snapshot()['calls'] === 3, 'Bypass did not clear both descriptor hint caches.');

    $absentValue = null;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $candidate = 'hint-live-bitmap-'.$attempt.'@example.test';
        if ($hint->existsResult('benchmark.email', $candidate)->membership() === Membership::DefinitelyAbsent) {
            $absentValue = $candidate;
            break;
        }
    }
    benchmarkCheck($absentValue !== null, 'Could not warm a definitely absent bitmap mutation fixture.');
    $prepared = BloomGate::prepare('benchmark.email', WriterLeaseToken::generate()->value(), [$absentValue]);
    benchmarkCheck($prepared->authoritativeAborted() === CoordinatedWriterCompletionResult::Released,
        'Bitmap mutation fixture did not release its rolled-back writer.');
    $before = $set->lookups;
    $profile->reset();
    $preAdded = $hint->existsResult('benchmark.email', $absentValue);
    benchmarkCheck(! $preAdded->exists() && $preAdded->membership() === Membership::MaybePresent
        && $set->lookups === $before + 1 && $profile->snapshot()['calls'] === 1,
        'Warmed hint cached a negative answer or failed to probe the live pre-added bitmap.');

    $state = $app->make(RedisKeyspace::class)->stateKey($name);
    $healthField = 'g:'.$snapshot->activeVersion()->value().':health';
    $originalHealth = $redis->command('hget', [$state, $healthField]);
    benchmarkCheck(is_string($originalHealth) && $originalHealth === 'healthy',
        'Control mutation fixture needs a healthy active generation.');
    $before = $set->lookups;
    $profile->reset();
    try {
        $redis->command('hset', [$state, $healthField, 'degraded']);
        $degraded = $hint->existsResult('benchmark.email', $value);
        benchmarkCheck(! $degraded->exists() && $degraded->membership() === Membership::Bypassed
            && $set->lookups === $before + 1 && $profile->snapshot()['calls'] === 1,
            'Warmed hint did not recheck live control health and use SQL fallback.');
    } finally {
        $redis->command('hset', [$state, $healthField, $originalHealth]);
    }

    return true;
}

/**
 * @return array<string, mixed>
 */
function runBenchmark(): array
{
    foreach (['bootstrap/cache', 'config', 'storage/framework/cache', 'storage/framework/views', 'storage/logs'] as $directory) {
        if (! is_dir(__DIR__.'/'.$directory)) {
            mkdir(__DIR__.'/'.$directory, 0777, true);
        }
    }
    $run = WriterLeaseToken::generate()->value();
    // An external DSN must point to a disposable test database: this fixture resets its users table.
    $database = new PDO((string) (getenv('PILOT_DB_DSN') ?: 'sqlite::memory:'),
        (string) (getenv('PILOT_DB_USER') ?: ''), (string) (getenv('PILOT_DB_PASSWORD') ?: ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $driver = $database->getAttribute(PDO::ATTR_DRIVER_NAME);
    benchmarkCheck(is_string($driver), 'Invalid PDO driver name.');
    // MySQL's default text collation folds case; the exact-byte normalizer requires binary equality.
    $emailType = $driver === 'mysql' ? 'VARBINARY(255)' : 'VARCHAR(255)';
    $database->exec('DROP TABLE IF EXISTS users');
    $database->exec('CREATE TABLE users (email '.$emailType.' PRIMARY KEY)');
    $insert = $database->prepare('INSERT INTO users (email) VALUES (?)');
    benchmarkCheck($insert !== false, 'Could not prepare seed insertion.');
    for ($index = 0; $index < 1000; $index++) {
        $insert->execute(['present-'.$index.'@example.test']);
    }
    $set = new BenchmarkSet($database);
    $app = Application::configure(basePath: __DIR__)->withExceptions()->create();
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    if (getenv('BENCHMARK_FORCE_FAILURE') === '1') {
        throw new RuntimeException('Injected benchmark failure after Laravel bootstrap.');
    }
    $app['config']->set('database.redis', ['client' => 'phpredis', 'default' => [
        'host' => (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
        'port' => (int) (getenv('REDIS_PORT') ?: 6379), 'database' => 0,
    ]]);
    $app['config']->set('bloom-gate.default', 'redis');
    $app['config']->set('bloom-gate.keyspace.prefix', 'lbg_benchmark_'.$run);
    $app['config']->set('bloom-gate.drivers.redis.trusted_negative_profile', getenv('PILOT_PROFILE') ?: null);
    $app['config']->set('bloom-gate.filters', ['benchmark.email' => [
        'enabled' => true, 'definition' => BenchmarkDefinition::class,
        'capacity' => 1000, 'false_positive_rate' => 0.01, 'coordination' => 'coordinated-v1',
    ]]);
    $app->instance(BenchmarkDefinition::class, new BenchmarkDefinition(new BenchmarkNormalizer, $set));
    $hintRequested = getenv('BENCHMARK_DESCRIPTOR_HINT') === '1';
    benchmarkCheck(! $hintRequested || getenv('BENCHMARK_PROFILE') === '1',
        'BENCHMARK_DESCRIPTOR_HINT=1 requires BENCHMARK_PROFILE=1.');
    $profile = null;
    if (getenv('BENCHMARK_PROFILE') === '1') {
        require_once __DIR__.'/benchmark-profile.php';
        $profile = new BenchmarkProfileExecutor($app->make(RedisStructuredCommandExecutor::class));
        $app->instance(RedisStructuredCommandExecutor::class, $profile);
        $app->instance(RedisCommandExecutor::class, $profile);
    }
    $exit = $kernel->call('bloom:coordinate:adopt', ['filter' => 'benchmark.email']);
    $output = $kernel->output();
    benchmarkCheck($exit === 0, 'Coordination adoption failed: '.$output);
    $completed = false;
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $exit = $kernel->call('bloom:rebuild', ['filter' => 'benchmark.email', '--wait' => '1']);
        $output = $kernel->output();
        benchmarkCheck($exit === 0, 'Initial rebuild failed: '.$output);
        if (str_contains($output, 'Completed')) {
            $completed = true;
            break;
        }
    }
    benchmarkCheck($completed, 'Initial rebuild did not complete after 10 bounded attempts: '.$output);
    $caseVariant = 'PRESENT-0@example.test';
    $caseVariantExpected = $set->exists(NormalizedValue::fromBytes($caseVariant));
    benchmarkCheck(BloomGate::exists('benchmark.email', $caseVariant) === $caseVariantExpected,
        'Exact-byte normalization did not match authoritative SQL case semantics.');
    $hint = null;
    if ($hintRequested) {
        require_once __DIR__.'/benchmark-descriptor-hint.php';
        $hint = new BenchmarkDescriptorHint($app);
    }

    $workloads = [];
    foreach ([0, 100, 1000] as $present) {
        $values = [];
        $expected = [];
        for ($index = 0; $index < 1000; $index++) {
            $expected[] = $index < $present;
            $values[] = ($index < $present ? 'present-' : 'absent-').$index.'@example.test';
        }
        $workloads['present_'.$present.'_of_1000'] = benchmarkWorkload($set, $app['redis']->connection(),
            $values, $expected, $profile, $profile !== null ? $app : null, $hint);
    }
    $store = $app->make(WriterSynchronizationStore::class);
    $name = FilterName::fromString('benchmark.email');
    $epoch = $store->read($name)?->currentEpoch();
    benchmarkCheck($epoch !== null, 'Synchronization state missing.');
    $retained = 0;
    $drain = [];
    foreach ([0, 100, 1000] as $target) {
        while ($retained < $target) {
            $token = WriterLeaseToken::fromString(sprintf('%032x', $retained));
            $store->acquire($name, $token);
            benchmarkCheck($store->release($name, $token)->state() === WriterLeaseState::Released,
                'Benchmark writer did not release.');
            $retained++;
        }
        $actualRetained = $app['redis']->connection()->command('hlen', [
            $app->make(RedisKeyspace::class)->syncLeasesKey($name),
        ]);
        benchmarkCheck(is_int($actualRetained) && $actualRetained === $target,
            'Actual retained lease count did not match the writer drain measurement label.');
        $samples = [];
        for ($repetition = 0; $repetition < 11; $repetition++) {
            $started = hrtime(true);
            benchmarkCheck($store->activeWriterCount($name, $epoch) === 0, 'Released leases prevented zero writer count.');
            $elapsed = (hrtime(true) - $started) / 1_000_000;
            if ($repetition > 0) {
                $samples[] = $elapsed;
            }
        }
        // Ten measurements: use the mean of the two middle samples.
        sort($samples, SORT_NUMERIC);
        $drain[] = ['retained_released_leases' => $retained, 'active_writers' => 0,
            'elapsed_ms' => $samples, 'median_elapsed_ms' => ($samples[4] + $samples[5]) / 2];
    }

    $hintMutation = null;
    if ($hint !== null) {
        benchmarkCheck($profile !== null, 'Descriptor hint profiling is required.');
        $hintMutation = benchmarkHintMutation($app, $set, $hint, $profile);
    }

    $prepared = BloomGate::prepare('benchmark.email', WriterLeaseToken::generate()->value(), ['rolled-back@example.test']);
    benchmarkCheck($prepared->authoritativeAborted() === CoordinatedWriterCompletionResult::Released,
        'Rollback lease cleanup failed.');
    $before = $set->lookups;
    $rolledBack = BloomGate::existsResult('benchmark.email', 'rolled-back@example.test');
    benchmarkCheck(! $rolledBack->exists() && $rolledBack->membership() === Membership::MaybePresent
        && $set->lookups === $before + 1, 'Pre-added absent value did not use authoritative fallback.');

    return [
        'schema_version' => 1, 'status' => 'passed', 'php' => PHP_VERSION,
        'laravel' => Application::VERSION, 'redis' => $app['redis']->connection()->info()['redis_version'] ?? 'unknown',
        'database_driver' => $database->getAttribute(PDO::ATTR_DRIVER_NAME),
        'database_server_version' => $database->getAttribute(PDO::ATTR_SERVER_VERSION),
        'seed_rows' => 1000, 'configured_false_positive_rate' => 0.01,
        'measured_repetitions' => 5, 'warmup_repetitions' => 1,
        'workloads' => $workloads, 'rollback_false_positive_fallback_verified' => true,
        'writer_drain' => $drain,
        'profile_enabled' => $profile !== null,
        'descriptor_hint_enabled' => $hint !== null,
        'descriptor_hint_mutation_fallback_verified' => $hintMutation,
        'readonly_replay_calls' => $profile?->capturedCalls() ?? [],
        'limitations' => [
            'A dedicated test database with indexed email lookups; no additional latency is simulated.',
            'SQLite defaults to memory; MySQL/PostgreSQL evidence requires explicitly supplied PDO DSN and observed runs.',
            'Redis is a separate process; timings depend on the supplied runtime and transport.',
            'EVAL commandstats deltas require an otherwise idle Redis instance and exclude client/network CPU.',
            'Profiling adds wrapper bookkeeping; executor wall time includes transport, driver dispatch and Redis execution.',
            'Descriptor hints are an experimental fixture for batch/process reuse; cold single-query requests still make three Redis calls.',
            'Hints cache only descriptor metadata; every value executes the unchanged atomic production probe and fresh application fingerprint calculation.',
            'Hint bypass diagnostics can differ from fresh resolution; no production API compatibility claim is made.',
            'Fixed deterministic requests and dataset; no concurrency, production load or statistical speed claim.',
            'A random isolated Redis prefix requires a disposable Redis instance; no shared keys are deleted.',
        ],
    ];
}

try {
    echo json_encode(runBenchmark(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $failure) {
    fwrite(STDERR, json_encode(['status' => 'failed', 'message' => $failure->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
