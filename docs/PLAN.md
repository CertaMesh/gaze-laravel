# `naoray/gaze-laravel` — Implementation Plan

**Status:** planning. Separate repo to be created: `naoray/gaze-laravel`. Target Packagist name: `naoray/gaze-laravel`.

This document is self-contained. A fresh session can implement the package from this doc alone, without reading the chat history that produced it.

---

## Context

`gaze-laravel` is the Laravel adapter for the Gaze project. It is a thin wrapper around the `ghostwriter` Rust binary (v0.1 pipe mode) which lives in the [`gaze`](https://github.com/worka-ai/gaze) repo under `crates/ghostwriter/`. The binary reads a `SanitizeRequest` / `RestoreRequest` JSON on stdin and writes a `SanitizeResponse` / `RestoreResponse` JSON on stdout.

The existing roadmap doc `docs/roadmap/v0.3/laravel.md` (in the gaze repo) describes a Laravel integration against a future `gaze clean` / `gaze restore` CLI that does not yet exist. This adapter targets the **currently shipping** `ghostwriter` binary instead, not the speculative v0.3 surface. The security rules, encryption posture, and failure-mode table from that roadmap doc are reused verbatim where applicable.

Separation of concerns: the Rust binary is owned by another contributor and evolves in the `gaze` repo. `gaze-laravel` only wraps it.

## Decisions (locked)

| Decision | Value |
|---|---|
| Vendor | `naoray` |
| Packagist name | `naoray/gaze-laravel` |
| Repo location | **Separate GitHub repo**, not a subdir of `gaze` |
| Laravel-only | Yes. No plain-PHP abstraction in v0.1. Extraction later if needed. |
| PHP floor | `^8.2` (readonly classes) |
| Laravel floor | `^11.0 \|\| ^12.0` |
| Binary delivery | **Auto-download on composer post-install** (Option B). Opt-in via consumer's `composer.json` scripts hook. |
| Binary name | `ghostwriter` (real v0.1 name), overridable via `GAZE_BINARY` env. |

## Prerequisites (not part of this package)

These live in the `gaze` repo and are another contributor's work. `gaze-laravel` cannot ship usefully until they exist, but the PHP package scaffold can be built ahead of them.

1. GitHub Actions release workflow that builds `ghostwriter` for:
   - `aarch64-apple-darwin`
   - `x86_64-apple-darwin`
   - `x86_64-unknown-linux-gnu`
   - `aarch64-unknown-linux-gnu`
   - `x86_64-unknown-linux-musl`
2. Per-release artifacts: `ghostwriter-v<ver>-<target>.tar.gz` containing a single `ghostwriter` executable.
3. `SHA256SUMS` file published with each release covering all tarballs.
4. Release tags of shape `ghostwriter-v<semver>` so the PHP installer can target them deterministically.

Until these exist, integration tests in `gaze-laravel` use a locally built binary (`cargo build -p ghostwriter`) via a `GAZE_BINARY` env override.

## Repository layout

```
gaze-laravel/
├── .github/workflows/
│   ├── tests.yml                    phpunit matrix over PHP + Laravel versions
│   └── static-analysis.yml          phpstan, pint
├── composer.json
├── phpunit.xml.dist
├── phpstan.neon.dist
├── pint.json
├── README.md
├── CHANGELOG.md
├── LICENSE                          Apache-2.0 (matches gaze)
├── config/
│   └── gaze.php                     published to consumer's config/
├── src/
│   ├── Gaze.php                     core service
│   ├── Context.php                  readonly DTO
│   ├── GazeSession.php              sanitize result DTO
│   ├── RestoredText.php             restore result DTO
│   ├── GazeServiceProvider.php      auto-discovered
│   ├── Facades/
│   │   └── Gaze.php
│   ├── EncryptedBlob.php            Crypt wrappers
│   ├── Console/
│   │   ├── CheckCommand.php         gaze:check
│   │   └── CanaryCommand.php        gaze:canary
│   ├── Exceptions/
│   │   ├── GazeException.php
│   │   ├── GazeBinaryMissingException.php
│   │   ├── GazeTimeoutException.php
│   │   ├── GazeSanitizeFailedException.php
│   │   ├── GazeRestoreFailedException.php
│   │   ├── GazeUnknownTokenException.php
│   │   └── GazeBlobExpiredException.php
│   ├── Install/
│   │   └── BinaryInstaller.php      composer post-install hook
│   └── Testing/
│       └── FakeGaze.php             in-memory impl for phpunit
└── tests/
    ├── TestCase.php                 orchestra/testbench base
    ├── Unit/
    │   ├── ContextTest.php
    │   ├── GazeSessionTest.php
    │   └── EncryptedBlobTest.php
    ├── Feature/
    │   ├── ServiceProviderTest.php
    │   ├── ConfigPublishTest.php
    │   ├── FakeGazeTest.php
    │   └── ExceptionMappingTest.php
    ├── Integration/
    │   ├── SanitizeRoundTripTest.php
    │   └── CanaryCommandTest.php
    └── Install/
        └── BinaryInstallerTest.php  offline tests only — verify detection, checksum, extraction with fixtures
```

## composer.json

```json
{
    "name": "naoray/gaze-laravel",
    "description": "Laravel adapter for the Gaze PII sanitization binary.",
    "license": "Apache-2.0",
    "keywords": ["laravel", "gdpr", "pii", "anonymization", "llm", "ai"],
    "require": {
        "php": "^8.2",
        "ext-json": "*",
        "ext-phar": "*",
        "illuminate/contracts": "^11.0|^12.0",
        "illuminate/console": "^11.0|^12.0",
        "illuminate/encryption": "^11.0|^12.0",
        "illuminate/process": "^11.0|^12.0",
        "illuminate/support": "^11.0|^12.0",
        "composer-plugin-api": "^2.0"
    },
    "require-dev": {
        "orchestra/testbench": "^9.0|^10.0",
        "phpunit/phpunit": "^11.0",
        "phpstan/phpstan": "^1.11",
        "laravel/pint": "^1.17"
    },
    "autoload": {
        "psr-4": {
            "Naoray\\GazeLaravel\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Naoray\\GazeLaravel\\Tests\\": "tests/"
        }
    },
    "extra": {
        "laravel": {
            "providers": ["Naoray\\GazeLaravel\\GazeServiceProvider"],
            "aliases": {"Gaze": "Naoray\\GazeLaravel\\Facades\\Gaze"}
        }
    },
    "scripts": {
        "test": "phpunit",
        "analyse": "phpstan analyse",
        "format": "pint"
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

## config/gaze.php

```php
<?php

return [
    /*
     * Absolute path or executable name for the ghostwriter binary.
     * Defaults to the auto-downloaded copy in vendor/bin/ghostwriter when present,
     * otherwise falls back to the first "ghostwriter" on $PATH.
     */
    'binary' => env('GAZE_BINARY'),

    /*
     * Hard ceiling on any single ghostwriter invocation. A hung process must be
     * killed rather than tying up a worker.
     */
    'timeout_seconds' => (int) env('GAZE_TIMEOUT', 30),

    /*
     * When true, any ghostwriter failure raises a GazeException and the caller
     * must treat that as "no LLM response produced". Half-anonymized output is
     * worse than no output. Do not set to false in production.
     */
    'fail_closed' => filter_var(env('GAZE_FAIL_CLOSED', true), FILTER_VALIDATE_BOOL),

    /*
     * Optional dedicated base64-encoded 32-byte key for session-blob encryption.
     * When unset, EncryptedBlob falls back to Laravel's default Crypt facade
     * (keyed on APP_KEY). When set, the key MUST be valid or boot fails loudly.
     */
    'blob_encryption_key' => env('GAZE_ENCRYPTION_KEY'),
];
```

Binary path resolution order, evaluated lazily at first `Gaze` method call:

1. Explicit `GAZE_BINARY` env (wins always).
2. `base_path('vendor/bin/ghostwriter')` if the file exists and is executable.
3. `ghostwriter` from `$PATH` (`exec('which ghostwriter')`).
4. `GazeBinaryMissingException` if none resolve.

## Core API

```php
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\Context;

$session = $gaze->sanitize(
    text: $email->body,
    context: new Context(
        customerName: 'Krishan Koenig',
        customerEmail: 'k@example.com',
    ),
);
// $session->cleanText, $session->sessionBlob, $session->placeholders, $session->warnings

$restored = $gaze->restore($llmReply, $session->sessionBlob);
// $restored->text
```

### `Gaze` class

```php
<?php

namespace Naoray\GazeLaravel;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Log;
use Naoray\GazeLaravel\Exceptions\GazeBinaryMissingException;
use Naoray\GazeLaravel\Exceptions\GazeBlobExpiredException;
use Naoray\GazeLaravel\Exceptions\GazeRestoreFailedException;
use Naoray\GazeLaravel\Exceptions\GazeSanitizeFailedException;
use Naoray\GazeLaravel\Exceptions\GazeTimeoutException;
use Naoray\GazeLaravel\Exceptions\GazeUnknownTokenException;

class Gaze
{
    public function __construct(
        private readonly BinaryResolver $resolver,
        private readonly ProcessFactory $process,
        private readonly int $timeoutSeconds,
    ) {}

    public function sanitize(string $text, ?Context $context = null): GazeSession
    {
        $payload = ['text' => $text];
        if ($context !== null) {
            $payload['context'] = $context->toArray();
        }

        $result = $this->run('sanitize', $payload, GazeSanitizeFailedException::class);

        $decoded = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);

        return new GazeSession(
            cleanText: $decoded['clean_text'],
            sessionBlob: $decoded['session_blob'],
            placeholders: $decoded['metadata']['placeholders'] ?? [],
            warnings: $decoded['warnings'] ?? [],
        );
    }

    public function restore(string $text, string $sessionBlob): RestoredText
    {
        $result = $this->run(
            'restore',
            ['text' => $text, 'session_blob' => $sessionBlob],
            GazeRestoreFailedException::class,
        );

        $decoded = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);

        return new RestoredText(
            text: $decoded['restored_text'],
            warnings: $decoded['warnings'] ?? [],
        );
    }

    /**
     * @param  class-string<\Naoray\GazeLaravel\Exceptions\GazeException>  $failureClass
     */
    private function run(string $subcommand, array $payload, string $failureClass): ProcessResult
    {
        $binary = $this->resolver->resolve();
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $result = $this->process
            ->timeout($this->timeoutSeconds)
            ->input($json)
            ->run([$binary, $subcommand]);

        if ($result->successful()) {
            return $result;
        }

        throw $this->mapFailure($subcommand, $result, $failureClass);
    }

    private function mapFailure(string $stage, ProcessResult $result, string $fallbackClass): \Throwable
    {
        $stderr = $result->errorOutput() ?: '';
        $stderrHash = hash('sha256', $stderr);
        $exitCode = $result->exitCode() ?? -1;

        Log::warning("gaze {$stage} failed", [
            'exit_code' => $exitCode,
            'stderr_sha256' => $stderrHash,
        ]);

        // Map known error kinds by best-effort stderr tag inspection.
        // The Rust side's stderr envelope is currently anyhow::Error text; this
        // mapping is opportunistic until the binary emits structured errors.
        if (str_contains($stderr, 'UnknownToken')) {
            return new GazeUnknownTokenException(
                "gaze {$stage} unknown token (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            );
        }

        if (str_contains($stderr, 'BlobExpired')) {
            return new GazeBlobExpiredException(
                "gaze {$stage} blob expired (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            );
        }

        if ($result->failed() && str_contains(strtolower($stderr), 'timed out')) {
            return new GazeTimeoutException(
                "gaze {$stage} timed out (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            );
        }

        return new $fallbackClass(
            "gaze {$stage} failed (exit={$exitCode}, stderr_sha256={$stderrHash})",
            $exitCode,
            $stderrHash,
        );
    }
}
```

**Stderr rule (load-bearing):** the raw `$stderr` value is used only for tag matching inside `mapFailure` and never interpolated into exception messages, log lines, or return values. Only the SHA-256 and exit code escape. This prevents a chain where the Rust binary emits a sanitizer bug into stderr, the PHP wrapper surfaces it, and it ends up in Telescope / Sentry / `failed_jobs.exception`.

If future auditing shows the tag-matching heuristic ever leaked stderr content by mistake, remove the heuristic and collapse all failures to the generic fallback class. The mapping is convenience, not correctness.

### `BinaryResolver`

```php
<?php

namespace Naoray\GazeLaravel;

use Naoray\GazeLaravel\Exceptions\GazeBinaryMissingException;

final class BinaryResolver
{
    public function __construct(
        private readonly ?string $explicitPath,
        private readonly string $vendorBinPath,
    ) {}

    public function resolve(): string
    {
        if ($this->explicitPath !== null && $this->explicitPath !== '') {
            return $this->explicitPath;
        }

        if (is_executable($this->vendorBinPath)) {
            return $this->vendorBinPath;
        }

        $which = @shell_exec('command -v ghostwriter 2>/dev/null');
        if (is_string($which) && ($trimmed = trim($which)) !== '') {
            return $trimmed;
        }

        throw new GazeBinaryMissingException(
            'ghostwriter binary not found. Set GAZE_BINARY, install the binary, '
            . 'or add the naoray/gaze-laravel post-install-cmd to composer.json.'
        );
    }
}
```

## DTOs

All readonly, final, scalar-only. `Context::toArray()` emits snake_case to match the Rust `SanitizeRequest.context` schema exactly.

```php
<?php

namespace Naoray\GazeLaravel;

final readonly class Context
{
    public function __construct(
        public ?string $customerName = null,
        public ?string $customerEmail = null,
        public ?string $customerPhone = null,
    ) {}

    public function toArray(): array
    {
        return array_filter(
            [
                'customer_name' => $this->customerName,
                'customer_email' => $this->customerEmail,
                'customer_phone' => $this->customerPhone,
            ],
            fn ($v) => $v !== null,
        );
    }
}

final readonly class GazeSession
{
    /**
     * @param  list<string>  $placeholders
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $cleanText,
        public string $sessionBlob,
        public array $placeholders,
        public array $warnings,
    ) {}
}

final readonly class RestoredText
{
    /** @param  list<string>  $warnings */
    public function __construct(
        public string $text,
        public array $warnings,
    ) {}
}
```

## Exceptions

Base class carries `exitCode` and `stderrHash` as read-only attributes. No accessor returns stderr bytes because the class never holds them.

```php
<?php

namespace Naoray\GazeLaravel\Exceptions;

class GazeException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $exitCode,
        public readonly string $stderrHash,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $exitCode, $previous);
    }
}
```

Subclasses exist only as tag types: `GazeBinaryMissingException`, `GazeTimeoutException`, `GazeSanitizeFailedException`, `GazeRestoreFailedException`, `GazeUnknownTokenException`, `GazeBlobExpiredException`. No behavior difference.

## Service provider

```php
<?php

