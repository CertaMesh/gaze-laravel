# SafetyNet

`gaze-laravel` v0.9.0 exposes the upstream **SafetyNet** Pass-3 second-opinion
privacy detector through Laravel-native config keys, typed exceptions, and a
`gaze:doctor` pre-flight probe. SafetyNet observes the cleaned manifest after
Pass-1 (regex + rulepacks) and Pass-2 (NER) and flags any spans it still
suspects of being raw PII; it never mutates the manifest directly — the
adapter forwards the binary's typed envelope back to your app, where you
decide what to do with it.

SafetyNet is **opt-in**. Out of the box the package ships `gaze.safety_net =
false` and no backend flags, which means the binary runs Pass-1 + Pass-2 only.
Set `GAZE_SAFETY_NET=true` to enable it, then pick a backend. The backend
selector (`GAZE_SAFETY_NET_BACKEND`) is forwarded **only while the net is
enabled** — a disabled net with a leftover selector stays off.

## Backends

| Backend | Selector value | What it is | When to pick it |
|---|---|---|---|
| OpenAI Privacy Filter (OPF) | `openai-filter` | Eight typed labels covering names, emails, phone numbers, addresses, etc. Python subprocess, GPU optional. Needs a gaze binary built with upstream's `safety-net-openai` feature — the release binaries are not. | You build gaze yourself and already manage Python in production. |
| Nym (gaze >= 0.15.0) | `nym` | Pinned Nym-small int8 bundle run by ONNX Runtime inside the gaze binary; compiled into the release binary. | You want a safety net on the stock release binary. The adapter has no first-class Nym config or installer yet ([#157](https://github.com/CertaMesh/gaze-laravel/issues/157)) — set it up by hand as in [Kiji was removed upstream](#kiji-was-removed-upstream-in-gaze-0150). |

The shared `safety_net_mode` / `safety_net_fallback` /
`safety_net_timeout_ms` / `safety_net_input_limit_bytes` knobs apply to every
backend.

The **Kiji DistilBERT** backend (`kiji-distilbert`) and every `--kiji-*` flag
were removed upstream in gaze 0.15.0. See
[Kiji was removed upstream](#kiji-was-removed-upstream-in-gaze-0150).

## Quick start (OpenAI Privacy Filter)

```env
GAZE_SAFETY_NET=true
GAZE_SAFETY_NET_BACKEND=openai-filter
GAZE_OPENAI_FILTER_COMMAND=/usr/local/bin/opf
```

`GAZE_OPENAI_FILTER_COMMAND` is optional when `opf` is already on `PATH`. See
the upstream [OpenAI-filter safety-net docs](https://github.com/CertaMesh/gaze/blob/main/docs/explanation/safety-net/safety-nets.md)
for the `opf` backend — install it from a pinned upstream release, then fetch
its model weights. The gaze release binaries are built without upstream's
`safety-net-openai` feature, so OPF needs a gaze you build yourself (point
`GAZE_BINARY` at it); the release binary answers with
`GazeSafetyNetConfigException`.

## Kiji was removed upstream in gaze 0.15.0

Upstream gaze 0.15.0 deleted the Kiji DistilBERT safety net
([CertaMesh/gaze#612](https://github.com/CertaMesh/gaze/pull/612)):
`--safety-net-backend kiji-distilbert` and every `--kiji-*` flag are gone from
`gaze clean` and `gaze daemon`. A 0.15+ binary given any of them exits 2 with a
bare `{"error":"PolicyConfig","exit":2}` — no detail — so the adapter now:

- **never forwards a `--kiji-*` flag**, whatever Kiji config is still set;
- **fails closed before spawning** when the safety net is enabled with
  `GAZE_SAFETY_NET_BACKEND=kiji-distilbert`: `Gaze::clean()`, `Gaze::mask()`,
  `Gaze::daemon()` and `gaze:daemon:serve` throw
  `GazeSafetyNetConfigException` (exit 2, no stderr) naming the replacement;
- **leaves `Gaze::restore()` alone** — sessions cleaned under Kiji stay
  restorable;
- **reports it in `gaze:doctor`**: `FAIL` for the enabled backend, a non-fatal
  warning for leftover Kiji config (see [Doctor probe](#doctor-probe)).

To migrate, either turn the safety net off (`GAZE_SAFETY_NET=false`) or move
to Nym:

1. **Fetch the Nym bundle as the user that runs gaze** (the PHP-FPM / queue
   worker user), so the bundle lands where that user can read it. Use the
   binary the adapter resolves (`GAZE_BINARY`, else `vendor/bin/gaze`):

   ```bash
   sudo -u www-data env XDG_DATA_HOME=/srv/gaze vendor/bin/gaze setup \
       --safety-net nym --non-interactive --policy-out /tmp/gaze-setup.toml
   ```

   Nym lands in `$XDG_DATA_HOME/gaze/models/nym-small-int8` (here
   `/srv/gaze/gaze/models/nym-small-int8`); `--model-dir` would only move the
   NER model, which `gaze setup` also downloads (~680 MB). The starter policy
   it writes (`--policy-out`, else `./gaze.toml`) is not used by the adapter,
   which keeps `GAZE_POLICY_PATH`; take only its `[safety_net.nym]` table (it
   names the bundle directory), then discard the file.

2. **Select the backend:**

   ```env
   GAZE_SAFETY_NET=true
   GAZE_SAFETY_NET_BACKEND=nym
   ```

3. **Point the binary at the bundle directory in your policy** (the file
   `GAZE_POLICY_PATH` names — the adapter always passes it as `--policy`):

   ```toml
   [safety_net.nym]
   model_dir = "/srv/gaze/gaze/models/nym-small-int8"
   ```

   This survives `php artisan config:cache` and needs no process environment.
   The adapter has no config key for the bundle path yet (#157). Alternative:
   set `GAZE_NYM_MODEL_DIR` in the **real process environment** of the PHP
   worker (systemd `Environment=`, supervisord `environment=`, the container's
   `ENV`, or PHP-FPM's `env[GAZE_NYM_MODEL_DIR]`) — a line in `.env` alone is
   not enough, because `config:cache` stops Laravel from loading `.env`, so the
   inherited variable silently vanishes in production. With neither, clean
   fails with `GazeSafetyNetConfigException` (`nym model_dir is missing`); an
   empty or wrong directory fails with `GazeSafetyNetArtifactMissingException`
   (`backend()` = `nym`, `path()` = `<missing:SHA256SUMS> (install via …)`).
   Through `Gaze::daemon()` both surface as `GazeDaemonTransportException`
   (the daemon exits at startup; its stderr goes to `gaze.daemon.stderr_path`).

   Keep the on/off switch in one place: a policy `[safety_net] backend = "nym"`
   table turns Nym on even while `GAZE_SAFETY_NET=false`.

4. Remove the `GAZE_KIJI_*` env vars and any `kiji` block from a published
   `config/gaze.php`, run `php artisan config:clear` (or re-cache), then
   `php artisan gaze:doctor --deep` — the deep round-trip exercises the Nym
   bundle end to end.

## Config reference

All keys live under `config/gaze.php`. Every nullable key forwards as an exact
upstream `--flag=<value>` when set, or omits the flag entirely when `null` —
i.e. `null` means "defer to the binary's own default", never "force-disable".
The argv-forwarding contract is the declarative flag map in `Gaze::clean()`
(`src/Gaze.php`) and its daemon twin `Daemon\DaemonArgv`.

| Config key | Env var | Type | Default | Meaning |
|---|---|---|---|---|
| `gaze.safety_net` | `GAZE_SAFETY_NET` | `bool` | `false` | Master switch. When `false`, no safety-net flag is forwarded. When `true`, the binary runs Pass-3 against the active backend. Legacy v0.6.5 key. |
| `gaze.safety_net_backend` | `GAZE_SAFETY_NET_BACKEND` | `string\|null` | `null` | Backend selector. Valid: `openai-filter`, `nym`. Forwarded only while `gaze.safety_net` is `true`. `null` lets the binary keep its single-backend default of `openai-filter`. Wins over the legacy `--safety-net=<kind>` flag. `kiji-distilbert` was removed upstream in gaze 0.15.0 and fails closed before spawning. |
| `gaze.openai_filter_command` | `GAZE_OPENAI_FILTER_COMMAND` | `string\|null` | `null` | Absolute path to the `opf` binary. `null` lets the binary `PATH`-resolve. |
| `gaze.openai_filter_checkpoint` | `GAZE_OPENAI_FILTER_CHECKPOINT` | `string\|null` | `null` | Model-checkpoint directory for OPF. `null` uses the binary's built-in default. |
| `gaze.openai_filter_operating_point` | `GAZE_OPENAI_FILTER_OPERATING_POINT` | `string\|null` | `null` | Sensitivity trade-off. Valid: `high-recall`, `balanced`, `high-precision`. `null` uses the binary's default. |
| `gaze.safety_net_device` | `GAZE_SAFETY_NET_DEVICE` | `string\|null` | `null` | CUDA / CPU device hint forwarded as `--openai-filter-device` (e.g. `cuda:0`, `cpu`). OPF-specific. |
| `gaze.safety_net_timeout_ms` | `GAZE_SAFETY_NET_TIMEOUT_MS` | `int\|null` | `null` | Subprocess timeout, milliseconds. Must be positive. `null` uses the binary's default of `5000`. Applies to every backend. |
| `gaze.safety_net_input_limit_bytes` | `GAZE_SAFETY_NET_INPUT_LIMIT_BYTES` | `int\|null` | `null` | Clean-text size cap, bytes. `null` uses the binary's default of `1048576`. Applies to every backend. |
| `gaze.safety_net_mode` | `GAZE_SAFETY_NET_MODE` | `string\|null` | `null` | Suspected-leak handling mode. Valid: `strict`, `tolerant`, `redact`, `resolve`. `null` defers — **upstream default flipped from `strict` to `resolve` in v0.8.1**. See [Mode and fallback semantics](#mode-and-fallback-semantics). |
| `gaze.safety_net_fallback` | `GAZE_SAFETY_NET_FALLBACK` | `string\|null` | `null` | Fallback applied when `safety_net_mode` is `redact` or `resolve` and the backend cannot complete. Valid: `strict`, `tolerant`, `redact`. `null` uses the binary's default of `redact`. |

## Mode and fallback semantics

`safety_net_mode` controls what happens when SafetyNet flags a suspected leak
that Pass-1 and Pass-2 missed.

| Mode | Behaviour on suspected leak | Status |
|---|---|---|
| `strict` | Abort the clean. Binary returns a `SafetyNet` envelope with `variant=SuspectedLeak`; the adapter throws `GazeSafetyNetFailureException`. | Legacy default ≤ v0.8.0. |
| `tolerant` | Log the finding; continue with the original Pass-2 manifest unchanged. | Legacy; emits an upstream deprecation warning. |
| `redact` | Redact the suspected spans from the manifest before returning. On subprocess failure (timeout, oversized input, runtime crash), `safety_net_fallback` engages. | New in v0.9.0. |
| `resolve` | Replace the suspected spans with policy-driven pseudonyms so `restore()` can still round-trip. On subprocess failure, `safety_net_fallback` engages. | **Upstream default in v0.8.1+**. |

`safety_net_fallback` only engages when `safety_net_mode` is `redact` or
`resolve` AND the backend cannot complete (e.g. `Timeout`, `WeightsMissing`,
`InputTooLarge`). When `safety_net_mode` is `strict` or `tolerant`, the
fallback is never consulted.

Example matrix:

| `safety_net_mode` | `safety_net_fallback` | Backend completes, flags leak | Backend fails (`Timeout`) |
|---|---|---|---|
| `strict` | (ignored) | Throw `GazeSafetyNetFailureException` | Throw `GazeSafetyNetFailureException` |
| `tolerant` | (ignored) | Log, keep original manifest | Log, keep original manifest |
| `redact` | `redact` (default) | Spans redacted | Spans redacted (fallback) |
| `resolve` | `redact` | Spans pseudonymized | Spans redacted (fallback) |
| `resolve` | `tolerant` | Spans pseudonymized | Log, keep original manifest |
| `resolve` | `strict` | Spans pseudonymized | Throw `GazeSafetyNetFailureException` |

### Trust state per mode

The session's `leak_report` lists what the net found, not what is still raw,
so `$session->coverageState()` reads it together with the mode and fallback the
adapter forwarded. Spans the net flagged read `Unverified` (amber) under
`resolve` with the `redact` or `strict` fallback and under `redact`: they were
tokenized or replaced with a marker, and
`$session->leakReport->hasResolvedSuspects()` is `true`. They read `Suspect`
(red) under `tolerant` and under `resolve` with the `tolerant` fallback, where
they may have shipped raw. Under `strict` the clean throws instead.

The fallback also engages without a backend failure, whenever the `resolve`
pass cannot protect a flagged span:

- `resolve` + `redact` (the default) replaces it with a `[REDACTED:<class>]`
  marker, then scans the output once more and ships what that scan flags raw,
  with nothing in the report to tell it apart. A `resolve` + `redact` session
  whose `cleanText` carries a marker therefore reads `Suspect` whenever the net
  flagged anything other than a class mismatch, even when every span was in
  fact protected. Any `[REDACTED:` the input or a policy `redact` rule put
  there counts the same.
- `resolve` + `strict` refuses the document: exit 3 with a `Pipeline` envelope,
  so `Gaze::clean()` throws `GazePipelineException` (not
  `GazeSafetyNetFailureException`). It is `Retryable`, but the same input
  refuses again.

Per-mode probe output:
[Clean leak report & trust state](../reference/upstream-coverage.md#clean-leak-report--trust-state-v011x).

## Doctor probe

`php artisan gaze:doctor` checks the safety-net backend selector against the
upstream removal of Kiji (`probeKijiRemoval()` in
`src/Console/DoctorCommand.php`):

- **FAIL** when the safety net is enabled and `gaze.safety_net_backend` is
  `kiji-distilbert` — the same pre-flight `Gaze::clean()` and the daemon
  spawn paths apply. Doctor exits non-zero:

  ```
  safety_net_backend ........................ kiji-distilbert removed in gaze 0.15.0
  gaze.safety_net.backend=kiji-distilbert was removed upstream in gaze 0.15.0 (pre-flight). Switch GAZE_SAFETY_NET_BACKEND to nym (fetch the bundle with `gaze setup --safety-net nym`) or set GAZE_SAFETY_NET=false.
  status ........................................................... FAIL
  ```

- **WARN** (exit code unchanged) on leftover Kiji config the adapter now
  ignores: a `kiji-distilbert` selector on a disabled net, a published
  config's `safety_net.kiji.*` group or pre-v0.13 flat `kiji_*` keys,
  `daemon.kiji_distilbert_locales`, or a `GAZE_KIJI_*` /
  `GAZE_DAEMON_KIJI_DISTILBERT_LOCALES` env var:

  ```
  kiji config ...................................................... ignored
  Kiji DistilBERT config is ignored — upstream removed the backend in gaze 0.15.0: gaze.kiji_backend, GAZE_KIJI_DISTILBERT_MODEL_DIR.
  Remove it (see UPGRADING.md); for a safety net, switch to nym.
  ```

- **Silent** otherwise. Doctor does not probe the OPF subprocess or the Nym
  bundle; `gaze:doctor --deep` runs a real clean/restore round-trip, which
  surfaces a missing bundle as `GazeSafetyNetConfigException` or
  `GazeSafetyNetArtifactMissingException`.

## Exception handling

SafetyNet failures map onto three typed exceptions. All three sit under the
`CertaMesh\Gaze\Exceptions\` namespace and share the
`GazeException::toLogContext()` shape.

| Exception | When raised | Exit | Retry policy | Accessors |
|---|---|---|---|---|
| `GazeSafetyNetConfigException` | Config invalid: a backend subprocess/config error upstream (exit 3), a Nym setup error such as no bundle directory configured (exit 2, gaze >= 0.15.0), or the adapter's pre-flight for an enabled `kiji-distilbert` backend (exit 2, no stderr — the binary never ran). | 3 / 2 | NonRetryable | inherited |
| `GazeSafetyNetFailureException` | Backend ran but failed (`Timeout`, `WeightsMissing`, `InputTooLarge`, `Unsupported`, `SuspectedLeak`, `Runtime`, `InvalidOutput`, `ModelUnavailable`, `Unavailable`, `Other`). | 3 | varies — implements `HasRetryDisposition`; classify via `GazeRetryPolicy::classify()` or `retryDisposition()` | `safetyNetVariant(): string` |
| `GazeSafetyNetArtifactMissingException` (v0.9.0 new) | Backend's pinned artifact bundle is missing or incomplete — e.g. the policy's `[safety_net.nym] model_dir` (or `GAZE_NYM_MODEL_DIR`) points at a directory without the Nym bundle; `path()` is upstream's `<missing:SHA256SUMS> (install via …)` placeholder, not the directory. | 2 | NonRetryable | `backend(): string`, `path(): string` |

Use `GazeRetryPolicy::classify()` to route exceptions onto your queue's
retry / fail / alert lanes:

```php
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use CertaMesh\Gaze\Exceptions\GazeException;
use CertaMesh\Gaze\Facades\Gaze;
use CertaMesh\Gaze\Queue\GazeRetryPolicy;

final class CleanRequestJob
{
    use Queueable, InteractsWithQueue;

    public array $backoff = [10, 30, 120];

    public function handle(): void
    {
        try {
            $session = Gaze::clean($this->body);
            // ... hand $session->cleanText to your LLM
        } catch (GazeException $e) {
            // Routes to fail() / release() / alert based on retry contract.
            GazeRetryPolicy::dispatch($e, $this);
        }
    }
}
```

### Variant-dependent retry disposition (`HasRetryDisposition`)

`GazeSafetyNetFailureException` is unusual: the upstream `SafetyNetFailure`
envelope carries a `variant` string (`Timeout`, `SuspectedLeak`,
`WeightsMissing`, etc.) that decides the retry lane at runtime, so no static
marker interface (`NonRetryable`, `Retryable`, `RetryableWithAlert`) can
describe it — it implements **none** of them. Instead it implements
`CertaMesh\Gaze\Queue\Contracts\HasRetryDisposition`, whose
`retryDisposition(): RetryAction` inspects `safetyNetVariant()`:

- `Timeout`, `Other` → `RetryAction::ReleaseWithBackoff`
- `SuspectedLeak` → `RetryAction::ReleaseWithAlert` (fires `GazeInfraAlert`)
- `WeightsMissing`, `InputTooLarge`, `Unsupported` — and any variant this
  package does not know yet — → `RetryAction::Fail` (fail closed)

`GazeRetryPolicy::classify()` consults `HasRetryDisposition` before the marker
interfaces, so classification works with no special-casing. If you branch on
markers yourself, add a `HasRetryDisposition` arm first:

```php
use CertaMesh\Gaze\Queue\Contracts\HasRetryDisposition;
use CertaMesh\Gaze\Queue\GazeRetryPolicy;

// Either delegate entirely:
$action = GazeRetryPolicy::classify($e);

// Or, in a hand-rolled instanceof chain, check the disposition contract first:
if ($e instanceof HasRetryDisposition) {
    $action = $e->retryDisposition();
}
```

> **History:** before v1.0 this exception implemented all three markers
> simultaneously, which made a naive `instanceof NonRetryable` match even for
> retryable variants. That contradiction is resolved by `HasRetryDisposition`
> (design Q5 from the v0.6.6 dogfooding pass).

## Migration notes (v0.8.x → v0.9.x)

The `safety_net_mode` upstream default flipped from `strict` to `resolve` in
v0.8.1 of the pinned binary. Adopters who relied on the legacy
strict-as-default behaviour must set `GAZE_SAFETY_NET_MODE=strict` explicitly.
See [`docs/upgrading.md`](./upgrading.md) for the full v0.8.1 → v0.9.0
walkthrough.

## Security notes

- **SafetyNet runs locally.** OPF invokes a Python child process and Nym
  runs inside the gaze binary, both on the same host as your Laravel app. No
  cleaned-manifest text crosses the network on its own — network exposure is
  still entirely a function of where you send `$session->cleanText`
  afterwards.
- **Model-bundle checks are enforced by the upstream binary, not the
  adapter.** Fetch the Nym bundle with `gaze setup --safety-net nym` as the
  user that runs gaze. If you manage it manually (e.g. baked into a container
  image at build time), keep it readable by that user only — the binary fails
  closed if it cannot read the bundle.
- **`SafetyNet` is a second-opinion detector, not a guarantee.** It catches
  PII that Pass-1 / Pass-2 missed; it cannot create privacy you do not
  already have. Treat a flagged suspected leak as a signal to review the
  source policy, not as an excuse to relax it.

## Doctor + CI gating

Recommended pattern: gate deploy / job-runner boot on `php artisan
gaze:doctor` whenever SafetyNet is enabled. Doctor's exit code is
CI-friendly (`0` pass / non-zero fail).

```bash
#!/usr/bin/env bash
set -euo pipefail

# Run before booting queue workers / serving traffic.
if [[ "${GAZE_SAFETY_NET:-false}" == "true" ]]; then
  php artisan gaze:doctor
fi
```

This catches the removed `kiji-distilbert` backend before the first user
request hits a queue job that would otherwise dead-letter on
`GazeSafetyNetConfigException`. Add `--deep` to also exercise the active
backend (a missing Nym bundle, a missing OPF binary) through a real
round-trip.

## See also

- [Upstream coverage matrix](../reference/upstream-coverage.md) — full upstream-flag ↔
  Laravel-surface mapping for SafetyNet and every other CLI surface.
- [Upgrading](./upgrading.md) — per-minor adapter upgrade guide, including
  the `strict → resolve` default flip.
- [Exceptions](../reference/exceptions.md) — full typed exception reference with exit
  buckets and retry-contract semantics.
- [Queue integration](./queue-integration.md) — `GazeRetryPolicy` deep-dive, alert
  routing, and backoff schedule conventions.
