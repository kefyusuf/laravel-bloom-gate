<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\Membership;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Tests\Experiments\Swoole\SharedBloom;
use Kefyusuf\BloomGate\Tests\Experiments\Swoole\SharedMemoryDomain;
use Kefyusuf\BloomGate\Tests\Experiments\Swoole\SharedMemoryQuery;

if (getenv('SHARED_MEMORY_NATIVE_TESTS') !== '1') {
    it('requires the native query authorization runtime')->skip();

    return;
}

require_once __DIR__.'/fixture.php';

beforeEach(function (): void {
    // RED: the shared domain is not implemented yet. All later checks exercise native storage.
    expect(class_exists(SharedMemoryDomain::class))->toBeTrue();
});

/** @return array{SharedMemoryDomain, SharedMemoryQuery} */
function queryFixture(bool $publish = true, int $bits = 65536, int $chunkRows = 1024): array
{
    $domain = new SharedMemoryDomain($chunkRows);
    $query = new SharedMemoryQuery($domain, 'member-0000000');
    if ($publish) {
        $domain->publish([NormalizedValue::fromBytes('member-0000000')],
            BloomLayout::create($bits, min(4, $bits), ProbeAlgorithm::Sha256DoubleHashV1),
            $query->semantics(), 1, hash('sha256', "member-0000000\n"));
    }

    return [$domain, $query];
}

it('positive_and_false_positive_use_sql', function (): void {
    [$domain, $query] = queryFixture(bits: 1);
    $positive = $query->lookup('member-0000000');
    $falsePositive = $query->lookup('absent');
    expect($positive->exists())->toBeTrue();
    expect($positive->membership())->toBe(Membership::MaybePresent);
    expect($falsePositive->exists())->toBeFalse();
    expect($falsePositive->membership())->toBe(Membership::MaybePresent);
    expect($query->sqlCalls())->toBe(2);
})->group('swoole');

it('small_fixture_sql_scope_matches_published_values', function (): void {
    [$domain, $query] = queryFixture();
    expect($query->authoritativeSet()->exists(NormalizedValue::fromBytes('member-0000000')))->toBeTrue();
    expect($query->authoritativeSet()->exists(NormalizedValue::fromBytes('member-0000001')))->toBeFalse();
    expect($query->lookup('member-0000001')->exists())->toBeFalse();
})->group('swoole');

it('negative_skips_sql_only_when_sealed', function (): void {
    [$domain, $query] = queryFixture(publish: false);
    expect($query->lookup('absent')->membership())->toBe(Membership::Bypassed);
    expect($query->sqlCalls())->toBe(1);
    $domain->publish([NormalizedValue::fromBytes('member-0000000')],
        BloomLayout::create(65536, 4, ProbeAlgorithm::Sha256DoubleHashV1),
        $query->semantics(), 1, hash('sha256', "member-0000000\n"));
    expect($query->lookup('absent')->membership())->toBe(Membership::DefinitelyAbsent);
    expect($query->sqlCalls())->toBe(1);
})->group('swoole');

it('publication_during_probe_bypasses', function (): void {
    [$domain, $query] = queryFixture();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($pair === false) {
        throw new RuntimeException('Barrier creation failed.');
    }
    $pid = pcntl_fork();
    if ($pid < 0) {
        throw new RuntimeException('Probe fork failed.');
    }
    if ($pid === 0) {
        fclose($pair[0]);
        stream_set_timeout($pair[1], 10);
        $query->afterAuthorization(static function () use ($pair): void {
            fwrite($pair[1], "authorized\n");
            if (fgets($pair[1]) !== "published\n") {
                throw new RuntimeException('Publication barrier failed.');
            }
        });
        $result = $query->lookup('absent');
        fwrite($pair[1], $result->membership()->name.':'.$query->sqlCalls()."\n");
        fclose($pair[1]);
        exit(0);
    }
    fclose($pair[1]);
    stream_set_timeout($pair[0], 10);
    expect(fgets($pair[0]))->toBe("authorized\n");
    $domain->publish([NormalizedValue::fromBytes('member-0000000')],
        BloomLayout::create(65536, 4, ProbeAlgorithm::Sha256DoubleHashV1),
        $query->semantics(), 1, hash('sha256', "member-0000000\n"));
    fwrite($pair[0], "published\n");
    expect(fgets($pair[0]))->toBe("Bypassed:1\n");
    fclose($pair[0]);
    pcntl_waitpid($pid, $status);
    if (! is_int($status)) {
        throw new RuntimeException('Invalid child exit status.');
    }
    expect(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0)->toBeTrue();
    expect($query->lookup('absent')->membership())->toBe(Membership::DefinitelyAbsent);
})->group('swoole');

