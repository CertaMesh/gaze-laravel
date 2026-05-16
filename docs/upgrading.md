# Upgrading

Per-minor upgrade guide for `empiretwo/gaze-laravel`. Pair with
[CHANGELOG.md](../CHANGELOG.md) and the upstream binary's
[UPGRADE.md](https://github.com/EmpireTwo/gaze/blob/main/UPGRADE.md).

## v0.8.1 → v0.9.0

> Pre-1.0 SemVer MINOR bump: binary pin advances to upstream `gaze`
> v0.9.0 final plus net-new feature surface (Kiji backend selector with
> ORT int8 reach, three Kiji + fallback flags,
> `SafetyNetArtifactMissing` typed exception, new `Variant` case, new
> `DoctorCommand` probe) and the upstream default flip on
> `safety_net_mode`. Patch framing (v0.8.2) was rejected in review —
> additive features land on MINOR.

### TL;DR

1. **Binary pin bump.** `BinaryInstaller::PINNED_VERSION` advances to
   upstream `gaze` `0.9.0`. Pin `GAZE_VERSION=0.8.1` temporarily if you
   need to hold the previous binary while validating.
2. **Upstream `safety_net_mode` default flipped `strict`→`resolve`.** v0.8.1
   binaries no longer treat unset `--safety-net-mode` as `strict`; the new
   default is `resolve`, which Pass-3 routes suspected leaks through the
   active backend before redacting. Adopters who relied on the legacy
   strict-as-default behaviour must set `GAZE_SAFETY_NET_MODE=strict`
   explicitly. No code changes required on the adapter side — the binary
   applies the new default when the flag is omitted.
3. **Kiji DistilBERT safety-net backend (opt-in), ORT int8 reachable.** A
   new `gaze.safety_net_backend` config knob accepts `kiji-distilbert` to
   route Pass-3 through the upstream Tier 2.5 DistilBERT NER subprocess.
   Paired with `gaze.kiji_distilbert_command` /
   `gaze.kiji_distilbert_model_dir` so adopters can pin the local binary
   + artifact directory. To enable the v0.9 ORT int8 path, set
   `GAZE_SAFETY_NET=1`, `GAZE_SAFETY_NET_BACKEND=kiji-distilbert`,
   `GAZE_KIJI_BACKEND=ort`, and `GAZE_KIJI_DISTILBERT_PRECISION=int8`
   alongside your Kiji model directory.
4. **`SafetyNetArtifactMissing` exception.** When the Kiji (or any
   future pinned-artifact) backend can't find its required files,
   upstream emits a typed envelope at exit 2; the adapter now throws
   `GazeSafetyNetArtifactMissingException` with `backend()` + `path()`
   accessors. Inherits `NonRetryable` — queue retry policy fails fast.
5. **`gaze:doctor` Kiji pre-flight.** When `gaze.safety_net_backend` is
   `kiji-distilbert`, doctor verifies the model directory carries
   `SHA256SUMS`, `labels.json`, `model.onnx`, and `tokenizer.json` before
   the binary fails closed on the first invocation.
6. **Daemon restore is deferred.** Upstream `gaze daemon` in v0.9.0 is
   clean-only JSONL and does not return the signed `session_blob`
   required by `Gaze::restore()`. The PHP adapter keeps the one-shot
   clean/restore contract for reversible round trips.

### Action required

- Only if you relied on `safety_net_mode=strict` as an implicit default:
  set `GAZE_SAFETY_NET_MODE=strict` in your environment (or
  `gaze.safety_net_mode` in published config) to keep pre-v0.8.1
  behaviour. Everyone else: no action.

### Additive

- **Four new clean flags** wrap upstream's Kiji + fallback surfaces:
  `--safety-net-backend`, `--kiji-distilbert-command`,
  `--kiji-distilbert-model-dir`, `--safety-net-fallback`. All four config
  keys default to null (defer to upstream).
- **`safety_net_mode` accepts `redact` and `resolve`** in addition to the
  legacy `strict` / `tolerant`. The `tolerant` value emits a deprecation
  warning upstream.
- **Kiji backend + precision selectors.** `gaze.kiji_backend` /
  `GAZE_KIJI_BACKEND` forwards `--kiji-backend`;
  `gaze.kiji_distilbert_precision` / `GAZE_KIJI_DISTILBERT_PRECISION`
  forwards `--kiji-distilbert-precision`. Required for the v0.9 ORT int8
  path.
- CI pins `GAZE_VERSION=0.9.0` so installer tests and future integration
  jobs exercise the intended upstream tag by default.

> See [docs/safety-net.md](./safety-net.md) for the adopter usage guide for both SafetyNet backends.

## v0.7.x → v0.8.0

### TL;DR

1. **Bundle unification.** The published `resources/policy.toml` now ships
   `bundled = ["core"]` only. Adopters who copied the previous version
   with `["core", "core-extended"]` should drop `core-extended` and pass
   a locale flag (`GAZE_LOCALE=de-DE` or similar) to keep
   `phone.national.*` / `postal.*` coverage.
2. **Binary pin bump.** `BinaryInstaller::PINNED_VERSION` jumps from
   `0.7.2` to `0.8.0`. The `GAZE_VERSION` env override is still honoured
   if you want to stay on v0.7.x temporarily.
3. **10 new locale-gated entities.** Set `GAZE_LOCALE` to opt in to
   Aadhaar / NIR / Steuer-ID / BSN / CPF / CNPJ / NHS / SSN / NINO / PAN
   coverage. All additive — no behaviour change without the locale flag.

### Action required

- Only if `core-extended` appears in your `gaze.rulepacks` config or in
  your application's `policy.toml`: edit those files to drop
  `core-extended` and set an explicit `--locale` (or `GAZE_LOCALE`). Run
  `php artisan gaze:doctor` to surface the deprecation warning if you
  missed any.

### Additive

- **Versioned recognizer IDs on audit rows.** `Audit\QueryBuilder`
  returns the new `recognizer_id` + `recognizer_version_id` columns
  verbatim — no code change needed. See
  [`docs/upstream-coverage.md`](./upstream-coverage.md) for the column
  semantics.
- **`PolicySchemaUnsupported` typed exception.** Already shipped in
  v0.7.0; v0.8 binaries are the first to emit it for schemas the
  adapter does not know.

### Deferred to v0.8.x

- **`gaze proxy` daemon mode** is not yet exposed via Artisan. Adopters
  who want the proxy today can run `gaze proxy start` against the
  installed binary; Laravel-side wrappers (config block + 5 artisan
  commands) land in the next adapter minor.
- **`Ipv4Parse` / `Ipv6Parse` / `EthEip55` validator kinds** are still
  deferred from v0.7.x; tracked for v0.8.x adapter release.

## v0.6.x → v0.7.0

`gaze-laravel` 0.7.0 pinned the upstream `EmpireTwo/gaze` binary at
**v0.7.2** (previously v0.6.6). Adopters who never override
`GAZE_VERSION` silently jumped across the upstream 0.6 → 0.7 boundary
on `composer update`.

- **Existing `policy.toml` files keep loading.** Upstream v0.7.2
  introduces a top-level `schema_version` field but soft-defaults
  missing values to `0.1.0`, so 0.6.x policies stay drop-in. Pin
  explicitly with `schema_version = "0.1"` at the top of `policy.toml`
  once you want the schema-drift gate to fail closed on future contract
  breaks.
- **New typed exception `GazePolicySchemaUnsupportedException`** fires
  when the binary rejects a policy whose `major.minor` prefix does not
  match its supported schema. The exception carries `found()` /
  `supported()` accessors and shares the `exit=2` bucket with other
  config errors.
- **Existing `GazePolicyConfigDetailException` now exposes `detail()`** —
  upstream threads loader-cause strings (e.g.
  `"unknown bundled rulepack: garbage"`) through the additive `detail`
  sidecar on `{"error":"PolicyConfig","exit":2}`. Code that already
  catches this class needs no change; new code can read `$e->detail()`
  directly.
- **MCP + document subcommands are deferred.** Upstream `gaze mcp
  install` / `gaze document clean` and new validator kinds (`Ipv4Parse`,
  `Ipv6Parse`, `EthEip55`) are tracked as separate follow-up surfaces
  and intentionally not yet exposed through artisan commands or facade
  methods.