namespace Naoray\GazeLaravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Encryption\Encrypter;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\ServiceProvider;
use Naoray\GazeLaravel\Console\CanaryCommand;
use Naoray\GazeLaravel\Console\CheckCommand;

class GazeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/gaze.php', 'gaze');

        $this->app->singleton(BinaryResolver::class, function (Application $app) {
            return new BinaryResolver(
                explicitPath: $app['config']->get('gaze.binary'),
                vendorBinPath: $app->basePath('vendor/bin/ghostwriter'),
            );
        });

        $this->app->singleton(Gaze::class, function (Application $app) {
            return new Gaze(
                resolver: $app->make(BinaryResolver::class),
                process: $app->make(ProcessFactory::class),
                timeoutSeconds: (int) $app['config']->get('gaze.timeout_seconds', 30),
            );
        });

        $this->app->singleton('gaze.encrypter', function (Application $app) {
            $raw = $app['config']->get('gaze.blob_encryption_key');
            if ($raw === null || $raw === '') {
                return $app->make('encrypter');
            }

            $decoded = base64_decode($raw, true);
            if ($decoded === false || strlen($decoded) !== 32) {
                throw new \RuntimeException(
                    'GAZE_ENCRYPTION_KEY must be base64-encoded 32 bytes.'
                );
            }

            return new Encrypter($decoded, 'AES-256-CBC');
        });

        $this->app->singleton(EncryptedBlob::class, function (Application $app) {
            return new EncryptedBlob($app->make('gaze.encrypter'));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/gaze.php' => config_path('gaze.php'),
            ], 'gaze-config');

            $this->commands([
                CheckCommand::class,
                CanaryCommand::class,
            ]);
        }
    }
}
```

## `EncryptedBlob`

```php
<?php

