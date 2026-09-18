<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

use InvalidArgumentException;

final readonly class BitPositions
{
    private BloomLayout $layout;

    /**
     * @var list<int>
     */
    private array $positions;

    /**
     * @param  list<int>  $positions
     */
    private function __construct(BloomLayout $layout, array $positions)
    {
        $this->layout = $layout;
        $this->positions = $positions;
    }

    /**
     * @param  array<array-key, mixed>  $positions
     */
    public static function forLayout(BloomLayout $layout, array $positions): self
    {
        if (array_is_list($positions) === false) {
            throw new InvalidArgumentException('Bloom bit positions must be provided as an ordered list.');
        }

        if (count($positions) !== $layout->hashCount()) {
            throw new InvalidArgumentException('Bloom bit position count must equal the layout hash count.');
        }

        $validatedPositions = [];

        foreach ($positions as $position) {
            if (is_int($position) === false) {
                throw new InvalidArgumentException('Bloom bit positions must be integers.');
            }

            if ($position < 0 || $position >= $layout->bitCount()) {
                throw new InvalidArgumentException('Bloom bit position is outside the layout bit range.');
            }

            $validatedPositions[] = $position;
        }

        return new self($layout, $validatedPositions);
    }

    public function layout(): BloomLayout
    {
        return $this->layout;
    }

    /**
     * @return list<int>
     */
    public function values(): array
    {
        return $this->positions;
    }

    public function count(): int
    {
        return count($this->positions);
    }

    public function equals(self $other): bool
    {
        return $this->layout->equals($other->layout)
            && $this->positions === $other->positions;
    }
}
