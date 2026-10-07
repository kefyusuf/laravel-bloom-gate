<?php

declare(strict_types=1);

use Kefyusuf\BloomGate\Contracts\Exception\BloomStorageCorrupt;
use Kefyusuf\BloomGate\Core\BloomLayout;
use Kefyusuf\BloomGate\Core\NormalizedValue;
use Kefyusuf\BloomGate\Core\ProbeAlgorithm;
use Kefyusuf\BloomGate\Tests\Experiments\Swoole\MeasurementRuntime;
use Kefyusuf\BloomGate\Tests\Experiments\Swoole\SharedMemoryDomain;
use Kefyusuf\BloomGate\Tests\Experiments\Swoole\SharedMemoryQuery;

if (getenv('MEASUREMENT_NATIVE_TESTS') !== '1') {
    it('requires native measurement admission')->skip();

    return;
}
require_once __DIR__.'/fixture.php';
require_once __DIR__.'/MeasurementRuntime.php';

it('refuses Redis admission from damaged shared storage', function (string $damage): void {
    $domain = new SharedMemoryDomain;
    $query = new SharedMemoryQuery($domain, 'member-0000000');
    $domain->publish([NormalizedValue::fromBytes('member-0000000')],
        BloomLayout::create(128, 3, ProbeAlgorithm::Sha256DoubleHashV1), $query->semantics(),
        1, hash('sha256', "member-0000000\n"));
    if ($damage === 'missing') {
        $domain->chunks->del('1:0');
    } else {
        $domain->chunks->set('1:0', ['bytes' => str_repeat("\0", $damage === 'truncated' ? 15 : 16)]);
    }
    expect(fn () => MeasurementRuntime::publishRedis($domain))->toThrow(BloomStorageCorrupt::class);
})->with(['corrupt', 'truncated', 'missing'])->group('measurement');
