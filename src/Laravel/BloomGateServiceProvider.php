<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Kefyusuf\BloomGate\Application\CandidateDiscarder;
use Kefyusuf\BloomGate\Application\ManagedFilterActivator;
use Kefyusuf\BloomGate\Application\ManagedFilterBuilder;
use Kefyusuf\BloomGate\Application\ManagedFilterVerifier;
use Kefyusuf\BloomGate\Application\ManagedFilterStatusReader;
use Kefyusuf\BloomGate\Application\MembershipAdder;
use Kefyusuf\BloomGate\Application\OptimalBloomSizingV1;
use Kefyusuf\BloomGate\Application\QueryGate;
use Kefyusuf\BloomGate\Application\QuerySafetyDescriptorResolver;
use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Contracts\AuthorizedProbe;
use Kefyusuf\BloomGate\Contracts\BloomDriver;
use Kefyusuf\BloomGate\Contracts\BloomGenerationInspector;
use Kefyusuf\BloomGate\Contracts\BulkBloomDriver;
use Kefyusuf\BloomGate\Contracts\Exception\InvalidConfiguration;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Contracts\Redis\RedisCommandExecutor;
use Kefyusuf\BloomGate\Contracts\Redis\RedisStructuredCommandExecutor;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryAuthorizedProbe;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryBloomDriver;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryFilterControlStore;
use Kefyusuf\BloomGate\Drivers\Memory\MemoryGenerationContractStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisAuthorizedProbe;
use Kefyusuf\BloomGate\Drivers\Redis\RedisBloomDriver;
use Kefyusuf\BloomGate\Drivers\Redis\RedisControlStateCodec;
use Kefyusuf\BloomGate\Drivers\Redis\RedisFilterControlStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisGenerationContractStore;
use Kefyusuf\BloomGate\Drivers\Redis\RedisKeyspace;
use Kefyusuf\BloomGate\Laravel\Console\ActivateCommand;
use Kefyusuf\BloomGate\Laravel\Console\BuildCommand;
use Kefyusuf\BloomGate\Laravel\Console\DiscardCommand;
use Kefyusuf\BloomGate\Laravel\Console\StatusCommand;
use Kefyusuf\BloomGate\Laravel\Console\VerifyCommand;
use Kefyusuf\BloomGate\Laravel\Redis\LaravelRedisCommandExecutor;
use Kefyusuf\BloomGate\Laravel\Redis\RedisTrustedNegativeProfileResolver;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationEvidenceApplier;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerifier;
use Kefyusuf\BloomGate\Lifecycle\CandidateAllocator;
use Kefyusuf\BloomGate\Lifecycle\CandidatePromoter;
use Kefyusuf\BloomGate\Lifecycle\GenerationHealthUpdater;
use Kefyusuf\BloomGate\Lifecycle\GenerationLifecycleTransitioner;
use Kefyusuf\BloomGate\Lifecycle\LifecycleTransitionPolicy;