it('old_incarnation_bypasses', function (): void {
    [$old, $oldQuery] = queryFixture();
    [$fresh, $query] = queryFixture();
    $descriptor = $oldQuery->descriptor();
    $positions = (new BloomProbeGenerator)->generate(NormalizedValue::fromBytes('absent'), $descriptor->layout());
    expect($query->probe($descriptor, $positions)->membership())->toBe(Membership::Bypassed);
    expect($query->lookup('absent')->membership())->toBe(Membership::DefinitelyAbsent);
})->group('swoole');

it('missing_or_corrupt_chunk_bypasses', function (string $fault): void {
    [$domain, $query] = queryFixture();
    $descriptor = $query->descriptor();
    $positions = (new BloomProbeGenerator)->generate(NormalizedValue::fromBytes('absent'), $descriptor->layout());
    $key = '1:'.intdiv($positions->values()[0], 32768);
    if ($fault === 'missing') {
        $domain->chunks->del($key);
    }
    if ($fault === 'bytes') {
        $domain->chunks->set($key, ['bytes' => str_repeat("\xff", 4096)]);
    }
    if ($fault === 'length') {
        $domain->chunks->set($key, ['bytes' => '', 'digest' => hash('sha256', '')]);
    }
    expect($query->lookup('absent')->membership())->toBe(Membership::Bypassed);
    expect($query->sqlCalls())->toBe(1);
})->with(['missing', 'bytes', 'length'])->group('swoole');

it('wrong_semantics_bypasses', function (): void {
    [$domain, $query] = queryFixture();
    $query->useWrongSemantics();
    expect($query->lookup('member-0000000')->membership())->toBe(Membership::Bypassed);
    expect($query->sqlCalls())->toBe(1);
})->group('swoole');

it('corruption_after_first_unset_bit_still_bypasses', function (string $fault): void {
    [$domain, $query] = queryFixture();
    $descriptor = $query->descriptor();
    $positions = (new BloomProbeGenerator)->generate(NormalizedValue::fromBytes('absent'), $descriptor->layout())->values();
    $first = $positions[0];
    $row = $domain->chunks->get('1:'.intdiv($first, 32768));
    if (! is_array($row) || ! is_string($row['bytes'] ?? null)) {
        throw new RuntimeException('Missing first chunk.');
    }
    expect(ord($row['bytes'][intdiv($first % 32768, 8)]) & (1 << ($first % 8)))->toBe(0);
    $later = null;
    foreach (array_slice($positions, 1) as $position) {
        if (intdiv($position, 32768) !== intdiv($first, 32768)) {
            $later = intdiv($position, 32768);
            break;
        }
    }
    expect($later)->not->toBeNull();
    if ($later === null) {
        throw new RuntimeException('Probe does not touch a later distinct chunk.');
    }
    if ($fault === 'missing') {
        $domain->chunks->del('1:'.$later);
    } else {
        $domain->chunks->set('1:'.$later, ['bytes' => str_repeat("\xff", 4096)]);
    }
    expect($query->lookup('absent')->membership())->toBe(Membership::Bypassed);
    expect($query->sqlCalls())->toBe(1);
})->with(['missing', 'corrupt'])->group('swoole');

it('bad_dataset_proof_cannot_replace_active_generation', function (string $fault): void {
    [$domain, $query] = queryFixture();
    $before = $domain->control->get('active');
    expect(fn () => $domain->publish([NormalizedValue::fromBytes('member-0000000')],
        BloomLayout::create(65536, 4, ProbeAlgorithm::Sha256DoubleHashV1), $query->semantics(),
        $fault === 'count' ? 2 : 1, $fault === 'digest' ? str_repeat('0', 64) : hash('sha256', "member-0000000\n")))->toThrow(RuntimeException::class);
    expect($domain->control->get('active'))->toBe($before);
    expect($query->lookup('member-0000000')->exists())->toBeTrue();
})->with(['count', 'digest'])->group('swoole');

