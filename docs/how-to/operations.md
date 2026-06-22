# Operations

This page expands the operations guidance from the [README](../../README.md). Use it to wire health checks, telemetry exclusions, and failed-job pruning.

`php artisan gaze:check` verifies binary resolution and encrypter wiring.

`php artisan gaze:doctor --deep` adds policy-file checks plus a clean/restore smoke test.

`php artisan gaze:bench --requests=N` measures cold `Gaze::clean()` latency for adopter diagnostics.

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
