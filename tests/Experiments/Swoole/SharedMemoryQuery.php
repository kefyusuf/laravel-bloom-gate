<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Experiments\Swoole;

use Closure;
use Kefyusuf\BloomGate\Application\ExistenceResult;
use Kefyusuf\BloomGate\Application\QueryGate;
use Kefyusuf\BloomGate\Application\QuerySafetyDescriptorResolver;
use Kefyusuf\BloomGate\Contracts\AuthoritativeSet;
use Kefyusuf\BloomGate\Contracts\Exception\UnknownFilter;
use Kefyusuf\BloomGate\Contracts\FilterDefinition;
use Kefyusuf\BloomGate\Contracts\FilterRegistry;
use Kefyusuf\BloomGate\Contracts\RegisteredFilter;
use Kefyusuf\BloomGate\Contracts\ValueNormalizer;
use Kefyusuf\BloomGate\Core\AuthoritativeSetIdentity;
use Kefyusuf\BloomGate\Core\AuthorizedProbeResult;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\ConsistencyContract;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\NormalizationIdentity;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Core\QuerySafetyDescriptor;
use Kefyusuf\BloomGate\Core\SemanticFingerprintCalculator;
use PDO;
use RuntimeException;

final class SharedNormalizer implements ValueNormalizer
{
    public bool $wrong = false;

    public function identity(): NormalizationIdentity
    {
        return NormalizationIdentity::fromString($this->wrong ? 'shared-demo-wrong@1' : 'shared-demo-exact@1');
    }

    public function normalize(string|int $value): NormalizedValue
    {
        return NormalizedValue::fromBytes((string) $value);
    }
}

final class SharedSqlSet implements AuthoritativeSet
{
    public int $calls = 0;

    public function __construct(private readonly ?string $scope = null, private readonly ?PDO $connection = null) {}

    public function identity(): AuthoritativeSetIdentity
    {
        return AuthoritativeSetIdentity::fromString('shared-demo-mysql-members-'.($this->scope === null ? 'all' : hash('sha256', $this->scope)).'@1');
    }

    public static function database(): PDO
    {
        return new PDO('mysql:host='.getenv('DEMO_DB_HOST').';dbname=demo',
            (string) getenv('DEMO_DB_USERNAME'), (string) getenv('DEMO_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]);
    }

    public function exists(NormalizedValue $value): bool
    {
        $this->calls++;
        $statement = ($this->connection ?? self::database())->prepare('SELECT 1 FROM members WHERE member_key = ?'.($this->scope === null ? '' : ' AND member_key = ?').' LIMIT 1');
        if ($statement === false) {
            throw new RuntimeException('SQL prepare failed.');
        }
        $statement->execute($this->scope === null ? [$value->bytes()] : [$value->bytes(), $this->scope]);

        return $statement->fetchColumn() !== false;
    }

    public function values(): iterable
    {
        $database = self::database();
        $statement = $database->prepare('SELECT member_key FROM members'.($this->scope === null ? '' : ' WHERE member_key = ?').' ORDER BY member_key');
        if ($statement === false) {
            throw new RuntimeException('SQL enumeration failed.');
        }
        $statement->execute($this->scope === null ? [] : [$this->scope]);
        while (($value = $statement->fetchColumn()) !== false) {
            if (! is_string($value)) {
                throw new RuntimeException('Invalid membership bytes.');
            }
            yield $value;
        }
        $statement->closeCursor();
    }
}

/** Uses the package's QueryGate and resolver; no fixture membership shortcut. */
final class SharedMemoryQuery implements FilterDefinition, FilterRegistry
{
    private readonly SharedNormalizer $normalization;

    private readonly SharedSqlSet $set;

    private readonly SharedProbe $authorized;

    private readonly QuerySafetyDescriptorResolver $resolver;

    private readonly QueryGate $gate;

    public function __construct(private readonly SharedMemoryDomain $domain, ?string $sqlScope = null, ?SharedSqlSet $set = null)
    {
        $this->normalization = new SharedNormalizer;
        $this->set = $set ?? new SharedSqlSet($sqlScope);
        $this->authorized = new SharedProbe($domain);
        $this->resolver = new QuerySafetyDescriptorResolver(new SharedSnapshots($domain), new SharedContracts($domain));
        $this->gate = new QueryGate($this, $this->resolver, $this->authorized, new BloomProbeGenerator, new SemanticFingerprintCalculator);
    }

    public function globalQueryOptimizationEnabled(): bool
    {
        return true;
    }

    public function get(FilterName $name): RegisteredFilter
    {
        if (! $name->equals($this->domain->name)) {
            throw new UnknownFilter('Wrong fixture filter.');
        }

        return new RegisteredFilter($name, $this, true, 1000000, 0.001);
    }

    public function normalizer(): ValueNormalizer
    {
        return $this->normalization;
    }

    public function authoritativeSet(): AuthoritativeSet
    {
        return $this->set;
    }

    public function consistency(): ConsistencyContract
    {
        return ConsistencyContract::ImmutableV1;
    }

    public function semantics(): GenerationSemanticContract
    {
        $calculator = new SemanticFingerprintCalculator;

        return new GenerationSemanticContract($calculator->normalization($this->normalization->identity()),
            $calculator->authoritativeSet($this->set->identity()), $calculator->consistency($this->consistency()));
    }

    public function lookup(string $key): ExistenceResult
    {
        return $this->gate->existsResult($this->domain->name->value(), $key);
    }

    public function sqlCalls(): int
    {
        return $this->set->calls;
    }

    public function useWrongSemantics(): void
    {
        $this->normalization->wrong = true;
    }

    public function afterAuthorization(Closure $barrier): void
    {
        $this->authorized->afterAuthorization = $barrier;
    }

    public function descriptor(): QuerySafetyDescriptor
    {
        return $this->resolver->resolve($this->domain->name, $this->semantics())->descriptor()
            ?? throw new RuntimeException('No authorized generation descriptor.');
    }

    public function probe(QuerySafetyDescriptor $descriptor, BitPositions $positions): AuthorizedProbeResult
    {
        return $this->authorized->probe($descriptor, $positions);
    }

    public function publishDataset(): void
    {
        $database = SharedSqlSet::database();
        $seal = $database->query('SELECT @@global.read_only, @@global.super_read_only');
        if ($seal === false || $seal->fetch(PDO::FETCH_NUM) !== [1, 1]) {
            throw new RuntimeException('SQL dataset is not sealed.');
        }
        $seal->closeCursor();
        $database = null;
        $values = (function (): \Generator {
            foreach ($this->set->values() as $value) {
                yield $this->normalization->normalize($value);
            }
        })();
        $this->domain->publish($values, BloomLayout::create(16000000, 10, ProbeAlgorithm::Sha256DoubleHashV1),
            $this->semantics(), 1000000, '9ee8cb5c216392aacf242289162d07bc7292f5c06ffa373a51d4f7d1e10fae00');
    }
}
