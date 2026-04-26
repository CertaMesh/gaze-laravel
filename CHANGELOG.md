# Changelog

All notable changes to `naoray/gaze-laravel` are documented in this file.

## [Unreleased]

### Added

- `upstream-watch` GitHub Actions workflow that checks the latest stable `piinuts/gaze` release daily, opens a guarded `bump/gaze-vX.Y.Z` PR when `BinaryInstaller::PINNED_VERSION` drifts, regenerates CLI help snapshots, and labels the PR for contract review.
- Help snapshot contract covering the pinned `gaze` CLI surface (`--version`, top-level help, `clean`, `restore`, `audit`, and `audit purge`) so upstream command/help drift is visible in adapter tests.

## [0.4.0] - 2026-04-26

First stable adopter-facing release. Bundles the v0.3 retarget with full upstream `gaze` v0.4.5 lockstep, real Composer-plugin-driven binary install, `GAZE_GITHUB_TOKEN` auth for private upstream artifacts, and CI matrix coverage.

### Added

- **Composer plugin install hook (#12).** `GazeInstallerPlugin` (`PluginInterface` + `EventSubscriberInterface`) auto-downloads the `gaze` binary into `vendor/bin/` on `composer require naoray/gaze-laravel`. Subscribes to `POST_PACKAGE_INSTALL` and `POST_PACKAGE_UPDATE` for the package itself. Previously `BinaryInstaller::postInstall` was orphaned dead code (no `extra.class`, no consumer-side script wiring) so adopters never auto-got the binary.
- **`GAZE_GITHUB_TOKEN` env (#16).** Authenticated downloads from the private `piinuts/gaze` release artifacts via GitHub asset-id resolution + `Authorization: Bearer` + `Accept: application/octet-stream`. Required for adopter installs while the upstream repo is private. `Authorization` header is stripped on cross-host redirects (api.github.com → S3 signed URL) so the token never leaves GitHub. README documents the token + required scopes.
- **CI matrix workflow (#13).** `.github/workflows/test.yml` runs Pest + PHPStan on PHP 8.2/8.3 × Laravel 11/12, ubuntu-latest. CI sets `GAZE_SKIP_BINARY_DOWNLOAD=1` (wrapper-only test scope; binary integration test is a follow-up). Concurrency cancels in-progress runs on the same ref. Action versions pinned.
- **Variant lockstep with upstream `gaze` v0.4.5 (#14).** `Variant::PolicyConfigDetail` + `Variant::AuditPurgeIso8601` enum cases, matching `GazePolicyConfigDetailException` and `GazeAuditPurgeIso8601Exception` typed exceptions, `Gaze::buildException` mapFailure arms, and `Variant::exitBucket()` arms. `PolicyConfigDetail` shares its wire name with `PolicyConfig` upstream and is disambiguated via the `detail` sidecar in `Variant::tryFromStderr`.
- **Variant contract test (#14).** `tests/Contract/VariantContractTest.php` is a fixture-based parity guard asserting every PHP `Variant` matches upstream `crates/gaze-cli/src/error.rs:8-23` for `(name, exit, wire shape)`. Catches drift in either direction.
- **Inherited from the v0.3 retarget (never shipped as a tagged release):**
  - `policy_path`, `max_bytes`, and `session_ttl_seconds` config surfaces.
  - `policy.toml.example` publish target.
  - `GazeRetryPolicy`, retry marker contracts (`TerminalGazeException` / `TransientGazeException`), and `GazeInfraAlert`.
  - `Gaze::fake()` facade test double with Laravel-idiomatic assertion helpers.
  - `MIGRATION-v0.3.md`.

### Changed

- **`PINNED_VERSION` `0.3.0` → `0.4.5` (#14).** Adapter is now lockstep with the current upstream stable release.
- **`RELEASE_BASE` repointed `Naoray/gaze` → `piinuts/gaze` (#12)** post org transfer 2026-04-26. GitHub redirects today; the new canonical URL avoids future redirect-TTL rot.
- **`config('gaze.binary')` default `'gaze'` → `null` (#15).** Without this fix, `BinaryResolver` short-circuits on the literal string `'gaze'` and never discovers the auto-installed binary at `vendor/bin/gaze`. PR #12 was effectively a no-op for default installs without #15. `null` = auto-discover `vendor/bin/` then `PATH`; non-empty string = explicit override. Empty string still coerces to `null` in `GazeServiceProvider::register`.
- **Inherited from the v0.3 retarget (never shipped as a tagged release):**
  - **BREAKING.** Retargeted the adapter from `ghostwriter` to the `gaze` v0.3 CLI contract.
  - **BREAKING.** Replaced `sanitize()` with `clean()` and removed the old `Context` envelope.
  - **BREAKING.** `restore()` now consumes `GazeSession` and returns the restored string directly.
  - Replaced substring-based stderr handling with a typed variant parser and dedicated exception hierarchy.
  - Session blobs are now stored as encrypted `EncryptedBlob` values instead of plaintext strings.

### Removed

- **Inherited from the v0.3 retarget (never shipped as a tagged release):**
  - `Context`
  - `ContextResolver`
  - `RestoredText`
  - `GazeSanitizeFailedException`
  - `GazeRestoreFailedException`
  - legacy fail-open behavior

### Adopter-visible behavior change (upstream `gaze` v0.4.5)

- **`core-extended` bundle now activates `postal.us` + `postal.de` recognizers under no-policy invocation.** Bare 5-digit numerics will tokenize. To restore prior behavior, pass `--locale=global` to `gaze` invocations or supply a policy with narrower locale gating.

### Migration notes

- **From the unreleased `[Unreleased]` state (v0.3 retarget):** v0.4.0 is purely additive on top. No further breaking changes beyond what `[Unreleased]` already had.
- **From a pre-v0.3 fork (ghostwriter-era):** read [`MIGRATION-v0.3.md`](./MIGRATION-v0.3.md) first. v0.4.0 inherits all v0.3 breaking changes (sanitize → clean, Context removal, GazeSession restore signature).

### Install

```bash
composer require naoray/gaze-laravel:^0.4.0
```

The upstream `piinuts/gaze` repo is currently private, so binary download requires a GitHub PAT with `repo` scope:

```env
GAZE_GITHUB_TOKEN=ghp_yourTokenHere
```

Set this in `.env` **before** running `composer require`. Without it, the binary download will 404.
