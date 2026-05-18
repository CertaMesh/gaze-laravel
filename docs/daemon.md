# Daemon

`gaze-laravel` v0.11.0 ships a Facade + config + Artisan surface for the
upstream `gaze daemon` JSONL stdio runtime — a long-lived
redaction worker that keeps one process, one policy-loaded pipeline,
and any configured model load hot across requests. Adopters call into
the daemon from a multi-turn agent loop or worker without paying the
binary startup + model cold-start cost on every turn.

> **Reversibility caveat.** Daemon mode is **clean-only**. The protocol
> does NOT emit the signed `session_blob` that one-shot `Gaze::clean()`
> produces, and there is no `restore` request type. `DaemonSession` does
> not expose `restore()`. For round-trip reversal stay on the one-shot
> path: `Gaze::clean($text)` produces a `GazeSession` carrying the blob,
> hand `cleanText` to your LLM, then call `Gaze::restore($session,
> $reply)` to invert. North-star Principle 4 (reversibility) is honored
> exclusively by the one-shot contract.

## TL;DR

```bash
# 1. Rebuild upstream with the daemon feature (one-time per host, if gated)
cargo install gaze-cli --features daemon

# 2. Set a policy path and start the foreground wrapper under your supervisor
GAZE_DAEMON_POLICY_PATH=/etc/gaze/policy.toml php artisan gaze:daemon:serve

# 3. From application code
Gaze::daemon()->session('agent-thread-a')->clean($prompt);
```

> **Opt-in upstream feature.** The published GitHub-release `gaze`
> binary may be built **without** `--features daemon`. Doctor's
> pre-flight surfaces the exact `cargo install` hint when it detects
> daemon configuration against a binary missing the subverb.

## When To Use

- Multi-turn agent loops that redact every assistant turn.
- Worker queues processing dozens-to-thousands of short documents.
- Any caller that would otherwise pay binary startup + Kiji ORT init on
  every redaction.

Use the one-shot `Gaze::clean()` / `Gaze::restore()` path when:

- You need reversibility (daemon is clean-only).
- You only have one document to redact in a CLI script or batch.
- You want the signed `session_blob` for cross-process restore.

## Prerequisites

- The `gaze` binary on `PATH` (or `GAZE_BINARY` / `GAZE_DAEMON_BINARY_PATH`).
- A policy TOML file on disk (`gaze.daemon.policy_path`). Use
  `php artisan vendor:publish --tag=gaze-policy` to seed
  `policy.toml` and edit from there.
- A supervisor (systemd, Horizon process, supervisord, Forge daemon).
  The adapter does NOT daemonize; it ships a foreground wrapper.

## Config

`config/gaze.php` exposes a `daemon` block with flat keys. All keys
default to `null` so the upstream binary applies its own defaults;
populating a key forwards the matching flag.

| Config key | Env override | Default | Effect |
|---|---|---|---|
| `gaze.daemon.policy_path` | `GAZE_DAEMON_POLICY_PATH` | `null` | Forwarded as `--policy=`. Setting this key is the opt-in signal; doctor's daemon section stays silent while null. |
| `gaze.daemon.audit_db_path` | `GAZE_DAEMON_AUDIT_DB_PATH` | `null` | Forwarded as `--audit-db=`. Daemon-emitted rows stamp `provenance_stage = "daemon"`. |
| `gaze.daemon.request_timeout_ms` | `GAZE_DAEMON_REQUEST_TIMEOUT_MS` | `5000` | Adapter-side per-request ceiling. Raise it for cold first requests when policy + Kiji ORT init exceed 5s. |
| `gaze.daemon.idle_timeout_s` | `GAZE_DAEMON_IDLE_TIMEOUT_S` | `null` | Forwarded as `--idle-timeout=`. Daemon exits cleanly when no request arrives within the window. |
| `gaze.daemon.binary_path` | `GAZE_DAEMON_BINARY_PATH` | `null` | Override for the `gaze` binary path used by `:serve`. Falls back to `BinaryResolver` resolution. |
| `gaze.daemon.stderr_path` | `GAZE_DAEMON_STDERR_PATH` | `null` | File path the daemon's stderr is appended to when spawned via the adapter's `DaemonClient`. Null inherits stderr from the supervisor. |

Intentionally NOT shipped: `gaze.daemon.events.enabled`,
`gaze.daemon.extra_flags`, and connections-style
`gaze.daemon.connections.{name}.*`. See "Out of scope" below.

## Artisan Commands

TWO commands. Supervision is OS-owned (systemd / Horizon / supervisord)
— this package does not invent a Laravel-side daemon supervisor.

