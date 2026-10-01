# Exception Reference

Every exception this package throws extends `CertaMesh\Gaze\Exceptions\GazeException`, so `catch (GazeException $e)` is the single catch-all surface. Runtime exceptions live under `CertaMesh\Gaze\Exceptions` and map directly to `gaze` binary exit codes and stderr JSON variants; NER install exceptions live under `CertaMesh\Gaze\Install` and describe `gaze:install:ner` / Composer-plugin failures.

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
    │   │   ├── GazeSafetyNetConfigException (exit bucket 3, NonRetryable)
    │   │   └── GazeSafetyNetUsageException (exit bucket 2, NonRetryable — gaze >= 0.15)
    │   ├── GazePolicyConfigDetailException
    │   ├── GazePolicySchemaUnsupportedException
    │   ├── GazeAuditPurgeIso8601Exception
    │   └── GazeAuditDbNotConfiguredException
    ├── GazeIntegrityException           (exit bucket 3 — integrity/session error)
    │   ├── GazeUnknownTokenException    (NonRetryable)
    │   ├── GazeResponseDecodeException  (NonRetryable)
    │   ├── GazeSafetyNetFailureException (HasRetryDisposition — variant-dependent retry policy)
    │   ├── GazeUnsupportedSessionScopeException (NonRetryable — deprecated, never thrown)
    │   ├── GazeInvalidSignatureException (NonRetryable)
    │   ├── GazeInvalidBlobVersionException (NonRetryable, requiresFreshClean() = true)
    │   ├── GazeBlobExpiredException     (NonRetryable, requiresFreshClean() = true)
    │   └── GazePipelineException        (Retryable)
    ├── GazeInfraException               (exit bucket 4 / 141 — infra/I-O error)
    │   ├── GazeIoException              (RetryableWithAlert)
    │   ├── GazeSigPipeException         (RetryableWithAlert)
    │   ├── GazeTimeoutException         (RetryableWithAlert)
    │   └── GazePolicyOpenException      (NonRetryable)
    └── Install\NerInstallException      (NER model install failures, exitCode())
        ├── NerDiskSpaceException        (exit 1 — not enough disk space at dest)
        ├── NerLockHeldException         (exit 75 — concurrent gaze:install:ner)
        ├── NerManifestInvalidException  (exit 2 — manifest or policy file invalid)
        ├── NerPolicyConflictException   (exit 1 — [ner] block conflicts; use --force)
        ├── NerShaMismatchException      (exit 1 — artifact sha256 mismatch)
        ├── NerTransportException        (exit 1 — download/filesystem failure)
        └── NerVariantUnknownException   (exit 2 — unknown NER variant name)