final class BloomGateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/bloom-gate.php',
            'bloom-gate',
        );

        $this->registerRegistry();
        $this->registerInfrastructure();
        $this->registerLifecycle();
        $this->registerApplication();
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../../config/bloom-gate.php' => config_path('bloom-gate.php'),
        ], 'bloom-gate-config');

        $this->commands([
            BuildCommand::class,
            VerifyCommand::class,
            ActivateCommand::class,
            DiscardCommand::class,
            StatusCommand::class,
        ]);
    }

    private function registerRegistry(): void
    {
        $this->app->singleton(FilterDefinitionResolver::class);
        $this->app->singleton(
            FilterRegistry::class,
            ConfigFilterRegistry::class,
        );
        $this->app->singleton(
            RedisTrustedNegativeProfileResolver::class,
        );
    }

    private function registerInfrastructure(): void
    {
        $this->app->singleton(MemoryBloomDriver::class);
        $this->app->singleton(MemoryFilterControlStore::class);
        $this->app->singleton(
            MemoryGenerationContractStore::class,
            static fn (Application $app): MemoryGenerationContractStore => new MemoryGenerationContractStore(
                $app->make(MemoryBloomDriver::class),
            ),
        );
        $this->app->singleton(
            MemoryAuthorizedProbe::class,
            static fn (Application $app): MemoryAuthorizedProbe => new MemoryAuthorizedProbe(
                snapshots: $app->make(MemoryFilterControlStore::class),
                contracts: $app->make(MemoryGenerationContractStore::class),
                driver: $app->make(MemoryBloomDriver::class),
            ),
        );

        $this->app->singleton(
            LaravelRedisCommandExecutor::class,
            static function (Application $app): LaravelRedisCommandExecutor {
                $redis = $app->make('redis');

                return new LaravelRedisCommandExecutor(
                    $redis->connection(self::redisConnectionName($app)),
                );
            },
        );
        $this->app->alias(
            LaravelRedisCommandExecutor::class,
            RedisStructuredCommandExecutor::class,
        );
        $this->app->alias(
            LaravelRedisCommandExecutor::class,
            RedisCommandExecutor::class,
        );

        $this->app->singleton(
            RedisKeyspace::class,
            static function (Application $app): RedisKeyspace {
                try {
                    return RedisKeyspace::fromPrefix(
                        self::requiredString(
                            $app,
                            'bloom-gate.keyspace.prefix',
                        ),
                    );
                } catch (InvalidArgumentException $failure) {
                    throw new InvalidConfiguration(
                        'Bloom Gate Redis keyspace prefix is invalid.',
                        0,
                        $failure,
                    );
                }
            },
        );
        $this->app->singleton(RedisControlStateCodec::class);
        $this->app->singleton(RedisBloomDriver::class);
        $this->app->singleton(RedisFilterControlStore::class);
        $this->app->singleton(RedisGenerationContractStore::class);
        $this->app->singleton(
            RedisAuthorizedProbe::class,
            static fn (Application $app): RedisAuthorizedProbe => new RedisAuthorizedProbe(
                executor: $app->make(RedisStructuredCommandExecutor::class),
                keyspace: $app->make(RedisKeyspace::class),
                trustedNegativeProfile: $app
                    ->make(RedisTrustedNegativeProfileResolver::class)
                    ->resolve(),
            ),
        );

        $this->app->singleton(
            BulkBloomDriver::class,
            static fn (Application $app): BulkBloomDriver => match (self::driverName($app)) {
                'memory' => $app->make(MemoryBloomDriver::class),
                'redis' => $app->make(RedisBloomDriver::class),
            },
        );
        $this->app->alias(BulkBloomDriver::class, BloomDriver::class);

        $this->app->singleton(
            FilterControlStore::class,
            static fn (Application $app): FilterControlStore => match (self::driverName($app)) {
                'memory' => $app->make(MemoryFilterControlStore::class),
                'redis' => $app->make(RedisFilterControlStore::class),
            },
        );
        $this->app->alias(
            FilterControlStore::class,
            ActiveGenerationSnapshotReader::class,
        );

        $this->app->singleton(
            GenerationContractStore::class,
            static fn (Application $app): GenerationContractStore => match (self::driverName($app)) {
                'memory' => $app->make(MemoryGenerationContractStore::class),
                'redis' => $app->make(RedisGenerationContractStore::class),
            },
        );

        $this->app->singleton(
            BloomGenerationInspector::class,
            static fn (Application $app): BloomGenerationInspector => match (self::driverName($app)) {
                'memory' => $app->make(MemoryBloomDriver::class),
                'redis' => $app->make(RedisGenerationContractStore::class),
            },
        );

        $this->app->singleton(
            AuthorizedProbe::class,
            static fn (Application $app): AuthorizedProbe => match (self::driverName($app)) {
                'memory' => $app->make(MemoryAuthorizedProbe::class),
                'redis' => $app->make(RedisAuthorizedProbe::class),
            },
        );
    }

    private function registerLifecycle(): void
    {
        foreach ([
            BloomProbeGenerator::class,
            SemanticFingerprintCalculator::class,
            OptimalBloomSizingV1::class,
            LifecycleTransitionPolicy::class,
            CandidateAllocator::class,
            GenerationLifecycleTransitioner::class,
            GenerationHealthUpdater::class,
            ActivationVerifier::class,
            ActivationVerificationEvidenceApplier::class,
            CandidatePromoter::class,
        ] as $service) {
            $this->app->singleton($service);
        }
    }

    private function registerApplication(): void
    {
        foreach ([
            QuerySafetyDescriptorResolver::class,
            QueryGate::class,
            MembershipAdder::class,
            ManagedFilterVerifier::class,
            ManagedFilterStatusReader::class,
            CandidateDiscarder::class,
            BloomGateManager::class,
        ] as $service) {
            $this->app->singleton($service);
        }

        $this->app->singleton(
            ManagedFilterBuilder::class,
            static fn (Application $app): ManagedFilterBuilder => new ManagedFilterBuilder(
                registry: $app->make(FilterRegistry::class),
                sizing: $app->make(OptimalBloomSizingV1::class),
                allocator: $app->make(CandidateAllocator::class),
                transitions: $app->make(GenerationLifecycleTransitioner::class),
                health: $app->make(GenerationHealthUpdater::class),
                driver: $app->make(BulkBloomDriver::class),
                generationContracts: $app->make(GenerationContractStore::class),
                probes: $app->make(BloomProbeGenerator::class),
                fingerprints: $app->make(SemanticFingerprintCalculator::class),
                chunkSize: self::buildChunkSize($app),
            ),
        );

        $this->app->singleton(
            ManagedFilterActivator::class,
            static fn (Application $app): ManagedFilterActivator => new ManagedFilterActivator(
                registry: $app->make(FilterRegistry::class),
                control: $app->make(FilterControlStore::class),
                generationContracts: $app->make(GenerationContractStore::class),
                driver: $app->make(BulkBloomDriver::class),
                probes: $app->make(BloomProbeGenerator::class),
                fingerprints: $app->make(SemanticFingerprintCalculator::class),
                verifier: $app->make(ManagedFilterVerifier::class),
                promoter: $app->make(CandidatePromoter::class),
                chunkSize: self::buildChunkSize($app),
            ),
        );
    }

    /**
     * @return 'memory'|'redis'
     */
    private static function driverName(Application $app): string
    {
        $driver = self::requiredString($app, 'bloom-gate.default');

        if ($driver !== 'memory' && $driver !== 'redis') {
            throw new InvalidConfiguration(
                'Bloom Gate default driver must be [memory] or [redis].',
            );
        }

        return $driver;
    }

    private static function redisConnectionName(Application $app): string
    {
        return self::requiredString(
            $app,
            'bloom-gate.drivers.redis.connection',
        );
    }

    private static function buildChunkSize(Application $app): int
    {
        $value = self::config($app)->get('bloom-gate.build.chunk_size');

        if (! is_int($value) || $value < 1) {
            throw new InvalidConfiguration(
                'Bloom Gate build chunk size must be a positive integer.',
            );
        }

        return $value;
    }

    private static function requiredString(
        Application $app,
        string $key,
    ): string {
        $value = self::config($app)->get($key);

        if (! is_string($value) || $value === '') {
            throw new InvalidConfiguration(sprintf(
                'Bloom Gate configuration [%s] must be a non-empty string.',
                $key,
            ));
        }

        return $value;
    }

    private static function config(Application $app): Repository
    {
        return $app->make(Repository::class);
    }
}
