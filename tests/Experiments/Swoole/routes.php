<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Kefyusuf\BloomGate\Tests\Experiments\Swoole\MeasurementRuntime;
use Kefyusuf\BloomGate\Tests\Experiments\Swoole\SharedMemoryQuery;
use Kefyusuf\BloomGate\Tests\Experiments\Swoole\SharedSqlSet;
use Laravel\Octane\Swoole\WorkerState;
use Swoole\Http\Server;

if (getenv('SHARED_MEMORY_MEASURE') === '1') {
    require_once __DIR__.'/MeasurementRuntime.php';
    Route::get('/measurement-info', function (): array {
        $domain = ParentRuntime::$domain ?? throw new RuntimeException('No measurement domain.');
        $state = $domain->state() ?? throw new RuntimeException('No coherent measurement generation.');
        $json = $domain->manifests->get((string) $state['version'], 'json');
        if (! is_string($json)) {
            throw new RuntimeException('No sealed measurement manifest.');
        }
        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest)) {
            throw new RuntimeException('Invalid measurement manifest.');
        }
        $database = SharedSqlSet::database();
        $seal = capabilitySql($database, 'SELECT @@global.read_only, @@global.super_read_only')->fetch(PDO::FETCH_NUM);
        $settings = app(Server::class)->setting;
        if (! is_array($settings)) {
            throw new RuntimeException('Measurement worker settings are unavailable.');
        }
        $setting = $settings['worker_num'] ?? null;
        $workers = is_int($setting) || is_string($setting)
            ? filter_var($setting, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if (! is_int($workers)) {
            throw new RuntimeException('Measurement worker count is unavailable.');
        }

        return ['php' => PHP_VERSION, 'swoole' => phpversion('openswoole'),
            'laravel' => InstalledVersions::getPrettyVersion('laravel/framework'),
            'octane' => InstalledVersions::getPrettyVersion('laravel/octane'),
            'apcu_enabled' => apcu_enabled(), 'seal' => $seal, 'manifest' => $manifest,
            'manifest_digest' => $domain->manifestDigest($state['version']), 'filter' => $domain->name->value(),
            'workers' => $workers,
            'shared_bytes' => $domain->chunks->getMemorySize() + $domain->control->getMemorySize() + $domain->manifests->getMemorySize(),
            'writer_credentials_present' => getenv('MYSQL_ROOT_PASSWORD') !== false
                || getenv('SHARED_MEMORY_SQL_ROOT_PASSWORD') !== false || getenv('DEMO_SEED_PASSWORD') !== false];
    });
    Route::get('/measure/{path}', function (Request $request, string $path): array {
        $key = $request->query('key');
        if (! is_string($key)) {
            throw new InvalidArgumentException('A membership key is required.');
        }
        $domain = ParentRuntime::$domain ?? throw new RuntimeException('No measurement domain.');

        return MeasurementRuntime::worker($domain)->lookup($path, $key);
    });
}

Route::get('/query', function (Request $request): array {
    $key = $request->query('key');
    if (! is_string($key)) {
        throw new InvalidArgumentException('A membership key is required.');
    }
    $domain = ParentRuntime::$domain ?? throw new RuntimeException('Parent query domain is unavailable.');
    $query = new SharedMemoryQuery($domain);
    $result = $query->lookup($key);

    return ['exists' => $result->exists(), 'membership' => $result->membership()->name,
        'sql_calls' => $query->sqlCalls(), 'pid' => getmypid(), 'filter' => $domain->name->value()];
});

Route::get('/probe', function (): array {
    $row = ParentRuntime::$control?->get('active');
    if (! is_array($row)) {
        throw new RuntimeException('Parent control row is unavailable.');
    }
    $worker = app(WorkerState::class);

    return ['worker_id' => $worker->workerId, 'pid' => getmypid(),
        'parent_pid' => $row['parent_pid'] ?? 0, 'incarnation' => $row['incarnation'] ?? '',
        'marker_pid' => $row['marker_pid'] ?? 0,
        'sentinel' => $row['sentinel'], 'published' => $row['published'] !== 0];
});

Route::get('/touch', function (): array {
    if (ParentRuntime::$control === null || ! ParentRuntime::$control->set('active', ['marker_pid' => getmypid()])) {
        throw new RuntimeException('Shared marker write failed.');
    }

    return ['pid' => getmypid()];
});

Route::get('/reload', function (): array {
    app(Server::class)->reload();

    return ['requested' => true];
});

Route::get('/database', function (): array {
    $database = new PDO('mysql:host='.getenv('DEMO_DB_HOST').';dbname=demo',
        (string) getenv('DEMO_DB_USERNAME'), (string) getenv('DEMO_DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $denied = [];
    foreach (['insert' => "INSERT INTO members VALUES ('forbidden')", 'update' => "UPDATE members SET member_key = 'forbidden' WHERE member_key = 'member-0000000'",
        'delete' => "DELETE FROM members WHERE member_key = 'member-0000000'"] as $operation => $sql) {
        try {
            $database->exec($sql);
        } catch (PDOException $exception) {
            if (! in_array($exception->errorInfo[1] ?? null, [1142, 1290], true)) {
                throw $exception;
            }
            $denied[] = $operation;
        }
    }

    $summary = capabilitySql($database, 'SELECT COUNT(*) AS rows_count, MIN(member_key) AS first_key, MAX(member_key) AS last_key FROM members')->fetch(PDO::FETCH_ASSOC);
    if (! is_array($summary) || ! is_numeric($summary['rows_count'] ?? null)) {
        throw new RuntimeException('Dataset summary is invalid.');
    }

    return ['rows' => (int) $summary['rows_count'], 'first' => $summary['first_key'],
        'last' => $summary['last_key'], 'denied' => $denied,
        'grants' => capabilitySql($database, 'SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN),
        'read_only' => capabilitySql($database, 'SELECT @@global.super_read_only')->fetchColumn(),
        'writer_credentials_present' => getenv('MYSQL_ROOT_PASSWORD') !== false
            || getenv('SHARED_MEMORY_SQL_ROOT_PASSWORD') !== false || getenv('DEMO_SEED_PASSWORD') !== false,
        'username' => capabilitySql($database, 'SELECT CURRENT_USER()')->fetchColumn()];
});

function capabilitySql(PDO $database, string $sql): PDOStatement
{
    $statement = $database->query($sql);
    if ($statement === false) {
        throw new RuntimeException('Capability SQL query failed.');
    }

    return $statement;
}
