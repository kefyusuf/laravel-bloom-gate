<?php

declare(strict_types=1);

namespace Kefyusuf\BloomGate\Tests\Experiments\Swoole;

use Kefyusuf\BloomGate\Contracts\Exception\BloomStorageCorrupt;
use Kefyusuf\BloomGate\Core\AuthoritativeSetFingerprint;
use Kefyusuf\BloomGate\Core\BitPositions;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\BloomProbeGenerator;
use Kefyusuf\BloomGate\Core\ConsistencyFingerprint;
use Kefyusuf\BloomGate\Core\FilterName;
use Kefyusuf\BloomGate\Core\GenerationSemanticContract;
use Kefyusuf\BloomGate\Core\ManagedGenerationDescriptor;
use Kefyusuf\BloomGate\Core\NormalizationFingerprint;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use LogicException;
use RuntimeException;
use Swoole\Table;

/** Fixture-only immutable storage. Public tables are solely for native fault injection. */
final class SharedMemoryDomain
{
    public readonly Table $chunks;

    public readonly Table $control;

    public readonly Table $manifests;

    public readonly FilterName $name;

    private readonly int $publisherPid;

    private readonly string $incarnation;

    private int $allocated = 0;

    public function __construct(int $chunkRows = 1024)
    {
        /** @var non-empty-string $entropy */
        $entropy = random_bytes(16);
        $this->incarnation = bin2hex($entropy);
        $this->name = FilterName::fromString('demo.'.$this->incarnation.'.members');
        $pid = getmypid();
        if ($pid === false) {
            throw new RuntimeException('Publisher PID is unavailable.');
        }
        $this->publisherPid = $pid;
        $this->chunks = new Table($chunkRows);
        $this->chunks->column('bytes', Table::TYPE_STRING, 4096);
        $this->chunks->column('digest', Table::TYPE_STRING, 64);
        $this->control = new Table(16);
        foreach (['version', 'revision', 'healthy'] as $field) {
            $this->control->column($field, Table::TYPE_INT);
        }
        $this->control->column('incarnation', Table::TYPE_STRING, 32);
        $this->control->column('manifest', Table::TYPE_STRING, 64);
        $this->manifests = new Table(16);
        $this->manifests->column('json', Table::TYPE_STRING, 4096);
        $this->manifests->column('digest', Table::TYPE_STRING, 64);
        if (! $this->chunks->create() || ! $this->control->create() || ! $this->manifests->create()
            || ! $this->control->set('active', ['version' => 0, 'revision' => 0, 'healthy' => 0,
                'incarnation' => $this->incarnation, 'manifest' => ''])) {
            throw new RuntimeException('Shared generation allocation failed.');
        }
    }

    /** @param iterable<NormalizedValue> $values */
    public function publish(iterable $values, BloomLayout $layout, GenerationSemanticContract $contract, int $rows, string $datasetDigest): void
    {
        if (getmypid() !== $this->publisherPid || $this->allocated >= 2) {
            throw new LogicException('Only the parent may allocate at most two generations.');
        }
        if ($layout->bitCount() > 16000000 || $rows < 1 || preg_match('/^[a-f0-9]{64}$/', $datasetDigest) !== 1) {
            throw new LogicException('Publication exceeds the bounded fixture contract.');
        }
        // Failed slots remain reserved. No generation bytes are reused in this incarnation.
        $version = ++$this->allocated;
        $bitmap = str_repeat("\0", intdiv($layout->bitCount() + 7, 8));
        $hash = hash_init('sha256');
        $count = 0;
        $generator = new BloomProbeGenerator;
        foreach ($values as $value) {
            hash_update($hash, $value->bytes()."\n");
            $count++;
            foreach ($generator->generate($value, $layout)->values() as $position) {
                $offset = intdiv($position, 8);
                $bitmap[$offset] = chr((ord($bitmap[$offset]) | (1 << ($position % 8))) & 0xFF);
            }
        }
        if ($count !== $rows || ! hash_equals($datasetDigest, hash_final($hash))) {
            throw new RuntimeException('Dataset count/digest cannot authorize publication.');
        }
        $build = hash('sha256', $bitmap);
        for ($offset = 0, $chunk = 0; $offset < strlen($bitmap); $offset += 4096, $chunk++) {
            $bytes = substr($bitmap, $offset, 4096);
            try {
                if (! $this->chunks->set($version.':'.$chunk, ['bytes' => $bytes, 'digest' => hash('sha256', $bytes)])) {
                    throw new RuntimeException('Shared chunk write failed; generation not published.');
                }
            } catch (\Exception $exception) {
                throw new RuntimeException('Native chunk admission failed; generation not published.', previous: $exception);
            }
        }
        $verified = hash_init('sha256');
        for ($chunk = 0; $chunk < (int) ceil(strlen($bitmap) / 4096); $chunk++) {
            hash_update($verified, $this->readChunk($version, $chunk, strlen($bitmap)));
        }
        if (! hash_equals($build, hash_final($verified))) {
            throw new RuntimeException('Full bitmap verification failed.');
        }
        $json = json_encode(['incarnation' => $this->incarnation, 'version' => $version,
            'bits' => $layout->bitCount(), 'hashes' => $layout->hashCount(), 'algorithm' => 'sha256-double-hash-v1',
            'normalization' => $contract->normalizationFingerprint()->value(),
            'set' => $contract->authoritativeSetFingerprint()->value(), 'consistency' => $contract->consistencyFingerprint()->value(),
            'rows' => $rows, 'dataset' => $datasetDigest, 'build' => $build], JSON_THROW_ON_ERROR);
        $digest = hash('sha256', $json);
        if (! $this->manifests->set((string) $version, ['json' => $json, 'digest' => $digest])) {
            throw new RuntimeException('Shared manifest write failed.');
        }
        $this->managed($version);
        if (! $this->control->set('active', ['version' => $version, 'revision' => $version,
            'healthy' => 1, 'incarnation' => $this->incarnation, 'manifest' => $digest])) {
            throw new RuntimeException('Coherent publication failed.');
        }
    }

