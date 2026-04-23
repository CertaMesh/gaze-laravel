<?php

declare(strict_types=1);

return [
    /*
     * Absolute path or executable name for the gaze binary.
     * Defaults to the auto-downloaded copy in vendor/bin/gaze when present,
     * otherwise falls back to the first "gaze" on $PATH.
     */
    'binary' => env('GAZE_BINARY', 'gaze'),

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
];
