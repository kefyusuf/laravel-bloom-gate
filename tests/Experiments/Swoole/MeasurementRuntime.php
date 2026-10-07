<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Experiments\Swoole;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Kefyusuf\BloomGate\Application\ExistenceResult;
use Kefyusuf\BloomGate\Application\QueryGate;
use Kefyusuf\BloomGate\Application\QuerySafetyDescriptorResolver;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\Redis\RedisStructuredCommandExecutor;
use Kefyusuf\BloomGate\Contracts\RegisteredFilter;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\BypassReason;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationControlState;
use Kefyusuf\BloomGate\Core\HealthState;
use Kefyusuf\BloomGate\Core\LifecycleState;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use Kefyusuf\BloomGate\Drivers\Apcu\ApcuQuerySafetyDescriptorCache;
use Kefyusuf\BloomGate\Drivers\Redis\RedisAuthorizedProbe;
use Kefyusuf\BloomGate\Drivers\Redis\RedisBloomDriver;
use Kefyusuf\BloomGate\Drivers\Redis\RedisControlStateCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisFilterControlStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisGenerationContractStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Laravel\Redis\LaravelRedisCommandExecutor;
use Redis;
use RuntimeException;

final readonly class MeasurementRegistry implements FilterRegistry
{
    public function __construct(private SharedMemoryQuery $query) {}

    public function globalQueryOptimizationEnabled(): bool
    {
        return false;
    }

    public function get(FilterName $name): RegisteredFilter
    {
        return $this->query->get($name);
    }
}

final class MeasurementCommands implements RedisStructuredCommandExecutor
{
    public int $calls = 0;

    public function __construct(private readonly RedisStructuredCommandExecutor $inner) {}

    public function evaluate(string $script, array $keys, array $arguments): int
    {
        $this->calls++;

        return $this->inner->evaluate($script, $keys, $arguments);
    }

    public function evaluateStructured(string $script, array $keys, array $arguments): array
    {
        $this->calls++;

        return $this->inner->evaluateStructured($script, $keys, $arguments);
    }
}

/** Worker-local connections are created after fork; the parent never constructs this instance. */
final class MeasurementRuntime
{
    private static ?self $worker = null;

    private readonly SharedSqlSet $sql;

    private readonly SharedMemoryQuery $shared;

    private readonly QueryGate $bypass;

    private readonly QueryGate $redis;

    private readonly MeasurementCommands $commands;

    private function __construct(private readonly SharedMemoryDomain $domain)
    {
        if (! apcu_enabled()) {
            throw new RuntimeException('APCu descriptor hints are unavailable.');
        }
        $this->sql = new SharedSqlSet(connection: SharedSqlSet::database());
        $this->shared = new SharedMemoryQuery($domain, set: $this->sql);
        $generator = new BloomProbeGenerator;
        $fingerprints = new SemanticFingerprintCalculator;
        $this->bypass = new QueryGate(new MeasurementRegistry($this->shared),
            new QuerySafetyDescriptorResolver(new SharedSnapshots($domain), new SharedContracts($domain)),
            new SharedProbe($domain), $generator, $fingerprints);
        $client = self::connect();
        $this->commands = new MeasurementCommands(new LaravelRedisCommandExecutor(new PhpRedisConnection($client)));
        $keys = RedisKeyspace::fromPrefix('measurement');
        $this->redis = new QueryGate($this->shared,
            new QuerySafetyDescriptorResolver(new RedisFilterControlStore($this->commands, $keys, new RedisControlStateCodec),
                new RedisGenerationContractStore($this->commands, $keys)),
            new RedisAuthorizedProbe($this->commands, $keys, RedisAuthorizedProbe::TRUSTED_NEGATIVE_PROFILE),
            $generator, $fingerprints, new ApcuQuerySafetyDescriptorCache($domain->name->value()));
        $this->redis->existsResult($domain->name->value(), 'absent-warmup');
    }

    public static function worker(SharedMemoryDomain $domain): self
    {
        return self::$worker ??= new self($domain);
    }

    /** @return array<string, bool|int|float|string> */
    public function lookup(string $path, string $key): array
    {
        $sql = $this->sql->calls;
        $redis = $this->commands->calls;
        $start = hrtime(true);
        $result = match ($path) {
            'direct' => ExistenceResult::bypassed($this->sql->exists($this->shared->normalizer()->normalize($key)), BypassReason::optimizationDisabled()),
            'bypass' => $this->bypass->existsResult($this->domain->name->value(), $key),
            'redis' => $this->redis->existsResult($this->domain->name->value(), $key),
            'shared' => $this->shared->lookup($key),
            default => throw new RuntimeException('Unknown measurement path.'),
        };

        return ['exists' => $result->exists(), 'membership' => $result->membership()->name,
            'sql_calls' => $this->sql->calls - $sql, 'redis_calls' => $this->commands->calls - $redis,
            'query_ms' => (hrtime(true) - $start) / 1000000, 'pid' => getmypid() ?: 0,
            'filter' => $this->domain->name->value()];
    }

    private static function connect(): Redis
    {
        $redis = new Redis;
        if (! $redis->connect((string) getenv('DEMO_REDIS_HOST'), 6379, 5)) {
            throw new RuntimeException('Measurement Redis connection failed.');
        }

        return $redis;
    }

    /** Parent-only admission transports the already verified bitmap; it closes Redis before fork. */
    public static function publishRedis(SharedMemoryDomain $domain): void
    {
        $state = $domain->state() ?? throw new RuntimeException('No sealed shared dataset.');
        $bitmap = $domain->exportBitmap();
        $descriptor = $domain->managed($state['version']);
        $version = FilterVersion::fromInt($state['version']);
        $redis = self::connect();
        $config = $redis->config('GET', '*');
        $role = $redis->role();
        if (! is_array($config) || ($config['appendonly'] ?? null) !== 'yes'
            || ($config['appendfsync'] ?? null) !== 'always' || ($config['maxmemory-policy'] ?? null) !== 'noeviction'
            || ! is_array($role) || ($role[0] ?? null) !== 'master') {
            throw new RuntimeException('Redis does not satisfy the asserted standalone durable profile.');
        }
        $executor = new LaravelRedisCommandExecutor(new PhpRedisConnection($redis));
        $keys = RedisKeyspace::fromPrefix('measurement');
        (new RedisBloomDriver($executor, $keys))->provision($domain->name, $version, $descriptor->layout());
        $map = [];
        for ($byte = 0; $byte < 256; $byte++) {
            $reversed = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                $reversed |= (($byte >> $bit) & 1) << (7 - $bit);
            }
            $map[chr($byte & 0xFF)] = chr($reversed & 0xFF);
        }
        $bitmap = strtr($bitmap, $map);
        if (! $redis->set($keys->bitmapKey($domain->name, $version), $bitmap)
            || $redis->get($keys->bitmapKey($domain->name, $version)) !== $bitmap) {
            throw new RuntimeException('Redis bitmap admission failed.');
        }
        (new RedisGenerationContractStore($executor, $keys))->bind($domain->name, $version, $descriptor->layout(), $descriptor->semanticContract());
        $control = new FilterControlState($domain->name, FilterStateRevision::fromInt(1), $version, $version, null,
            [new GenerationControlState($version, LifecycleState::Active, HealthState::Healthy)]);
        $redis->rawCommand('HSET', $keys->stateKey($domain->name), ...(new RedisControlStateCodec)->encode($control));
        $redis->close();
    }
}
