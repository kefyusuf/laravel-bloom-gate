<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Drivers\Apcu;

use InvalidArgumentException;
use Kefyusuf\BloomGate\Contracts\QuerySafetyDescriptorCache;
use Kefyusuf\BloomGate\Core\AuthoritativeSetFingerprint;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\ConsistencyFingerprint;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\FilterStateRevision;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\NormalizationFingerprint;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Core\QuerySafetyDescriptor;
use Throwable;

final readonly class ApcuQuerySafetyDescriptorCache implements QuerySafetyDescriptorCache
{
    public function __construct(private string $namespace, private int $ttl = 60)
    {
        if (trim($namespace) === '' || $ttl < 1 || $ttl > 86400) {
            throw new InvalidArgumentException('Descriptor cache requires a namespace and a TTL between 1 and 86400 seconds.');
        }
    }

    public function get(FilterName $name, GenerationSemanticContract $expectedContract): ?QuerySafetyDescriptor
    {
        if (! $this->available()) {
            return null;
        }

        try {
            $payload = apcu_fetch($this->key($name));

            if (! is_array($payload) || ($payload['format'] ?? null) !== 'descriptor-v1') {
                return null;
            }

            foreach (['filter', 'algorithm', 'normalization', 'authoritative', 'consistency'] as $field) {
                if (! is_string($payload[$field] ?? null)) {
                    return null;
                }
            }

            foreach (['revision', 'version', 'bits', 'hashes'] as $field) {
                if (! is_int($payload[$field] ?? null)) {
                    return null;
                }
            }

            $contract = new GenerationSemanticContract(
                NormalizationFingerprint::fromString($payload['normalization']),
                AuthoritativeSetFingerprint::fromString($payload['authoritative']),
                ConsistencyFingerprint::fromString($payload['consistency']),
            );
            $filter = FilterName::fromString($payload['filter']);

            if (! $filter->equals($name) || ! $contract->equals($expectedContract)) {
                return null;
            }

            return new QuerySafetyDescriptor(
                $filter,
                FilterStateRevision::fromInt($payload['revision']),
                FilterVersion::fromInt($payload['version']),
                BloomLayout::create($payload['bits'], $payload['hashes'], ProbeAlgorithm::from($payload['algorithm'])),
                $contract,
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function put(QuerySafetyDescriptor $descriptor): void
    {
        if (! $this->available()) {
            return;
        }

        $contract = $descriptor->semanticContract();

        try {
            apcu_store($this->key($descriptor->filterName()), [
                'format' => 'descriptor-v1',
                'filter' => $descriptor->filterName()->value(),
                'revision' => $descriptor->revision()->value(),
                'version' => $descriptor->activeVersion()->value(),
                'bits' => $descriptor->layout()->bitCount(),
                'hashes' => $descriptor->layout()->hashCount(),
                'algorithm' => $descriptor->layout()->probeAlgorithm()->value,
                'normalization' => $contract->normalizationFingerprint()->value(),
                'authoritative' => $contract->authoritativeSetFingerprint()->value(),
                'consistency' => $contract->consistencyFingerprint()->value(),
            ], $this->ttl);
        } catch (Throwable) {
            // Optional hints must not interfere with authorized lookups.
        }
    }

    public function forget(FilterName $name): void
    {
        if ($this->available()) {
            try {
                apcu_delete($this->key($name));
            } catch (Throwable) {
                // Live Redis authorization remains the source of safety.
            }
        }
    }

    private function available(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    private function key(FilterName $name): string
    {
        return 'lbg:query-descriptor:v1:'.hash('sha256', $this->namespace).':'.$name->value();
    }
}
