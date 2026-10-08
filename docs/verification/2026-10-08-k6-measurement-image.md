# Patched k6 measurement image integration

## Scope and result

The measurement collector now builds and verifies a current-task Linux/amd64
image containing the reviewed PR #97 arrival-slot correction. This is image
integration and provenance proof only. No HTTP generator qualification ran;
PR #95 remains INCONCLUSIVE and its count/resource/negative-control guards are
unchanged. A fresh qualification requires its own frozen reviewed source and
one approved attempt, stopping on the first failing cell.

## Installed provenance

The recipe pins upstream commit `5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb`,
the upstream archive SHA-256, Go 1.23.7 builder and original k6 runtime base
digests. It builds the patched source with vendored dependencies and networking
disabled. The runtime source contains no test clock hooks. Image readback runs
with networking disabled, read-only filesystem and dropped capabilities.

Observed installed hashes from the task-local proof:

| Item | SHA-256 |
| --- | --- |
| Patched executor source | `e5cbf62b0eebd7088df5090046adf83d1793fed47279810d3300546cc724ccce` |
| Correction patch | `1232b8e7feded527f85ba0f7ea4e69823cbf258f53e537ad1fcbdb11bad8e4d1` |
| Linux binary | `80dfd2c0b536ddbb9aa5a09dff9c5aef6fe4b63fea66aad45cb7bad818ab6b3b` |

The binary banner is `k6 v1.3.0 (commit/devel, go1.23.7, linux/amd64)` because
the build uses the verified source archive. The separate provenance carries
the exact upstream revision and correction identity; the banner alone is
insufficient. The observed image ID is
`sha256:ea63582e5bac758c18b721344a219e577d15a35ffdb88ee166fe81583562b460`.
Build attestations can give subsequent builds different image IDs; each attempt
must read its actual immutable ID and installed binary instead of reusing this ID.

## Admission and tests

The collector refuses preexisting task generator tags, verifies the installed
image before starting the receiver, records provenance and frozen build/helper/
patch sources, and requires the actual running generator image ID to match.
Compose cannot silently pull an upstream replacement. Report/CLI admission
requires matching image, revision, patched source, patch, Go version, binary
digest and `instrumented_clock=false`. Report admission validates supplied
observations; the live collector separately authenticates installed files.

- RED: the old report returned PASS without patched-image provenance.
- GREEN: 105 measurement tests / 131 assertions; substitution rejection is
  exercised through the real evaluator CLI, including test clocks, source,
  toolchain, image and patch changes and absent binary digest.
- Actual image RED: the original upstream image lacks patched provenance.
- Actual image GREEN: installed binary/source/patch hashes and version match;
  tag and immutable-ID readbacks agree; duplicate task build is refused.
- A real derived image with forged embedded binary digest is rejected against
  the independently hashed installed binary.
- Five existing JavaScript window tests passed. Changed PHP files pass Pint
  and scoped PHPStan. PowerShell parsing and Compose configuration passed.
- Local PHP 8.5 fast tests: 934 passed, 13 optional extension tests skipped,
  7,475 assertions. Fresh dependencies resolved Laravel 13.35 / Larastan 3.13;
  whole-package PHPStan reports nine errors in unchanged console command input
  types. These do not appear in the changed files; hosted exact-head checks
  must resolve the delivery gate before merge.

Retained logs are in [evidence](evidence/2026-10-08-k6-measurement-image/).
The manifest hashes the normalized tracked artifacts; these are component and
image proof, not a new qualification result.

## Reproduction and cleanup

```powershell
./tests/Experiments/Swoole/K6Boundary/test-image.ps1 -Task unique-image-proof
```

The same image test is included in the k6 boundary CI workflow. It acquires the
exact original runtime pin before the RED test on a fresh runner. Acquisition
failure cannot count as behavioral RED. No image is published.

Owned patched smoke/proof and forged proof image tags were removed. All image
readback containers used `--rm`; the package quality container is removed at
task closure. No Compose stack, network, volume or worktree was created. Existing
resources, dependency base images and BuildKit cache are retained.
