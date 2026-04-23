<?php

declare(strict_types=1);

return [
    /*
     * Absolute path or executable name for the ghostwriter binary.
     * Defaults to the auto-downloaded copy in vendor/bin/ghostwriter when present,
     * otherwise falls back to the first "ghostwriter" on $PATH.
     */
    'binary' => env('GAZE_BINARY'),

    /*
     * Hard ceiling on any single ghostwriter invocation. A hung process must be
     * killed rather than tying up a worker.
     */
    'timeout_seconds' => (int) env('GAZE_TIMEOUT', 30),

    /*
     * When true (default), any ghostwriter failure raises a GazeException and
     * the caller must treat that as "no LLM response produced". When false,
     * Gaze::sanitize / Gaze::restore return a fallback DTO carrying the
     * ORIGINAL (unsanitized) text with a loud warning marker so dev workflows
     * keep moving even when the binary is unavailable. Half-anonymized output
     * is worse than no output. DO NOT set to false in production.
     */
    'fail_closed' => filter_var(env('GAZE_FAIL_CLOSED', true), FILTER_VALIDATE_BOOL),

    /*
     * Optional dedicated base64-encoded 32-byte key for session-blob encryption.
     * When unset, EncryptedBlob falls back to Laravel's default Crypt facade
     * (keyed on APP_KEY). When set, the key MUST be valid or boot fails loudly.
     */
    'blob_encryption_key' => env('GAZE_ENCRYPTION_KEY'),
];
