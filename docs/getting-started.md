# Getting Started with gaze-laravel

10-minute quickstart — install, configure, first clean/restore cycle, one test.

For the full API reference see [README.md](../README.md).

## 1. Install

```bash
composer require naoray/gaze-laravel
php artisan vendor:publish --tag=gaze-config
php artisan vendor:publish --tag=gaze-policy
```

The Composer plugin will prompt you to allow downloading the `gaze` binary into `vendor/bin/`. Press `y`.

## 2. Configure

Add to `.env`:

```env
GAZE_POLICY_PATH=/absolute/path/to/policy.toml
# GAZE_ENCRYPTION_KEY=base64:<32-byte-key>   # optional; falls back to APP_KEY
```

## 3. First clean/restore cycle

```php
use Naoray\GazeLaravel\Facades\Gaze;

class EmailDraftController extends Controller
{
    public function draft(Request $request): JsonResponse
    {
        $session = Gaze::clean($request->string('body'));
        // $session->cleanText is safe for your LLM — real PII replaced with tokens

        $llmReply = $this->callLlm($session->cleanText);

        $draft = Gaze::restore($session, $llmReply);
        // restore() maps tokens back to the original values from the encrypted blob

        return response()->json(['draft' => $draft]);
    }
}
```

→ [Blob lifecycle, Livewire patterns, queue jobs, conversational-loop guidance](../README.md#blob-lifecycle)

## 4. Test with fakes

```php
use Naoray\GazeLaravel\Facades\Gaze;

it('strips PII and restores the draft', function () {
    Gaze::fake();

    $this->postJson('/email/draft', ['body' => 'Call bob@example.invalid'])->assertOk();

    Gaze::assertCleaned('Call bob@example.invalid');
    Gaze::assertRestored();
});
```

→ [Full fake API and assert methods](../README.md#testing)

## Next steps

- [Config reference](../README.md#config) — all env keys and defaults
- [Exceptions](../README.md#exceptions) — exit-bucket taxonomy
- [Operations](../README.md#operations) — `gaze:check`, `gaze:doctor`, `gaze:bench`
- [Security model](../README.md#security-model) — what the adapter guarantees
- [Enabling NER](../README.md#enabling-ner) — multilingual / higher-recall detection
- [Audit surface](audit.md) — purge workflow and upcoming query/export verbs
