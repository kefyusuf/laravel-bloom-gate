<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Experiments\Swoole;

use Closure;

/** Fixture-only response release at an absolute monotonic deadline. */
final class GeneratorDeadline
{
    /**
     * @param  Closure(): int  $now  Nanoseconds from a monotonic clock.
     * @param  Closure(int, Closure(): void): void  $schedule  Nonblocking timer; handles allocation failure without releasing the response.
     * @param  Closure(): void  $finish
     */
    public static function after(int $milliseconds, Closure $now, Closure $schedule, Closure $finish): void
    {
        $deadline = $now() + $milliseconds * 1000000;
        self::arm($deadline, $now, $schedule, $finish);
    }

    /**
     * @param  Closure(): int  $now
     * @param  Closure(int, Closure(): void): void  $schedule
     * @param  Closure(): void  $finish
     */
    private static function arm(int $deadline, Closure $now, Closure $schedule, Closure $finish): void
    {
        $remaining = $deadline - $now();
        if ($remaining > 0) {
            $schedule((int) ceil($remaining / 1000000), static function () use ($deadline, $now, $schedule, $finish): void {
                self::arm($deadline, $now, $schedule, $finish);
            });

            return;
        }
        $finish();
    }
}
