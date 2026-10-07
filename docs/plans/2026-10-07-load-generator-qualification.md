# Load generator qualification

The user approved qualifying generation before repeating application measurement.
This increment delivers that qualification only, using synthetic keys, no SQL,
no Redis and no private application. Public seams: controlled HTTP receiver,
independent receiver counters, actual k6 output and report admission. Use TDD,
task branch/commit, independent PR review, exact-head CI and merge.

Freeze before the live run:

- Same pinned k6 image and load.js as the future screen. Stable name tags; no
  per-key URL time series. Fixed generator budget 4 CPU / 4 GiB / 1,024 VUs.
- Receiver: pinned native PHP/OpenSwoole image, four workers, 2 CPU / 256 MiB.
  Asynchronous fixed-delay responses; no SQL/Redis/storage lookup. Independent
  accepted/completed/failed request counters are exposed over HTTP.
- Positive profiles: 4,800 offered requests/second, controlled delays 0, 25,
  100 and 150 ms; each 30-second warmup and 60-second measurement. The delay
  envelope covers the preceding 106.631-ms calibration p99, without claiming
  qualification for longer responses. The rate covers the planned SQL ladder.
- Negative control: 1,200 RPS / 150 ms / 128 VUs, two-second warmup and
  five-second measurement. Qualification must reject lost work. This is a
  deliberate generator-limit observation, not SQL capacity or application speed.
- Positive admission: no drops/errors/parity/FN/unfinished work; exact scheduled
  work within one endpoint-boundary iteration; total started/completed counts
  agree with independent receiver deltas; at least 10,000 measured responses;
  delivered RPS >= 95% of offered. Metadata matches every positive profile.
- Controlled delay <= p50 <= p99 <= delay + 50 ms. Samples covering
  the measured interval show generator CPU < 360%, receiver CPU < 180%, both
  RSS < 80% of their limits. No OOM/restart. Missing observations invalidate.

PASS qualifies only this bounded rate/delay/resource/source envelope. A real
application response outside it, generator saturation, changed settings/source,
or count disagreement requires INCONCLUSIVE, never a SQL-capacity inference.
Do not tune settings until PASS. Keep any failed profile and publish its reason.
The application comparator remains a separate follow-up after qualification.
No production backend, release or deployment is authorized here.

Retain tracked raw data and normalized artifact hashes outside .build. Remove
only current-task stacks, volumes/networks/images; retain dependency images.
