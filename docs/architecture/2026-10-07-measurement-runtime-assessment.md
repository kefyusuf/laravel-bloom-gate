# PHP runtime assessment for measurement recovery — 2026-10-07

This assessment uses current official documentation, not third-party benchmark
rankings. It informs the approved generator-recovery increment, not a production
runtime migration. Installed/tested versions remain pinned and are recorded
separately from current documentation. No private application is involved.

| Capability | Relevance and decision | Official reference |
| --- | --- | --- |
| OpenSwoole timers and async HTTP workers | Already installed at 26.2.0. Retain the receiver and dispatch mode 1; use an absolute monotonic deadline with nonblocking rearming and explicit allocation-failure accounting. A timer wake is not independent proof that the requested elapsed time passed. | [Timer](https://openswoole.com/docs/modules/swoole-timer-after), [configuration](https://openswoole.com/docs/modules/swoole-server/configuration) |
| Laravel Octane | Laravel integration for OpenSwoole/Swoole, RoadRunner and FrankenPHP. Relevant to the later equal application-path comparison. Adding framework bootstrap to the independent receiver would add another variable. | [Laravel 13 Octane](https://laravel.com/docs/13.x/octane) |
| RoadRunner | Persistent PHP worker process pool. A separate worker-based consumer is a future comparison candidate; changing the receiver requires proving equivalent concurrent delay handling. No automatic speed inference follows from persistence. | [Worker pool](https://docs.roadrunner.dev/docs/php-worker/pool) |
| FrankenPHP | Persistent worker scripts and request handlers. Another later consumer candidate; existing receiver counters/state and async delay behavior do not transfer automatically. | [Worker mode](https://frankenphp.dev/docs/worker/) |
| Workerman | Event-driven worker server with timers and coroutine-capable drivers. A credible alternative if evidence identifies an OpenSwoole-specific obstacle. Multiple drivers must not be combined blindly. | [Coroutine model](https://manual.workerman.net/doc/en/coroutine/coroutine.html), [timer](https://manual.workerman.net/doc/en/timer/add.html) |
| ReactPHP | Shared event loop and best-effort timers; recommends monotonic clocks. Supports the same deadline-recheck principle, not exact timing merely by switching event loops. | [Event loop](https://reactphp.org/event-loop/) |
| Amp / Revolt | Cooperative fiber-based concurrency; blocking work stalls the event loop. A possible independent async control, with additional dependencies and its own qualification burden. | [Amp](https://amphp.org/amp) |

The narrow first change is exact deadline control using [PHP hrtime](https://www.php.net/manual/en/function.hrtime.php).
It guarantees no response release before the observed deadline, not an upper
latency bound under arbitrary load. Avoid busy waits, blocking sleeps and callback
reference cycles in the request path. The separate CLI observer may sleep between
polls; it does not block an HTTP worker.

k6 preallocated VUs are initialized before the arrival-rate scenario starts.
The existing fixed 1,024-VU case therefore does not establish dynamic VU
initialization as the cause of drops. Connection startup, connection-slot waits,
CPU throttling or scheduling stalls remain hypotheses. `http_req_duration`
excludes initial connection work; full iteration, blocked/connecting/waiting and
drop/VU observations must be considered together. Sources:
[allocation](https://grafana.com/docs/k6/latest/using-k6/scenarios/concepts/arrival-rate-vu-allocation/),
[metrics](https://grafana.com/docs/k6/latest/using-k6/metrics/reference/).

Use the [local k6 REST API](https://grafana.com/docs/k6/latest/reference/k6-rest-api/)
for bounded cumulative snapshots at a target one-second interval, alongside
receiver counters and cgroup CPU statistics. Expose it only inside the own-task
Docker network, without host ports. Record actual poll start/end timestamps and
unavailable observations. Cumulative trends cannot be presented as interval
quantiles. Pausing k6 until the observer is ready is a documented startup change;
it does not excuse warmup drops or qualify the prior unsuccessful source.

Revisit runtime selection only when the retained diagnosis identifies a concrete
need. All later variants must preserve membership parity, independent counts,
fixed resource budgets, immutable source identity and zero-loss admission.