it('unsafe_control_and_manifest_layout_bypass_to_sql', function (string $fault): void {
    [$domain, $query] = queryFixture();
    if ($fault === 'health') {
        $domain->control->set('active', ['healthy' => 0]);
    }
    if ($fault === 'revision') {
        $domain->control->set('active', ['revision' => 2]);
    }
    if ($fault === 'manifest') {
        $domain->control->set('active', ['manifest' => str_repeat('0', 64)]);
    }
    if ($fault === 'layout') {
        $query->afterAuthorization(static function () use ($domain): void {
            $json = $domain->manifests->get('1', 'json');
            if (! is_string($json)) {
                throw new RuntimeException('Missing manifest JSON.');
            }
            $json = str_replace('"bits":65536', '"bits":1', $json);
            $domain->manifests->set('1', ['json' => $json, 'digest' => hash('sha256', $json)]);
        });
    }
    expect($query->lookup('member-0000000')->membership())->toBe(Membership::Bypassed);
    expect($query->sqlCalls())->toBe(1);
})->with(['health', 'revision', 'manifest', 'layout'])->group('swoole');

it('failed_table_write_cannot_publish', function (bool $replacement): void {
    [$domain, $query] = queryFixture(publish: $replacement, bits: 1, chunkRows: 1);
    $before = $domain->control->get('active');
    // Exhaust real native capacity, rather than substituting a fake Table.
    set_error_handler(static fn (): bool => true);
    try {
        for ($i = 0; $i < 1000; $i++) {
            try {
                $domain->chunks->set('occupied:'.$i, ['bytes' => 'x']);
            } catch (Exception) { /* Native capacity failures are the injected fault. */
            }
        }
        expect(fn () => $domain->publish([NormalizedValue::fromBytes('member-0000000')],
            BloomLayout::create(65536, 4, ProbeAlgorithm::Sha256DoubleHashV1),
            $query->semantics(), 1, hash('sha256', "member-0000000\n")))->toThrow(RuntimeException::class);
    } finally {
        restore_error_handler();
    }
    expect($domain->control->get('active'))->toBe($before);
    expect($query->lookup('absent')->membership())->toBe($replacement ? Membership::MaybePresent : Membership::Bypassed);
    expect($query->sqlCalls())->toBe(1);
    expect($query->lookup('member-0000000')->exists())->toBeTrue();
})->with([false, true])->group('swoole');

it('third_generation_is_rejected', function (): void {
    [$domain, $query] = queryFixture();
    $values = [NormalizedValue::fromBytes('member-0000000')];
    $layout = BloomLayout::create(65536, 4, ProbeAlgorithm::Sha256DoubleHashV1);
    $domain->publish($values, $layout, $query->semantics(), 1, hash('sha256', "member-0000000\n"));
    $before = $domain->control->get('active');
    expect(fn () => $domain->publish($values, $layout, $query->semantics(), 1, hash('sha256', "member-0000000\n")))->toThrow(LogicException::class);
    expect($domain->control->get('active'))->toBe($before);
    expect($domain->chunks->exists('1:0'))->toBeTrue();
    expect($domain->chunks->exists('2:0'))->toBeTrue();
    expect($query->lookup('member-0000000')->exists())->toBeTrue();
})->group('swoole');

it('present_keys_never_return_definitely_absent', function (): void {
    $domain = new SharedMemoryDomain;
    $query = new SharedMemoryQuery($domain);
    $query->publishDataset();
    $descriptor = $query->descriptor();
    $generator = new BloomProbeGenerator;
    for ($i = 0; $i < 1000000; $i++) {
        $key = 'member-'.str_pad((string) $i, 7, '0', STR_PAD_LEFT);
        $result = $query->probe($descriptor, $generator->generate(NormalizedValue::fromBytes($key), $descriptor->layout()));
        if ($result->membership() !== Membership::MaybePresent) {
            throw new RuntimeException('Seeded key failed authorized membership: '.$key);
        }
    }
    expect($i)->toBe(1000000);
    expect($query->sqlCalls())->toBe(0);
})->group('swoole');

it('two_full_generations_retain_verified_storage', function (): void {
    $domain = new SharedMemoryDomain;
    $query = new SharedMemoryQuery($domain);
    $query->publishDataset();
    $old = $query->descriptor();
    $positions = (new BloomProbeGenerator)->generate(NormalizedValue::fromBytes('member-0000000'), $old->layout());
    $query->publishDataset();
    expect($domain->chunks->exists('1:488'))->toBeTrue();
    expect($domain->chunks->exists('2:488'))->toBeTrue();
    expect((new SharedBloom($domain))->mightContain($old->filterName(), $old->activeVersion(), $positions))->toBeTrue();
    expect($query->probe($old, $positions)->membership())->toBe(Membership::Bypassed);
    expect($query->lookup('member-0999999')->exists())->toBeTrue();
    expect($query->sqlCalls())->toBe(1);
})->group('swoole');
