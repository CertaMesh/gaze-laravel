# Retry Discipline

This page expands the retry discipline guidance from the [README](../README.md). For the full queue guide, see [Queue integration](./queue.md).

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
