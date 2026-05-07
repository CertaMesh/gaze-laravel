# gaze-laravel

Laravel adapter for the [`gaze`](https://github.com/piinuts/gaze) CLI contract.

`gaze-laravel` wraps the pipe-mode `gaze clean` / `gaze restore` workflow for Laravel apps. It sends raw UTF-8 text to `clean`, keeps the returned `session_blob` encrypted at rest, and restores model output through `restore` with typed exceptions and queue-aware retry helpers.

Use it when you need to:

- send pseudonymized text to an LLM instead of raw PII;
- restore model output back into owner-side text;
- keep encrypted session blobs out of logs and public component state;
- classify subprocess failures into caller, config, integrity, and infra buckets.

**New here?** Start with the [getting started guide](./docs/getting-started.md).

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

The package ships as a Composer plugin (`Naoray\GazeLaravel\Install\GazeInstallerPlugin`). On first install your Composer will ask whether to allow it — pick `y` to enable automatic binary download, or pick `n` and provide `GAZE_BINARY` yourself.

Installer env overrides:

- `GAZE_SKIP_BINARY_DOWNLOAD=1` — skip the download entirely when you manage the binary out-of-band.
- `GAZE_VERSION=x.y.z` — install a different gaze version than the one pinned by this release; use cautiously because the pinned version is contract-tested.
- `GAZE_RELEASE_BASE=https://...` — release base override for fixture or staging release hosts.

See [Configuration](./docs/configuration.md) for the full env var + config publishing reference.

## Usage

```php
use Naoray\GazeLaravel\Gaze;

$session = $gaze->clean($request->string('body'));
$reply = $llm->complete($session->cleanText);

return $gaze->restore($session, $reply);
```

See [Exceptions](./docs/exceptions.md) for the exit bucket and typed exception reference.

See [Testing](./docs/testing.md) for fakes, assertions, and integration-test setup.

## Documentation

- [Getting started](./docs/getting-started.md)
- [Configuration](./docs/configuration.md)
- [Architecture](./docs/architecture.md)
- [Audit query / export](./docs/audit.md)
- [Blob lifecycle](./docs/blob-lifecycle.md)
- [NER install](./docs/ner.md)
- [Livewire integration](./docs/livewire.md)
- [Conversational-loop patterns](./docs/conversational-loop.md)
- [Operations](./docs/operations.md)
- [Retry discipline](./docs/retry.md)
- [Diagnostics](./docs/diagnostics.md)
- [Exceptions](./docs/exceptions.md)
- [Queue integration](./docs/queue.md)
- [Security model](./docs/security.md)
- [Testing](./docs/testing.md)

## Security

Session blobs are encrypted at rest with Laravel's encrypter, keyed by `GAZE_ENCRYPTION_KEY` or `APP_KEY`.
Only pseudonymized `$session->cleanText` should cross the model boundary; restore happens owner-side.
See [Security model](./docs/security.md) for guarantees, responsibilities, and compliance boundaries.

## License

Apache-2.0 — see [LICENSE](./LICENSE).
