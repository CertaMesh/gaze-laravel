# Upstream Coverage

Living parity checklist for upstream `CertaMesh/gaze` v0.11.1.

> Adopter usage: [docs/safety-net.md](../how-to/safety-net.md). Why surfaces land here vs. defer: [docs/NORTH_STAR.md](../NORTH_STAR.md) (surface promotion rule). GDPR posture for these surfaces (pseudonymization, storage limitation, erasure): [docs/explanation/gdpr.md](../explanation/gdpr.md) — adopter guidance, not legal advice.

## Commands

| Upstream command | Laravel surface |
|---|---|
| `gaze clean` | `CertaMesh\Gaze\Gaze::clean()` |
| `gaze clean` (one-way output reshape) | `CertaMesh\Gaze\Gaze::mask()` — redacts the clean inventory into masked labels (`[Class]` default, or a `callable(Entry): string`). NON-reversible: no session blob, no `restore()` counterpart. Adds no detection — reshapes `clean()`'s tokens only. |
| `gaze restore` | `CertaMesh\Gaze\Gaze::restore()` |
| `gaze audit query` | `Gaze::audit()->query()` |
| `gaze audit purge` | `Gaze::audit()->purge()` |

## Clean Flags

| Upstream flag | Laravel surface |
|---|---|
| `--policy` | `gaze.policy_path` / `GAZE_POLICY_PATH` |
| `--format=json` | Always set by `Gaze::clean()` |
| `--max-bytes` | `gaze.max_bytes` / `GAZE_MAX_BYTES` |
| `--session-ttl` | `gaze.session_ttl_seconds` / `GAZE_SESSION_TTL` |
| `--session-scope` | `gaze.session_scope` / `GAZE_SESSION_SCOPE` |
| `--audit-db` | `gaze.audit_db_path` / `GAZE_AUDIT_DB_PATH` |
| `--locale` | `gaze.locale` / `GAZE_LOCALE` |
| `--ner-threshold` | per-call `Gaze::clean($text, $threshold)` arg + `gaze.ner_threshold` / `GAZE_NER_THRESHOLD` (override policy `[ner]` threshold, 0.0–1.0 inclusive; per-call wins over config; null = upstream policy default) |
| `--rulepack-bundled` | `gaze.rulepacks` / `GAZE_RULEPACKS` |
| `--rulepack-path` | `gaze.rulepack_paths` / `GAZE_RULEPACK_PATHS` |
| `--safety-net` | `gaze.safety_net` / `GAZE_SAFETY_NET` |
| `--safety-net-backend` | `gaze.safety_net_backend` / `GAZE_SAFETY_NET_BACKEND` (v0.8.x; `openai-filter` \| `kiji-distilbert`) |
| `--kiji-backend` | `gaze.kiji_backend` / `GAZE_KIJI_BACKEND` (v0.9; `subprocess` \| `ort`) |
| `--kiji-distilbert-precision` | `gaze.kiji_distilbert_precision` / `GAZE_KIJI_DISTILBERT_PRECISION` (v0.9; `fp32` \| `int8`) |
| `--kiji-distilbert-command` | `gaze.kiji_distilbert_command` / `GAZE_KIJI_DISTILBERT_COMMAND` (v0.8.x) |
| `--kiji-distilbert-model-dir` | `gaze.kiji_distilbert_model_dir` / `GAZE_KIJI_DISTILBERT_MODEL_DIR` (v0.8.x) |
| `--openai-filter-device` | `gaze.safety_net_device` / `GAZE_SAFETY_NET_DEVICE` |
| `--openai-filter-command` | `gaze.openai_filter_command` / `GAZE_OPENAI_FILTER_COMMAND` |
| `--openai-filter-checkpoint` | `gaze.openai_filter_checkpoint` / `GAZE_OPENAI_FILTER_CHECKPOINT` |
| `--openai-filter-operating-point` | `gaze.openai_filter_operating_point` / `GAZE_OPENAI_FILTER_OPERATING_POINT` |
| `--safety-net-timeout-ms` | `gaze.safety_net_timeout_ms` / `GAZE_SAFETY_NET_TIMEOUT_MS` |
| `--safety-net-input-limit-bytes` | `gaze.safety_net_input_limit_bytes` / `GAZE_SAFETY_NET_INPUT_LIMIT_BYTES` |
| `--safety-net-mode` | `gaze.safety_net_mode` / `GAZE_SAFETY_NET_MODE` (`strict` \| `tolerant` \| `redact` \| `resolve`; upstream default flipped `strict`→`resolve` in v0.8.1) |
| `--safety-net-fallback` | `gaze.safety_net_fallback` / `GAZE_SAFETY_NET_FALLBACK` (v0.8.x; `strict` \| `tolerant` \| `redact`; default `redact`) |