namespace Naoray\GazeLaravel;

use Illuminate\Contracts\Encryption\Encrypter;

final class EncryptedBlob
{
    public function __construct(private readonly Encrypter $encrypter) {}

    public function wrap(string $plaintextBlob): string
    {
        return $this->encrypter->encryptString($plaintextBlob);
    }

    public function unwrap(string $ciphertext): string
    {
        return $this->encrypter->decryptString($ciphertext);
    }
}
```

Tamper detection is owned by Laravel's AEAD envelope (`decryptString` throws `DecryptException` on HMAC mismatch). Ghostwriter does not sign the blob. This is intentional and matches `docs/roadmap/v0.3/laravel.md` §"Encryption-in-Flight — mandatory".

## Artisan commands

### `gaze:check`

Verifies the binary resolves, runs `ghostwriter --version`, validates the optional dedicated encryption key shape, and prints a status block. Non-zero exit on any failure so it wires into CI and `php artisan about`.

```
$ php artisan gaze:check
binary       vendor/bin/ghostwriter
version      ghostwriter 0.1.0
encrypter    gaze.encrypter (dedicated key)
status       OK
```

### `gaze:canary`

End-to-end round trip using a fixed marker derived from `docs/roadmap/v0.3/laravel.md` §"Testing Strategy" #1. Fails loud if the marker leaks into clean text or fails to return in restored text.

```
$ php artisan gaze:canary
[1/3] sanitize           OK (4 placeholders)
[2/3] marker-absent      OK
[3/3] restore+marker     OK
status                   PASS
```

Implementation detail: use the testbench-style fixture text. Not a replacement for application-level canary tests, but a ship-readiness smoke test operators can run post-deploy.

## `FakeGaze`

Drop-in for tests. Not an interface (the real `Gaze` is concrete Laravel-only per decision). Tests bind via `$this->app->instance(Gaze::class, new FakeGaze(...))`.

```php
<?php

