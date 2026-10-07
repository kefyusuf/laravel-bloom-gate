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
It contains no test clock hooks. Default Compose and its pinned k6 image are
unchanged. Future Linux image integration and a new frozen, single-attempt
qualification are separate work; no result from PR #95 gains PASS retroactively.