    /** @return array{version:int,revision:int,healthy:int,incarnation:string,manifest:string}|null */
    public function state(): ?array
    {
        $row = $this->control->get('active');
        if (! is_array($row) || ! is_int($row['version'] ?? null) || ! is_int($row['revision'] ?? null)
            || ! is_int($row['healthy'] ?? null) || ! is_string($row['incarnation'] ?? null) || ! is_string($row['manifest'] ?? null)
            || $row['incarnation'] !== $this->incarnation || $row['version'] < 1 || $row['version'] > 2
            || $row['revision'] !== $row['version'] || $row['healthy'] !== 1 || strlen($row['manifest']) !== 64) {
            return null;
        }

        /** @var array{version:int,revision:int,healthy:int,incarnation:string,manifest:string} $row */
        return $row;
    }

    /** Parent-only verified export; admission never trusts a raw native Table copy. */
    public function exportBitmap(): string
    {
        if (getmypid() !== $this->publisherPid) {
            throw new LogicException('Only the publishing parent may export a generation.');
        }
        $before = $this->state() ?? throw new BloomStorageCorrupt('No coherent export state.');
        $descriptor = $this->managed($before['version']);
        $length = (int) ceil($descriptor->layout()->bitCount() / 8);
        $bitmap = '';
        for ($chunk = 0; $chunk < (int) ceil($length / 4096); $chunk++) {
            $bitmap .= $this->readChunk($before['version'], $chunk, $length);
        }
        $json = $this->manifests->get((string) $before['version'], 'json');
        if (! is_string($json)) {
            throw new BloomStorageCorrupt('Missing export manifest.');
        }
        $manifest = json_decode($json, true);
        if (! is_array($manifest) || ! is_string($manifest['build'] ?? null)
            || ! hash_equals($manifest['build'], hash('sha256', $bitmap))
            || $before !== $this->state() || $before['manifest'] !== $this->manifestDigest($before['version'])) {
            throw new BloomStorageCorrupt('Export identity or full build integrity changed.');
        }

        return $bitmap;
    }

    public function manifestDigest(int $version): string
    {
        $row = $this->manifests->get((string) $version);
        if (! is_array($row) || ! is_string($row['json'] ?? null) || ! is_string($row['digest'] ?? null)
            || ! hash_equals(hash('sha256', $row['json']), $row['digest'])) {
            throw new BloomStorageCorrupt('Invalid sealed manifest.');
        }

        return $row['digest'];
    }

    public function managed(int $version): ManagedGenerationDescriptor
    {
        $this->manifestDigest($version);
        $json = $this->manifests->get((string) $version, 'json');
        if (! is_string($json)) {
            throw new BloomStorageCorrupt('Missing manifest.');
        }
        $data = json_decode($json, true);
        if (! is_array($data) || ($data['incarnation'] ?? null) !== $this->incarnation || ($data['version'] ?? null) !== $version
            || ($data['algorithm'] ?? null) !== 'sha256-double-hash-v1') {
            throw new BloomStorageCorrupt('Manifest identity mismatch.');
        }
        foreach (['bits', 'hashes', 'rows'] as $field) {
            if (! is_int($data[$field] ?? null) || $data[$field] < 1) {
                throw new BloomStorageCorrupt('Invalid manifest integer.');
            }
        }
        foreach (['normalization', 'set', 'consistency', 'dataset', 'build'] as $field) {
            if (! is_string($data[$field] ?? null)) {
                throw new BloomStorageCorrupt('Invalid manifest fingerprint.');
            }
        }
        if ($data['bits'] > 16000000 || preg_match('/^[a-f0-9]{64}$/', $data['dataset']) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $data['build']) !== 1) {
            throw new BloomStorageCorrupt('Invalid manifest bounds.');
        }
        try {
            return new ManagedGenerationDescriptor(BloomLayout::create($data['bits'], $data['hashes'], ProbeAlgorithm::Sha256DoubleHashV1),
                new GenerationSemanticContract(NormalizationFingerprint::fromString($data['normalization']),
                    AuthoritativeSetFingerprint::fromString($data['set']), ConsistencyFingerprint::fromString($data['consistency'])));
        } catch (\InvalidArgumentException $exception) {
            throw new BloomStorageCorrupt('Invalid manifest contract.', previous: $exception);
        }
    }

    public function contains(int $version, BitPositions $positions): bool
    {
        $bytes = intdiv($positions->layout()->bitCount() + 7, 8);
        $chunks = [];
        $present = true;
        foreach ($positions->values() as $position) {
            $chunk = intdiv($position, 32768);
            $chunks[$chunk] ??= $this->readChunk($version, $chunk, $bytes);
            if ((ord($chunks[$chunk][intdiv($position % 32768, 8)]) & (1 << ($position % 8))) === 0) {
                $present = false;
            }
        }

        // Every touched chunk is validated, including those after the first unset bit.
        return $present;
    }

    private function readChunk(int $version, int $chunk, int $totalBytes): string
    {
        $row = $this->chunks->get($version.':'.$chunk);
        $length = min(4096, $totalBytes - $chunk * 4096);
        if (! is_array($row) || ! is_string($row['bytes'] ?? null) || ! is_string($row['digest'] ?? null)
            || strlen($row['bytes']) !== $length || ! hash_equals(hash('sha256', $row['bytes']), $row['digest'])) {
            throw new BloomStorageCorrupt('Missing or corrupt immutable chunk.');
        }

        return $row['bytes'];
    }
}
