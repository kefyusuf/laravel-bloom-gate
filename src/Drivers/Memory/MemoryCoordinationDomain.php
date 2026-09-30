<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Memory;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\CoordinatedLifecycleSnapshot;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationFenced;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationStateCorrupt;
use Kefyusuf\BloomGate\Contracts\Exception\CoordinationWriteConflict;
use Kefyusuf\BloomGate\Contracts\Exception\FilterControlWriteConflict;
use Kefyusuf\BloomGate\Contracts\Exception\UnknownWriterLease;
use Kefyusuf\BloomGate\Contracts\Exception\WriterLeaseReleased;
use Kefyusuf\BloomGate\Core\FilterControlState;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\SynchronizationEpoch;
use Kefyusuf\BloomGate\Core\SynchronizationRevision;
use Kefyusuf\BloomGate\Core\SynchronizationState;
use Kefyusuf\BloomGate\Core\WriterLease;
use Kefyusuf\BloomGate\Core\WriterLeaseState;
use Kefyusuf\BloomGate\Core\WriterLeaseToken;

final class MemoryCoordinationDomain
{
    /**
     * @var array<string, mixed>
     */
    private array $controls = [];

    /**
     * @var array<string, mixed>
     */
    private array $owners = [];

    /**
     * @var array<string, mixed>
     */
    private array $synchronizations = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $leases = [];

    /**
     * @var array<string, array<int, mixed>>
     */
    private array $counts = [];

    public function readControl(FilterName $name): ?FilterControlState
    {
        return $this->control($name->value());
    }

    public function compareAndSwapLegacyControl(
        FilterName $name,
        FilterControlState $next,
        ?FilterStateRevision $expectedRevision,
    ): void {
        $this->assertControlTargetMatches($name, $next);

        $key = $name->value();
        $owner = $this->ownershipClaimed($key);
        $synchronization = $this->synchronization($key);

        if ($owner === false && $synchronization !== null) {
            throw new CoordinationStateCorrupt(
                'Synchronization state cannot exist without coordinated ownership.',
            );
        }

        if ($owner) {
            throw new CoordinationFenced(
                'Ordinary control mutation is fenced after coordinated ownership begins.',
            );
        }

        $current = $this->control($key);

        if ($current === null) {
            if ($expectedRevision !== null) {
                throw new FilterControlWriteConflict(
                    'Control state does not exist at the expected revision.',
                );
            }

            if ($next->revision()->value() !== 1) {
                throw new InvalidArgumentException(
                    'Initial control state revision must be 1.',
                );
            }

            $this->controls[$key] = $next;

            return;
        }

        if (
            $expectedRevision === null
            || $current->revision()->equals($expectedRevision) === false
        ) {
            throw new FilterControlWriteConflict(
                'Control state revision does not match the expected revision.',
            );
        }

        if ($next->revision()->equals($current->revision()->next()) === false) {
            throw new InvalidArgumentException(
                'Updated control state revision must advance exactly once.',
            );
        }

        $this->controls[$key] = $next;
    }

    public function readLifecycle(FilterName $name): CoordinatedLifecycleSnapshot
    {
        $key = $name->value();
        $owner = $this->ownershipClaimed($key);
        $synchronization = $this->synchronization($key);

        if ($owner === false && $synchronization !== null) {
            throw new CoordinationStateCorrupt(
                'Synchronization state cannot exist without coordinated ownership.',
            );
        }

        return new CoordinatedLifecycleSnapshot(
            ownershipClaimed: $owner,
            control: $this->control($key),
            synchronization: $synchronization,
        );
    }

    public function claimOwnership(
        FilterName $name,
        ?FilterStateRevision $expectedControlRevision,
    ): CoordinatedLifecycleSnapshot {
        $key = $name->value();
        $owner = $this->ownershipClaimed($key);
        $synchronization = $this->synchronization($key);

        if ($owner === false && $synchronization !== null) {
            throw new CoordinationStateCorrupt(
                'Synchronization state cannot exist without coordinated ownership.',
            );
        }

        if ($owner) {
            throw new CoordinationFenced(
                'Coordinated ownership is already claimed for this filter.',
            );
        }

        $this->assertExpectedControlRevision(
            $this->control($key),
            $expectedControlRevision,
        );

        $this->owners[$key] = true;

        return $this->readLifecycle($name);
    }

    public function compareAndSwapControl(
        FilterName $name,
        FilterControlState $next,
        ?FilterStateRevision $expectedControlRevision,
        SynchronizationRevision $expectedSyncRevision,
    ): CoordinatedLifecycleSnapshot {
        $this->assertControlTargetMatches($name, $next);

        $key = $name->value();
        $synchronization = $this->requireCurrentSynchronization($key);

        if ($synchronization->revision()->equals($expectedSyncRevision) === false) {
            throw new CoordinationWriteConflict(
                'Synchronization revision does not match the expected revision.',
            );
        }

        $current = $this->control($key);
        $this->assertExpectedControlRevision(
            $current,
            $expectedControlRevision,
        );
        $this->assertNextControlRevision($current, $next);

        $this->controls[$key] = $next;

        return $this->readLifecycle($name);
    }

