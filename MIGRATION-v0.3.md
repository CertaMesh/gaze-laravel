# Migrating to gaze-laravel v0.3

## Required API changes

```php
// v0.2.x
$session = $gaze->sanitize($body, $context);
$restored = $gaze->restore($reply, $session->sessionBlob);

// v0.3
$session = $gaze->clean($body);
$restored = $gaze->restore($session, $reply);
```

- `Context` and `ContextResolver` are removed.
- `GazeSession` now exposes `cleanText`, `ciphertext`, and `detections`.
- The raw `session_blob` is encrypted internally through `EncryptedBlob`.
- Old alias exceptions are gone. Catch bucket parents or dedicated v0.3 subclasses.

The four sections below expand each breaking change with before/after snippets and migration paths.

## Removed: `fail_closed` config + `GAZE_FAIL_CLOSED` env

`config('gaze.fail_closed')` and the `GAZE_FAIL_CLOSED=false` escape hatch are gone in v0.3. Fail-closed is permanent: a binary failure always raises a typed `GazeException`, including in `local` and `testing` environments. There is no longer a way to swap in a fallback DTO that returns the original (unsanitized) text.

Any `GAZE_FAIL_CLOSED=false` line left in `.env` is silently ignored — `config/gaze.php` no longer reads the key.

```diff
# .env
- GAZE_FAIL_CLOSED=false
```

If you relied on fail-open in dev (for example, running without the binary installed), wrap call sites so transient gaze unavailability does not break local flows:

```php
use Naoray\GazeLaravel\Exceptions\GazeException;

try {
    $session = $gaze->clean($body);
    $reply = $gaze->restore($session, $llmResponse);
} catch (GazeException $e) {
    if (! app()->environment('production')) {
        report($e);
        $reply = $llmResponse; // dev-only fallback. Never in prod.
    } else {
        throw $e;
    }
}
```

Production code paths must propagate `GazeException` as before. Half-anonymized output is still worse than no output.

## Removed: `Context` / `ContextResolver`

Pre-v0.3, per-call PII allowlists were threaded via a `Context` DTO and a chain of `ContextResolver` decorators:

```php
// v0.2.x
$context = new Context(allow: ['internal_user_id'], domain: 'support');
$session = $gaze->sanitize($body, $context);
```

In v0.3 the `Context` envelope and the entire `ContextResolver` chain are removed. PII detection is driven exclusively by the detector policy file passed to the Rust `gaze` binary:

```php
// v0.3
$session = $gaze->clean($body);
```

Detector behaviour is now configured at the policy layer. Wire your policy via `config/gaze.php`:

```php
'policy_path' => env('GAZE_POLICY_PATH', base_path('policy.toml')),
```

Migration path for custom resolvers:

1. Translate per-domain allow / deny rules from your `ContextResolver` chain into one or more `policy.toml` rule sets. See `policy.toml.example` shipped with this package and the upstream `gaze` `docs/policy.md` for the rule grammar.
2. If you previously selected resolvers per tenant or per route, switch to multiple policy files and override `policy_path` at the call site (e.g. via a per-request scoped config bind) rather than instantiating different resolver chains.
3. Drop any `Context` / `ContextResolver` implementations and DI bindings — `app(ContextResolver::class)` will throw because the contract is gone.

This is a real architectural shift: detection logic that lived in PHP now lives in declarative TOML consumed by the binary. There is no PHP-level extension point for custom detectors in v0.3.

## Changed: `restore()` returns `string` (`RestoredText` DTO removed)

Pre-v0.3, `restore()` returned a `RestoredText` value object:

```php
// v0.2.x
$restored = $gaze->restore($reply, $session->sessionBlob);
echo $restored->text;
foreach ($restored->warnings as $warning) {
    // ...
}
```

v0.3 returns the restored string directly. The `RestoredText` class is removed:

```php
// v0.3
$restored = $gaze->restore($session, $reply);
echo $restored;
```

Typed call sites will fail to load after upgrade. Any signature like:

```php
function consume(RestoredText $r): void { /* ... */ }
```

triggers a `TypeError` (or `Error: class not found`) because `Naoray\GazeLaravel\RestoredText` no longer exists. Update parameter and return types to `string`:

```diff
- function consume(RestoredText $r): void
+ function consume(string $r): void
```

`RestoredText::warnings` has no direct call-site replacement — per-call warnings are now surfaced as typed exceptions or via the audit log. If you previously inspected `$restored->warnings` programmatically, switch to catching the corresponding `GazeException` subclass or to subscribing to the audit log surface.

## Removed exceptions and replacements

Pre-v0.3 ran on two coarse `*Failed` exception classes plus two marker interfaces. v0.3 replaces them with per-variant typed exceptions and dedicated queue-retry contracts.

| Removed | Replacement(s) | Notes |
| --- | --- | --- |
| `GazeSanitizeFailedException` | Variant-typed subclasses of `GazeException` (`GazeUnknownTokenException`, `GazeBlobExpiredException`, `GazeTimeoutException`, `GazeIoException`, `GazePipelineException`, `GazeIntegrityException`, `GazeInfraException`, `GazeCallerBugException`, `GazePolicyConfigException`, `GazePolicyConfigDetailException`, `GazePolicyOpenException`, `GazeBinaryMissingException`, `GazeStdinParseException`, `GazeResponseDecodeException`, `GazeSigPipeException`, `GazeInputTooLargeException`, `GazeEmptyInputException`, `GazeInvalidBlobVersionException`, `GazeInvalidEncodingException`, `GazeInvalidSignatureException`, `GazeOpsConfigException`, `GazeAuditPurgeIso8601Exception`) | Catch `GazeException` for bucket-level handling, or a specific subclass for variant-specific behaviour. `src/Exceptions/` is the canonical list. |
| `GazeRestoreFailedException` | Same set as above | `clean()` and `restore()` now share one typed hierarchy keyed by `Variant`. |
| `TerminalGazeException` (marker interface) | `Naoray\GazeLaravel\Queue\Contracts\NonRetryable` | Drives `GazeRetryPolicy` to dead-letter without retry. |
| `TransientGazeException` (marker interface) | `Naoray\GazeLaravel\Queue\Contracts\Retryable` — and `RetryableWithAlert` for variants that warrant alerting on retry (e.g. `GazeTimeoutException`, `GazeIoException`) | Drives `GazeRetryPolicy` to apply normal queue backoff. |

Catch blocks targeting the removed parents will silently stop catching after upgrade, surfacing as unhandled exceptions in production. Update them to the new bucket parent or to specific variants:

```diff
try {
    $session = $gaze->clean($body);
-} catch (GazeSanitizeFailedException $e) {
+} catch (GazeUnknownTokenException $e) {
+    // variant-specific handling
+} catch (GazeException $e) {
+    // bucket fallback
}
```

For per-variant routing, branch on `$e->variant` (a `Variant` enum) or on the marker interfaces — `$e instanceof NonRetryable` / `$e instanceof Retryable` / `$e instanceof RetryableWithAlert`.

## Queue jobs

Consumer jobs must `use Queueable, InteractsWithQueue` traits.

```php
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\Queue\GazeRetryPolicy;

public function handle(Gaze $gaze): void
{
    try {
        $session = $gaze->clean($this->payload);
        $draft = $gaze->restore($session, $this->reply);
    } catch (\Throwable $e) {
        GazeRetryPolicy::dispatch($e, $this);
    }
}
```

`failed()` does not enforce retry policy. Put the retry decision in the `handle()` catch block.
