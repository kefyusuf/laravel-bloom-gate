<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Experiments\Swoole;

use InvalidArgumentException;

/** Admits observation coverage only, never generator capacity. */
final class GeneratorObservation
{
    /** @return array{verdict:string,reasons:list<string>} */
    public static function evaluate(mixed $timeline, mixed $cgroup, mixed $start, mixed $end): array
    {
        try {
            if ((! is_int($start) && ! is_float($start)) || (! is_int($end) && ! is_float($end))
                || ! is_finite((float) $start) || ! is_finite((float) $end) || $start < 0 || $end <= $start
                || ! is_array($timeline) || ! array_is_list($timeline)) {
                throw new InvalidArgumentException('A finite measurement window and timeline are required.');
            }
            $times = [];
            foreach ($timeline as $row) {
                if (! is_array($row) || ! is_int($row['epoch_ms'] ?? null)
                    || ! in_array($row['status'] ?? null, ['observed', 'unavailable'], true)) {
                    throw new InvalidArgumentException('Malformed observation.');
                }
                $finished = $row['observation_end_epoch_ms'] ?? $row['epoch_ms'];
                if (! is_int($finished) || $finished < $row['epoch_ms']) {
                    throw new InvalidArgumentException('Malformed observation interval.');
                }
                if ($row['status'] === 'unavailable' && $finished >= $start && $row['epoch_ms'] < $end) {
                    throw new InvalidArgumentException('An observation was unavailable during measurement.');
                }
                if ($row['status'] === 'observed') {
                    $times[] = $row['epoch_ms'];
                }
            }
            self::covers($times, (float) $start, (float) $end);
            self::covers($cgroup, (float) $start, (float) $end);

            return ['verdict' => 'OBSERVED', 'reasons' => []];
        } catch (InvalidArgumentException $failure) {
            return ['verdict' => 'INCONCLUSIVE', 'reasons' => [$failure->getMessage()]];
        }
    }

    private static function covers(mixed $times, float $start, float $end): void
    {
        if (! is_array($times) || ! array_is_list($times)) {
            throw new InvalidArgumentException('Observation timestamps are required.');
        }
        $window = [];
        foreach ($times as $time) {
            if (! is_int($time) || $time < 0) {
                throw new InvalidArgumentException('Malformed observation timestamp.');
            }
            if ($time >= $start - 2000 && $time <= $end + 2000) {
                $window[] = $time;
            }
        }
        $window = array_values(array_unique($window));
        sort($window);
        if (count($window) < 3) {
            throw new InvalidArgumentException('At least three distinct observations must cover measurement.');
        }
        $previous = $start;
        foreach ($window as $time) {
            if ($time < $start) {
                continue;
            }
            $current = min($end, $time);
            if ($current - $previous > 2000) {
                throw new InvalidArgumentException('Observation gap exceeds two seconds.');
            }
            $previous = $current;
        }
        if ($end - $previous > 2000) {
            throw new InvalidArgumentException('Observations do not cover the measurement end.');
        }
    }
}
