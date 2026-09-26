<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Redis;

final class RedisQuerySafetyScripts
{
    public const string STATUS_ACTIVE = '100';

    public const string STATUS_NO_ACTIVE = '101';

    public const string STATUS_CORRUPT = '201';

    public const string RESULT_ABSENT = '100';

    public const string RESULT_MAYBE = '101';

    public const string RESULT_BYPASS = '200';

    public const string RESULT_PROTOCOL_ERROR = '300';

    public static function readActive(): string
    {
        return self::hydrate(<<<'LUA'
local function isCanonicalPositiveInteger(value)
    if type(value) ~= 'string' then
        return false
    end

    if string.match(value, '^[1-9][0-9]*$') == nil then
        return false
    end

    local maximum = '__PHP_INT_MAX__'

    if string.len(value) > string.len(maximum) then
        return false
    end

    if string.len(value) == string.len(maximum) and value > maximum then
        return false
    end

    return true
end

local function isLifecycleToken(value)
    return value == 'configured'
        or value == 'building'
        or value == 'shadow'
        or value == 'verified'
        or value == 'active'
        or value == 'retired'
end

local function isHealthToken(value)
    return value == 'healthy'
        or value == 'degraded'
        or value == 'stale'
        or value == 'unavailable'
end

local keyType = redis.call('TYPE', KEYS[1]).ok

if keyType == 'none' then
    return {'__NO_ACTIVE__'}
end

if keyType ~= 'hash' then
    return {'__CORRUPT__'}
end

local top = redis.call(
    'HMGET',
    KEYS[1],
    'format',
    'revision',
    'active_version'
)

if top[1] ~= 'control-v1' then
    return {'__CORRUPT__'}
end

if not isCanonicalPositiveInteger(top[2]) then
    return {'__CORRUPT__'}
end

local activeVersion = top[3]

if activeVersion == false then
    return {'__NO_ACTIVE__'}
end

if not isCanonicalPositiveInteger(activeVersion) then
    return {'__CORRUPT__'}
end

local lifecycleField = 'g:' .. activeVersion .. ':lifecycle'
local healthField = 'g:' .. activeVersion .. ':health'
local generation = redis.call(
    'HMGET',
    KEYS[1],
    lifecycleField,
    healthField
)

if generation[1] == false or generation[2] == false then
    return {'__CORRUPT__'}
end

if not isLifecycleToken(generation[1])
    or not isHealthToken(generation[2])
then
    return {'__CORRUPT__'}
end

return {
    '__ACTIVE__',
    top[2],
    activeVersion,
    generation[1],
    generation[2]
}
LUA);
    }

