# Changelog

All notable changes to `empiretwo/gaze-laravel` (formerly `naoray/gaze-laravel`) will be documented in this file.

## [Unreleased]

### Added

- `composer.json` homepage, support, and authors blocks for Packagist discoverability.
- Pint format-check CI job that runs `composer format -- --test`.

### Changed

- Pre-existing Pint format drift in `BinaryInstaller` and `Gaze` was cleaned up to enable the new format-check gate.
- Repository org renamed to `EmpireTwo`. Code/test/doc URLs sweep to canonical name; GitHub redirects keep historical refs resolvable. Two CHANGELOG history entries preserve their original org/repo wording to keep historical accuracy intact.
- Pinned upstream `gaze` Rust binary URL switches to `EmpireTwo/gaze` (`BinaryInstaller::RELEASE_BASE` + `GazeServiceProvider` NER manifest fetch).

### Fixed

- `config/gaze.php` safety-net description now references the correct `--safety-net=openai-filter` flag arity.

## [0.6.4] - 2026-05-08

OSS readiness wave: lockstep with upstream `EmpireTwo/gaze` v0.6.4, README turned into a promo page with deep content moved to `docs/`, and a fix for the broken binary download path that shipped with the unreleased v0.6.5 metadata.

### Added

- PHP adapter coverage for the upstream v0.6.4 CLI surface, including locale, bundled rulepacks, custom rulepack paths, audit query, safety-net, and OpenAI filter device arguments.
- `gaze.rulepack_paths` / `GAZE_RULEPACK_PATHS` config support so `--rulepack-path=` is reachable through the service container.
- Configuration reference, exception guide, testing guide, queue guide, getting-started docs, blob lifecycle docs, Livewire side-by-side notes, Pest architecture notes, and conversational-loop guidance.
- Recreated GitHub Actions test matrix for PHP 8.2/8.3 across Laravel 11/12.

### Changed

- README slim-down: deep content moved to dedicated `docs/*.md` files. README is now a promo page (~100 lines, was ~340).
- Retarget the upcoming OSS cut to upstream `EmpireTwo/gaze` v0.6.4, the latest published release, instead of the unreleased v0.6.5 metadata used during audit prep.
- `php artisan gaze:install-ner --force` is now the single Laravel-idiomatic gate for both non-interactive confirmation and overwriting existing destination/policy state.
- NER artifact integrity now resolves `SHA256SUMS` from the upstream release URL instead of carrying a stale static checksum file.
- The pre-push hook gained a docs-only fast path before being removed for OSS packaging; historical v0.5.0 notes still document the old contributor-local hook behavior.
- OSS docs and metadata were scrubbed for public packaging, including license, gitignore, binary install guidance, security model, and quickstart entry points.

### Fixed

- `Gaze::clean()` now passes `--safety-net=openai-filter`; PR #48 originally emitted bare `--safety-net`, which v0.6.x binaries reject.
- `BinaryInstaller::PINNED_VERSION` now points at `0.6.4`, avoiding 404s for the nonexistent upstream v0.6.5 release.
- Intel Mac install guidance is reachable again after fixing the `detectTarget()` branch ordering.
- Help/version snapshots and contract docblocks now reference the pinned v0.6.4 upstream contract.
- Dead code and audit-found drift from the OSS readiness pass were cleaned up.

### Removed

- Repository-managed `.githooks/pre-push` and composer `post-install-cmd` / `post-update-cmd` auto-wiring that changed contributors' `core.hooksPath` during install/update.
- Stale packaged NER SHA fixture that duplicated upstream release checksums.

## [0.6.0] - 2026-04-29

NER opt-in wave: ships `gaze:install-ner` for one-command Davlan mBERT NER int8 ONNX setup, lockstep with upstream gaze v0.5.2 (canonical NER asset contracts), and bundles the polish PR follow-ups from PR #40 review nits + GH issues #1/#2/#8/#9.

### Added

- `php artisan gaze:install-ner` — opt-in command to download and verify the pinned Davlan mBERT NER int8 ONNX artifact set and optionally wire `[ner]` into `policy.toml`. Includes packaged upstream NER labels/policy contracts, SHA256SUMS validation, idempotent installs, dry-run/check modes, policy backups, and fail-closed mismatch handling. Closes #32.
- `RequiresFreshClean` marker contract for blob-expired / invalid-blob-version exceptions so queue consumers can branch on the recovery path without duplicating subclass lists.

