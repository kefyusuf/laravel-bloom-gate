<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Kefyusuf\BloomGate\Application\CoordinatedWriterCompletionResult;
use Kefyusuf\BloomGate\Contracts\AuthoritativeSet;
use Kefyusuf\BloomGate\Contracts\FilterDefinition;
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
use Kefyusuf\BloomGate\Laravel\Facades\BloomGate;

require __DIR__.'/vendor/autoload.php';

final class FactoryDemoRow extends Model
{
    protected $table = 'users';

    protected $primaryKey = 'email';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

/** @extends Factory<FactoryDemoRow> */
final class FactoryDemoRowFactory extends Factory
{
    protected $model = FactoryDemoRow::class;

    /** @return array{email: string} */
    public function definition(): array
    {
        return ['email' => 'demo@example.test'];
    }
}

final class FactoryDemoNormalizer implements ValueNormalizer
{
    public function identity(): NormalizationIdentity
    {
        return NormalizationIdentity::fromString('factory-demo-email-exact@1');
    }

    public function normalize(string|int $value): NormalizedValue
    {
        return NormalizedValue::fromBytes((string) $value);
    }
}

final class FactoryDemoSet implements AuthoritativeSet
{
    public int $lookups = 0;

    public function __construct(private PDO $database) {}

    public function identity(): AuthoritativeSetIdentity
    {
        $driver = $this->database->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (! is_string($driver)) {
            throw new RuntimeException('Invalid PDO driver name.');
        }

        return AuthoritativeSetIdentity::fromString('factory-demo-'.$driver.'-users-email@1');
    }

    public function exists(NormalizedValue $value): bool
    {
        $this->lookups++;
        $query = $this->database->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        if ($query === false) {
            throw new RuntimeException('Could not prepare authoritative query.');
        }
        $query->execute([$value->bytes()]);

        return $query->fetchColumn() !== false;
    }

