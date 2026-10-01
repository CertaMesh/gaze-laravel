# Upstream Coverage

Living parity checklist for upstream `CertaMesh/gaze` v0.15.1.

> Adopter usage: [docs/safety-net.md](../how-to/safety-net.md). Why surfaces land here vs. defer: [docs/NORTH_STAR.md](../NORTH_STAR.md) (surface promotion rule). GDPR posture for these surfaces (pseudonymization, storage limitation, erasure): [docs/explanation/gdpr.md](../explanation/gdpr.md) — adopter guidance, not legal advice.

## Commands

| Upstream command | Laravel surface |
|---|---|
| `gaze clean` | `CertaMesh\Gaze\Gaze::clean()` |
| `gaze clean` (one-way output reshape) | `CertaMesh\Gaze\Gaze::mask()` — redacts the clean inventory into masked labels (`[Class]` default, or a `callable(Entry): string`). NON-reversible: no session blob, no `restore()` counterpart. Adds no detection — reshapes `clean()`'s tokens only. |
| `gaze restore` | `CertaMesh\Gaze\Gaze::restore()` |
| `gaze audit query` | `Gaze::audit()->query()` — fluent builder covering all 11 upstream filter flags (see [Audit query/export filters](#audit-queryexport-filters-v011x)) |
| `gaze audit export` | `Gaze::audit()->query()->…->export(?string $output, string $format = 'jsonl')` — reuses the query builder's filter state (upstream applies the identical filter flags to both subcommands) |
| `gaze audit purge` | `Gaze::audit()->purge()` + `php artisan gaze:audit:purge` (scheduler-friendly; `--before` ISO 8601/relative, `--audit-db`, `--dry-run`, `--force`) |
| `gaze audit safety-net query` | `Gaze::audit()->safetyNetQuery()` — flattened name; `query` is upstream's only `safety-net` subcommand |

## Clean Flags

| Upstream flag | Laravel surface |
|---|---|
| `--policy` | `gaze.policy_path` / `GAZE_POLICY_PATH` |
| `--format=json` | Always set by `Gaze::clean()` |
| `--max-bytes` | `gaze.max_bytes` / `GAZE_MAX_BYTES` |
| `--session-ttl` | `gaze.session_ttl_seconds` / `GAZE_SESSION_TTL` |
| `--session-scope` | `gaze.session_scope` / `GAZE_SESSION_SCOPE` |
| `--audit-db` | `gaze.audit_db_path` / `GAZE_AUDIT_DB_PATH` |
| `--locale` | `gaze.locale` / `GAZE_LOCALE` — passed verbatim. Upstream accepts a **comma-separated, priority-ordered fallback chain** (`--help`: "Active locale fallback chain, comma separated and priority ordered"), so `GAZE_LOCALE=de-DE,en` works today; a single BCP47 value is just a chain of one. |
| `--ner-model-dir` (runtime) | **Not exposed.** Runtime override of policy `[ner].model_dir` on `gaze clean`. Deferred — the adapter only sets `model_dir` at install time via `gaze:install:ner` writing `policy.toml`. See [Deferred](#deferred). |
| `--ner-locale` (runtime) | **Not exposed.** Runtime override of policy `[ner].locale` on `gaze clean`. Deferred — install-time only via `gaze:install:ner --locale`. See [Deferred](#deferred). |
| `--ner-threshold` | per-call `Gaze::clean($text, $threshold)` arg + `gaze.ner_threshold` / `GAZE_NER_THRESHOLD` (override policy `[ner]` threshold, 0.0–1.0 inclusive; per-call wins over config; null = upstream policy default) |
| `--rulepack-bundled` | `gaze.rulepacks` / `GAZE_RULEPACKS` |
| `--rulepack-path` | `gaze.rulepack_paths` / `GAZE_RULEPACK_PATHS` |
| `--safety-net` | `gaze.safety_net` / `GAZE_SAFETY_NET` |
| `--safety-net-backend` | `gaze.safety_net_backend` / `GAZE_SAFETY_NET_BACKEND` (v0.8.x; `openai-filter` \| `nym` since gaze 0.15.0). Forwarded only while `gaze.safety_net` is enabled. `kiji-distilbert` was removed upstream in gaze 0.15.0 — the adapter fails closed before spawning (`GazeSafetyNetConfigException`). |
| `--safety-net-registry` | **Not exposed.** v0.9.0 locale-aware Pass-3 registry dispatch (boolean). Deferred — see [Safety-net registry (v0.9.0)](#safety-net-registry-v090). |
| `--safety-net-add` | **Not exposed.** Repeatable registry backend add (`openai-filter` \| `nym`; `kiji-distilbert` until gaze 0.15.0). Deferred — see [Safety-net registry (v0.9.0)](#safety-net-registry-v090). |
| `--opf-locales` | **Not exposed.** Locale list for the OPF registry entry. Deferred — see [Safety-net registry (v0.9.0)](#safety-net-registry-v090). |
| `--kiji-distilbert-locales` | **Removed upstream in gaze 0.15.0** ([CertaMesh/gaze#612](https://github.com/CertaMesh/gaze/pull/612)). Was the Kiji registry-entry locale list; never exposed on the one-shot path. |
| `--opf-command` / `--opf-checkpoint` | **No surface needed** — upstream `--help` marks these as aliases for `--openai-filter-command` / `--openai-filter-checkpoint` "in registry examples". The adapter forwards the canonical spellings (rows above); the aliases add no capability. |
| `--kiji-backend` | **Removed upstream in gaze 0.15.0** ([CertaMesh/gaze#612](https://github.com/CertaMesh/gaze/pull/612)). `gaze.kiji_backend` / `GAZE_KIJI_BACKEND` removed in adapter v0.14.0; leftover values are ignored and `gaze:doctor` warns. |
| `--kiji-distilbert-precision` | **Removed upstream in gaze 0.15.0** ([CertaMesh/gaze#612](https://github.com/CertaMesh/gaze/pull/612)). `gaze.kiji_distilbert_precision` / `GAZE_KIJI_DISTILBERT_PRECISION` removed in adapter v0.14.0 (ignored, doctor warns). |
| `--kiji-distilbert-command` | **Removed upstream in gaze 0.15.0** ([CertaMesh/gaze#612](https://github.com/CertaMesh/gaze/pull/612)). `gaze.kiji_distilbert_command` / `GAZE_KIJI_DISTILBERT_COMMAND` removed in adapter v0.14.0 (ignored, doctor warns). |
| `--kiji-distilbert-model-dir` | **Removed upstream in gaze 0.15.0** ([CertaMesh/gaze#612](https://github.com/CertaMesh/gaze/pull/612)). `gaze.kiji_distilbert_model_dir` / `GAZE_KIJI_DISTILBERT_MODEL_DIR` removed in adapter v0.14.0 (ignored, doctor warns). |
| `--nym-model-dir` / `--nym-intra-threads` (gaze >= 0.15.0) | **Not exposed.** The binary reads the Nym bundle path from the policy's `[safety_net.nym] model_dir` (the adapter always passes `--policy`), falling back to `GAZE_NYM_MODEL_DIR` in its inherited process environment — see [SafetyNet → Kiji was removed upstream](../how-to/safety-net.md#kiji-was-removed-upstream-in-gaze-0150). First-class Nym config is tracked in [#157](https://github.com/CertaMesh/gaze-laravel/issues/157). |
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
| `SafetyNetConfig` | `GazeSafetyNetConfigException` (exit 3; since gaze 0.15.0 also exit 2 for Nym policy/bundle setup errors) |
| `SafetyNetUsage` (gaze >= 0.15.0) | `GazeSafetyNetUsageException` (exit 2); `detail()` accessor exposes the upstream usage message (e.g. `--safety-net-backend` without exactly one `--safety-net`) |
| `SafetyNet` | `GazeSafetyNetFailureException` |
| `SafetyNetArtifactMissing` | `GazeSafetyNetArtifactMissingException`; `backend()` + `path()` accessors expose the typed envelope sidecars. Axis-1 fail-closed (exit 2) when a backend's pinned artifact (e.g. the Nym bundle weights) is absent. |
| `AuditPurgeIso8601` | `GazeAuditPurgeIso8601Exception` |
| `UnknownToken` | `GazeUnknownTokenException` |
| `UnsupportedSessionScope` (removed upstream in 0.15.0, #618) | `GazeUnsupportedSessionScopeException` — **deprecated**, kept for BC. Only the no-policy clean path ever emitted it; the adapter always passes `--policy`, and an invalid `--session-scope` surfaces as `PolicyConfig` + `detail`. |
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
packs. All entities are additive. At v0.8.0 deployments saw no behaviour
change unless `gaze.locale` / `GAZE_LOCALE` was set to a matching BCP47
locale; since upstream v0.13.0 (#423) the locale chain no longer suppresses
them: every entity in this table, cue-gated Tier 3 ones included, fires
regardless of locale. Under the shipped policy's `tokenize` default every
detected entity is tokenized.

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

The Laravel adapter forwards `--locale=<value>` via `gaze.locale` /
`GAZE_LOCALE`; no code change is needed to opt in. The value is passed
**verbatim**, and upstream parses it as a comma-separated,
priority-ordered fallback chain — so both a single BCP47 hint
(`GAZE_LOCALE=de`) and a chain (`GAZE_LOCALE=de-DE,en`) work today.

## Audit row columns (v0.8.0)

Upstream v0.8.0 adds two columns to the `gaze audit query` output:

| Column | Notes |
|---|---|
| `recognizer_id` | Stable string identifier for the recognizer that produced a span. |
| `recognizer_version_id` | `<recognizer_id>_v<N>` suffix; bumps on recognizer behaviour changes for replay-stability. |

`gaze audit query` prints **TSV**, and `Audit\QueryBuilder::execute()`
returns it as positional rows — `list<list<string>>`, where the FIRST row
is upstream's header line (column names). Rows are NOT keyed by string;
columns are located by matching against the header row. No columns are
stripped — everything upstream prints flows through verbatim. For
string-keyed rows (`$row['recognizer_version_id']`), use
`QueryBuilder::export()`: `gaze audit export` emits JSONL objects keyed by
column name, decoded by `AuditExportResult::rows()` for stdout exports.
A typed `AuditRow` DTO is tracked as a future ergonomics nicety, not a
blocker.

## Audit query/export filters (v0.11.x)

All `gaze audit query` / `gaze audit export` filter flags forward through
the fluent `Audit\QueryBuilder` (pure argv forwarding — no PHP-side
filtering). `--class` is a PHP reserved word as a method name, hence the
`where` prefix, kept consistent across the value filters:

| Upstream flag | Builder method |
|---|---|
| `--class` | `whereClass(string)` |
| `--source` | `whereSource(string)` |
| `--action` | `whereAction(string)` |
| `--document-kind` | `whereDocumentKind(string)` |
| `--from` | `from(CarbonInterface\|string)` (Carbon → ISO 8601 UTC Zulu) |
| `--to` | `to(CarbonInterface\|string)` (same normalisation) |
| `--session` | `whereSession(string)` |
| `--has-ambiguity` | `hasAmbiguity()` |
| `--ambiguity-reason` | `whereAmbiguityReason(string)` |
| `--collision-family` | `whereCollisionFamily(string)` |
| `--collision-variant` | `whereCollisionVariant(string)` |
| `--restore-events` | `onlyRestoreEvents()` |
| `--format` (export only) | `export()` `$format` arg, forwarded verbatim (upstream 0.11.x accepts only `jsonl`) |
| `--output` (export only) | `export()` `$output` arg; null exports to stdout, captured on `AuditExportResult` |

`gaze audit safety-net query` filters forward through
`Audit\SafetyNetQueryBuilder` the same way:

| Upstream flag | Builder method |
|---|---|
| `--leak-kind` | `whereLeakKind(string)` |
| `--raw-label` | `whereRawLabel(string)` |
| `--mapped-class` | `whereMappedClass(string)` |
| `--field-path` | `whereFieldPath(string)` |
| `--from` / `--to` | `from()` / `to()` (Carbon → ISO 8601 UTC Zulu) |

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

Since upstream v0.13.0 the detached child started by `gaze:proxy:start` /
`gaze:proxy:restart` actually applies `--policy`, `--rulepack` and the
`--upstream-*` URLs; before that it ran without a policy and with default
upstreams even though `status` showed the configured values. The
`--foreground-daemon` row is broken on every pin (upstream spells it
`--_foreground-daemon`) — #161.

## SafetyNet backend & mode reshape (v0.8.1)

Upstream v0.8.1 introduces a backend selector for the Pass-3 safety net
plus a four-valued `safety_net_mode` enum and a typed fallback. All
surfaces are exposed via `gaze.*` config keys; defaults match upstream
when the key is null.

| Knob | Upstream default | Adapter key | Notes |
|---|---|---|---|
| `--safety-net-backend` | `openai-filter` | `gaze.safety_net_backend` | Selects the Pass-3 backend; wins over the legacy `--safety-net=<kind>` flag. Forwarded only while `gaze.safety_net` is enabled. The v0.8.1 `kiji-distilbert` value was removed upstream in gaze 0.15.0 (`nym` replaces it). |
| `--kiji-distilbert-command` | — | — | **Removed upstream in gaze 0.15.0** ([CertaMesh/gaze#612](https://github.com/CertaMesh/gaze/pull/612)). |
| `--kiji-distilbert-model-dir` | — | — | **Removed upstream in gaze 0.15.0** ([CertaMesh/gaze#612](https://github.com/CertaMesh/gaze/pull/612)). |
| `--safety-net-mode` | `resolve` (v0.8.1; was `strict` ≤ v0.8.0) | `gaze.safety_net_mode` | Valid: `strict` \| `tolerant` \| `redact` \| `resolve`. `tolerant` emits a deprecation warning upstream. |
| `--safety-net-fallback` | `redact` | `gaze.safety_net_fallback` | Engages when `safety_net_mode` is `redact` or `resolve` and the active backend cannot complete. |

`php artisan gaze:doctor` FAILs when an enabled safety net still selects
`kiji-distilbert` (removed upstream in gaze 0.15.0) and warns about leftover
Kiji config; the former Kiji artifact pre-flight is gone with the backend.

## Safety-net registry (v0.9.0)

Upstream v0.9.0 adds a **locale-aware Pass-3 safety-net registry**: instead
of one global backend, `--safety-net-registry` enables registry dispatch,
`--safety-net-add` registers one backend per use (repeatable), and
`--opf-locales` / `--kiji-distilbert-locales` scope each registry entry to a
locale list. All four flags are present on the pinned binary's
`gaze clean --help` (`--kiji-distilbert-locales` was removed upstream in gaze
0.15.0 with the Kiji backend).

The adapter does **not** expose this family yet — honest status: **deferred**,
not wrapped and not passthrough (no config key or argv reaches these flags).

| Upstream flag | Verdict | Notes |
|---|---|---|
| `--safety-net-registry` | **defer** | Boolean registry-dispatch switch. Single-backend selection (`gaze.safety_net_backend`) covers current adopters. |
| `--safety-net-add` | **defer** | Repeatable — needs a list-shaped config key (`gaze.safety_net_registry.backends`-style), which deserves design rather than an ad-hoc CSV env var. |
| `--opf-locales` | **defer** | Per-entry locale scoping for the OPF backend. Only meaningful once the registry itself is exposed. |
| `--kiji-distilbert-locales` | **removed upstream** | Removed with the Kiji DistilBERT backend in gaze 0.15.0. |
| `--opf-command` / `--opf-checkpoint` | no surface needed | Upstream aliases for the already-wrapped `--openai-filter-command` / `--openai-filter-checkpoint`. |

Wrap trigger: an adopter running multi-locale traffic that needs different
Pass-3 backends per locale. Until then the existing single
`--safety-net-backend` surface plus the `--locale` fallback chain is the
supported path. Tracked in [Deferred](#deferred).

## Daemon (v0.11.0)

Upstream `gaze daemon` is a long-lived JSONL stdio runtime. The adapter
exposes it via the `Gaze::daemon()` Facade chain, a flat config block,
and TWO artisan commands. See [docs/daemon.md](../how-to/daemon.md) for the
adopter quickstart.

The upstream binary pin is now **v0.15.1**. The daemon request/response JSONL
shapes are unchanged since v0.11.x. v0.13.0 added eight daemon flags (#446) —
the rulepack pair is forwarded since adapter v0.14.0 (#158), see
[Upstream v0.12.0 → v0.13.0 deltas](#upstream-v0120--v0130-deltas); v0.15.0
removed the Kiji flags (#612), added `--nym-model-dir` / `--nym-intra-threads`
(not forwarded, #157), and reports failed eviction audit writes on
stderr (#570). Earlier pins: [v0.9.1 → v0.11.1](#upstream-v091--v0111-deltas),
[v0.11.1 → v0.11.2](#upstream-v0111--v0112-deltas),
[v0.11.2 → v0.11.3](#upstream-v0112--v0113-deltas).

### Commands

| Upstream command | Laravel surface |
|---|---|
| `gaze daemon --policy=...` (foreground) | `php artisan gaze:daemon:serve` |
| n/a (best-effort PID lookup) | `php artisan gaze:daemon:status` |
| JSONL request `{"session_id","text"}` | `Gaze::daemon()->session($id)->clean($text)` / `Gaze::daemon()->clean($id, $text)` |

`:start`, `:stop`, `:restart`, `:logs` are intentionally NOT shipped —
supervision is OS-owned. Use systemd / Horizon / supervisord primitives.

### Daemon Flags

Both daemon spawn paths — `gaze:daemon:serve` AND the `Gaze::daemon()`
Facade hot path (`DaemonClient` spawn) — forward **every** flag the
pinned v0.11.1 `gaze daemon --help` surface accepts. Both paths build
their argv from the same assembler (`Daemon\DaemonArgv`), so they cannot
drift. Daemon-specific knobs live under `gaze.daemon.*`; the shared
pipeline flags source the same top-level `gaze.*` keys the one-shot
`Gaze::clean()` path forwards, so a configured pipeline behaves
identically in both runtimes. Flags marked *artisan option* can also be
overridden per-invocation on `gaze:daemon:serve`.

| Upstream flag | Laravel surface | Artisan option |
|---|---|---|
| `--policy=` | `gaze.daemon.policy_path` / `GAZE_DAEMON_POLICY_PATH` | `--policy=` |
| `--audit-db=` | `gaze.daemon.audit_db_path` / `GAZE_DAEMON_AUDIT_DB_PATH` | `--audit-db=` |
| `--idle-timeout=` | `gaze.daemon.idle_timeout_s` / `GAZE_DAEMON_IDLE_TIMEOUT_S` | `--idle-timeout=` |
| `--session-idle-timeout=` | `gaze.daemon.session_idle_timeout_s` / `GAZE_DAEMON_SESSION_IDLE_TIMEOUT_S` | `--session-idle-timeout=` |
| `--session-cap=` | `gaze.daemon.session_cap` / `GAZE_DAEMON_SESSION_CAP` | `--session-cap=` |
| `--locale=` | `gaze.locale` / `GAZE_LOCALE` (shared with one-shot) | `--locale=` |
| `--ner-threshold=` | `gaze.ner_threshold` / `GAZE_NER_THRESHOLD` (shared with one-shot) | `--ner-threshold=` |
| `--ner-model-dir=` | `gaze.daemon.ner_model_dir` / `GAZE_DAEMON_NER_MODEL_DIR` | config-only |
| `--ner-locale=` | `gaze.daemon.ner_locale` / `GAZE_DAEMON_NER_LOCALE` | config-only |
| `--safety-net=` | `gaze.safety_net` / `GAZE_SAFETY_NET` (truthy → `openai-filter`, mirroring one-shot) | config-only |
| `--safety-net-backend=` | `gaze.safety_net_backend` / `GAZE_SAFETY_NET_BACKEND` (only alongside `--safety-net=`; `kiji-distilbert` fails closed before spawning) | config-only |
| `--openai-filter-device=` | `gaze.safety_net_device` / `GAZE_SAFETY_NET_DEVICE` | config-only |
| `--openai-filter-command=` | `gaze.openai_filter_command` / `GAZE_OPENAI_FILTER_COMMAND` | config-only |
| `--openai-filter-checkpoint=` | `gaze.openai_filter_checkpoint` / `GAZE_OPENAI_FILTER_CHECKPOINT` | config-only |
| `--openai-filter-operating-point=` | `gaze.openai_filter_operating_point` / `GAZE_OPENAI_FILTER_OPERATING_POINT` | config-only |
| `--kiji-backend=` | **Removed upstream in gaze 0.15.0** ([CertaMesh/gaze#612](https://github.com/CertaMesh/gaze/pull/612)). `gaze.kiji_backend` no longer forwarded. | — |
| `--kiji-distilbert-command=` | **Removed upstream in gaze 0.15.0** ([CertaMesh/gaze#612](https://github.com/CertaMesh/gaze/pull/612)). `gaze.kiji_distilbert_command` no longer forwarded. | — |
| `--kiji-distilbert-model-dir=` | **Removed upstream in gaze 0.15.0** ([CertaMesh/gaze#612](https://github.com/CertaMesh/gaze/pull/612)). `gaze.kiji_distilbert_model_dir` no longer forwarded. | — |
| `--kiji-distilbert-locales=` | **Removed upstream in gaze 0.15.0** ([CertaMesh/gaze#612](https://github.com/CertaMesh/gaze/pull/612)). `gaze.daemon.kiji_distilbert_locales` / `GAZE_DAEMON_KIJI_DISTILBERT_LOCALES` removed in adapter v0.14.0. | — |
| `--safety-net-timeout-ms=` | `gaze.safety_net_timeout_ms` / `GAZE_SAFETY_NET_TIMEOUT_MS` | config-only |
| `--safety-net-input-limit-bytes=` | `gaze.safety_net_input_limit_bytes` / `GAZE_SAFETY_NET_INPUT_LIMIT_BYTES` | config-only |
| `--safety-net-mode=` | `gaze.safety_net_mode` / `GAZE_SAFETY_NET_MODE` | config-only |
| `--safety-net-fallback=` | `gaze.safety_net_fallback` / `GAZE_SAFETY_NET_FALLBACK` | config-only |
| `--rulepack-bundled=` (repeated, gaze >= 0.13) | `gaze.rulepacks` / `GAZE_RULEPACKS` (shared with one-shot) | config-only |
| `--rulepack-path=` (repeated, gaze >= 0.13) | `gaze.rulepack_paths` / `GAZE_RULEPACK_PATHS` (shared with one-shot) | config-only |
| n/a (adapter-side ceiling) | `gaze.daemon.request_timeout_ms` / `GAZE_DAEMON_REQUEST_TIMEOUT_MS` (default 5000) | — |
| n/a (adapter spawn override) | `gaze.daemon.binary_path` / `GAZE_DAEMON_BINARY_PATH` | — |
| n/a (adapter spawn stderr) | `gaze.daemon.stderr_path` / `GAZE_DAEMON_STDERR_PATH` | — |

Note: the whole `--kiji-*` family was removed upstream in gaze 0.15.0;
neither daemon spawn path forwards any of it, whatever Kiji config is left.

Intentionally NOT shipped: `gaze.daemon.events.enabled` (reserved
P1-violation), `gaze.daemon.extra_flags` (P3 velocity signal),
connections-style `gaze.daemon.connections.{name}.*` (additive MINOR
once a second adopter files).

### Errors

`Gaze::daemon()` calls throw the `GazeDaemonException` family. Variants
are exposed via `DaemonErrorVariant` so adopter `match()` ladders react
per-variant. **`default` arm is required** — new wire variants land in
`DaemonErrorVariant::Unknown`.

Every wire variant the v0.15.1 daemon writes to stdout has its own case,
pinned against upstream `commands/daemon.rs` by
`tests/Contract/DaemonErrorVariantContractTest.php` (#162). The daemon writes
a safety-net failure's own variant as `error`, so those cases carry a
`SafetyNet` prefix. Without it, the safety net's `Timeout` and `Unavailable`
would collide with the adapter-owned cases. All wire variants throw
`GazeDaemonException`. The wire name stays in `raw()['error']`.

| Wire variant | `DaemonErrorVariant` | Exception subclass | Adapter posture |
|---|---|---|---|
| `JsonMalformed` | `JsonMalformed` | `GazeDaemonException` | Adapter framing bug |
| `ProtocolInvalid` | `ProtocolInvalid` | `GazeDaemonException` | Blank `session_id`; caller bug |
| `Pipeline` | `Pipeline` | `GazeDaemonException` | Upstream fail-closed |
| `PipelineInvariant` | `PipelineInvariant` | `GazeDaemonException` | Upstream internal invariant broke; report upstream |
| `SuspectedLeak` | `SafetyNetSuspectedLeak` | `GazeDaemonException` | Safety net flagged a leak under `safety_net_mode=strict` |
| `Timeout` | `SafetyNetTimeout` | `GazeDaemonException` | Safety-net backend exceeded `gaze.safety_net_timeout_ms`, **not** the request ceiling |
| `InputTooLarge` | `SafetyNetInputTooLarge` | `GazeDaemonException` | Input exceeds `gaze.safety_net_input_limit_bytes` |
| `Unavailable`, `WeightsMissing`, `ModelUnavailable`, `ModelIntegrityMismatch` | `SafetyNet` + wire name | `GazeDaemonException` | Safety-net backend or model unusable; fix the install |
| `Runtime`, `InvalidOutput` | `SafetyNet` + wire name | `GazeDaemonException` | Safety-net backend failed at runtime |
| n/a (adapter) | `Transport` | `GazeDaemonTransportException` | EOF / broken pipe / session id mismatch — fail-closed, no auto-reconnect |
| n/a (adapter) | `Timeout` | `GazeDaemonTimeoutException` | Per-request `gaze.daemon.request_timeout_ms` exceeded |
| n/a (adapter) | `Unavailable` | `GazeDaemonFeatureUnsupportedException` | Binary missing `daemon` subverb |
| anything else | `Unknown` (forward-compat) | `GazeDaemonException` | New upstream variant; the raw envelope is kept |

Deliberately unmapped (they land in `Unknown`): `PolicyConfig`,
`SafetyNetConfig`, `PolicyOpen`, `Io` and `CliError` sit in upstream's
`DaemonError::variant()` table, but no request can produce them at v0.15.1.
Where those names do occur, they are startup errors, and so is
`TolerantModeDisabled`. The daemon writes them to stderr as one-shot JSON and
exits before it reads stdin. The client sees EOF
(`GazeDaemonTransportException`), and the JSON line is kept only when
`gaze.daemon.stderr_path` is set. `AuditWriteFailed` is stderr-only.

## Upstream v0.9.1 → v0.11.1 deltas

Gap analysis for upstream changes landed since the v0.9.0 parity baseline.
Verdicts follow the surface-promotion rule ([NORTH_STAR](../NORTH_STAR.md) §3):
`wrap` = new Laravel surface, `passthrough` = forwarded argv with no new
adopter surface, `defer` = documented non-goal.

| Upstream change | Verdict | Adapter SemVer | Notes |
|---|---|---|---|
| NER fail-closed (#290/#293), byte-exact restore (#295), strict manifest-restore (#262, MCP-only) + binary pin bump | passthrough | PATCH | Detection / restore-determinism hardening upstream; nothing new for the adapter to forward beyond the existing pin. Reinforces reversibility (NORTH_STAR §4), changes no surface. |
| Restore telemetry + audit columns (#261/#270) | **wrap** | MINOR | New opt-in adopter surface — see [Restore telemetry (v0.11.x)](#restore-telemetry-v011x) below. |
| Clean `leak_report` (verification signal on `gaze clean --format=json`) | **wrap** | MINOR | The adapter previously dropped this field. Now surfaced as a typed `LeakReport` + `CoverageState` trust state on `GazeSession` — see [Clean leak report & trust state (v0.11.x)](#clean-leak-report--trust-state-v011x) below. |
| TokenBridge index-search (#327) | **defer** | none | Verdict as adjudicated against v0.11.1. The "unencrypted on disk" leg of this rationale was resolved upstream in v0.11.2 (encrypted indexes at rest) — see the re-adjudicated entry in [Deferred](#deferred). |
| `gaze-mcp-bridge` (#330) | **defer** | none | MCP server lifecycle — explicit non-goal. See Deferred. |
| CLI accessibility gate (#287) | internal-only | none | Human-TTY affordance; the adapter always invokes with `--format=json`, so the gate never engages. No surface. |
| `core-extended` rulepack | still-available alias | n/a | `gaze:doctor`'s "Removal target: v0.10.0" line was **stale** — upstream never removed the pack. It still soft-aliases through v0.11.3; documented as available, not removed. See [upgrading.md](../how-to/upgrading.md). |
| `gaze-document` split (#279) | already-covered | none | OCR / document pipeline stays a deferred non-goal. See Deferred. |

## Upstream v0.11.1 → v0.11.2 deltas

Gap analysis for the v0.11.2 pin bump (upstream released 2026-06-23). Same
verdict vocabulary as above.

| Upstream change | Verdict | Adapter SemVer | Notes |
|---|---|---|---|
| New default recognizers: EU VAT IDs, ISO-length-gated IBANs, spaced international E.164 phones | passthrough | PATCH | Detection additions in the default recognizer set — adopters get them purely by taking the pin. No new flag, no new adapter surface. |
| NER loader fix for the Kiji bundle (optional `config.json`, conditional `token_type_ids`) | passthrough | PATCH | Fixes the `kiji-distilbert` backend load path. The existing `gaze.kiji_*` config keys forward unchanged. |
| Proxy PII-surface + email-TLD recognizer hardening | passthrough | PATCH | Correctness fixes inside the binary; nothing to forward. |
| `gaze setup` (one-command onboarding: NER install + policy + doctor) | **defer** | none | The Laravel onboarding path is already covered by `php artisan gaze:install` / `gaze:install:ner` + `gaze:doctor`, which additionally handle the adapter-side pieces (config publish, binary pin) that upstream `setup` knows nothing about. Delegating those artisans to `gaze setup` internally is a future option, not a gap. |
| TokenBridge: encrypted indexes at rest (ChaCha20-Poly1305, `GAZE_INDEX_KEY`, `os-keychain`), `gaze index ingest --on-residual redact\|strict`, real error detail | **defer** | none | Removes the plaintext-PII blocker from the v0.11.1 adjudication; the surface is now a **promotion candidate** — see the re-adjudicated entry in [Deferred](#deferred). |

## Upstream v0.15.0 → v0.15.1 deltas

Gap analysis for the v0.15.1 pin (upstream released 2026-09-26). The pin
skips 0.13.0–0.15.0 and lands directly on 0.15.1; the four sections below
adjudicate each step. Verified against the real sha256-pinned macOS arm64
binaries of every intermediate release: the `gaze --help` / subcommand-help
snapshots for `restore` and the whole `audit` family are **byte-identical to
v0.12.0**; only `clean --help` changes (0.15.0) and `version.txt` moves. Same
verdict vocabulary as above.

| Upstream change | Verdict | Adapter SemVer | Notes |
|---|---|---|---|
| A payment card with touching digits (CVV/expiry after it, an order/year number before it, normalization-glued digits) is tokenized on the forward path (upstream #658) | passthrough | PATCH | **The reason the pin is 0.15.1, not 0.15.0.** `Karte 4111 1111 1111 1111 123` reaches the model raw on 0.12.0 *and* 0.15.0 through the shipped policy; tokenized on 0.15.1. Pinned by `PublishedPolicyTest` ("closes the leaks the 0.12.0 pin shipped raw"). About 7 % of IBANs now settle to `custom:iban` instead of the family class — the shipped policy tokenizes both, so only token labels and audit rows change. |
| Restore-boundary DLP scans the whole digit run (#658) | n/a | none | Proxy/MCP only; `gaze restore` never enables Phase-B DLP. |
| `gaze proxy` tokenizes safety-net findings instead of refusing; refusals become `422 Refused` / `ProtectionRefused` (#660) | passthrough | none | Reachable only with a Nym policy at `GAZE_PROXY_POLICY_PATH`. The artisan wrappers manage the process and never parse proxy HTTP responses. Docs follow-up: #167. |
| `DirectProxyError` no longer `Copy` (#660); docs batch A (#659) | n/a | none | Rust API / docs only. |

## Upstream v0.14.0 → v0.15.0 deltas

`clean --help` changes: the `kiji-distilbert` safety-net value and every
`--kiji-*` flag are gone, `nym` / `none` are new `--safety-net` values,
`--nym-model-dir` / `--nym-intra-threads` are new, `--policy` documents the
`core` floor, and `--rulepack-bundled` accepts `none`.

| Upstream change | Verdict | Adapter SemVer | Notes |
|---|---|---|---|
| Kiji DistilBERT safety net removed: flags, backend value, env vars, API (#612) | **wrap** (surface removal) | MINOR (BREAKING) | Any `--kiji-*` flag or `--safety-net-backend=kiji-distilbert` now exits 2 with a bare `{"error":"PolicyConfig","exit":2}`. Kiji surface removed and a fail-closed pre-flight added in the "drop the Kiji surface" PR of this train. |
| `--safety-net` repeatable + `none`; `--safety-net-backend` without exactly one `--safety-net` is refused with the new `SafetyNetUsage` error (#636) | passthrough | PATCH | 0.12.0 silently ignored a lone `--safety-net-backend` (net off); 0.15 fails every call. The adapter now forwards the backend only when the net is enabled (Kiji PR) and maps `SafetyNetUsage` to `GazeSafetyNetUsageException` (error-variant PR). |
| CLI error contract: `SafetyNetUsage` added (exit 2); `SafetyNetConfig` also emitted at exit 2 (Nym setup); `UnsupportedSessionScope` removed (#618); `IndexNerModelMissing` (index only); `PolicySchemaUnsupported.supported` reads `"0.1."` | passthrough | PATCH | See [Exception Variants](#exception-variants); fixture re-pinned to v0.15.1. |
| Nym-small safety net: `--safety-net nym`, `--nym-model-dir`, `--nym-intra-threads`, policy `[safety_net] backend = "nym"` (#609/#636); `gaze setup` turns it on by default (#642) | **defer** | none | Compiled into the stock binary (OPF is not). Reachable today through `GAZE_SAFETY_NET=true` + `GAZE_SAFETY_NET_BACKEND=nym` + a `GAZE_NYM_MODEL_DIR` in the worker's real environment, or through a policy `[safety_net]` table. Bundle must be owned by the effective uid, directory mode `0700`. First-class wrap: #157. |
| Policy fall-through warnings on stderr (#641); `gaze setup` policies tokenize by default (#635) | passthrough | MINOR | The shipped policy's `preserve` default triggered the warning for 19 classes (national IDs, `custom:family:government-id`, dates of birth, URLs, …) — a real leak the adapter never surfaced (stderr is discarded on success). Fixed by the "tokenize by default" PR of this train; doctor surfacing tracked in #159. |
| Credential recognizers move from `core` to the opt-in `secrets` pack; `username.field` removed (#607) | passthrough | none | No change versus the 0.12.0 pin: its `core` had no credential recognizers (0.13 added `security_token.anchored`; 0.15 moved it to `secrets`). Opt in with `GAZE_RULEPACKS=core,secrets` — never `secrets` alone, the flag replaces the policy's `bundled` list. Caveat: after a cue word (`Bearer eyJ…`) only the JWT header is tokenized, payload and signature stay raw (upstream recognizer, #175). See [configuration](configuration.md#gazerulepacks). |
| `gaze clean` without `--policy` runs `core` (#618); `--rulepack-path` without a policy dropped custom classes (#545) | passthrough | none | Leak fixes, **not reachable through the adapter**: `Gaze::clean()`, doctor, canary and bench always pass `--policy`, `gaze daemon` requires one, and the proxy without a policy already ran `core`. Fixed in the binary adopters run anyway (matters for direct `vendor/bin/gaze` use). |
| Stale prefix-cache decision could return raw PII (#579) | n/a | none | Leak fix, **not reachable**: the prefix cache is a library opt-in; `gaze-cli` (clean, daemon) and the proxy never enable it. |
| Terminal residual scan after a Resolve+Redact fallback (#584, #586, #591, #599) | passthrough | PATCH | **Reason to upgrade for safety-net users.** A fallback deletion could leave newly detectable raw text behind (v0.8.1–v0.14.0), reachable through `clean` and the daemon whenever a net was on (Kiji shipped in the 0.12.0 stock binary). Named refusals surface as `Pipeline` exit 3 → `GazePipelineException`. |
| The net writes a one-way `[REDACTED:<class>]` marker instead of deleting bytes (#623) | passthrough | PATCH | Markers are not in `entries[]`, so `Gaze::mask()` leaves them alone; `restore` passes them through verbatim. `leak_report` still counts the suspects, so `CoverageState::Suspect` can be a false red — #160. |
| IBAN fixes: candidate stops at registry length (#622); trailing boundary decided in code (#626); settled family stays settled (#619); NBSP-grouped identifiers and national IDs under JSON keys (#647) | passthrough | PATCH | **Reasons to upgrade, reachable through the shipped policy.** IBAN + `BIC:` label, IBAN glued to `BIC`, NBSP-grouped IBAN, `{"bsn":…}` / `{"nhs":…}` were raw on 0.12.0; all tokenized on 0.15.1 (the JSON-key IDs via the tokenize default). Pinned by `PublishedPolicyTest`. |
| Containment precedence, per-character residual coverage, strictest-member family action (#628, #597, #624, #627) | passthrough | PATCH | Token stream changes (one token per entity, e.g. one IBAN token instead of split tokens). `entries` / `detections` count replacements. 0.12.0 session blobs restore on 0.15.1 and vice versa (verified). |
| `schema_version = "0.1"` refused; must be `"0.1.x"` (#576) | passthrough | PATCH | The shipped policy carries no `schema_version`. The adapter docs used to recommend `"0.1"` — corrected to `"0.1.0"`. |
| Custom rulepack paths keep the `core` floor unless `bundled = []` / `--rulepack-bundled=none` (#632) | passthrough | none | Shipped policy declares `bundled = ["core"]`; unchanged. `GAZE_RULEPACKS=none` is now **accepted** (0.12 rejected it with `PolicyConfig`) and runs no bundled pack — a fail-open configuration; documented as such, and `gaze:doctor` warns whenever `gaze.rulepacks` lacks `core`. |
| Audit JSONL export carries the restore telemetry fields (#555) | passthrough | PATCH | `export()` rows gain `restore_*` keys; see [Restore telemetry](#restore-telemetry-v011x). |
| Daemon reports failed eviction audit writes on stderr (#570) | passthrough | none | `{"error":"AuditWriteFailed",…}` lines on the daemon's stderr (`gaze.daemon.stderr_path`); stdout and exit code unchanged. |
| OPF: offsets read as characters (#608), stock `opf` CLI analyses the whole text (#611), verbose stderr no longer aborts (#580) | passthrough | PATCH | Requires an adopter-built `safety-net-openai` binary. A custom `GAZE_OPENAI_FILTER_COMMAND` wrapper must accept `--no-print-color-coded-text --text-file <path>`. |
| Other detection changes: per-span locale fall-through (#614), GB/CA/IE postal (#598), AT/CH postal (#613), `birth_date.cue` (#589), IPv6 (#625, #631), NER once per document (#653), case-insensitive regex exclusions (#567), hyphenated dictionary boundaries (#568) | passthrough | PATCH | Expect more tokens (e.g. `London SW1A 2AA`). |
| Proxy runs configured nets at admission (#585), fails closed after a fallback deletion (#593), proxy fixes (#544, #548, #549, #572, #652, #656) | passthrough | none | No adapter surface; #167. |
| `gaze index` core floor (#620), MCP/bridge/rmcp 2.x (#546, #557, #571, #578, #582, #590, #616), dashboard (#547, #550, #551, #592), document/TokenBridge (#553, #556, #565, #569, #634, #650) | defer | none | Not wrapped; see [Deferred](#deferred), #165, #166. |
| Bench/scorecard, docs, refactors, tests (#594, #601–#606, #615, #621, #630, #633, #643, #645, #648, #649, #651, #654, #655, #657, …) | n/a | none | Upstream internals. |

## Upstream v0.13.0 → v0.14.0 deltas

Every help snapshot is byte-identical to v0.13.0.

| Upstream change | Verdict | Adapter SemVer | Notes |
|---|---|---|---|
| Strict restore no longer fails on identifier-shaped literals (#473); new `trap_shape_count` telemetry + nullable `restore_trap_shape_count` audit column | passthrough | PATCH | `… Kunde_7 ORDER_12345` threw `GazeUnknownTokenException` on 0.13.0 and restores on 0.14.0+. Own- and foreign-session placeholders still fail closed (`CrossSessionIsolationTest`). `QueryBuilder` locates columns by header, so the new TSV column passes through. |
| ORT NER fails closed on missing/malformed/NaN model output (#474) | passthrough | PATCH | A corrupt adapter-installed NER model now fails `clean` with `Pipeline` (→ `GazePipelineException`) instead of silently returning no name spans. |
| Registry builder, build-script removal, document fallbacks, evidence/benchmark harness, dependency bumps, docs (#454–#477) | defer / n/a | none | Rust internals, benchmarks, docs. |

## Upstream v0.12.0 → v0.13.0 deltas

Only `gaze daemon --help` changes (eight new flags); the clean stdout JSON and
the stderr error envelope are unchanged.

| Upstream change | Verdict | Adapter SemVer | Notes |
|---|---|---|---|
| New `core` recognizers: `custom:url`, `custom:security_token`, `ssn.de_cue`, tax-number / driver-licence / national-ID / passport cues, the `custom:family:government-id` collision family | passthrough | PATCH | Detected-but-preserved under the old shipped policy (raw). On 0.13/0.14 the URL span also swallowed emails/IPs inside URLs the 0.12.0 policy had tokenized — skipped by this pin (0.15 #628 re-protects them, and the tokenize default tokenizes the whole URL). |
| `--locale` no longer suppresses bundled format-based identifiers (#423) | passthrough | PATCH | With `GAZE_LOCALE` set, identifiers outside the chain's locales are now detected (e.g. US-format phones under `de-DE`). To exclude a recognizer, disable it in an adopter rulepack instead of relying on locale mismatch. |
| `gaze daemon` gains `--rulepack-bundled`, `--rulepack-path`, `--kiji-distilbert-precision`, and the registry family (#446) | **wrap** (rulepack pair); defer (registry family) | MINOR | `DaemonArgv` now forwards `gaze.rulepacks` / `gaze.rulepack_paths` like the one-shot path (closes #158) — without it, `GAZE_RULEPACKS=core,secrets` protected one-shot cleans but not daemon cleans. Kiji precision is moot after #612. |
| Kiji/OPF artifact owner check uses the effective uid, not the CWD owner (OPF: #422); `gaze setup` model-bundle owner/mode enforcement (#366–#368) | passthrough | none | Runs on every `clean`/daemon call with a net on, recursively: every file and directory owned by the effective uid, directories `0700`, no group/world-writable files, no symlinks. Models must be owned by the user that executes gaze (under PHP-FPM: the pool user). The `[ner]` model directory the adapter installs is **not** owner/mode-checked by `clean` (0.12.0–0.15.1); Nym bundles (0.15) are. |
| Proxy: detached `start`/`restart` apply `--policy`, `--rulepack`, `--upstream-*` (#441; shared policy loader #437); `start` fails with `DaemonExitedEarly` | passthrough | PATCH | Before 0.13 the background child ignored `gaze.proxy.policy_path` / `rulepack` / `upstream.*` even though `status` showed them. Now enforced. |
| Safety-net fixes (residual merge, RESOLVE verification, Kiji LOC/ORG label swap #425) | passthrough | PATCH | `leak_report` shape unchanged. Kiji audit rows written before 0.13 have LOC/ORG `raw_label`/`mapped_class` swapped. |
| ORT NER decoder receives the document text, not its provenance label (#424; upstream audit S07-F1) | passthrough | PATCH | More name spans for short values; shipped policy tokenizes them. |
| Audit `decided_by` names the deciding tier; `structured_containment` value; canonical enum strings | passthrough | PATCH | Values flow through `QueryBuilder`/`export()` unchanged. |
| `FallbackReason` JSON is snake_case | n/a | none | Not on any surface the adapter parses: audit rows already stored snake_case at 0.12.0; clean/daemon JSON do not carry it. |
| MCP strict protection (#452), dashboard (#397), index schema v2 (#432), Rust APIs | defer | none | Not wrapped; #165, #166. |

## Upstream v0.11.3 → v0.12.0 deltas

Gap analysis for the v0.12.0 pin bump (upstream released 2026-07-06). Verified
against the real 0.12.0 macOS arm64 binary (sha256-pinned): every `gaze --help`
and subcommand-help contract snapshot is **byte-identical to v0.11.3** (modulo
the binary name in the usage line) — no new, changed, or removed flag, so there
is **no surface to promote**. Same verdict vocabulary as above.

| Upstream change | Verdict | Adapter SemVer | Notes |
|---|---|---|---|
| `gaze clean` warns on stderr when an uncovered collision-family class would silently leak (upstream #360 / PR #362) | passthrough | PATCH | Fires only on the success path; the adapter reads stderr exclusively on failure (`buildException`), so nothing chokes. Verified: silent against the shipped `resources/policy.toml` (covers `custom:family:payment-card-or-iban` since #151), fires against an uncovered probe policy. Surfacing it via `gaze:doctor`/`gaze:check` is a promotion candidate, not a gap — the shipped policy makes the warning unreachable for default installs. |
| `gaze_assembly::uncovered_collision_family_classes` — new Rust library API (upstream PR #362) | **defer** | none | Rust-embedding surface; the PHP adapter consumes the CLI, not the crates. Revisit if upstream exposes it as a CLI subverb (e.g. `gaze policy lint`). |
| Policy-authoring docs: collision-family contract + `\b`-next-to-symbol pitfall (upstream PRs #362, #363); CI drift gate now `--verify-ack` | passthrough | none | Process/docs hardening; nothing to forward. The `\b` guidance is already applied to the shipped policy (#152). |

## Upstream v0.11.2 → v0.11.3 deltas

Gap analysis for the v0.11.3 pin bump (upstream released 2026-07-03). Verified
against the real 0.11.3 macOS arm64 binary (sha256-pinned): every `gaze --help`
and subcommand-help contract snapshot is **byte-identical to v0.11.2** — no new,
changed, or removed flag, so there is **no surface to promote**. Same verdict
vocabulary as above.

| Upstream change | Verdict | Adapter SemVer | Notes |
|---|---|---|---|
| Supply-chain hygiene: pdfium build-input pin, dead `daemonize` dependency dropped | passthrough | PATCH | Upstream dependency-graph hardening. Nothing crosses the CLI contract; adopters inherit it purely by taking the pin. No flag, no surface. |
| Leak fixes (observer-only safety-net correctness) | passthrough | PATCH | Correctness fixes inside the binary's safety-net path. The `LeakReport` / `LeakSuspect` projection shape is unchanged — the contract enum + round-trip suites pass against the real 0.11.3 binary. |
| `restore` token-ordinal parsing tightened to ASCII digits only | passthrough | PATCH | Hardens `restore` against malformed/adversarial token ordinals. The clean/restore round trip is byte-identical against the real binary (reversibility, NORTH_STAR §4); not adopter-observable through this package's wire shape. |
| Property-test infra + restore cache (upstream internal) | n/a | none | Upstream test/perf internals. No CLI-contract effect, nothing to forward. |

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

Seven audit columns surface through `Audit\QueryBuilder` — positional
TSV columns located via the header row, like the v0.8.0 recognizer columns.
`export()` returns them string-keyed only since upstream v0.15.0 (#555); the
JSONL export of earlier binaries dropped them:

| Column | Notes |
|---|---|
| `restore_policy` | Restore policy in effect for the row. |
| `restore_decision` | Per-row restore decision. |
| `restore_unknown_token_count` | Count of tokens with no mapping in the session blob. |
| `restore_manifest_bypass_count` | Identifier-shaped literals restore let through. Not a DLP signal (see caveat). |
| `restore_fresh_pii_count` | Fresh-PII count. **Always `0`** through the stock gaze CLI (see caveat). |
| `restore_phase_mask` | Bitmask of restore phases that executed. |
| `restore_trap_shape_count` | Upstream v0.14.0 (#473), nullable: every unprefixed identifier-shaped string in the restored text, restored values included. |

> **Caveat —** `restore_fresh_pii_count` is ALWAYS `0` through the stock gaze
> CLI — gaze-cli's `run_restore` never enables the Phase-B DLP builder.
> `restore_manifest_bypass_count` is not a DLP signal either: it counts
> identifier-shaped literals restore passed through (it tracked the unknown-token
> count up to v0.13 and the bypassed trap shapes since v0.14). This surface
> ships for **restore-decision / unknown-token audit trails, NOT outbound-DLP
> fresh-PII detection.** Do NOT advertise the DLP use-case.
>
> **Schema note —** gaze ≥ 0.14 adds `restore_trap_shape_count` to an existing
> audit DB on its first write, and `gaze audit purge` migrates it even with
> `--dry-run`. Rolling back to 0.12.0 against a migrated DB is safe: the older
> binary ignores the extra column.

## Clean leak report & trust state (v0.11.x)

`gaze clean --format=json` always emits a `leak_report` object — the upstream
pipeline's own coverage check. The adapter previously **dropped** it, leaving
callers to infer safety from the detection count. That over-asserts: a high
detection count never proves a span did not bleed through (NER can fire many
times while a real PII value stays uncovered). The report is now **wrapped** as
a typed, metadata-only DTO and a derived trust state on every `GazeSession`.

| Surface | Detail |
|---|---|
| `GazeSession::$leakReport` | `?CertaMesh\Gaze\LeakReport` — the parsed report, or `null` when the binary emits no `leak_report` |
| `CertaMesh\Gaze\LeakReport` | Counts (`suspectCount`, `uncoveredCount`, `partialBleedCount`, `classMismatchCount`, `localeSkippedCount`), a `list<LeakSuspect> $suspects`, optional `$replayHash` |
| `CertaMesh\Gaze\LeakSuspect` | Per-suspect **metadata only**: `safetyNetId`, `rawLabel` (backend category label, never source text), `mappedClass`, `leakKind`, `pipelineClass`, `spanLen`, `fieldPath`, `score` |
| `GazeSession::coverageState(): CoverageState` | `Verified` (green) \| `Unverified` (amber) \| `Suspect` (red) |
| `GazeSession::hasSuspectedLeak(): bool` | `true` only when the safety net actively flagged a span |

**Trust-state semantics** ([why a green count over-asserts](../explanation/security.md#trust-state-a-count-is-not-a-verification)):

| State | When | Meaning |
|---|---|---|
| `Suspect` (red) | `suspect_count > 0` | The observer-only safety net flagged a span that may still carry raw PII. Hardest signal — wins over amber. |
| `Unverified` (amber) | no suspects, but any of `uncovered_count` / `partial_bleed_count` / `class_mismatch_count` / `locale_skipped_count` > 0 — **or `leak_report` absent** | Coverage is partial, or there is no upstream verification to back a green. Never silently promoted to green. |
| `Verified` (green) | no suspects **and** no coverage gaps | Upstream's coverage check passed. Not "N detections" — an actual verification. |

The report is **metadata only**: upstream serialises no source text and no byte
offsets (only `span_len` survives; `raw_label` is the backend's category label).
The adapter doubles down — `LeakReport`/`LeakSuspect` read a strict field
allowlist, so a future or tampered upstream field carrying raw text can never
flow through (enforced by a hostile-fixture test).

> **Caveat —** the `suspect_count` / `suspects` channel is populated by the
> **Pass-3 safety net**. Without a net configured those stay `0` / empty, so
> the strongest reachable state is `Unverified`. Since upstream v0.15.0 the
> stock release binary ships the Nym net (OPF still needs a `safety-net-openai`
> build), so `Suspect` (red) is reachable with `GAZE_SAFETY_NET_BACKEND=nym` —
> but the report keeps counting suspects the default `resolve` mode already
> tokenized (or `redact` replaced with `[REDACTED:<class>]`), so red can be a
> false alarm until #160 lands. The four coverage-gap counts come from the core
> pipeline and are always present.

## Deferred

| Upstream surface | Reason |
|---|---|
| Per-detection byte spans (`start` / `end`) on `gaze clean --format=json` entries | **Upstream feature request.** As of the v0.11.3 pin, clean `--format=json` `entries[]` keys are exactly `{class, raw, token, family}` — there are **no byte offsets**. Computing span positions in PHP is a NORTH_STAR non-goal (it would re-derive detection geometry outside upstream). Blocked on upstream adding per-detection byte spans (start/end) to the clean `--format=json` contract; until then `Gaze::mask()` ships on the collision-safe token map instead. A `length()` / offset accessor on `Entry`/`GazeSession` lands as an additive MINOR once upstream emits the spans. |
| `--context-json` | P1 design item; needs PHP API design before exposure. |
| `gaze mcp install --client=<name>` / `gaze mcp doctor` / `gaze mcp serve` | Opt-in `mcp` feature in upstream v0.7.0; needs `php artisan gaze:mcp:*` artisan surface design. Tracked separately. |
| `gaze-mcp-bridge` (#330) | MCP server lifecycle — explicit NORTH_STAR non-goal. Not a Laravel idiom; lives upstream. Tracked with the other `gaze mcp *` surfaces above. |
| `gaze setup` (v0.11.2 one-command onboarding) | The Laravel path is covered by `php artisan gaze:install` / `gaze:install:ner` + `gaze:doctor`, which also handle the adapter-side pieces (config publish, pinned-binary install) that upstream `setup` does not know about. Delegating the artisans to `gaze setup` internally is a future option; a separate wrap would only duplicate the surface. |
| TokenBridge index-search (`gaze index`, #327) | **Re-adjudicated at the v0.11.2 pin.** The v0.11.1 deferral leaned on two legs: (1) indexes persisted raw PII **unencrypted on disk** — **resolved upstream in v0.11.2** (ChaCha20-Poly1305 per-index encryption at rest, `GAZE_INDEX_KEY`, optional `os-keychain`; plus `gaze index ingest --on-residual redact\|strict` for residual safety-net hits); (2) the search flow routes through an MCP chokepoint, and owner-side gated search over redacted corpora sits outside this package's thin clean/restore gate — **still holds**. Verdict stays **defer** on leg 2 alone, but the surface is now a **promotion candidate**: a wrap would be `php artisan gaze:index:ingest` / `gaze:index:search` artisans plus a `gaze.index_key` / `GAZE_INDEX_KEY` config passthrough (key material handled like `GAZE_ENCRYPTION_KEY`, never logged). Promote once an adopter files a concrete Laravel-side use case. |
| `gaze document clean <input> --out <dir>` | Opt-in `document` feature in upstream v0.7.1 (Tesseract + pdfium); needs `Gaze::document()` facade or `php artisan gaze:document:clean` design. The v0.11.x `gaze-document` split (#279) keeps OCR a non-goal — still deferred, not re-scoped. Tracked separately. |
| `Ipv4Parse` / `Ipv6Parse` / `EthEip55` validator kinds, `eth.address` in published policy | Upstream v0.7.0 additions. Tracked for v0.8.x adapter release. |
| `gaze proxy install-launchd` / `install-systemd-user` | Upstream stubs the launchd / systemd integrations in v0.8.0 (return `"reserved for v0.8.x"`). Adapter will ship `php artisan gaze:proxy:install` once upstream implements them. |
| Nym safety net config surface (`--nym-model-dir`, `--nym-intra-threads`, installer, doctor ownership probe; upstream v0.15.0) | Reachable today via `GAZE_SAFETY_NET_BACKEND=nym` + `GAZE_NYM_MODEL_DIR` in the worker's environment. First-class wrap: #157. |
| Proxy inspection dashboard (`--dashboard*`, upstream v0.13.0, `dashboard` feature not in release binaries) | #165. |
| `gaze clean --ner-model-dir` / `--ner-locale` (runtime NER overrides) | Runtime overrides of policy `[ner].model_dir` / `[ner].locale` — distinct from the **install-time** variants the adapter already owns (`gaze:install:ner --dest --locale` writes them into `policy.toml`). Currently **not exposed**: no config key or per-call arg forwards them. Deferring keeps one source of truth for NER placement (the policy file `gaze:doctor` validates); a per-request model-dir swap has no adopter demand yet. Wrap-later candidate: `gaze.ner_model_dir` / `gaze.ner_locale` config passthrough (additive MINOR) once an adopter needs per-environment model dirs without policy edits. |
| Safety-net registry family (`--safety-net-registry`, `--safety-net-add`, `--opf-locales`, v0.9.0; `--kiji-distilbert-locales` removed upstream in 0.15.0) | Locale-aware Pass-3 registry dispatch — see [Safety-net registry (v0.9.0)](#safety-net-registry-v090) for per-flag verdicts. Not exposed; the single-backend `gaze.safety_net_backend` surface covers current adopters. Wrap once an adopter needs per-locale backend routing (list-shaped config, additive MINOR). |
