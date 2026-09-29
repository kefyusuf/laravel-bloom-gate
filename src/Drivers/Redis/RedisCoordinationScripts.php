<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Redis;

final class RedisCoordinationScripts
{
    public static function read(): string
    {
        $operation = <<<'LUA'
local controlOk, controlFields = loadControl(KEYS[1])
local ownerOk, ownerPresent = loadOwner(KEYS[2])
local syncOk, syncFields = loadSynchronization(KEYS[3])

if not controlOk or not ownerOk or not syncOk then
    return {'204'}
end

if not ownerPresent and #syncFields > 0 then
    return {'204'}
end

return snapshotResponse(ownerPresent, controlFields, syncFields)
LUA;

        return self::prelude()."\\n".$operation;
    }

    public static function claimOwnership(): string
    {
        $operation = <<<'LUA'
local controlOk, controlFields, controlRevision = loadControl(KEYS[1])
local ownerOk, ownerPresent = loadOwner(KEYS[2])
local syncOk, syncFields = loadSynchronization(KEYS[3])

if not controlOk or not ownerOk or not syncOk then
    return {'204'}
end

if ownerPresent then
    return {'203'}
end

if #syncFields > 0 then
    return {'204'}
end

if not expectedRevisionMatches(controlRevision, ARGV[1]) then
    return {'200'}
end

redis.call('SET', KEYS[2], 'coordinated-v1')

return snapshotResponse(true, controlFields, {})
LUA;

        return self::prelude()."\\n".$operation;
    }

    public static function compareAndSwapControl(): string
    {
        $operation = <<<'LUA'
local controlOk, controlFields, controlRevision = loadControl(KEYS[1])
local ownerOk, ownerPresent = loadOwner(KEYS[3])
local syncOk, syncFields, syncRevision = loadSynchronization(KEYS[4])

if not controlOk or not ownerOk or not syncOk then
    return {'204'}
end

if not ownerPresent and #syncFields > 0 then
    return {'204'}
end

if not ownerPresent then
    return {'203'}
end

if #syncFields == 0 then
    return {'204'}
end

if syncRevision ~= ARGV[2] then
    return {'200'}
end

if not expectedRevisionMatches(controlRevision, ARGV[1]) then
    return {'200'}
end

local nextFields = {}

for index = 3, #ARGV do
    nextFields[#nextFields + 1] = ARGV[index]
end

local nextValid, nextRevision = validateControlFields(nextFields)

if not nextValid then
    return {'204'}
end

if not nextRevisionMatches(controlRevision, nextRevision) then
    return {'202'}
end

redis.call('DEL', KEYS[2])

local writeOk, writeFailure = writeHashFields(KEYS[2], nextFields)

if not writeOk then
    redis.call('DEL', KEYS[2])

    return writeFailure
end

local renameResult = redis.pcall('RENAME', KEYS[2], KEYS[1])

if type(renameResult) == 'table' and renameResult.err ~= nil then
    redis.call('DEL', KEYS[2])

    return renameResult
end

return snapshotResponse(true, nextFields, syncFields)
LUA;

        return self::prelude()."\\n".$operation;
    }

    public static function compareAndSwapSynchronization(): string
    {
        $operation = <<<'LUA'
local controlOk, controlFields, controlRevision = loadControl(KEYS[1])
local ownerOk, ownerPresent = loadOwner(KEYS[2])
local syncOk, syncFields, syncRevision = loadSynchronization(KEYS[3])

if not controlOk or not ownerOk or not syncOk then
    return {'204'}
end

if not ownerPresent and #syncFields > 0 then
    return {'204'}
end

if not ownerPresent then
    return {'203'}
end

if not expectedRevisionMatches(controlRevision, ARGV[1]) then
    return {'200'}
end

if not expectedRevisionMatches(syncRevision, ARGV[2]) then
    return {'200'}
end

local nextFields = {}

for index = 3, #ARGV do
    nextFields[#nextFields + 1] = ARGV[index]
end

local nextValid, nextRevision = validateSynchronizationFields(nextFields)

if not nextValid then
    return {'204'}
end

if not nextRevisionMatches(syncRevision, nextRevision) then
    return {'202'}
end

redis.call('DEL', KEYS[4])

local writeOk, writeFailure = writeHashFields(KEYS[4], nextFields)

if not writeOk then
    redis.call('DEL', KEYS[4])

    return writeFailure
end

local renameResult = redis.pcall('RENAME', KEYS[4], KEYS[3])

if type(renameResult) == 'table' and renameResult.err ~= nil then
    redis.call('DEL', KEYS[4])

    return renameResult
end

return snapshotResponse(true, controlFields, nextFields)
LUA;

        return self::prelude()."\\n".$operation;
    }

    private static function prelude(): string
    {
        $helpers = <<<'LUA'
local WRITE_CHUNK_SIZE = 128

local function writeHashFields(key, fields)
    local chunk = {}
    local chunkCount = 0

    for index = 1, #fields do
        chunkCount = chunkCount + 1
        chunk[chunkCount] = fields[index]

        if chunkCount == WRITE_CHUNK_SIZE or index == #fields then
            local result = redis.pcall('HSET', key, unpack(chunk, 1, chunkCount))

            if type(result) == 'table' and result.err ~= nil then
                return false, result
            end

            chunk = {}
            chunkCount = 0
        end
    end

    return true, nil
end

local function loadControl(key)
    local keyType = redis.call('TYPE', key).ok

    if keyType == 'none' then
        return true, {}, nil
    end

    if keyType ~= 'hash' then
        return false, nil, nil
    end

    local fields = redis.call('HGETALL', key)
    local valid, revision = validateControlFields(fields)

    if not valid then
        return false, nil, nil
    end

    return true, fields, revision
end

local function loadOwner(key)
    local keyType = redis.call('TYPE', key).ok

    if keyType == 'none' then
        return true, false
    end

    if keyType ~= 'string' then
        return false, false
    end

    if redis.call('GET', key) ~= 'coordinated-v1' then
        return false, false
    end

    return true, true
end

local function loadSynchronization(key)
    local keyType = redis.call('TYPE', key).ok

    if keyType == 'none' then
        return true, {}, nil
    end

    if keyType ~= 'hash' then
        return false, nil, nil
    end

    local fields = redis.call('HGETALL', key)
    local valid, revision = validateSynchronizationFields(fields)

    if not valid then
        return false, nil, nil
    end

    return true, fields, revision
end

local function expectedRevisionMatches(currentRevision, expectedRevision)
    if currentRevision == nil then
        return expectedRevision == ''
    end

    return expectedRevision ~= '' and currentRevision == expectedRevision
end

local function nextRevisionMatches(currentRevision, nextRevision)
    if currentRevision == nil then
        return nextRevision == '1'
    end

    local required = incrementCanonicalPositiveInteger(currentRevision)

    return required ~= nil and required == nextRevision
end

local function snapshotResponse(ownerPresent, controlFields, syncFields)
    local response = {
        '100',
        ownerPresent and '1' or '0',
        tostring(#controlFields),
    }

    for index = 1, #controlFields do
        response[#response + 1] = controlFields[index]
    end

    response[#response + 1] = tostring(#syncFields)

    for index = 1, #syncFields do
        response[#response + 1] = syncFields[index]
    end

    return response
end
LUA;

        return RedisControlScripts::controlValidator()
            ."\\n".RedisControlScripts::coordinationValidator()
            ."\\n".$helpers;
    }
}
