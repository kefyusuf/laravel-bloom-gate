<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
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

final class PilotNormalizer implements ValueNormalizer
{
    public function identity(): NormalizationIdentity
    {
        return NormalizationIdentity::fromString('rc-pilot-email-exact@1');
    }

    public function normalize(string|int $value): NormalizedValue
    {
        return NormalizedValue::fromBytes((string) $value);
    }
}

final class PilotSet implements AuthoritativeSet
{
    public int $lookups = 0;

    public function __construct(private PDO $database) {}

    public function identity(): AuthoritativeSetIdentity
    {
        return AuthoritativeSetIdentity::fromString('rc-pilot-sqlite-users-email@1');
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

final readonly class PilotDefinition implements FilterDefinition
{
    public function __construct(private PilotNormalizer $normalization, private PilotSet $set) {}

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

function pilotCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function runRedisPilot(): void
{
    foreach (['bootstrap/cache', 'config', 'storage/framework/cache', 'storage/framework/views', 'storage/logs'] as $directory) {
        if (! is_dir(__DIR__.'/'.$directory)) {
            mkdir(__DIR__.'/'.$directory, 0777, true);
        }
    }

    $run = bin2hex(random_bytes(8));
    $database = new PDO('sqlite:'.__DIR__.'/pilot-'.$run.'.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $database->exec('CREATE TABLE users (email TEXT PRIMARY KEY)');
    $database->exec("INSERT INTO users VALUES ('seed@example.test')");
    $set = new PilotSet($database);
    $app = Application::configure(basePath: __DIR__)->withExceptions()->create();
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $app['config']->set('database.redis', ['client' => 'phpredis', 'default' => [
        'host' => (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
        'port' => (int) (getenv('REDIS_PORT') ?: 6379), 'database' => 0,
    ]]);
    $app['config']->set('bloom-gate.default', 'redis');
    $app['config']->set('bloom-gate.keyspace.prefix', 'lbg_rc_pilot_'.$run);
    $app['config']->set('bloom-gate.drivers.redis.trusted_negative_profile', getenv('PILOT_PROFILE') ?: null);
    $app['config']->set('bloom-gate.filters', ['pilot.email' => [
        'enabled' => true, 'definition' => PilotDefinition::class,
        'capacity' => 100, 'false_positive_rate' => 0.000001, 'coordination' => 'coordinated-v1',
    ]]);
    $app->instance(PilotDefinition::class, new PilotDefinition(new PilotNormalizer, $set));

    foreach (['bloom:coordinate:adopt', 'bloom:rebuild'] as $command) {
        $exit = $kernel->call($command, ['filter' => 'pilot.email']);
        $output = $kernel->output();
        pilotCheck($exit === 0, $command.' failed: '.$output);
        if ($command === 'bloom:rebuild') {
            pilotCheck(str_contains($output, 'Completed'), 'Initial rebuild did not complete: '.$output);
        }
    }
    $before = $set->lookups;
    $absent = BloomGate::existsResult('pilot.email', 'absent@example.test');
    pilotCheck(! $absent->exists() && $absent->membership() === Membership::DefinitelyAbsent && $set->lookups === $before,
        'Safe negative did not skip the authoritative SQL lookup.');
    pilotCheck(BloomGate::exists('pilot.email', 'seed@example.test') && $set->lookups === $before + 1,
        'Present value did not consult authoritative SQL.');

    $prepared = BloomGate::prepare('pilot.email', bin2hex(random_bytes(16)), ['committed@example.test']);
    pilotCheck($prepared->lease()->state() === WriterLeaseState::Prepared, 'Write was not prepared.');
    $database->beginTransaction();
    $database->exec("INSERT INTO users VALUES ('committed@example.test')");
    $database->commit();
    pilotCheck($prepared->authoritativeCommitted() === CoordinatedWriterCompletionResult::Released, 'Committed write cleanup failed.');
    pilotCheck(BloomGate::exists('pilot.email', 'committed@example.test'), 'Committed SQL value was lost.');

    $token = bin2hex(random_bytes(16));
    $aborted = BloomGate::prepare('pilot.email', $token, ['aborted@example.test']);
    $store = $app->make(WriterSynchronizationStore::class);
    pilotCheck($store->readLease(FilterName::fromString('pilot.email'), WriterLeaseToken::fromString($token))?->state() === WriterLeaseState::Prepared,
        'Unacknowledged write did not retain its lease.');
    $database->beginTransaction();
    $database->exec("INSERT INTO users VALUES ('aborted@example.test')");
    $database->rollBack();
    pilotCheck($aborted->authoritativeAborted() === CoordinatedWriterCompletionResult::Released, 'Rolled-back write cleanup failed.');
    $before = $set->lookups;
    pilotCheck(! BloomGate::exists('pilot.email', 'aborted@example.test') && $set->lookups === $before + 1,
        'Pre-added but rolled-back value did not use authoritative SQL.');

    pilotCheck($kernel->call('bloom:rebuild', ['filter' => 'pilot.email']) === 0 && str_contains($kernel->output(), 'Completed'),
        'Post-write rebuild did not complete.');
    pilotCheck(BloomGate::exists('pilot.email', 'committed@example.test'), 'Rebuild lost committed SQL membership.');
    $app['config']->set('bloom-gate.enabled', false);
    $before = $set->lookups;
    pilotCheck(! BloomGate::exists('pilot.email', 'absent@example.test') && $set->lookups === $before + 1,
        'Disabled optimization did not consult authoritative SQL.');

    echo "PASS: production-only Redis/SQLite pilot, trusted negative, SQL fallback, commit/rollback leases and rebuild.\n";
}

try {
    runRedisPilot();
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage().PHP_EOL);
    exit(1);
}
