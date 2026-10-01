<?php

declare(strict_types=1);

return [
    /*
     * Absolute path or executable name for the gaze binary.
     *
     * Resolution contract (BinaryResolver):
     *   null / unset → auto-discover: prefer vendor/bin/gaze (auto-installed by
     *                  the Composer plugin), then fall back to the first "gaze"
     *                  on $PATH.
     *   non-empty    → used as-is (treated as explicit override). Set to an
     *                  absolute path for production; a bare name like "gaze"
     *                  defeats the vendor/bin fallback and is discouraged.
     */
    'binary' => env('GAZE_BINARY'),

    /*
     * Hard ceiling on any single gaze invocation. A hung process must be
     * killed rather than tying up a worker. Raw env read —
     * GazeOptions::fromConfig coerces to int (default 30).
     */
    'timeout_seconds' => env('GAZE_TIMEOUT', 30),

    /*
     * Path to the detector policy file passed to `gaze clean`.
     */
    'policy_path' => env('GAZE_POLICY_PATH', base_path('policy.toml')),

    /*
     * Optional explicit max-bytes override for the CLI. When unset, the
     * library pre-flight still enforces the v0.3 default ceiling of 10 MB.
     */
    'max_bytes' => env('GAZE_MAX_BYTES'),

    /*
     * Optional session TTL forwarded to the CLI.
     */
    'session_ttl_seconds' => env('GAZE_SESSION_TTL'),

    /*
     * Optional session isolation scope forwarded to `gaze clean`.
     * Supported values are `conversation` and `persistent`. Null omits the
     * flag and uses the policy's `[session] scope`.
     *
     * `ephemeral` is rejected before the binary runs (non-retryable
     * GazePolicyConfigDetailException): gaze clean must return an exported
     * session blob for restore(), and gaze never exports an ephemeral
     * session. A policy `[session] scope = "ephemeral"` fails every clean the
     * same way; `gaze:doctor` warns about it.
     */
    'session_scope' => env('GAZE_SESSION_SCOPE'),

    /*
     * Optional dedicated base64-encoded 32-byte key for session-blob encryption.
     * When unset, EncryptedBlob falls back to Laravel's default Crypt facade
     * (keyed on APP_KEY). When set, the key MUST be valid or boot fails loudly.
     */
    'blob_encryption_key' => env('GAZE_ENCRYPTION_KEY'),

    /*
     * Optional SQLite redaction-log database path.
     *
     * When set:
     *   - `Gaze::clean()` forwards `--audit-db=<path>` so redaction events are
     *     written to this DB.
     *   - `Gaze::audit()->purge()` (and future query/export verbs) read from
     *     this DB.
     *
     * When null, audit verbs throw GazeAuditDbNotConfiguredException at call
     * time (never at boot). `Gaze::clean()` continues to work without audit.
     *
     * Per-call overrides ARE supported: `Gaze::audit($path)->purge()...` wins
     * over this config value. The resolved value is passed verbatim to the
     * binary which creates the file on first write (no Laravel-side
     * file_exists pre-flight).
     */
    'audit_db_path' => env('GAZE_AUDIT_DB_PATH'),

    /*
     * Locale hint forwarded to `gaze clean` as `--locale=<value>`. The value
     * is passed verbatim, and upstream parses it as a comma-separated,
     * priority-ordered BCP47 fallback chain (e.g. `GAZE_LOCALE=de-DE,en-US`);
     * earlier entries win. It REPLACES the policy's `[locale] active` chain, so
     * use full BCP47 tags: a bare `de` does not activate the `de-DE`-bound
     * recognizers (German national phone numbers, postal codes). Null passes
     * no flag and keeps the policy's chain.
     */
    'locale' => env('GAZE_LOCALE'),

    /*
     * Optional global override for the policy `[ner]` detection threshold,
     * forwarded to `gaze clean` as `--ner-threshold=<value>`. Must be between
     * 0.0 and 1.0 inclusive. Null omits the flag and lets upstream apply the
     * policy's own threshold. A per-call `Gaze::clean($text, $threshold)`
     * argument wins over this config value.
     */
    'ner_threshold' => env('GAZE_NER_THRESHOLD'),

    /*
     * Comma-separated list of bundled rulepack names forwarded as `--rulepack-bundled=`
     * flags. Replaces the policy's `[policy.rulepacks] bundled` list, so keep
     * `core` in it (e.g. `GAZE_RULEPACKS=core,secrets` opts into the credential
     * recognizers upstream moved out of `core` in gaze 0.15.0). Forwarded on
     * both the one-shot and the daemon path. DANGER: `none` (accepted since
     * gaze 0.15) disables every bundled pack — cleans then succeed without
     * detecting emails, phones, IBANs or cards. `gaze:doctor` warns whenever
     * the list lacks `core`.
     */
    'rulepacks' => array_filter(explode(',', env('GAZE_RULEPACKS', ''))),

    /*
     * Comma-separated list of filesystem paths to custom rulepack TOML files,
     * forwarded as `--rulepack-path=` flags
     * (e.g. `GAZE_RULEPACK_PATHS=/path/a.toml,/path/b.toml`).
     */
    'rulepack_paths' => array_filter(explode(',', env('GAZE_RULEPACK_PATHS', ''))),

    /*
    |--------------------------------------------------------------------------
    | Safety Net
    |--------------------------------------------------------------------------
    |
    | Nested configuration for the safety-net classifier tier (v0.13+ shape).
    | Values are raw `env()` reads — `GazeOptions::fromConfig()` is the single
    | coercion layer, so no casts belong in this file. Env var names are
    | unchanged from the pre-v0.13 flat keys.
    |
    | The pre-v0.13 flat root keys (`safety_net_mode`,
    | `openai_filter_command`, …) are DEPRECATED but still read as a
    | fallback, so previously published configs keep working. At
    | registration the provider also back-fills the flat keys from this group
    | (and collapses `gaze.safety_net` to the bool enable switch) so legacy
    | `config('gaze.safety_net_*')` readers observe the same values. See
    | UPGRADING.md for the key map.
    |
    | The Kiji DistilBERT group (`kiji.*`, `GAZE_KIJI_*`) is gone: upstream
    | removed that backend in gaze 0.15.0. Leftover values are ignored and
    | `gaze:doctor` warns about them.
    */
    'safety_net' => [
        /*
         * Enable the safety-net classifier. When true, the binary passes
         * `--safety-net=openai-filter` (v0.6.4+ contract).
         */
        'enabled' => env('GAZE_SAFETY_NET', false),

        /*
         * Optional explicit safety-net backend selector, forwarded as
         * `--safety-net-backend=<value>` — ONLY while `enabled` is true, so a
         * disabled net with a leftover backend stays off. It wins over the
         * legacy `--safety-net=<kind>` flag. Valid values:
         *
         *   - `openai-filter` — Tier 2 OpenAI privacy-filter subprocess; needs
         *     a gaze binary built with upstream's `safety-net-openai` feature
         *     (the release binaries are not).
         *   - `nym` — compiled into the release binary. Fetch the bundle with
         *     `gaze setup --safety-net nym` and name its directory in the
         *     policy's `[safety_net.nym] model_dir` (or in `GAZE_NYM_MODEL_DIR`
         *     in the worker's real process environment).
         *
         * `kiji-distilbert` was removed upstream in gaze 0.15.0; the adapter
         * refuses it before spawning. Null omits the flag and lets upstream
         * keep its single-backend default of `openai-filter`.
         */
        'backend' => env('GAZE_SAFETY_NET_BACKEND'),

        /*
         * CUDA/CPU device for the safety-net model (e.g. `cuda:0`, `cpu`).
         * Forwarded as `--openai-filter-device=<value>`. Null omits the flag.
         */
        'device' => env('GAZE_SAFETY_NET_DEVICE'),

        /*
         * Optional safety-net subprocess timeout in milliseconds. Must be
         * positive. Forwarded as `--safety-net-timeout-ms=<value>`. Null lets
         * the binary use its default of 5000 ms.
         */
        'timeout_ms' => env('GAZE_SAFETY_NET_TIMEOUT_MS'),

        /*
         * Optional clean-text size cap, in bytes, for the safety-net
         * subprocess. Must be positive. Forwarded as
         * `--safety-net-input-limit-bytes=<value>`. Null lets the binary use
         * its default of 1048576 bytes.
         */
        'input_limit_bytes' => env('GAZE_SAFETY_NET_INPUT_LIMIT_BYTES'),

        /*
         * Optional suspected-leak handling mode. Valid values are `strict`,
         * `tolerant`, `redact`, and `resolve`. Forwarded as
         * `--safety-net-mode=<value>`. Null lets the binary apply its default,
         * which flipped from `strict` to `resolve` in upstream v0.8.1. Adopters
         * who relied on the legacy strict-as-default behaviour must set
         * `GAZE_SAFETY_NET_MODE=strict` explicitly. `tolerant` emits a
         * deprecation warning upstream.
         */
        'mode' => env('GAZE_SAFETY_NET_MODE'),

        /*
         * Optional fallback when `mode` is `redact` or `resolve` and the
         * active backend cannot complete (timeout, oversized input, etc.).
         * Valid values are `strict`, `tolerant`, and `redact`. Forwarded as
         * `--safety-net-fallback=<value>`. Null lets the binary use its
         * default of `redact`.
         */
        'fallback' => env('GAZE_SAFETY_NET_FALLBACK'),

        /*
         * Tier 2 OpenAI privacy-filter (`opf`) subprocess backend.
         */
        'openai_filter' => [
            /*
             * Optional path to the local `opf` binary used by the safety-net
             * classifier. Forwarded as `--openai-filter-command=<value>`.
             * Null lets the binary use PATH lookup.
             */
            'command' => env('GAZE_OPENAI_FILTER_COMMAND'),

            /*
             * Optional model checkpoint directory for the safety-net
             * classifier. Forwarded as `--openai-filter-checkpoint=<value>`.
             * Null lets the binary use its built-in default.
             */
            'checkpoint' => env('GAZE_OPENAI_FILTER_CHECKPOINT'),

            /*
             * Optional safety-net sensitivity trade-off. Valid values are
             * `high-recall`, `balanced`, and `high-precision`. Forwarded as
             * `--openai-filter-operating-point=<value>`. Null lets the binary
             * use its default.
             */
            'operating_point' => env('GAZE_OPENAI_FILTER_OPERATING_POINT'),
        ],

    ],

    /*
     * Optional restore behavior for unknown tokens. Valid values are `strict`
     * and `tolerant`. Null omits the flag and lets upstream default to `strict`.
     */
    'restore_mode' => env('GAZE_RESTORE_MODE'),

    /*
     * Enable restore-decision telemetry. When truthy, `Gaze::restore()` forwards
     * `--telemetry` (and `--audit-db=<gaze.audit_db_path>` when that path is set)
     * so the binary records restore-decision / unknown-token audit rows. Null or
     * false = upstream default (telemetry off); this surface adds no detection
     * logic — it only forwards the upstream flag.
     *
     * CAVEAT: restore_fresh_pii_count is ALWAYS 0 through the stock gaze CLI,
     * because gaze-cli's run_restore never enables the Phase-B DLP builder, and
     * restore_manifest_bypass_count only counts identifier-shaped literals
     * restore passed through. This surface ships for restore-decision and
     * unknown-token audit trails, NOT for outbound-DLP fresh-PII detection. Do
     * not rely on it for DLP.
     */
    'restore_telemetry' => env('GAZE_RESTORE_TELEMETRY'),

    /*
     * gaze-proxy daemon settings.
     *
     * Wraps the upstream `gaze proxy *` subcommands. Each key forwards as an
     * exact `--flag` to the binary; null/empty omits the flag and lets the
     * binary fall back to its own config file (default `~/.config/gaze/proxy.toml`).
     *
     * The upstream `proxy` subcommand is feature-gated. The GitHub-release
     * binary asset is built WITHOUT `--features proxy`. Adopters that want
     * `php artisan gaze:proxy:*` at runtime must rebuild upstream with:
     *
     *     cargo install gaze-cli --features proxy
     *
     * See `docs/proxy.md` for the full reference.
     */
    'proxy' => [
        /*
         * Loopback bind address. Format: `host:port`. Forwarded as `--bind=`.
         */
        'bind' => env('GAZE_PROXY_BIND', '127.0.0.1:8787'),

        /*
         * Session TTL applied to redaction-session state held by the daemon.
         * Duration string (`30m`, `1h`, `120s`). Forwarded as `--session-ttl=`.
         */
        'session_ttl' => env('GAZE_PROXY_SESSION_TTL', '30m'),

        /*
         * Bundled rulepack name passed to the proxy pipeline. Forwarded as
         * `--rulepack=`. Use `core` (default) unless you publish a custom
         * bundle.
         */
        'rulepack' => env('GAZE_PROXY_RULEPACK', 'core'),

        /*
         * Optional policy.toml path forwarded as `--policy=`. Null lets the
         * binary fall back to its default pipeline (no policy file).
         */
        'policy_path' => env('GAZE_PROXY_POLICY_PATH'),

        /*
         * Upstream provider URLs forwarded as `--upstream-openai=`,
         * `--upstream-anthropic=`, `--upstream-gemini=`. Each key takes a full
         * https:// URL. Adapter ships the canonical defaults so a fresh
         * `php artisan gaze:proxy:start` works without further config.
         */
        'upstream' => [
            'openai' => env('GAZE_PROXY_UPSTREAM_OPENAI', 'https://api.openai.com/'),
            'anthropic' => env('GAZE_PROXY_UPSTREAM_ANTHROPIC', 'https://api.anthropic.com/'),
            'gemini' => env('GAZE_PROXY_UPSTREAM_GEMINI', 'https://generativelanguage.googleapis.com/'),
        ],

        /*
         * Graceful-shutdown timeout for `gaze:proxy:stop` / `:restart`.
         * Duration string (`10s`, `30s`, `1m`). Forwarded as `--timeout=`.
         */
        'stop_timeout' => env('GAZE_PROXY_STOP_TIMEOUT', '10s'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Daemon
    |--------------------------------------------------------------------------
    |
    | Flat configuration for the long-lived `gaze daemon` JSONL stdio runtime.
    | All keys default to null so the upstream binary applies its own
    | defaults; populating a key forwards the value as the matching flag.
    |
    | `gaze:daemon:serve` also forwards the shared pipeline flags —
    | `--locale`, `--ner-threshold`, and the full safety-net / OPF family —
    | sourced from the SAME top-level `gaze.*` keys the one-shot
    | `Gaze::clean()` path uses, so a configured pipeline behaves
    | identically in both runtimes. Only daemon-specific knobs live here.
    |
    | The upstream `daemon` subcommand may be feature-gated. When the binary
    | lacks the feature, `php artisan gaze:daemon:serve` will fail at first
    | invocation. Doctor's `--deep` probe pre-flights this and surfaces:
    |
    |     cargo install gaze-cli --features daemon
    |
    | See `docs/daemon.md` for the full reference.
    |
    | Connections-style configuration (`gaze.daemon.connections.{name}.*`) is
    | intentionally not shipped — it's an additive MINOR promotion once a
    | second adopter files for multi-daemon orchestration.
    */
    'daemon' => [
        /*
         * Policy TOML path forwarded as `--policy=`. Null skips the flag and
         * lets the binary fall back to its default pipeline (no policy).
         *
         * Doctor's daemon section is skipped when this key is null — that is
         * the opt-in signal that the adopter intends to use daemon mode.
         */
        'policy_path' => env('GAZE_DAEMON_POLICY_PATH'),

        /*
         * Audit DB path forwarded as `--audit-db=`. Daemon-emitted rows
         * stamp `provenance_stage = "daemon"`. Null leaves audit disabled.
         */
        'audit_db_path' => env('GAZE_DAEMON_AUDIT_DB_PATH'),

        /*
         * Per-request timeout the adapter applies to each JSONL round-trip.
         * Integer milliseconds. Default 5000ms. Cold first request may want
         * a higher value when the upstream pipeline loads a safety-net model
         * (e.g. Nym's ONNX Runtime init).
         *
         * NOTE: this is an adapter-side ceiling, not an upstream flag.
         */
        'request_timeout_ms' => env('GAZE_DAEMON_REQUEST_TIMEOUT_MS', 5000),

        /*
         * Daemon idle timeout forwarded as `--idle-timeout=`. Integer
         * seconds. Null lets the binary apply its default.
         */
        'idle_timeout_s' => env('GAZE_DAEMON_IDLE_TIMEOUT_S'),

        /*
         * Per-session idle eviction window forwarded as
         * `--session-idle-timeout=`. Integer seconds. Null lets the binary
         * apply its default (3600 s in v0.11.x). Evicted sessions write an
         * audit row with `source = "daemon.session_eviction"`.
         */
        'session_idle_timeout_s' => env('GAZE_DAEMON_SESSION_IDLE_TIMEOUT_S'),

        /*
         * Maximum live sessions before LRU eviction, forwarded as
         * `--session-cap=`. Null lets the binary apply its default
         * (1000 in v0.11.x).
         */
        'session_cap' => env('GAZE_DAEMON_SESSION_CAP'),

        /*
         * Optional policy `[ner].model_dir` override forwarded as
         * `--ner-model-dir=`. Null omits the flag and keeps the policy's
         * own model directory.
         */
        'ner_model_dir' => env('GAZE_DAEMON_NER_MODEL_DIR'),

        /*
         * Optional policy `[ner].locale` override forwarded as
         * `--ner-locale=`. Null omits the flag and keeps the policy's
         * own NER locale.
         */
        'ner_locale' => env('GAZE_DAEMON_NER_LOCALE'),

        /*
         * Override path for the `gaze` binary used by `gaze:daemon:serve`.
         * Falls back to `BinaryResolver` resolution when null.
         */
        'binary_path' => env('GAZE_DAEMON_BINARY_PATH'),

        /*
         * Optional file path the daemon stderr is appended to when invoked
         * via `gaze:daemon:serve`. Null leaves stderr inherited from the
         * supervisor (systemd / Horizon / supervisord). Spec mandates stderr
         * is the log surface — no `--log-file` flag exists upstream.
         */
        'stderr_path' => env('GAZE_DAEMON_STDERR_PATH'),
    ],
];
