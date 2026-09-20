<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Redis;

final class RedisControlScripts
{
    public static function read(): string
    {
        return self::validator()."\n".<<<'LUA'
local keyType = redis.call('TYPE', KEYS[1]).ok

if keyType == 'none' then
    return {'100'}
end

if keyType ~= 'hash' then
    return {'201'}
end

local fields = redis.call('HGETALL', KEYS[1])
local valid, revision = validateControlFields(fields)

if not valid then
    return {'201'}
end

local response = {}
response[1] = '100'

for index = 1, #fields do
    response[#response + 1] = fields[index]
end

return response
LUA;
    }

    public static function compareAndSwap(): string
    {
        return self::validator()."\n".<<<'LUA'
local currentType = redis.call('TYPE', KEYS[1]).ok
local expectedRevision = ARGV[1]
local currentRevision = nil

if currentType ~= 'none' and currentType ~= 'hash' then
    return {'201'}
end

if currentType == 'hash' then
    local currentFields = redis.call('HGETALL', KEYS[1])
    local currentValid, currentRevision = validateControlFields(currentFields)

    if not currentValid then
        return {'201'}
    end

    if expectedRevision == '' then
        return {'200'}
    end

    if currentRevision ~= expectedRevision then
        return {'200'}
    end
else
    if expectedRevision ~= '' then
        return {'200'}
    end
end

local nextFields = {}

for index = 2, #ARGV do
    nextFields[#nextFields + 1] = ARGV[index]
end

local nextValid, nextRevision = validateControlFields(nextFields)

if not nextValid then
    return {'201'}
end

redis.call('DEL', KEYS[1])
redis.call('HSET', KEYS[1], unpack(nextFields))

return {'100'}
LUA;
    }

    private static function validator(): string
    {
        $maximum = (string) PHP_INT_MAX;

        return str_replace(
            '__PHP_INT_MAX__',
            $maximum,
            <<<'LUA'
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

local function positiveIntegerLessThanOrEqual(left, right)
    if string.len(left) < string.len(right) then
        return true
    end

    if string.len(left) > string.len(right) then
        return false
    end

    return left <= right
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

local function validateControlFields(fields)
    if #fields % 2 ~= 0 then
        return false, nil
    end

    local seen = {}
    local generations = {}
    local format = nil
    local revision = nil
    local lastAllocatedVersion = nil
    local activeVersion = nil
    local candidateVersion = nil
    local activeLifecycleCount = 0

    for index = 1, #fields, 2 do
        local field = fields[index]
        local value = fields[index + 1]

        if type(field) ~= 'string' or type(value) ~= 'string' then
            return false, nil
        end

        if seen[field] then
            return false, nil
        end

        seen[field] = true

        if field == 'format' then
            format = value
        elseif field == 'revision' then
            revision = value
        elseif field == 'last_allocated_version' then
            lastAllocatedVersion = value
        elseif field == 'active_version' then
            activeVersion = value
        elseif field == 'candidate_version' then
            candidateVersion = value
        else
            local version, property = string.match(field, '^g:([^:]+):([^:]+)$')

            if version == nil or property == nil then
                return false, nil
            end

            if not isCanonicalPositiveInteger(version) then
                return false, nil
            end

            if property ~= 'lifecycle' and property ~= 'health' then
                return false, nil
            end

            if generations[version] == nil then
                generations[version] = {}
            end

            if generations[version][property] ~= nil then
                return false, nil
            end

            generations[version][property] = value
        end
    end

    if format ~= 'control-v1' then
        return false, nil
    end

    if not isCanonicalPositiveInteger(revision) then
        return false, nil
    end

    if not isCanonicalPositiveInteger(lastAllocatedVersion) then
        return false, nil
    end

    if activeVersion ~= nil and not isCanonicalPositiveInteger(activeVersion) then
        return false, nil
    end

    if candidateVersion ~= nil and not isCanonicalPositiveInteger(candidateVersion) then
        return false, nil
    end

    if activeVersion ~= nil and candidateVersion ~= nil and activeVersion == candidateVersion then
        return false, nil
    end

    for version, generation in pairs(generations) do
        if generation.lifecycle == nil or generation.health == nil then
            return false, nil
        end

        if not isLifecycleToken(generation.lifecycle) then
            return false, nil
        end

        if not isHealthToken(generation.health) then
            return false, nil
        end

        if not positiveIntegerLessThanOrEqual(version, lastAllocatedVersion) then
            return false, nil
        end

        if generation.lifecycle == 'active' then
            activeLifecycleCount = activeLifecycleCount + 1

            if activeLifecycleCount > 1 then
                return false, nil
            end
        end
    end

    if activeVersion ~= nil then
        local active = generations[activeVersion]

        if active == nil or active.lifecycle ~= 'active' then
            return false, nil
        end
    end

    if candidateVersion ~= nil then
        local candidate = generations[candidateVersion]

        if candidate == nil then
            return false, nil
        end

        if candidate.lifecycle == 'active' or candidate.lifecycle == 'retired' then
            return false, nil
        end
    end

    return true, revision
end
LUA,
        );
    }
}
