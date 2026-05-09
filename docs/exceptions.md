# Exception Reference

All exceptions live under `Naoray\GazeLaravel\Exceptions`. They form a typed hierarchy that maps directly to `gaze` binary exit codes and stderr JSON variants.

## Hierarchy

```
\RuntimeException
└── GazeException                       (base, exitCode + stderrHash + variant)
    ├── GazeCallerBugException           (exit bucket 1 — caller error, NonRetryable)
    │   ├── GazeEmptyInputException
    │   ├── GazeInputTooLargeException
    │   ├── GazeInvalidEncodingException
    │   └── GazeStdinParseException
    ├── GazeOpsConfigException           (exit bucket 2 — config error, NonRetryable)
    │   ├── GazePolicyConfigException
    │   ├── GazePolicyConfigDetailException
    │   ├── GazeAuditPurgeIso8601Exception
    │   └── GazeAuditDbNotConfiguredException
    ├── GazeIntegrityException           (exit bucket 3 — integrity/session error)
    │   ├── GazeUnknownTokenException    (NonRetryable)
    │   ├── GazeResponseDecodeException  (NonRetryable)
    │   ├── GazeInvalidSignatureException (NonRetryable)
    │   ├── GazeInvalidBlobVersionException (NonRetryable + RequiresFreshClean)
    │   ├── GazeBlobExpiredException     (NonRetryable + RequiresFreshClean)
    │   └── GazePipelineException        (Retryable)
    └── GazeInfraException               (exit bucket 4 / 141 — infra/I-O error)
        ├── GazeIoException              (RetryableWithAlert)
        ├── GazeSigPipeException         (RetryableWithAlert)
        ├── GazeTimeoutException         (RetryableWithAlert)
        └── GazePolicyOpenException      (NonRetryable)
```

---

## Retry Contract Interfaces

| Interface | Namespace | Meaning |
|---|---|---|
| `NonRetryable` | `Queue\Contracts` | `GazeRetryPolicy` calls `$job->fail()` — permanent failure, no retry. |
| `Retryable` | `Queue\Contracts` | `GazeRetryPolicy` calls `$job->release()` with backoff — transient, retry. |
| `RetryableWithAlert` | `Queue\Contracts` | Like `Retryable` plus fires a `GazeInfraAlert` event — infra problem worth alerting. |
| `RequiresFreshClean` | `Queue\Contracts` | Signals that the session blob is unrecoverable; re-run `Gaze::clean()` on the original text. |

---

## Exception Table

| Class | Exit Bucket | Retry Behaviour | `RequiresFreshClean` | When Thrown |
|---|---|---|---|---|
| `GazeException` | — | (base, never thrown directly) | No | — |
| `GazeCallerBugException` | 1 | `NonRetryable` → fail | No | Abstract base for caller-error subclasses |
| `GazeEmptyInputException` | 1 | `NonRetryable` → fail | No | Input string is empty (pre-flight or binary) |
| `GazeInputTooLargeException` | 1 | `NonRetryable` → fail | No | Input exceeds `max_bytes` ceiling |
| `GazeInvalidEncodingException` | 1 | `NonRetryable` → fail | No | Input is not valid UTF-8 |
| `GazeStdinParseException` | 1 | `NonRetryable` → fail | No | Binary could not parse the JSON sent on stdin |
| `GazeOpsConfigException` | 2 | `NonRetryable` → fail | No | Abstract base for configuration-error subclasses |
| `GazePolicyConfigException` | 2 | `NonRetryable` → fail | No | TOML policy file is syntactically invalid |
| `GazePolicyConfigDetailException` | 2 | `NonRetryable` → fail | No | TOML policy file has a semantic validation error (detail field present) |
| `GazeAuditPurgeIso8601Exception` | 2 | `NonRetryable` → fail | No | `--before` timestamp is not valid ISO 8601 UTC |
| `GazeAuditDbNotConfiguredException` | N/A | `NonRetryable` → fail | No | `gaze.audit_db_path` is null and no per-call override given |
| `GazeBinaryMissingException` | N/A | (not queue-facing) | No | Binary not found at configured or discovered path |
| `GazeIntegrityException` | 3 | (see subclasses) | No | Abstract base for session-integrity subclasses |
| `GazeUnknownTokenException` | 3 | `NonRetryable` → fail | No | Binary encountered a token it could not map back to PII |
| `GazeResponseDecodeException` | 3 | `NonRetryable` → fail | No | Binary stdout was not valid JSON or not a JSON object |
| `GazeInvalidSignatureException` | 3 | `NonRetryable` → fail | No | Session blob HMAC verification failed |
| `GazeInvalidBlobVersionException` | 3 | `NonRetryable` → fail | **Yes** | Session blob was created by a newer binary version |
| `GazeBlobExpiredException` | 3 | `NonRetryable` → fail | **Yes** | Session blob TTL has elapsed |
| `GazePipelineException` | 3 | `Retryable` → release | No | SQLite audit DB open/query failed (BUSY/LOCKED) |
| `GazeInfraException` | 4/141 | (see subclasses) | No | Abstract base for infra subclasses |
| `GazeIoException` | 4 | `RetryableWithAlert` → release + alert | No | Binary I/O failure (disk, pipe) |
| `GazeSigPipeException` | 141 | `RetryableWithAlert` → release + alert | No | Binary killed by SIGPIPE (exit 141) |
| `GazeTimeoutException` | 141 | `RetryableWithAlert` → release + alert | No | Binary exceeded `gaze.timeout_seconds` |
| `GazePolicyOpenException` | 4 | `NonRetryable` → fail | No | Binary could not open the policy file (missing/unreadable) |

