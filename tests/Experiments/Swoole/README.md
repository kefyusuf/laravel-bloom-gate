# Single-host capability fixture

Experimental test infrastructure only. This blank Laravel 13 application uses
OpenSwoole 26.2.0 and Octane 2.20.0. Production backend requirements and defaults
are unchanged. Root `laravel/octane` is a dev-only dependency so the existing
PHPStan maximum-level gate checks actual Octane types; its non-autoloaded
`bin/WorkerState.php` is scanned explicitly.

From the repository root on Linux:

```sh
SHARED_MEMORY_TASK=local-unique-label bash tests/Experiments/Swoole/run.sh
```

Use a new label each time. The script rejects pre-existing task resources and
removes its containers, network, volumes and built image on exit. Base images
are retained. Windows can execute the documented Compose operations individually
with Docker Desktop; Bash is needed only for this convenience script.

The script installs a pinned dependency lock into an isolated volume, constructs
native tables before Octane forks, and uses four HTTP workers with no task
workers. It verifies that a marker written by one worker is visible to all four,
worker replacement preserves the parent's incarnation, and a full parent restart
creates a fresh incarnation with no published generation. A native fork barrier
and required intermediate revisions exercise whole-row coherence; 4096 binary
bytes must round-trip exactly. These checks are observations of the pinned
runtime, not a proof of multi-row transactions.

Seeding uses deterministic Eloquent factories in batches of 10,000 and exactly
1,000,000 unique indexed keys, `member-0000000` through `member-0999999`. Ordered
SQL contents must match the independently generated SHA-256 digest. Seeding
finishes and disconnects before HTTP workers start. The seeder account is then
dropped, MySQL `read_only` and `super_read_only` are enabled, and workers receive
only a SELECT-only account. Administrator/seeder passwords are generated per run
outside HTTP workers; their environment must contain none of these credentials.
The tests verify its actual grants, global seal and
rejected INSERT/UPDATE/DELETE (MySQL errors 1142 or 1290 only).

Task 1 verifies native capability and unpublished control state. Task 2 uses the
actual package QueryGate/resolver through fixture-only shared-memory ports.
The parent explicitly publishes only when `SHARED_MEMORY_PUBLISH=1`; otherwise
the query endpoint remains SQL-only. Publication enumerates the sealed SQL set
and verifies the exact million-row digest before exposing a generation.
No database connection survives publication into the HTTP workers.

`SharedMemoryDomain` retains at most two generations, uses packed 4096-byte
chunks with exact-length/SHA-256 validation, and binds layout/semantics/data/build
to a sealed manifest. The authorized probe validates the whole control tuple
before/after reading every touched chunk, and rechecks manifest identity at the
end. Drift, missing/corrupt storage or unsafe metadata bypasses to SQL. Mutation,
contract binding and early retirement through the adapters raise protocol errors.
Native Tables remain writable primitives; only test code injects faults, and
there is no HTTP publication or generation-mutation endpoint.

The script runs capability, query-safety and HTTP suites separately. Safety tests
exhaustively probe all 1,000,000 seeded keys, force a publication race with a fork
barrier, reject stale incarnations and failed writes, and verify exact SQL calls
for positives, forced false positives and selected negatives. Small fault-test
fixtures use a matching single-row SQL scope with a distinct semantic identity;
the HTTP/exhaustive checks use the full sealed dataset.

No HTTP performance claim is made by these tests. See the
[query safety record](../../../docs/verification/2026-10-06-shared-memory-query-safety.md)
and [runtime provenance](../../../docs/verification/evidence/2026-10-06-shared-memory-provenance.json).
