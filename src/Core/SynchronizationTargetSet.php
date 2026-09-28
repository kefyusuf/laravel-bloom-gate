<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

use InvalidArgumentException;

final readonly class SynchronizationTargetSet
{
    /**
     * @var list<FilterVersion>
     */
    private array $versions;

    /**
     * @param  list<FilterVersion>  $versions
     */
    private function __construct(array $versions)
    {
        $this->versions = $versions;
    }

    /**
     * @param  array<array-key, mixed>  $versions
     */
    public static function fromVersions(array $versions): self
    {
        if (array_is_list($versions) === false) {
            throw new InvalidArgumentException('Synchronization targets must be provided as an ordered list.');
        }

        $validated = [];
        $previous = null;

        foreach ($versions as $version) {
            if ($version instanceof FilterVersion === false) {
                throw new InvalidArgumentException('Synchronization targets must contain only filter versions.');
            }

            $value = $version->value();

            if ($previous !== null && $value <= $previous) {
                throw new InvalidArgumentException('Synchronization targets must be unique and strictly ascending.');
            }

            $validated[] = $version;
            $previous = $value;
        }

        return new self($validated);
    }

    /**
     * @return list<FilterVersion>
     */
    public function versions(): array
    {
        return $this->versions;
    }

    public function isEmpty(): bool
    {
        return $this->versions === [];
    }

    public function contains(FilterVersion $version): bool
    {
        foreach ($this->versions as $target) {
            if ($target->equals($version)) {
                return true;
            }
        }

        return false;
    }

    public function equals(self $other): bool
    {
        if (count($this->versions) !== count($other->versions)) {
            return false;
        }

        foreach ($this->versions as $index => $version) {
            if ($version->equals($other->versions[$index]) === false) {
                return false;
            }
        }

        return true;
    }
}