### Changed

- Bump pinned upstream `EmpireTwo/gaze` from v0.5.0 to v0.5.2 (docs/scripts/assets refresh; CLI surface unchanged).
- `GazeServiceProvider` now defers container bindings until first use, and `GazeException` subclasses own their log-level classification.
- PHPStan now analyzes `tests/` as well as `src/`; shared test helpers live in `tests/Helpers.php` and the direct PHPUnit dev constraint is removed in favor of Pest's transitive PHPUnit dependency.
- `GazeRetryPolicy` now supports Laravel-style `array<int>` backoff schedules and reports the missing queue method (`fail` / `release`) when a consumer job lacks the expected queue traits.

### Fixed

- Help snapshot version text now matches the pinned upstream `gaze 0.5.2` binary.
- `NerInstaller` now fails closed when writing destination `.gitignore` fails and only removes a destination directory during rollback after the new staged directory was actually placed.
- `Gaze::restore()` now forwards `--max-bytes` and pre-flights the wrapped `{session_blob, text}` JSON payload, not only the caller-supplied text.
- Cross-session isolation integration coverage now asserts ciphertext divergence before documenting the current upstream legacy behavior.

### Documentation

- `GazeException` documents that inherited `getCode()` values are upstream process exit codes, not HTTP or app-domain status codes.

## [0.5.0] - 2026-04-29

Adopter ergonomics wave: ships a real multi-class default policy, a cold-latency diagnostic command, contributor-side test enforcement, and consolidates several v0.4.5+v0.5.0 lockstep changes that accumulated post-v0.4.0.

### Security

- Production deployments now ignore `GAZE_RELEASE_BASE` env override and always fetch from the canonical release host. The override remains available for non-production (testing/staging) flows. Closes #194.

### Added

- `php artisan gaze:bench --requests=N [--json]` for adopter latency baselines under the current cold, one-shot `gaze clean` contract. Output includes `bench_schema_version`, `mode`, `first_ms`, chronological `samples_ms`, percentiles, and an env fingerprint. Refs #33.
- Help snapshot contract covering the pinned `gaze` CLI surface (`--version`, top-level help, `clean`, `restore`, `audit`, and `audit purge`) so upstream command/help drift is visible in adapter tests.
- Audit purge foundation: `gaze.audit_db_path` / `GAZE_AUDIT_DB_PATH`, clean-side `--audit-db` forwarding, `Gaze::audit()->purge()->before(...)->dryRun()` / `execute()`, fake audit assertions, and audit docs.
- Pre-push git hook (`.githooks/pre-push`) that runs `composer test` + `composer analyse` before every push. `composer install` / `composer update` auto-wires `core.hooksPath` to `.githooks` so contributors get local enforcement without manual setup. Bypass with `git push --no-verify`.

### Changed

