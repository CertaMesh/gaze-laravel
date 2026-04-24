# Changelog

All notable changes to `naoray/gaze-laravel` are documented in this file.

## [Unreleased]

### Changed

- `BinaryInstaller::PINNED_VERSION` bumped from `0.3.0-rc.3` to `0.3.0` (stable).
- **BREAKING.** Retargeted the adapter from `ghostwriter` to the `gaze v0.3` CLI contract.
- **BREAKING.** Replaced `sanitize()` with `clean()` and removed the old `Context` envelope.
- **BREAKING.** `restore()` now consumes `GazeSession` and returns the restored string directly.
- Replaced substring-based stderr handling with a typed variant parser and dedicated exception hierarchy.
- Session blobs are now stored as encrypted `EncryptedBlob` values instead of plaintext strings.

### Added

- `policy_path`, `max_bytes`, and `session_ttl_seconds` config surfaces.
- `policy.toml.example` publish target.
- `GazeRetryPolicy`, retry marker contracts, and `GazeInfraAlert`.
- `MIGRATION-v0.3.md`.

### Removed

- `Context`
- `ContextResolver`
- `RestoredText`
- `GazeSanitizeFailedException`
- `GazeRestoreFailedException`
- legacy fail-open behavior
