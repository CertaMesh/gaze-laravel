# Upstream Coverage

Living parity checklist for upstream `EmpireTwo/gaze` v0.8.0.

## Commands

| Upstream command | Laravel surface |
|---|---|
| `gaze clean` | `Naoray\GazeLaravel\Gaze::clean()` |
| `gaze restore` | `Naoray\GazeLaravel\Gaze::restore()` |
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
| `--rulepack-bundled` | `gaze.rulepacks` / `GAZE_RULEPACKS` |
| `--rulepack-path` | `gaze.rulepack_paths` / `GAZE_RULEPACK_PATHS` |
| `--safety-net` | `gaze.safety_net` / `GAZE_SAFETY_NET` |
| `--openai-filter-device` | `gaze.safety_net_device` / `GAZE_SAFETY_NET_DEVICE` |
| `--openai-filter-command` | `gaze.openai_filter_command` / `GAZE_OPENAI_FILTER_COMMAND` |
| `--openai-filter-checkpoint` | `gaze.openai_filter_checkpoint` / `GAZE_OPENAI_FILTER_CHECKPOINT` |
| `--openai-filter-operating-point` | `gaze.openai_filter_operating_point` / `GAZE_OPENAI_FILTER_OPERATING_POINT` |
| `--safety-net-timeout-ms` | `gaze.safety_net_timeout_ms` / `GAZE_SAFETY_NET_TIMEOUT_MS` |
| `--safety-net-input-limit-bytes` | `gaze.safety_net_input_limit_bytes` / `GAZE_SAFETY_NET_INPUT_LIMIT_BYTES` |
| `--safety-net-mode` | `gaze.safety_net_mode` / `GAZE_SAFETY_NET_MODE` |

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
is wrapped by six Artisan commands. See [`docs/proxy.md`](./proxy.md) for
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

## Deferred

| Upstream surface | Reason |
|---|---|
| `--context-json` | P1 design item; needs PHP API design before exposure. |
| `gaze mcp install --client=<name>` / `gaze mcp doctor` / `gaze mcp serve` | Opt-in `mcp` feature in upstream v0.7.0; needs `php artisan gaze:mcp:*` artisan surface design. Tracked separately. |
| `gaze document clean <input> --out <dir>` | Opt-in `document` feature in upstream v0.7.1 (Tesseract + pdfium); needs `Gaze::document()` facade or `php artisan gaze:document:clean` design. Tracked separately. |
| `Ipv4Parse` / `Ipv6Parse` / `EthEip55` validator kinds, `eth.address` in published policy | Upstream v0.7.0 additions. Tracked for v0.8.x adapter release. |
| `gaze proxy install-launchd` / `install-systemd-user` | Upstream stubs the launchd / systemd integrations in v0.8.0 (return `"reserved for v0.8.x"`). Adapter will ship `php artisan gaze:proxy:install` once upstream implements them. |
