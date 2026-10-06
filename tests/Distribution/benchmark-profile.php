<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\Redis\RedisStructuredCommandExecutor;
use Kefyusuf\BloomGate\Drivers\Redis\RedisGenerationContractScripts;
use Kefyusuf\BloomGate\Drivers\Redis\RedisQuerySafetyScripts;

/** A fixture-only decorator; the actual production scripts still execute. */
final class BenchmarkProfileExecutor implements RedisStructuredCommandExecutor
{
    /** @var array<string, string> */
    private array $groups = [];

    /** @var array<string, string> */
    private array $scriptHashes = [];

    /** @var array<string, array{calls: int, wall_ns: int, logical_script_bytes: int}> */
    private array $totals = [];

    /** @var array<string, array{script: string, keys: list<string>, arguments: list<string>}> */
    private array $captured = [];

    public function __construct(private readonly RedisStructuredCommandExecutor $inner)
    {
        foreach ([
            'snapshot' => RedisQuerySafetyScripts::readActive(),
            'contract' => RedisGenerationContractScripts::read(),
            'probe' => RedisQuerySafetyScripts::authorizedProbe(),
        ] as $group => $script) {
            $this->groups[$script] = $group;
            $this->scriptHashes[$group] = hash('sha256', $script);
        }
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $arguments
     */
    public function evaluate(string $script, array $keys, array $arguments): int
    {
        $started = hrtime(true);
        try {
            $result = $this->inner->evaluate($script, $keys, $arguments);
        } finally {
            $this->record($script, hrtime(true) - $started);
        }
        $this->capture($script, $keys, $arguments);

        return $result;
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public function evaluateStructured(string $script, array $keys, array $arguments): array
    {
        $started = hrtime(true);
        try {
            $result = $this->inner->evaluateStructured($script, $keys, $arguments);
        } finally {
            $this->record($script, hrtime(true) - $started);
        }
        $this->capture($script, $keys, $arguments);

        return $result;
    }

    public function reset(): void
    {
        $this->totals = [];
    }

    /** @return array{calls: int, wall_ns: int, logical_script_bytes: int, groups: array<string, array{calls: int, wall_ns: int, logical_script_bytes: int}>, script_sha256: array<string, string>} */
    public function snapshot(): array
    {
        $calls = 0;
        $wall = 0;
        $bytes = 0;
        foreach ($this->totals as $group) {
            $calls += $group['calls'];
            $wall += $group['wall_ns'];
            $bytes += $group['logical_script_bytes'];
        }

        return ['calls' => $calls, 'wall_ns' => $wall, 'logical_script_bytes' => $bytes,
            'groups' => $this->totals, 'script_sha256' => $this->scriptHashes];
    }

    /** @return array<string, array{script: string, keys: list<string>, arguments: list<string>}> */
    public function capturedCalls(): array
    {
        return $this->captured;
    }

    private function record(string $script, int $elapsed): void
    {
        $group = $this->groups[$script] ?? 'other';
        $totals = $this->totals[$group] ?? ['calls' => 0, 'wall_ns' => 0, 'logical_script_bytes' => 0];
        $this->totals[$group] = ['calls' => $totals['calls'] + 1,
            'wall_ns' => $totals['wall_ns'] + $elapsed,
            'logical_script_bytes' => $totals['logical_script_bytes'] + strlen($script)];
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $arguments
     */
    private function capture(string $script, array $keys, array $arguments): void
    {
        $group = $this->groups[$script] ?? 'other';
        // Only these three production query scripts are readonly; keep one fixture replay per group.
        if ($group !== 'other') {
            $this->captured[$group] = ['script' => $script, 'keys' => $keys, 'arguments' => $arguments];
        }
    }
}
