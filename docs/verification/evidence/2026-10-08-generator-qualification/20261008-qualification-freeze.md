# Frozen generator qualification attempt

Preflight withdrawn before any load invocation: independent review found that
negative-control safety was validated only after all positive profiles. No
timing/outcome exists for this attempt. The unused current-task built image was
removed. Correct and review the early safety gate before freezing a new source.

- Measured source: eff6e863fe8d65f195a6803025822726e56cfb6f (clean merged PR94).
- Same tree as independently reviewed/CI-passing d7094fd4a0257ac77254a099ed5d30455b6da5f9.
- Task: 20261008-qualification; current-task Compose project lbg-generator-20261008-qualification.
- One default collector invocation; no tuning or retry after failure.
- Negative control: 1200 RPS,150 ms,128 VUs,2s warmup/5s measurement.
- Qualification: three blocks x0/25/100/150 ms,4800 RPS,1024 VUs,30s warmup/60s measurement.
- Generator4CPU/4GiB; receiver2CPU/256MiB; four stable worker PIDs.
- Docker host readback:12 CPUs,16440238080 bytes; no host/fleet settings changed.
- Native OpenSwoole receiver and pinned grafana/k6:1.3.0 manifest retained.
- Require exact-source/readback identity,zero correctness failures,zero positive drops,nominal completeness,
  actual-window reconciliation/throughput/latency,observed coverage and resources; no weakened guards.
- Stop first failure; source and prior artifacts remain unchanged throughout collection.
- Only synthetic package fixture data; no SQL/application benchmark or private-project access.
- Remove only current-task containers/network/image in collector finally; no named volumes/worktrees.
