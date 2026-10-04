<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Kefyusuf\BloomGate\Application\CoordinatedWriter;
use Kefyusuf\BloomGate\Application\CoordinationStatusReader;
use Kefyusuf\BloomGate\Application\OnlineRebuildCoordinator;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleStore;
use Kefyusuf\BloomGate\Contracts\Exception\InvalidConfiguration;
use Kefyusuf\BloomGate\Contracts\RuntimeCoordinationRequirement;
use Kefyusuf\BloomGate\Contracts\WriterLeaseInspector;
use Kefyusuf\BloomGate\Contracts\WriterSynchronizationStore;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationPhase;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;
use Kefyusuf\BloomGate\Laravel\Console\LeaseResolveCommand;
use Kefyusuf\BloomGate\Laravel\Facades\BloomGate;
use Kefyusuf\BloomGate\Tests\Support\Application\DrainObservingWriterSynchronizationStore;
use Kefyusuf\BloomGate\Tests\Support\Laravel\Task17FilterDefinition;

function wu11Configure(bool $coordinated = true, mixed $mode = 'coordinated-v1'): void
{
    config()->set('bloom-gate.default', 'memory');
    config()->set('bloom-gate.filters', ['users.email' => [
        'enabled' => true, 'definition' => Task17FilterDefinition::class,
        'capacity' => 1000, 'false_positive_rate' => 0.01,
        'coordination' => $coordinated ? $mode : null,
    ]]);
    app()->instance(Task17FilterDefinition::class, new Task17FilterDefinition(['one@example.test'], ConsistencyContract::PreAddV1));
}

it('wires coordination ports and application services over one memory domain', function (): void {
    wu11Configure();
    $name = FilterName::fromString('users.email');
    expect(app(RuntimeCoordinationRequirement::class)->requiresCoordinatedV1($name))->toBeTrue();
    foreach ([WriterSynchronizationStore::class, WriterLeaseInspector::class, CoordinatedWriter::class, OnlineRebuildCoordinator::class, CoordinationStatusReader::class] as $service) {
        expect(app($service))->toBeObject();
    }
    expect(app(WriterLeaseInspector::class))->toBe(app(WriterSynchronizationStore::class));
});

it('adopts and completes a rebuild through thin commands and exposes writer preparation', function (): void {
    wu11Configure();
    expect(Artisan::call('bloom:coordinate:adopt', ['filter' => 'users.email']))->toBe(0)
        ->and(Artisan::call('bloom:rebuild', ['filter' => 'users.email', '--wait' => '0']))->toBe(0);
    expect(Artisan::output())->toContain('Completed');
    $token = str_repeat('a', 32);
    $prepared = BloomGate::prepare('users.email', $token, ['two@example.test']);
    expect($prepared->lease()->state())->toBe(WriterLeaseState::Prepared);
    expect(Artisan::call('bloom:status', ['filter' => 'users.email', '--leases' => true]))->toBe(0);
    expect(Artisan::output())->toContain('coordination=ADOPTED')->toContain('phase=Steady')->toContain($token)->toContain('state=Prepared');
    expect(Artisan::call('bloom:lease:resolve', ['filter' => 'users.email', 'token' => $token, '--outcome' => 'committed']))->toBe(0);
    expect(app(WriterSynchronizationStore::class)->readLease(FilterName::fromString('users.email'), WriterLeaseToken::fromString($token))?->state())->toBe(WriterLeaseState::Released);
    expect(Artisan::call('bloom:doctor'))->toBe(0);
    expect(Artisan::output())->toContain('PASS filter.users.email.coordination');
});