namespace Naoray\GazeLaravel\Testing;

use Naoray\GazeLaravel\Context;
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\GazeSession;
use Naoray\GazeLaravel\RestoredText;

final class FakeGaze extends Gaze
{
    private array $sanitizeCalls = [];
    private array $restoreCalls = [];

    public function __construct(
        private readonly ?\Closure $sanitizeHandler = null,
        private readonly ?\Closure $restoreHandler = null,
    ) {
        // Deliberately skip parent constructor — fake never invokes process.
    }

    public function sanitize(string $text, ?Context $context = null): GazeSession
    {
        $this->sanitizeCalls[] = compact('text', 'context');
        if ($this->sanitizeHandler) {
            return ($this->sanitizeHandler)($text, $context);
        }
        return new GazeSession(
            cleanText: str_replace((string) ($context?->customerName ?? ''), '<CUSTOMER_NAME>', $text),
            sessionBlob: base64_encode(json_encode(['customer_name' => $context?->customerName])),
            placeholders: $context?->customerName ? ['<CUSTOMER_NAME>'] : [],
            warnings: [],
        );
    }

    public function restore(string $text, string $sessionBlob): RestoredText
    {
        $this->restoreCalls[] = compact('text', 'sessionBlob');
        if ($this->restoreHandler) {
            return ($this->restoreHandler)($text, $sessionBlob);
        }
        $map = json_decode(base64_decode($sessionBlob), true) ?: [];
        $name = $map['customer_name'] ?? '';
        return new RestoredText(
            text: str_replace('<CUSTOMER_NAME>', $name, $text),
            warnings: [],
        );
    }