## Restore Flags

| Upstream flag | Laravel surface |
|---|---|
| `--format=json` | Always set by `Gaze::restore()` |
| `--max-bytes` | `gaze.max_bytes` / `GAZE_MAX_BYTES` |
| `--restore-mode` | `gaze.restore_mode` / `GAZE_RESTORE_MODE` |

## Exception Variants

| Upstream variant | Laravel exception |
|---|---|
| `StdinParse` | `GazeStdinParseException` |
| `EmptyInput` | `GazeEmptyInputException` |
| `InputTooLarge` | `GazeInputTooLargeException` |
| `InvalidEncoding` | `GazeInvalidEncodingException` |
| `PolicyConfig` | `GazePolicyConfigException` or `GazePolicyConfigDetailException` when `detail` exists; `detail()` accessor exposes the upstream sidecar |
| `PolicySchemaUnsupported` | `GazePolicySchemaUnsupportedException`; `found()` + `supported()` accessors expose the typed envelope fields |
| `SafetyNetConfig` | `GazeSafetyNetConfigException` |
| `SafetyNet` | `GazeSafetyNetFailureException` |
| `SafetyNetArtifactMissing` | `GazeSafetyNetArtifactMissingException`; `backend()` + `path()` accessors expose the typed envelope sidecars. Axis-1 fail-closed (exit 2) when a backend's pinned artifact (e.g. `SHA256SUMS` for the Kiji DistilBERT backend) is absent. |
| `AuditPurgeIso8601` | `GazeAuditPurgeIso8601Exception` |
| `UnknownToken` | `GazeUnknownTokenException` |
| `UnsupportedSessionScope` | `GazeUnsupportedSessionScopeException` |
| `InvalidSignature` | `GazeInvalidSignatureException` |
| `InvalidBlobVersion` | `GazeInvalidBlobVersionException` |
| `BlobExpired` | `GazeBlobExpiredException` |
| `Pipeline` | `GazePipelineException` |
| `Io` | `GazeIoException` |
| `SigPipe` | `GazeSigPipeException` |
| `PolicyOpen` | `GazePolicyOpenException` |

## Coverage by locale (v0.8.0)

Upstream v0.8.0 introduces 10 locale-gated entities across the new
`locale-{br,fr,nl,in,uk}` packs plus extensions to the existing US/UK
packs. All entities are additive — existing deployments see no
behaviour change unless `gaze.locale` / `GAZE_LOCALE` is set to a
matching BCP47 locale.

| Entity | Locale | ValidatorKind | Tier |
|---|---|---|---|
| Aadhaar | IN | `AadhaarVerhoeff` | 2 (safe_default) |
| NIR | FR | `FrNirMod97` | 2 (safe_default) |
| Steuer-ID | DE | `DeSteuerIdMod1110` | 2 (safe_default) |
| BSN | NL | `BsnMod11` | 2 (safe_default) |
| CPF | BR | `CpfMod11` | 2 (safe_default) |
| CNPJ | BR | `CnpjMod11` | 2 (safe_default) |
| NHS number | UK | `UkNhsMod11` | 2 (safe_default) |
| US SSN | US | `None` (cue-gated) | 3 (locale_gated) |
| UK NINO | UK | `None` (cue-gated) | 3 (locale_gated) |
| Indian PAN | IN | `None` (cue-gated) | 3 (locale_gated) |

The Laravel adapter forwards `--locale=<bcp47>` via `gaze.locale` /
`GAZE_LOCALE`; no code change is needed to opt in.

## Audit row columns (v0.8.0)

Upstream v0.8.0 adds two columns to `gaze audit query` JSON output:

| Column | Notes |
|---|---|
| `recognizer_id` | Stable string identifier for the recognizer that produced a span. |
| `recognizer_version_id` | `<recognizer_id>_v<N>` suffix; bumps on recognizer behaviour changes for replay-stability. |

`Audit\QueryBuilder::parseRows()` returns the rows as `array<string,
mixed>` and does not strip unknown fields — both columns flow through
verbatim. Adopters index by string key (`$row['recognizer_version_id']`).
A typed `AuditRow` DTO is tracked as a future ergonomics nicety, not a
blocker.

## Proxy (v0.8.1)