    public function compareAndSwapSynchronization(
        FilterName $name,
        SynchronizationState $next,
        ?SynchronizationRevision $expectedSyncRevision,
        ?FilterStateRevision $expectedControlRevision,
    ): CoordinatedLifecycleSnapshot {
        $key = $name->value();
        $this->requireOwnership($key);
        $this->assertExpectedControlRevision(
            $this->control($key),
            $expectedControlRevision,
        );

        $current = $this->synchronization($key);

        if ($current === null) {
            if ($expectedSyncRevision !== null) {
                throw new CoordinationWriteConflict(
                    'Synchronization state does not exist at the expected revision.',
                );
            }

            if ($next->revision()->value() !== 1) {
                throw new InvalidArgumentException(
                    'Initial synchronization revision must be 1.',
                );
            }
        } else {
            if (
                $expectedSyncRevision === null
                || $current->revision()->equals($expectedSyncRevision) === false
            ) {
                throw new CoordinationWriteConflict(
                    'Synchronization revision does not match the expected revision.',
                );
            }

            if ($next->revision()->equals($current->revision()->next()) === false) {
                throw new InvalidArgumentException(
                    'Updated synchronization revision must advance exactly once.',
                );
            }
        }

        $this->synchronizations[$key] = $next;

        return $this->readLifecycle($name);
    }

    public function readSynchronization(FilterName $name): ?SynchronizationState
    {
        $key = $name->value();
        $owner = $this->ownershipClaimed($key);
        $synchronization = $this->synchronization($key);

        if ($owner === false && $synchronization !== null) {
            throw new CoordinationStateCorrupt(
                'Synchronization state cannot exist without coordinated ownership.',
            );
        }

        if ($owner && $synchronization === null) {
            throw new CoordinationFenced(
                'Synchronization state is unavailable while coordinated ownership is pending.',
            );
        }

        return $synchronization;
    }

    public function readLease(
        FilterName $name,
        WriterLeaseToken $token,
    ): ?WriterLease {
        return $this->lease($name->value(), $token->value());
    }

    public function acquire(
        FilterName $name,
        WriterLeaseToken $token,
    ): WriterLease {
        $key = $name->value();
        $synchronization = $this->requireCurrentSynchronization($key);
        $tokenKey = $token->value();
        $existing = $this->lease($key, $tokenKey);

        if ($existing !== null) {
            if ($existing->state() === WriterLeaseState::Released) {
                throw new WriterLeaseReleased(
                    'Released writer lease tokens are terminal and cannot be rebound.',
                );
            }

            return $existing;
        }

        $epoch = $synchronization->currentEpoch();
        $count = $this->activeWriterCountByKey($key, $epoch);

        if ($count === PHP_INT_MAX) {
            throw new CoordinationStateCorrupt(
                'Active writer count cannot advance beyond PHP_INT_MAX.',
            );
        }

        $lease = new WriterLease(
            token: $token,
            state: WriterLeaseState::Acquired,
            epoch: $epoch,
            targets: $synchronization->currentTargets(),
        );

        $this->leases[$key][$tokenKey] = $lease;
        $this->counts[$key][$epoch->value()] = $count + 1;

        return $lease;
    }

    public function markPrepared(
        FilterName $name,
        WriterLeaseToken $token,
    ): WriterLease {
        $key = $name->value();
        $tokenKey = $token->value();
        $lease = $this->lease($key, $tokenKey);

        if ($lease === null) {
            throw new UnknownWriterLease(
                'Writer lease token is unknown for this filter.',
            );
        }

        if ($lease->state() === WriterLeaseState::Released) {
            throw new WriterLeaseReleased(
                'Released writer lease tokens cannot regain write authority.',
            );
        }

        $this->requireCurrentSynchronization($key);

        if ($this->activeWriterCountByKey($key, $lease->epoch()) <= 0) {
            throw new CoordinationStateCorrupt(
                'Prepared writer lease requires a positive active count for its original epoch.',
            );
        }

        if ($lease->state() === WriterLeaseState::Prepared) {
            return $lease;
        }

        $prepared = new WriterLease(
            token: $lease->token(),
            state: WriterLeaseState::Prepared,
            epoch: $lease->epoch(),
            targets: $lease->targets(),
        );

        $this->leases[$key][$tokenKey] = $prepared;

        return $prepared;
    }

