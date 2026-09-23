<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Redis;

final class RedisBloomScripts
{
    public const string STORAGE_FORMAT = 'redis-bitmap-v1';

    public const int STATUS_OK = 100;

    public const int STATUS_MEMBERSHIP_ABSENT = 101;

    public const int STATUS_MEMBERSHIP_MAYBE_PRESENT = 102;

    public const int STATUS_NOT_PROVISIONED = 200;

    public const int STATUS_LAYOUT_CONFLICT = 201;

    public const int STATUS_LAYOUT_MISMATCH = 202;

    public const int STATUS_STORAGE_CORRUPT = 203;

    public const int STATUS_INVALID_BATCH = 204;

    public const string META_FORMAT = 'format';

    public const string META_BIT_COUNT = 'bit_count';

    public const string META_HASH_COUNT = 'hash_count';

    public const string META_PROBE_ALGORITHM = 'probe_algorithm';

    public const string META_MANAGED_BITMAP_WRITTEN = 'managed_bitmap_written';

    public static function provision(): string
    {
        return self::hydrate(<<<'LUA'
local metaType = redis.call('TYPE', KEYS[1]).ok
local bitmapType = redis.call('TYPE', KEYS[2]).ok

if metaType == 'none' then
    if bitmapType ~= 'none' then
        return __STORAGE_CORRUPT__
    end

    redis.call(
        'HSET',
        KEYS[1],
        'format', ARGV[1],
        'bit_count', ARGV[2],
        'hash_count', ARGV[3],
        'probe_algorithm', ARGV[4]
    )

    return __OK__
end

if metaType ~= 'hash' then
    return __STORAGE_CORRUPT__
end

if bitmapType ~= 'none' and bitmapType ~= 'string' then
    return __STORAGE_CORRUPT__
end

local metadata = redis.call(
    'HMGET',
    KEYS[1],
    'format',
    'bit_count',
    'hash_count',
    'probe_algorithm'
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

if metadata[1] ~= '__FORMAT__' then
    return __STORAGE_CORRUPT__
end

if not isCanonicalPositiveInteger(metadata[2], 2147483647) then
    return __STORAGE_CORRUPT__
end

if not isCanonicalPositiveInteger(metadata[3], 64) then
    return __STORAGE_CORRUPT__
end

if tonumber(metadata[3]) > tonumber(metadata[2]) then
    return __STORAGE_CORRUPT__
end

if type(metadata[4]) ~= 'string' or metadata[4] == '' then
    return __STORAGE_CORRUPT__
end

local metadataMatches =
    metadata[1] == ARGV[1]
    and metadata[2] == ARGV[2]
    and metadata[3] == ARGV[3]
    and metadata[4] == ARGV[4]

if metadataMatches then
    return __OK__
end

return __LAYOUT_CONFLICT__
LUA);
    }

    public static function add(): string
    {
        return self::hydrate(<<<'LUA'
local metaType = redis.call('TYPE', KEYS[1]).ok
local bitmapType = redis.call('TYPE', KEYS[2]).ok

if metaType == 'none' then
    if bitmapType ~= 'none' then
        return __STORAGE_CORRUPT__
    end

    return __NOT_PROVISIONED__
end

if metaType ~= 'hash' then
    return __STORAGE_CORRUPT__
end

if bitmapType ~= 'none' and bitmapType ~= 'string' then
    return __STORAGE_CORRUPT__
end

local metadata = redis.call(
    'HMGET',
    KEYS[1],
    'format',
    'bit_count',
    'hash_count',
    'probe_algorithm'
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

if metadata[1] ~= '__FORMAT__' then
    return __STORAGE_CORRUPT__
end

if not isCanonicalPositiveInteger(metadata[2], 2147483647) then
    return __STORAGE_CORRUPT__
end

if not isCanonicalPositiveInteger(metadata[3], 64) then
    return __STORAGE_CORRUPT__
end

if tonumber(metadata[3]) > tonumber(metadata[2]) then
    return __STORAGE_CORRUPT__
end

if type(metadata[4]) ~= 'string' or metadata[4] == '' then
    return __STORAGE_CORRUPT__
end

local metadataMatches =
    metadata[1] == ARGV[1]
    and metadata[2] == ARGV[2]
    and metadata[3] == ARGV[3]
    and metadata[4] == ARGV[4]

if metadataMatches == false then
    return __LAYOUT_MISMATCH__
end

for index = 5, #ARGV do
    redis.call('SETBIT', KEYS[2], ARGV[index], 1)
end

return __OK__
LUA);
    }


    public static function addMany(): string
    {
        return self::hydrate(<<<'LUA'
local metaType = redis.call('TYPE', KEYS[1]).ok
local bitmapType = redis.call('TYPE', KEYS[2]).ok

if metaType == 'none' then
    if bitmapType ~= 'none' then
        return __STORAGE_CORRUPT__
    end

    return __NOT_PROVISIONED__
end

if metaType ~= 'hash' then
    return __STORAGE_CORRUPT__
end

if bitmapType ~= 'none' and bitmapType ~= 'string' then
    return __STORAGE_CORRUPT__
end

local metadata = redis.call(
    'HMGET',
    KEYS[1],
    'format',
    'bit_count',
    'hash_count',
    'probe_algorithm',
    'managed_bitmap_written'
)

local function isCanonicalPositiveInteger(value, maximum)
    if type(value) ~= 'string' then
        return false
    end

    if string.match(value, '^[1-9][0-9]*    {
        return self::hydrate(<<<'LUA'
local metaType = redis.call('TYPE', KEYS[1]).ok
local bitmapType = redis.call('TYPE', KEYS[2]).ok

if metaType == 'none' then
    if bitmapType ~= 'none' then
        return __STORAGE_CORRUPT__
    end

    return __NOT_PROVISIONED__
end

if metaType ~= 'hash' then
    return __STORAGE_CORRUPT__
end

if bitmapType ~= 'none' and bitmapType ~= 'string' then
    return __STORAGE_CORRUPT__
end

local metadata = redis.call(
    'HMGET',
    KEYS[1],
    'format',
    'bit_count',
    'hash_count',
    'probe_algorithm'
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

if metadata[1] ~= '__FORMAT__' then
    return __STORAGE_CORRUPT__
end

if not isCanonicalPositiveInteger(metadata[2], 2147483647) then
    return __STORAGE_CORRUPT__
end

if not isCanonicalPositiveInteger(metadata[3], 64) then
    return __STORAGE_CORRUPT__
end

if tonumber(metadata[3]) > tonumber(metadata[2]) then
    return __STORAGE_CORRUPT__
end

if type(metadata[4]) ~= 'string' or metadata[4] == '' then
    return __STORAGE_CORRUPT__
end

local metadataMatches =
    metadata[1] == ARGV[1]
    and metadata[2] == ARGV[2]
    and metadata[3] == ARGV[3]
    and metadata[4] == ARGV[4]

if metadataMatches == false then
    return __LAYOUT_MISMATCH__
end

if bitmapType == 'none' then
    return __MEMBERSHIP_ABSENT__
end

for index = 5, #ARGV do
    if redis.call('GETBIT', KEYS[2], ARGV[index]) == 0 then
        return __MEMBERSHIP_ABSENT__
    end
end

return __MEMBERSHIP_MAYBE_PRESENT__
LUA);
    }

    public static function destroy(): string
    {
        return self::hydrate(<<<'LUA'
redis.call('DEL', KEYS[1], KEYS[2])

return __OK__
LUA);
    }

    private static function hydrate(string $script): string
    {
        return strtr($script, [
            '__FORMAT__' => self::STORAGE_FORMAT,
            '__OK__' => (string) self::STATUS_OK,
            '__MEMBERSHIP_ABSENT__' => (string) self::STATUS_MEMBERSHIP_ABSENT,
            '__MEMBERSHIP_MAYBE_PRESENT__' => (string) self::STATUS_MEMBERSHIP_MAYBE_PRESENT,
            '__NOT_PROVISIONED__' => (string) self::STATUS_NOT_PROVISIONED,
            '__LAYOUT_CONFLICT__' => (string) self::STATUS_LAYOUT_CONFLICT,
            '__LAYOUT_MISMATCH__' => (string) self::STATUS_LAYOUT_MISMATCH,
            '__STORAGE_CORRUPT__' => (string) self::STATUS_STORAGE_CORRUPT,
            '__INVALID_BATCH__' => (string) self::STATUS_INVALID_BATCH,
        ]);
    }
}
) == nil then
        return false
    end

    local numeric = tonumber(value)

    return numeric ~= nil and numeric >= 1 and numeric <= maximum
end

local function isCanonicalBitPosition(value, maximumExclusive)
    if type(value) ~= 'string' then
        return false
    end

    if value ~= '0' and string.match(value, '^[1-9][0-9]*    {
        return self::hydrate(<<<'LUA'
local metaType = redis.call('TYPE', KEYS[1]).ok
local bitmapType = redis.call('TYPE', KEYS[2]).ok

if metaType == 'none' then
    if bitmapType ~= 'none' then
        return __STORAGE_CORRUPT__
    end

    return __NOT_PROVISIONED__
end

if metaType ~= 'hash' then
    return __STORAGE_CORRUPT__
end

if bitmapType ~= 'none' and bitmapType ~= 'string' then
    return __STORAGE_CORRUPT__
end

local metadata = redis.call(
    'HMGET',
    KEYS[1],
    'format',
    'bit_count',
    'hash_count',
    'probe_algorithm'
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

if metadata[1] ~= '__FORMAT__' then
    return __STORAGE_CORRUPT__
end

if not isCanonicalPositiveInteger(metadata[2], 2147483647) then
    return __STORAGE_CORRUPT__
end

if not isCanonicalPositiveInteger(metadata[3], 64) then
    return __STORAGE_CORRUPT__
end

if tonumber(metadata[3]) > tonumber(metadata[2]) then
    return __STORAGE_CORRUPT__
end

if type(metadata[4]) ~= 'string' or metadata[4] == '' then
    return __STORAGE_CORRUPT__
end

local metadataMatches =
    metadata[1] == ARGV[1]
    and metadata[2] == ARGV[2]
    and metadata[3] == ARGV[3]
    and metadata[4] == ARGV[4]

if metadataMatches == false then
    return __LAYOUT_MISMATCH__
end

if bitmapType == 'none' then
    return __MEMBERSHIP_ABSENT__
end

for index = 5, #ARGV do
    if redis.call('GETBIT', KEYS[2], ARGV[index]) == 0 then
        return __MEMBERSHIP_ABSENT__
    end
end

return __MEMBERSHIP_MAYBE_PRESENT__
LUA);
    }

    public static function destroy(): string
    {
        return self::hydrate(<<<'LUA'
redis.call('DEL', KEYS[1], KEYS[2])

return __OK__
LUA);
    }

    private static function hydrate(string $script): string
    {
        return strtr($script, [
            '__FORMAT__' => self::STORAGE_FORMAT,
            '__OK__' => (string) self::STATUS_OK,
            '__MEMBERSHIP_ABSENT__' => (string) self::STATUS_MEMBERSHIP_ABSENT,
            '__MEMBERSHIP_MAYBE_PRESENT__' => (string) self::STATUS_MEMBERSHIP_MAYBE_PRESENT,
            '__NOT_PROVISIONED__' => (string) self::STATUS_NOT_PROVISIONED,
            '__LAYOUT_CONFLICT__' => (string) self::STATUS_LAYOUT_CONFLICT,
            '__LAYOUT_MISMATCH__' => (string) self::STATUS_LAYOUT_MISMATCH,
            '__STORAGE_CORRUPT__' => (string) self::STATUS_STORAGE_CORRUPT,
        ]);
    }
}
) == nil then
        return false
    end

    local numeric = tonumber(value)

    return numeric ~= nil
        and numeric >= 0
        and numeric < maximumExclusive
end

if metadata[1] ~= '__FORMAT__' then
    return __STORAGE_CORRUPT__
end

if not isCanonicalPositiveInteger(metadata[2], 2147483647) then
    return __STORAGE_CORRUPT__
end

if not isCanonicalPositiveInteger(metadata[3], 64) then
    return __STORAGE_CORRUPT__
end

if tonumber(metadata[3]) > tonumber(metadata[2]) then
    return __STORAGE_CORRUPT__
end

if type(metadata[4]) ~= 'string' or metadata[4] == '' then
    return __STORAGE_CORRUPT__
end

local metadataMatches =
    metadata[1] == ARGV[1]
    and metadata[2] == ARGV[2]
    and metadata[3] == ARGV[3]
    and metadata[4] == ARGV[4]

if metadataMatches == false then
    return __LAYOUT_MISMATCH__
end

local managedBitmapWritten = metadata[5]

if managedBitmapWritten ~= false and managedBitmapWritten ~= '1' then
    return __STORAGE_CORRUPT__
end

if managedBitmapWritten == '1' and bitmapType == 'none' then
    return __STORAGE_CORRUPT__
end

local hashCount = tonumber(metadata[3])
local bitCount = tonumber(metadata[2])
local positionArgumentCount = #ARGV - 4

if positionArgumentCount < hashCount
    or positionArgumentCount % hashCount ~= 0
then
    return __INVALID_BATCH__
end

for index = 5, #ARGV do
    if not isCanonicalBitPosition(ARGV[index], bitCount) then
        return __INVALID_BATCH__
    end
end

redis.call('HSET', KEYS[1], 'managed_bitmap_written', '1')

for index = 5, #ARGV do
    redis.call('SETBIT', KEYS[2], ARGV[index], 1)
end

return __OK__
LUA);
    }