    public function sanitizeCalls(): array { return $this->sanitizeCalls; }
    public function restoreCalls(): array { return $this->restoreCalls; }
}
```

Extending the concrete class means PHPStan-level drop-in compatibility with code hinting `Gaze $gaze`, without introducing an interface that the real implementation has to implement and maintain long-term.

## Composer post-install binary installer

**Security-critical.** Installer downloads a native binary over the network. Rules:

- HTTPS only. No HTTP fallback.
- Canonical release host: `https://github.com/worka-ai/gaze/releases/download/ghostwriter-v<ver>/`.
- Download tarball + `SHA256SUMS` from the same tagged release.
- Compute local `hash_file('sha256', $tarPath)`, compare against the entry in `SHA256SUMS` for the exact filename. Mismatch = delete tarball, exit non-zero.
- Optional: verify `SHA256SUMS.asc` using a public key bundled in the package (`src/Install/ghostwriter-release.pub`). If upstream ships signatures, do it. If not, proceed with sha256 alone and document the gap.
- Extract via `PharData` (`tar.gz` support native, no shell exec on downloaded bytes).
- `chmod 0755` on the extracted `ghostwriter` file.
- Honor `GAZE_SKIP_BINARY_DOWNLOAD=1` env: skip entirely. Used for air-gapped CI and Docker multi-stage builds that pre-copy the binary.
- If `vendor/bin/ghostwriter --version` already matches the pinned version, short-circuit (idempotent reinstalls).
- `GAZE_BINARY_VERSION` env override for advanced users pinning a different version for testing. Document as unsupported.

