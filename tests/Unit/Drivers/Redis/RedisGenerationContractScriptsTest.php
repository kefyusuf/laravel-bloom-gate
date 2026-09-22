<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Drivers\Redis\RedisGenerationContractScripts;

it('pins generation semantic metadata field names and status protocol', function (): void {
    expect(RedisGenerationContractScripts::META_NORMALIZATION_FINGERPRINT)
        ->toBe('normalization_fingerprint')
        ->and(RedisGenerationContractScripts::META_AUTHORITATIVE_SET_FINGERPRINT)
        ->toBe('authoritative_set_fingerprint')
        ->and(RedisGenerationContractScripts::META_CONSISTENCY_FINGERPRINT)
        ->toBe('consistency_fingerprint')
        ->and(RedisGenerationContractScripts::STATUS_OK)->toBe('100')
        ->and(RedisGenerationContractScripts::STATUS_NOT_PROVISIONED)->toBe('200')
        ->and(RedisGenerationContractScripts::STATUS_STORAGE_CORRUPT)->toBe('201')
        ->and(RedisGenerationContractScripts::STATUS_LAYOUT_MISMATCH)->toBe('202')
        ->and(RedisGenerationContractScripts::STATUS_CONTRACT_CONFLICT)->toBe('203');
});

it('reads canonical m3 layout and semantic fields without mutating generation storage', function (): void {
    $script = RedisGenerationContractScripts::read();

    expect($script)->toContain("redis.call('TYPE', KEYS[1]).ok")
        ->and($script)->toContain("redis.call('TYPE', KEYS[2]).ok")
        ->and($script)->toContain("'HMGET'")
        ->and($script)->toContain("'format'")
        ->and($script)->toContain("'bit_count'")
        ->and($script)->toContain("'hash_count'")
        ->and($script)->toContain("'probe_algorithm'")
        ->and($script)->toContain("'normalization_fingerprint'")
        ->and($script)->toContain("'authoritative_set_fingerprint'")
        ->and($script)->toContain("'consistency_fingerprint'")
        ->and($script)->not->toContain("'HSET'")
        ->and($script)->not->toContain("'SETBIT'")
        ->and($script)->not->toContain("'DEL'")
        ->and($script)->not->toContain("'EXPIRE'");
});

it('binds all semantic fingerprints in one hset only after layout and existing binding validation', function (): void {
    $script = RedisGenerationContractScripts::bind();

    $layoutValidation = strpos($script, 'if metadataMatches == false then');
    $bindingStateValidation = strpos($script, 'local semanticFieldCount = 0');
    $write = strpos($script, "redis.call(\n        'HSET'");

    if (
        $layoutValidation === false
        || $bindingStateValidation === false
        || $write === false
    ) {
        throw new RuntimeException('Expected Redis semantic bind validation/write markers.');
    }

    expect($layoutValidation)->toBeLessThan($bindingStateValidation)
        ->and($bindingStateValidation)->toBeLessThan($write)
        ->and($script)->toContain("'normalization_fingerprint', ARGV[5]")
        ->and($script)->toContain("'authoritative_set_fingerprint', ARGV[6]")
        ->and($script)->toContain("'consistency_fingerprint', ARGV[7]")
        ->and($script)->not->toContain("'SETBIT'")
        ->and($script)->not->toContain("'DEL'")
        ->and($script)->not->toContain("'EXPIRE'");
});
