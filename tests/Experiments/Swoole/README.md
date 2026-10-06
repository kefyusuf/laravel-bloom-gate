# Single-host capability fixture

Experimental test infrastructure only. This blank Laravel 13 application uses
OpenSwoole 26.2.0 and Octane 2.17.0. Production backend requirements and defaults
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
only a SELECT-only account. The tests verify its actual grants, global seal and
rejected INSERT/UPDATE/DELETE (MySQL errors 1142 or 1290 only).

Task 1 verifies native capability and unpublished control state. It does not
implement QueryGate or establish trusted negative/SQL-bypass behavior. The real
QueryGate decision and authoritative SQL call assertions are required in Task 2.
No HTTP performance claim is made by these tests.
