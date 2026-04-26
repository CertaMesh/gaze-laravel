# gaze-laravel

Laravel adapter for the [`gaze`](https://github.com/piinuts/gaze) v0.3 CLI contract.

`gaze-laravel` wraps the pipe-mode `gaze clean` / `gaze restore` workflow. It sends raw UTF-8 text to `clean`, keeps the returned `session_blob` encrypted at rest, and restores model output through `restore` with typed exceptions and queue-aware retry helpers.

## Requirements

- PHP `^8.2`
- Laravel `^11.0 || ^12.0`
- The `gaze` binary on `PATH`, in `vendor/bin/gaze`, or configured via `GAZE_BINARY`

## Install

```bash
composer require naoray/gaze-laravel
php artisan vendor:publish --tag=gaze-config
php artisan vendor:publish --tag=gaze-policy
```

Optional composer hooks for binary download:

```json
{
    "scripts": {
        "post-install-cmd": ["Naoray\\GazeLaravel\\Install\\BinaryInstaller::postInstall"],
        "post-update-cmd": ["Naoray\\GazeLaravel\\Install\\BinaryInstaller::postInstall"]
    }
}
```

The installer targets `v0.3.0` and downloads the published `gaze-<target>` binary plus its `.sha256` checksum over HTTPS.

## Config

```php
return [
    'binary' => env('GAZE_BINARY', 'gaze'),
    'timeout_seconds' => (int) env('GAZE_TIMEOUT', 30),
    'policy_path' => env('GAZE_POLICY_PATH', base_path('policy.toml')),
    'max_bytes' => env('GAZE_MAX_BYTES'),
    'session_ttl_seconds' => env('GAZE_SESSION_TTL'),
    'blob_encryption_key' => env('GAZE_ENCRYPTION_KEY'),
];
```

`GAZE_ENCRYPTION_KEY` may be unset to reuse `APP_KEY`, or set to a dedicated `base64:` 32-byte key.

## Usage

```php
use Naoray\GazeLaravel\Gaze;

public function draft(Gaze $gaze, string $body, string $llmReply): string
{
    $session = $gaze->clean($body);

    // $session->cleanText is safe for the model.
    // $session->ciphertext keeps the session blob encrypted in serialized payloads.
    // $session->detections is forwarded from the CLI stats block.

    return $gaze->restore($session, $llmReply);
}
```

## Retry Discipline

Consumer jobs must `use Queueable, InteractsWithQueue` traits.

```php
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\Queue\GazeRetryPolicy;

class DraftReplyJob implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;

    public int $tries = 3;
    public int $backoff = 30;

    public function handle(Gaze $gaze): void
    {
        try {
            $session = $gaze->clean($this->payload);
            $draft = $gaze->restore($session, $this->llmReply);
        } catch (\Throwable $e) {
            GazeRetryPolicy::dispatch($e, $this);
        }
    }
}
```

`PolicyOpen` is treated as alert-and-fail, not retryable. Unknown throwables are rethrown to Laravel.

## Exceptions

- Exit bucket `1`: `GazeCallerBugException`
- Exit bucket `2`: `GazeOpsConfigException`
- Exit bucket `3`: `GazeIntegrityException`
- Exit bucket `4`: `GazeInfraException`

Dedicated subclasses include `GazeUnknownTokenException`, `GazeBlobExpiredException`, `GazeInvalidBlobVersionException`, `GazeIoException`, `GazePolicyOpenException`, and `GazeSigPipeException`.

## Operations

`php artisan gaze:check` verifies binary resolution and encrypter wiring.

`php artisan gaze:doctor --deep` adds policy-file checks plus a clean/restore smoke test.

Exclude blob-carrying jobs from Telescope and Pulse. Keep ciphertext out of long-lived telemetry stores.

```php
// app/Providers/TelescopeServiceProvider.php
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

public function register(): void
{
    Telescope::filter(function (IncomingEntry $entry) {
        if ($entry->type === 'job' && in_array(
            $entry->content['name'] ?? '',
            [DraftEmailReplyJob::class],
            true,
        )) {
            return false;
        }

        return $this->shouldRecord($entry);
    });
}
```

Apply the same exclusion to Laravel Pulse and any audit-log or breadcrumb tooling that captures queued job payloads.

Prune failed jobs on a cadence aligned with your session TTL:

```php
Schedule::command('queue:prune-failed --hours=24')->daily();
```

## Testing

```bash
./vendor/bin/pest
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
```

Integration tests require a real binary:

```bash
GAZE_BINARY=/path/to/gaze ./vendor/bin/pest --testsuite Integration
```
