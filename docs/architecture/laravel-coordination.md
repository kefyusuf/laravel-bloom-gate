# Laravel coordinated operation

Laravel exposes the Application coordination services through thin adapters.
Configuration, service wiring, CLI validation, polling, and presentation belong to
the adapter; lifecycle legality and recovery evidence remain Application-owned.

## Configuration and adoption

Set `coordination` on the named filter:

```php
'filters' => [
    'users.email' => [
        'enabled' => true,
        'definition' => UsersEmailFilter::class,
        'capacity' => 100_000,
        'false_positive_rate' => 0.01,
        'coordination' => 'coordinated-v1',
    ],
],
```

Missing/null coordination preserves legacy runtime expectations. Other values are
invalid. This mapping does not instantiate filter definitions or contact Redis.
Durable ownership fences legacy mutations even if local configuration later drifts.
Enabling the runtime requirement also fences legacy mutation before adoption.

Adopt explicitly:

```text
php artisan bloom:coordinate:adopt users.email
```

Brownfield adoption requires no current candidate and an explicit handoff after
all old uncoordinated writers have finished:

Hold an external pause/queue of membership-entry writes, deploy the coordinated
writer path for every participant, and configure `coordinated-v1` before claiming
ownership. Release the external handoff only after valid STEADY synchronization
has been initialized. Keep the handoff held across interrupted adoption retries.

```text
php artisan bloom:coordinate:adopt users.email --quiescent
```

The flag records the operator's acknowledgement; the command does not discover or
prove completion of external writers. Adoption delegates to `CoordinatedFilterAdopter`.

## Prepared writer surface

Mutable coordinated writing uses the existing `preadd-v1` contract. All membership
entry writes must participate in the protocol. The facade accepts a caller-owned
stable 32-character lowercase hexadecimal token:

```php
$prepared = BloomGate::prepare('users.email', $persistedToken, [$email]);

// Perform the authoritative write and establish its committed outcome.
$cleanup = $prepared->authoritativeCommitted();
```

Persist the token before preparation so retries and recovery can identify the
same lease. `prepare()` performs Bloom writes and establishes durable PREPARED
evidence before returning; it does not execute the authoritative write.
For a known aborted authoritative write, call `authoritativeAborted()` instead.
For an unknown outcome, retain the token and lease until evidence becomes available.
Cleanup uncertainty is represented separately from the authoritative result.

The facade delegates to `CoordinatedWriter`; it does not infer database transaction
outcomes, install observers, or release leases on process interruption.

## Durable workflow commands

```text
php artisan bloom:rebuild users.email [--wait=0]
php artisan bloom:rebuild:abort users.email [--wait=0]
php artisan bloom:lease:resolve users.email <token> --outcome=committed|aborted
php artisan bloom:status users.email [--leases]
php artisan bloom:doctor
```

Rebuild and abort repeatedly advance the same persisted workflow until it completes,
requires recovery, or reaches a blocked drain. `--wait` is a non-negative integer
budget in seconds for polling blocked progress. Zero returns at the first blocked
condition. A positive budget may continue when writers finish; expiry leaves the
blocked state and leases intact. Rerunning the same command resumes durable progress.

Commands print `Blocked`, `Completed`, or `RecoveryRequired` progress.
Blocked progress is a successful, resumable command invocation, not workflow
completion; recovery-required progress or an operational error returns failure.
No wait budget expires leases or overrides drain evidence.

Lease resolution accepts only a known committed or aborted outcome. Committed
resolution requires PREPARED evidence. Unknown tokens/outcomes fail; released tokens
remain terminal and retries are idempotent. There is no force option.

Status prints ownership classification, sync revision/phase, epochs, target
versions, candidate, drain count, and issue. `--leases` adds active acquired/prepared
tokens with their original epoch/targets; released tombstones remain excluded.
These diagnostics are separate observations and do not authorize workflow mutation.
If ordinary managed status fails while reading control or generation data, the
command independently attempts coordination diagnostics, preserves the original
error, and returns failure. It does not fabricate active or candidate information.
Invalid configuration reports `INVALID/invalid_configuration`; unavailable
coordination infrastructure reports `UNAVAILABLE/diagnostics_unavailable`.

Service and command discovery remain side-effect free. Memory ports share the same
coordination domain, and Redis ports use the existing same-filter atomic protocols.
Existing legacy build/verify/activate/discard/add paths remain fenced by Application
and persistence semantics after adoption.

## Recovery boundaries

Rerun adoption when status is `ADOPTION_PENDING`, retaining the brownfield handoff.
Rerun rebuild or abort after interruption to continue its persisted phase. If a
drain is blocked, inspect active tokens and establish their authoritative outcomes
before resolving them. An exception from a database request is insufficient proof
of rollback. For a known commit followed by uncertain cleanup, retry lease cleanup;
replaying the authoritative write requires separate application evidence.

There is no force release, de-adoption, counter repair or automatic tombstone purge.
Missing synchronization after ownership claim keeps legacy operations fenced;
surviving synchronization without ownership is corruption. Diagnostics neither
repair state nor authorize skipping a drain.

Restoring historical data that erases ownership, synchronization and runtime
configuration together can erase all local evidence of prior adoption. Detecting
that full rollback requires external operational history. Preserve that history
and explicitly adopt before resuming coordinated writes. Protocol interruption
tests do not establish Redis hardware power-loss durability; the declared Redis
production profile and external writer participation remain operator obligations.
