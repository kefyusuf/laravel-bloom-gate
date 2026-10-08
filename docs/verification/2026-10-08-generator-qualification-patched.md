# Frozen patched-generator qualification — 2026-10-08

## Outcome

**INCONCLUSIVE.** One invocation at reviewed source
`5185bf8d7bbe90078bd9fc2d72c3d0737e66ec79` attempted six of twelve positive
cells. Five were admitted; block 2 / 25 ms stopped the collector at unavailable
measurement observation coverage. The remaining six did not run. No retry,
source change, budget adjustment or tolerance relaxation occurred.

The failed cell independently loses 1,102 scheduled iterations, so fixing only
the observer cannot qualify this result. The completed/dropped total is exactly
432,000. The corrected executor's extra-terminal-work failure did not recur in
the six observed totals, but this does not establish the cause of PR #95 or
qualify generator capacity, application/SQL performance or production stability.
The earlier qualification remains INCONCLUSIVE.

| Profile | Actual-window responses | All-run completions | Drops | HTTP p99 ms | Admission |
| --- | ---: | ---: | ---: | ---: | --- |
| Negative, 150 ms / 1,200 RPS / 128 VUs | 4,210 | 5,888 | 2,512 | 152.119 | VALID_NEGATIVE_CONTROL |
| Block 1 / 0 ms | 287,995 | 432,000 | 0 | 2.610 | PASS |
| Block 1 / 25 ms | 287,993 | 432,000 | 0 | 40.646 | PASS |
| Block 1 / 100 ms | 287,988 | 432,000 | 0 | 110.094 | PASS |
| Block 1 / 150 ms | 287,990 | 432,000 | 0 | 154.237 | PASS |
| Block 2 / 0 ms | 287,991 | 432,000 | 0 | 4.502 | PASS |
| Block 2 / 25 ms | 286,993 | 430,898 | 1,102 | 139.225 | NOT ADMITTED |

The safe deficient control completes 5,888 + drops 2,512 = 8,400 offered.
Its safety validation passed before any positive cell. Client, builtin and
independent receiver counts agree for completed work in all six positive cells.
Errors, parity failures, false negatives, unknown membership, early receiver
responses and unfinished iterations are zero. These zero counters do not
override missing work, observation coverage or resource headroom failures.

## Failed-cell evidence and limits

The observer records an unavailable request at epoch `1791440862711`, finishing
at `1791440863213` (502 ms), inside measurement
`[1791440826204, 1791440886239)`. Its previous successful combined observation
takes 541 ms. The recorded reason is
`Observation endpoint is unavailable or exceeds its bound.` The observer uses
a 0.5-second request timeout and stops after its first unavailable observation
following success. The timing is consistent with a request timeout, but the
record does not distinguish the k6 metrics endpoint from the receiver endpoint,
nor timeout from an oversized response. It cannot prove endpoint/root cause.

Parsed timeline rows 23–24 already increase cumulative drops from zero to 39
at `1791440818619`, during warmup and about 44.1 seconds before the unavailable
observation. The last successful row 67 contains 456 drops; final count 1,102
adds 646 whose exact times are unavailable after observer termination. Thus the
initial loss predates coverage failure. Generator cgroup rows 65–67 independently
increase `nr_throttled` from 169 to 184 and `throttled_usec` from 45,603,422 to
50,517,554 around the unavailable observation. This does not prove causality.

Independent offline evaluation of the retained failed cell rejects
`dropped_iterations must be zero.` The summary also flags generator saturation;
loss makes local iteration indices unavailable for the nominal window audit.
Observed Docker-stats maxima are generator 469.45% and receiver 243.82%, above
the unchanged headroom limits of 360% and 180%. These observations are not proof
that Docker ignored the CPU quota or that host contention caused the failure.
Docker-stats timestamps are assigned after collection; exact alignment of its
averaging intervals with the observer snapshots is not established.
There is no OOM, receiver restart or generator exit failure.

Next: correlate retained cumulative snapshots and cgroup deltas around the
loss/unavailable interval before proposing a fix. If live diagnostics remain
insufficient, first add bounded per-endpoint stage/timing/failure evidence through
the real observer CLI seam with TDD and review. Preserve zero-drop admission,
two-second coverage, fixed budgets and the single-attempt policy. A fresh timing
run needs its own reviewed frozen plan; this record cannot gain PASS retroactively.

## Frozen provenance and retained evidence

Task: `20261008-qualification-patched-v1`. The verified k6 binary digest is
`80dfd2c0b536ddbb9aa5a09dff9c5aef6fe4b63fea66aad45cb7bad818ab6b3b`;
patched executor source is
`e5cbf62b0eebd7088df5090046adf83d1793fed47279810d3300546cc724ccce`.
The installed image ID, patch, pinned builder/runtime bases and all sixteen
fixture source hashes are recorded in the run identity. Runtime is PHP 8.4.26 /
OpenSwoole 26.2.0, four distinct stable workers. Positive cells use 4,800 RPS /
1,024 fixed VUs, 30-second warmup and 60-second measurement. Generator budget
is four CPUs / 4 GiB; receiver is two CPUs / 256 MiB. Host readback is twelve
CPUs / 16,440,238,080 bytes RAM. Only package-owned synthetic fixtures ran.

[Evidence](evidence/2026-10-08-generator-qualification-patched/) retains all
55 task artifacts, including the sixth cell omitted from the five-admitted-cell
outcome array. Its manifest hashes UTF-8/LF tracked content; log extensions become
`.txt` and ANSI/trailing whitespace is removed. No result value is rewritten.

The collector removed its load/receiver containers, unique Compose network and
both task images. No named volume or worktree was created. Existing Docker
resources, dependency bases and build cache are retained; no global prune ran.
