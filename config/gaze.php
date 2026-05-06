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
     * killed rather than tying up a worker.
     */
    'timeout_seconds' => (int) env('GAZE_TIMEOUT', 30),

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
     * BCP47 locale hint forwarded to `gaze clean` as `--locale=<value>`
     * (e.g. `en`, `de`). Null passes no flag.
     */
    'locale' => env('GAZE_LOCALE'),

    /*
     * Comma-separated list of bundled rulepack names forwarded as `--rulepack-bundled=`
     * flags (e.g. `GAZE_RULEPACKS=names,emails`).
     */
    'rulepacks' => array_filter(explode(',', env('GAZE_RULEPACKS', ''))),

    /*
     * Comma-separated list of filesystem paths to custom rulepack TOML files,
     * forwarded as `--rulepack-path=` flags
     * (e.g. `GAZE_RULEPACK_PATHS=/path/a.toml,/path/b.toml`).
     */
    'rulepack_paths' => array_filter(explode(',', env('GAZE_RULEPACK_PATHS', ''))),

    /*
     * Enable the safety-net classifier (`--safety-net`). When true, the binary
     * runs a lightweight secondary pass to catch tokens the primary policy missed.
     */
    'safety_net' => (bool) env('GAZE_SAFETY_NET', false),

    /*
     * CUDA/CPU device for the safety-net model (e.g. `cuda:0`, `cpu`). Forwarded
     * as `--openai-filter-device=<value>`. Null omits the flag.
     */
    'safety_net_device' => env('GAZE_SAFETY_NET_DEVICE'),
];
