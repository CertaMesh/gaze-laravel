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
| Nym (gaze >= 0.15.0) | `nym` | Pinned Nym-small int8 bundle run by ONNX Runtime inside the gaze binary; compiled into the release binary. | You want a safety net on the stock release binary. See [Quick start (Nym)](#quick-start-nym). |
| OpenAI Privacy Filter (OPF) | `openai-filter` | Eight typed labels covering names, emails, phone numbers, addresses, etc. Python subprocess, GPU optional. Needs a gaze binary built with upstream's `safety-net-openai` feature — the release binaries are not. | You build gaze yourself and already manage Python in production. |

The shared `safety_net_mode` / `safety_net_fallback` /
`safety_net_timeout_ms` / `safety_net_input_limit_bytes` knobs apply to every
backend.

The **Kiji DistilBERT** backend (`kiji-distilbert`) and every `--kiji-*` flag
were removed upstream in gaze 0.15.0. See
[Kiji was removed upstream](#kiji-was-removed-upstream-in-gaze-0150).

## Quick start (Nym)

Nym is compiled into the gaze release binary (gaze >= 0.15.0). It needs a
pinned model bundle that upstream `gaze setup` downloads and verifies; the
adapter never downloads it.

1. **Fetch the bundle as the user that runs gaze**: the PHP-FPM pool user and
   your queue workers' user, not the deploy user. gaze refuses a bundle it does
   not own (see [Nym bundle ownership](#nym-bundle-ownership)). Use the binary
   the adapter resolves (`GAZE_BINARY`, else `vendor/bin/gaze`). The data home
   must exist and belong to that user:

   ```bash
   sudo install -d -o www-data /srv/gaze
   sudo -u www-data env XDG_DATA_HOME=/srv/gaze vendor/bin/gaze setup \
       --safety-net nym --non-interactive \
       --policy-out /srv/gaze/gaze-setup.toml --force
   ```

   The bundle lands in `$XDG_DATA_HOME/gaze/models/nym-small-int8` (here
   `/srv/gaze/gaze/models/nym-small-int8`). Upstream `setup` has no flag that
   moves the Nym bundle: `--model-dir` only moves the NER model, which `setup`
   also downloads (~680 MB). `setup` also writes a starter policy to
   `--policy-out`. The adapter does not use it, so delete it. `--force` only
   lets a re-run overwrite that file; without it a re-run fails at the very
   end, after the downloads.

2. **Wire it.** Run the installer as the runtime user, or name that user with
   `--runtime-user` (a name or a uid). Whoever runs it must be able to write
   `.env`:

   ```bash
   php artisan gaze:install:safety-net --safety-net=nym \
       --nym-model-dir=/srv/gaze/gaze/models/nym-small-int8 \
       --runtime-user=www-data
   ```

   The installer first runs the same bundle checks as `gaze:doctor`, with the
   owner checked against the runtime user. If one fails it leaves `.env`
   untouched and prints the `gaze setup` command (and a `chown` / `chmod` fix
   for an existing directory). A `0700` bundle of another user is closed to
   the installer: it then checks only the directory itself, writes `.env`, and
   tells you to check the files with `gaze:doctor` as the runtime user (step
   3). Otherwise it writes:

   ```env
   GAZE_SAFETY_NET=true
   GAZE_SAFETY_NET_BACKEND=nym
   GAZE_NYM_MODEL_DIR=/srv/gaze/gaze/models/nym-small-int8
   ```

   Without `--nym-model-dir` the installer prompts for the directory, or,
   non-interactively, accepts a directory that `GAZE_NYM_MODEL_DIR` or the
   policy already names (and then writes only the first two keys). A
   `GAZE_NYM_MODEL_DIR=` line with no value counts as set: gaze uses the empty
   value and never reads the policy, so the installer refuses it. Remove the
   line, or pass `--nym-model-dir`, which fills it in.

   `php artisan gaze:install --safety-net=nym --nym-model-dir=…` runs the same
   step inside the umbrella install. It has no `--runtime-user` and ends on a
   `gaze:doctor` run as you, so use it only as the runtime user.

3. **Check it as the runtime user:** run `php artisan config:clear` (or
   re-cache), then `sudo -u www-data php artisan gaze:doctor`. Add `--deep` for
   a real round trip through the bundle.

### Nym config keys

| Config key | Env var | Forwarded as | Default |
|---|---|---|---|
| `gaze.safety_net.nym.model_dir` | `GAZE_NYM_MODEL_DIR` | `--nym-model-dir=<dir>` | `null`: gaze falls back to `GAZE_NYM_MODEL_DIR` in its process environment, then the policy |
| `gaze.safety_net.nym.intra_threads` | `GAZE_NYM_INTRA_THREADS` | `--nym-intra-threads=<n>` | `null`: upstream default of 1 ONNX Runtime thread |

Both are forwarded on `Gaze::clean()` / `Gaze::mask()` and on both daemon spawn
paths (`Gaze::daemon()`, `gaze:daemon:serve`), **only while
`GAZE_SAFETY_NET=true` and `GAZE_SAFETY_NET_BACKEND=nym`**. gaze rejects them in
any other state (exit 3, `safety-net backend options require
--safety-net=<kind> activation`), so leftover values on a disabled net stay
inert. `intra_threads` must be a positive integer. `0`, a negative number or
a value that is no integer (`1.5`, `abc`) fails closed before spawning with
`GazeSafetyNetConfigException` (upstream would answer `0` with a detail-less
`PolicyConfig`). `gaze:doctor` fails on it too.

The adapter passes the directory as a flag, so it survives
`php artisan config:cache`. Before v0.16.0, short of editing the policy, the
bundle could only reach gaze through a `GAZE_NYM_MODEL_DIR` in the worker's
real process environment, which `config:cache` silently dropped whenever it
came from `.env`.

**Policy-file alternative.** Leave `GAZE_NYM_MODEL_DIR` unset and name the
directory in the policy that `GAZE_POLICY_PATH` points at (the adapter always
passes it as `--policy`):

```toml
[safety_net.nym]
model_dir = "/srv/gaze/gaze/models/nym-small-int8"
```

gaze takes the first of: `--nym-model-dir` (the config key),
`GAZE_NYM_MODEL_DIR` in the gaze process environment, the policy's
`model_dir`. Use an absolute path; gaze resolves a relative one against the
worker's working directory. Keep the on/off switch in `.env`: a policy
`[safety_net] backend = "nym"` table turns Nym on even while
`GAZE_SAFETY_NET=false`, and `gaze:doctor` probes the bundle only when the
adapter enables the net.

**Failure modes.** With no directory configured anywhere, clean fails with
`GazeSafetyNetConfigException` (`nym model_dir is missing`); a missing or
incomplete directory fails with `GazeSafetyNetArtifactMissingException`
(`backend()` = `nym`, `path()` = `<missing:SHA256SUMS> (install via …)`), a
bundle gaze does not trust (owner, mode, digest) with
`GazeSafetyNetConfigException`. A `GAZE_NYM_MODEL_DIR` that is set but empty
counts as a directory: gaze takes the empty path, skips the policy, and fails
with `GazeSafetyNetArtifactMissingException`. Through `Gaze::daemon()` they
all surface as `GazeDaemonTransportException`: the daemon exits at startup,
and its stderr goes to `gaze.daemon.stderr_path`. `gaze:doctor` catches all
of them before the first request: the digest case through its clean probe,
which loads the bundle the way every clean does.

### Nym bundle ownership

gaze checks the bundle on every clean and every daemon start, as the user it
runs as (the effective uid), and refuses it when:

- the directory, or one of `SHA256SUMS`, `config.json`, `model_int8.onnx`,
  `tokenizer.json`, is missing, or one of those files is not readable;
- any path in it is not owned by that user;
- the directory, or a directory in it, is not mode exactly `0700`;
- a file is group- or world-writable;
- anything in it is a symlink, or neither a regular file nor a directory (a
  fifo, a socket);
- a file does not match its pinned SHA-256 digest.

Under PHP-FPM that user is the pool user (`www-data`, `nginx`, …); under
Horizon or supervisord it is the worker user. Fetch the bundle as that user, or
hand an existing one over:

```bash
sudo chown -R www-data /srv/gaze/gaze/models/nym-small-int8
sudo chmod -R u+rwX,go-w /srv/gaze/gaze/models/nym-small-int8
sudo find /srv/gaze/gaze/models/nym-small-int8 -type d -exec chmod 700 {} +
```

`gaze:doctor` and the installer mirror every check except the digests, which
the binary verifies when it loads the bundle (doctor's clean probe does), but as
the user that runs *them*. A deploy user's doctor run says nothing about the pool user, so run
doctor as the runtime user. The installer can judge for that user instead
(`--runtime-user`), but it cannot open a `0700` bundle of another user, so it
leaves the files to doctor. Without ext-posix neither can check the owner:
doctor shows `WARN owner not checked (ext-posix missing)` instead of `OK`.

### Nym latency: prefer the daemon

A one-shot `Gaze::clean()` loads the Nym model on every call: about 2 s per
call on the 0.15.1 binary. `Gaze::daemon()` loads it once at startup, so use it
for anything beyond occasional cleans (see the [daemon how-to](./daemon.md)).
Raise `GAZE_DAEMON_REQUEST_TIMEOUT_MS` if the first request after a cold start
times out.

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
to Nym ([Quick start (Nym)](#quick-start-nym)). Then remove the `GAZE_KIJI_*`
env vars and any `kiji` block from a published `config/gaze.php`, run
`php artisan config:clear` (or re-cache), and run
`php artisan gaze:doctor --deep` as the runtime user.

## Config reference

All keys live under `config/gaze.php`. Every nullable key forwards as an exact
upstream `--flag=<value>` when set, or omits the flag entirely when `null` —
i.e. `null` means "defer to the binary's own default", never "force-disable".
The argv-forwarding contract is the declarative flag map in `Gaze::clean()`
(`src/Gaze.php`) and its daemon twin `Daemon\DaemonArgv`.

| Config key | Env var | Type | Default | Meaning |
|---|---|---|---|---|
| `gaze.safety_net` | `GAZE_SAFETY_NET` | `bool` | `false` | Master switch. When `false`, no safety-net flag is forwarded. When `true`, the binary runs Pass-3 against the active backend. Legacy v0.6.5 key. |
| `gaze.safety_net_backend` | `GAZE_SAFETY_NET_BACKEND` | `string\|null` | `null` | Backend selector. Valid: `openai-filter`, `nym`, spelled exactly (gaze rejects `Nym`); `gaze:doctor` fails any other value on an enabled net. Forwarded only while `gaze.safety_net` is `true`. `null` lets the binary keep its single-backend default of `openai-filter`. Wins over the legacy `--safety-net=<kind>` flag. `kiji-distilbert` was removed upstream in gaze 0.15.0 and fails closed before spawning. |
| `gaze.safety_net.nym.model_dir` | `GAZE_NYM_MODEL_DIR` | `string\|null` | `null` | Nym bundle directory, forwarded as `--nym-model-dir` only while the enabled net selects `nym`. Wins over the policy's `[safety_net.nym] model_dir`. See [Nym config keys](#nym-config-keys). |
| `gaze.safety_net.nym.intra_threads` | `GAZE_NYM_INTRA_THREADS` | `int\|null` | `null` | ONNX Runtime threads for Nym, forwarded as `--nym-intra-threads` only while the enabled net selects `nym`. Positive integer; anything else fails closed. `null` uses the binary's default of `1`. |
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

`php artisan gaze:doctor` probes the Nym bundle (`probeNymBundle()` in
`src/Console/DoctorCommand.php`) while `GAZE_SAFETY_NET=true` and
`GAZE_SAFETY_NET_BACKEND=nym`:

- **FAIL** when `gaze.safety_net.nym.intra_threads` is not a positive
  integer: the same pre-flight every clean and daemon start applies.

- **FAIL** when no bundle directory is configured anywhere (config key,
  `GAZE_NYM_MODEL_DIR` in the process environment, the policy's
  `[safety_net.nym] model_dir`). gaze would fail every clean with
  `nym model_dir is missing`. Doctor prints the `gaze setup` command:

  ```
  nym bundle ............................................... not configured
  GAZE_SAFETY_NET_BACKEND=nym, but no Nym bundle directory is configured: ...
  Fetch the bundle as the PHP-FPM pool / queue worker user (replace www-data):
  sudo -u www-data env XDG_DATA_HOME=/srv/gaze /app/vendor/bin/gaze setup --safety-net nym --non-interactive --policy-out /srv/gaze/gaze-setup.toml --force
  Then: php artisan gaze:install:safety-net --safety-net=nym --nym-model-dir=/srv/gaze/gaze/models/nym-small-int8 --runtime-user=www-data
  status ......................................................... FAIL
  ```

- **FAIL** when `GAZE_NYM_MODEL_DIR` is set but empty (a bare
  `GAZE_NYM_MODEL_DIR=` line in `.env`). gaze uses the empty value as is and
  never falls back to the policy, even when the policy names a good bundle:

  ```
  nym bundle ................................. GAZE_NYM_MODEL_DIR is empty
  GAZE_NYM_MODEL_DIR is set but empty. gaze uses it as is: it does not fall back to the policy, and every clean fails with SafetyNetArtifactMissing. Remove the GAZE_NYM_MODEL_DIR= line from .env (or the environment), or set it to the bundle directory.
  status ......................................................... FAIL
  ```

- **FAIL** when gaze would refuse the bundle: the directory or a required file
  is missing or unreadable, a path is not owned by the user running doctor, a
  directory is not `0700`, a file is group/world-writable, or a path is a
  symlink or neither a file nor a directory. The result names the uid it was
  checked as; run doctor as the PHP-FPM pool user
  (`sudo -u www-data php artisan gaze:doctor`) to check what gaze will see:

  ```
  nym bundle ......................................... refused for uid 1000 (deploy)
  gaze would refuse the Nym bundle at /srv/gaze/gaze/models/nym-small-int8 (from gaze.safety_net.nym.model_dir):
    - the directory is owned by uid 33 (www-data), checked as uid 1000 (deploy)
    - the directory is not readable by uid 1000 (deploy), so its files cannot be checked
  These checks ran as uid 1000 (deploy). gaze enforces them for the user that runs it, so run doctor as the PHP-FPM pool user, e.g. sudo -u www-data php artisan gaze:doctor.
  status ......................................................... FAIL
  ```

- **WARN** (exit code unchanged) when every other check passed but ext-posix
  is missing, so the owner could not be checked:
  `nym bundle ... WARN owner not checked (ext-posix missing)`.

- **OK** otherwise (`nym bundle ... OK for uid 33 (www-data)`). The SHA-256
  digests are left to the binary. Doctor's upstream-warning probe runs one real
  clean, so gaze checks them on every doctor run; a mismatch FAILs that row
  with a re-fetch hint.

It also checks the safety-net backend selector, for the upstream removal of
Kiji (`probeKijiRemoval()`) and for values gaze does not accept:

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

- **FAIL** when the safety net is enabled and `gaze.safety_net_backend` is
  any other value than exactly `openai-filter` or `nym`
  (`probeSafetyNetBackend()`). gaze matches the value exactly, so `Nym` fails
  every clean with a bare `PolicyConfig`, and the Nym bundle probe would never
  run for it:

  ```
  safety_net_backend ............................................ unknown 'Nym'
  GAZE_SAFETY_NET_BACKEND='Nym' is not a backend gaze accepts, so every clean fails with PolicyConfig. Use nym or openai-filter. Did you mean nym? gaze matches the value exactly: case and spaces count.
  status ........................................................... FAIL
  ```

- **Silent** otherwise. Doctor has no OPF-specific row, but its
  upstream-warning probe runs one real clean through the active backend, and
  `gaze:doctor --deep` a clean/restore round-trip.

## Exception handling

SafetyNet failures map onto three typed exceptions. All three sit under the
`CertaMesh\Gaze\Exceptions\` namespace and share the
`GazeException::toLogContext()` shape.

| Exception | When raised | Exit | Retry policy | Accessors |
|---|---|---|---|---|
| `GazeSafetyNetConfigException` | Config invalid: a backend subprocess/config error upstream (exit 3), a Nym setup error such as no bundle directory configured (exit 2, gaze >= 0.15.0), or the adapter's pre-flight for an enabled `kiji-distilbert` backend (exit 2, no stderr — the binary never ran). | 3 / 2 | NonRetryable | inherited |
| `GazeSafetyNetFailureException` | Safety net failed or refused the clean (`Timeout`, `Runtime`, `SuspectedLeak`, `Unavailable`, `WeightsMissing`, `ModelUnavailable`, `ModelIntegrityMismatch`, `InputTooLarge`, `InvalidOutput`, `TolerantModeDisabled`, `Unknown`). | 3 | varies — implements `HasRetryDisposition`; classify via `GazeRetryPolicy::classify()` or `retryDisposition()` | `safetyNetVariant(): string` |
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

- `Timeout`, `Runtime` → `RetryAction::ReleaseWithBackoff` (transient
  backend failures)
- `SuspectedLeak` → `RetryAction::ReleaseWithAlert` (fires `GazeInfraAlert`)
- `Unavailable`, `WeightsMissing`, `ModelUnavailable`,
  `ModelIntegrityMismatch`, `InputTooLarge`, `InvalidOutput`,
  `TolerantModeDisabled`, `Unknown` — and any variant this package does not
  know yet — → `RetryAction::Fail` (configuration, model or input problems
  that a retry does not fix; unknown variants fail closed)
- Legacy names no gaze release emits keep their old lanes until 1.0: `Other`
  → `ReleaseWithBackoff`, `Unsupported` → `Fail`

The [exception reference](../reference/exceptions.md#safety-net-and-session-scope-exceptions)
gives the reason for each. Daemon safety-net errors
(`DaemonErrorVariant::SafetyNet*` on `GazeDaemonException`) get the same lane
as the one-shot variant of the same name.

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
- **Model-bundle checks are enforced by the upstream binary.** The adapter
  mirrors them in `gaze:doctor` and the installer so you hear about them
  first, but never relaxes them. Fetch the Nym bundle with
  `gaze setup --safety-net nym` as the user that runs gaze. If you manage it
  manually (e.g. baked into a container image at build time), keep it owned by
  that user, the directory at `0700` — see
  [Nym bundle ownership](#nym-bundle-ownership).
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

This catches a missing or foreign-owned Nym bundle and the removed
`kiji-distilbert` backend before the first user request hits a queue job
that would otherwise dead-letter on `GazeSafetyNetConfigException`. Run it as
the user your workers run as. Its upstream-warning probe already runs one real
clean through the active backend (the Nym digests, a missing OPF binary); add
`--deep` for a full clean/restore round-trip.

## See also

- [Upstream coverage matrix](../reference/upstream-coverage.md) — full upstream-flag ↔
  Laravel-surface mapping for SafetyNet and every other CLI surface.
- [Upgrading](./upgrading.md) — per-minor adapter upgrade guide, including
  the `strict → resolve` default flip.
- [Exceptions](../reference/exceptions.md) — full typed exception reference with exit
  buckets and retry-contract semantics.
- [Queue integration](./queue-integration.md) — `GazeRetryPolicy` deep-dive, alert
  routing, and backoff schedule conventions.