| Artisan | Upstream | Behaviour |
|---|---|---|
| `php artisan gaze:daemon:serve` | `gaze daemon` | Foreground wrapper. Blocks. Streams stdout/stderr verbatim. SIGTERM/SIGINT are forwarded to the child via pcntl handlers so supervisor stop signals reach the graceful-shutdown loop. Use as a systemd `ExecStart=` or a Horizon-process command. |
| `php artisan gaze:daemon:status` | n/a | Best-effort `pgrep -af "gaze daemon"`. Returns visible PIDs. **NOT** a supervisor — daemons launched under a different UID, or by your supervisor in an isolated cgroup, are invisible. Query your supervisor for ground truth. |

Intentionally NOT shipped: `:start`, `:stop`, `:restart`, `:logs`. The
upstream daemon binary is a foreground stdio worker (`gaze daemon` has
no subverbs); inventing in-PHP supervision would conflict with adopter
supervisors. Use `systemctl stop`, `horizon:terminate`,
`supervisorctl stop` instead.

## Facade

Two entry shapes — pick the one that matches your call site.

### Composition (fluent sugar)

```php
use Naoray\GazeLaravel\Facades\Gaze;

$session = Gaze::daemon()->session('agent-thread-a');

foreach ($turns as $turn) {
    $response = $session->clean($turn->text);
    $turn->cleanText = $response->cleanText;
}
```

`Gaze::daemon()` returns a `DaemonManager` request-scoped (Octane-safe).
`session($id)` returns a `DaemonSession` memoised per `$id` so repeated
lookups in an agent loop are allocation-free.

### Direct hot path (P5 agentic preservation)

```php
use Naoray\GazeLaravel\Facades\Gaze;

$response = Gaze::daemon()->clean('agent-thread-a', $turn->text);
```

One PHP call = one JSONL line on the wire. Equivalent to the
composition chain but skips the intermediate `DaemonSession` allocation.
Prefer this when you have the session id in scope already and don't
need a long-lived `DaemonSession` handle.

## Error Variants

`Gaze::daemon()` calls throw an exception family rooted at
`GazeDaemonException extends GazeIntegrityException`. Variants are
exposed via `DaemonErrorVariant` (backed enum) so adopter `match()`
ladders can react to wire shapes individually. **Adopters MUST include a
`default` arm in any `match($variant)` block** — new wire variants land
in `DaemonErrorVariant::Unknown` and would otherwise raise an unhandled
`UnhandledMatchError`.

| Variant | Origin | Exception subclass | Hint |
|---|---|---|---|
| `JsonMalformed` | upstream | `GazeDaemonException` | Adapter framing bug. Open an issue. |
| `Pipeline` | upstream | `GazeDaemonException` | Upstream pipeline failed closed. Same fail-closed posture as one-shot. |
| `Transport` | adapter | `GazeDaemonTransportException` | Broken pipe / EOF / mismatched session id. Doctor probe is the only place reconnect logic lives — hot path is fail-closed. |
| `Timeout` | adapter | `GazeDaemonTimeoutException` | Per-request `gaze.daemon.request_timeout_ms` exceeded. Raise for cold first requests. |
| `Unavailable` | adapter | `GazeDaemonFeatureUnsupportedException` | Binary missing `daemon` subverb. Rebuild with `cargo install gaze-cli --features daemon`. |
| `Unknown` | forward-compat | `GazeDaemonException` | New upstream variant; doctor logs an adopter warning when it appears on `gaze daemon --help`. |

```php
use Naoray\GazeLaravel\Daemon\DaemonErrorVariant;
use Naoray\GazeLaravel\Exceptions\GazeDaemonException;
use Naoray\GazeLaravel\Exceptions\GazeDaemonTimeoutException;
use Naoray\GazeLaravel\Exceptions\GazeDaemonTransportException;

try {
    $response = Gaze::daemon()->clean($sessionId, $text);
} catch (GazeDaemonTimeoutException $e) {
    // Queue back-pressure — retry with longer ceiling.
} catch (GazeDaemonTransportException $e) {
    // Fail-closed transport fault. Surface to ops; let supervisor restart.
} catch (GazeDaemonException $e) {
    match ($e->daemonVariant()) {
        DaemonErrorVariant::JsonMalformed => report_adapter_bug($e),
        DaemonErrorVariant::Pipeline      => surface_to_user($e),
        DaemonErrorVariant::Unavailable   => hint_rebuild($e),
        default                            => log_forward_compat($e), // REQUIRED
    };
}
```

`GazeDaemonException::toLogContext()` returns
`{daemon_variant, session_id, raw}` so structured logs carry the full
envelope without leaking `stderr_sha256` (daemon errors are stdout
envelopes — there is no stderr to hash).

The daemon exception family does **NOT** implement `Retryable`. Queue
retry policy is the adopter's responsibility — daemon failures map to
adopter-defined back-pressure (different surfaces have different
retry-vs-fail-fast semantics).

## Octane / Swoole / Concurrency