it('requires a quiescent handoff for brownfield adoption and fences legacy mutation', function (): void {
    wu11Configure(false);
    expect(Artisan::call('bloom:build', ['filter' => 'users.email']))->toBe(0)
        ->and(Artisan::call('bloom:activate', ['filter' => 'users.email', '--quiescent' => true]))->toBe(0);
    wu11Configure();
    expect(Artisan::call('bloom:coordinate:adopt', ['filter' => 'users.email']))->toBe(1)
        ->and(Artisan::call('bloom:coordinate:adopt', ['filter' => 'users.email', '--quiescent' => true]))->toBe(0)
        ->and(Artisan::call('bloom:build', ['filter' => 'users.email']))->toBe(1);
    expect(Artisan::output())->toContain('fenced');
});

it('returns at a durable drain and resumes the same rebuild after lease resolution', function (): void {
    wu11Configure();
    $name = FilterName::fromString('users.email');
    expect(Artisan::call('bloom:coordinate:adopt', ['filter' => 'users.email']))->toBe(0);
    $token = WriterLeaseToken::fromString(str_repeat('b', 32));
    app(WriterSynchronizationStore::class)->acquire($name, $token);
    expect(Artisan::call('bloom:rebuild', ['filter' => 'users.email', '--wait' => '0']))->toBe(0);
    expect(Artisan::output())->toContain('Blocked');
    $before = app(CoordinatedLifecycleStore::class)->read($name);
    expect($before->synchronization()?->phase())->toBe(SynchronizationPhase::DrainingPreReconcile);
    expect(Artisan::call('bloom:rebuild', ['filter' => 'users.email', '--wait' => '0']))->toBe(0);
    expect(app(CoordinatedLifecycleStore::class)->read($name))->toEqual($before);
    expect(Artisan::call('bloom:lease:resolve', ['filter' => 'users.email', 'token' => $token->value(), '--outcome' => 'aborted']))->toBe(0)
        ->and(Artisan::call('bloom:rebuild', ['filter' => 'users.email']))->toBe(0);
    expect(Artisan::output())->toContain('Completed');
});

it('resumes durable abort intent without expiring the blocking writer', function (): void {
    wu11Configure();
    $name = FilterName::fromString('users.email');
    expect(Artisan::call('bloom:coordinate:adopt', ['filter' => 'users.email']))->toBe(0);
    $token = WriterLeaseToken::fromString(str_repeat('c', 32));
    app(WriterSynchronizationStore::class)->acquire($name, $token);
    Artisan::call('bloom:rebuild', ['filter' => 'users.email']);
    expect(Artisan::call('bloom:rebuild:abort', ['filter' => 'users.email']))->toBe(0);
    expect(app(CoordinatedLifecycleStore::class)->read($name)->synchronization()?->phase())->toBe(SynchronizationPhase::AbortRequested);
    expect(app(WriterSynchronizationStore::class)->readLease($name, $token)?->state())->toBe(WriterLeaseState::Acquired);
    Artisan::call('bloom:lease:resolve', ['filter' => 'users.email', 'token' => $token->value(), '--outcome' => 'aborted']);
    expect(Artisan::call('bloom:rebuild:abort', ['filter' => 'users.email']))->toBe(0);
    expect(Artisan::output())->toContain('Completed');
    expect(app(CoordinatedLifecycleStore::class)->read($name)->control()?->candidateVersion())->toBeNull();
});

it('rejects invalid wait and unknown outcomes without creating ownership', function (): void {
    wu11Configure();
    foreach (['-1', 'NaN', '0.1'] as $wait) {
        expect(Artisan::call('bloom:rebuild', ['filter' => 'users.email', '--wait' => $wait]))->toBe(1);
    }
    expect(Artisan::call('bloom:lease:resolve', ['filter' => 'users.email', 'token' => str_repeat('d', 32), '--outcome' => 'unknown']))->toBe(1);
    expect(app(CoordinatedLifecycleStore::class)->read(FilterName::fromString('users.email'))->ownershipClaimed())->toBeFalse();
    expect(app(LeaseResolveCommand::class)->getDefinition()->hasOption('force'))->toBeFalse();
});

