# Getting Started with gaze-laravel

A 10-minute walkthrough from install to your first clean/restore cycle.

## Requirements

- PHP `^8.2`
- Laravel `^11.0` or `^12.0`
- The `gaze` v0.6.5 binary — installed automatically by the Composer plugin, or supplied via `GAZE_BINARY`

## 1. Install

```bash
composer require naoray/gaze-laravel
```

The Composer plugin will prompt you to allow downloading the `gaze` binary into `vendor/bin/`. Press `y`.

> **Private repo:** `piinuts/gaze` is currently private. Set `GAZE_GITHUB_TOKEN` to a PAT with `contents:read` before running `composer require` — see the "Binary install" section in [README.md](../README.md) for details.
>
> **Skip auto-download:** set `GAZE_SKIP_BINARY_DOWNLOAD=1` if you manage the binary out-of-band (CI, container image, etc.).

Publish the config file and a starter policy:

```bash
php artisan vendor:publish --tag=gaze-config
php artisan vendor:publish --tag=gaze-policy
```

## 2. Configure

Add to `.env`:

```env
# Path to your detector policy file (published above)
GAZE_POLICY_PATH=/var/www/html/policy.toml

# Optional: dedicated 32-byte encryption key for session blobs
# Falls back to APP_KEY when unset.
# GAZE_ENCRYPTION_KEY=base64:<32-byte-key>

# Optional: enable the audit trail (SQLite)
# GAZE_AUDIT_DB_PATH=/var/www/html/storage/app/gaze/audit.sqlite
```

The published `config/gaze.php` maps every env value — no changes to the PHP file are needed for a basic setup.

## 3. First clean/restore cycle

The core pattern is two calls:

1. **`Gaze::clean($text)`** — strips PII before your LLM sees it. Returns a `GazeSession` carrying the encrypted blob.
2. **`Gaze::restore($session, $llmReply)`** — re-inserts original values from the blob into the LLM reply.

```php
use Naoray\GazeLaravel\Facades\Gaze;

class EmailDraftController extends Controller
{
    public function draft(Request $request): JsonResponse
    {
        // Raw user message — may contain real PII
        $body = $request->string('body');
        // e.g. "Please reply to alice@example.invalid or call +1-555-0100"

        // Strip PII — alice@example.invalid → <Email_0>, +1-555-0100 → <Phone_0>
        $session = Gaze::clean($body);

        // $session->cleanText is safe to forward to your LLM
        $llmReply = $this->callLlm($session->cleanText);

        // Restore: re-inserts the original values from the encrypted blob
        $draft = Gaze::restore($session, $llmReply);

        return response()->json(['draft' => $draft]);
    }

    private function callLlm(string $text): string
    {
        // LLM sees no real PII — only placeholder tokens
        // Simulated reply:
        return "I'll follow up at <Email_0> or reach you on <Phone_0>.";
    }
}
```

`restore()` maps `<Email_0>` back to `alice@example.invalid` and `<Phone_0>` back to `+1-555-0100` using the encrypted session blob. The LLM never saw real PII.

### Queue jobs

For background processing, inject `Gaze` and catch exceptions via `GazeRetryPolicy`:

```php
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\Queue\GazeRetryPolicy;

class SendDraftEmailJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(private readonly string $body) {}

    public function handle(Gaze $gaze): void
    {
        try {
            $session = $gaze->clean($this->body);
            $draft = $gaze->restore($session, $this->getLlmReply($session->cleanText));
            // ... send $draft
        } catch (\Throwable $e) {
            GazeRetryPolicy::dispatch($e, $this);
        }
    }
}
```

## 4. Enable NER (optional)

By default, gaze-laravel uses regex/rulepack detection. For multilingual or higher-recall use cases, enable the Davlan mBERT NER model:

```bash
php artisan gaze:install-ner --yes
```

This downloads and SHA256-verifies the ONNX model into `storage/app/gaze-ner/`. Follow the printed instructions to add the `[ner]` block to `policy.toml`, or pass `--update-policy` to patch it automatically.

> **Cold-start cost:** every `Gaze::clean()` call loads the NER model from disk. Run `php artisan gaze:bench --requests=10` to measure the latency on your hardware before enabling in production.

## 5. Testing with fakes

`Gaze::fake()` swaps the real binary for an in-memory stub — no binary or policy file required in tests.

```php
use Naoray\GazeLaravel\Facades\Gaze;

it('drafts a reply and strips PII', function () {
    Gaze::fake();

    $this->postJson('/email/draft', [
        'body' => 'Please reply to bob@example.invalid or +44 7700 900000',
    ])->assertOk();

    Gaze::assertCleaned('Please reply to bob@example.invalid or +44 7700 900000');
    Gaze::assertRestored();
});
```

To control what the fake returns, pass handlers:

```php
use Naoray\GazeLaravel\EncryptedBlob;
use Naoray\GazeLaravel\GazeSession;

Gaze::fake(
    cleanHandler: fn (string $text): GazeSession => new GazeSession(
        cleanText: 'Please reply to <Email_0> or <Phone_0>.',
        ciphertext: EncryptedBlob::wrap('stub-blob'),
        detections: 2,
    ),
    restoreHandler: fn (GazeSession $s, string $text): string
        => str_replace(['<Email_0>', '<Phone_0>'], ['bob@example.invalid', '+44 7700 900000'], $text),
);
```

### Useful assert methods

| Method | Asserts |
|---|---|
| `Gaze::assertCleaned($text)` | `clean()` was called (optionally with specific text) |
| `Gaze::assertRestored()` | `restore()` was called at least once |
| `Gaze::assertCleanCount(int $n)` | exact number of `clean()` calls |
| `Gaze::assertNothingCleaned()` | `clean()` was never called |

### Audit fake

```php
it('purges audit rows older than 90 days', function () {
    Gaze::fake();

    Gaze::audit()->purge()->before(now()->subDays(90))->execute();

    Gaze::assertAuditPurged(now()->subDays(90));
    Gaze::assertAuditPurgeCount(1);
});
```

## Next steps

- **Full API reference** — [README.md](../README.md): all config keys, exception taxonomy, queue integration, and Telescope/Pulse exclusion patterns.
- **Audit surface** — [docs/audit.md](audit.md): purge workflow, atomicity caveats, and the upcoming `query`/`export` verbs.
- **Security model** — see the "Security" and "Telemetry" sections in README.md for the no-real-PII-in-logs contract and session blob handling.