- Bump pinned upstream `EmpireTwo/gaze` from v0.4.5 to v0.5.0. v0.5.0 is a workspace-shape refactor (extracted `gaze-types` + `gaze-audit` crates, Dylint-based audit-isolation gate); CLI binary contract is unchanged so the adapter does not need behaviour changes. Help snapshots regenerated against the v0.5.0 binary.
- Adapter Encrypter cipher now follows host `config('app.cipher')` instead of hardcoded AES-256-CBC (#18, closes #209). Aligns with Laravel 11+ AES-256-GCM default. Existing deployments unaffected unless they relied on the hardcode mismatching the host. Cross-config regression test added.
- `policy.toml.example` rewritten as a multi-class v0.4 default. Activates `core` + `core-extended` rulepacks, ships 4 custom recognizers (money amount, invoice/order number, street address, org with legal suffix), uses BCP47 locales (`de-DE` + `en-US`) so postal recognizers actually fire, and keeps `[ner]` commented (opt-in via #32's `php artisan gaze:install-ner`). Migrates from the retired `[[detector]]` v0.3 surface to v0.4 `[[policy.custom_recognizers]]`. Money-amount regex is bounded to prevent partial matches inside identifiers and fixes the `\d{1,3}` thousand-separator bug from #31. Schema-shape test plus real-binary integration round-trip locks the contract. Closes #31.
- `.github/workflows/test.yml` auto-triggers (`push: main`, `pull_request`) temporarily disabled while GitHub Actions billing on the PIInuts org is being resolved. Workflow stays present and is invokable via `workflow_dispatch` (manual run from the Actions tab). Local enforcement via the new pre-push hook covers the same `composer test` + `composer analyse` checks. Re-enable by uncommenting the two trigger blocks in the workflow file.

### Fixed

- GazeSigPipeException now classified as Retryable+alert instead of dead-lettering on first occurrence. Closes #210.
- `GazeInstallerPlugin::uninstall()` now removes `vendor/bin/gaze` on `composer remove` instead of leaving it orphaned. Closes #213.
- `GazeInstallerPlugin::uninstall()` now correctly handles relative `bin-dir` Composer configs by normalizing against `vendor-dir`.
- Composer plugin install no longer uses `shell_exec` (#19, closes #197 + #217). `BinaryResolver` resolves the binary via Symfony `ExecutableFinder`; `BinaryInstaller::alreadyInstalled` invokes the binary via Symfony `Process`. The plugin now runs in container, Alpine, and `disable_functions=shell_exec` environments where the previous code path silently failed at install time.

### Documentation

- `MIGRATION-v0.3.md` gap-fill (#20, closes #206). Expanded four under-documented v0.3 breaking changes: (1) `fail_closed` config + `GAZE_FAIL_CLOSED` env removal (fail-closed is now permanent), (2) `Context` / `ContextResolver` removal in favor of policy-file-driven detection, (3) `RestoredText` DTO removal — `restore()` now returns `string`, (4) full rename table for the exception hierarchy (`GazeSanitizeFailedException` / `GazeRestoreFailedException` + `Terminal*` / `Transient*` markers → variant-typed subclasses + `NonRetryable` / `Retryable` / `RetryableWithAlert`).

## [0.4.0] - 2026-04-26

> **Historical note (post-2026-05 public flip):** This release predates both the public flip of `EmpireTwo/gaze` and the vendor rename to `empiretwo/gaze-laravel`. The install instructions below required a GitHub Personal Access Token because `gaze` was a private repo, and reference the legacy package name `naoray/gaze-laravel`. **No PAT is required as of v0.6.x, and the current package name is `empiretwo/gaze-laravel`.** Adopters on current versions should follow `README.md` install instructions instead. The block below is preserved for reproducibility of historical builds.

First stable adopter-facing release. Bundles the v0.3 retarget with full upstream `gaze` v0.4.5 lockstep, real Composer-plugin-driven binary install, `GAZE_GITHUB_TOKEN` auth for private upstream artifacts, and CI matrix coverage.

### Added

- **Composer plugin install hook (#12).** `GazeInstallerPlugin` (`PluginInterface` + `EventSubscriberInterface`) auto-downloads the `gaze` binary into `vendor/bin/` on `composer require naoray/gaze-laravel` (legacy package name). Subscribes to `POST_PACKAGE_INSTALL` and `POST_PACKAGE_UPDATE` for the package itself. Previously `BinaryInstaller::postInstall` was orphaned dead code (no `extra.class`, no consumer-side script wiring) so adopters never auto-got the binary.
- **`GAZE_GITHUB_TOKEN` env (#16).** Authenticated downloads from the private `EmpireTwo/gaze` release artifacts via GitHub asset-id resolution + `Authorization: Bearer` + `Accept: application/octet-stream`. Required for adopter installs while the upstream repo is private. `Authorization` header is stripped on cross-host redirects (api.github.com → S3 signed URL) so the token never leaves GitHub. README documents the token + required scopes.
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
composer require naoray/gaze-laravel:^0.4.0 # legacy package name
```

The upstream `EmpireTwo/gaze` repo is currently private, so binary download requires a GitHub PAT with `repo` scope:

```env
GAZE_GITHUB_TOKEN=[redacted historical PAT placeholder]
```

Set this in `.env` **before** running `composer require`. Without it, the binary download will 404.