The upstream `gaze proxy` daemon (v0.8.0, opt-in `--features proxy` build)
is wrapped by six Artisan commands. See [`docs/proxy.md`](../how-to/proxy-daemon.md) for
the adopter quickstart, security notes, and the doctor probe.

| Upstream subcommand | Artisan surface |
|---|---|
| `gaze proxy serve` | `php artisan gaze:proxy:serve` |
| `gaze proxy start` | `php artisan gaze:proxy:start` |
| `gaze proxy stop` | `php artisan gaze:proxy:stop` |
| `gaze proxy restart` | `php artisan gaze:proxy:restart` |
| `gaze proxy status` | `php artisan gaze:proxy:status` |
| `gaze proxy logs` | `php artisan gaze:proxy:logs` |
| `gaze proxy install-launchd` | not wrapped — upstream stub in v0.8.0 (`"reserved for v0.8.x"`) |
| `gaze proxy install-systemd-user` | not wrapped — upstream stub in v0.8.0 (`"reserved for v0.8.x"`) |

| Upstream flag | Laravel surface |
|---|---|
| `--bind` | `gaze.proxy.bind` / `GAZE_PROXY_BIND` (default `127.0.0.1:8787`) |
| `--session-ttl` | `gaze.proxy.session_ttl` / `GAZE_PROXY_SESSION_TTL` (default `30m`) |
| `--rulepack` | `gaze.proxy.rulepack` / `GAZE_PROXY_RULEPACK` (default `core`) |
| `--policy` | `gaze.proxy.policy_path` / `GAZE_PROXY_POLICY_PATH` (default `null`) |
| `--upstream-openai` | `gaze.proxy.upstream.openai` / `GAZE_PROXY_UPSTREAM_OPENAI` |
| `--upstream-anthropic` | `gaze.proxy.upstream.anthropic` / `GAZE_PROXY_UPSTREAM_ANTHROPIC` |
| `--upstream-gemini` | `gaze.proxy.upstream.gemini` / `GAZE_PROXY_UPSTREAM_GEMINI` |
| `--timeout` (stop / restart) | `gaze.proxy.stop_timeout` / `GAZE_PROXY_STOP_TIMEOUT` (default `10s`) |
| `--force` (stop / restart) | `--force` artisan flag |
| `--follow` (logs) | `--follow` artisan flag |
| `--foreground-daemon` (serve) | `--foreground-daemon` artisan flag |

## SafetyNet backend & mode reshape (v0.8.1)

Upstream v0.8.1 introduces a backend selector for the Pass-3 safety net
plus a four-valued `safety_net_mode` enum and a typed fallback. All
surfaces are exposed via `gaze.*` config keys; defaults match upstream
when the key is null.

| Knob | Upstream default | Adapter key | Notes |
|---|---|---|---|
| `--safety-net-backend` | `openai-filter` | `gaze.safety_net_backend` | Set to `kiji-distilbert` to opt into the Tier 2.5 DistilBERT NER subprocess. Wins over the legacy `--safety-net=<kind>` flag when both are set. |
| `--kiji-distilbert-command` | (PATH lookup) | `gaze.kiji_distilbert_command` | Local Kiji binary path. |
| `--kiji-distilbert-model-dir` | (none — fails closed) | `gaze.kiji_distilbert_model_dir` | Pinned-artifact directory. Required when the backend is `kiji-distilbert`. |
| `--safety-net-mode` | `resolve` (v0.8.1; was `strict` ≤ v0.8.0) | `gaze.safety_net_mode` | Valid: `strict` \| `tolerant` \| `redact` \| `resolve`. `tolerant` emits a deprecation warning upstream. |
| `--safety-net-fallback` | `redact` | `gaze.safety_net_fallback` | Engages when `safety_net_mode` is `redact` or `resolve` and the active backend cannot complete. |

`php artisan gaze:doctor` adds a Kiji artifact pre-flight: when
`gaze.safety_net_backend === 'kiji-distilbert'`, doctor asserts the
model dir is set and carries `SHA256SUMS`, `labels.json`, `model.onnx`,
and `tokenizer.json` before the binary fails the first `gaze clean`
with a `SafetyNetArtifactMissing` envelope.

## Daemon (v0.11.0)

Upstream `gaze daemon` is a long-lived JSONL stdio runtime. The adapter
exposes it via the `Gaze::daemon()` Facade chain, a flat config block,
and TWO artisan commands. See [docs/daemon.md](../how-to/daemon.md) for the
adopter quickstart.

