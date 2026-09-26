<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\BypassReason;

it('provides the built-in bypass reasons', function (BypassReason $reason, string $code): void {
    expect($reason->code())->toBe($code);
})->with([
    'optimization disabled' => [BypassReason::optimizationDisabled(), 'optimization_disabled'],
    'lifecycle not active' => [BypassReason::lifecycleNotActive(), 'lifecycle_not_active'],
    'health not healthy' => [BypassReason::healthNotHealthy(), 'health_not_healthy'],
    'active version unavailable' => [BypassReason::activeVersionUnavailable(), 'active_version_unavailable'],
    'backend unavailable' => [BypassReason::backendUnavailable(), 'backend_unavailable'],
    'backend profile unasserted' => [BypassReason::backendProfileUnasserted(), 'backend_profile_unasserted'],
    'operation failed' => [BypassReason::operationFailed(), 'operation_failed'],
]);

it('accepts custom stable reason codes', function (string $code): void {
    expect(BypassReason::fromCode($code)->code())->toBe($code);
})->with([
    'single character' => 'a',
    'dot namespace' => 'redis.connection_unavailable',
    'hyphen' => 'custom-reason',
    'maximum length' => str_repeat('a', 64),
]);

it('rejects invalid reason codes', function (string $code): void {
    BypassReason::fromCode($code);
})->with([
    'empty' => '',
    'too long' => str_repeat('a', 65),
    'starts with digit' => '1reason',
    'uppercase' => 'BackendUnavailable',
    'space' => 'backend unavailable',
    'slash' => 'backend/unavailable',
    'colon' => 'backend:unavailable',
    'opening brace' => 'backend{unavailable',
    'unicode' => 'hata_çıkışı',
])->throws(InvalidArgumentException::class);

it('compares bypass reasons by code', function (): void {
    expect(BypassReason::fromCode('backend_unavailable')
        ->equals(BypassReason::backendUnavailable()))->toBeTrue()
        ->and(BypassReason::backendUnavailable()
            ->equals(BypassReason::operationFailed()))->toBeFalse();
});
