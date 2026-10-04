# M6 coordination diagnostics

WU-10 adds framework-neutral, read-only coordination diagnostics. WU-11 wires these
services into Laravel, maps per-filter coordination configuration, and exposes
coordination details through `bloom:status`, `bloom:status --leases`, and `bloom:doctor`.

`CoordinationStatusReader` observes the atomic lifecycle snapshot and the explicit
`RuntimeCoordinationRequirement`. It reports:

| State | Meaning |
|---|---|
| `Unadopted` | No durable ownership and no runtime coordination requirement. |
| `AdoptionPending` | Ownership exists but synchronization initialization/evidence is missing. |
| `Adopted` | Ownership, runtime expectation, and control/sync relation agree. |
| `Invalid` | Configuration disagrees with ownership, persisted data is corrupt, or the control/sync relation is impossible. |
| `Unavailable` | Required diagnostic observations could not be completed. |

Owner absence with surviving sync state is corruption, as enforced by the existing
persistence ports. Owner presence with missing sync is never interpreted as
de-adoption. Missing sync evidence cannot distinguish interrupted initialization
from later storage loss; `adoption_pending` is a diagnostic classification, not
permission to reinitialize persisted history.

The result includes the synchronization snapshot (revision, phase, current epoch,
targets, candidate, and draining epoch), the separately observed draining writer
count, and a stable issue string where attention is needed:

- `coordination_required`
- `runtime_coordination_not_required`
- `adoption_pending`
- `invalid_relation`
- `invalid_configuration`
- `storage_corrupt`
- `diagnostics_unavailable`
- `draining_writers`
- `rebuild_in_progress`
- `promotion_recovery`
- `abort_requested`
- `abort_draining`

Resumable intermediate states remain valid: verification persisted before sync
advancement, promotion persisted before target contraction, and retirement
persisted before abort completion. These states require workflow progress rather
than storage repair.

`WriterLeaseInspector` is a separate optional diagnostic port. Memory and Redis
implementations return active A/P records ordered by token, retaining each lease's
original epoch and targets. Released tombstones are excluded. Enumeration is
requested explicitly with `read($name, includeLeases: true)` and requires an
inspector; it is not performed on ordinary status or doctor reads.
Within enumeration, persisted epoch counts are compared atomically against the
active A/P records. Redis reads both same-filter keys in one Lua call; Memory
uses one uninterrupted observation. Missing/excess counts or malformed count
storage cause corruption, with no repair. This internal integrity check does not
make the separately observed status drain count a workflow authorization.

`ManagedFilterStatusReader` accepts an optional coordination reader and exposes
its result through `ManagedFilterStatus::coordination()`. `ProductionSafetyDoctor`
accepts the same optional service and adds `filter.<name>.coordination` checks:

- unadopted: `NOT_ENABLED`;
- adopted with no outstanding issue: `PASS`;
- adoption pending or resumable rebuild/abort work: `WARN`;
- invalid or unavailable: `FAIL`.

Pair reads, count reads, and optional lease enumeration are separate observations.
They may disagree during concurrent progress. A diagnostic zero or empty lease
list is never drain proof. Workflow services continue to use their own guarded
observations and revision fencing; they do not consume these diagnostic results.
No diagnostic service acquires, prepares, releases, expires, repairs, or mutates
leases or lifecycle state. An unavailable count stays unknown rather than zero.

Laravel's doctor resolves coordination infrastructure lazily during inspection.
Unavailable Redis infrastructure therefore remains a diagnostic failure rather
than preventing the doctor service from being constructed.
Invalid runtime configuration reports `Invalid` with `invalid_configuration`;
it is distinct from infrastructure unavailability.
