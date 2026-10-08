param([Parameter(Mandatory)][string]$Image)
$ErrorActionPreference = 'Stop'
$expectedRevision = '5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb'
$expectedRuntime = 'e5cbf62b0eebd7088df5090046adf83d1793fed47279810d3300546cc724ccce'
$expectedPatch = (Get-FileHash (Join-Path $PSScriptRoot 'arrival-slot-boundary.patch')).Hash.ToLowerInvariant()
$imageId = & docker image inspect $Image --format '{{.Id}}'
if ($LASTEXITCODE -ne 0 -or $imageId -notmatch '^sha256:[a-f0-9]{64}$') { throw 'Image identity unavailable.' }
$readback = & docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges --entrypoint /bin/sh $imageId -ec 'cat /opt/lbg-k6/provenance.json; sha256sum /usr/bin/k6 /opt/lbg-k6/runtime.go /opt/lbg-k6/arrival-slot-boundary.patch; /usr/bin/k6 version'
if ($LASTEXITCODE -ne 0) { throw 'Image provenance readback failed.' }
if ($readback.Count -ne 5) { throw 'Unexpected provenance readback shape.' }
$embedded = $readback[0] | ConvertFrom-Json
$binaryHash = ($readback[1] -split '\s+')[0]
$runtimeHash = ($readback[2] -split '\s+')[0]
$patchHash = ($readback[3] -split '\s+')[0]
$version = $readback[4]
if ($embedded.upstream_revision -ne $expectedRevision -or $embedded.runtime_source_sha256 -ne $expectedRuntime -or
    $embedded.patch_sha256 -ne $expectedPatch -or $embedded.instrumented_clock -isnot [bool] -or
    $embedded.instrumented_clock -ne $false -or $runtimeHash -ne $expectedRuntime -or $patchHash -ne $expectedPatch -or
    $embedded.source_archive_sha256 -ne '2c50056f2ae1db089b0438d161a13ba4b2e6e53497413a6e99dec2047c7c0ec0' -or
    $embedded.builder_image -ne 'golang:1.23.7-alpine@sha256:e438c135c348bd7677fde18d1576c2f57f265d5dfa1a6b26fca975d4aa40b3bb' -or
    $embedded.runtime_base_image -ne 'grafana/k6:1.3.0@sha256:3ddc8b1a33a2c3d8edc6e99b6a762ae36cba08788463458f5e6a7703e14eb77d' -or
    $binaryHash -notmatch '^[a-f0-9]{64}$' -or $embedded.binary_sha256 -ne $binaryHash -or
    $version -notmatch '^k6 v1\.3\.0 .+go1\.23\.7, linux/amd64\)') { throw 'Pinned image provenance mismatch.' }
[ordered]@{ upstream_revision=$expectedRevision; runtime_source_sha256=$runtimeHash; patch_sha256=$patchHash;
    binary_sha256=$binaryHash; instrumented_clock=$false; image_id=$imageId; version=$version; go_version='go1.23.7';
    source_archive_sha256=$embedded.source_archive_sha256; builder_image=$embedded.builder_image;
    runtime_base_image=$embedded.runtime_base_image } | ConvertTo-Json
