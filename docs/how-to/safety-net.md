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
Set `GAZE_SAFETY_NET=true` to enable it, then pick a backend.

## Backends

| Backend | Selector value | What it is | When to pick it |
|---|---|---|---|
| OpenAI Privacy Filter (OPF) | `openai-filter` | Eight typed labels covering names, emails, phone numbers, addresses, etc. Python subprocess, GPU optional. | You already manage Python in production and want the legacy v0.6.x default. |
| Kiji DistilBERT (new in v0.8.x) | `kiji-distilbert` | Lightweight ONNX DistilBERT NER subprocess. Closed label set: `person`, `location`, `organization`, `miscellaneous`. Pinned-artifact bundle (no Python). | You want a single self-contained binary + model dir without a Python toolchain. |

Both backends share the same `safety_net_mode` / `safety_net_fallback` /
`safety_net_timeout_ms` / `safety_net_input_limit_bytes` knobs.

## Quick start (OpenAI Privacy Filter)

```env
GAZE_SAFETY_NET=true
GAZE_SAFETY_NET_BACKEND=openai-filter
GAZE_OPENAI_FILTER_COMMAND=/usr/local/bin/opf
```

`GAZE_OPENAI_FILTER_COMMAND` is optional when `opf` is already on `PATH`. See
the upstream [OPF README](https://github.com/EmpireTwo/gaze/tree/main/crates/gaze-safety-net-openai-filter)
for the Python install and weights-fetch steps.

## Quick start (Kiji DistilBERT)

```env
GAZE_SAFETY_NET=true
GAZE_SAFETY_NET_BACKEND=kiji-distilbert
GAZE_KIJI_DISTILBERT_COMMAND=/usr/local/bin/kiji-runner
GAZE_KIJI_DISTILBERT_MODEL_DIR=/var/lib/gaze/kiji-model
```

The model directory must contain four artifacts before the first `gaze clean`
or the binary fails closed with `SafetyNetArtifactMissing` (see
[Exception handling](#exception-handling) below):

- `SHA256SUMS`
- `labels.json`
- `model.onnx`
- `tokenizer.json`

Fetch them with the upstream helper (one-shot; idempotent; sets `0o700` on the
dir and `0o600` on each file):

```bash
# Run from the upstream gaze checkout
./scripts/fetch-kiji-safetynet-model.sh /var/lib/gaze/kiji-model
```

Then verify with `php artisan gaze:doctor` — see [Doctor probe](#doctor-probe).

## Config reference

All keys live under `config/gaze.php`. Every nullable key forwards as an exact
upstream `--flag=<value>` when set, or omits the flag entirely when `null` —
i.e. `null` means "defer to the binary's own default", never "force-disable".
The argv-forwarding contract lives in `src/Gaze.php:107` (the `clean()`
safety-net block).

| Config key | Env var | Type | Default | Meaning |
|---|---|---|---|---|
| `gaze.safety_net` | `GAZE_SAFETY_NET` | `bool` | `false` | Master switch. When `false`, no safety-net flag is forwarded. When `true`, the binary runs Pass-3 against the active backend. Legacy v0.6.5 key. |
| `gaze.safety_net_backend` | `GAZE_SAFETY_NET_BACKEND` | `string\|null` | `null` | Backend selector. Valid: `openai-filter`, `kiji-distilbert`. `null` lets the binary keep the v0.6/v0.7 single-backend default of `openai-filter`. Wins over the legacy `--safety-net=<kind>` flag when both are set. |
| `gaze.openai_filter_command` | `GAZE_OPENAI_FILTER_COMMAND` | `string\|null` | `null` | Absolute path to the `opf` binary. `null` lets the binary `PATH`-resolve. |
| `gaze.openai_filter_checkpoint` | `GAZE_OPENAI_FILTER_CHECKPOINT` | `string\|null` | `null` | Model-checkpoint directory for OPF. `null` uses the binary's built-in default. |
| `gaze.openai_filter_operating_point` | `GAZE_OPENAI_FILTER_OPERATING_POINT` | `string\|null` | `null` | Sensitivity trade-off. Valid: `high-recall`, `balanced`, `high-precision`. `null` uses the binary's default. |
| `gaze.kiji_backend` | `GAZE_KIJI_BACKEND` | `string\|null` | `null` | Kiji runtime backend. Valid in release binaries: `subprocess`, `ort`. `int8` precision requires `ort`. |
| `gaze.kiji_distilbert_precision` | `GAZE_KIJI_DISTILBERT_PRECISION` | `string\|null` | `null` | Kiji ONNX precision. Valid: `fp32`, `int8`. `null` defers to upstream's fp32 default. |
| `gaze.kiji_distilbert_command` | `GAZE_KIJI_DISTILBERT_COMMAND` | `string\|null` | `null` | Absolute path to the Kiji runner. `null` lets the binary `PATH`-resolve. |
| `gaze.kiji_distilbert_model_dir` | `GAZE_KIJI_DISTILBERT_MODEL_DIR` | `string\|null` | `null` | Pinned-artifact directory for the Kiji backend. Required when `safety_net_backend=kiji-distilbert`. Must carry `SHA256SUMS`, `labels.json`, `model.onnx`, `tokenizer.json` (`0o700` dir / `0o600` files). |
| `gaze.safety_net_device` | `GAZE_SAFETY_NET_DEVICE` | `string\|null` | `null` | CUDA / CPU device hint forwarded as `--openai-filter-device` (e.g. `cuda:0`, `cpu`). OPF-specific. |
| `gaze.safety_net_timeout_ms` | `GAZE_SAFETY_NET_TIMEOUT_MS` | `int\|null` | `null` | Subprocess timeout, milliseconds. Must be positive. `null` uses the binary's default of `5000`. Applies to both backends. |
| `gaze.safety_net_input_limit_bytes` | `GAZE_SAFETY_NET_INPUT_LIMIT_BYTES` | `int\|null` | `null` | Clean-text size cap, bytes. `null` uses the binary's default of `1048576`. Applies to both backends. |
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

## Doctor probe

`php artisan gaze:doctor` runs a backend-specific pre-flight when SafetyNet
is enabled. The Kiji probe (`src/Console/DoctorCommand.php:162`,
`probeKijiArtifacts()`):

- **Skips silently** when `gaze.safety_net_backend !== 'kiji-distilbert'`.
  The OPF backend ships its own pre-flight upstream; the default `null`
  backend selector means "let upstream choose" so there is no Kiji-specific
  contract to enforce.
- **Asserts `gaze.kiji_distilbert_model_dir` is set** and points at a
  directory.
- **Asserts the four pinned artifacts exist**: `SHA256SUMS`, `labels.json`,
  `model.onnx`, `tokenizer.json`. Anything missing flips doctor status to
  `FAIL` and surfaces the remediation hint.

Failure output (paraphrased — exact wording lives in
`src/Console/DoctorCommand.php`):

```
kiji_distilbert  missing: SHA256SUMS, model.onnx
gaze.kiji_distilbert_model_dir is missing required artifacts
(SHA256SUMS, model.onnx). Re-fetch with upstream
scripts/fetch-kiji-safetynet-model.sh; the dir must carry 0o700
permissions and each file 0o600.
status           FAIL
```

The Kiji pre-flight is fail-closed by design: the binary itself surfaces a
typed `SafetyNetArtifactMissing` envelope on the first `gaze clean` if you
skip the probe, but doctor's job is to catch this at deploy time, not at the
first user request.

## Exception handling

SafetyNet failures map onto three typed exceptions. All three sit under the
`Naoray\GazeLaravel\Exceptions\` namespace and share the
`GazeException::toLogContext()` shape.

| Exception | When raised | Exit | Retry policy | Accessors |
|---|---|---|---|---|
| `GazeSafetyNetConfigException` | Config invalid (e.g. `safety_net_backend=kiji-distilbert` with no `kiji_distilbert_command`). | 3 | NonRetryable | inherited |
| `GazeSafetyNetFailureException` | Backend ran but failed (`Timeout`, `WeightsMissing`, `InputTooLarge`, `Unsupported`, `SuspectedLeak`, `Runtime`, `InvalidOutput`, `ModelUnavailable`, `Unavailable`, `Other`). | 3 | varies — classify via `GazeRetryPolicy::classify()` | `safetyNetVariant(): string` |
| `GazeSafetyNetArtifactMissingException` (v0.9.0 new) | Backend's pinned artifact bundle is incomplete. Currently raised by the Kiji backend; the contract is generic for future pinned-artifact backends. | 2 | NonRetryable | `backend(): string`, `path(): string` |

Use `GazeRetryPolicy::classify()` to route exceptions onto your queue's
retry / fail / alert lanes:

```php
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Naoray\GazeLaravel\Exceptions\GazeException;
use Naoray\GazeLaravel\Facades\Gaze;
use Naoray\GazeLaravel\Queue\GazeRetryPolicy;

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

### Caveat — do not branch on `instanceof` for `GazeSafetyNetFailureException`

`GazeSafetyNetFailureException` is unusual: it implements **all three** retry
marker contracts (`NonRetryable`, `Retryable`, `RetryableWithAlert`)
simultaneously, because the upstream `SafetyNetFailure` envelope carries a
`variant` string (`Timeout`, `SuspectedLeak`, `WeightsMissing`, etc.) that
decides the retry lane at runtime. A naive `instanceof NonRetryable` check
outside `GazeRetryPolicy::classify()` always matches, even for retryable
variants. **Always classify via `GazeRetryPolicy::classify()`** — it inspects
`safetyNetVariant()` and routes correctly:

- `Timeout`, `Other` → `RetryAction::ReleaseWithBackoff`
- `SuspectedLeak` → `RetryAction::ReleaseWithAlert` (fires `GazeInfraAlert`)
- `WeightsMissing`, `InputTooLarge`, `Unsupported` → `RetryAction::Fail`

This is the resolved design Q5 from the v0.6.6 dogfooding pass; the roadmap
scratchpad (`SafetyNetFailure { variant }` row) carries the full caveat.

## Migration notes (v0.8.x → v0.9.x)

The `safety_net_mode` upstream default flipped from `strict` to `resolve` in
v0.8.1 of the pinned binary. Adopters who relied on the legacy
strict-as-default behaviour must set `GAZE_SAFETY_NET_MODE=strict` explicitly.
See [`docs/upgrading.md`](./upgrading.md) for the full v0.8.1 → v0.9.0
walkthrough.

## Security notes

- **The SafetyNet subprocess runs locally.** Both backends invoke a child
  process (Python `opf` or the Kiji ONNX runner) on the same host as your
  Laravel app. No cleaned-manifest text crosses the network on its own —
  network exposure is still entirely a function of where you send
  `$session->cleanText` afterwards.
- **Model-directory permissions are enforced by the upstream binary, not the
  adapter.** For Kiji the upstream `scripts/fetch-kiji-safetynet-model.sh`
  helper sets `0o700` on the model dir and `0o600` on each artifact. If you
  manage the dir manually (e.g. baked into a container image at build time),
  mirror those bits — the binary will fail closed if it cannot read the
  artifacts, but it does not weaken its trust posture if you over-relax the
  permissions.
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

This catches the common deploy-time failure modes — missing Kiji artifacts,
mis-pointed model dir, missing OPF binary — before the first user request
hits a queue job that would otherwise dead-letter on
`GazeSafetyNetArtifactMissingException` or `GazeSafetyNetConfigException`.

## See also

- [Upstream coverage matrix](../reference/upstream-coverage.md) — full upstream-flag ↔
  Laravel-surface mapping for SafetyNet and every other CLI surface.
- [Upgrading](./upgrading.md) — per-minor adapter upgrade guide, including
  the `strict → resolve` default flip.
- [Exceptions](../reference/exceptions.md) — full typed exception reference with exit
  buckets and retry-contract semantics.
- [Queue integration](./queue-integration.md) — `GazeRetryPolicy` deep-dive, alert
  routing, and backoff schedule conventions.
