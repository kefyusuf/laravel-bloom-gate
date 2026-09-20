<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Support\Redis;

use Kefyusuf\BloomGate\Contracts\Redis\Exception\RedisCommandFailed;
use Kefyusuf\BloomGate\Contracts\Redis\RedisStructuredCommandExecutor;
use UnexpectedValueException;

final readonly class RespRedisCommandExecutor implements RedisStructuredCommandExecutor
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
        $result = $this->executeEval($script, $keys, $arguments);

        if (! is_int($result)) {
            throw new UnexpectedValueException(sprintf(
                'Expected an integer Redis EVAL reply, received [%s].',
                get_debug_type($result),
            ));
        }

        return $result;
    }

    public function evaluateStructured(
        string $script,
        array $keys,
        array $arguments,
    ): array {
        $result = $this->executeEval($script, $keys, $arguments);

        if (! is_array($result) || array_is_list($result) === false) {
            throw new UnexpectedValueException(sprintf(
                'Expected a structured Redis EVAL list reply, received [%s].',
                get_debug_type($result),
            ));
        }

        $structured = [];

        foreach ($result as $index => $value) {
            if (! is_string($value)) {
                throw new UnexpectedValueException(sprintf(
                    'Expected structured Redis EVAL item [%d] to be string, received [%s].',
                    $index,
                    get_debug_type($value),
                ));
            }

            $structured[] = $value;
        }

        return $structured;
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $arguments
     */
    private function executeEval(
        string $script,
        array $keys,
        array $arguments,
    ): mixed {
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

            return $this->readReply($socket);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param  resource  $socket
     */
    private function readReply($socket): mixed
    {
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

        if ($type === ':') {
            if (preg_match('/\A-?[0-9]+\z/', $value) !== 1) {
                throw new UnexpectedValueException(sprintf(
                    'Malformed integer Redis reply [%s].',
                    $line,
                ));
            }

            return (int) $value;
        }

        if ($type === '+') {
            return $value;
        }

        if ($type === '$') {
            if (preg_match('/\A-?[0-9]+\z/', $value) !== 1) {
                throw new UnexpectedValueException(sprintf(
                    'Malformed bulk-string Redis reply [%s].',
                    $line,
                ));
            }

            $length = (int) $value;

            if ($length === -1) {
                return null;
            }

            if ($length < 0) {
                throw new UnexpectedValueException(sprintf(
                    'Invalid bulk-string Redis length [%d].',
                    $length,
                ));
            }

            $payload = $this->readExact($socket, $length);
            $terminator = $this->readExact($socket, 2);

            if ($terminator !== "\r\n") {
                throw new UnexpectedValueException('Malformed Redis bulk-string terminator.');
            }

            return $payload;
        }

        if ($type === '*') {
            if (preg_match('/\A-?[0-9]+\z/', $value) !== 1) {
                throw new UnexpectedValueException(sprintf(
                    'Malformed array Redis reply [%s].',
                    $line,
                ));
            }

            $count = (int) $value;

            if ($count === -1) {
                return null;
            }

            if ($count < 0) {
                throw new UnexpectedValueException(sprintf(
                    'Invalid array Redis length [%d].',
                    $count,
                ));
            }

            $items = [];

            for ($index = 0; $index < $count; $index++) {
                $items[] = $this->readReply($socket);
            }

            return $items;
        }

        throw new UnexpectedValueException(sprintf(
            'Unsupported Redis reply [%s].',
            $line,
        ));
    }

    /**
     * @param  resource  $socket
     */
    private function readExact($socket, int $length): string
    {
        $result = '';

        while (strlen($result) < $length) {
            $chunk = fread(
                $socket,
                max(1, $length - strlen($result)),
            );

            if ($chunk === false || $chunk === '') {
                throw new RedisCommandFailed('Redis closed the connection during a reply payload.');
            }

            $result .= $chunk;
        }

        return $result;
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