    public static function authorizedProbe(): string
    {
        return self::hydrate(<<<'LUA'
local function isCanonicalPositiveInteger(value, maximum)
    if type(value) ~= 'string' then
        return false
    end

    if string.match(value, '^[1-9][0-9]*$') == nil then
        return false
    end

    if maximum ~= nil then
        local numeric = tonumber(value)

        return numeric ~= nil and numeric >= 1 and numeric <= maximum
    end

    local phpMaximum = '__PHP_INT_MAX__'

    if string.len(value) > string.len(phpMaximum) then
        return false
    end

    if string.len(value) == string.len(phpMaximum) and value > phpMaximum then
        return false
    end

    return true
end

local function isCanonicalBitPosition(value, maximumExclusive)
    if type(value) ~= 'string' then
        return false
    end

    if value ~= '0' and string.match(value, '^[1-9][0-9]*$') == nil then
        return false
    end

    local numeric = tonumber(value)

    return numeric ~= nil
        and numeric >= 0
        and numeric < maximumExclusive
end

local function isCanonicalFingerprint(value)
    return type(value) == 'string'
        and string.match(value, '^sha256:[0-9a-f]+$') ~= nil
        and string.len(value) == 71
end

local function isLifecycleToken(value)
    return value == 'configured'
        or value == 'building'
        or value == 'shadow'
        or value == 'verified'
        or value == 'active'
        or value == 'retired'
end

local function isHealthToken(value)
    return value == 'healthy'
        or value == 'degraded'
        or value == 'stale'
        or value == 'unavailable'
end

local expectedRevision = ARGV[1]
local expectedVersion = ARGV[2]
local expectedFormat = ARGV[3]
local expectedBitCount = ARGV[4]
local expectedHashCount = ARGV[5]
local expectedProbeAlgorithm = ARGV[6]
local expectedNormalization = ARGV[7]
local expectedAuthoritativeSet = ARGV[8]
local expectedConsistency = ARGV[9]

if not isCanonicalPositiveInteger(expectedRevision, nil)
    or not isCanonicalPositiveInteger(expectedVersion, nil)
    or expectedFormat ~= 'redis-bitmap-v1'
    or not isCanonicalPositiveInteger(expectedBitCount, 2147483647)
    or not isCanonicalPositiveInteger(expectedHashCount, 64)
    or tonumber(expectedHashCount) > tonumber(expectedBitCount)
    or expectedProbeAlgorithm ~= 'sha256-double-hash-v1'
    or not isCanonicalFingerprint(expectedNormalization)
    or not isCanonicalFingerprint(expectedAuthoritativeSet)
    or not isCanonicalFingerprint(expectedConsistency)
then
    return {'__PROTOCOL_ERROR__'}
end

local hashCount = tonumber(expectedHashCount)
local bitCount = tonumber(expectedBitCount)
local positionArgumentCount = #ARGV - 9

if positionArgumentCount ~= hashCount then
    return {'__PROTOCOL_ERROR__'}
end

for index = 10, #ARGV do
    if not isCanonicalBitPosition(ARGV[index], bitCount) then
        return {'__PROTOCOL_ERROR__'}
    end
end

local stateType = redis.call('TYPE', KEYS[1]).ok

if stateType == 'none' then
    return {'__BYPASS__', 'control_state_changed'}
end

if stateType ~= 'hash' then
    return {'__BYPASS__', 'operation_failed'}
end

local control = redis.call(
    'HMGET',
    KEYS[1],
    'format',
    'revision',
    'active_version'
)

if control[1] ~= 'control-v1' then
    return {'__BYPASS__', 'operation_failed'}
end

if not isCanonicalPositiveInteger(control[2], nil) then
    return {'__BYPASS__', 'operation_failed'}
end

if control[3] == false then
    return {'__BYPASS__', 'control_state_changed'}
end

if not isCanonicalPositiveInteger(control[3], nil) then
    return {'__BYPASS__', 'operation_failed'}
end

if control[2] ~= expectedRevision
    or control[3] ~= expectedVersion
then
    return {'__BYPASS__', 'control_state_changed'}
end

local lifecycleField = 'g:' .. expectedVersion .. ':lifecycle'
local healthField = 'g:' .. expectedVersion .. ':health'
local generation = redis.call(
    'HMGET',
    KEYS[1],
    lifecycleField,
    healthField
)

if generation[1] == false or generation[2] == false then
    return {'__BYPASS__', 'operation_failed'}
end

if not isLifecycleToken(generation[1])
    or not isHealthToken(generation[2])
then
    return {'__BYPASS__', 'operation_failed'}
end

if generation[1] ~= 'active'
    or generation[2] ~= 'healthy'
then
    return {'__BYPASS__', 'control_state_changed'}
end

local metaType = redis.call('TYPE', KEYS[2]).ok
local bitmapType = redis.call('TYPE', KEYS[3]).ok

if metaType == 'none' then
    if bitmapType ~= 'none' then
        return {'__BYPASS__', 'generation_storage_corrupt'}
    end

    return {'__BYPASS__', 'generation_storage_unavailable'}
end

if metaType ~= 'hash' then
    return {'__BYPASS__', 'generation_storage_corrupt'}
end

if bitmapType ~= 'none' and bitmapType ~= 'string' then
    return {'__BYPASS__', 'generation_storage_corrupt'}
end

local metadata = redis.call(
    'HMGET',
    KEYS[2],
    'format',
    'bit_count',
    'hash_count',
    'probe_algorithm',
    'normalization_fingerprint',
    'authoritative_set_fingerprint',
    'consistency_fingerprint',
    'managed_bitmap_written'
)

if metadata[1] ~= 'redis-bitmap-v1'
    or not isCanonicalPositiveInteger(metadata[2], 2147483647)
    or not isCanonicalPositiveInteger(metadata[3], 64)
    or tonumber(metadata[3]) > tonumber(metadata[2])
    or type(metadata[4]) ~= 'string'
    or metadata[4] == ''
then
    return {'__BYPASS__', 'generation_storage_corrupt'}
end

if metadata[1] ~= expectedFormat
    or metadata[2] ~= expectedBitCount
    or metadata[3] ~= expectedHashCount
    or metadata[4] ~= expectedProbeAlgorithm
then
    return {'__BYPASS__', 'generation_storage_corrupt'}
end

local semanticFieldCount = 0

for index = 5, 7 do
    if metadata[index] ~= false then
        semanticFieldCount = semanticFieldCount + 1
    end
end

if semanticFieldCount == 0 then
    return {'__BYPASS__', 'generation_contract_unbound'}
end

if semanticFieldCount ~= 3 then
    return {'__BYPASS__', 'generation_storage_corrupt'}
end

if not isCanonicalFingerprint(metadata[5])
    or not isCanonicalFingerprint(metadata[6])
    or not isCanonicalFingerprint(metadata[7])
then
    return {'__BYPASS__', 'generation_storage_corrupt'}
end

if metadata[5] ~= expectedNormalization then
    return {'__BYPASS__', 'normalization_mismatch'}
end

if metadata[6] ~= expectedAuthoritativeSet then
    return {'__BYPASS__', 'authoritative_set_mismatch'}
end

if metadata[7] ~= expectedConsistency then
    return {'__BYPASS__', 'consistency_mismatch'}
end

local managedBitmapWritten = metadata[8]

if managedBitmapWritten ~= false and managedBitmapWritten ~= '1' then
    return {'__BYPASS__', 'generation_storage_corrupt'}
end

if managedBitmapWritten == '1' and bitmapType == 'none' then
    return {'__BYPASS__', 'generation_storage_corrupt'}
end

if bitmapType == 'none' then
    return {'__ABSENT__'}
end

for index = 10, #ARGV do
    if redis.call('GETBIT', KEYS[3], ARGV[index]) == 0 then
        return {'__ABSENT__'}
    end
end

return {'__MAYBE__'}
LUA);
    }

    private static function hydrate(string $script): string
    {
        return strtr($script, [
            '__PHP_INT_MAX__' => (string) PHP_INT_MAX,
            '__ACTIVE__' => self::STATUS_ACTIVE,
            '__NO_ACTIVE__' => self::STATUS_NO_ACTIVE,
            '__CORRUPT__' => self::STATUS_CORRUPT,
            '__ABSENT__' => self::RESULT_ABSENT,
            '__MAYBE__' => self::RESULT_MAYBE,
            '__BYPASS__' => self::RESULT_BYPASS,
            '__PROTOCOL_ERROR__' => self::RESULT_PROTOCOL_ERROR,
        ]);
    }
}
