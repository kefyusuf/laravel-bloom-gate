param([Parameter(Mandatory)][ValidatePattern('^[a-z0-9][a-z0-9-]{0,63}$')][string]$Task)
$ErrorActionPreference = 'Stop'
$image = "lbg-generator-k6:$Task"
$forged = "lbg-generator-k6-forged:$Task"
& docker info --format '{{.OSType}}' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Docker unavailable.' }
foreach ($tag in @($image, $forged)) {
    & docker image inspect $tag 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) { throw "Test tag already exists: $tag" }
}
$verify = Join-Path $PSScriptRoot 'verify-image.ps1'
$attempted = $false
try {
    $original = 'grafana/k6:1.3.0@sha256:3ddc8b1a33a2c3d8edc6e99b6a762ae36cba08788463458f5e6a7703e14eb77d'
    & docker image inspect $original 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) {
        & docker pull --platform linux/amd64 $original 2>&1 | ForEach-Object { Write-Host $_ }
        if ($LASTEXITCODE -ne 0) { throw 'Pinned upstream base acquisition failed; RED not assessed.' }
    }
    $red = $false
    try { & $verify -Image $original | Out-Null } catch {
        if ($_.Exception.Message -ne 'Image provenance readback failed.') { throw }
        $red = $true
    }
    if (-not $red) { throw 'RED failed: upstream image was admitted.' }
    Write-Host 'RED: original upstream image rejected for missing patched provenance.'
    $attempted = $true
    $provenance = (& (Join-Path $PSScriptRoot 'build-image.ps1') -Task $Task) | ConvertFrom-Json
    & (Join-Path $PSScriptRoot 'smoke-image.ps1') -Image $image | Out-Null
    Write-Host 'GREEN: patched image passed independent installed-file hashes and version readback.'
    & docker build --platform linux/amd64 --network none --build-arg "BASE=$image" --tag $forged --file (Join-Path $PSScriptRoot 'forged-provenance.Dockerfile') $PSScriptRoot 2>&1 | ForEach-Object { Write-Host $_ }
    if ($LASTEXITCODE -ne 0) { throw 'Forged-metadata fixture build failed.' }
    $rejected = $false
    try { & $verify -Image $forged | Out-Null } catch {
        if ($_.Exception.Message -ne 'Pinned image provenance mismatch.') { throw }
        $rejected = $true
    }
    if (-not $rejected) { throw 'Forged embedded binary digest was admitted.' }
    Write-Host 'PASS: forged embedded binary digest rejected against actual installed binary.'
    $provenance | ConvertTo-Json
} finally {
    if ($attempted) {
        foreach ($tag in @($forged, $image)) {
            & docker image inspect $tag 2>$null | Out-Null
            if ($LASTEXITCODE -eq 0) {
                & docker image rm $tag | ForEach-Object { Write-Host $_ }
                if ($LASTEXITCODE -ne 0) { throw "Current-task image cleanup failed: $tag" }
            }
        }
        Write-Host 'CLEANUP: only current-task patched and forged tags removed; base images/cache retained.'
    }
}
