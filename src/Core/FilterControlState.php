<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Core;

use InvalidArgumentException;

final readonly class FilterControlState
{
    /**
     * @var list<GenerationControlState>
     */
    private array $generations;

    /**
     * @param  list<GenerationControlState>  $generations
     */
    public function __construct(
        private FilterName $filterName,
        private FilterStateRevision $revision,
        private FilterVersion $lastAllocatedVersion,
        private ?FilterVersion $activeVersion,
        private ?FilterVersion $candidateVersion,
        array $generations,
    ) {
        if (
            $this->activeVersion !== null
            && $this->candidateVersion !== null
            && $this->activeVersion->equals($this->candidateVersion)
        ) {
            throw new InvalidArgumentException('Active and candidate generations must be different.');
        }

        /** @var array<int, GenerationControlState> $byVersion */
        $byVersion = [];

        foreach ($generations as $generation) {
            $version = $generation->version()->value();

            if ($version > $this->lastAllocatedVersion->value()) {
                throw new InvalidArgumentException('Tracked generation cannot exceed the last allocated version.');
            }

            if (isset($byVersion[$version])) {
                throw new InvalidArgumentException('Tracked generation versions must be unique.');
            }

            $byVersion[$version] = $generation;
        }

        if ($this->activeVersion !== null) {
            $active = $byVersion[$this->activeVersion->value()] ?? null;

            if ($active === null) {
                throw new InvalidArgumentException('Active generation must be tracked.');
            }

            if ($active->lifecycle() !== LifecycleState::Active) {
                throw new InvalidArgumentException('Active generation pointer must reference an ACTIVE lifecycle.');
            }
        }

        if ($this->candidateVersion !== null) {
            $candidate = $byVersion[$this->candidateVersion->value()] ?? null;

            if ($candidate === null) {
                throw new InvalidArgumentException('Candidate generation must be tracked.');
            }

            if (
                $candidate->lifecycle() === LifecycleState::Active
                || $candidate->lifecycle() === LifecycleState::Retired
            ) {
                throw new InvalidArgumentException('Candidate generation cannot be ACTIVE or RETIRED.');
            }
        }

        $this->generations = array_values($generations);
    }

    public function filterName(): FilterName
    {
        return $this->filterName;
    }

    public function revision(): FilterStateRevision
    {
        return $this->revision;
    }

    public function lastAllocatedVersion(): FilterVersion
    {
        return $this->lastAllocatedVersion;
    }

    public function activeVersion(): ?FilterVersion
    {
        return $this->activeVersion;
    }

    public function candidateVersion(): ?FilterVersion
    {
        return $this->candidateVersion;
    }

    /**
     * @return list<GenerationControlState>
     */
    public function generations(): array
    {
        return $this->generations;
    }
}
