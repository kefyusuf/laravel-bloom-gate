<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Drivers\Redis\RedisBloomScripts;

it('pins the managed bitmap marker and invalid-batch status', function (): void {
    expect(RedisBloomScripts::META_MANAGED_BITMAP_WRITTEN)
        ->toBe('managed_bitmap_written')
        ->and(RedisBloomScripts::STATUS_INVALID_BATCH)->toBe(204);
});

it('validates the entire bulk batch before marker and bitmap mutation', function (): void {
    $script = RedisBloomScripts::addMany();

    $layoutValidation = strpos($script, 'if metadataMatches == false then');
    $batchValidation = strpos($script, 'if positionArgumentCount < hashCount');
    $positionValidation = strpos($script, 'if not isCanonicalBitPosition');
    $markerWrite = strpos(
        $script,
        "redis.call('HSET', KEYS[1], 'managed_bitmap_written', '1')",
    );
    $bitWrite = strpos($script, "redis.call('SETBIT', KEYS[2]");

    if (
        $layoutValidation === false
        || $batchValidation === false
        || $positionValidation === false
        || $markerWrite === false
        || $bitWrite === false
    ) {
        throw new RuntimeException('Expected bulk validation/marker/mutation markers.');
    }

    expect($layoutValidation)->toBeLessThan($batchValidation)
        ->and($batchValidation)->toBeLessThan($positionValidation)
        ->and($positionValidation)->toBeLessThan($markerWrite)
        ->and($markerWrite)->toBeLessThan($bitWrite);
});

it('requires marker to be absent or canonical one before bulk mutation', function (): void {
    $script = RedisBloomScripts::addMany();

    expect($script)->toContain("'managed_bitmap_written'")
        ->and($script)->toContain(
            "if managedBitmapWritten ~= false and managedBitmapWritten ~= '1' then",
        )
        ->and($script)->toContain((string) RedisBloomScripts::STATUS_STORAGE_CORRUPT);
});
