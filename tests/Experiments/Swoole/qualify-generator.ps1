param(
    [Parameter(Mandatory)][ValidatePattern('^[a-z0-9][a-z0-9-]+$')][string]$Task,
    [switch]$OwnedBuiltImage,
    [switch]$Diagnostic
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '../../..')).Path
Set-Location $repo
$docker = (Get-Command docker -ErrorAction SilentlyContinue).Source
if (-not $docker) { $docker = 'C:\Users\yukonit\AppData\Local\Programs\DockerDesktop\resources\bin\docker.exe' }
$project = "lbg-generator-$Task"
$image = "lbg-generator-php:$Task"
$env:GENERATOR_TASK = $Task
$compose = @('compose', '-p', $project, '-f', "$PSScriptRoot/generator-compose.yaml")
$results = Join-Path $repo '.build/generator-qualification'
New-Item -ItemType Directory -Force $results | Out-Null
if (git status --porcelain) { throw 'Commit reviewed qualification sources first.' }
if (Test-Path (Join-Path $results "$Task-outcome.json")) { throw 'Refusing to overwrite qualification evidence.' }
if (& $docker ps -aq --filter "label=com.docker.compose.project=$project") { throw 'Refusing an existing stack.' }
& $docker network inspect "${project}_default" *> $null
if ($LASTEXITCODE -eq 0) { throw 'Refusing an existing network.' }
& $docker image inspect $image *> $null
if ($LASTEXITCODE -eq 0 -and -not $OwnedBuiltImage) { throw 'Refusing an existing image.' }

