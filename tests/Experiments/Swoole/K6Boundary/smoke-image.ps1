param([Parameter(Mandatory)][string]$Image)
$ErrorActionPreference = 'Stop'
$verify = Join-Path $PSScriptRoot 'verify-image.ps1'
$original = 'grafana/k6:1.3.0@sha256:3ddc8b1a33a2c3d8edc6e99b6a762ae36cba08788463458f5e6a7703e14eb77d'
$rejected = $false
try { & $verify -Image $original | Out-Null } catch {
    if ($_.Exception.Message -ne 'Image provenance readback failed.') { throw }
    $rejected = $true
}
if (-not $rejected) { throw 'Unpatched upstream image was admitted.' }
$fromTag = (& $verify -Image $Image) | ConvertFrom-Json
$fromId = (& $verify -Image $fromTag.image_id) | ConvertFrom-Json
if ($fromId.binary_sha256 -ne $fromTag.binary_sha256 -or $fromId.image_id -ne $fromTag.image_id) {
    throw 'Tag and immutable image readbacks disagree.'
}
if ($Image -match '^lbg-generator-k6:([a-z0-9][a-z0-9-]{0,63})$') {
    $taskLabel = $Matches[1]
    $refused = $false
    try { & (Join-Path $PSScriptRoot 'build-image.ps1') -Task $taskLabel | Out-Null } catch {
        if ($_.Exception.Message -ne 'Current-task image tag already exists; refusing overwrite.') { throw }
        $refused = $true
    }
    if (-not $refused) { throw 'Existing task image was overwritten.' }
    $after = & docker image inspect $Image --format '{{.Id}}'
    if ($LASTEXITCODE -ne 0 -or $after -ne $fromTag.image_id) { throw 'Existing image changed.' }
}
Write-Host 'PASS: upstream rejected; installed hashes/version verified; tag and image ID agree; existing tag preserved.'
$fromTag | ConvertTo-Json