The shared stdio pipe is the headline trust risk: JSONL responses carry
no request id, so interleaved write/read between two concurrent callers
would mis-attribute responses across tenants (P4 trust contract).

Mitigations baked in:

1. **Request-scoped binding.** `DaemonClient` is bound via
   `app()->scoped()`. Each Octane request gets its own client and its
   own daemon subprocess — there is no cross-request stdio reuse.
2. **Per-request mutex.** `DaemonClient::request()` serialises calls
   within a request boundary via a `$busy` flag. Two fibers in the same
   request that both call `request()` will take turns; the second waits
   for the first to finish.
3. **`session_id` echo validation.** Every response's `session_id` is
   compared against the request's; mismatch throws
   `GazeDaemonTransportException` before the response leaves the client.

For Horizon fork-storms (N workers × 1 daemon binary), each worker
fork resolves a fresh `DaemonManager` via the container and spawns its
own `gaze daemon` subprocess. Adopters who want SQLite WAL-mode audit
contention should configure per-worker audit DB paths via tenant
identity.

## DaemonSession Serialization Boundary

```php
$session = Gaze::daemon()->session('agent-thread-a');
SomeJob::dispatch($session); // LogicException: DaemonSession is not serializable
```

`DaemonSession::__serialize()` throws `\LogicException`. The bound
`DaemonClient` is process-local — queueing a session would hand a
worker a stale handle to a daemon it never saw. Resolve a fresh
`Gaze::daemon()->session($id)` per worker tick instead.

## Eviction Wire Shape

When daemon sessions are evicted (LRU cap or
`--session-idle-timeout`), the upstream binary writes an audit-row with
`source = "daemon.session_eviction"`. The adapter does NOT expose a
PHP-side eviction event (deferred — first-adopter-ask). Tail-watchers
that need eviction observability today can subscribe to the audit DB:

```sql
SELECT session_id, source, occurred_at
FROM gaze_audit
WHERE source = 'daemon.session_eviction'
ORDER BY occurred_at DESC;
```

The schema is documented in upstream
[`docs/architecture/daemon-mode.md`](https://github.com/EmpireTwo/gaze/blob/main/docs/architecture/daemon-mode.md).

## Doctor Probe

`php artisan gaze:doctor` skips the daemon section when
`gaze.daemon.policy_path` is null. When the key is populated, the
probe:

1. Pre-flights `gaze daemon --help` — feature-gate check. Missing
   subverb throws `GazeDaemonFeatureUnsupportedException` with the
   `cargo install gaze-cli --features daemon` hint.
2. Checks readability of `gaze.daemon.policy_path` and parent-dir
   writability of `gaze.daemon.audit_db_path` / `stderr_path` when set.
3. (`--deep`) Diffs the upstream variant list against
   `DaemonErrorVariant` cases. New variants surface as adopter warnings
   so you can upgrade typed-handling proactively.

## Test Helpers

`Gaze::fake()` extends to cover daemon calls:

```php
use Naoray\GazeLaravel\Facades\Gaze;

Gaze::fake();

Gaze::daemon()->clean('agent-thread-a', 'hello world');

Gaze::assertDaemonCleaned('agent-thread-a');
Gaze::assertDaemonCleanCount(1);
Gaze::assertNothingDaemonCleaned(); // fails if any daemon call ran
```

No real binary is spawned; the fake handler returns a deterministic
`CleanResponse` so unit tests stay fast.

## Five-Axis Pitch

- **Reliability (P2).** Per-request timeout ceiling; fail-closed on EOF
  / broken pipe / mismatched session id; doctor probe surfaces new
  upstream variants forward-compat.
- **Reversibility (P4 sacrosanct).** Daemon is clean-only. Restore
  stays on the one-shot signed-blob contract. `DaemonSession::restore()`
  does NOT exist.
- **Agentic-first (P2).** Hot path explicit: `Gaze::daemon()->clean($id,
  $text)` = one PHP call = one JSONL line. Composition chain stays as
  fluent sugar.
- **Trust (P4).** Per-request mutex + scoped binding prevent
  cross-tenant payload leak through the shared stdio pipe; session_id
  echo validation catches any residual interleave.
- **Adopter ergonomics (P2).** One flat config block; one Facade method
  with two shapes; two artisan commands (foreground + diagnostic); one
  exception family with surface-distinct subclasses + enum-driven
  forward-compat. No connections boilerplate, no in-PHP daemon
  supervisor.

## See also

- [Upstream coverage matrix](./upstream-coverage.md) — daemon
  command/flag/exception mapping.
- [Upstream `gaze daemon` spec](https://github.com/EmpireTwo/gaze/blob/main/docs/adopter/daemon-quickstart.md) — JSONL protocol, eviction, graceful shutdown.
- [docs/upgrading.md](./upgrading.md) — v0.11.0 upgrade notes.