function Invoke-Docker([string[]]$Arguments) {
    $output = & $docker @Arguments
    if ($LASTEXITCODE) {
        $output | Set-Content (Join-Path $results "$Task-operation-failure.log")
        throw "Docker operation failed: $($Arguments[0])"
    }
    return $output
}
function Write-Json([string]$Path, $Value) {
    $body = ($Value | ConvertTo-Json -Depth 40).Replace("`r`n", "`n")
    [IO.File]::WriteAllText($Path, "$body`n", [Text.UTF8Encoding]::new($false))
}
function Get-Info {
    return (Invoke-Docker ($compose + @('exec', '-T', 'php', 'php', '-r', 'echo file_get_contents("http://127.0.0.1:8000/generator-info");')) | ConvertFrom-Json -AsHashtable)
}
function Inspect-Container([string]$Name) {
    return (Invoke-Docker @('inspect', $Name) | ConvertFrom-Json -AsHashtable -NoEnumerate)[0]
}
function Invoke-Profile([int]$Rate, [int]$Delay, [int]$Vus, [int]$Warmup, [int]$Duration, [string]$Label) {
    $name = "$Task-$Label"
    $before = Get-Info
    $loadCommand = 'while true; do printf "%s " "$(date +%s)"; tr "\n" "," < /sys/fs/cgroup/cpu.stat; printf " memory_current "; cat /sys/fs/cgroup/memory.current; sleep 1; done > /results/${OUTPUT}-generator-cgroup.txt & exec k6 run --paused --address 0.0.0.0:6565 --quiet /fixture/load.js'
    Invoke-Docker ($compose + @('run', '-d', '--entrypoint', 'sh', '--name', "$project-load", '--no-deps',
        '-e', "RATE=$Rate", '-e', "VUS=$Vus", '-e', "CONTROLLED_DELAY_MS=$Delay",
        '-e', "WARMUP=$Warmup", '-e', "DURATION=$Duration", '-e', 'PATH_MODE=generator',
        '-e', "OUTPUT=$name", 'k6', '-c', $loadCommand)) | Out-Null
    $generator = Inspect-Container "$project-load"
    if ($generator.Image -ne $script:k6Image -or $generator.HostConfig.NanoCpus -ne 4000000000 -or $generator.HostConfig.Memory -ne 4294967296) { throw 'Generator image/budget differs from the frozen profile.' }
    $timeline = Join-Path $results "$name-timeline.jsonl"
    Invoke-Docker ($compose + @('exec', '-d', 'php', 'php', '/fixture/generator-observe.php',
        "http://${project}-load:6565", "/results/$name-timeline.jsonl", [string]($Warmup + $Duration + 30))) | Out-Null
    $ready = [Diagnostics.Stopwatch]::StartNew()
    do {
        if (Test-Path $timeline) {
            $first = Get-Content $timeline | Where-Object { $_ } | Select-Object -Last 1
            if ($first -and (($first | ConvertFrom-Json).status -eq 'observed')) { break }
        }
        Start-Sleep -Milliseconds 100
    } while ($ready.Elapsed.TotalSeconds -lt 10)
    if ($ready.Elapsed.TotalSeconds -ge 10) { throw 'Observer did not see paused k6 before load.' }
    Invoke-Docker ($compose + @('exec', '-T', '-e', "LOAD_API=http://${project}-load:6565", 'php', 'php', '-r',
        '$context=stream_context_create(["http"=>["method"=>"PATCH","header"=>"Content-Type: application/json","content"=>json_encode(["data"=>["type"=>"status","id"=>"default","attributes"=>["paused"=>false]]]),"timeout"=>2]]); if(file_get_contents(getenv("LOAD_API")."/v1/status",false,$context)===false){exit(1);}')) | Out-Null
    $samples = @()
    do {
        foreach ($line in (Invoke-Docker @('stats', '--no-stream', '--format', '{{json .}}', "$project-load", "$project-php-1"))) {
            if ($line.StartsWith('{')) {
                $sample = $line | ConvertFrom-Json -AsHashtable
                $sample.epoch_ms = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
                $samples += $sample
            }
        }
        Start-Sleep -Seconds 3
        $generator = Inspect-Container "$project-load"
    } while ($generator.State.Running)
    $receiver = Inspect-Container "$project-php-1"
    # The client's last response can arrive just before the receiver increments completion.
    $drain = [Diagnostics.Stopwatch]::StartNew()
    do {
        $after = Get-Info
        if ($after.started -eq $after.completed -or $after.failed -gt 0) { break }
        Start-Sleep -Milliseconds 100
    } while ($drain.Elapsed.TotalSeconds -lt 5)
    & $docker logs "$project-load" *> (Join-Path $results "$name.log")
    Write-Json (Join-Path $results "$name-resources.json") $samples
    Invoke-Docker @('rm', "$project-load") | Out-Null
    if ($generator.State.ExitCode -ne 0) { throw "Generator exited $($generator.State.ExitCode); no qualification." }
    $rawPath = Join-Path $results "$name.json"
    $raw = Get-Content $rawPath -Raw | ConvertFrom-Json -AsHashtable
    if ($raw.metrics.vus_max.values.max -ne $Vus) { throw 'Observed allocated VUs differ from the fixed budget.' }
    if (($before.worker_pids | ConvertTo-Json -Compress) -ne ($after.worker_pids | ConvertTo-Json -Compress)) { throw 'Receiver workers changed during qualification.' }
    $raw.cell.identity = $script:identity
    $raw.cell.receiver_delta = @{ started = $after.started - $before.started; completed = $after.completed - $before.completed; failed = $after.failed - $before.failed }
    $raw.cell.worker_pids = $after.worker_pids
    $raw.cell.worker_requests = @(0..3 | ForEach-Object { $after.worker_requests[$_] - $before.worker_requests[$_] })
    $measured = @($samples | Where-Object { $_.epoch_ms -ge $raw.cell.measurement_start_epoch_ms -and $_.epoch_ms -le $raw.cell.measurement_end_epoch_ms })
    $producer = @($measured | Where-Object Name -eq "$project-load")
    $consumer = @($measured | Where-Object Name -eq "$project-php-1")
    function Maximum($Entries, [string]$Field) {
        if (-not $Entries.Count) { return $null }
        return ($Entries | ForEach-Object { [double]::Parse($_[$Field].TrimEnd('%'), [Globalization.CultureInfo]::InvariantCulture) } | Measure-Object -Maximum).Maximum
    }
    $raw.cell.resources = @{ samples = [Math]::Min($producer.Count, $consumer.Count);
        generator_cpu_max = (Maximum $producer 'CPUPerc'); receiver_cpu_max = (Maximum $consumer 'CPUPerc');
        generator_memory_percent_max = (Maximum $producer 'MemPerc'); receiver_memory_percent_max = (Maximum $consumer 'MemPerc');
        generator_oom = $generator.State.OOMKilled; receiver_oom = $receiver.State.OOMKilled;
        generator_exit = $generator.State.ExitCode; receiver_restarts = $receiver.RestartCount; receiver_running = $receiver.State.Running }
    $raw.receiver_before = $before
    $raw.receiver_after = $after
    $raw.resource_samples = $samples
    $raw.diagnostic_artifacts = @{ timeline = "$name-timeline.jsonl"; generator_cgroup = "$name-generator-cgroup.txt";
        scope = 'Target one-second cumulative snapshots with actual timestamps; not interval quantiles or capacity proof.' }
    $raw.observed_containers = @{ generator_image = $generator.Image; receiver_image = $receiver.Image;
        generator_nano_cpus = $generator.HostConfig.NanoCpus; generator_memory_bytes = $generator.HostConfig.Memory;
        receiver_nano_cpus = $receiver.HostConfig.NanoCpus; receiver_memory_bytes = $receiver.HostConfig.Memory }
    Write-Json $rawPath $raw
    Write-Json (Join-Path $results "$name-cell.json") $raw.cell
    $coverage = Invoke-Docker ($compose + @('exec', '-T', 'php', 'php', '/fixture/generator-coverage.php',
        "/results/$name-timeline.jsonl", "/results/$name-generator-cgroup.txt", "/results/$name-cell.json")) | ConvertFrom-Json -AsHashtable
    Write-Json (Join-Path $results "$name-coverage.json") $coverage
    if ($coverage.verdict -ne 'OBSERVED') { throw ($coverage.reasons -join ' ') }
    $evaluation = Invoke-Docker ($compose + @('exec', '-T', 'php', 'php', '/fixture/generator-evaluate.php', "/results/$name-cell.json")) | ConvertFrom-Json -AsHashtable
    Write-Host "$Label responses=$($raw.cell.responses) drops=$($raw.cell.dropped_iterations) p99=$($raw.cell.p99_ms) verdict=$($evaluation.verdict)"
    return @{ cell = $raw.cell; evaluation = $evaluation; raw = "$name.json" }
}

