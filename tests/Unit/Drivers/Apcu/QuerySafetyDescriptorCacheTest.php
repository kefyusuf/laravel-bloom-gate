<?php

declare(strict_types=1);

require_once __DIR__.'/../../../Support/Application/Task13QueryGateFixtures.php';

use Kefyusuf\BloomGate\Core\AuthorizedProbeResult;
use Kefyusuf\BloomGate\Core\FilterVersion;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\NormalizationFingerprint;
use Kefyusuf\BloomGate\Core\QuerySafetyDescriptor;
use Kefyusuf\BloomGate\Drivers\Apcu\ApcuQuerySafetyDescriptorCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Kefyusuf\BloomGate\Tests\Support\Application\task13Fixture;

final class QuerySafetyDescriptorCacheTest extends TestCase
{
    /** @var list<string> */
    private array $existingKeys = [];

    private QuerySafetyDescriptor $descriptor;

    private ApcuQuerySafetyDescriptorCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            $this->existingKeys = $this->descriptorKeys();
        }
        $fixture = task13Fixture(AuthorizedProbeResult::definitelyAbsent());
        $managed = $fixture->contracts->read($fixture->name, FilterVersion::fromInt(4));
        self::assertNotNull($managed);
        $descriptor = $fixture->resolver->resolve($fixture->name, $managed->semanticContract())->descriptor();
        self::assertNotNull($descriptor);
        $this->descriptor = $descriptor;
        $entropy = random_bytes(12);
        self::assertIsString($entropy);
        $this->cache = new ApcuQuerySafetyDescriptorCache('test-'.bin2hex($entropy), 60);
    }

    protected function tearDown(): void
    {
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            foreach (array_diff($this->descriptorKeys(), $this->existingKeys) as $key) {
                apcu_delete($key);
            }
        }
        parent::tearDown();
    }

    public function test_descriptor_reuse_and_namespace_isolation(): void
    {
        $this->requireEnabledApcu();
        $entropy = random_bytes(12);
        self::assertIsString($entropy);
        $namespace = 'shared-'.bin2hex($entropy);
        $first = new ApcuQuerySafetyDescriptorCache($namespace, 60);
        $second = new ApcuQuerySafetyDescriptorCache($namespace, 60);
        $other = new ApcuQuerySafetyDescriptorCache($namespace.'-other', 60);
        $descriptor = $this->descriptor;
        $first->put($descriptor);
        $found = $second->get($descriptor->filterName(), $descriptor->semanticContract());
        self::assertNotNull($found);
        self::assertSame(9, $found->revision()->value());
        self::assertTrue($found->layout()->equals($descriptor->layout()));
        self::assertNull($other->get($descriptor->filterName(), $descriptor->semanticContract()));
    }

    public function test_changed_semantics_are_a_cache_miss(): void
    {
        $this->requireEnabledApcu();
        $descriptor = $this->descriptor;
        $this->cache->put($descriptor);
        $contract = $descriptor->semanticContract();
        $changed = new GenerationSemanticContract(
            NormalizationFingerprint::fromString('sha256:'.str_repeat('a', 64)),
            $contract->authoritativeSetFingerprint(),
            $contract->consistencyFingerprint(),
        );
        self::assertNull($this->cache->get($descriptor->filterName(), $changed));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function malformedPayloads(): iterable
    {
        yield 'format' => ['format', 'future-v2'];
        yield 'filter' => ['filter', 'other.filter'];
        yield 'revision' => ['revision', 0];
        yield 'version' => ['version', '1'];
        yield 'bits' => ['bits', -1];
        yield 'hashes' => ['hashes', 65];
        yield 'algorithm' => ['algorithm', 'unknown'];
        yield 'normalization' => ['normalization', 'invalid'];
        yield 'authoritative' => ['authoritative', null];
        yield 'consistency' => ['consistency', []];
    }

    #[DataProvider('malformedPayloads')]
    public function test_malformed_payload_is_a_cache_miss(string $field, mixed $value): void
    {
        $this->requireEnabledApcu();
        $descriptor = $this->descriptor;
        $this->cache->put($descriptor);
        $keys = array_values(array_diff($this->descriptorKeys(), $this->existingKeys));
        self::assertCount(1, $keys);
        $payload = apcu_fetch($keys[0]);
        self::assertIsArray($payload);
        $payload[$field] = $value;
        apcu_store($keys[0], $payload);
        self::assertNull($this->cache->get($descriptor->filterName(), $descriptor->semanticContract()));
    }

    public function test_forget_evicts_the_requested_descriptor(): void
    {
        $this->requireEnabledApcu();
        $descriptor = $this->descriptor;
        $this->cache->put($descriptor);
        $this->cache->forget($descriptor->filterName());
        self::assertNull($this->cache->get($descriptor->filterName(), $descriptor->semanticContract()));
    }

    public function test_disabled_apcu_gets_miss_and_writes_are_noops(): void
    {
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            self::markTestSkipped('Run with apc.enable_cli=0 to verify disabled APCu fallback.');
        }
        $descriptor = $this->descriptor;
        self::assertNull($this->cache->get($descriptor->filterName(), $descriptor->semanticContract()));
        $this->cache->put($descriptor);
        self::assertNull($this->cache->get($descriptor->filterName(), $descriptor->semanticContract()));
        $this->cache->forget($descriptor->filterName());
        self::assertNull($this->cache->get($descriptor->filterName(), $descriptor->semanticContract()));
    }

    private function requireEnabledApcu(): void
    {
        if (! function_exists('apcu_enabled') || ! apcu_enabled()) {
            self::markTestSkipped('Requires enabled APCu; run PHP with apc.enable_cli=1.');
        }
    }

    /** @return list<string> */
    private function descriptorKeys(): array
    {
        $keys = [];
        foreach (new APCUIterator('/^lbg:query-descriptor:v1:/') as $key => $entry) {
            $keys[] = $key;
        }

        return $keys;
    }
}