    public static function mightContain(): string
    {
        return self::hydrate(<<<'LUA'
local metaType = redis.call('TYPE', KEYS[1]).ok
local bitmapType = redis.call('TYPE', KEYS[2]).ok

if metaType == 'none' then
    if bitmapType ~= 'none' then
        return __STORAGE_CORRUPT__
    end

    return __NOT_PROVISIONED__
end

if metaType ~= 'hash' then
    return __STORAGE_CORRUPT__
end

if bitmapType ~= 'none' and bitmapType ~= 'string' then
    return __STORAGE_CORRUPT__
end

local metadata = redis.call(
    'HMGET',
    KEYS[1],
    'format',
    'bit_count',
    'hash_count',
    'probe_algorithm'
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

if metadata[1] ~= '__FORMAT__' then
    return __STORAGE_CORRUPT__
end

if not isCanonicalPositiveInteger(metadata[2], 2147483647) then
    return __STORAGE_CORRUPT__
end

if not isCanonicalPositiveInteger(metadata[3], 64) then
    return __STORAGE_CORRUPT__
end

if tonumber(metadata[3]) > tonumber(metadata[2]) then
    return __STORAGE_CORRUPT__
end

if type(metadata[4]) ~= 'string' or metadata[4] == '' then
    return __STORAGE_CORRUPT__
end

local metadataMatches =
    metadata[1] == ARGV[1]
    and metadata[2] == ARGV[2]
    and metadata[3] == ARGV[3]
    and metadata[4] == ARGV[4]

if metadataMatches == false then
    return __LAYOUT_MISMATCH__
end

if bitmapType == 'none' then
    return __MEMBERSHIP_ABSENT__
end

for index = 5, #ARGV do
    if redis.call('GETBIT', KEYS[2], ARGV[index]) == 0 then
        return __MEMBERSHIP_ABSENT__
    end
end

return __MEMBERSHIP_MAYBE_PRESENT__
LUA);
    }

    public static function destroy(): string
    {
        return self::hydrate(<<<'LUA'
redis.call('DEL', KEYS[1], KEYS[2])

return __OK__
LUA);
    }

    private static function hydrate(string $script): string
    {
        return strtr($script, [
            '__FORMAT__' => self::STORAGE_FORMAT,
            '__OK__' => (string) self::STATUS_OK,
            '__MEMBERSHIP_ABSENT__' => (string) self::STATUS_MEMBERSHIP_ABSENT,
            '__MEMBERSHIP_MAYBE_PRESENT__' => (string) self::STATUS_MEMBERSHIP_MAYBE_PRESENT,
            '__NOT_PROVISIONED__' => (string) self::STATUS_NOT_PROVISIONED,
            '__LAYOUT_CONFLICT__' => (string) self::STATUS_LAYOUT_CONFLICT,
            '__LAYOUT_MISMATCH__' => (string) self::STATUS_LAYOUT_MISMATCH,
            '__STORAGE_CORRUPT__' => (string) self::STATUS_STORAGE_CORRUPT,
        ]);
    }
}