$owned = $false
$profiles = @()
$negative = $null
$script:identity = $null
try {
    $owned = $true
    if (-not $OwnedBuiltImage) { Invoke-Docker ($compose + @('build', 'php')) | Set-Content (Join-Path $results "$Task-build.log") }
    $pin = 'grafana/k6:1.3.0@sha256:3ddc8b1a33a2c3d8edc6e99b6a762ae36cba08788463458f5e6a7703e14eb77d'
    Invoke-Docker @('pull', $pin) | Out-Null
    $script:k6Image = Invoke-Docker @('image', 'inspect', $pin, '--format', '{{.Id}}')
    Invoke-Docker ($compose + @('up', '-d', '--wait')) | Out-Null
    $receiver = Inspect-Container "$project-php-1"
    if ($receiver.Image -ne (Invoke-Docker @('image', 'inspect', $image, '--format', '{{.Id}}')) -or $receiver.HostConfig.NanoCpus -ne 2000000000 -or $receiver.HostConfig.Memory -ne 268435456) { throw 'Receiver image/budget differs.' }
    $info = Get-Info
    if ($info.workers -ne 4 -or @($info.worker_pids | Where-Object { $_ -le 0 }).Count -gt 0 -or @($info.worker_pids | Select-Object -Unique).Count -ne 4) { throw 'Four actual receiver PIDs are unavailable.' }
    $sources = [ordered]@{}
    foreach ($file in @('load.js', 'generator-server.php', 'GeneratorDeadline.php', 'generator-observe.php', 'GeneratorObservation.php', 'generator-coverage.php', 'GeneratorReport.php', 'generator-evaluate.php', 'generator-compose.yaml', 'qualify-generator.ps1', 'Dockerfile')) {
        $body = [IO.File]::ReadAllText((Join-Path $PSScriptRoot $file)).Replace("`r`n", "`n")
        $sources[$file] = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData([Text.Encoding]::UTF8.GetBytes($body))).ToLowerInvariant()
        $observed = Invoke-Docker ($compose + @('exec', '-T', '-e', "SOURCE_FILE=$file", 'php', 'php', '-r', 'echo hash("sha256",str_replace("\r\n","\n",file_get_contents("/fixture/".getenv("SOURCE_FILE"))));'))
        if ($observed -ne $sources[$file]) { throw "Installed source differs: $file" }
    }
    $script:identity = [ordered]@{ source_ref = (git rev-parse HEAD); sources = $sources; receiver_image = $receiver.Image;
        k6_image_id = $k6Image; k6_manifest = ($pin -split '@')[1]; php = $info.php; swoole = $info.swoole;
        workers = $info.workers; generator_cpus = 4; generator_memory_bytes = 4294967296;
        receiver_cpus = 2; receiver_memory_bytes = 268435456; fixed_vus = 1024 }
    if ($Diagnostic) {
        $profile = Invoke-Profile 4800 25 1024 5 15 'diagnostic-delay-25'
        if ((git rev-parse HEAD) -ne $identity.source_ref -or (git status --porcelain)) { throw 'Diagnostic sources changed during the run.' }
        Write-Json (Join-Path $results "$Task-outcome.json") @{ verdict = 'DIAGNOSTIC_ONLY'; identity = $identity; profiles = @($profile);
            scope = 'One frozen 4800-RPS/25-ms short diagnostic, same budgets. Not generator qualification or application speed.' }
        return
    }
    $negative = Invoke-Profile 1200 150 128 2 5 'negative-128-vus'
    if ($negative.cell.dropped_iterations -le 0 -or $negative.evaluation.verdict -ne 'INCONCLUSIVE' -or $negative.evaluation.reasons[0] -ne 'dropped_iterations must be zero.') { throw 'The deliberate fixed-VU deficit was not rejected as lost scheduled work.' }
    foreach ($delay in @(0, 25, 100, 150)) {
        $profile = Invoke-Profile 4800 $delay 1024 30 60 "delay-$delay"
        $profiles += $profile
        if ($profile.evaluation.verdict -ne 'PASS') { throw ($profile.evaluation.reasons -join ' ') }
    }
    if ((git rev-parse HEAD) -ne $identity.source_ref -or (git status --porcelain)) { throw 'Qualification sources changed during the run.' }
    Write-Json (Join-Path $results "$Task-outcome.json") @{ verdict = 'PASS'; identity = $identity; negative_control = $negative; profiles = $profiles;
        scope = 'Generator qualification only: fixed 4800 RPS, 0/25/100/150 ms, budgets and source. No SQL/application speed result.' }
} catch {
    Write-Json (Join-Path $results "$Task-outcome.json") @{ verdict = 'INCONCLUSIVE'; reasons = @($_.Exception.Message); identity = $identity; negative_control = $negative; profiles = $profiles }
    throw
} finally {
    if ($owned) {
        & $docker @compose down --volumes --remove-orphans
        & $docker image rm $image
    }
}
