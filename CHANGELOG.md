# Changelog

All notable changes to `naoray/gaze-laravel` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial scaffold covering the full surface described in [`docs/PLAN.md`](docs/PLAN.md):
  - `Gaze::sanitize` and `Gaze::restore` wrapping the `ghostwriter` binary over pipe mode (stdin/stdout JSON).
  - `Context`, `GazeSession`, `RestoredText` readonly DTOs.
  - `BinaryResolver` with `GAZE_BINARY` → `vendor/bin/ghostwriter` → `$PATH` precedence.
  - Stderr-safe exception mapping (`GazeException` hierarchy). Only SHA-256 + exit code escape.
  - `GazeServiceProvider`, `Gaze` facade, optional dedicated encryption key.
  - `EncryptedBlob` wrapper using Laravel's AEAD envelope (`encryptString`/`decryptString`).
  - Artisan `gaze:check` and `gaze:canary` commands.
  - `FakeGaze` testing helper.
  - `Contracts\ContextResolver` interface — contract only, no default implementation. Consumers bind one resolver per domain to translate app-specific sources (Eloquent models, DTOs, queue payloads) into `Context`.
  - `BinaryInstaller::postInstall` Composer hook: HTTPS-only download, `sha256` verification against `SHA256SUMS` from the matching release tag, skip-env + short-circuit on idempotent reinstalls.
- Unit + Feature + Install test suites. Integration suite skips when `GAZE_BINARY` is unset.

### Security

- Raw stderr from `ghostwriter` is never interpolated into exception messages or log lines; only the SHA-256 hash and exit code escape the wrapper.
- Binary installer refuses non-HTTPS release bases and aborts on `sha256` mismatch without leaving a partial artifact.

[Unreleased]: https://github.com/Naoray/gaze-laravel/compare/HEAD...HEAD