```php
<?php

namespace Naoray\GazeLaravel\Install;

use Composer\Script\Event;

final class BinaryInstaller
{
    /** Pinned per gaze-laravel release. Bumped intentionally. */
    public const PINNED_VERSION = '0.1.0';

    private const RELEASE_BASE = 'https://github.com/worka-ai/gaze/releases/download';

    public static function postInstall(Event $event): void
    {
        if (getenv('GAZE_SKIP_BINARY_DOWNLOAD') === '1') {
            $event->getIO()->write('<comment>gaze-laravel: skipping binary download (GAZE_SKIP_BINARY_DOWNLOAD=1)</comment>');
            return;
        }

        $version = getenv('GAZE_BINARY_VERSION') ?: self::PINNED_VERSION;
        $binDir = $event->getComposer()->getConfig()->get('bin-dir');
        $target = self::detectTarget($event);
        if ($target === null) {
            $event->getIO()->writeError('<error>gaze-laravel: unsupported platform, please install ghostwriter manually and set GAZE_BINARY</error>');
            return; // do not fail composer install
        }

        $binPath = $binDir . DIRECTORY_SEPARATOR . 'ghostwriter';
        if (self::alreadyInstalled($binPath, $version)) {
            $event->getIO()->write("<info>gaze-laravel: ghostwriter v{$version} already installed</info>");
            return;
        }

        $tag = "ghostwriter-v{$version}";
        $asset = "ghostwriter-v{$version}-{$target}.tar.gz";
        $assetUrl = self::RELEASE_BASE . "/{$tag}/{$asset}";
        $sumsUrl = self::RELEASE_BASE . "/{$tag}/SHA256SUMS";

        $tmpDir = sys_get_temp_dir();
        $tarPath = $tmpDir . DIRECTORY_SEPARATOR . $asset;
        $sumsPath = $tmpDir . DIRECTORY_SEPARATOR . "SHA256SUMS-{$version}";

        try {
            self::download($assetUrl, $tarPath);
            self::download($sumsUrl, $sumsPath);
            self::verifyChecksum($tarPath, $sumsPath, $asset);
            self::extract($tarPath, $binDir);
            @chmod($binPath, 0755);
            $event->getIO()->write("<info>gaze-laravel: installed ghostwriter v{$version} → {$binPath}</info>");
        } catch (\Throwable $e) {
            $event->getIO()->writeError("<error>gaze-laravel: binary install failed — {$e->getMessage()}</error>");
            @unlink($binPath); // never leave partial artifact
            // Do NOT rethrow — composer install should succeed even if binary download fails.
            // Operator fixes GAZE_BINARY or runs composer install again.
        } finally {
            @unlink($tarPath);
            @unlink($sumsPath);
        }
    }

    private static function detectTarget(Event $event): ?string
    {
        $os = strtolower(PHP_OS_FAMILY); // Darwin, Linux, Windows
        $arch = strtolower(php_uname('m'));

        return match (true) {
            $os === 'darwin' && in_array($arch, ['arm64', 'aarch64'], true) => 'aarch64-apple-darwin',
            $os === 'darwin' && $arch === 'x86_64' => 'x86_64-apple-darwin',
            $os === 'linux' && $arch === 'x86_64' => 'x86_64-unknown-linux-gnu',
            $os === 'linux' && in_array($arch, ['arm64', 'aarch64'], true) => 'aarch64-unknown-linux-gnu',
            default => null,
        };
    }

    private static function alreadyInstalled(string $binPath, string $version): bool
    {
        if (!is_executable($binPath)) {
            return false;
        }
        $output = @shell_exec(escapeshellarg($binPath) . ' --version 2>/dev/null');
        return is_string($output) && str_contains($output, $version);
    }

    private static function download(string $url, string $destPath): void
    {
        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'follow_location' => 1, 'timeout' => 60],
            'https' => ['method' => 'GET', 'follow_location' => 1, 'timeout' => 60],
        ]);
        $bytes = @file_get_contents($url, false, $ctx);
        if ($bytes === false) {
            throw new \RuntimeException("download failed: {$url}");
        }
        file_put_contents($destPath, $bytes);
    }

    private static function verifyChecksum(string $tarPath, string $sumsPath, string $asset): void
    {
        $sums = file_get_contents($sumsPath);
        if ($sums === false) {
            throw new \RuntimeException('SHA256SUMS unreadable');
        }
        $expected = null;
        foreach (preg_split('/\r\n|\n/', $sums) as $line) {
            if (preg_match('/^([a-f0-9]{64})\s+\*?' . preg_quote($asset, '/') . '$/i', trim($line), $m)) {
                $expected = strtolower($m[1]);
                break;
            }
        }
        if ($expected === null) {
            throw new \RuntimeException("no checksum entry for {$asset}");
        }
        $actual = hash_file('sha256', $tarPath);
        if (!hash_equals($expected, $actual)) {
            throw new \RuntimeException("sha256 mismatch for {$asset}");
        }
    }

    private static function extract(string $tarPath, string $binDir): void
    {
        $phar = new \PharData($tarPath);
        $gzPath = substr($tarPath, 0, -3); // .tar
        $phar->decompress();
        $tar = new \PharData($gzPath);
        $tar->extractTo($binDir, null, overwrite: true);
        @unlink($gzPath);
    }
}
```