---

## Notes on Non-Obvious Classes

### `GazeCallerBugException` / `isCallerBug()`

`GazeException::isCallerBug()` returns `true` for any exception whose `Variant` maps to exit bucket 1. This is a convenience for catch-all handlers that want to separate "the caller sent bad input" from "the binary had an infra problem":

```php
try {
    $session = Gaze::clean($text);
} catch (\Naoray\GazeLaravel\Exceptions\GazeException $e) {
    if ($e->isCallerBug()) {
        // Bad input — do not retry; surface to the caller.
        throw new \InvalidArgumentException('Input cannot be processed: '.$e->getMessage(), previous: $e);
    }
    throw $e;
}
```

### `GazePolicyConfigDetailException`

This class is never produced by the binary's raw stderr — it is synthesized client-side by `Variant::tryFromStderr()`. The binary emits `error=PolicyConfig` for both config errors; when the stderr JSON also contains a `detail` sidecar field, the adapter promotes the exception to `GazePolicyConfigDetailException` to give you richer context without changing exit codes.

### `GazeInvalidBlobVersionException` + `GazeBlobExpiredException` (`RequiresFreshClean`)

Both implement `RequiresFreshClean`. The `requiresFreshClean(): bool` method returns `true`, which is a signal to your job handler that the session blob is permanently unrecoverable and the only path forward is to re-run `Gaze::clean()` on the original plaintext. The `GazeRetryPolicy` itself does not automate this re-run — your job must implement the re-clean logic:

```php
use Naoray\GazeLaravel\Queue\Contracts\RequiresFreshClean;

} catch (\Naoray\GazeLaravel\Exceptions\GazeException $e) {
    if ($e instanceof RequiresFreshClean) {
        // Re-clean from original text and re-enqueue.
        dispatch(new ProcessDocumentJob($this->originalText));
        $this->fail($e);
        return;
    }
    \Naoray\GazeLaravel\Queue\GazeRetryPolicy::dispatch($e, $this);
}
```

### `GazeResponseDecodeException`

Thrown when the binary exits successfully (or with a non-zero code) but the stdout is not valid JSON or not a JSON object. This indicates a binary version mismatch or a catastrophic runtime error rather than a caller or infra problem. It is `NonRetryable` because retrying will produce the same malformed output.

### `GazeBinaryMissingException`

Not queue-facing. Thrown during binary resolution at the point `BinaryResolver::resolve()` is called. Fix by ensuring the binary is installed (`composer require empiretwo/gaze-laravel` triggers the Composer plugin) or by setting `GAZE_BINARY` to a valid path.

### `GazePipelineException`

Thrown when the audit DB operation fails with a `Pipeline` variant (typically SQLite `BUSY` or `LOCKED`). It is `Retryable` (release with backoff) rather than `RetryableWithAlert` because transient SQLite contention under moderate write concurrency is expected. If it alerts consistently, investigate write concurrency on the audit DB file.

### Log Levels

The log level for each exception family:

| Family | Level |
|---|---|
| `GazeCallerBugException` (bucket 1) | `notice` |
| `GazeOpsConfigException` (bucket 2) | `notice` |
| `GazeIntegrityException` (bucket 3) | `notice` |
| `GazeInfraException` (bucket 4/141) | `warning` |
| `GazeTimeoutException` | `warning` |

`GazeException::toLogContext()` returns a structured array safe to pass to `Log::*()`:

```php
[
    'exit_code'     => $e->exitCode,
    'error_variant' => $e->variant?->value, // e.g. "BlobExpired"
    'stderr_sha256' => $e->stderrHash,      // SHA-256 of raw stderr
]
```

The `stderrHash` is always a SHA-256 of the raw stderr string. It never contains PII — the raw stderr itself is never logged.
