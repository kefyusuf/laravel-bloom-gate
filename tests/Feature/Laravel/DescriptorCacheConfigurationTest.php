<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\Exception\InvalidConfiguration;
use Kefyusuf\BloomGate\Contracts\QuerySafetyDescriptorCache;

it('rejects an unsupported descriptor cache driver', function (): void {
    config()->set('bloom-gate.default', 'redis');
    config()->set('bloom-gate.query.descriptor_cache.driver', 'remote');

    expect(fn () => app(QuerySafetyDescriptorCache::class))->toThrow(InvalidConfiguration::class);
});

it('requires an explicit application namespace for APCu descriptors', function (): void {
    config()->set('bloom-gate.default', 'redis');
    config()->set('bloom-gate.query.descriptor_cache', [
        'driver' => 'apcu',
        'namespace' => '',
        'ttl' => 60,
    ]);

    expect(fn () => app(QuerySafetyDescriptorCache::class))->toThrow(InvalidConfiguration::class);
});

it('rejects invalid descriptor expiration settings', function (mixed $ttl): void {
    config()->set('bloom-gate.default', 'redis');
    config()->set('bloom-gate.query.descriptor_cache', [
        'driver' => 'apcu',
        'namespace' => 'test-application',
        'ttl' => $ttl,
    ]);

    expect(fn () => app(QuerySafetyDescriptorCache::class))->toThrow(InvalidConfiguration::class);
})->with([0, -1, '60', null, 86401]);
