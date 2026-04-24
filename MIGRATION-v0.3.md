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