    public function release(
        FilterName $name,
        WriterLeaseToken $token,
    ): WriterLease {
        $key = $name->value();
        $tokenKey = $token->value();
        $lease = $this->lease($key, $tokenKey);

        if ($lease === null) {
            throw new UnknownWriterLease(
                'Writer lease token is unknown for this filter.',
            );
        }

        if ($lease->state() === WriterLeaseState::Released) {
            return $lease;
        }

        $count = $this->activeWriterCountByKey($key, $lease->epoch());

        if ($count <= 0) {
            throw new CoordinationStateCorrupt(
                'Writer lease release would underflow the active writer count.',
            );
        }

        $released = new WriterLease(
            token: $lease->token(),
            state: WriterLeaseState::Released,
            epoch: $lease->epoch(),
            targets: $lease->targets(),
        );

        $this->counts[$key][$lease->epoch()->value()] = $count - 1;
        $this->leases[$key][$tokenKey] = $released;

        return $released;
    }

    public function activeWriterCount(
        FilterName $name,
        SynchronizationEpoch $epoch,
    ): int {
        return $this->activeWriterCountByKey(
            $name->value(),
            $epoch,
        );
    }

    private function control(string $key): ?FilterControlState
    {
        if (array_key_exists($key, $this->controls) === false) {
            return null;
        }

        $control = $this->controls[$key];

        if (! $control instanceof FilterControlState) {
            throw new CoordinationStateCorrupt(
                'Memory control state contains an invalid value.',
            );
        }

        return $control;
    }

    private function synchronization(string $key): ?SynchronizationState
    {
        if (array_key_exists($key, $this->synchronizations) === false) {
            return null;
        }

        $synchronization = $this->synchronizations[$key];

        if (! $synchronization instanceof SynchronizationState) {
            throw new CoordinationStateCorrupt(
                'Memory synchronization state contains an invalid value.',
            );
        }

        return $synchronization;
    }

    private function ownershipClaimed(string $key): bool
    {
        if (array_key_exists($key, $this->owners) === false) {
            return false;
        }

        if ($this->owners[$key] !== true) {
            throw new CoordinationStateCorrupt(
                'Memory coordinated ownership marker is invalid.',
            );
        }

        return true;
    }

    private function requireOwnership(string $key): void
    {
        if ($this->ownershipClaimed($key)) {
            return;
        }

        if ($this->synchronization($key) !== null) {
            throw new CoordinationStateCorrupt(
                'Synchronization state cannot exist without coordinated ownership.',
            );
        }

        throw new CoordinationFenced(
            'Coordinated ownership has not been claimed for this filter.',
        );
    }

    private function requireCurrentSynchronization(
        string $key,
    ): SynchronizationState {
        $this->requireOwnership($key);
        $synchronization = $this->synchronization($key);

        if ($synchronization === null) {
            throw new CoordinationFenced(
                'Current synchronization state is required for writer or coordinated mutation.',
            );
        }

        return $synchronization;
    }

    private function assertExpectedControlRevision(
        ?FilterControlState $current,
        ?FilterStateRevision $expected,
    ): void {
        if ($current === null) {
            if ($expected !== null) {
                throw new CoordinationWriteConflict(
                    'Control state does not exist at the expected revision.',
                );
            }

            return;
        }

        if (
            $expected === null
            || $current->revision()->equals($expected) === false
        ) {
            throw new CoordinationWriteConflict(
                'Control state revision does not match the expected revision.',
            );
        }
    }

    private function assertNextControlRevision(
        ?FilterControlState $current,
        FilterControlState $next,
    ): void {
        if ($current === null) {
            if ($next->revision()->value() !== 1) {
                throw new InvalidArgumentException(
                    'Initial control state revision must be 1.',
                );
            }

            return;
        }

        if ($next->revision()->equals($current->revision()->next()) === false) {
            throw new InvalidArgumentException(
                'Updated control state revision must advance exactly once.',
            );
        }
    }

    private function assertControlTargetMatches(
        FilterName $name,
        FilterControlState $next,
    ): void {
        if ($name->equals($next->filterName())) {
            return;
        }

        throw new InvalidArgumentException(
            'Control state target filter name must match the snapshot filter name.',
        );
    }

    private function lease(
        string $filterKey,
        string $tokenKey,
    ): ?WriterLease {
        if (array_key_exists($tokenKey, $this->leases[$filterKey] ?? []) === false) {
            return null;
        }

        $lease = $this->leases[$filterKey][$tokenKey];

        if (! $lease instanceof WriterLease) {
            throw new CoordinationStateCorrupt(
                'Memory writer lease registry contains an invalid value.',
            );
        }

        if ($lease->token()->value() !== $tokenKey) {
            throw new CoordinationStateCorrupt(
                'Memory writer lease token binding is inconsistent.',
            );
        }

        return $lease;
    }

    private function activeWriterCountByKey(
        string $filterKey,
        SynchronizationEpoch $epoch,
    ): int {
        $epochKey = $epoch->value();
        $count = $this->counts[$filterKey][$epochKey] ?? 0;

        if (! is_int($count) || $count < 0) {
            throw new CoordinationStateCorrupt(
                'Memory active writer count is invalid.',
            );
        }

        return $count;
    }
}
