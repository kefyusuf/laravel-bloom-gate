<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Laravel\Redis;

use Closure;
use Illuminate\Redis\Connections\Connection;
use Kefyusuf\BloomGate\Contracts\Diagnostics\Exception\RedisDiagnosticsInvalid;
use Kefyusuf\BloomGate\Contracts\Diagnostics\Exception\RedisDiagnosticsUnavailable;
use Kefyusuf\BloomGate\Contracts\Diagnostics\RedisRuntimeDiagnostics;
use Kefyusuf\BloomGate\Core\RedisDurabilitySettings;
use Kefyusuf\BloomGate\Core\RedisRuntimeInfo;
use Throwable;

final readonly class LaravelRedisRuntimeDiagnostics implements RedisRuntimeDiagnostics
{
    /**
     * @var list<string>
     */
    private const array OPERATIONAL_FAILURE_TYPES = [
        'RedisException',
        'Predis\\PredisException',
    ];

    /**
     * @param  Closure(): Connection  $connection
     */
    public function __construct(
        private Closure $connection,
    ) {}

    public function runtime(): RedisRuntimeInfo
    {
        $server = $this->info('server');
        $replication = $this->info('replication');

        return new RedisRuntimeInfo(
            version: $this->requiredField($server, 'redis_version'),
            mode: $this->requiredField($server, 'redis_mode'),
            role: $this->requiredField($replication, 'role'),
        );
    }

    public function durability(): RedisDurabilitySettings
    {
        $appendOnly = $this->configValue('appendonly');

        if ($appendOnly !== 'yes' && $appendOnly !== 'no') {
            throw new RedisDiagnosticsInvalid(
                'Redis appendonly diagnostic value is invalid.',
            );
        }

        return new RedisDurabilitySettings(
            appendOnly: $appendOnly === 'yes',
            appendFsync: $this->configValue('appendfsync'),
            maxmemoryPolicy: $this->configValue('maxmemory-policy'),
        );
    }

    /**
     * @return array<string, string>
     */
    private function info(string $section): array
    {
        $reply = $this->command('info', [$section]);

        if (is_array($reply)) {
            $parsed = [];

            foreach ($reply as $key => $value) {
                if (! is_string($key) || (! is_string($value) && ! is_int($value))) {
                    throw new RedisDiagnosticsInvalid(
                        'Redis INFO diagnostic reply contains an unsupported field.',
                    );
                }

                $parsed[$key] = (string) $value;
            }

            return $parsed;
        }

        if (! is_string($reply)) {
            throw new RedisDiagnosticsInvalid(
                'Redis INFO diagnostic reply must be an array or string.',
            );
        }

        $parsed = [];

        foreach (preg_split('/\r?\n/', $reply) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, ':');

            if ($separator === false) {
                continue;
            }

            $parsed[substr($line, 0, $separator)] = substr(
                $line,
                $separator + 1,
            );
        }

        return $parsed;
    }

    private function configValue(string $key): string
    {
        $reply = $this->command('config', ['GET', $key]);

        if (! is_array($reply)) {
            throw new RedisDiagnosticsInvalid(
                'Redis CONFIG GET diagnostic reply must be an array.',
            );
        }

        if (array_is_list($reply)) {
            $returnedKey = $reply[0] ?? null;
            $value = $reply[1] ?? null;

            if (
                count($reply) !== 2
                || ! is_string($returnedKey)
                || $returnedKey !== $key
                || ! is_string($value)
            ) {
                throw new RedisDiagnosticsInvalid(
                    'Redis CONFIG GET diagnostic list reply is invalid.',
                );
            }

            return $value;
        }

        $value = $reply[$key] ?? null;

        if (! is_string($value)) {
            throw new RedisDiagnosticsInvalid(
                'Redis CONFIG GET diagnostic map reply is invalid.',
            );
        }

        return $value;
    }

    /**
     * @param  list<mixed>  $parameters
     */
    private function command(
        string $method,
        array $parameters,
    ): mixed {
        try {
            return ($this->connection)()->command(
                $method,
                $parameters,
            );
        } catch (Throwable $failure) {
            if (! $this->isOperationalRedisFailure($failure)) {
                throw $failure;
            }

            throw new RedisDiagnosticsUnavailable(
                'Redis runtime diagnostic command is unavailable.',
                0,
                $failure,
            );
        }
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function requiredField(array $fields, string $key): string
    {
        $value = $fields[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RedisDiagnosticsInvalid(sprintf(
                'Redis runtime diagnostic field [%s] is missing.',
                $key,
            ));
        }

        return $value;
    }

    private function isOperationalRedisFailure(Throwable $failure): bool
    {
        foreach (self::OPERATIONAL_FAILURE_TYPES as $type) {
            if (is_a($failure, $type)) {
                return true;
            }
        }

        return false;
    }
}
