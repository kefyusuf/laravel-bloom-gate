param(
    [Parameter(Mandatory)][string]$SourcePath,
    [ValidateSet('Baseline','Patched')][string]$Mode = 'Baseline',
    [switch]$UpstreamChecks,
    [switch]$BuildBinary,
    [switch]$Race
)
$ErrorActionPreference = 'Stop'
$revision = '5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb'
$source = (Resolve-Path -LiteralPath $SourcePath).Path
$head = & git -C $source rev-parse HEAD
if ($LASTEXITCODE -ne 0 -or $head -ne $revision) { throw 'Pinned k6 source revision mismatch.' }
$status = & git -C $source status --porcelain
if ($status) { throw 'Use an unmodified pinned source checkout.' }
$repo = (Resolve-Path (Join-Path $PSScriptRoot '../../../..')).Path
$output = Join-Path $repo '.build/k6-terminal-fix'
New-Item -ItemType Directory -Force $output | Out-Null
$target = Join-Path $source 'lib/executor/constant_arrival_rate.go'
$original = [IO.File]::ReadAllBytes($target)
$body = [Text.Encoding]::UTF8.GetString($original).Replace("`r`n", "`n")
if ($Mode -eq 'Patched') {
    $copyRoot = Join-Path $output 'patch-copy'
    $copyFile = Join-Path $copyRoot 'lib/executor/constant_arrival_rate.go'
    New-Item -ItemType Directory -Force (Split-Path $copyFile) | Out-Null
    [IO.File]::WriteAllBytes($copyFile, $original)
    $gitDirectory = Join-Path $source '.git'
    $patch = Join-Path $PSScriptRoot 'arrival-slot-boundary.patch'
    & git -C $copyRoot "--git-dir=$gitDirectory" "--work-tree=$copyRoot" apply --check $patch
    if ($LASTEXITCODE) { throw 'Pinned patch check failed.' }
    & git -C $copyRoot "--git-dir=$gitDirectory" "--work-tree=$copyRoot" apply $patch
    if ($LASTEXITCODE) { throw 'Pinned patch apply failed.' }
    $body = [IO.File]::ReadAllText($copyFile).Replace("`r`n", "`n")
}
$runtimeFile = Join-Path $output 'runtime.go'
[IO.File]::WriteAllText($runtimeFile, $body, [Text.UTF8Encoding]::new($false))
$runtimeReplace = @{}
$runtimeReplace[$target] = $runtimeFile
$runtimeOverlay = Join-Path $output 'runtime-overlay.json'
[IO.File]::WriteAllText($runtimeOverlay, (@{Replace=$runtimeReplace} | ConvertTo-Json -Depth 4), [Text.UTF8Encoding]::new($false))
$replacements = [ordered]@{
    'getDurationContexts(parentCtx, duration, gracefulStop)' = 'boundaryContexts(parentCtx, duration, gracefulStop)'
    'time.Since(startTime)' = 'boundarySince(startTime)'
    'time.NewTimer(time.Hour * 24)' = 'boundaryNewTimer(time.Hour * 24)'
    'case <-timer.C:' = "case <-timer.C:`n`t`t`tboundaryAfterTimer()"
}
foreach ($item in $replacements.GetEnumerator()) {
    if (-not $body.Contains($item.Key)) { throw ('Clock seam missing: ' + $item.Key) }
    $body = $body.Replace($item.Key, $item.Value)
}
$instrumented = Join-Path $output 'instrumented.go'
[IO.File]::WriteAllText($instrumented, $body, [Text.UTF8Encoding]::new($false))
$replace = @{}
$replace[$target] = $instrumented
$replace[(Join-Path $source 'lib/executor/lbg_arrival_boundary_test.go')] = Join-Path $PSScriptRoot 'arrival_boundary_test.go'
$overlay = Join-Path $output 'overlay.json'
[IO.File]::WriteAllText($overlay, (@{Replace=$replace} | ConvertTo-Json -Depth 4), [Text.UTF8Encoding]::new($false))
Push-Location $source
try {
    $raceArgs = @()
    if ($Race) { $raceArgs += '-race' }
    & go test -mod=vendor @raceArgs "-overlay=$overlay" ./lib/executor -run '^TestBoundary' -count=1 -timeout=60s -v
    $result = $LASTEXITCODE
    if ($result -eq 0 -and $UpstreamChecks) {
        & go test -mod=vendor @raceArgs "-overlay=$runtimeOverlay" ./lib/executor -run '^TestConstantArrivalRate' -count=1 -timeout=120s -v
        $result = $LASTEXITCODE
    }
    if ($result -eq 0 -and $BuildBinary) {
        $binaryName = 'k6-boundary'
        if ($IsWindows) { $binaryName += '.exe' }
        $binary = Join-Path $output $binaryName
        & go build -mod=vendor "-overlay=$runtimeOverlay" -o $binary .
        $result = $LASTEXITCODE
        if ($result -eq 0) {
            & $binary version
            $result = $LASTEXITCODE
            $metadata = [ordered]@{
                upstream_revision=$revision; mode=$Mode; go=(& go version)
                runtime_sha256=(Get-FileHash $runtimeFile).Hash.ToLowerInvariant()
                binary_sha256=(Get-FileHash $binary).Hash.ToLowerInvariant()
                instrumented_clock=$false
            }
            [IO.File]::WriteAllText((Join-Path $output 'binary-provenance.json'), ($metadata | ConvertTo-Json), [Text.UTF8Encoding]::new($false))
            Write-Output ($metadata | ConvertTo-Json)
        }
    }
} finally { Pop-Location }
exit $result
