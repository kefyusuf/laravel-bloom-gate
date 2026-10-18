param([ValidateSet('all', 'conflicting-modes', 'invalid-negative', 'unsafe-negative', 'coverage-failure', 'admission-failure', 'success', 'short', 'default', 'timeout-after-cleanup')][string]$Case = 'all')
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '../../..')).Path
$evidence = Join-Path $repo '.build/loss-diagnostic'
New-Item -ItemType Directory -Force $evidence | Out-Null
$script:assertions = 0
function Assert-True([bool]$Condition, [string]$Message) {
    $script:assertions++
    if (-not $Condition) { throw "Assertion failed: $Message" }
}
# Get-Process may still see a reaped-pending Linux zombie after tree kill.
# Treat only an absent PID or Linux's terminal Z/X states as stopped.
# Running, sleeping or uninspectable processes must fail closed.
function Test-DescendantStopped([int]$ProcessId, [datetime]$ExpectedStartTime) {
    try { $candidate = Get-Process -Id $ProcessId -ErrorAction Stop }
    catch {
        # A missing PID means the owned child has exited; inspection errors do not.
        return ($_.CategoryInfo.Category -eq [Management.Automation.ErrorCategory]::ObjectNotFound)
    }
    try {
        if ($candidate.StartTime -ne $ExpectedStartTime) { return $true }
    } catch { return $false }
    if ($IsLinux) {
        $statusPath = "/proc/$ProcessId/status"
        $stateLine = Get-Content -LiteralPath $statusPath -ErrorAction SilentlyContinue |
            Where-Object { $_ -match '^State:' } | Select-Object -First 1
        if ($stateLine -match '^State:\s+[ZX]\b') { return $true }
    }
    # Recheck both identity and presence in case the PID was recycled meanwhile.
    try { $candidate = Get-Process -Id $ProcessId -ErrorAction Stop }
    catch {
        return ($_.CategoryInfo.Category -eq [Management.Automation.ErrorCategory]::ObjectNotFound)
    }
    try { return ($candidate.StartTime -ne $ExpectedStartTime) }
    catch { return $false }
}
# Regression RED: a reused PID with a different creation time is no longer
# the original live descendant. The previous PID-only predicate fails here.
$liveStart = (Get-Process -Id $PID -ErrorAction Stop).StartTime
Assert-True (Test-DescendantStopped $PID ($liveStart.AddTicks(-1))) 'reused PID must not be mistaken for the original descendant'
function Invoke-CollectorCase([string]$Scenario) {
    $owned = Join-Path $evidence ('synthetic-' + [Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory $owned | Out-Null
    $process = $null
    try {
        $fixture = Join-Path $owned 'tests/Experiments/Swoole'
        New-Item -ItemType Directory -Force $fixture | Out-Null
        Copy-Item (Join-Path $PSScriptRoot '*') $fixture -Recurse
        $bin = Join-Path $owned 'bin'
        $hooks = Join-Path $owned 'empty-hooks'
        New-Item -ItemType Directory $bin, $hooks | Out-Null
        [IO.File]::WriteAllText((Join-Path $owned '.gitignore'), "bin/`nempty-hooks/`n.build/`nstate.json`ncalls.jsonl`n")
        & git -C $owned init -q
        & git -C $owned add .
        & git -C $owned -c user.name=CollectorFixture -c user.email=fixture@example.invalid -c core.hooksPath=$hooks commit -qm 'Synthetic collector fixture'
        if ($LASTEXITCODE) { throw 'Synthetic checkout initialization failed.' }
        $pwsh = (Get-Process -Id $PID).Path
        $python = (Get-Command python -ErrorAction Stop).Source
        $boundary = Join-Path $fixture 'collector-docker-fixture.py'
        if ($IsWindows) {
            [IO.File]::WriteAllText((Join-Path $bin 'docker.cmd'), "@echo off`r`n`"$python`" `"$boundary`" %*`r`nexit /b %errorlevel%`r`n")
        } else {
            [IO.File]::WriteAllText((Join-Path $bin 'docker'), "#!/bin/sh`nexec '$python' '$boundary' `"`$@`"`n")
            & chmod +x (Join-Path $bin 'docker')
        }
        $calls = Join-Path $owned 'calls.jsonl'
        $arguments = @('-NoProfile', '-File', (Join-Path $fixture 'qualify-generator.ps1'), '-Task', 'synthetic-case')
        if ($Scenario -eq 'conflicting-modes') { $arguments += @('-Diagnostic', '-LossDiagnostic') }
        elseif ($Scenario -eq 'short') { $arguments += '-Diagnostic' }
        elseif ($Scenario -ne 'default') { $arguments += '-LossDiagnostic' }
        $stdoutPath = Join-Path $bin 'stdout.txt'
        $stderrPath = Join-Path $bin 'stderr.txt'
        $launcher = Join-Path $bin 'run-collector.py'
        [IO.File]::WriteAllText($launcher, @'
import os
import subprocess
import sys
with open(os.environ['COLLECTOR_STDOUT'], 'wb') as stdout, open(os.environ['COLLECTOR_STDERR'], 'wb') as stderr:
    result = subprocess.run(sys.argv[1:], stdout=stdout, stderr=stderr)
sys.exit(result.returncode)
'@)
        $start = [Diagnostics.ProcessStartInfo]::new($python)
        $start.UseShellExecute = $false
        $start.CreateNoWindow = $true
        $start.WorkingDirectory = $owned
        $start.ArgumentList.Add($launcher)
        $start.ArgumentList.Add($pwsh)
        foreach ($arg in $arguments) { $start.ArgumentList.Add($arg) }
        $start.Environment['PATH'] = $bin + [IO.Path]::PathSeparator + $env:PATH
        $start.Environment['COLLECTOR_CALLS'] = $calls
        $start.Environment['COLLECTOR_ROOT'] = $owned
        $start.Environment['COLLECTOR_CASE'] = $Scenario
        $start.Environment['COLLECTOR_STDOUT'] = $stdoutPath
        $start.Environment['COLLECTOR_STDERR'] = $stderrPath
        $process = [Diagnostics.Process]::Start($start)
        $deadline = [Diagnostics.Stopwatch]::StartNew()
        $limitSeconds = if ($Scenario -eq 'timeout-after-cleanup') { 30 } else { 180 }
        while (-not $process.HasExited -and $deadline.Elapsed.TotalSeconds -lt $limitSeconds) {
            Start-Sleep -Milliseconds 100
        }
        if (-not $process.HasExited) {
            if ($Scenario -eq 'timeout-after-cleanup') {
                # Capture the owned child identity while it is still sleeping.
                # Refuse to certify cleanup if identity cannot be inspected.
                $stalledPid = [int](Get-Content (Join-Path $owned '.build/generator-qualification/stalled-docker-pid.txt'))
                $stalledStartTime = (Get-Process -Id $stalledPid -ErrorAction Stop).StartTime
            }
            $process.Kill($true)
            $process.WaitForExit()
            if ($Scenario -eq 'timeout-after-cleanup') {
                $outcomePath = Join-Path $owned '.build/generator-qualification/synthetic-case-outcome.json'
                Assert-True ((Get-Content $outcomePath -Raw | ConvertFrom-Json).verdict -eq 'INCONCLUSIVE') 'timeout fixture reached expected collector rejection'
                $liveStartTime = (Get-Process -Id $PID -ErrorAction Stop).StartTime
                Assert-True (-not (Test-DescendantStopped $PID $liveStartTime)) 'live test parent cannot be mistaken for a stopped descendant'
                # Kill(true)/WaitForExit() waits for the launcher, not its children.
                # Bound the descendant check; do not accept a still-running child.
                $childWait = [Diagnostics.Stopwatch]::StartNew()
                while ($childWait.Elapsed.TotalSeconds -lt 5 -and -not (Test-DescendantStopped $stalledPid $stalledStartTime)) {
                    Start-Sleep -Milliseconds 100
                }
                Assert-True (Test-DescendantStopped $stalledPid $stalledStartTime) 'deadline kills the owned descendant fixture'
                return
            }
            $diagnostics = @("Scenario: $Scenario", "Owned PID: $($process.Id)", "HasExited: $($process.HasExited)",
                'Last synthetic Docker commands:')
            if (Test-Path $calls) { $diagnostics += Get-Content $calls -Tail 5 }
            $diagnostics += 'Captured stdout:', [IO.File]::ReadAllText($stdoutPath)
            $diagnostics += 'Captured stderr:', [IO.File]::ReadAllText($stderrPath)
            [IO.File]::WriteAllText((Join-Path $evidence "$Scenario-timeout.txt"), ($diagnostics -join "`n"))
            if (-not $process.HasExited) { $process.Kill($true) }
            throw 'Collector subprocess exceeded test bound.'
        }
        $output = [IO.File]::ReadAllText($stdoutPath) + [IO.File]::ReadAllText($stderrPath)
        if ($Scenario -eq 'timeout-after-cleanup') { throw 'Assertion failed: timed-out collector escaped the parent deadline rejection.' }
        [IO.File]::WriteAllText((Join-Path $evidence "$Scenario-subprocess.txt"), $output)
        if ($Scenario -eq 'conflicting-modes') {
            Assert-True ($process.ExitCode -ne 0) 'conflicting modes must fail'
            Assert-True ($output.Contains('Diagnostic and LossDiagnostic are mutually exclusive.')) 'intended mode refusal'
            Assert-True (-not (Test-Path $calls)) 'conflicting modes fail before Docker'
            return
        }
        $commands = @(Get-Content $calls | ForEach-Object { ,($_ | ConvertFrom-Json -NoEnumerate) })
        $runs = @($commands | Where-Object { $_[0] -eq 'compose' -and $_[5] -eq 'run' })
        $artifacts = Join-Path $owned '.build/generator-qualification'
        $outcome = Get-Content (Join-Path $artifacts 'synthetic-case-outcome.json') -Raw | ConvertFrom-Json
        Assert-True (@($commands | Where-Object { $_[0] -eq 'build' }).Count -eq 1) 'actual pinned build helper reaches Docker boundary'
        Assert-True (@($commands | Where-Object { $_[0] -eq 'run' -and $_ -contains 'none' }).Count -eq 1) 'actual verify helper performs network-none readback'
        $stackCleanup = @($commands | Where-Object { $_[0] -eq 'compose' -and $_[5] -eq 'down' })
        Assert-True ($stackCleanup.Count -eq 1 -and $stackCleanup[0][2] -eq 'lbg-generator-synthetic-case' -and $stackCleanup[0] -contains '--volumes' -and $stackCleanup[0] -contains '--remove-orphans') 'only exact owned stack cleaned'
        $imageCleanup = @($commands | Where-Object { $_[0] -eq 'image' -and $_[1] -eq 'rm' })
        Assert-True ($imageCleanup.Count -eq 2 -and $imageCleanup[0].Count -eq 3 -and $imageCleanup[1].Count -eq 3 -and $imageCleanup[0][2] -eq 'lbg-generator-php:synthetic-case' -and $imageCleanup[1][2] -eq 'lbg-generator-k6:synthetic-case') 'only exact owned images cleaned'
        $containerCleanup = @($commands | Where-Object { $_[0] -eq 'rm' })
        Assert-True ($containerCleanup.Count -eq $runs.Count -and @($containerCleanup | Where-Object { $_.Count -ne 2 -or $_[1] -ne 'lbg-generator-synthetic-case-load' }).Count -eq 0) 'only exact owned load containers cleaned'
        if ($Scenario -in @('invalid-negative', 'unsafe-negative')) {
            Assert-True ($process.ExitCode -ne 0 -and $outcome.verdict -eq 'INCONCLUSIVE') 'invalid control rejected'
            Assert-True ($runs.Count -eq 1) 'invalid control blocks all positive work'
            Assert-True ($runs[0] -contains 'RATE=1200' -and $runs[0] -contains 'VUS=128') 'existing deficient control retained'
            return
        }
        if ($Scenario -eq 'short') {
            Assert-True ($process.ExitCode -eq 0) 'old short diagnostic succeeds'
            Assert-True ($runs.Count -eq 1 -and $runs[0] -contains 'WARMUP=5' -and $runs[0] -contains 'DURATION=15') 'old short profile unchanged'
            Assert-True ($outcome.verdict -eq 'DIAGNOSTIC_ONLY') 'old short outcome unchanged'
            return
        }
        if ($Scenario -eq 'default') {
            Assert-True ($process.ExitCode -eq 0) 'default qualification succeeds'
            Assert-True ($runs.Count -eq 13) 'default retains one control and twelve positive cells'
            Assert-True ($outcome.verdict -eq 'PASS' -and $outcome.profiles.Count -eq 12) 'default aggregate unchanged'
            $index = 1
            foreach ($block in 1..3) {
                foreach ($delay in @(0, 25, 100, 150)) {
                    Assert-True ($runs[$index] -contains "BLOCK=$block" -and $runs[$index] -contains "CONTROLLED_DELAY_MS=$delay" -and $runs[$index] -contains 'RATE=4800' -and $runs[$index] -contains 'VUS=1024' -and $runs[$index] -contains 'WARMUP=30' -and $runs[$index] -contains 'DURATION=60') "default block $block delay $delay exact profile"
                    $index++
                }
            }
            return
        }
        Assert-True ($runs.Count -eq 2) 'one existing negative and exactly one positive cell'
        Assert-True ($runs[0] -contains 'RATE=1200' -and $runs[0] -contains 'CONTROLLED_DELAY_MS=150' -and $runs[0] -contains 'VUS=128' -and $runs[0] -contains 'WARMUP=2' -and $runs[0] -contains 'DURATION=5') 'exact control profile retained'
        Assert-True ($runs[1] -contains 'RATE=4800' -and $runs[1] -contains 'CONTROLLED_DELAY_MS=25' -and $runs[1] -contains 'VUS=1024' -and $runs[1] -contains 'WARMUP=30' -and $runs[1] -contains 'DURATION=60') 'exact long loss diagnostic profile'
        Assert-True (@($commands | Where-Object { $_ -contains '--qualification' }).Count -eq 0) 'loss diagnostic never invokes aggregate admission'
        Assert-True ($outcome.profiles.Count -eq 1) 'attempted positive profile retained in outcome'
        $profile = $outcome.profiles[0]
        $attempt = Get-Content (Join-Path $artifacts 'synthetic-case-attempt.json') -Raw | ConvertFrom-Json
        Assert-True ($profile.raw -eq 'synthetic-case-loss-diagnostic-delay-25.json' -and $attempt.raw -eq $profile.raw) 'attempted raw reference persisted before admission'
        Assert-True ($null -ne $profile.cell -and $profile.cell.identity.source_ref -eq $outcome.identity.source_ref) 'raw cell identity and receiver accounting retained'
        foreach ($suffix in @('.json', '-cell.json', '-coverage.json', '-resources.json', '-timeline.jsonl', '-generator-cgroup.txt', '.log')) {
            Assert-True (Test-Path (Join-Path $artifacts ('synthetic-case-loss-diagnostic-delay-25' + $suffix))) "retained diagnostic artifact $suffix"
        }
        if ($Scenario -in @('coverage-failure', 'admission-failure')) {
            Assert-True ($process.ExitCode -ne 0 -and $outcome.verdict -eq 'INCONCLUSIVE') 'failed diagnostic stays inconclusive'
            if ($Scenario -eq 'coverage-failure') {
                Assert-True ($profile.coverage.verdict -eq 'INCONCLUSIVE' -and $attempt.coverage.verdict -eq 'INCONCLUSIVE') 'failed coverage retained before throw'
                Assert-True ($null -eq $profile.evaluation -and $null -eq $attempt.evaluation -and -not (Test-Path (Join-Path $artifacts $profile.artifact_references.evaluation))) 'missing evaluation explicit and not fabricated'
                Assert-True ($outcome.reasons[0] -eq 'An observation was unavailable during measurement.' -and $profile.coverage.reasons[0] -eq $outcome.reasons[0]) 'exact coverage failure reason retained'
                Assert-True (@($commands | Where-Object { $_ -contains '/fixture/generator-evaluate.php' -and $_ -contains '/results/synthetic-case-loss-diagnostic-delay-25-cell.json' }).Count -eq 0) 'failed coverage prevents positive evaluation'
            } else {
                Assert-True ($profile.evaluation.verdict -eq 'INCONCLUSIVE' -and $attempt.evaluation.verdict -eq 'INCONCLUSIVE') 'failed admission retained before throw'
                Assert-True ($outcome.reasons[0] -eq 'dropped_iterations must be zero.' -and $profile.evaluation.reasons[0] -eq $outcome.reasons[0]) 'exact admission failure reason retained'
                Assert-True (Test-Path (Join-Path $artifacts $profile.artifact_references.evaluation)) 'available failed evaluation artifact retained'
            }
        } else {
            Assert-True ($process.ExitCode -eq 0 -and $outcome.verdict -eq 'DIAGNOSTIC_ONLY') 'success never aggregate PASS'
            Assert-True ($outcome.reproduction -eq 'NOT_REPRODUCED') 'success records non-reproduction'
            Assert-True ($outcome.scope.Contains('capacity') -and $outcome.scope.Contains('cause')) 'success states capacity and cause limits'
            Assert-True ($profile.evaluation.verdict -eq 'PASS' -and $attempt.evaluation.verdict -eq 'PASS' -and (Test-Path (Join-Path $artifacts $profile.artifact_references.evaluation))) 'successful cell evaluation retained without aggregate admission'
        }
    } finally {
        if ($null -ne $process) {
            if (-not $process.HasExited) { $process.Kill($true); $process.WaitForExit() }
            $process.Dispose()
        }
        $resolved = [IO.Path]::GetFullPath($owned)
        if (-not $resolved.StartsWith([IO.Path]::GetFullPath($evidence) + [IO.Path]::DirectorySeparatorChar)) { throw 'Unsafe synthetic checkout cleanup path.' }
        Remove-Item -LiteralPath $resolved -Recurse -Force
    }
}
$cases = if ($Case -eq 'all') { @('conflicting-modes', 'invalid-negative', 'unsafe-negative', 'coverage-failure', 'admission-failure', 'success', 'short', 'default', 'timeout-after-cleanup') } else { @($Case) }
foreach ($scenario in $cases) {
    Invoke-CollectorCase $scenario
    Write-Host "PASS $scenario (actual collector subprocess; synthetic Docker boundary only)"
}
Write-Host "Tests: $($cases.Count) passed ($script:assertions assertions)"
