<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Redis;

final class RedisWriterSynchronizationScripts
{
    public const string STATUS_OK = '100';

    public const string STATUS_NOT_COORDINATED = '101';

    public const string STATUS_FENCED = '203';

    public const string STATUS_CORRUPT = '204';

    public const string STATUS_UNKNOWN_LEASE = '205';

    public const string STATUS_RELEASED_LEASE = '206';

    public static function read(): string
    {
        $operation = <<<'LUA'
local status, fields = loadCurrentSynchronization(KEYS[1], KEYS[2])

if status == 'missing' then
    return {'101'}
end

if status == 'fenced' then
    return {'203'}
end

if status ~= 'ok' then
    return {'204'}
end

local response = {'100'}

for index = 1, #fields do
    response[#response + 1] = fields[index]
end

return response
LUA;

        return self::prelude().PHP_EOL.$operation;
    }

    public static function acquire(): string
    {
        $operation = <<<'LUA'
local syncStatus, _, currentEpoch, currentTargets = loadCurrentSynchronization(KEYS[1], KEYS[2])

if syncStatus == 'missing' or syncStatus == 'fenced' then
    return {'203'}
end

if syncStatus ~= 'ok' then
    return {'204'}
end

local leaseStatus, leaseState, leaseEpoch, leaseTargets, encodedLease = loadLease(KEYS[3], ARGV[1])

if leaseStatus == 'corrupt' then
    return {'204'}
end

if leaseStatus == 'ok' then
    if leaseState == 'R' then
        return {'206'}
    end

    return {'100', encodedLease}
end

local countStatus, count = loadCount(KEYS[4], currentEpoch)

if countStatus ~= 'ok' then
    return {'204'}
end

local nextCount = incrementNonNegativeInteger(count)

if nextCount == nil then
    return {'204'}
end

local nextLease = 'A|' .. currentEpoch .. '|' .. currentTargets

redis.call('HSET', KEYS[3], ARGV[1], nextLease)
redis.call('HSET', KEYS[4], countField(currentEpoch), nextCount)

return {'100', nextLease}
LUA;

        return self::prelude().PHP_EOL.$operation;
    }

    public static function markPrepared(): string
    {
        $operation = <<<'LUA'
local leaseStatus, leaseState, leaseEpoch, leaseTargets, encodedLease = loadLease(KEYS[3], ARGV[1])

if leaseStatus == 'missing' then
    return {'205'}
end

if leaseStatus ~= 'ok' then
    return {'204'}
end

if leaseState == 'R' then
    return {'206'}
end

local syncStatus = loadCurrentSynchronization(KEYS[1], KEYS[2])

if syncStatus == 'missing' or syncStatus == 'fenced' then
    return {'203'}
end

if syncStatus ~= 'ok' then
    return {'204'}
end

local countStatus, count = loadCount(KEYS[4], leaseEpoch)

if countStatus ~= 'ok' or count == '0' then
    return {'204'}
end

if leaseState == 'P' then
    return {'100', encodedLease}
end

local preparedLease = 'P|' .. leaseEpoch .. '|' .. leaseTargets

redis.call('HSET', KEYS[3], ARGV[1], preparedLease)

return {'100', preparedLease}
LUA;

        return self::prelude().PHP_EOL.$operation;
    }

    public static function release(): string
    {
        $operation = <<<'LUA'
local leaseStatus, leaseState, leaseEpoch, leaseTargets, encodedLease = loadLease(KEYS[1], ARGV[1])

if leaseStatus == 'missing' then
    return {'205'}
end

if leaseStatus ~= 'ok' then
    return {'204'}
end

if leaseState == 'R' then
    return {'100', encodedLease}
end

local countStatus, count = loadCount(KEYS[2], leaseEpoch)

if countStatus ~= 'ok' or count == '0' then
    return {'204'}
end

local nextCount = decrementPositiveInteger(count)

if nextCount == nil then
    return {'204'}
end

local releasedLease = 'R|' .. leaseEpoch .. '|' .. leaseTargets

redis.call('HSET', KEYS[2], countField(leaseEpoch), nextCount)
redis.call('HSET', KEYS[1], ARGV[1], releasedLease)

return {'100', releasedLease}
LUA;

        return self::prelude().PHP_EOL.$operation;
    }

    public static function activeWriterCount(): string
    {
        $operation = <<<'LUA'
local status, count = loadCount(KEYS[1], ARGV[1])

if status ~= 'ok' then
    return {'204'}
end

return {'100', count}
LUA;

        return self::prelude().PHP_EOL.$operation;
    }

    private static function prelude(): string
    {
        $helpers = <<<'LUA'
local function isCanonicalNonNegativeInteger(value)
    return value == '0' or isCanonicalPositiveInteger(value)
end

local function incrementNonNegativeInteger(value)
    if value == '0' then
        return '1'
    end

    return incrementCanonicalPositiveInteger(value)
end

local function decrementPositiveInteger(value)
    if not isCanonicalPositiveInteger(value) then
        return nil
    end

    if value == '1' then
        return '0'
    end

    local digits = {}
    local borrow = 1

    for index = string.len(value), 1, -1 do
        local digit = string.byte(value, index) - 48 - borrow

        if digit < 0 then
            digits[index] = '9'
            borrow = 1
        else
            digits[index] = string.char(48 + digit)
            borrow = 0
        end
    end

    local result = table.concat(digits)

    if string.sub(result, 1, 1) == '0' then
        result = string.sub(result, 2)
    end

    if not isCanonicalNonNegativeInteger(result) then
        return nil
    end

    return result
end

local function countField(epoch)
    return 'e:' .. epoch
end

local function loadCurrentSynchronization(ownerKey, syncKey)
    local ownerType = redis.call('TYPE', ownerKey).ok
    local syncType = redis.call('TYPE', syncKey).ok

    if ownerType ~= 'none' and ownerType ~= 'string' then
        return 'corrupt', nil, nil, nil
    end

    if syncType ~= 'none' and syncType ~= 'hash' then
        return 'corrupt', nil, nil, nil
    end

    if ownerType == 'none' then
        if syncType == 'none' then
            return 'missing', nil, nil, nil
        end

        return 'corrupt', nil, nil, nil
    end

    if redis.call('GET', ownerKey) ~= 'coordinated-v1' then
        return 'corrupt', nil, nil, nil
    end

    if syncType == 'none' then
        return 'fenced', nil, nil, nil
    end

    local fields = redis.call('HGETALL', syncKey)
    local valid = validateSynchronizationFields(fields)

    if not valid then
        return 'corrupt', nil, nil, nil
    end

    local currentEpoch = nil
    local currentTargets = nil

    for index = 1, #fields, 2 do
        if fields[index] == 'current_epoch' then
            currentEpoch = fields[index + 1]
        elseif fields[index] == 'current_targets' then
            currentTargets = fields[index + 1]
        end
    end

    if currentEpoch == nil or currentTargets == nil then
        return 'corrupt', nil, nil, nil
    end

    return 'ok', fields, currentEpoch, currentTargets
end

local function loadLease(leasesKey, token)
    local keyType = redis.call('TYPE', leasesKey).ok

    if keyType == 'none' then
        return 'missing', nil, nil, nil, nil
    end

    if keyType ~= 'hash' then
        return 'corrupt', nil, nil, nil, nil
    end

    local encoded = redis.call('HGET', leasesKey, token)

    if encoded == false then
        return 'missing', nil, nil, nil, nil
    end

    local state, epoch, targets = string.match(
        encoded,
        '^([APR])|([^|]+)|([^|]+)$'
    )

    if state == nil
        or not isCanonicalPositiveInteger(epoch)
        or not isCanonicalTargetSet(targets)
        or encoded ~= state .. '|' .. epoch .. '|' .. targets
    then
        return 'corrupt', nil, nil, nil, nil
    end

    return 'ok', state, epoch, targets, encoded
end

local function loadCount(countsKey, epoch)
    local keyType = redis.call('TYPE', countsKey).ok

    if keyType == 'none' then
        return 'ok', '0'
    end

    if keyType ~= 'hash' then
        return 'corrupt', nil
    end

    local encoded = redis.call('HGET', countsKey, countField(epoch))

    if encoded == false then
        return 'ok', '0'
    end

    if not isCanonicalNonNegativeInteger(encoded) then
        return 'corrupt', nil
    end

    return 'ok', encoded
end
LUA;

        return RedisControlScripts::controlValidator()
            .PHP_EOL.RedisControlScripts::coordinationValidator()
            .PHP_EOL.$helpers;
    }
}