```

The `Install\Ner*` family lives under the `CertaMesh\Gaze\Install` namespace. These exceptions are produced without a `gaze` subprocess (including during `composer install`, where no Laravel app exists), so their inherited `$stderrHash` and `$variant` are always `null`, and `isCallerBug()` is always `false`. Their `exitCode(): int` method mirrors the inherited `$exitCode` property and is the suggested process exit code for `gaze:install:ner`. None of them implement a retry contract — they never flow through `GazeRetryPolicy`.

---

## Retry Contract Interfaces

| Interface | Namespace | Meaning |
|---|---|---|
| `NonRetryable` | `Queue\Contracts` | `GazeRetryPolicy` calls `$job->fail()` — permanent failure, no retry. |
| `Retryable` | `Queue\Contracts` | `GazeRetryPolicy` calls `$job->release()` with backoff — transient, retry. |
| `RetryableWithAlert` | `Queue\Contracts` | Like `Retryable` plus fires a `GazeInfraAlert` event — infra problem worth alerting. |
| `RequiresFreshClean` | `Queue\Contracts` | **Deprecated.** Signals that the session blob is unrecoverable. Branch on `GazeIntegrityException::requiresFreshClean()` instead; the marker is kept on its two implementors until 1.0 for BC. |

---

## Exception Table

| Class | Exit Bucket | Retry Behaviour | `requiresFreshClean()` | When Thrown |
|---|---|---|---|---|
| `GazeException` | — | (base, never thrown directly) | No | — |
| `GazeCallerBugException` | 1 | `NonRetryable` → fail | No | Abstract base for caller-error subclasses |
| `GazeEmptyInputException` | 1 | `NonRetryable` → fail | No | Input string is empty (pre-flight or binary) |
| `GazeInputTooLargeException` | 1 | `NonRetryable` → fail | No | Input exceeds `max_bytes` ceiling |
| `GazeInvalidEncodingException` | 1 | `NonRetryable` → fail | No | Input is not valid UTF-8 |
| `GazeStdinParseException` | 1 | `NonRetryable` → fail | No | Binary could not parse the JSON sent on stdin |
| `GazeOpsConfigException` | 2 | `NonRetryable` → fail | No | Abstract base for configuration-error subclasses |
| `GazePolicyConfigException` | 2 | `NonRetryable` → fail | No | TOML policy file is syntactically invalid |
| `GazePolicyConfigDetailException` | 2 | `NonRetryable` → fail | No | TOML policy file has a semantic validation error; exposes `detail(): ?string`. The adapter also throws it itself (exit 2, `stderrHash` null, binary never spawned) for `gaze.session_scope=ephemeral`, which `gaze clean` cannot export |
| `GazePolicySchemaUnsupportedException` | 2 | `NonRetryable` → fail | No | `policy.toml`'s `schema_version` major.minor prefix is outside the binary's supported range; exposes `found(): string` + `supported(): string` |
| `GazeAuditPurgeIso8601Exception` | 2 | `NonRetryable` → fail | No | `--before` timestamp is not valid ISO 8601 UTC |
| `GazeAuditDbNotConfiguredException` | N/A | `NonRetryable` → fail | No | `gaze.audit_db_path` is null and no per-call override given |
| `GazeBinaryMissingException` | N/A | (not queue-facing) | No | Binary not found at configured or discovered path |
| `GazeIntegrityException` | 3 | (see subclasses) | No | Abstract base for session-integrity subclasses |
| `GazeUnknownTokenException` | 3 | `NonRetryable` → fail | No | Binary encountered a token it could not map back to PII |
| `GazeResponseDecodeException` | 3 | `NonRetryable` → fail | No | Binary stdout was not valid JSON or not a JSON object |
| `GazeSafetyNetConfigException` | 3 | `NonRetryable` → fail | No | Safety-net configuration is invalid; extends `GazePolicyConfigException`. Since gaze 0.15.0 upstream also emits it at exit 2 for Nym policy/bundle setup errors. The adapter also throws it itself (exit 2, `stderrHash` null, binary never spawned) when an enabled safety net selects `kiji-distilbert`, removed upstream in gaze 0.15.0 |
| `GazeSafetyNetUsageException` | 2 | `NonRetryable` → fail | No | gaze >= 0.15.0 rejected the safety-net flag combination (e.g. `--safety-net-backend` without exactly one `--safety-net`); extends `GazePolicyConfigException`; exposes `detail(): ?string` |
| `GazeSafetyNetFailureException` | 3 | See safety-net table | No | Safety-net subprocess failed or suspected a leak; exposes `safetyNetVariant(): string` |
| `GazeUnsupportedSessionScopeException` | 3 | `NonRetryable` → fail | No | **Deprecated, never thrown.** Upstream removed the variant in gaze 0.15.0; an invalid `--session-scope` surfaces as `GazePolicyConfigDetailException`. Kept for BC until 1.0 |
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
} catch (\CertaMesh\Gaze\Exceptions\GazeException $e) {
    if ($e->isCallerBug()) {
        // Bad input — do not retry; surface to the caller.
        throw new \InvalidArgumentException('Input cannot be processed: '.$e->getMessage(), previous: $e);
    }
    throw $e;
}
```

### `GazePolicyConfigDetailException`

This class is never produced by the binary's raw stderr — it is synthesized client-side by `Variant::tryFromStderr()`. The binary emits `error=PolicyConfig` for both config errors; when the stderr JSON also contains a `detail` sidecar field, the adapter promotes the exception to `GazePolicyConfigDetailException` to give you richer context without changing exit codes.

`GazePolicyConfigDetailException::detail(): ?string` returns the upstream
`detail` sidecar string verbatim — typical values are loader causes such as
`"unknown bundled rulepack: garbage"`,
`"--format must be 'json', got 'xml'"`, or a TOML parse-error trace. It is
`null` only when the adapter could not decode the field (defensive — upstream
always emits it on this variant).

### `GazePolicySchemaUnsupportedException`

Thrown when the upstream `gaze` binary rejects a policy whose top-level
`schema_version` major.minor prefix does not match its supported range.
Distinct from `GazePolicyConfigException` so adopters crossing a schema
contract break see the version mismatch directly:

