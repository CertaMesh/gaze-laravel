# gaze-laravel

[![Latest Stable Version](https://img.shields.io/packagist/v/empiretwo/gaze-laravel.svg?style=flat-square)](https://packagist.org/packages/empiretwo/gaze-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/empiretwo/gaze-laravel.svg?style=flat-square)](https://packagist.org/packages/empiretwo/gaze-laravel)
[![Tests](https://img.shields.io/github/actions/workflow/status/EmpireTwo/gaze-laravel/test.yml?branch=main&label=tests&style=flat-square)](https://github.com/EmpireTwo/gaze-laravel/actions/workflows/test.yml)
[![License](https://img.shields.io/packagist/l/empiretwo/gaze-laravel.svg?style=flat-square)](https://github.com/EmpireTwo/gaze-laravel/blob/main/LICENSE)

Laravel adapter for the [`gaze`](https://github.com/EmpireTwo/gaze) CLI contract.

`gaze-laravel` wraps the pipe-mode `gaze clean` / `gaze restore` workflow for Laravel apps. It sends raw UTF-8 text to `clean`, keeps the returned `session_blob` encrypted at rest, and restores model output through `restore` with typed exceptions and queue-aware retry helpers.

Use it when you need to:

- send pseudonymized text to an LLM instead of raw PII;
- restore model output back into owner-side text;
- keep encrypted session blobs out of logs and public component state;
- classify subprocess failures into caller, config, integrity, and infra buckets.

> **Detection modes:** Regex + rulepack runs by default. Optional NER (ONNX-backed) is an opt-in
> second install — run `php artisan gaze:install-ner` to download model artifacts. See
> [`docs/ner.md`](docs/ner.md) for trade-offs.

**New here?** Start with the [getting started guide](./docs/getting-started.md).

## Requirements

- PHP `^8.2`
- Laravel `^11.0 || ^12.0`
- The `gaze` binary on `PATH`, in `vendor/bin/gaze`, or configured via `GAZE_BINARY`

## Install

```bash
composer require empiretwo/gaze-laravel
php artisan vendor:publish --tag=gaze-config
php artisan vendor:publish --tag=gaze-policy
```

The package ships as a Composer plugin (`Naoray\GazeLaravel\Install\GazeInstallerPlugin`). On first install your Composer will ask whether to allow it — pick `y` to enable automatic binary download, or pick `n` and provide `GAZE_BINARY` yourself.

> **Non-interactive (CI) installs:** Composer 2.2+ requires plugins be allow-listed before
> they execute. Add this once before installing in CI:
>
> ```bash
> composer config allow-plugins.empiretwo/gaze-laravel true
> ```
>
> Or pre-seed `composer.json`:
>
> ```json
> "config": {
>   "allow-plugins": {
>     "empiretwo/gaze-laravel": true
>   }
> }
> ```
>
> Without this, the binary auto-download step is silently skipped on first install.

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

### Per-rule detection entries

`GazeSession::$entries` exposes each tokenized span as a readonly `Entry` DTO
(`class`, `raw`, `token`, `family`) when the upstream `gaze` CLI emits the
`entries` field on its JSON response. The array is empty for releases that do
not yet surface the field, so consumers can always iterate safely:

```php
foreach ($session->entries as $entry) {
    logger()->info('detected', [
        'class' => $entry->class,
        'token' => $entry->token,
        'family' => $entry->family,
    ]);
}

// Single-entry access:
$firstClass = $session->entries[0]->class ?? null;
```

This surface replaces the previous pattern of decrypting `$session->ciphertext`
and parsing the binary snapshot header by hand.

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

## Known limitations

- Pre-built binary auto-downloads currently cover Linux x86_64 and macOS arm64. Intel Mac users must install `gaze` from source and set `GAZE_BINARY`.
- NER model artifacts are not bundled in the Composer package. Install them explicitly with `php artisan gaze:install-ner` when you need NER-backed detection.

## License

Apache-2.0 — see [LICENSE](./LICENSE).