it('continues the same workflow when the drain clears inside the wait budget', function (): void {
    wu11Configure();
    $name = FilterName::fromString('users.email');
    Artisan::call('bloom:coordinate:adopt', ['filter' => 'users.email']);
    $store = app(WriterSynchronizationStore::class);
    $token = WriterLeaseToken::fromString(str_repeat('3', 32));
    $store->acquire($name, $token);
    $observer = new DrainObservingWriterSynchronizationStore($store, $name, $token);
    app()->instance(WriterSynchronizationStore::class, $observer);
    expect(Artisan::call('bloom:rebuild', ['filter' => 'users.email', '--wait' => '1']))->toBe(0);
    expect(Artisan::output())->toContain('Completed');
    expect(app(CoordinatedLifecycleStore::class)->read($name)->control()?->activeVersion()?->value())->toBe(1);
    expect($store->readLease($name, $token)?->state())->toBe(WriterLeaseState::Released);
});

it('keeps leases and durable state unchanged when a positive wait budget expires', function (): void {
    wu11Configure();
    $name = FilterName::fromString('users.email');
    Artisan::call('bloom:coordinate:adopt', ['filter' => 'users.email']);
    $token = WriterLeaseToken::fromString(str_repeat('e', 32));
    app(WriterSynchronizationStore::class)->acquire($name, $token);
    Artisan::call('bloom:rebuild', ['filter' => 'users.email']);
    $before = app(CoordinatedLifecycleStore::class)->read($name);
    expect(Artisan::call('bloom:rebuild', ['filter' => 'users.email', '--wait' => '1']))->toBe(0);
    expect(Artisan::output())->toContain('Blocked');
    expect(app(CoordinatedLifecycleStore::class)->read($name))->toEqual($before);
    expect(app(WriterSynchronizationStore::class)->readLease($name, $token)?->state())->toBe(WriterLeaseState::Acquired);
    expect(app(WriterSynchronizationStore::class)->activeWriterCount($name, SynchronizationEpoch::fromInt(1)))->toBe(1);
});

it('rejects invalid coordination configuration and defaults missing mode to legacy', function (): void {
    wu11Configure(false);
    $name = FilterName::fromString('users.email');
    $runtime = app(RuntimeCoordinationRequirement::class);
    expect($runtime->requiresCoordinatedV1($name))->toBeFalse();
    foreach ([true, 'automatic', [], 1] as $invalid) {
        wu11Configure(mode: $invalid);
        expect(fn () => $runtime->requiresCoordinatedV1($name))->toThrow(InvalidConfiguration::class);
    }
});

it('does not expose released tombstones and does not enumerate leases by default', function (): void {
    wu11Configure();
    Artisan::call('bloom:coordinate:adopt', ['filter' => 'users.email']);
    $name = FilterName::fromString('users.email');
    $token = WriterLeaseToken::fromString(str_repeat('f', 32));
    app(WriterSynchronizationStore::class)->acquire($name, $token);
    Artisan::call('bloom:status', ['filter' => 'users.email']);
    expect(Artisan::output())->not->toContain($token->value());
    app(WriterSynchronizationStore::class)->release($name, $token);
    Artisan::call('bloom:status', ['filter' => 'users.email', '--leases' => true]);
    expect(Artisan::output())->not->toContain($token->value());
});

it('requires durable prepared evidence for a committed lease and rejects missing tokens', function (): void {
    wu11Configure();
    Artisan::call('bloom:coordinate:adopt', ['filter' => 'users.email']);
    $name = FilterName::fromString('users.email');
    $token = WriterLeaseToken::fromString(str_repeat('1', 32));
    $store = app(WriterSynchronizationStore::class);
    $store->acquire($name, $token);
    expect(Artisan::call('bloom:lease:resolve', ['filter' => 'users.email', 'token' => $token->value(), '--outcome' => 'committed']))->toBe(1);
    expect($store->readLease($name, $token)?->state())->toBe(WriterLeaseState::Acquired);
    expect(Artisan::call('bloom:lease:resolve', ['filter' => 'users.email', 'token' => str_repeat('2', 32), '--outcome' => 'aborted']))->toBe(1);
});