```php
try {
    $session = Gaze::clean($text);
} catch (\CertaMesh\Gaze\Exceptions\GazePolicySchemaUnsupportedException $e) {
    report(new \RuntimeException(sprintf(
        'policy.toml schema_version %s is unsupported; binary expects prefix %s',
        $e->found(),
        $e->supported(),
    ), previous: $e));
    throw $e;
}
```

Soft-default behaviour: existing 0.6.x / 0.7.x policies that omit
`schema_version` keep loading because upstream stamps the missing field with
`DEFAULT_POLICY_SCHEMA_VERSION` (`"0.1.0"`). Adopters can opt into explicit
pinning by adding `schema_version = "0.1.0"` to the top of `policy.toml`. Use
the full `major.minor.patch` form: since gaze 0.15.0 (#576) a two-part
`"0.1"` is refused with `PolicySchemaUnsupported` (`supported: "0.1."`).

### Safety-net and session-scope exceptions

`GazeSafetyNetFailureException::safetyNetVariant()` returns the upstream sidecar variant. Because the retry lane depends on that runtime value, the class implements none of the static marker interfaces; it implements `CertaMesh\Gaze\Queue\Contracts\HasRetryDisposition`, and `retryDisposition(): RetryAction` (consulted first by `GazeRetryPolicy::classify()`) maps the variants as:

| Safety-net variant (gaze 0.15.1) | `retryDisposition()` | Why |
|---|---|---|
| `Timeout` | `ReleaseWithBackoff` → release | Backend exceeded `gaze.safety_net.timeout_ms`; load-dependent |
| `Runtime` | `ReleaseWithBackoff` → release | Backend process crashed, exited non-zero, or its inference failed; usually transient. Was `Fail` before v0.16.0 |
| `SuspectedLeak` | `ReleaseWithAlert` → release + alert | Strict mode flagged an uncovered span; a human should look |
| `Unavailable` | `Fail` | Backend not configured (env var unset, empty opf command, no model for the locale) |
| `WeightsMissing` | `Fail` | A model weight or checkpoint file is missing |
| `ModelUnavailable` | `Fail` | Model or opf command could not be loaded, spawned or verified |
| `ModelIntegrityMismatch` | `Fail` | A model file failed its SHA-256 pin |
| `InputTooLarge` | `Fail` | Input exceeds `gaze.safety_net.input_limit_bytes` |
| `InvalidOutput` | `Fail` | Backend output did not parse; points at a model/command mismatch |
| `TolerantModeDisabled` | `Fail` | `tolerant` mode or fallback without `GAZE_ALLOW_TOLERANT` |
| `Unknown` | `Fail` | Upstream's own label for a variant its CLI does not map yet |
| `Other` *(legacy)* | `ReleaseWithBackoff` → release | No gaze release emits it; the adapter's label for a missing `variant` sidecar. Kept for BC until 1.0 |
| `Unsupported` *(legacy)* | `Fail` | No gaze release emits it. Kept for BC until 1.0 |
| any other variant | `Fail` (fail closed) | |

`tests/Contract/SafetyNetRetryMapContractTest.php` pins this list as of gaze 0.15.1. Re-check it on every binary pin bump, so a new upstream variant gets an explicit decision instead of the fail-closed fallback.

Do **not** branch on `$e instanceof NonRetryable` (or the other markers) for this exception — it matches none of them. Use `GazeRetryPolicy::classify($e)` or `$e->retryDisposition()`. `isNonRetryable()` is `true` only for a variant listed above with `Fail`; an unknown variant still fails, but answers `false` there.

**Daemon parity (v0.16.0).** `Gaze::daemon()` reports the same safety-net failures as `GazeDaemonException` with a `DaemonErrorVariant::SafetyNet*` case (`SafetyNetTimeout`, `SafetyNetRuntime`, …). `GazeDaemonException` implements `HasRetryDisposition` too and gives those cases the disposition of the same-named variant above; `DaemonErrorVariant::safetyNetVariant()` returns that name (`SafetyNetTimeout` → `Timeout`). Every other daemon variant, and the `GazeDaemonTransportException` / `GazeDaemonTimeoutException` / `GazeDaemonFeatureUnsupportedException` subclasses, return `RetryAction::Throw` as before.

`GazeSafetyNetConfigException` and `GazeSafetyNetUsageException` extend `GazePolicyConfigException`, so existing catch blocks for policy/config failures keep working. `GazeSafetyNetUsageException` (gaze >= 0.15.0) means the argv itself is wrong — fix the safety-net config, don't retry.

`GazeUnsupportedSessionScopeException` is deprecated and never thrown: upstream only emitted `UnsupportedSessionScope` on the no-policy `gaze clean` path (the adapter always passes `--policy`) and removed the variant in gaze 0.15.0. Catch `GazePolicyConfigDetailException` for an invalid `--session-scope`. `attemptedScope()` still returns the `variant` sidecar if an older binary ever emits it.

`GAZE_SESSION_SCOPE=ephemeral` also surfaces as `GazePolicyConfigDetailException`, thrown by the adapter before spawning. Without that pre-flight the binary answers with the retryable `GazePipelineException`, because gaze never exports an ephemeral session. A policy `[session] scope = "ephemeral"` still reaches the binary and fails with `GazePipelineException`; `gaze:doctor` warns about it. See [configuration](configuration.md#gazesession_scope).

### `GazeInvalidBlobVersionException` + `GazeBlobExpiredException` (fresh clean required)

Both override `requiresFreshClean(): bool` (defined on `GazeIntegrityException`) to return `true` — a signal to your job handler that the session blob is permanently unrecoverable and the only path forward is to re-run `Gaze::clean()` on the original plaintext. The `GazeRetryPolicy` itself does not automate this re-run — your job must implement the re-clean logic:

```php
use CertaMesh\Gaze\Exceptions\GazeIntegrityException;

} catch (\CertaMesh\Gaze\Exceptions\GazeException $e) {
    if ($e instanceof GazeIntegrityException && $e->requiresFreshClean()) {
        // Re-clean from original text and re-enqueue.
        dispatch(new ProcessDocumentJob($this->originalText));
        $this->fail($e);
        return;
    }
    \CertaMesh\Gaze\Queue\GazeRetryPolicy::dispatch($e, $this);
}
```

Both classes also still implement the `Queue\Contracts\RequiresFreshClean` marker interface, but it is deprecated: prefer the method, which mirrors the `HasRetryDisposition` method-based pattern and can give variant-dependent answers. The marker stays on these two exceptions until 1.0 for backwards compatibility; nothing in this package dispatches on it.

### `GazeResponseDecodeException`

Thrown when the binary exits successfully (or with a non-zero code) but the stdout is not valid JSON or not a JSON object. This indicates a binary version mismatch or a catastrophic runtime error rather than a caller or infra problem. It is `NonRetryable` because retrying will produce the same malformed output.

### `GazeBinaryMissingException`

Not queue-facing. Thrown during binary resolution at the point `BinaryResolver::resolve()` is called. Fix by installing the binary (`php artisan gaze:install`, or `gaze:install:binary` for the binary alone) or by setting `GAZE_BINARY` to a valid path.

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
    'stderr_sha256' => $e->stderrHash,      // SHA-256 of raw stderr, or null
]
```

`stderrHash` is the SHA-256 of the raw stderr string when a subprocess stderr stream existed — including the hash of the empty string when the process ran but emitted nothing (a forensic fact worth recording). It is `null` when no stderr stream ever existed: pre-flight validation failures, timeouts, stdout decode failures, daemon envelope errors, and the `Install\Ner*` family. Exception messages render the null case as `stderr_sha256=none`. Either way it never contains PII — the raw stderr itself is never logged.

The daemon family (`GazeDaemonException` and its subclasses) overrides `toLogContext()` with a different shape. Daemon errors are stdout envelopes, and the session id is adopter-chosen, so it appears only as a digest:

```php
[
    'daemon_variant'    => $e->daemonVariant()->value, // e.g. "Pipeline", "Transport"
    'session_id_sha256' => '3f2a9c0d41b7',             // first 12 hex of sha256($e->sessionId()), or null
    'raw'               => [                           // the envelope, digested:
        'session_id_sha256' => '3f2a9c0d41b7',         //   session_id        → 12-hex digest
        'error'             => 'Pipeline',             //   clean_text        → clean_text_sha256 (full SHA-256)
        'detail'            => 'gaze daemon request failed closed', // raw_line → raw_line_sha256 (full SHA-256)
    ],
]
```

Before v0.16.0 the keys were `session_id` (raw) and the unmodified `raw` envelope (#181). `$e->sessionId()` and `$e->raw()` still return the raw values; the adapter never logs them. See [daemon logging](../how-to/daemon.md#logging-and-session-ids).
