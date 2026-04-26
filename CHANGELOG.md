# Changelog

All notable changes to `naoray/gaze-laravel` are documented in this file.

## [Unreleased]

### Changed

- `BinaryInstaller::PINNED_VERSION` bumped from `0.3.0` to `0.4.5` — adapter now lockstep with current upstream stable.
- `BinaryInstaller::PINNED_VERSION` bumped from `0.3.0-rc.3` to `0.3.0` (stable).
- **BREAKING.** Retargeted the adapter from `ghostwriter` to the `gaze v0.3` CLI contract.
- **BREAKING.** Replaced `sanitize()` with `clean()` and removed the old `Context` envelope.
- **BREAKING.** `restore()` now consumes `GazeSession` and returns the restored string directly.
- Replaced substring-based stderr handling with a typed variant parser and dedicated exception hierarchy.
- Session blobs are now stored as encrypted `EncryptedBlob` values instead of plaintext strings.

### Added

- `Variant::PolicyConfigDetail` and `Variant::AuditPurgeIso8601` enum cases for upstream v0.4.5 parity, plus matching `GazePolicyConfigDetailException` and `GazeAuditPurgeIso8601Exception` typed exceptions and `Gaze::buildException` arms.
- `tests/Contract/VariantContractTest.php` — fixture-based parity guard asserting every PHP `Variant` matches upstream `crates/gaze-cli/src/error.rs:8-23` for `(name, exit, wire shape)`. Catches drift in either direction.
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