Consumer wires this in their `composer.json`:

```json
{
    "scripts": {
        "post-install-cmd": ["Naoray\\GazeLaravel\\Install\\BinaryInstaller::postInstall"],
        "post-update-cmd": ["Naoray\\GazeLaravel\\Install\\BinaryInstaller::postInstall"]
    }
}
```

README documents this opt-in step. Consider writing a Composer plugin in a later version so wiring is automatic — for v0.1 the explicit script hook is simpler and easier to audit.

## Testing plan

- **Unit** — DTOs, `EncryptedBlob`, `BinaryResolver` (with fake file fixtures).
- **Feature** — service provider registration, config merge, encrypter binding, facade resolution, exception mapping given fixture `ProcessResult` objects (use `Process::fake()` from `illuminate/process`).
- **Integration** — requires real `ghostwriter`. Build locally via `cargo build -p ghostwriter --manifest-path /path/to/gaze/Cargo.toml` and point `GAZE_BINARY` at the resulting binary. Tests skip if `getenv('GAZE_BINARY')` returns false.
- **Canary** — same text as in the gaze repo's end-to-end canary test (`tests/e2e_canary.rs`) to keep the two suites in lockstep.
- **Installer** — offline: call `postInstall` with a fake `Event` against a local fixture release directory served by `php -S` on a random port. Assert checksum mismatch causes failure, success case leaves an executable file, short-circuit path works on second call.
- **CI matrix** — PHP 8.2, 8.3, 8.4 × Laravel 11.x, 12.x. Real binary built once per workflow run, cached per `(os, ghostwriter-sha)`.

