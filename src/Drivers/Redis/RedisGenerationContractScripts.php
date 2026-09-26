<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Redis;

final class RedisGenerationContractScripts
{
    public const string STATUS_OK = '100';

    public const string STATUS_NOT_PROVISIONED = '200';

    public const string STATUS_STORAGE_CORRUPT = '201';

    public const string STATUS_LAYOUT_MISMATCH = '202';

    public const string STATUS_CONTRACT_CONFLICT = '203';

    public const string META_NORMALIZATION_FINGERPRINT = 'normalization_fingerprint';

    public const string META_AUTHORITATIVE_SET_FINGERPRINT = 'authoritative_set_fingerprint';

    public const string META_CONSISTENCY_FINGERPRINT = 'consistency_fingerprint';

    public static function read(): string
    {
        return self::hydrate(<<<'LUA'
local metaType = redis.call('TYPE', KEYS[1]).ok
local bitmapType = redis.call('TYPE', KEYS[2]).ok

if metaType == 'none' then
    if bitmapType ~= 'none' then
        return {__STORAGE_CORRUPT__}
    end

    return {__NOT_PROVISIONED__}
end

if metaType ~= 'hash' then
    return {__STORAGE_CORRUPT__}
end

if bitmapType ~= 'none' and bitmapType ~= 'string' then
    return {__STORAGE_CORRUPT__}
end

local metadata = redis.call(
    'HMGET',
    KEYS[1],
    'format',
    'bit_count',
    'hash_count',
    'probe_algorithm',
    'normalization_fingerprint',
    'authoritative_set_fingerprint',
    'consistency_fingerprint'
)

local function isCanonicalPositiveInteger(value, maximum)
    if type(value) ~= 'string' then
        return false
    end

    if string.match(value, '^[1-9][0-9]*$') == nil then
        return false
    end

    local numeric = tonumber(value)

    return numeric ~= nil and numeric >= 1 and numeric <= maximum
end

local function isCanonicalFingerprint(value)
    return type(value) == 'string'
        and string.match(value, '^sha256:[0-9a-f]+$') ~= nil
        and string.len(value) == 71
end

if metadata[1] ~= '__FORMAT__' then
    return {__STORAGE_CORRUPT__}
end

if not isCanonicalPositiveInteger(metadata[2], 2147483647) then
    return {__STORAGE_CORRUPT__}
end

if not isCanonicalPositiveInteger(metadata[3], 64) then
    return {__STORAGE_CORRUPT__}
end

if tonumber(metadata[3]) > tonumber(metadata[2]) then
    return {__STORAGE_CORRUPT__}
end

if type(metadata[4]) ~= 'string' or metadata[4] == '' then
    return {__STORAGE_CORRUPT__}
end

local semanticFieldCount = 0

for index = 5, 7 do
    if metadata[index] ~= false then
        semanticFieldCount = semanticFieldCount + 1
    end
end

if semanticFieldCount == 0 then
    return {
        __OK__,
        metadata[1],
        metadata[2],
        metadata[3],
        metadata[4],
        '',
        '',
        ''
    }
end

if semanticFieldCount ~= 3 then
    return {__STORAGE_CORRUPT__}
end

if not isCanonicalFingerprint(metadata[5])
    or not isCanonicalFingerprint(metadata[6])
    or not isCanonicalFingerprint(metadata[7])
then
    return {__STORAGE_CORRUPT__}
end

return {
    __OK__,
    metadata[1],
    metadata[2],
    metadata[3],
    metadata[4],
    metadata[5],
    metadata[6],
    metadata[7]
}
LUA);
    }