The upstream binary pin is now **v0.11.1**. The v0.9.1 → v0.11.1 hardening
(NER fail-closed, byte-exact restore, strict manifest-restore) is all
passthrough — no new daemon flag — see [Upstream v0.9.1 → v0.11.1 deltas](#upstream-v091--v0111-deltas).

### Commands

| Upstream command | Laravel surface |
|---|---|
| `gaze daemon --policy=...` (foreground) | `php artisan gaze:daemon:serve` |
| n/a (best-effort PID lookup) | `php artisan gaze:daemon:status` |
| JSONL request `{"session_id","text"}` | `Gaze::daemon()->session($id)->clean($text)` / `Gaze::daemon()->clean($id, $text)` |

`:start`, `:stop`, `:restart`, `:logs` are intentionally NOT shipped —
supervision is OS-owned. Use systemd / Horizon / supervisord primitives.

### Daemon Flags

| Upstream flag | Laravel surface |
|---|---|
| `--policy=` | `gaze.daemon.policy_path` / `GAZE_DAEMON_POLICY_PATH` |
| `--audit-db=` | `gaze.daemon.audit_db_path` / `GAZE_DAEMON_AUDIT_DB_PATH` |
| `--idle-timeout=` | `gaze.daemon.idle_timeout_s` / `GAZE_DAEMON_IDLE_TIMEOUT_S` |
| n/a (adapter-side ceiling) | `gaze.daemon.request_timeout_ms` / `GAZE_DAEMON_REQUEST_TIMEOUT_MS` (default 5000) |
| n/a (adapter spawn override) | `gaze.daemon.binary_path` / `GAZE_DAEMON_BINARY_PATH` |
| n/a (adapter spawn stderr) | `gaze.daemon.stderr_path` / `GAZE_DAEMON_STDERR_PATH` |

Intentionally NOT shipped: `gaze.daemon.events.enabled` (reserved
P1-violation), `gaze.daemon.extra_flags` (P3 velocity signal),
connections-style `gaze.daemon.connections.{name}.*` (additive MINOR
once a second adopter files).

### Errors

`Gaze::daemon()` calls throw the `GazeDaemonException` family. Variants
are exposed via `DaemonErrorVariant` so adopter `match()` ladders react
per-variant. **`default` arm is required** — new wire variants land in
`DaemonErrorVariant::Unknown`.

| Wire variant | Exception subclass | Adapter posture |
|---|---|---|
| `JsonMalformed` | `GazeDaemonException` | Adapter framing bug |
| `Pipeline` | `GazeDaemonException` | Upstream fail-closed |
| `Transport` (adapter) | `GazeDaemonTransportException` | EOF / broken pipe / session id mismatch — fail-closed, no auto-reconnect |
| `Timeout` (adapter) | `GazeDaemonTimeoutException` | Per-request `gaze.daemon.request_timeout_ms` exceeded |
| `Unavailable` (adapter) | `GazeDaemonFeatureUnsupportedException` | Binary missing `daemon` subverb |
| `Unknown` (forward-compat) | `GazeDaemonException` | New upstream variant; doctor logs adopter warning |

## Upstream v0.9.1 → v0.11.1 deltas

Gap analysis for upstream changes landed since the v0.9.0 parity baseline.
Verdicts follow the surface-promotion rule ([NORTH_STAR](../NORTH_STAR.md) §3):
`wrap` = new Laravel surface, `passthrough` = forwarded argv with no new
adopter surface, `defer` = documented non-goal.

| Upstream change | Verdict | Adapter SemVer | Notes |
|---|---|---|---|
| NER fail-closed (#290/#293), byte-exact restore (#295), strict manifest-restore (#262, MCP-only) + binary pin bump | passthrough | PATCH | Detection / restore-determinism hardening upstream; nothing new for the adapter to forward beyond the existing pin. Reinforces reversibility (NORTH_STAR §4), changes no surface. |
| Restore telemetry + audit columns (#261/#270) | **wrap** | MINOR | New opt-in adopter surface — see [Restore telemetry (v0.11.x)](#restore-telemetry-v011x) below. |
| TokenBridge index-search (#327) | **defer** | none | Persists raw PII unencrypted on disk and routes through an MCP chokepoint — both NORTH_STAR non-goals ("no plaintext session state at rest"; MCP lifecycle). See Deferred. |
| `gaze-mcp-bridge` (#330) | **defer** | none | MCP server lifecycle — explicit non-goal. See Deferred. |
| CLI accessibility gate (#287) | internal-only | none | Human-TTY affordance; the adapter always invokes with `--format=json`, so the gate never engages. No surface. |
| `core-extended` rulepack | still-available alias | n/a | `gaze:doctor`'s "Removal target: v0.10.0" line was **stale** — upstream never removed the pack. It still soft-aliases through v0.11.1; documented as available, not removed. See [upgrading.md](../how-to/upgrading.md). |
| `gaze-document` split (#279) | already-covered | none | OCR / document pipeline stays a deferred non-goal. See Deferred. |

## Restore telemetry (v0.11.x)

Upstream's restore-telemetry + audit-column work (#261/#270) is **wrapped**
as an opt-in adapter surface. Off by default (null = upstream default,
NORTH_STAR §6).

| Surface | Detail |
|---|---|
| Config / env | `gaze.restore_telemetry` / `GAZE_RESTORE_TELEMETRY` — default `null` (off) |
| `Gaze::restore()` | When enabled, forwards `--telemetry --audit-db=<gaze.audit_db_path>` |
| `CertaMesh\Gaze\Audit\QueryBuilder::onlyRestoreEvents()` | Forwards `--restore-events` to scope an audit query to restore rows |
| `--policy` restore alias | Redundant with the already-forwarded `--restore-mode`; **document-only, NO new Laravel surface** |

Six new audit columns surface through `Audit\QueryBuilder`, indexed by
string key like the v0.8.0 recognizer columns:

| Column | Notes |
|---|---|
| `restore_policy` | Restore policy in effect for the row. |
| `restore_decision` | Per-row restore decision. |
| `restore_unknown_token_count` | Count of tokens with no mapping in the session blob. |
| `restore_manifest_bypass_count` | Manifest-bypass count. **Always `0`** through the stock gaze CLI (see caveat). |
| `restore_fresh_pii_count` | Fresh-PII count. **Always `0`** through the stock gaze CLI (see caveat). |
| `restore_phase_mask` | Bitmask of restore phases that executed. |

> **Caveat —** `restore_fresh_pii_count` and `restore_manifest_bypass_count`
> are ALWAYS `0` through the stock gaze CLI — gaze-cli's `run_restore` never
> enables the Phase-B DLP builder. This surface ships for
> **restore-decision / unknown-token audit trails, NOT outbound-DLP fresh-PII
> detection.** Do NOT advertise the DLP use-case.

## Deferred

| Upstream surface | Reason |
|---|---|
| Per-detection byte spans (`start` / `end`) on `gaze clean --format=json` entries | **Upstream feature request.** As of the v0.11.1 pin, clean `--format=json` `entries[]` keys are exactly `{class, raw, token, family}` — there are **no byte offsets**. Computing span positions in PHP is a NORTH_STAR non-goal (it would re-derive detection geometry outside upstream). Blocked on upstream adding per-detection byte spans (start/end) to the clean `--format=json` contract; until then `Gaze::mask()` ships on the collision-safe token map instead. A `length()` / offset accessor on `Entry`/`GazeSession` lands as an additive MINOR once upstream emits the spans. |
| `--context-json` | P1 design item; needs PHP API design before exposure. |
| `gaze mcp install --client=<name>` / `gaze mcp doctor` / `gaze mcp serve` | Opt-in `mcp` feature in upstream v0.7.0; needs `php artisan gaze:mcp:*` artisan surface design. Tracked separately. |
| `gaze-mcp-bridge` (#330) | MCP server lifecycle — explicit NORTH_STAR non-goal. Not a Laravel idiom; lives upstream. Tracked with the other `gaze mcp *` surfaces above. |
| TokenBridge index-search (#327) | Persists raw PII **unencrypted on disk** and routes through an MCP chokepoint — both NORTH_STAR non-goals ("no plaintext session state at rest"; MCP lifecycle). Not wrapped. |
| `gaze document clean <input> --out <dir>` | Opt-in `document` feature in upstream v0.7.1 (Tesseract + pdfium); needs `Gaze::document()` facade or `php artisan gaze:document:clean` design. The v0.11.x `gaze-document` split (#279) keeps OCR a non-goal — still deferred, not re-scoped. Tracked separately. |
| `Ipv4Parse` / `Ipv6Parse` / `EthEip55` validator kinds, `eth.address` in published policy | Upstream v0.7.0 additions. Tracked for v0.8.x adapter release. |
| `gaze proxy install-launchd` / `install-systemd-user` | Upstream stubs the launchd / systemd integrations in v0.8.0 (return `"reserved for v0.8.x"`). Adapter will ship `php artisan gaze:proxy:install` once upstream implements them. |
