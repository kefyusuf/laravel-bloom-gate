param(
    [Parameter(Mandatory)][ValidatePattern('^[a-z0-9][a-z0-9-]+$')][string]$Task,
    [switch]$OwnedPreparedStack
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '../../..')).Path
Set-Location $repo
$docker = (Get-Command docker -ErrorAction SilentlyContinue).Source
if (-not $docker) { $docker = 'C:\Users\yukonit\AppData\Local\Programs\DockerDesktop\resources\bin\docker.exe' }
$project = "lbg-shared-memory-$Task"
$image = "lbg-shared-memory-php:$Task"
$env:SHARED_MEMORY_TASK = $Task
$env:SHARED_MEMORY_SQL_ROOT_PASSWORD = [Convert]::ToHexString([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
$compose = @('compose', '-p', $project, '-f', "$PSScriptRoot/compose.yaml", '-f', "$PSScriptRoot/measurement-compose.yaml")
$results = Join-Path $repo '.build/swoole-measurement'
New-Item -ItemType Directory -Force $results | Out-Null
if (Test-Path (Join-Path $results "$Task-report.json")) { throw 'Refusing to overwrite an existing screen.' }
if (git status --porcelain) { throw 'Commit the reviewed measurement sources before running the screen.' }

function Invoke-Docker([string[]]$DockerArgs) {
    $output = & $docker @DockerArgs
    if ($LASTEXITCODE) {
        $output | Set-Content (Join-Path $results "$Task-failed-operation.log")
        throw "Task Docker operation failed: $($DockerArgs[0]); see $Task-failed-operation.log"
    }
    return $output
}
function Get-RedisCommands {
    $commands = @{ eval = 0; evalsha = 0 }
    foreach ($line in (Invoke-Docker ($compose + @('exec', '-T', 'redis', 'redis-cli', 'INFO', 'commandstats')))) {
        if ($line -match '^cmdstat_(evalsha|eval):calls=(\d+),') { $commands[$Matches[1]] = [long]$Matches[2] }
    }
    return $commands
}
function Invoke-Cell([int]$Rate, [int]$Warmup, [int]$Duration, [int]$Block, [string]$Path, [string]$Label) {
    $mode = if ($Path.StartsWith('control-')) { 'direct' } else { $Path }
    $name = "$Task-$Label"
    $args = $compose + @('run', '-d', '--name', "$project-load", '--no-deps',
        '-e', "RATE=$Rate", '-e', "WARMUP=$Warmup", '-e', "DURATION=$Duration",
        '-e', "PATH_MODE=$mode", '-e', "CELL_PATH=$Path", '-e', "BLOCK=$Block", '-e', "OUTPUT=$name",
        'k6', 'run', '--quiet', '/fixture/load.js')
    $wireBefore = Get-RedisCommands
    Invoke-Docker $args | Out-Null
    if ((Invoke-Docker @('inspect', '--format', '{{.Image}}', "$project-load")) -ne $script:imageIds.k6) { throw 'Load generator image differs from the verified pin.' }
    $samples = @()
    do {
        $stats = Invoke-Docker @('stats', '--no-stream', '--format', '{{json .}}', "$project-load", "$project-php-1", "$project-mysql-1", "$project-redis-1")
        foreach ($line in $stats) {
            if ($line.StartsWith('{')) {
                $sample = $line | ConvertFrom-Json -AsHashtable
                $sample.epoch_ms = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
                $samples += $sample
            }
        }
        Start-Sleep -Seconds 5
        $running = Invoke-Docker @('inspect', '--format', '{{.State.Running}}', "$project-load")
    } while ($running -eq 'true')
    $exitCode = Invoke-Docker @('inspect', '--format', '{{.State.ExitCode}}', "$project-load")
    Invoke-Docker @('logs', "$project-load") | Set-Content (Join-Path $results "$name.log")
    Invoke-Docker @('rm', "$project-load") | Out-Null
    if ($exitCode -ne '0') { throw 'Load generator failed; no performance verdict may be inferred.' }
    $raw = Get-Content (Join-Path $results "$name.json") -Raw | ConvertFrom-Json -AsHashtable
    $wireAfter = Get-RedisCommands
    $raw.cell.redis_wire_evalsha = $wireAfter.evalsha - $wireBefore.evalsha
    $raw.cell.redis_wire_eval = $wireAfter.eval - $wireBefore.eval
    if ($mode -eq 'redis' -and ($raw.cell.redis_wire_evalsha -lt $raw.cell.responses -or $raw.cell.redis_wire_eval -ne 0)) {
        throw 'Configured Redis did not retain warm EVALSHA live authorization.'
    }
    $cpu = @($samples | Where-Object { $_.Name -eq "$project-load" -and $_.epoch_ms -ge $raw.cell.measurement_start_epoch_ms -and $_.epoch_ms -le $raw.cell.measurement_end_epoch_ms } | ForEach-Object { [double]$_.CPUPerc.TrimEnd('%') })
    if ($cpu.Count -eq 0) { throw 'Measured generator CPU observations are missing.' }
    $raw.cell.generator_saturated = $raw.cell.generator_saturated -or (($cpu | Measure-Object -Maximum).Maximum -ge 190)
    $raw.cell.identity = $script:identity
    $raw.resource_samples = $samples
    $raw | ConvertTo-Json -Depth 30 | Set-Content (Join-Path $results "$name.json")
    Write-Host "$Label rate=$Rate responses=$($raw.cell.responses) p99=$($raw.cell.p99_ms) errors=$($raw.cell.errors) drops=$($raw.cell.dropped_iterations)"
    return $raw.cell
}

$owned = $false
try {
    if (-not $OwnedPreparedStack) {
        if (& $docker ps -aq --filter "label=com.docker.compose.project=$project") { throw 'Refusing to reuse an existing stack.' }
        foreach ($volume in @('mysql', 'experiment', 'redis')) {
            & $docker volume inspect "${project}_$volume" *> $null
            if ($LASTEXITCODE -eq 0) { throw 'Refusing to reuse an existing volume.' }
        }
        & $docker network inspect "${project}_default" *> $null
        if ($LASTEXITCODE -eq 0) { throw 'Refusing to reuse an existing network.' }
        & $docker image inspect $image *> $null
        if ($LASTEXITCODE -eq 0) { throw 'Refusing to replace an existing task image.' }
        $owned = $true
        Invoke-Docker ($compose + @('build', 'php')) | Set-Content (Join-Path $results "$Task-build.log")
        Invoke-Docker ($compose + @('up', '-d', '--wait')) | Out-Null
        $seed = [Convert]::ToHexString([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
        "ALTER USER 'seeder'@'%' IDENTIFIED BY '$seed';" | & $docker @compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot'
        if ($LASTEXITCODE) { throw 'Seeder provisioning failed.' }
        Invoke-Docker ($compose + @('exec', '-T', 'php', 'sh', '-c', 'cp -r /package/tests/Experiments/Swoole/. /experiment/ && mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs public tests/Experiments/Swoole && cp SharedMemory*.php Measurement*.php fixture.php tests/Experiments/Swoole/ && composer install --no-interaction --no-progress --prefer-dist')) | Out-Null
        Invoke-Docker ($compose + @('exec', '-T', '-e', "DEMO_SEED_PASSWORD=$seed", 'php', 'sh', '-c', 'php seed.php && rm seed.php')) | Set-Content (Join-Path $results "$Task-seed.json")
        $seed = $null
        Invoke-Docker ($compose + @('cp', "$PSScriptRoot/seal.sql", 'mysql:/tmp/seal.sql')) | Out-Null
        Invoke-Docker ($compose + @('exec', '-T', 'mysql', 'sh', '-c', 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot < /tmp/seal.sql')) | Out-Null
    } else {
        # The caller asserts this stack/image was created for this task in the current session.
        $owned = $true
    }
    Invoke-Docker ($compose + @('exec', '-T', 'php', 'sh', '-c', 'cp -r /package/tests/Experiments/Swoole/. /experiment/ && cp SharedMemory*.php Measurement*.php fixture.php tests/Experiments/Swoole/')) | Out-Null
    # Install the tracked lock in both fresh and current-task prepared stacks.
    Invoke-Docker ($compose + @('exec', '-T', 'php', 'composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist')) | Set-Content (Join-Path $results "$Task-install.log")
    Invoke-Docker ($compose + @('exec', '-T', 'php', 'php', 'preflight.php')) | Set-Content (Join-Path $results "$Task-preflight.json")
    Invoke-Docker ($compose + @('exec', '-T', '-e', 'MEASUREMENT_NATIVE_TESTS=1', 'php', 'vendor/bin/pest', 'tests/Experiments/Swoole/MeasurementAdmissionTest.php', 'tests/Experiments/Swoole/MeasurementHttpTest.php', '--compact', '--fail-on-warning', '--fail-on-risky')) | Set-Content (Join-Path $results "$Task-native.log")
    Invoke-Docker ($compose + @('exec', '-T', 'redis', 'redis-cli', 'FLUSHALL')) | Out-Null
    $startup = [Diagnostics.Stopwatch]::StartNew()
    Invoke-Docker ($compose + @('exec', '-d', '-e', 'SHARED_MEMORY_PUBLISH=1', '-e', 'SHARED_MEMORY_MEASURE=1', 'php', 'sh', '-c', 'php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000 --workers=4 --task-workers=0 --max-requests=0 > /experiment/measurement-server.log 2>&1')) | Out-Null
    $info = $null
    do {
        $body = & $docker @compose exec -T php php -r '$b=@file_get_contents("http://127.0.0.1:8000/measurement-info");if($b===false){exit(1);}echo $b;'
        if ($LASTEXITCODE -eq 0) { $info = $body | ConvertFrom-Json -AsHashtable }
        if ($startup.Elapsed.TotalSeconds -gt 120) { throw 'Measurement startup failed.' }
        if (-not $info) { Start-Sleep -Seconds 1 }
    } while (-not $info)
    $startup.Stop()
    if ($info.seal[0] -ne 1 -or $info.seal[1] -ne 1 -or $info.writer_credentials_present -or $info.workers -ne 4 -or -not $info.apcu_enabled -or $info.manifest.rows -ne 1000000) { throw 'Runtime admission proof is incomplete.' }
    $sources = [ordered]@{}
    foreach ($file in (git ls-files tests/Experiments/Swoole | Sort-Object)) {
        $text = [IO.File]::ReadAllText((Join-Path $repo $file)).Replace("`r`n", "`n")
        $sources[$file] = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData([Text.Encoding]::UTF8.GetBytes($text))).ToLowerInvariant()
    }
    $sourceJson = $sources | ConvertTo-Json -Compress
    $sourcePaths = @($sources.Keys | ForEach-Object { $_.Substring('tests/Experiments/Swoole/'.Length) }) | ConvertTo-Json -Compress
    $installedSources = Invoke-Docker ($compose + @('exec', '-T', '-e', "SOURCE_PATHS=$sourcePaths", 'php', 'php', '-r', '$r=[];foreach(json_decode(getenv("SOURCE_PATHS"),true) as $p){$f="/experiment/".$p;if(is_file($f)){$r[$p]=hash("sha256",str_replace("\r\n","\n",file_get_contents($f)));}}echo json_encode($r);')) | ConvertFrom-Json -AsHashtable
    foreach ($file in $sources.Keys) {
        $relative = $file.Substring('tests/Experiments/Swoole/'.Length)
        if (-not $installedSources.ContainsKey($relative) -or $installedSources[$relative] -ne $sources[$file]) { throw "Installed fixture source differs: $relative" }
    }
    $script:imageIds = [ordered]@{}
    $imageDigests = [ordered]@{}
    $pins = [ordered]@{ mysql = 'mysql:8.4@sha256:0744ee5ef89ce6ccfa13de3e579fe6b9e27f93dd70da9c06d2c908b1b193fb8d'; redis = 'redis:8-alpine@sha256:3811787313eba226a2ef38658c6ccb91cd5e110edc89c37767de373120a0e5a0'; k6 = 'grafana/k6:1.3.0@sha256:3ddc8b1a33a2c3d8edc6e99b6a762ae36cba08788463458f5e6a7703e14eb77d' }
    Invoke-Docker @('pull', $pins.k6) | Set-Content (Join-Path $results "$Task-k6-image.log")
    $imageIds.php = Invoke-Docker @('inspect', '--format', '{{.Image}}', "$project-php-1")
    if ($imageIds.php -ne (Invoke-Docker @('image', 'inspect', $image, '--format', '{{.Id}}'))) { throw 'PHP container image differs from the task image.' }
    $imageDigests.php = $imageIds.php
    foreach ($service in $pins.Keys) {
        $installed = Invoke-Docker @('image', 'inspect', $pins[$service]) | ConvertFrom-Json -AsHashtable -NoEnumerate
        $expectedDigest = ($pins[$service] -split '@')[1]
        if (-not @($installed[0].RepoDigests | Where-Object { $_.EndsWith("@$expectedDigest") }).Count) { throw "Missing observed pinned image digest: $service" }
        $imageIds[$service] = $installed[0].Id
        if ($service -ne 'k6' -and $imageIds[$service] -ne (Invoke-Docker @('inspect', '--format', '{{.Image}}', "$project-$service-1"))) { throw "Running image differs from the pin: $service" }
        $imageDigests[$service] = $expectedDigest
    }
    $script:identity = [ordered]@{ source_ref = (git rev-parse HEAD); source_digest = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData([Text.Encoding]::UTF8.GetBytes($sourceJson))).ToLowerInvariant();
        lock_digest = (Get-FileHash "$PSScriptRoot/composer.lock" -Algorithm SHA256).Hash.ToLowerInvariant(); dataset_digest = $info.manifest.dataset;
        runtime = "$($info.php) / OpenSwoole $($info.swoole) / Laravel $($info.laravel) / Octane $($info.octane)";
        images = $imageDigests;
        sql_connection = 'worker-local-reused'; redis_profile = 'standalone-primary-durable-v1'; redis_apcu = $info.apcu_enabled;
        redis_evalsha = (Get-RedisCommands).evalsha -gt 0; sealed = ($info.seal[0] -eq 1 -and $info.seal[1] -eq 1); integrity_checks = $true }
    $calibration = @()
    $capacity = 0
    foreach ($rate in @(100, 200, 400, 800, 1200, 1600, 2400, 3200, 4800)) {
        $cell = Invoke-Cell $rate 10 20 0 'direct' "calibration-$rate"
        $calibration += $cell
        if ($cell.errors -gt 0 -or $cell.parity_failures -gt 0 -or $cell.dropped_iterations -gt 0 -or $cell.generator_saturated -or $cell.rps -lt $rate * 0.95) { break }
        $capacity = $rate
    }
    if ($capacity -eq 0 -or $capacity -eq 4800) { throw 'SQL sustainable capacity was not bounded.' }
    $target = [int][Math]::Floor($capacity * 0.8)
    $duration = [int][Math]::Max(60, [Math]::Ceiling(10500 / $target))
    $cells = @()
    foreach ($block in 1..3) {
        $order = switch ($block) { 1 { @('direct','bypass','redis','shared') }; 2 { @('redis','shared','direct','bypass') }; 3 { @('shared','direct','bypass','redis') } }
        foreach ($path in @('control-before') + $order + @('control-after')) {
            $cells += Invoke-Cell $target 30 $duration $block $path "block-$block-$path"
        }
    }
    $report = [ordered]@{ identity = $identity; sql_capacity = $capacity; cells = $cells; calibration = $calibration;
        fixture_sources = $sources; observed_image_ids = $imageIds; runtime_observations = $info; startup_seconds = $startup.Elapsed.TotalSeconds;
        capacity_method = 'Highest passing fixed ladder rate before the first failing rate; conservative tested capacity, not an exact maximum.' }
    $reportPath = Join-Path $results "$Task-report.json"
    $report | ConvertTo-Json -Depth 30 | Set-Content $reportPath
    Invoke-Docker ($compose + @('cp', $reportPath, 'php:/experiment/screen-report.json')) | Out-Null
    Invoke-Docker ($compose + @('exec', '-T', 'php', 'php', 'measure.php', '/experiment/screen-report.json')) | Set-Content (Join-Path $results "$Task-verdict.json")
    Write-Host (Get-Content (Join-Path $results "$Task-verdict.json") -Raw)
} catch {
    if ($owned) {
        & $docker @compose exec -T php sh -c 'cat /experiment/measurement-server.log 2>/dev/null || true' | Set-Content (Join-Path $results "$Task-server.log")
    }
    [ordered]@{ verdict = 'INCONCLUSIVE'; reasons = @($_.Exception.Message); source_ref = (git rev-parse HEAD); calibration = @($calibration); completed_cells = @($cells) } | ConvertTo-Json -Depth 30 | Set-Content (Join-Path $results "$Task-aborted.json")
    throw
} finally {
    if ($owned) {
        & $docker @compose down --volumes --remove-orphans
        & $docker image rm $image
    }
}
