# ADR-0043: Coordinated adoption, recovery, and public management boundary

**Status:** ACCEPTED

## Context

An already-running M5 `preadd-v1` deployment can have membership-entry writers that never acquired M6 leases.

The package cannot retroactively discover or fence those in-flight operations. Coordinated ownership therefore cannot safely appear merely because runtime configuration changed.

M6 also needs explicit recovery semantics for:

- crash during adoption;
- stuck or outcome-uncertain writer leases;
- interrupted rebuild/abort workflow;
- durable coordination state that disagrees with local configuration.

Those recovery paths must not expose low-level persistence operations as normal product APIs or add unsafe "force" escape hatches.

## Decision

### Brownfield adoption uses one explicit quiescent handoff

Any existing M5 control state requires one externally established membership-entry handoff before coordinated safety can be claimed.

The required rollout is:

```text
deploy coordination-capable code while still using M5
-> pause/queue membership-entry writes outside Bloom Gate
-> wait until every old uncoordinated writer is definitively complete
-> switch all participating writers to the coordinated writer protocol
-> require no current control candidate
-> claim coordinated ownership
-> initialize and verify STEADY sync-v1
-> release the external handoff
```

The package does not own the external pause and cannot prove that raw database writers obeyed it.

The handoff acknowledgement is an explicit operator/application assertion, not writer discovery.

Brownfield adoption is rejected while a current control candidate exists. That candidate must first be completed or discarded through valid M5 semantics before ownership is claimed.

A fresh filter with no control state may initialize coordinated ownership without a brownfield quiescent acknowledgement because there is no prior package-managed lifecycle history to hand off. ADR-0010 still applies to raw external writers.

### Adoption is owner-first and interruption-safe

Adoption is a two-step durable transition.

First:

```text
require expected control revision/absence
require :sync:owner absent
require :sync absent
-> create immutable :sync:owner
```

Then, under the still-required control relation:

```text
initialize sync-v1
revision = 1
phase = STEADY
current_epoch = 1
current_targets = current active generation or []
candidate = absent
draining_epoch = absent
```

Crash after ownership claim but before sync initialization is:

```text
ADOPTION_PENDING
```

ADOPTION_PENDING:

- keeps ordinary M5 lifecycle mutation fenced;
- blocks coordinated writer acquisition;
- blocks coordinated rebuild start;
- permits idempotent adoption initialization retry;
- requires the external brownfield handoff to remain held until valid sync-v1 exists.

It never means "not adopted".

### Coordinated ownership is one-way in M6 v1

M6 v1 has no dynamic de-adoption transition.

Runtime configuration cannot reopen ordinary M5 mutation after durable ownership exists.

There is no supported:

```text
ADOPTED -> ordinary mutable M5 ownership
```

A reverse migration would require a separately designed external fence.

If runtime configuration still requires coordinated operation but durable owner/sync evidence is missing, mutation enters recovery and must not infer ordinary M5 ownership.

When both durable coordination records are absent **and** runtime configuration does not require coordinated operation, the ordinary unadopted M5 path remains eligible.

An external restore that erases both durable ownership history and coordinated runtime configuration is outside the protocol; coordinated guarantees require a new explicit adoption handoff afterward.

### Manual lease recovery is evidence-bound

Recovery never means force release.

Allowed authoritative-outcome resolution is:

```text
known COMMITTED:
  P -> R
  A -> blocked: durable preparation proof missing

known ABORTED / ROLLED BACK:
  A -> R
  P -> R

unknown outcome:
  no release
```

An already R token is terminal/idempotent; its existence does not retroactively attest which authoritative outcome originally caused release.

There is no:

- force flag;
- TTL override;
- "assume committed";
- "assume rolled back";
- token deletion as recovery;
- automatic transaction-outcome discovery.

Recovery does not replay the authoritative mutation and does not manufacture missing Bloom writes.

### Public management is Application-owned

Correctness-sensitive store primitives are internal persistence capabilities, not the normal product API.

The public framework-neutral management boundary is owned by Application orchestration for:

- coordinated adoption;
- online rebuild advance/resume;
- coordinated rebuild abort;
- explicit evidence-bound lease resolution;
- read-only status/diagnostics.

Laravel console commands are thin adapters over those Application services.

Exact command names and presentation formatting are not architectural commitments of this ADR.

Low-level revision CAS, epoch rotation, ownership-marker mutation, and target-set mutation are not exposed as ordinary operator workflows.

### Workflow progress is durable and resumable

Rebuild and abort orchestration make deterministic durable progress and may report:

- advanced;
- blocked on a drain or other recoverable condition;
- completed;
- recovery required.

A caller or command may wait/poll for a bounded period, but timeout/interruption never:

- expires leases;
- releases tokens;
- skips writer drains;
- weakens revision fencing;
- reverses durable abort intent.

Rerunning the management operation rereads durable state and resumes.

### Status and doctor remain diagnostic

Coordination status must be able to represent:

- unadopted;
- adoption pending;
- adopted;
- invalid/inconsistent ownership;
- sync revision and phase;
- current epoch/targets;
- candidate and draining epoch;
- persisted draining writer count;
- blocker/recovery classification.

Active A/P lease enumeration may be exposed diagnostically.

Lease enumeration is **not** drain proof. Drain correctness uses the persisted admission-closed epoch count.

Production doctor remains read-only and reports ownership/configuration disagreement, malformed coordination state, impossible control-sync relations, and blocked drains. It does not repair them.

## Consequences

- brownfield coordinated guarantees begin at an explicit observable handoff instead of an unprovable config switch;
- brownfield applications remain a first-class adoption path under ADR-0014; the one-time fence is a migration-safety requirement, not reduced product support;
- a crash during adoption cannot silently return the filter to legacy lifecycle ownership;
- durable adoption outranks local runtime mode drift;
- M6 v1 avoids the complexity and risk of reverse de-adoption;
- stuck leases remain recoverable only when authoritative evidence is sufficient;
- unknown outcomes favor correctness over rebuild liveness;
- Application owns product workflow while persistence remains a narrow atomicity boundary;
- command interruption and wait timeout are availability events, not correctness events;
- diagnostics expose blockers without becoming authorization logic.
