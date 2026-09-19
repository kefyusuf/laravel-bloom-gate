<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Redis;

use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Contracts\Redis\RedisCommandExecutor;
use UnexpectedValueException;

final readonly class RespRedisCommandExecutor implements RedisCommandExecutor
{
    public function __construct(
        private string $host = '127.0.0.1',
        private int $port = 6379,
    ) {}

    public function evaluate(
        string $script,
        array $keys,
        array $arguments,
    ): int {
        $errorCode = 0;
        $errorMessage = '';

        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errorCode,
            $errorMessage,
            2.0,
        );

        if (! is_resource($socket)) {
            throw new RedisCommandFailed(sprintf(
                'Unable to connect to Redis at [%s:%d]: [%d] %s',
                $this->host,
                $this->port,
                $errorCode,
                $errorMessage,
            ));
        }

        try {
            stream_set_timeout($socket, 2);

            $parts = [
                'EVAL',
                $script,
                (string) count($keys),
                ...$keys,
                ...$arguments,
            ];

            $payload = '*'.count($parts)."\r\n";

            foreach ($parts as $part) {
                $payload .= '$'.strlen($part)."\r\n".$part."\r\n";
            }

            $this->writeAll($socket, $payload);

            $reply = fgets($socket);

            if ($reply === false) {
                throw new RedisCommandFailed('Redis closed the connection before returning an EVAL reply.');
            }

            $line = rtrim($reply, "\r\n");
            $type = $line[0] ?? '';
            $value = substr($line, 1);

            if ($type === '-') {
                throw new RedisCommandFailed('Redis EVAL failed: '.$value);
            }

            if ($type !== ':' || preg_match('/\A-?[0-9]+\z/', $value) !== 1) {
                throw new UnexpectedValueException(sprintf(
                    'Expected an integer Redis EVAL reply, received [%s].',
                    $line,
                ));
            }

            return (int) $value;
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param  resource  $socket
     */
    private function writeAll($socket, string $payload): void
    {
        $offset = 0;
        $length = strlen($payload);

        while ($offset < $length) {
            $written = fwrite($socket, substr($payload, $offset));

            if ($written === false || $written === 0) {
                throw new RedisCommandFailed('Unable to write the complete EVAL command to Redis.');
            }

            $offset += $written;
        }
    }
}
