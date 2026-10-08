param([Parameter(Mandatory)][ValidatePattern('^[a-z0-9][a-z0-9-]{0,63}$')][string]$Task)
$ErrorActionPreference = 'Stop'
$image = "lbg-generator-k6:$Task"
& docker info --format '{{.OSType}}' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Docker unavailable.' }
& docker image inspect $image 2>$null | Out-Null
if ($LASTEXITCODE -eq 0) { throw 'Current-task image tag already exists; refusing overwrite.' }
& docker build --platform linux/amd64 --tag $image --file (Join-Path $PSScriptRoot 'Dockerfile') $PSScriptRoot 2>&1 | ForEach-Object { Write-Host $_ }
if ($LASTEXITCODE -ne 0) { throw 'Pinned k6 image build failed.' }
& (Join-Path $PSScriptRoot 'verify-image.ps1') -Image $image