## Livewire usage note (README)

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

Rule carried from `docs/roadmap/v0.3/laravel.md` §"Operational Notes": session blob lives only in a method scope or an encrypted job payload. Never a Livewire public property, never a Livewire `Session::flash`, never a cookie.

## Repo-scaffolding checklist (for the implementation session)

Follow in order. Each step should be reviewable as a single commit.

1. `git init` the new repo at `naoray/gaze-laravel`. Add `LICENSE` (Apache-2.0), `README.md` stub, `.gitignore` (vendor, `.phpunit.cache`, `composer.lock` committed, `.idea/`, `.vscode/`).
2. `composer.json` as specified. `composer install` to generate lock.
3. Scaffold `src/` skeleton with empty classes + types (no logic). `composer dump-autoload`. PHPStan level 8 passes on empty skeleton.
4. Implement DTOs + exceptions. Unit tests for DTOs.
5. Implement `BinaryResolver` + unit tests with `vfsStream` or tmp fixtures.
6. Implement `Gaze` using `Process::fake()` to simulate subprocess. Feature tests for both methods, including failure paths.
7. Implement `GazeServiceProvider`, facade, `EncryptedBlob`. Feature tests via testbench.
8. Implement `gaze:check` and `gaze:canary` commands. Feature tests with fake binary harness.
9. Implement `FakeGaze`. Self-test: register in container and assert it satisfies `Gaze` type-hints.
10. Implement `BinaryInstaller`. Local fixture release server for tests. Honor skip-env in CI.
11. Write `README.md`: install, config, one working sample, Livewire note, security notes carried from `v0.3/laravel.md`.
12. Write `CHANGELOG.md`, tag `v0.1.0` once integration tests pass against a real `ghostwriter` binary.
13. Submit to Packagist.

## Out of scope for v0.1 of `gaze-laravel`

Explicitly deferred. Do not add in the first pass:

- MCP DB tools wrapper (different surface entirely — `gaze serve` stdio MCP).
- Persistent token mode across jobs (`v0.3` roadmap item).
- Webhook or HTTP transport. Pipe mode only.
- Batching. One sanitize = one invocation.
- Metrics middleware / Pulse card.
- A Composer plugin for automatic script wiring.

## Open items carried forward

- If the Rust binary starts emitting structured errors (e.g., `{"error":"UnknownToken",...}` on stderr), replace the `str_contains` heuristics in `Gaze::mapFailure` with exact JSON parsing. Track as a `gaze` repo issue.
- Signed checksums (`SHA256SUMS.asc`). Ship public key in `src/Install/ghostwriter-release.pub` once the release pipeline produces signatures.
- Retry/backoff for the installer download. Currently single-shot. Add when first CI flake surfaces.
