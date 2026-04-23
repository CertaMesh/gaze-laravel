# gaze-laravel

Laravel adapter for the [Gaze](https://github.com/Naoray/gaze) PII sanitization binary (`ghostwriter`).

> **Status:** pre-release. The adapter scaffolding is complete and exercised by unit + feature tests. `v0.1.0` ships once the `ghostwriter` release pipeline produces signed, checksummed binaries — see [`docs/PLAN.md`](docs/PLAN.md) for the full design.

`gaze-laravel` is a thin wrapper around the `ghostwriter` binary (Rust, pipe mode). It lets a Laravel app feed user-facing text through Gaze so PII is replaced by placeholders before an LLM sees it, and restored on the way back.

## Requirements

- PHP **^8.2** (readonly classes)
- Laravel **^11.0 || ^12.0**
- The `ghostwriter` binary — auto-downloaded on `composer install` (opt-in), or pointed at via `GAZE_BINARY`.

## Install

```bash
composer require naoray/gaze-laravel
```

Publish the config:

```bash
php artisan vendor:publish --tag=gaze-config
```

### Auto-downloading the binary

Wire the post-install hook into your **application's** `composer.json`:

```json
{
    "scripts": {
        "post-install-cmd": ["Naoray\\GazeLaravel\\Install\\BinaryInstaller::postInstall"],
        "post-update-cmd": ["Naoray\\GazeLaravel\\Install\\BinaryInstaller::postInstall"]
    }
}
```

The installer downloads `ghostwriter-v<ver>-<target>.tar.gz` from the canonical release over HTTPS and verifies the `sha256` against `SHA256SUMS` from the same tagged release. A mismatch aborts the install without leaving a partial artifact.

Environment toggles:

| Var | Meaning |
|---|---|
| `GAZE_SKIP_BINARY_DOWNLOAD=1` | Skip the download step. Use in air-gapped CI / Docker multi-stage builds that pre-copy the binary. |
| `GAZE_BINARY_VERSION=x.y.z` | Pin a different version than the one shipped by this release. Unsupported — for upstream testing only. |
| `GAZE_BINARY=/abs/path/to/ghostwriter` | Explicit absolute path. Wins over vendor-bin and `$PATH`. |

## Configuration

[`config/gaze.php`](config/gaze.php) exposes:

```php
return [
    'binary' => env('GAZE_BINARY'),
    'timeout_seconds' => (int) env('GAZE_TIMEOUT', 30),
    'fail_closed' => filter_var(env('GAZE_FAIL_CLOSED', true), FILTER_VALIDATE_BOOL),
    'blob_encryption_key' => env('GAZE_ENCRYPTION_KEY'),
];
```

- `binary` — absolute path or name. Resolution order: `GAZE_BINARY` → `vendor/bin/ghostwriter` → `$PATH`.
- `timeout_seconds` — hard ceiling per invocation. A hung `ghostwriter` is killed rather than blocking a worker.
- `fail_closed` — when `true`, any failure raises a `GazeException` and the caller must treat it as "no LLM response produced". **Half-anonymized output is worse than no output.** Do not disable in production.
- `blob_encryption_key` — optional base64 32-byte key dedicated to session-blob encryption. Unset falls back to `APP_KEY` via the default Crypt facade. Must be a valid 32-byte key or the container boot fails loudly.

## Usage

```php
use Naoray\GazeLaravel\Context;
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\EncryptedBlob;

public function draft(Gaze $gaze, EncryptedBlob $blob, Email $email): string
{
    $session = $gaze->sanitize(
        $email->body,
        new Context(
            customerName: $email->customer_name,
            customerEmail: $email->customer_email,
        ),
    );

    // $session->cleanText   — safe to send to an LLM
    // $session->sessionBlob — plaintext blob Ghostwriter returns
    // $session->placeholders, $session->warnings

    $cipher = $blob->wrap($session->sessionBlob); // encrypt before it travels

    // ... call the LLM with $session->cleanText, receive $llmReply ...

    $restored = $gaze->restore($llmReply, $blob->unwrap($cipher));

    return $restored->text;
}
```

### Livewire note — do not ride the blob on the wire

```php
class DraftReply extends Component
{
    public int $emailId;                              // scalar only — safe to serialize to browser

    // DO NOT add public $sessionBlob. Livewire serializes public props to the
    // client and back; the blob would ride the network in plaintext.

    public function generate(Gaze $gaze, EncryptedBlob $blob): void
    {
        $session = $gaze->sanitize(
            $this->email->body,
            new Context(customerName: $this->email->customer_name),
        );

        DraftEmailReplyJob::dispatch(
            emailId: $this->emailId,
            cleanPrompt: $session->cleanText,
            encryptedSessionBlob: $blob->wrap($session->sessionBlob),
        );

        unset($session);
    }
}
```

**Rule:** the session blob lives only in a method scope or an encrypted job payload. Never a Livewire public property, never `Session::flash`, never a cookie.

## Security posture

- **Fail-closed by default.** Any `ghostwriter` failure raises a subclass of `GazeException`. The caller must treat this as "no LLM response produced". A half-anonymized prompt is worse than none.
- **Stderr never leaves the wrapper.** Only the SHA-256 hash of stderr and the exit code are included in exception messages or log lines. The raw bytes are inspected inside `Gaze::mapFailure` for best-effort error-kind detection and then discarded, so a sanitizer bug that leaks PII into stderr cannot resurface in Telescope / Sentry / `failed_jobs.exception`.
- **Binary integrity.** The installer downloads over HTTPS and verifies `sha256` against the `SHA256SUMS` from the same tagged release. Mismatch = abort.
- **Encryption-in-flight.** The `EncryptedBlob` wrapper uses Laravel's AEAD envelope (`encryptString`/`decryptString`). Tampering is detected via HMAC — a tampered ciphertext throws `DecryptException` on `unwrap()`.

## Testing

```bash
composer test             # pest: Unit + Feature + Install suites
composer analyse          # phpstan level 8
composer format           # pint
```

Integration tests (`tests/Integration/`) skip when `GAZE_BINARY` is unset. To run them, build `ghostwriter` locally and point at it:

```bash
cargo build -p ghostwriter --manifest-path /path/to/gaze/Cargo.toml
GAZE_BINARY=/path/to/gaze/target/debug/ghostwriter vendor/bin/pest --testsuite Integration
```

### Faking in tests

```php
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\Testing\FakeGaze;

$this->app->instance(Gaze::class, new FakeGaze());
```

`FakeGaze` is a drop-in subclass of the real `Gaze` — type hints keep working without introducing an interface.

## Artisan

- `php artisan gaze:check` — verifies the binary resolves, runs `ghostwriter --version`, validates the optional dedicated encryption key, and prints a status block. Non-zero exit on failure.
- `php artisan gaze:canary` — end-to-end round-trip canary against a fixed marker text. Fails loud if PII leaks into clean text or fails to reappear after `restore`.

## License

Apache-2.0 — matches the upstream `gaze` project.
