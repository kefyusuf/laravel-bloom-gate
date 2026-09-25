<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use Kefyusuf\BloomGate\Application\CandidateDiscarder;
use Kefyusuf\BloomGate\Application\ManagedFilterActivator;
use Kefyusuf\BloomGate\Application\ManagedFilterBuilder;
use Kefyusuf\BloomGate\Application\ManagedFilterVerifier;
use Kefyusuf\BloomGate\Application\MembershipAdder;
use Kefyusuf\BloomGate\Application\QueryGate;
use Kefyusuf\BloomGate\Application\QuerySafetyDescriptorResolver;
use Kefyusuf\BloomGate\Contracts\ActiveGenerationSnapshotReader;
use Kefyusuf\BloomGate\Contracts\AuthorizedProbe;
use Kefyusuf\BloomGate\Contracts\BloomDriver;
use Kefyusuf\BloomGate\Contracts\BulkBloomDriver;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\GenerationContractStore;
use Kefyusuf\BloomGate\Laravel\BloomGateManager;
use Kefyusuf\BloomGate\Laravel\BloomGateServiceProvider;
use Kefyusuf\BloomGate\Laravel\Facades\BloomGate as BloomGateFacade;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerificationEvidenceApplier;
use Kefyusuf\BloomGate\Lifecycle\ActivationVerifier;
use Kefyusuf\BloomGate\Lifecycle\CandidateAllocator;
use Kefyusuf\BloomGate\Lifecycle\CandidatePromoter;
use Kefyusuf\BloomGate\Lifecycle\GenerationHealthUpdater;
use Kefyusuf\BloomGate\Lifecycle\GenerationLifecycleTransitioner;
use Kefyusuf\BloomGate\Tests\Support\Laravel\Task15FilterDefinition;
use ReflectionClass;
use RuntimeException;

beforeEach(function (): void {
    config()->set('bloom-gate.default', 'memory');
    config()->set('bloom-gate.enabled', true);
    config()->set('bloom-gate.filters', []);
});

it('resolves the complete application and lifecycle service graph with memory driver', function (): void {
    $application = app();

    foreach ([
        FilterRegistry::class,
        BloomDriver::class,
        BulkBloomDriver::class,
        FilterControlStore::class,
        ActiveGenerationSnapshotReader::class,
        GenerationContractStore::class,
        AuthorizedProbe::class,
        QuerySafetyDescriptorResolver::class,
        QueryGate::class,
        MembershipAdder::class,
        ManagedFilterBuilder::class,
        ManagedFilterVerifier::class,
        ManagedFilterActivator::class,
        CandidateDiscarder::class,
        CandidateAllocator::class,
        GenerationLifecycleTransitioner::class,
        GenerationHealthUpdater::class,
        ActivationVerifier::class,
        ActivationVerificationEvidenceApplier::class,
        CandidatePromoter::class,
        BloomGateManager::class,
    ] as $service) {
        expect($application->make($service))->toBeObject();
    }

    expect($application->make(BloomDriver::class))
        ->toBe($application->make(BulkBloomDriver::class))
        ->and($application->make(FilterControlStore::class))
        ->toBe($application->make(ActiveGenerationSnapshotReader::class));
});

it('keeps redis and database infrastructure lazy during package registration', function (): void {
    $application = app();

    $application->bind(
        'redis',
        static fn (): never => throw new RuntimeException(
            'Redis must not resolve during package registration.',
        ),
    );
    $application->bind(
        'db',
        static fn (): never => throw new RuntimeException(
            'Database must not resolve during package registration.',
        ),
    );

    config()->set('bloom-gate.default', 'redis');
    config()->set('bloom-gate.drivers.redis.connection', 'definitely-missing');

    $application->register(BloomGateServiceProvider::class, true);

    expect($application->bound(QueryGate::class))->toBeTrue()
        ->and($application->bound(MembershipAdder::class))->toBeTrue()
        ->and($application->bound(BloomGateManager::class))->toBeTrue();
});

it('does not resolve filter definitions while registering the complete service graph', function (): void {
    $instances = 0;

    app()->bind(
        Task15FilterDefinition::class,
        static function () use (&$instances): Task15FilterDefinition {
            $instances++;

            return new Task15FilterDefinition;
        },
    );

    config()->set('bloom-gate.filters', [
        'users.email' => [
            'enabled' => true,
            'definition' => Task15FilterDefinition::class,
            'capacity' => 1_000_000,
            'false_positive_rate' => 0.001,
        ],
    ]);

    app()->register(BloomGateServiceProvider::class, true);

    expect($instances)->toBe(0);
});

it('facade resolves the same thin manager and delegates to canonical application services', function (): void {
    $definition = new Task15FilterDefinition;

    app()->instance(Task15FilterDefinition::class, $definition);
    config()->set('bloom-gate.filters', [
        'users.email' => [
            'enabled' => true,
            'definition' => Task15FilterDefinition::class,
            'capacity' => 1_000_000,
            'false_positive_rate' => 0.001,
        ],
    ]);

    BloomGateFacade::clearResolvedInstance(BloomGateManager::class);

    $manager = app()->make(BloomGateManager::class);
    $facadeRoot = BloomGateFacade::getFacadeRoot();

    expect($facadeRoot)->toBe($manager);

    $reflection = new ReflectionClass($manager);
    $queries = $reflection->getProperty('queries')->getValue($manager);
    $writes = $reflection->getProperty('writes')->getValue($manager);

    expect($queries)->toBe(app()->make(QueryGate::class))
        ->and($writes)->toBe(app()->make(MembershipAdder::class))
        ->and(BloomGateFacade::exists('users.email', 'value'))->toBeTrue()
        ->and($definition->normalizer->calls)->toBe(1)
        ->and($definition->authoritativeSet->existsCalls)->toBe(1);

    BloomGateFacade::add('users.email', 'new-value');

    expect($definition->normalizer->calls)->toBe(1);
});

it('keeps config publishing stable after full service wiring', function (): void {
    $paths = Illuminate\Support\ServiceProvider::pathsToPublish(
        BloomGateServiceProvider::class,
        'bloom-gate-config',
    );

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0] ?? null)
        ->toBe(config_path('bloom-gate.php'));
});

it('keeps facade and manager in the laravel layer only', function (): void {
    foreach ([
        __DIR__.'/../../../src/Laravel/BloomGateManager.php',
        __DIR__.'/../../../src/Laravel/Facades/BloomGate.php',
    ] as $path) {
        $source = file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException('Unable to read Task 15 Laravel surface.');
        }

        expect($source)->not->toContain('Drivers\\Redis\\')
            ->and($source)->not->toContain('FilterControlStore')
            ->and($source)->not->toContain('RedisCommandExecutor');
    }
});
