# gaze-laravel

Laravel adapter for the [`gaze`](https://github.com/piinuts/gaze) v0.5 CLI contract.

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

### Binary install hook

The package ships as a Composer plugin (`Naoray\GazeLaravel\Install\GazeInstallerPlugin`). On first install your Composer will ask whether to allow it — pick `y` to enable automatic binary download, or pick `n` and provide `GAZE_BINARY` yourself. The plugin downloads the pinned `gaze-<target>` binary plus its `.sha256` checksum over HTTPS into `vendor/bin/`. Pinned upstream release is `gaze` v0.5.2.

Binary resolution and install probing both use Symfony `ExecutableFinder` and `Process` — no `shell_exec`. The plugin is therefore container-, Alpine-, and `disable_functions=shell_exec`-safe.

Installer env overrides:

- `GAZE_SKIP_BINARY_DOWNLOAD=1` — skip the download entirely (use when you manage the binary out-of-band)
- `GAZE_VERSION=x.y.z` — install a different gaze version than the one pinned by this release (use cautiously; pinned version is contract-tested)
- `GAZE_GITHUB_TOKEN=ghp_...` — GitHub PAT used to fetch release assets from the upstream `piinuts/gaze` repo (see below)
- `GAZE_RELEASE_BASE=https://...` — non-production-only release base override for fixture or staging release hosts. Production installs (`APP_ENV` empty, `production`, or `prod`) ignore this override and always use the canonical `https://github.com/piinuts/gaze/releases/download` host.

#### `GAZE_GITHUB_TOKEN` — private release access

`piinuts/gaze` is currently a private repository, which means the redirect from `releases/download/<tag>/<asset>` to the signed S3 URL returns `404` for unauthenticated requests. Set `GAZE_GITHUB_TOKEN` to a fine-scoped PAT with `contents:read` on `piinuts/gaze`, and the installer switches to the GitHub API path (`/repos/.../releases/assets/<id>` with `Accept: application/octet-stream`) which honors auth.

```bash
# .env (read by Composer at install time)
GAZE_GITHUB_TOKEN=ghp_...

composer require naoray/gaze-laravel
```

Important details:

- The token is read by Composer at install time, so it must be set in the shell or `.env` **before** you run `composer require` / `composer install`. Adding it after the fact requires `composer install` to re-run.
- For CI, store the PAT as a secret and export `GAZE_GITHUB_TOKEN` in the install step.
- Required scopes: `contents:read` on the `piinuts/gaze` repo (fine-grained PAT) — equivalent to the legacy `repo` scope on classic PATs. No write scopes are needed.
- The token is sent as `Authorization: Bearer …` to `api.github.com` only. It is dropped on the redirect to the signed S3 URL (same rule as `curl --location` and `gh`), so it never leaves GitHub.
- If `GAZE_RELEASE_BASE` points at a non-github.com mirror, `GAZE_GITHUB_TOKEN` is ignored — that scenario implies you have your own auth on the mirror.

## Config

```php
return [
    'binary' => env('GAZE_BINARY', 'gaze'),
    'timeout_seconds' => (int) env('GAZE_TIMEOUT', 30),
    'policy_path' => env('GAZE_POLICY_PATH', base_path('policy.toml')),
    'max_bytes' => env('GAZE_MAX_BYTES'),
    'session_ttl_seconds' => env('GAZE_SESSION_TTL'),
    'blob_encryption_key' => env('GAZE_ENCRYPTION_KEY'),
    'audit_db_path' => env('GAZE_AUDIT_DB_PATH'),
];
```

`GAZE_ENCRYPTION_KEY` may be unset to reuse `APP_KEY`, or set to a dedicated `base64:` 32-byte key.
The adapter Encrypter cipher matches host `config('app.cipher')` (Laravel default).
Pin the host cipher explicitly if you rotate keys across deploys.

`GAZE_AUDIT_DB_PATH` enables the audit-log SQLite trail: write side via `Gaze::clean()`, read side via `Gaze::audit()->purge()` and the upcoming `query` / `export` verbs. See [docs/audit.md](docs/audit.md).

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

## Enabling NER

By default gaze-laravel runs in regex/rulepack mode. Enable named-entity recognition with:

```bash
php artisan gaze:install-ner --yes
```

This downloads the pinned Davlan mBERT NER int8 ONNX artifact set into `storage/app/gaze-ner/davlan-mbert-ner-hrl-int8/`, verifies every file against the upstream v0.5.2 `SHA256SUMS` contract, copies the packaged BIO-to-class `labels.json`, and prints the `[ner]` block to paste into `policy.toml`.

To wire `policy.toml` automatically, add `--update-policy`. Re-running the command is idempotent when artifacts already verify.

### Flags

- `--variant=int8` — only `int8` is supported in v0; other variants fail closed.
- `--dest=<abs path>` — override the model storage location.
- `--locale=de` — embed a BCP47 locale hint in the generated `[ner]` block.
- `--check` — verify an existing install without downloading.
- `--dry-run` — preview destination and policy output without writing.
- `--force` — redownload and overwrite even when the destination already verifies.
- `--update-policy` — write the `[ner]` block to `config('gaze.policy_path')`.

### CI / shared-host considerations

Set `HUGGINGFACE_TOKEN` when your host or CI network is rate-limited by HuggingFace. The token is sent as `Authorization: Bearer ...` to HuggingFace artifact requests.

Cache `storage/app/gaze-ner/davlan-mbert-ner-hrl-int8/` between CI jobs when you need NER-enabled integration tests. On locked-down hosts, fetch the artifact set from `onnx-community/bert-base-multilingual-cased-ner-hrl-ONNX`, place it at the destination, and run `php artisan gaze:install-ner --check`.

## Latency baseline / Diagnostic

`Gaze::clean()` currently invokes the upstream `gaze clean` command as a one-shot subprocess for every call. With NER enabled, every invocation loads the NER model from disk before it can return a response. This is the current CLI contract, so repeated calls are not a warm-up run: every `gaze:bench --requests=N` sample pays the full model-load cost.

Use `gaze:bench` to measure your own cold baseline:

```bash
php artisan gaze:bench --requests=10
php artisan gaze:bench --requests=10 --json
```

JSON output includes `bench_schema_version`, `mode: "cold"`, `first_ms`, percentile fields, chronological `samples_ms`, and a small environment fingerprint. For `--requests >= 1000`, samples default to `head` mode (first 100 plus last 100); use `--samples=full` or `--samples=none` when you need a different payload size.

Daemon mode is tracked upstream. Once it ships, this package will gain warm worker-pool support in a follow-up release. Until then, this command is diagnostic only: it establishes a cold-start baseline you can compare across machines, releases, or issue reports.

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

### Pre-push hook

`composer install` / `composer update` automatically points `core.hooksPath` at `.githooks`. The shipped `pre-push` hook runs `composer test` (Pest) + `composer analyse` (PHPStan) before any push — so CI failures surface locally without burning GitHub Actions minutes.

Emergency bypass for WIP-branch backups:

```sh
SKIP_HOOK=1 git push ...
```

Note: cross-version (php × laravel) matrix coverage from the previous CI workflow is dropped. Cross-version regressions will surface on next dependency bump.
