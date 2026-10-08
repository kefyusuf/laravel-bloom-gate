# Pinned k6 arrival boundary correction

This fixture targets public upstream k6 v1.3.0, commit
`5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb`. It retains the upstream
[AGPL-3.0 source license](https://github.com/grafana/k6/blob/5870e99ae8a690a2b0bfc9a7dd2b5feb7c9851bb/LICENSE.md).
Only the small correction patch and package-owned boundary tests are stored here.

The seam is the real `ConstantArrivalRate.Run` result: accepted runner work plus
actual dropped-iteration samples. The Go overlay controls only external time,
timer delivery and context cancellation. It retains the upstream loop, striped
indices, VU pool, MiniRunner and drop accounting. There is no copied scheduler
model and no HTTP call. Virtual-time tests are not a capacity benchmark.

The patch adds an exact integer `ceil(rate × duration / timeUnit)` bound to the
global half-open slot schedule and checks regular cancellation after timer
selection. Original offsets/timer targets, fixed VU handling and visible drop
accounting remain. This prevents extra terminal slots; it does not guarantee
completeness, atomically couple dispatch to the deadline, or fix a host clock.
The existing qualification must still reject missing work, drops and errors.

```powershell
git clone --depth 1 --branch v1.3.0 https://github.com/grafana/k6.git .build/k6-terminal-fix/upstream
# Expected RED: original executor offers extra terminal work.
./tests/Experiments/Swoole/K6Boundary/verify.ps1 -SourcePath .build/k6-terminal-fix/upstream -Mode Baseline
# GREEN: actual component tests; uninstrumented upstream tests and binary.
./tests/Experiments/Swoole/K6Boundary/verify.ps1 -SourcePath .build/k6-terminal-fix/upstream -Mode Patched -UpstreamChecks -BuildBinary
```

Run commands sequentially from the package root; output uses the same task-local
directory. The source checkout must be clean and match the exact revision.
Patch application occurs in a copy, with Go overlays leaving the original source
unchanged. `-Race` adds the Go race detector on a supported host. CI uses Linux
and Go 1.23.7; local proof records its actual toolchain. A single first-runner
barrier proves busy-VU loss; failure to enter the runner fails the test.

Go 1.23's vet requires the added test file on disk. The script creates only its
previously absent test file in the verified checkout, then removes it in a
finally block before upstream checks/build. Original files and vet stay intact.

The binary is `.build/k6-terminal-fix/k6-boundary[.exe]`. Its version banner
still identifies upstream v1.3.0; `binary-provenance.json` distinguishes the
patched runtime source, exact upstream revision, toolchain and binary digest.
It contains no test clock hooks. No result from PR #95 gains PASS retroactively.

## Task-local Linux measurement image

`Dockerfile` builds the validated runtime patch with pinned Go 1.23.7 and the
vendored upstream dependencies. Source acquisition uses the exact upstream
commit archive and checks its SHA-256 before extraction. The patch utility is
pinned to Alpine `patch=2.7.6-r10`. Both build and runtime base manifests are
pinned. Compilation disables toolchain/module downloads and runs with no
network. No test overlay or clock hook enters the binary.

```powershell
./tests/Experiments/Swoole/K6Boundary/build-image.ps1 -Task 20261008-boundary-smoke
./tests/Experiments/Swoole/K6Boundary/smoke-image.ps1 -Image lbg-generator-k6:20261008-boundary-smoke
./tests/Experiments/Swoole/K6Boundary/verify-image.ps1 -Image <immutable-image-id>
# Repeatable integration proof with automatic current-task image cleanup:
./tests/Experiments/Swoole/K6Boundary/test-image.ps1 -Task 20261008-boundary-proof
```

Build context is this directory. The builder refuses an existing task tag and
returns verified JSON after building `lbg-generator-k6:<Task>`. Build output
uses the host stream. `verify-image.ps1` resolves the immutable image ID and
independently hashes the installed binary, retained runtime source and patch;
it also checks the embedded provenance and actual k6/Go version. Readback runs
with `--network none --read-only --cap-drop ALL` and removes its own container.
The smoke script rejects the unpatched upstream image, checks tag/ID agreement
and verifies that an existing task tag cannot be overwritten. It sends no HTTP
requests and runs no load script.

`test-image.ps1` combines the original-image RED, patched-image GREEN and a
derived image whose embedded binary digest is deliberately forged. The actual
installed binary remains unchanged in that negative fixture; admission must
reject the false embedded digest. The script refuses pre-existing test tags and
removes only its own patched/forged images in `finally`. It returns the verified
JSON and emits proof/cleanup labels through the host stream. Every runtime
readback and the forged-image build runs with network disabled. Base/source
acquisition during the patched build can require network access.

The caller must remove only its newly built task image when finished. Existing
base images and unrelated Docker resources must remain. Builds leave ordinary
BuildKit cache; no global prune is part of these helpers. Image smoke establishes
provenance and startup only; capacity qualification requires its own approved,
frozen single-attempt plan and unchanged admission guards.