    public function values(): iterable
    {
        $query = $this->database->query('SELECT email FROM users ORDER BY email');
        if ($query === false) {
            throw new RuntimeException('Could not enumerate authoritative values.');
        }
        while (($value = $query->fetchColumn()) !== false) {
            if (! is_string($value)) {
                throw new RuntimeException('Invalid authoritative value.');
            }
            yield $value;
        }
    }
}

final readonly class FactoryDemoDefinition implements FilterDefinition
{
    public function __construct(private FactoryDemoNormalizer $normalization, private FactoryDemoSet $set) {}

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

function factoryDemoCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function runFactoryDemo(): void
{
    foreach (['bootstrap/cache', 'config', 'storage/framework/cache', 'storage/framework/views', 'storage/logs'] as $directory) {
        if (! is_dir(__DIR__.'/'.$directory)) {
            mkdir(__DIR__.'/'.$directory, 0777, true);
        }
    }

    $run = WriterLeaseToken::generate()->value();
    $database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $driver = $database->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (! is_string($driver)) {
        throw new RuntimeException('Invalid PDO driver name.');
    }
    // MySQL's default text collation folds case; the exact-byte normalizer requires binary equality.
    $emailType = $driver === 'mysql' ? 'VARBINARY(255)' : 'VARCHAR(255)';
    $database->exec('DROP TABLE IF EXISTS users');
    $database->exec('CREATE TABLE users (email '.$emailType.' PRIMARY KEY)');
    $set = new FactoryDemoSet($database);
    $app = Application::configure(basePath: __DIR__)->withExceptions()->create();
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $app['config']->set('database.default', 'sqlite');
    $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);
    $app['db']->connection()->setPdo($database);
    FactoryDemoRowFactory::new()->count(100)->sequence(fn (Sequence $sequence): array => [
        'email' => $sequence->index === 0 ? 'seed@example.test' : sprintf('demo-%04d@example.test', $sequence->index),
    ])->create();
    $app['config']->set('database.redis', ['client' => 'phpredis', 'default' => [
        'host' => (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
        'port' => (int) (getenv('REDIS_PORT') ?: 6379), 'database' => 0,
    ]]);
    $app['config']->set('bloom-gate.default', 'redis');
    $app['config']->set('bloom-gate.keyspace.prefix', 'lbg_factory_demo_'.$run);
    $app['config']->set('bloom-gate.drivers.redis.trusted_negative_profile', getenv('PILOT_PROFILE') ?: null);
    $app['config']->set('bloom-gate.filters', ['demo.email' => [
        'enabled' => true, 'definition' => FactoryDemoDefinition::class,
        'capacity' => 100, 'false_positive_rate' => 0.000001, 'coordination' => 'coordinated-v1',
    ]]);
    $app->instance(FactoryDemoDefinition::class, new FactoryDemoDefinition(new FactoryDemoNormalizer, $set));

    foreach (['bloom:coordinate:adopt', 'bloom:rebuild'] as $command) {
        $exit = $kernel->call($command, ['filter' => 'demo.email']);
        $output = $kernel->output();
        factoryDemoCheck($exit === 0, $command.' failed: '.$output);
        if ($command === 'bloom:rebuild') {
            factoryDemoCheck(str_contains($output, 'Completed'), 'Initial rebuild did not complete: '.$output);
        }
    }
    $countQuery = $database->query('SELECT COUNT(*) FROM users');
    if ($countQuery === false) {
        throw new RuntimeException('Could not count factory records.');
    }
    factoryDemoCheck((int) $countQuery->fetchColumn() === 100,
        'Factory must create exactly 100 demo records.');

    $before = $set->lookups;
    $absent = BloomGate::existsResult('demo.email', 'absent@example.test');
    factoryDemoCheck(! $absent->exists() && $absent->membership() === Membership::DefinitelyAbsent && $set->lookups === $before,
        'Safe negative did not skip the authoritative SQL lookup.');
    factoryDemoCheck(BloomGate::exists('demo.email', 'seed@example.test') && $set->lookups === $before + 1,
        'Present value did not consult authoritative SQL.');
    $caseVariant = 'SEED@example.test';
    $caseVariantExpected = $set->exists(NormalizedValue::fromBytes($caseVariant));
    factoryDemoCheck(BloomGate::exists('demo.email', $caseVariant) === $caseVariantExpected,
        'Exact-byte normalization did not match authoritative SQL case semantics.');

    $prepared = BloomGate::prepare('demo.email', WriterLeaseToken::generate()->value(), ['committed@example.test']);
    factoryDemoCheck($prepared->lease()->state() === WriterLeaseState::Prepared, 'Write was not prepared.');
    $database->beginTransaction();
    $database->exec("INSERT INTO users VALUES ('committed@example.test')");
    $database->commit();
    factoryDemoCheck($prepared->authoritativeCommitted() === CoordinatedWriterCompletionResult::Released, 'Committed write cleanup failed.');
    factoryDemoCheck(BloomGate::exists('demo.email', 'committed@example.test'), 'Committed SQL value was lost.');

    $token = WriterLeaseToken::generate()->value();
    $aborted = BloomGate::prepare('demo.email', $token, ['aborted@example.test']);
    $store = $app->make(WriterSynchronizationStore::class);
    factoryDemoCheck($store->readLease(FilterName::fromString('demo.email'), WriterLeaseToken::fromString($token))?->state() === WriterLeaseState::Prepared,
        'Unacknowledged write did not retain its lease.');
    $database->beginTransaction();
    $database->exec("INSERT INTO users VALUES ('aborted@example.test')");
    $database->rollBack();
    factoryDemoCheck($aborted->authoritativeAborted() === CoordinatedWriterCompletionResult::Released, 'Rolled-back write cleanup failed.');
    $before = $set->lookups;
    factoryDemoCheck(! BloomGate::exists('demo.email', 'aborted@example.test') && $set->lookups === $before + 1,
        'Pre-added but rolled-back value did not use authoritative SQL.');

    factoryDemoCheck($kernel->call('bloom:rebuild', ['filter' => 'demo.email']) === 0 && str_contains($kernel->output(), 'Completed'),
        'Post-write rebuild did not complete.');
    factoryDemoCheck(BloomGate::exists('demo.email', 'committed@example.test'), 'Rebuild lost committed SQL membership.');
    $workload = [];
    for ($index = 0; $index < 1000; $index++) {
        $workload[] = $index === 0 ? 'seed@example.test' : sprintf('demo-%04d@example.test', $index);
    }
    $before = $set->lookups;
    $expected = array_map(fn (string $email): bool => $set->exists(NormalizedValue::fromBytes($email)), $workload);
    $directSql = $set->lookups - $before;
    $before = $set->lookups;
    $actual = array_map(fn (string $email): bool => BloomGate::exists('demo.email', $email), $workload);
    $gateSql = $set->lookups - $before;
    factoryDemoCheck($expected === $actual, 'Factory workload differs from authoritative SQL.');
    factoryDemoCheck(count(array_filter($actual)) === 100, 'Factory workload must contain 100 present values.');
    factoryDemoCheck($directSql === 1000 && $gateSql >= 100 && $gateSql < $directSql,
        'Factory workload did not retain positive fallback and skip safe negatives.');
    echo json_encode(['factory_records' => 100, 'lookups' => 1000, 'present' => 100,
        'absent' => 900, 'direct_sql' => $directSql, 'gate_sql' => $gateSql,
        'result_parity' => true], JSON_THROW_ON_ERROR).PHP_EOL;

    $app['config']->set('bloom-gate.enabled', false);
    $before = $set->lookups;
    factoryDemoCheck(! BloomGate::exists('demo.email', 'absent@example.test') && $set->lookups === $before + 1,
        'Disabled optimization did not consult authoritative SQL.');

    $driver = $database->getAttribute(PDO::ATTR_DRIVER_NAME);
    $serverVersion = $database->getAttribute(PDO::ATTR_SERVER_VERSION);
    if (! is_string($driver) || ! is_string($serverVersion)) {
        throw new RuntimeException('Invalid PDO runtime metadata.');
    }
    echo 'PASS: factory-created Redis/'.$driver.
        ' pilot, server='.$serverVersion.
        ', trusted negative, SQL fallback, commit/rollback leases and rebuild.'.PHP_EOL;
}

try {
    runFactoryDemo();
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage().PHP_EOL);
    exit(1);
}