    public static function bind(): string
    {
        return self::hydrate(<<<'LUA'
local metaType = redis.call('TYPE', KEYS[1]).ok
local bitmapType = redis.call('TYPE', KEYS[2]).ok

if metaType == 'none' then
    if bitmapType ~= 'none' then
        return {__STORAGE_CORRUPT__}
    end

    return {__NOT_PROVISIONED__}
end

if metaType ~= 'hash' then
    return {__STORAGE_CORRUPT__}
end

if bitmapType ~= 'none' and bitmapType ~= 'string' then
    return {__STORAGE_CORRUPT__}
end

local metadata = redis.call(
    'HMGET',
    KEYS[1],
    'format',
    'bit_count',
    'hash_count',
    'probe_algorithm',
    'normalization_fingerprint',
    'authoritative_set_fingerprint',
    'consistency_fingerprint'
)

local function isCanonicalPositiveInteger(value, maximum)
    if type(value) ~= 'string' then
        return false
    end

    if string.match(value, '^[1-9][0-9]*$') == nil then
        return false
    end

    local numeric = tonumber(value)

    return numeric ~= nil and numeric >= 1 and numeric <= maximum
end

local function isCanonicalFingerprint(value)
    return type(value) == 'string'
        and string.match(value, '^sha256:[0-9a-f]+$') ~= nil
        and string.len(value) == 71
end

if metadata[1] ~= '__FORMAT__' then
    return {__STORAGE_CORRUPT__}
end

if not isCanonicalPositiveInteger(metadata[2], 2147483647) then
    return {__STORAGE_CORRUPT__}
end

if not isCanonicalPositiveInteger(metadata[3], 64) then
    return {__STORAGE_CORRUPT__}
end

if tonumber(metadata[3]) > tonumber(metadata[2]) then
    return {__STORAGE_CORRUPT__}
end

if type(metadata[4]) ~= 'string' or metadata[4] == '' then
    return {__STORAGE_CORRUPT__}
end

local metadataMatches =
    metadata[1] == ARGV[1]
    and metadata[2] == ARGV[2]
    and metadata[3] == ARGV[3]
    and metadata[4] == ARGV[4]

if metadataMatches == false then
    return {__LAYOUT_MISMATCH__}
end

if not isCanonicalFingerprint(ARGV[5])
    or not isCanonicalFingerprint(ARGV[6])
    or not isCanonicalFingerprint(ARGV[7])
then
    return {__STORAGE_CORRUPT__}
end

local semanticFieldCount = 0

for index = 5, 7 do
    if metadata[index] ~= false then
        semanticFieldCount = semanticFieldCount + 1
    end
end

if semanticFieldCount == 0 then
    redis.call(
        'HSET',
        KEYS[1],
        'normalization_fingerprint', ARGV[5],
        'authoritative_set_fingerprint', ARGV[6],
        'consistency_fingerprint', ARGV[7]
    )

    return {__OK__}
end

if semanticFieldCount ~= 3 then
    return {__STORAGE_CORRUPT__}
end

if not isCanonicalFingerprint(metadata[5])
    or not isCanonicalFingerprint(metadata[6])
    or not isCanonicalFingerprint(metadata[7])
then
    return {__STORAGE_CORRUPT__}
end

local semanticMatches =
    metadata[5] == ARGV[5]
    and metadata[6] == ARGV[6]
    and metadata[7] == ARGV[7]

if semanticMatches then
    return {__OK__}
end

return {__CONTRACT_CONFLICT__}
LUA);
    }

    private static function hydrate(string $script): string
    {
        return strtr($script, [
            '__FORMAT__' => RedisBloomScripts::STORAGE_FORMAT,
            '__OK__' => "'".self::STATUS_OK."'",
            '__NOT_PROVISIONED__' => "'".self::STATUS_NOT_PROVISIONED."'",
            '__STORAGE_CORRUPT__' => "'".self::STATUS_STORAGE_CORRUPT."'",
            '__LAYOUT_MISMATCH__' => "'".self::STATUS_LAYOUT_MISMATCH."'",
            '__CONTRACT_CONFLICT__' => "'".self::STATUS_CONTRACT_CONFLICT."'",
        ]);
    }
}
