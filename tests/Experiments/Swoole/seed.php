<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Eloquent\Model;

require __DIR__.'/vendor/autoload.php';

final class DemoMember extends Model
{
    protected $table = 'members';

    protected $primaryKey = 'member_key';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

/** @extends Factory<DemoMember> */
final class DemoMemberFactory extends Factory
{
    protected $model = DemoMember::class;

    public function definition(): array
    {
        return ['member_key' => 'member-0000000'];
    }
}

$started = microtime(true);
$capsule = new Manager;
$capsule->addConnection(['driver' => 'mysql', 'host' => 'mysql', 'database' => 'demo',
    'username' => 'seeder', 'password' => (string) getenv('DEMO_SEED_PASSWORD'),
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
if (DemoMember::count() !== 0) {
    throw new RuntimeException('Seeding requires a fresh task-owned dataset.');
}
$expected = hash_init('sha256');
for ($offset = 0; $offset < 1000000; $offset += 10000) {
    $members = DemoMemberFactory::new()->count(10000)->sequence(
        fn (Sequence $sequence): array => ['member_key' => sprintf('member-%07d', $offset + $sequence->index)],
    )->make();
    if (! $members instanceof Collection) {
        throw new RuntimeException('Factory did not return a batch.');
    }
    $rows = [];
    foreach ($members as $member) {
        $key = $member->getAttribute('member_key');
        if (! is_string($key)) {
            throw new RuntimeException('Factory membership key is invalid.');
        }
        $rows[] = ['member_key' => $key];
    }
    DemoMember::insert($rows);
    foreach ($rows as $row) {
        hash_update($expected, $row['member_key']."\n");
    }
}
$actual = hash_init('sha256');
foreach (DemoMember::orderBy('member_key')->cursor() as $member) {
    $key = $member->getAttribute('member_key');
    if (! is_string($key)) {
        throw new RuntimeException('Stored membership key is invalid.');
    }
    hash_update($actual, $key."\n");
}
$digest = hash_final($actual);
$count = $capsule->getConnection()->table('members')->count();
if (! hash_equals(hash_final($expected), $digest) || $count !== 1000000) {
    throw new RuntimeException('Factory dataset cardinality/digest verification failed.');
}
$capsule->getConnection()->disconnect();
file_put_contents(__DIR__.'/dataset.json', json_encode(['rows' => 1000000,
    'sha256' => $digest, 'setup_seconds' => microtime(true) - $started], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
echo file_get_contents(__DIR__.'/dataset.json');
