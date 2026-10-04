<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Feature\Laravel;

use Illuminate\Support\Facades\Artisan;
use Kefyusuf\BloomGate\Application\CoordinationStatusReader;
use Kefyusuf\BloomGate\Contracts\BloomGenerationInspector;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Contracts\Exception\BloomStorageCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStoreOperationFailed;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlStateCorrupt;
use Kefyusuf\BloomGate\Contracts\FilterControlStore;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Laravel\Facades\BloomGate;
use Kefyusuf\BloomGate\Tests\Support\Laravel\Task17FilterDefinition;
use Kefyusuf\BloomGate\Tests\TestCase;

final class CoordinationStatusFailureTest extends TestCase
{
    private function configure(mixed $mode = 'coordinated-v1'): void
    {
        config()->set('bloom-gate.default', 'memory');
        config()->set('bloom-gate.filters', ['users.email' => [
            'enabled' => true, 'definition' => Task17FilterDefinition::class,
            'capacity' => 1000, 'false_positive_rate' => 0.01, 'coordination' => $mode,
        ]]);
        app()->instance(Task17FilterDefinition::class, new Task17FilterDefinition(['one'], ConsistencyContract::PreAddV1));
    }

    public function test_status_and_doctor_identify_invalid_configuration(): void
    {
        $this->configure(true);
        self::assertSame(0, Artisan::call('bloom:status', ['filter' => 'users.email']));
        $output = Artisan::output();
        self::assertStringContainsString('coordination=INVALID', $output);
        self::assertStringContainsString('invalid_configuration', $output);
        self::assertSame(1, Artisan::call('bloom:doctor'));
        self::assertStringContainsString('invalid_configuration', Artisan::output());
    }

    public function test_corrupt_control_preserves_coordination_diagnostics_and_failure_exit(): void
    {
        $this->configure();
        $control = $this->createMock(FilterControlStore::class);
        $control->method('read')->willThrowException(new FilterControlStateCorrupt('Malformed control.'));
        $lifecycle = $this->createMock(CoordinatedLifecycleStore::class);
        $lifecycle->method('read')->willThrowException(new FilterControlStateCorrupt('Malformed control.'));
        foreach (['claimOwnership', 'compareAndSwapControl', 'compareAndSwapSynchronization'] as $method) {
            $lifecycle->expects(self::never())->method($method);
        }
        app()->instance(FilterControlStore::class, $control);
        app()->instance(CoordinatedLifecycleStore::class, $lifecycle);

        self::assertSame(1, Artisan::call('bloom:status', ['filter' => 'users.email']));
        $output = Artisan::output();
        self::assertStringContainsString('coordination=INVALID', $output);
        self::assertStringContainsString('storage_corrupt', $output);
        self::assertStringContainsString('Malformed control.', $output);
        self::assertStringNotContainsString('active v', $output);
    }

    public function test_unavailable_coordination_service_preserves_the_original_failure(): void
    {
        $this->configure();
        app()->bind(CoordinationStatusReader::class, static fn () => throw new CoordinationStoreOperationFailed('Coordination offline.'));

        self::assertSame(1, Artisan::call('bloom:status', ['filter' => 'users.email']));
        $output = Artisan::output();
        self::assertStringContainsString('coordination=UNAVAILABLE', $output);
        self::assertStringContainsString('diagnostics_unavailable', $output);
        self::assertStringContainsString('Coordination offline.', $output);
        self::assertStringNotContainsString('active v', $output);
    }

    public function test_corrupt_generation_preserves_valid_coordination_and_prepared_lease(): void
    {
        $this->configure();
        Artisan::call('bloom:coordinate:adopt', ['filter' => 'users.email']);
        Artisan::call('bloom:rebuild', ['filter' => 'users.email']);
        $token = str_repeat('7', 32);
        $prepared = BloomGate::prepare('users.email', $token, ['two']);
        $inspector = $this->createMock(BloomGenerationInspector::class);
        $inspector->method('layout')->willThrowException(new BloomStorageCorrupt('Malformed generation.'));
        app()->instance(BloomGenerationInspector::class, $inspector);

        self::assertSame(1, Artisan::call('bloom:status', ['filter' => 'users.email', '--leases' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('coordination=ADOPTED', $output);
        self::assertStringContainsString($token, $output);
        self::assertStringContainsString('Malformed generation.', $output);
        self::assertStringNotContainsString('active v', $output);
        $store = app(WriterSynchronizationStore::class);
        self::assertSame(WriterLeaseState::Prepared, $store->readLease(FilterName::fromString('users.email'), $prepared->token())?->state());
        self::assertSame(1, $store->activeWriterCount(FilterName::fromString('users.email'), $prepared->lease()->epoch()));
    }
}
